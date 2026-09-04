<?php
declare(strict_types=1);

/**
 * Editorial V2 Phase 5 — Revision Service Module.
 *
 * Provides:
 * - Active assignment helper
 * - Canonical content hashing
 * - Immutable snapshot storage (filesystem JSON)
 * - Baseline + editorial revision creation
 * - Revision queries
 * - Simple line diff with performance guard
 */

// ─── Active Assignment ──────────────────────────────────────────

/**
 * Get active assignment for an article.
 * Returns null if 0 or >1 active rows.
 * Logs diagnostic if >1 found.
 */
function editorial_get_active_assignment(string $articleId): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('
        SELECT * FROM editorial_assignments
        WHERE article_id = :aid AND released_at IS NULL
    ');
    $stmt->execute(['aid' => $articleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 1) {
        return $rows[0];
    }
    if (count($rows) > 1) {
        editorial_log_activity('article.assignment.conflict_detected', $articleId, null, json_encode([
            'assignment_count' => count($rows),
            'assignment_ids' => array_column($rows, 'id'),
        ]));
    }
    return null;
}

// ─── Canonical Content Hash ─────────────────────────────────────

/**
 * Recursively sort associative array keys for canonical JSON.
 */
function editorial_stable_sort_keys(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }

    // Detect list vs associative
    $isList = true;
    if (function_exists('array_is_list')) {
        $isList = array_is_list($data);
    } else {
        $expectedKey = 0;
        foreach ($data as $k => $v) {
            if ($k !== $expectedKey++) {
                $isList = false;
                break;
            }
        }
    }

    if ($isList) {
        $result = [];
        foreach ($data as $item) {
            $result[] = editorial_stable_sort_keys($item);
        }
        return $result;
    }

    // Associative: sort keys
    ksort($data, SORT_STRING);
    foreach ($data as $key => $value) {
        $data[$key] = editorial_stable_sort_keys($value);
    }
    return $data;
}

/**
 * Compute canonical SHA-256 content hash for a payload.
 * Same payload always produces same hash.
 * A5 fix: throws RuntimeException on JSON encode failure instead of hashing '{}'.
 */
function editorial_revision_content_hash(array $payload): string
{
    $canonical = editorial_stable_sort_keys($payload);
    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Không thể tạo mã kiểm tra cho nội dung phiên bản.');
    }
    return hash('sha256', $json);
}

// ─── Snapshot Storage ───────────────────────────────────────────

/**
 * Base path for revision snapshots.
 */
function editorial_revisions_base_path(): string
{
    return dirname(__DIR__) . '/storage/revisions';
}

/**
 * Compute snapshot directory using SHA-256 sharding of article_id.
 * Creates directory if it does not exist.
 */
function editorial_revision_snapshot_dir(string $articleId): string
{
    $hash = hash('sha256', $articleId);
    $dir = editorial_revisions_base_path() . '/' . substr($hash, 0, 2) . '/' . $hash;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Write snapshot JSON file atomically.
 * Returns relative path from revisions base.
 */
function editorial_write_revision_snapshot(string $revisionId, string $articleId, array $payload): string
{
    $dir = editorial_revision_snapshot_dir($articleId);
    $basePath = editorial_revisions_base_path();
    $fileName = 'rev_' . $revisionId . '.json';
    $finalPath = $dir . '/' . $fileName;
    $relativePath = substr($finalPath, strlen($basePath) + 1);

    // Already exists (idempotent)
    if (file_exists($finalPath)) {
        return $relativePath;
    }

    $snapshot = [
        'schema_version' => 1,
        'article_id' => $articleId,
        'payload' => $payload,
    ];

    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Không thể mã hóa snapshot JSON.');
    }

    // Atomic write: temp file → rename
    $tempFile = $dir . '/.tmp_rev_' . $revisionId . '_' . uniqid('', true);
    $written = file_put_contents($tempFile, $json);
    if ($written === false || $written !== strlen($json)) {
        @unlink($tempFile);
        throw new RuntimeException('Không thể ghi đầy đủ snapshot tạm.');
    }

    if (!rename($tempFile, $finalPath)) {
        @unlink($tempFile);
        throw new RuntimeException('Không thể rename snapshot file.');
    }

    return $relativePath;
}

/**
 * Read and decode a revision snapshot from relative path.
 * Performs containment check to prevent path traversal.
 *
 * @return array|null Full snapshot array with schema_version, article_id, payload
 */
function editorial_read_revision_snapshot(string $snapshotPath): ?array
{
    if ($snapshotPath === '') {
        return null;
    }

    $basePath = editorial_revisions_base_path();
    $fullPath = $basePath . '/' . ltrim($snapshotPath, '/');

    // Containment check
    $realBase = realpath($basePath);
    if ($realBase === false) {
        return null;
    }

    $realPath = realpath($fullPath);
    if ($realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
        return null;
    }

    if (!is_file($realPath)) {
        return null;
    }

    $json = file_get_contents($realPath);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['payload']) || !is_array($data['payload'])) {
        return null;
    }

    return $data;
}

/**
 * Get and verify a revision snapshot.
 * A4: Validates path containment, JSON, schema_version, article_id match,
 * payload structure, and recomputed content_hash.
 *
 * @param array $revision The revision row from editorial_revisions
 * @return array{ok: bool, payload?: array, message: string}
 */
function editorial_get_verified_revision_snapshot(array $revision): array
{
    $snapshotPath = (string) ($revision['snapshot_path'] ?? '');
    if ($snapshotPath === '') {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    $snapshot = editorial_read_revision_snapshot($snapshotPath);
    if ($snapshot === null) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    // Verify schema_version
    $schemaVersion = $snapshot['schema_version'] ?? null;
    if ($schemaVersion !== 1) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    // Verify article_id matches
    $snapshotArticleId = (string) ($snapshot['article_id'] ?? '');
    $revisionArticleId = (string) ($revision['article_id'] ?? '');
    if ($snapshotArticleId === '' || $snapshotArticleId !== $revisionArticleId) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    // Verify payload is array
    $payload = $snapshot['payload'] ?? null;
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    // Recompute content hash and verify
    $revisionContentHash = (string) ($revision['content_hash'] ?? '');
    if ($revisionContentHash === '') {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    try {
        $recomputedHash = editorial_revision_content_hash($payload);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    if ($recomputedHash !== $revisionContentHash) {
        return ['ok' => false, 'message' => 'Snapshot phiên bản không hợp lệ hoặc đã bị thay đổi.'];
    }

    return ['ok' => true, 'payload' => $payload, 'message' => 'Snapshot hợp lệ.'];
}

// ─── Revision Queries ───────────────────────────────────────────

/**
 * Get article revisions, newest first.
 * Enriches each row with creator_name.
 *
 * @return array<int, array<string,mixed>>
 */
function editorial_get_article_revisions(string $articleId, ?int $limit = null): array
{
    $db = editorial_db();
    $sql = 'SELECT * FROM editorial_revisions WHERE article_id = :aid ORDER BY revision_no DESC';
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute(['aid' => $articleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $user = editorial_find_user_by_id((string) $row['created_by']);
        $row['creator_name'] = $user ? (string) ($user['display_name'] ?? $user['username'] ?? $row['created_by']) : $row['created_by'];
    }
    unset($row);

    return $rows;
}

/**
 * Get a single revision by ID.
 */
function editorial_get_revision(string $revisionId): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_revisions WHERE id = :id');
    $stmt->execute(['id' => $revisionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ─── Baseline Revision ──────────────────────────────────────────

/**
 * Create baseline revision from live HTML.
 * A2 fix: reads exact bytes once, hashes those bytes, parses same bytes.
 * A3 fix: self-queries and verifies active assignment internally.
 */
function editorial_create_baseline_revision(string $articleId, string $userId): array
{
    // Resolve article
    $article = editorial_find_article($articleId);
    if (!$article) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết.'];
    }

    $filePath = editorial_resolve_article_path($article);
    if ($filePath === null || !file_exists($filePath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy file bài viết.'];
    }

    // Check article state
    $state = editorial_get_article_state($articleId);
    if (!$state) {
        return ['ok' => false, 'message' => 'Trạng thái bài viết không hợp lệ.'];
    }

    // A3: Self-verify active assignment
    $assignment = editorial_get_active_assignment($articleId);
    if ($assignment === null) {
        return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo baseline.'];
    }
    if ((string) $assignment['user_id'] !== $userId) {
        return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo baseline.'];
    }
    if ((string) ($state['assigned_user_id'] ?? '') !== $userId) {
        return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo baseline.'];
    }
    $assignmentId = (string) $assignment['id'];

    // A2: Read exact HTML bytes ONCE
    $htmlBytes = file_get_contents($filePath);
    if ($htmlBytes === false || trim($htmlBytes) === '') {
        return ['ok' => false, 'message' => 'Không thể đọc file bài viết.'];
    }

    // A2: Hash exact bytes
    $currentLiveHash = hash('sha256', $htmlBytes);
    $baseLiveHash = (string) ($state['base_live_hash'] ?? '');

    // base_live_hash must not be empty
    if ($baseLiveHash === '') {
        return ['ok' => false, 'message' => 'Dữ liệu hash cơ sở không hợp lệ. Không thể tạo baseline an toàn.'];
    }

    // A2: Hash of exact bytes must match base_live_hash
    if ($currentLiveHash !== $baseLiveHash) {
        editorial_log_activity('article.baseline.skipped_conflict', $articleId, $userId, json_encode([
            'assignment_id' => $assignmentId,
            'base_live_hash' => $baseLiveHash,
            'current_live_hash' => $currentLiveHash,
        ]));
        return ['ok' => false, 'message' => 'File HTML đã thay đổi kể từ khi nhận bài. Không thể tạo baseline an toàn.'];
    }

    // A2: Parse the SAME exact bytes
    $parsed = editorial_parse_article_html($htmlBytes, $filePath);
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Không thể phân tích file bài viết.'];
    }

    $payload = editorial_build_initial_payload($parsed, $article, $parsed['meta_payload'] ?? []);

    try {
        $contentHash = editorial_revision_content_hash($payload);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    // Create inside transaction
    return editorial_transaction(function () use ($articleId, $userId, $assignmentId, $payload, $contentHash): array {
        $db = editorial_db();

        // Check if baseline already exists for this assignment
        $stmt = $db->prepare("SELECT id FROM editorial_revisions WHERE assignment_id = :aid AND revision_type = 'baseline'");
        $stmt->execute(['aid' => $assignmentId]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'message' => 'Bản gốc đã tồn tại cho phiên làm việc này.'];
        }

        // Next revision number
        $stmt = $db->prepare('SELECT MAX(revision_no) FROM editorial_revisions WHERE article_id = :aid');
        $stmt->execute(['aid' => $articleId]);
        $maxNo = (int) $stmt->fetchColumn();
        $revisionNo = $maxNo + 1;

        // Generate ID and write snapshot
        $revisionId = editorial_generate_id('rev');
        $snapshotPath = editorial_write_revision_snapshot($revisionId, $articleId, $payload);

        try {
            $stmt = $db->prepare('
                INSERT INTO editorial_revisions
                (id, article_id, revision_no, revision_type, snapshot_path, content_hash, created_by, created_at, assignment_id, source_draft_version)
                VALUES (:id, :aid, :rno, :rtype, :spath, :chash, :cby, :cat, :asgn, NULL)
            ');
            $stmt->execute([
                'id' => $revisionId,
                'aid' => $articleId,
                'rno' => $revisionNo,
                'rtype' => 'baseline',
                'spath' => $snapshotPath,
                'chash' => $contentHash,
                'cby' => $userId,
                'cat' => date('c'),
                'asgn' => $assignmentId,
            ]);

            editorial_log_activity('article.revision.baseline_created', $articleId, $userId, json_encode([
                'revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'revision_type' => 'baseline',
                'content_hash' => $contentHash,
            ]));

            return ['ok' => true, 'revision_id' => $revisionId, 'revision_no' => $revisionNo];
        } catch (\Throwable $e) {
            $fullPath = editorial_revisions_base_path() . '/' . $snapshotPath;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            throw $e;
        }
    });
}

// ─── Editorial Revision ─────────────────────────────────────────

/**
 * Create editorial revision from current saved draft.
 *
 * Snapshots from the SAVED draft in SQLite, NOT from POST data.
 * All authorization checked inside transaction.
 */
function editorial_create_editorial_revision(
    string $articleId,
    string $userId,
    string $lockToken,
    int $expectedDraftVersion,
    string $note = '',
    ?string $milestoneKey = null,
    bool $updateCurrentRevision = true,
    bool $requireLock = true,
    string $candidatePurpose = ''
): array
{
    $note = trim($note);
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 500 ký tự.'];
    }
    if ($milestoneKey !== null && !in_array($milestoneKey, ['stage1', 'stage2'], true)) {
        return ['ok' => false, 'message' => 'Mốc phiên bản không hợp lệ.'];
    }

    return editorial_transaction(function () use ($articleId, $userId, $lockToken, $expectedDraftVersion, $note, $milestoneKey, $updateCurrentRevision, $requireLock, $candidatePurpose): array {
        $db = editorial_db();

        // 1. Verify assignment + status
        $state = editorial_get_article_state($articleId);
        if (!$state || (string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không phải người phụ trách bài viết này.'];
        }
        if (!in_array((string) $state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái cho phép tạo phiên bản.'];
        }

        // 2. Workspace actions require the exact active lock. External handoff
        // may snapshot an already-saved draft without trusting browser state.
        if ($requireLock) {
            $lockStmt = $db->prepare('SELECT * FROM editorial_locks WHERE article_id = :aid');
            $lockStmt->execute(['aid' => $articleId]);
            $lock = $lockStmt->fetch();

            if (!$lock) {
                return ['ok' => false, 'message' => 'Không có phiên chỉnh sửa nào đang hoạt động.'];
            }
            if ((string) $lock['user_id'] !== $userId) {
                return ['ok' => false, 'message' => 'Phiên chỉnh sửa thuộc về người dùng khác.'];
            }
            if ((string) $lock['lock_token'] !== $lockToken) {
                return ['ok' => false, 'message' => 'Token phiên chỉnh sửa không hợp lệ.'];
            }
            $expiry = strtotime((string) $lock['expires_at']);
            if ($expiry !== false && $expiry < time()) {
                return ['ok' => false, 'message' => 'Phiên chỉnh sửa đã hết hạn. Vui lòng tải lại workspace.'];
            }
        }

        // 3. Load saved draft
        $draft = editorial_get_draft($articleId, $userId);
        if (!$draft) {
            return [
                'ok' => false,
                'message' => !$updateCurrentRevision
                    ? 'Bạn cần Lưu nháp trước khi thực hiện thao tác này.'
                    : 'Không tìm thấy bản nháp. Hãy lưu nháp trước khi tạo phiên bản.',
            ];
        }

        // 4. Verify draft version
        if ((int) ($draft['version'] ?? 0) !== $expectedDraftVersion) {
            return [
                'ok' => false,
                'message' => !$updateCurrentRevision
                    ? 'Bản nháp đã thay đổi trong khi chuẩn bị bản cố định. Vui lòng thử lại.'
                    : 'Nội dung trên màn hình có thể chưa được lưu. Hãy Lưu nháp trước khi hoàn tất chặng.',
            ];
        }

        // 5. Get payload (already decoded by editorial_get_draft)
        $payload = $draft['payload'];
        if (!is_array($payload)) {
            $payload = json_decode((string) $payload, true);
        }
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Dữ liệu bản nháp không hợp lệ.'];
        }

        // 6. Content hash — A5 fail-closed
        try {
            $contentHash = editorial_revision_content_hash($payload);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        // 7. A1: Verify exactly one active assignment — fail-closed
        $assignment = editorial_get_active_assignment($articleId);
        if ($assignment === null) {
            return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo phiên bản.'];
        }
        if ((string) $assignment['user_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo phiên bản.'];
        }
        if ((string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Dữ liệu phân công bài viết không nhất quán. Không thể tạo phiên bản.'];
        }
        $assignmentId = (string) $assignment['id'];

        // 8. Stage state is derived from verified revision chronology. A Stage2
        // only belongs to the active chain when it follows the current Stage1.
        // Re-saving Stage1 while Stage2 is active deliberately creates a new
        // immutable marker, which resets the active chain back to Stage1.
        if ($milestoneKey !== null) {
            $activeStages = editorial_get_active_stage_bundle($articleId, $assignmentId);
            if ($milestoneKey === 'stage2' && $activeStages['stage1'] === null) {
                return ['ok' => false, 'message' => 'Bạn cần hoàn tất Chặng 1 trước khi lưu Chặng 2.'];
            }

            $activeSameStage = $activeStages[$milestoneKey];
            $isStage1Reset = $milestoneKey === 'stage1' && $activeStages['stage2'] !== null;
            if (!$isStage1Reset
                && is_array($activeSameStage)
                && (string) ($activeSameStage['content_hash'] ?? '') === $contentHash
                && !empty(editorial_get_verified_revision_snapshot($activeSameStage)['ok'])) {
                $stageLabel = $milestoneKey === 'stage1' ? 'Chặng 1' : 'Chặng 2';
                return [
                    'ok' => false,
                    'duplicate_revision_id' => (string) ($activeSameStage['id'] ?? ''),
                    'message' => 'Nội dung không thay đổi so với bản ' . $stageLabel . ' gần nhất.',
                ];
            }
        } elseif (!$updateCurrentRevision) {
            $stmt = $db->prepare("
                SELECT * FROM editorial_revisions
                WHERE article_id = :article_id
                  AND assignment_id = :assignment_id
                  AND revision_type = 'editorial'
                  AND source_draft_version = :source_draft_version
                  AND content_hash = :content_hash
                ORDER BY revision_no DESC
            ");
            $stmt->execute([
                ':article_id' => $articleId,
                ':assignment_id' => $assignmentId,
                ':source_draft_version' => (int) $draft['version'],
                ':content_hash' => $contentHash,
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $existingRevision) {
                $snapshotResult = editorial_get_verified_revision_snapshot($existingRevision);
                if (!$snapshotResult['ok']) {
                    continue;
                }
                $existingMilestone = (string) ($existingRevision['milestone_key'] ?? '');
                return [
                    'ok' => true,
                    'revision_id' => (string) $existingRevision['id'],
                    'revision_no' => (int) $existingRevision['revision_no'],
                    'reused' => true,
                    'candidate_origin' => in_array($existingMilestone, ['stage1', 'stage2'], true)
                        ? $existingMilestone
                        : 'reused_editorial',
                    'message' => 'Đã dùng lại bản cố định khớp với bản nháp đã lưu.',
                ];
            }
        }

        // 9. Next revision number
        $stmt = $db->prepare('SELECT MAX(revision_no) FROM editorial_revisions WHERE article_id = :aid');
        $stmt->execute(['aid' => $articleId]);
        $maxNo = (int) $stmt->fetchColumn();
        $revisionNo = $maxNo + 1;

        // 10. Generate ID and write snapshot
        $revisionId = editorial_generate_id('rev');
        $snapshotPath = editorial_write_revision_snapshot($revisionId, $articleId, $payload);

        try {
            // 11. Insert immutable revision from the saved draft.
            $stmt = $db->prepare('
                INSERT INTO editorial_revisions
                (id, article_id, revision_no, revision_type, snapshot_path, content_hash, created_by, created_at, assignment_id, source_draft_version, note, milestone_key)
                VALUES (:id, :aid, :rno, :rtype, :spath, :chash, :cby, :cat, :asgn, :sdv, :note, :milestone_key)
            ');
            $stmt->execute([
                'id' => $revisionId,
                'aid' => $articleId,
                'rno' => $revisionNo,
                'rtype' => 'editorial',
                'spath' => $snapshotPath,
                'chash' => $contentHash,
                'cby' => $userId,
                'cat' => date('c'),
                'asgn' => $assignmentId,
                'sdv' => (int) $draft['version'],
                'note' => $note !== '' ? $note : null,
                'milestone_key' => $milestoneKey,
            ]);

            // 12. Milestones advance the workflow pointer. Auto publish
            // candidates stay independent so review/compare semantics remain intact.
            if ($updateCurrentRevision) {
                $stmt = $db->prepare('UPDATE editorial_article_state SET current_revision_id = :rid WHERE article_id = :aid');
                $stmt->execute(['rid' => $revisionId, 'aid' => $articleId]);
            }

            // 13. Activity log (no full prose, no token)
            editorial_log_activity(
                $milestoneKey === null && !$updateCurrentRevision
                    ? 'article.revision.' . ($candidatePurpose !== '' ? $candidatePurpose : 'external') . '_candidate_created'
                    : ($milestoneKey === null ? 'article.revision.created' : 'article.revision.' . $milestoneKey . '_created'),
                $articleId,
                $userId,
                json_encode([
                'revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'revision_type' => 'editorial',
                'milestone_key' => $milestoneKey,
                'source_draft_version' => (int) $draft['version'],
                'content_hash' => $contentHash,
                'candidate_origin' => $milestoneKey === null && !$updateCurrentRevision ? 'saved_draft_snapshot' : null,
                'candidate_purpose' => $candidatePurpose !== '' ? $candidatePurpose : null,
            ]));

            $message = $milestoneKey === 'stage1'
                ? 'Đã lưu Chặng 1 — bản sau chuẩn hóa trình bày.'
                : ($milestoneKey === 'stage2'
                    ? 'Đã lưu Chặng 2 — bản sau biên tập nội dung.'
                    : 'Tạo phiên bản #' . $revisionNo . ' thành công.');
            return [
                'ok' => true,
                'revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'candidate_origin' => $milestoneKey === null && !$updateCurrentRevision ? 'saved_draft_snapshot' : $milestoneKey,
                'message' => $message,
            ];
        } catch (\Throwable $e) {
            $fullPath = editorial_revisions_base_path() . '/' . $snapshotPath;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            throw $e;
        }
    });
}

/**
 * Prepare an immutable editor-direct Publish candidate from the current saved
 * draft. It reuses an exact verified revision when possible and otherwise
 * creates a revision without changing current_revision_id.
 */
function editorial_prepare_saved_draft_candidate(
    string $articleId,
    string $userId,
    string $lockToken,
    string $purpose,
    bool $requireLock = true
): array
{
    $draft = editorial_get_draft($articleId, $userId);
    if (!$draft) {
        return ['ok' => false, 'message' => 'Bạn cần Lưu nháp trước khi thực hiện thao tác này.'];
    }
    $result = editorial_create_editorial_revision(
        $articleId,
        $userId,
        $lockToken,
        (int) ($draft['version'] ?? 0),
        '',
        null,
        false,
        $requireLock,
        $purpose
    );
    if (!$result['ok']) {
        return $result;
    }

    $revision = editorial_get_revision((string) ($result['revision_id'] ?? ''));
    if (!$revision) {
        return ['ok' => false, 'message' => 'Không tìm thấy bản cố định từ nháp đã lưu.'];
    }
    $snapshotResult = editorial_get_verified_revision_snapshot($revision);
    if (!$snapshotResult['ok']) {
        return ['ok' => false, 'message' => 'Snapshot bản cố định không hợp lệ: ' . $snapshotResult['message']];
    }

    return [
        'ok' => true,
        'revision_id' => (string) $revision['id'],
        'candidate_origin' => (string) ($result['candidate_origin'] ?? 'saved_draft_snapshot'),
    ];
}

function editorial_prepare_saved_draft_publish_candidate(string $articleId, string $userId, string $lockToken): array
{
    return editorial_prepare_saved_draft_candidate($articleId, $userId, $lockToken, 'publish', true);
}

function editorial_prepare_saved_draft_review_candidate(string $articleId, string $userId, string $lockToken): array
{
    return editorial_prepare_saved_draft_candidate($articleId, $userId, $lockToken, 'review', true);
}

function editorial_create_stage_milestone_revision(string $articleId, string $userId, string $lockToken, int $expectedDraftVersion, string $milestoneKey, string $note = ''): array
{
    $result = editorial_create_editorial_revision($articleId, $userId, $lockToken, $expectedDraftVersion, $note, $milestoneKey);
    $duplicateRevisionId = (string) ($result['duplicate_revision_id'] ?? '');
    if (!empty($result['ok']) || $duplicateRevisionId === '') {
        return $result;
    }
    return editorial_transaction(function () use ($articleId, $userId, $lockToken, $milestoneKey, $duplicateRevisionId, $result): array {
        $state = editorial_get_article_state($articleId);
        $assignment = editorial_get_active_assignment($articleId);
        $revision = editorial_get_revision($duplicateRevisionId);
        $lock = editorial_get_article_lock($articleId);
        if (!$state || !$assignment || !$revision
            || (string) ($state['assigned_user_id'] ?? '') !== $userId
            || !in_array((string) ($state['status'] ?? ''), ['editing', 'returned'], true)
            || !$lock
            || (string) ($lock['user_id'] ?? '') !== $userId
            || (string) ($lock['lock_token'] ?? '') !== $lockToken
            || strtotime((string) ($lock['expires_at'] ?? '')) <= time()
            || (string) ($revision['article_id'] ?? '') !== $articleId
            || (string) ($revision['assignment_id'] ?? '') !== (string) $assignment['id']
            || (string) ($assignment['user_id'] ?? '') !== $userId
            || (string) ($revision['milestone_key'] ?? '') !== $milestoneKey
            || empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            return $result;
        }
        editorial_db()->prepare('UPDATE editorial_article_state SET current_revision_id = :rid, updated_at = :updated_at WHERE article_id = :aid')
            ->execute(['rid' => $duplicateRevisionId, 'updated_at' => date('c'), 'aid' => $articleId]);
        return [
            'ok' => true,
            'revision_id' => $duplicateRevisionId,
            'revision_no' => (int) ($revision['revision_no'] ?? 0),
            'reused' => true,
            'message' => $result['message'] ?? 'Đã dùng lại milestone hiện có.',
        ];
    });
}

/**
 * Return the coherent active stage chain for one assignment.
 *
 * Active Stage1 is the newest verified Stage1. Active Stage2 is the newest
 * verified Stage2 created after that Stage1. Historical snapshots remain
 * immutable but are excluded when their chronology no longer forms a chain.
 *
 * @return array{stage1:?array,stage2:?array}
 */
function editorial_get_active_stage_bundle(string $articleId, string $assignmentId): array
{
    $result = ['stage1' => null, 'stage2' => null];
    if ($assignmentId === '') {
        return $result;
    }

    $db = editorial_db();
    $stmt = $db->prepare("
        SELECT * FROM editorial_revisions
        WHERE article_id = :article_id
          AND assignment_id = :assignment_id
          AND revision_type = 'editorial'
          AND milestone_key = 'stage1'
        ORDER BY revision_no DESC
    ");
    $stmt->execute([':article_id' => $articleId, ':assignment_id' => $assignmentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $revision) {
        if (!empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            $result['stage1'] = $revision;
            break;
        }
    }

    if ($result['stage1'] === null) {
        return $result;
    }

    $stage2Stmt = $db->prepare("
        SELECT * FROM editorial_revisions
        WHERE article_id = :article_id
          AND assignment_id = :assignment_id
          AND revision_type = 'editorial'
          AND milestone_key = 'stage2'
          AND revision_no > :stage1_revision_no
        ORDER BY revision_no DESC
    ");
    $stage2Stmt->execute([
        ':article_id' => $articleId,
        ':assignment_id' => $assignmentId,
        ':stage1_revision_no' => (int) $result['stage1']['revision_no'],
    ]);
    foreach ($stage2Stmt->fetchAll(PDO::FETCH_ASSOC) as $revision) {
        if (!empty(editorial_get_verified_revision_snapshot($revision)['ok'])) {
            $result['stage2'] = $revision;
            break;
        }
    }

    return $result;
}

/**
 * Backward-compatible name for callers that need the active workflow state.
 *
 * @return array{stage1:?array,stage2:?array}
 */
function editorial_get_assignment_milestones(string $articleId, string $assignmentId): array
{
    return editorial_get_active_stage_bundle($articleId, $assignmentId);
}

// ─── Revision Type Label ────────────────────────────────────────

/**
 * Vietnamese label for revision type.
 */
function editorial_revision_type_label(string $type): string
{
    return match ($type) {
        'baseline' => 'Bản gốc',
        'editorial' => 'Bản biên tập',
        'published' => 'Đã xuất bản',
        'restore' => 'Khôi phục',
        default => $type,
    };
}

function editorial_revision_label(array $revision): string
{
    return match ((string) ($revision['milestone_key'] ?? '')) {
        'stage1' => 'Chặng 1 — Chuẩn hóa trình bày',
        'stage2' => 'Chặng 2 — Biên tập nội dung',
        default => editorial_revision_type_label((string) ($revision['revision_type'] ?? '')),
    };
}

// ─── Simple Line Diff ───────────────────────────────────────────

/**
 * Simple line-based diff with performance guard.
 *
 * Uses LCS for small content, falls back to del-all/add-all for large content.
 *
 * @return array<int, array{type: string, line: string}>
 */
function editorial_simple_diff(string $oldText, string $newText, int $maxTokens = 5000): array
{
    $oldLines = explode("\n", str_replace("\r", '', $oldText));
    $newLines = explode("\n", str_replace("\r", '', $newText));

    $m = count($oldLines);
    $n = count($newLines);

    // Performance guard: fallback for very large content
    if ($m + $n > (int) ($maxTokens / 10)) {
        $diff = [];
        foreach ($oldLines as $line) {
            $diff[] = ['type' => 'del', 'line' => $line];
        }
        foreach ($newLines as $line) {
            $diff[] = ['type' => 'add', 'line' => $line];
        }
        return $diff;
    }

    // LCS matrix
    $matrix = [];
    for ($i = 0; $i <= $m; $i++) {
        $matrix[$i] = array_fill(0, $n + 1, 0);
    }

    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            if ($oldLines[$i - 1] === $newLines[$j - 1]) {
                $matrix[$i][$j] = $matrix[$i - 1][$j - 1] + 1;
            } else {
                $matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
            }
        }
    }

    // Backtrack
    $diff = [];
    $i = $m;
    $j = $n;

    while ($i > 0 || $j > 0) {
        if ($i > 0 && $j > 0 && $oldLines[$i - 1] === $newLines[$j - 1]) {
            $diff[] = ['type' => 'same', 'line' => $oldLines[$i - 1]];
            $i--;
            $j--;
        } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
            $diff[] = ['type' => 'add', 'line' => $newLines[$j - 1]];
            $j--;
        } elseif ($i > 0) {
            $diff[] = ['type' => 'del', 'line' => $oldLines[$i - 1]];
            $i--;
        }
    }

    return array_reverse($diff);
}
