<?php
declare(strict_types=1);

/**
 * Editorial V2 — Assignment Service.
 *
 * Business logic for article claim/assignment.
 * Atomic claim via editorial_transaction() with BEGIN IMMEDIATE.
 * Status label centralization.
 */

// ─── Status labels ──────────────────────────────────────────────

/**
 * Map editorial status to Vietnamese label.
 */
function editorial_status_label(string $status): string
{
    return match ($status) {
        'available' => 'Chưa có người nhận',
        'editing' => 'Đang biên tập',
        'ready_review' => 'Chờ duyệt',
        'returned' => 'Cần chỉnh lại',
        'approved' => 'Đã duyệt',
        'published' => 'Đã xuất bản',
        default => $status,
    };
}

/**
 * CSS class suffix for status badge.
 */
function editorial_status_css(string $status): string
{
    return match ($status) {
        'available' => 'available',
        'editing' => 'editing',
        'ready_review' => 'review',
        'returned' => 'returned',
        'approved' => 'approved',
        'published' => 'published',
        default => 'default',
    };
}

// ─── State queries ──────────────────────────────────────────────

/**
 * Get editorial state for a single article.
 * Returns null if no state exists (= available).
 *
 * @return array<string,mixed>|null
 */
function editorial_get_article_state(string $articleId): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_article_state WHERE article_id = :id');
    $stmt->execute(['id' => $articleId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Batch-load editorial states for a set of article IDs.
 * Returns map: article_id => state row.
 *
 * @param array<int, string> $articleIds
 * @return array<string, array<string,mixed>>
 */
function editorial_get_states_for_articles(array $articleIds): array
{
    if (empty($articleIds)) return [];

    $db = editorial_db();
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = $db->prepare("SELECT * FROM editorial_article_state WHERE article_id IN ($placeholders)");
    $stmt->execute(array_values($articleIds));

    $map = [];
    while ($row = $stmt->fetch()) {
        $map[(string) $row['article_id']] = $row;
    }
    return $map;
}

/**
 * Get all articles currently assigned to a user.
 *
 * @return array<int, array<string,mixed>>
 */
function editorial_get_user_assignments(string $userId): array
{
    $db = editorial_db();
    $stmt = $db->prepare('
        SELECT * FROM editorial_article_state
        WHERE assigned_user_id = :uid
        ORDER BY assigned_at DESC
    ');
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Count articles by assignment status.
 *
 * @return array{total: int, assigned: int, available: int, mine: int}
 */
function editorial_assignment_counts(?string $currentUserId = null): array
{
    $totalArticles = editorial_article_count();
    $db = editorial_db();

    $assigned = (int) $db->query("SELECT COUNT(*) FROM editorial_article_state WHERE assigned_user_id IS NOT NULL")->fetchColumn();
    $mine = 0;
    if ($currentUserId !== null) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM editorial_article_state WHERE assigned_user_id = :uid");
        $stmt->execute(['uid' => $currentUserId]);
        $mine = (int) $stmt->fetchColumn();
    }

    return [
        'total' => $totalArticles,
        'assigned' => $assigned,
        'available' => $totalArticles - $assigned,
        'mine' => $mine,
    ];
}

/**
 * Preload user display names for a set of user IDs.
 *
 * @param array<int, string> $userIds
 * @return array<string, string> userId => display_name
 */
function editorial_preload_user_names(array $userIds): array
{
    $userIds = array_unique(array_filter($userIds));
    if (empty($userIds)) return [];

    $db = editorial_db();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare("SELECT id, display_name FROM editorial_users WHERE id IN ($placeholders)");
    $stmt->execute(array_values($userIds));

    $map = [];
    while ($row = $stmt->fetch()) {
        $map[(string) $row['id']] = (string) $row['display_name'];
    }
    return $map;
}

/**
 * Batch-load permanent editorial contributors for displayed articles.
 *
 * A contributor is an assignment with at least one successful Saved Draft.
 * The assignment table preserves this history after release/reassign/publish.
 *
 * @param array<int, string> $articleIds
 * @return array<string, array<int, array{user_id:string,display_name:string,first_saved_at:string,last_saved_at:string}>>
 */
function editorial_get_article_contributors(array $articleIds): array
{
    $articleIds = array_values(array_unique(array_filter(
        array_map(static fn(mixed $id): string => trim((string) $id), $articleIds),
        static fn(string $id): bool => $id !== ''
    )));
    if ($articleIds === []) {
        return [];
    }

    $contributors = array_fill_keys($articleIds, []);
    $db = editorial_db();
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = $db->prepare("
        SELECT
            a.article_id,
            a.user_id,
            COALESCE(NULLIF(TRIM(u.display_name), ''), u.username) AS display_name,
            a.first_saved_at,
            a.last_saved_at,
            a.assigned_at
        FROM editorial_assignments a
        INNER JOIN editorial_users u ON u.id = a.user_id
        WHERE a.article_id IN ($placeholders)
          AND a.first_saved_at IS NOT NULL
        ORDER BY a.article_id ASC, a.first_saved_at ASC, a.assigned_at ASC
    ");
    $stmt->execute($articleIds);

    $seenUsers = [];
    while ($row = $stmt->fetch()) {
        $articleId = (string) $row['article_id'];
        $userId = (string) $row['user_id'];
        if (isset($seenUsers[$articleId][$userId])) {
            continue;
        }
        $seenUsers[$articleId][$userId] = true;
        $contributors[$articleId][] = [
            'user_id' => $userId,
            'display_name' => (string) $row['display_name'],
            'first_saved_at' => (string) $row['first_saved_at'],
            'last_saved_at' => (string) ($row['last_saved_at'] ?? ''),
        ];
    }

    return $contributors;
}

// ─── Atomic Claim ───────────────────────────────────────────────

/**
 * Attempt to claim an article for editorial work.
 *
 * MUST be called with pre-validated inputs:
 * - $articleId exists in catalog
 * - $htmlPath is resolved and verified
 * - $liveHash is computed
 *
 * Uses editorial_transaction() with BEGIN IMMEDIATE to serialize writes.
 *
 * @return array{ok: bool, code: string, message: string}
 */
function editorial_claim_article(string $articleId, string $userId, string $htmlPath, string $liveHash): array
{
    return editorial_transaction(function () use ($articleId, $userId, $htmlPath, $liveHash): array {
        $db = editorial_db();
        $now = date('c');

        // Step 1: Ensure article_state row exists
        $state = editorial_get_article_state($articleId);
        if ($state === null) {
            $stmt = $db->prepare('
                INSERT INTO editorial_article_state (article_id, status, assigned_user_id, assigned_at, base_live_hash, updated_at)
                VALUES (:id, \'available\', NULL, NULL, NULL, :now)
            ');
            $stmt->execute(['id' => $articleId, 'now' => $now]);
            $state = [
                'article_id' => $articleId,
                'status' => 'available',
                'assigned_user_id' => null,
                'base_live_hash' => null,
            ];
        }

        // Step 2: Check current assignment
        $currentOwner = $state['assigned_user_id'] ?? null;
        if ($currentOwner !== null && $currentOwner !== '') {
            if ($currentOwner === $userId) {
                return [
                    'ok' => false,
                    'code' => 'already_owned_by_you',
                    'message' => 'Bạn đang là người phụ trách bài này.',
                ];
            }
            // Get owner name
            $ownerUser = editorial_find_user_by_id((string) $currentOwner);
            $ownerName = $ownerUser ? (string) $ownerUser['display_name'] : 'người khác';
            return [
                'ok' => false,
                'code' => 'already_claimed',
                'message' => 'Bài này vừa được ' . $ownerName . ' nhận biên tập.',
            ];
        }

        // Step 3: Verify no orphaned active assignment (fail-safe)
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM editorial_assignments
            WHERE article_id = :aid AND released_at IS NULL
        ');
        $stmt->execute(['aid' => $articleId]);
        $activeCount = (int) $stmt->fetchColumn();
        if ($activeCount > 0) {
            return [
                'ok' => false,
                'code' => 'assignment_conflict',
                'message' => 'Phát hiện xung đột dữ liệu assignment. Vui lòng liên hệ quản trị viên.',
            ];
        }

        // Step 4: Insert assignment history
        $assignmentId = editorial_generate_id('asgn');
        $stmt = $db->prepare('
            INSERT INTO editorial_assignments (id, article_id, user_id, assigned_at, released_at, release_reason, created_by, created_at)
            VALUES (:id, :article_id, :user_id, :assigned_at, NULL, NULL, :created_by, :created_at)
        ');
        $stmt->execute([
            'id' => $assignmentId,
            'article_id' => $articleId,
            'user_id' => $userId,
            'assigned_at' => $now,
            'created_by' => $userId,
            'created_at' => $now,
        ]);

        // Step 5: Update article state — reset work-cycle fields
        $stmt = $db->prepare('
            UPDATE editorial_article_state
            SET status = \'editing\',
                assigned_user_id = :user_id,
                assigned_at = :assigned_at,
                base_live_hash = :hash,
                current_revision_id = NULL,
                review_revision_id = NULL,
                review_requested_by = NULL,
                review_requested_at = NULL,
                approved_revision_id = NULL,
                approved_by = NULL,
                approved_at = NULL,
                published_revision_id = NULL,
                published_by = NULL,
                published_at = NULL,
                published_live_hash = NULL,
                publish_backup_path = NULL,
                updated_at = :updated_at
            WHERE article_id = :article_id
        ');
        $stmt->execute([
            'user_id' => $userId,
            'assigned_at' => $now,
            'hash' => $liveHash,
            'updated_at' => $now,
            'article_id' => $articleId,
        ]);

        // Step 6: Activity log
        editorial_log_activity('article.claimed', $articleId, $userId, json_encode([
            'assignment_id' => $assignmentId,
            'base_live_hash' => $liveHash,
        ]));

        return [
            'ok' => true,
            'code' => 'claimed',
            'message' => 'Đã nhận biên tập bài viết thành công.',
        ];
    });
}

// ─── Status Transition ──────────────────────────────────────────

/**
 * Check if a workflow status transition is allowed.
 * Phase 6: Centralized transition validation.
 */
function editorial_can_transition(string $from, string $to): bool
{
    $allowed = [
        'available' => ['editing'],
        'editing' => ['ready_review', 'available', 'editing'],
        'returned' => ['ready_review', 'available', 'editing'],
        'ready_review' => ['returned', 'approved'],
        'approved' => ['editing'],
        'published' => ['editing'],
    ];

    $transitions = $allowed[$from] ?? [];
    return in_array($to, $transitions, true);
}

/**
 * Reopen the current owner's approved article for a new editing phase.
 * The last approval fields remain an immutable checkpoint/history marker.
 *
 * @return array{ok:bool,message:string}
 */
function editorial_resume_approved_editing(string $articleId, string $userId): array
{
    return editorial_transaction(function () use ($articleId, $userId): array {
        $state = editorial_get_article_state($articleId);
        if ($state === null || (string) ($state['status'] ?? '') !== 'approved') {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái đã duyệt để tiếp tục biên tập.'];
        }
        if ((string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không phải người đang phụ trách bài viết này.'];
        }
        if (!editorial_can_transition('approved', 'editing')) {
            return ['ok' => false, 'message' => 'Trạng thái chuyển đổi không hợp lệ.'];
        }

        $assignment = editorial_get_active_assignment($articleId);
        if ($assignment === null || (string) ($assignment['user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Phân công hiện tại không hợp lệ hoặc không khớp.'];
        }

        $now = date('c');
        $stmt = editorial_db()->prepare("
            UPDATE editorial_article_state
            SET status = 'editing',
                updated_at = :updated_at
            WHERE article_id = :article_id
              AND status = 'approved'
              AND assigned_user_id = :user_id
        ");
        $stmt->execute([
            'updated_at' => $now,
            'article_id' => $articleId,
            'user_id' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'message' => 'Trạng thái bài viết đã thay đổi. Vui lòng tải lại danh sách.'];
        }

        editorial_log_activity('article.approval.resumed_editing', $articleId, $userId, json_encode([
            'assignment_id' => (string) $assignment['id'],
            'approved_revision_id' => (string) ($state['approved_revision_id'] ?? ''),
        ]));

        return ['ok' => true, 'message' => 'Đã mở lại giai đoạn biên tập.'];
    });
}
