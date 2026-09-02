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
 */
function editorial_revision_content_hash(array $payload): string
{
    $canonical = editorial_stable_sort_keys($payload);
    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{}';
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
    if ($written === false) {
        throw new RuntimeException('Không thể ghi snapshot tạm.');
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
 *
 * Only creates if current_live_hash matches base_live_hash.
 * Skips silently (with diagnostic log) on conflict.
 */
function editorial_create_baseline_revision(string $articleId, string $userId, string $assignmentId): array
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

    // Check live hash vs base hash
    $state = editorial_get_article_state($articleId);
    if (!$state) {
        return ['ok' => false, 'message' => 'Trạng thái bài viết không hợp lệ.'];
    }

    $currentLiveHash = editorial_live_hash($filePath);
    $baseLiveHash = (string) ($state['base_live_hash'] ?? '');

    if ($baseLiveHash !== '' && $currentLiveHash !== null && $currentLiveHash !== $baseLiveHash) {
        // Live content changed — cannot safely create baseline
        editorial_log_activity('article.baseline.skipped_conflict', $articleId, $userId, json_encode([
            'assignment_id' => $assignmentId,
            'base_live_hash' => $baseLiveHash,
            'current_live_hash' => $currentLiveHash,
        ]));
        return ['ok' => false, 'message' => 'File HTML đã thay đổi kể từ khi nhận bài. Không thể tạo baseline an toàn.'];
    }

    // Parse HTML
    $parsed = editorial_parse_article_file($filePath);
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Không thể phân tích file bài viết.'];
    }

    $payload = editorial_build_initial_payload($parsed, $article, $parsed['meta_payload'] ?? []);
    $contentHash = editorial_revision_content_hash($payload);

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
            // Best-effort cleanup
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
function editorial_create_editorial_revision(string $articleId, string $userId, string $lockToken, int $expectedDraftVersion, string $note = ''): array
{
    $note = trim($note);
    if (mb_strlen($note) > 500) {
        return ['ok' => false, 'message' => 'Ghi chú không được vượt quá 500 ký tự.'];
    }

    return editorial_transaction(function () use ($articleId, $userId, $lockToken, $expectedDraftVersion, $note): array {
        $db = editorial_db();

        // 1. Verify assignment + status
        $state = editorial_get_article_state($articleId);
        if (!$state || (string) ($state['assigned_user_id'] ?? '') !== $userId) {
            return ['ok' => false, 'message' => 'Bạn không phải người phụ trách bài viết này.'];
        }
        if (!in_array((string) $state['status'], ['editing', 'returned'], true)) {
            return ['ok' => false, 'message' => 'Bài viết không ở trạng thái cho phép tạo phiên bản.'];
        }

        // 2. Validate lock inside transaction
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

        // 3. Load saved draft
        $draft = editorial_get_draft($articleId, $userId);
        if (!$draft) {
            return ['ok' => false, 'message' => 'Không tìm thấy bản nháp. Hãy lưu nháp trước khi tạo phiên bản.'];
        }

        // 4. Verify draft version
        if ((int) ($draft['version'] ?? 0) !== $expectedDraftVersion) {
            return ['ok' => false, 'message' => 'Hãy lưu nháp trước khi tạo phiên bản.'];
        }

        // 5. Get payload (already decoded by editorial_get_draft)
        $payload = $draft['payload'];
        if (!is_array($payload)) {
            $payload = json_decode((string) $payload, true);
        }
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Dữ liệu bản nháp không hợp lệ.'];
        }

        // 6. Content hash
        $contentHash = editorial_revision_content_hash($payload);

        // 7. Duplicate check
        $stmt = $db->prepare("
            SELECT content_hash FROM editorial_revisions
            WHERE article_id = :aid AND revision_type = 'editorial'
            ORDER BY revision_no DESC LIMIT 1
        ");
        $stmt->execute(['aid' => $articleId]);
        $latestHash = $stmt->fetchColumn();

        if ($latestHash !== false && $latestHash === $contentHash) {
            return ['ok' => false, 'message' => 'Nội dung không thay đổi so với phiên bản gần nhất.'];
        }

        // 8. Get assignment
        $assignment = editorial_get_active_assignment($articleId);
        $assignmentId = $assignment ? (string) $assignment['id'] : null;

        // 9. Next revision number
        $stmt = $db->prepare('SELECT MAX(revision_no) FROM editorial_revisions WHERE article_id = :aid');
        $stmt->execute(['aid' => $articleId]);
        $maxNo = (int) $stmt->fetchColumn();
        $revisionNo = $maxNo + 1;

        // 10. Generate ID and write snapshot
        $revisionId = editorial_generate_id('rev');
        $snapshotPath = editorial_write_revision_snapshot($revisionId, $articleId, $payload);

        try {
            // 11. Insert revision
            $stmt = $db->prepare('
                INSERT INTO editorial_revisions
                (id, article_id, revision_no, revision_type, snapshot_path, content_hash, created_by, created_at, assignment_id, source_draft_version, note)
                VALUES (:id, :aid, :rno, :rtype, :spath, :chash, :cby, :cat, :asgn, :sdv, :note)
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
            ]);

            // 12. Update article state
            $stmt = $db->prepare('UPDATE editorial_article_state SET current_revision_id = :rid WHERE article_id = :aid');
            $stmt->execute(['rid' => $revisionId, 'aid' => $articleId]);

            // 13. Activity log (no full prose, no token)
            editorial_log_activity('article.revision.created', $articleId, $userId, json_encode([
                'revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'revision_type' => 'editorial',
                'source_draft_version' => (int) $draft['version'],
                'content_hash' => $contentHash,
            ]));

            return [
                'ok' => true,
                'revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'message' => 'Tạo phiên bản #' . $revisionNo . ' thành công.',
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
