<?php
declare(strict_types=1);

/**
 * Editorial V2 — Integrity Service.
 *
 * Central read-only scanner for detecting inconsistencies.
 * Does NOT auto-fix, auto-delete, or auto-restore.
 * Only detect and report.
 *
 * Each issue is a structured array:
 * ['severity' => 'critical'|'warning'|'info',
 *  'code' => string,
 *  'article_id' => ?string,
 *  'component' => string,
 *  'message' => string,
 *  'details_safe' => ?string]
 */

// ─── Issue Builder ────────────────────────────────────────────────────────────

function editorial_integrity_issue(
    string $severity,
    string $code,
    ?string $articleId,
    string $component,
    string $message,
    ?string $detailsSafe = null
): array {
    return [
        'severity' => $severity,
        'code' => $code,
        'article_id' => $articleId,
        'component' => $component,
        'message' => $message,
        'details_safe' => $detailsSafe,
    ];
}

// ─── Main Scanner ─────────────────────────────────────────────────────────────

/**
 * Run comprehensive integrity scan.
 * Focuses on articles with editorial state to avoid scanning all ~2000 articles.
 *
 * @param bool $full If true, also scans catalog for duplicates and global checks
 * @return array{issues: array, summary: array}
 */
function editorial_run_integrity_scan(bool $full = false): array
{
    $issues = [];
    $db = editorial_db();

    // Load all article states
    $stateRows = $db->query('SELECT * FROM editorial_article_state ORDER BY article_id')->fetchAll();

    // ── Global catalog checks ──
    $catalogIssues = editorial_integrity_check_catalog();
    $issues = array_merge($issues, $catalogIssues);

    // ── Per-article state checks ──
    foreach ($stateRows as $state) {
        $articleId = $state['article_id'];

        // State vs Assignment consistency
        $issues = array_merge($issues, editorial_integrity_check_article_state($state));

        // Revision pointers
        $issues = array_merge($issues, editorial_integrity_check_revision_pointers($state));

        // Snapshot integrity (only for current/published revisions)
        $issues = array_merge($issues, editorial_integrity_check_snapshots($state));

        // Live hash checks
        $issues = array_merge($issues, editorial_integrity_check_live_hashes($state));

        // Backup path checks for published articles
        if ($state['status'] === 'published') {
            $issues = array_merge($issues, editorial_integrity_check_backups($state));
        }
    }

    // ── Lock checks ──
    $issues = array_merge($issues, editorial_integrity_check_locks());

    // ── Draft checks ──
    $issues = array_merge($issues, editorial_integrity_check_drafts($stateRows));

    // ── Assignment uniqueness ──
    $issues = array_merge($issues, editorial_integrity_check_assignments($stateRows));

    // ── Build summary ──
    $summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0, 'total_checked' => count($stateRows)];
    foreach ($issues as $issue) {
        $sev = $issue['severity'] ?? 'info';
        if (isset($summary[$sev])) {
            $summary[$sev]++;
        }
    }
    if ($summary['critical'] === 0 && $summary['warning'] === 0) {
        $summary['ok'] = $summary['total_checked'];
    }

    return ['issues' => $issues, 'summary' => $summary];
}

// ─── Article State vs Assignment ──────────────────────────────────────────────

function editorial_integrity_check_article_state(array $state): array
{
    $issues = [];
    $articleId = $state['article_id'];
    $status = $state['status'] ?? '';
    $assignedUserId = $state['assigned_user_id'] ?? null;

    $db = editorial_db();

    // Get active assignments count
    $stmt = $db->prepare('SELECT COUNT(*) FROM editorial_assignments WHERE article_id = :aid AND released_at IS NULL');
    $stmt->execute([':aid' => $articleId]);
    $activeAssignmentCount = (int) $stmt->fetchColumn();

    // Get lock
    $lockStmt = $db->prepare('SELECT * FROM editorial_locks WHERE article_id = :aid');
    $lockStmt->execute([':aid' => $articleId]);
    $lock = $lockStmt->fetch();

    switch ($status) {
        case 'available':
            if ($assignedUserId !== null && $assignedUserId !== '') {
                $issues[] = editorial_integrity_issue('critical', 'available_has_assigned_user', $articleId, 'state', 'Bài available nhưng có assigned_user_id.');
            }
            if ($activeAssignmentCount > 0) {
                $issues[] = editorial_integrity_issue('critical', 'available_has_active_assignment', $articleId, 'state', 'Bài available nhưng có assignment đang hoạt động.');
            }
            if ($lock) {
                $issues[] = editorial_integrity_issue('warning', 'available_has_lock', $articleId, 'lock', 'Bài available nhưng có khóa chỉnh sửa.');
            }
            break;

        case 'editing':
        case 'returned':
            if (empty($assignedUserId)) {
                $issues[] = editorial_integrity_issue('critical', $status . '_no_assigned_user', $articleId, 'state', 'Bài ' . $status . ' nhưng không có assigned_user_id.');
            }
            if ($activeAssignmentCount !== 1) {
                $issues[] = editorial_integrity_issue('critical', $status . '_assignment_count', $articleId, 'assignment', 'Bài ' . $status . ' phải có đúng 1 assignment. Hiện có: ' . $activeAssignmentCount);
            }
            // Verify assignment user matches state
            if ($activeAssignmentCount === 1 && !empty($assignedUserId)) {
                $asgn = editorial_get_active_assignment($articleId);
                if ($asgn && $asgn['user_id'] !== $assignedUserId) {
                    $issues[] = editorial_integrity_issue('critical', $status . '_assignment_user_mismatch', $articleId, 'assignment', 'Assignment user không khớp state.assigned_user_id.');
                }
            }
            break;

        case 'ready_review':
        case 'approved':
            if (empty($assignedUserId)) {
                $issues[] = editorial_integrity_issue('critical', $status . '_no_assigned_user', $articleId, 'state', 'Bài ' . $status . ' nhưng không có assigned_user_id.');
            }
            if ($activeAssignmentCount !== 1) {
                $issues[] = editorial_integrity_issue('critical', $status . '_assignment_count', $articleId, 'assignment', 'Bài ' . $status . ' phải có đúng 1 assignment. Hiện có: ' . $activeAssignmentCount);
            }
            if ($activeAssignmentCount === 1 && !empty($assignedUserId)) {
                $asgn = editorial_get_active_assignment($articleId);
                if ($asgn && $asgn['user_id'] !== $assignedUserId) {
                    $issues[] = editorial_integrity_issue('critical', $status . '_assignment_user_mismatch', $articleId, 'assignment', 'Assignment user không khớp state.assigned_user_id.');
                }
            }
            if (empty($state['current_revision_id'])) {
                $issues[] = editorial_integrity_issue('critical', $status . '_no_current_revision', $articleId, 'revision', 'Bài ' . $status . ' không có current_revision_id.');
            }
            break;

        case 'published':
            if ($assignedUserId !== null && $assignedUserId !== '') {
                $issues[] = editorial_integrity_issue('warning', 'published_has_assigned_user', $articleId, 'state', 'Bài published nhưng vẫn có assigned_user_id.');
            }
            if ($activeAssignmentCount > 0) {
                $issues[] = editorial_integrity_issue('warning', 'published_has_active_assignment', $articleId, 'assignment', 'Bài published nhưng có assignment đang hoạt động.');
            }
            if ($lock) {
                $issues[] = editorial_integrity_issue('warning', 'published_has_lock', $articleId, 'lock', 'Bài published nhưng có khóa chỉnh sửa.');
            }
            if (empty($state['published_revision_id'])) {
                $issues[] = editorial_integrity_issue('critical', 'published_no_revision', $articleId, 'revision', 'Bài published nhưng không có published_revision_id.');
            }
            if (($state['current_revision_id'] ?? '') !== ($state['published_revision_id'] ?? '')) {
                $issues[] = editorial_integrity_issue('critical', 'published_revision_mismatch', $articleId, 'revision', 'current_revision_id không khớp published_revision_id.');
            }
            break;
    }

    // Check assigned user is valid
    if (!empty($assignedUserId)) {
        $user = editorial_find_user_by_id($assignedUserId);
        if (!$user) {
            $issues[] = editorial_integrity_issue('critical', 'assigned_user_not_found', $articleId, 'user', 'User được phân công không tồn tại.');
        } elseif (empty($user['is_active'])) {
            $issues[] = editorial_integrity_issue('warning', 'assigned_user_inactive', $articleId, 'user', 'User được phân công đã bị vô hiệu hóa.');
        } elseif (!in_array($user['role'] ?? '', ['admin', 'editor'], true)) {
            $issues[] = editorial_integrity_issue('warning', 'assigned_user_invalid_role', $articleId, 'user', 'User được phân công không có vai trò admin/editor.');
        }
    }

    return $issues;
}

// ─── Revision Pointers ────────────────────────────────────────────────────────

function editorial_integrity_check_revision_pointers(array $state): array
{
    $issues = [];
    $articleId = $state['article_id'];

    $pointers = [
        'current_revision_id' => null,
        'review_revision_id' => null,
        'approved_revision_id' => 'editorial',
        'published_revision_id' => 'published',
    ];

    foreach ($pointers as $field => $expectedType) {
        $revId = $state[$field] ?? '';
        if ($revId === '') continue;

        $rev = editorial_get_revision($revId);
        if (!$rev) {
            $issues[] = editorial_integrity_issue('critical', $field . '_not_found', $articleId, 'revision', 'Revision pointer ' . $field . ' trỏ tới revision không tồn tại.');
            continue;
        }

        if ($rev['article_id'] !== $articleId) {
            $issues[] = editorial_integrity_issue('critical', $field . '_wrong_article', $articleId, 'revision', 'Revision pointer ' . $field . ' trỏ tới revision của bài khác.');
        }

        if ($expectedType !== null && ($rev['revision_type'] ?? '') !== $expectedType) {
            $issues[] = editorial_integrity_issue('warning', $field . '_wrong_type', $articleId, 'revision', 'Revision ' . $field . ' có type "' . ($rev['revision_type'] ?? '') . '" thay vì "' . $expectedType . '".');
        }
    }

    return $issues;
}

// ─── Snapshot Integrity ───────────────────────────────────────────────────────

function editorial_integrity_check_snapshots(array $state): array
{
    $issues = [];
    $articleId = $state['article_id'];

    // Check current and published revision snapshots only
    $revisionIds = array_filter([
        $state['current_revision_id'] ?? '',
        $state['published_revision_id'] ?? '',
    ]);

    foreach (array_unique($revisionIds) as $revId) {
        if ($revId === '') continue;

        $rev = editorial_get_revision($revId);
        if (!$rev) continue; // Already reported by pointer check

        $snapResult = editorial_get_verified_revision_snapshot($rev);
        if (!$snapResult['ok']) {
            $issues[] = editorial_integrity_issue('critical', 'snapshot_invalid', $articleId, 'snapshot', 'Snapshot revision ' . $revId . ' không hợp lệ: ' . ($snapResult['message'] ?? 'unknown'));
        }
    }

    return $issues;
}

// ─── Live Hash Checks ─────────────────────────────────────────────────────────

function editorial_integrity_check_live_hashes(array $state): array
{
    $issues = [];
    $articleId = $state['article_id'];
    $status = $state['status'] ?? '';

    // Resolve article to get file path
    $article = editorial_find_article($articleId);
    if (!$article) {
        $issues[] = editorial_integrity_issue('critical', 'article_not_in_catalog', $articleId, 'catalog', 'Bài có state nhưng không tìm thấy trong catalog.');
        return $issues;
    }

    $filePath = editorial_resolve_article_path($article);
    if (!$filePath || !file_exists($filePath)) {
        $issues[] = editorial_integrity_issue('critical', 'live_file_missing', $articleId, 'live', 'File HTML live không tồn tại.');
        return $issues;
    }

    $currentHash = hash_file('sha256', $filePath);

    // For editing/returned/ready_review/approved: check base_live_hash
    if (in_array($status, ['editing', 'returned', 'ready_review', 'approved'], true)) {
        $baseLiveHash = $state['base_live_hash'] ?? '';
        if ($baseLiveHash !== '' && $currentHash !== $baseLiveHash) {
            $issues[] = editorial_integrity_issue('critical', 'live_changed_since_claim', $articleId, 'live', 'File HTML đã thay đổi bên ngoài editorial. base_live_hash mismatch.');
        }
    }

    // For published: check published_live_hash
    if ($status === 'published') {
        $publishedLiveHash = $state['published_live_hash'] ?? '';
        if ($publishedLiveHash !== '' && $currentHash !== $publishedLiveHash) {
            $issues[] = editorial_integrity_issue('critical', 'published_live_drift', $articleId, 'live', 'File HTML live khác với published_live_hash. Nội dung có thể đã bị sửa bên ngoài.');
        }
    }

    return $issues;
}

// ─── Catalog Checks ───────────────────────────────────────────────────────────

function editorial_integrity_check_catalog(): array
{
    $issues = [];
    $catalogPath = dirname(dirname(__DIR__)) . '/data/articles.json';

    if (!file_exists($catalogPath)) {
        $issues[] = editorial_integrity_issue('critical', 'catalog_missing', null, 'catalog', 'data/articles.json không tồn tại.');
        return $issues;
    }

    $raw = file_get_contents($catalogPath);
    if ($raw === false) {
        $issues[] = editorial_integrity_issue('critical', 'catalog_unreadable', null, 'catalog', 'Không đọc được data/articles.json.');
        return $issues;
    }

    $catalog = json_decode($raw, true);
    if (!is_array($catalog)) {
        $issues[] = editorial_integrity_issue('critical', 'catalog_invalid_json', null, 'catalog', 'data/articles.json không phải JSON hợp lệ.');
        return $issues;
    }

    // Check for duplicate IDs
    $idCounts = [];
    foreach ($catalog as $item) {
        $id = $item['id'] ?? '';
        if ($id === '') {
            $issues[] = editorial_integrity_issue('warning', 'catalog_empty_id', null, 'catalog', 'data/articles.json có item thiếu id.');
            continue;
        }
        $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
    }

    foreach ($idCounts as $id => $count) {
        if ($count > 1) {
            $issues[] = editorial_integrity_issue('critical', 'catalog_duplicate_id', $id, 'catalog', 'data/articles.json có ' . $count . ' entries với cùng id.');
        }
    }

    return $issues;
}

// ─── Lock Checks ──────────────────────────────────────────────────────────────

function editorial_integrity_check_locks(): array
{
    $issues = [];
    $db = editorial_db();
    $now = date('c');

    $locks = $db->query('SELECT * FROM editorial_locks')->fetchAll();

    foreach ($locks as $lock) {
        $articleId = $lock['article_id'] ?? '';
        $expiresAt = $lock['expires_at'] ?? '';

        // Expired lock
        if ($expiresAt !== '' && $expiresAt < $now) {
            $issues[] = editorial_integrity_issue('warning', 'lock_expired', $articleId, 'lock', 'Khóa chỉnh sửa đã hết hạn.', 'Hết hạn: ' . $expiresAt);
        }

        // Lock user vs assigned user
        $state = editorial_get_article_state($articleId);
        if (!$state) {
            $issues[] = editorial_integrity_issue('warning', 'lock_no_state', $articleId, 'lock', 'Khóa tồn tại nhưng bài không có state.');
            continue;
        }

        $status = $state['status'] ?? '';
        if (!in_array($status, ['editing', 'returned'], true)) {
            $issues[] = editorial_integrity_issue('warning', 'lock_wrong_status', $articleId, 'lock', 'Khóa tồn tại nhưng status là "' . $status . '" (expected editing/returned).');
        }

        if (!empty($state['assigned_user_id']) && ($lock['user_id'] ?? '') !== $state['assigned_user_id']) {
            $issues[] = editorial_integrity_issue('warning', 'lock_user_mismatch', $articleId, 'lock', 'User khóa không khớp assigned_user_id.');
        }
    }

    return $issues;
}

// ─── Draft Checks ─────────────────────────────────────────────────────────────

function editorial_integrity_check_drafts(array $stateRows): array
{
    $issues = [];
    $db = editorial_db();

    // Build state map
    $stateMap = [];
    foreach ($stateRows as $s) {
        $stateMap[$s['article_id']] = $s;
    }

    $drafts = $db->query('SELECT article_id, user_id FROM editorial_drafts')->fetchAll();

    foreach ($drafts as $draft) {
        $articleId = $draft['article_id'] ?? '';
        $draftUserId = $draft['user_id'] ?? '';
        $state = $stateMap[$articleId] ?? null;

        if (!$state) {
            $issues[] = editorial_integrity_issue('warning', 'draft_no_state', $articleId, 'draft', 'Draft tồn tại nhưng bài không có state.');
            continue;
        }

        $status = $state['status'] ?? '';

        if ($status === 'published') {
            $issues[] = editorial_integrity_issue('warning', 'draft_published_article', $articleId, 'draft', 'Draft tồn tại cho bài đã published.');
        }

        if ($status === 'available') {
            $issues[] = editorial_integrity_issue('warning', 'draft_available_article', $articleId, 'draft', 'Draft tồn tại cho bài available.');
        }

        // Draft owner not assigned user
        $assignedUserId = $state['assigned_user_id'] ?? '';
        if (!empty($assignedUserId) && $draftUserId !== $assignedUserId) {
            $issues[] = editorial_integrity_issue('warning', 'draft_wrong_owner', $articleId, 'draft', 'Draft owner không phải assigned user.');
        }
    }

    return $issues;
}

// ─── Assignment Uniqueness ────────────────────────────────────────────────────

function editorial_integrity_check_assignments(array $stateRows): array
{
    $issues = [];
    $db = editorial_db();

    // Check active assignment uniqueness
    $stmt = $db->query('
        SELECT article_id, COUNT(*) as cnt
        FROM editorial_assignments
        WHERE released_at IS NULL
        GROUP BY article_id
        HAVING cnt > 1
    ');
    $conflicts = $stmt->fetchAll();

    foreach ($conflicts as $row) {
        $issues[] = editorial_integrity_issue('critical', 'multiple_active_assignments', $row['article_id'], 'assignment', 'Bài có ' . $row['cnt'] . ' assignment hoạt động cùng lúc.');
    }

    // Check assignment user validity
    $activeAssignments = $db->query('SELECT * FROM editorial_assignments WHERE released_at IS NULL')->fetchAll();
    foreach ($activeAssignments as $asgn) {
        $user = editorial_find_user_by_id($asgn['user_id'] ?? '');
        if (!$user) {
            $issues[] = editorial_integrity_issue('critical', 'assignment_user_not_found', $asgn['article_id'] ?? '', 'assignment', 'Assignment user không tồn tại.');
        } elseif (empty($user['is_active'])) {
            $issues[] = editorial_integrity_issue('warning', 'assignment_user_inactive', $asgn['article_id'] ?? '', 'assignment', 'Assignment user đã bị vô hiệu hóa.');
        }
    }

    return $issues;
}

// ─── Backup Checks ────────────────────────────────────────────────────────────

function editorial_integrity_check_backups(array $state): array
{
    $issues = [];
    $articleId = $state['article_id'];
    $backupPath = $state['publish_backup_path'] ?? '';

    if ($backupPath === '') {
        $issues[] = editorial_integrity_issue('warning', 'published_no_backup_path', $articleId, 'backup', 'Bài published không có publish_backup_path.');
        return $issues;
    }

    // publish_backup_path is relative to editorial/storage, not storage/backups.
    $backupPath = trim((string) $backupPath);
    $pathParts = explode('/', $backupPath);
    $invalidShape = $backupPath === ''
        || str_starts_with($backupPath, '/')
        || str_contains($backupPath, '\\')
        || str_contains($backupPath, "\0")
        || !str_starts_with($backupPath, 'backups/')
        || count($pathParts) < 2
        || array_filter($pathParts, static fn(string $part): bool => $part === '' || $part === '.' || $part === '..') !== [];
    if ($invalidShape) {
        $issues[] = editorial_integrity_issue('critical', 'backup_path_invalid', $articleId, 'backup', 'publish_backup_path không đúng contract relative-to-storage/backups.');
        return $issues;
    }

    $storageRoot = dirname(__DIR__) . '/storage';
    $backupBase = $storageRoot . '/backups';
    $absolutePath = $storageRoot . '/' . $backupPath;

    // A missing backup is operationally different from a path escape.
    if (!file_exists($absolutePath)) {
        $issues[] = editorial_integrity_issue('warning', 'backup_file_missing', $articleId, 'backup', 'File backup không tồn tại.');
        return $issues;
    }

    $realBase = realpath($backupBase);
    $realPath = realpath($absolutePath);
    if ($realBase === false || $realPath === false
        || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
        $issues[] = editorial_integrity_issue('critical', 'backup_path_escape', $articleId, 'backup', 'Backup path nằm ngoài thư mục backup.');
        return $issues;
    }
    if (!is_file($realPath)) {
        $issues[] = editorial_integrity_issue('warning', 'backup_file_invalid', $articleId, 'backup', 'Backup path không trỏ tới file hợp lệ.');
    }

    return $issues;
}

// ─── Expired Lock Cleanup ─────────────────────────────────────────────────────

/**
 * Delete expired locks in a transaction.
 * Returns list of cleaned article IDs.
 */
function editorial_cleanup_expired_locks(): array
{
    $now = date('c');

    return editorial_transaction(function() use ($now) {
        $db = editorial_db();

        // Find expired locks inside transaction
        $stmt = $db->prepare('SELECT article_id FROM editorial_locks WHERE expires_at < :now');
        $stmt->execute([':now' => $now]);
        $expiredArticles = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($expiredArticles)) {
            return ['ok' => true, 'count' => 0, 'article_ids' => []];
        }

        // Delete only expired
        $delStmt = $db->prepare('DELETE FROM editorial_locks WHERE expires_at < :now');
        $delStmt->execute([':now' => $now]);
        $deleted = $delStmt->rowCount();

        return ['ok' => true, 'count' => $deleted, 'article_ids' => $expiredArticles];
    });
}
