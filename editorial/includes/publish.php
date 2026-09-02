<?php
declare(strict_types=1);

/**
 * Editorial V2 Phase 7 — Publish Service Module (Hardened 7.1).
 *
 * Handles safe publish of approved revisions to original live HTML files.
 * Only admin can publish. Only approved revision can be published.
 *
 * Key invariants:
 * - Writes to THE ORIGINAL HTML file (not a copy)
 * - Backup before write (both live HTML and data/articles.json)
 * - Atomic file operations (temp + rename in same directory)
 * - Post-write verification with full parse/field re-check
 * - Automatic compensation + DB rollback (throw) on failure after destructive point
 * - Taxonomy fields NOT overwritten
 * - Permission preservation on replaced files
 *
 * Failure strategy:
 * - BEFORE live file replace: return ['ok'=>false] (no destructive state)
 * - AFTER live file replace: compensate filesystem THEN throw RuntimeException
 *   so editorial_transaction() catches Throwable → ROLLBACK → rethrow
 *   Outer code catches → logs article.publish.failed → returns structured failure
 */

// ─── Exception for post-destructive failures (triggers DB rollback) ──────────

class EditorialPublishCompensationException extends RuntimeException
{
    public array $context;
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message);
        $this->context = $context;
    }
}

// ─── Tags Parser ─────────────────────────────────────────────────────────────

function editorial_parse_tags_text(string $tagsText): array
{
    $parts = explode(',', $tagsText);
    $result = [];
    $seen = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed === '') {
            continue;
        }
        $lower = mb_strtolower($trimmed, 'UTF-8');
        if (!isset($seen[$lower])) {
            $seen[$lower] = true;
            $result[] = $trimmed;
        }
    }
    return $result;
}

// ─── Structured Preflight ────────────────────────────────────────────────────

function editorial_publish_preflight(string $articleId, string $adminUserId): array
{
    $checks = [
        'actor'      => ['pass' => false, 'label' => 'Admin hợp lệ'],
        'article'    => ['pass' => false, 'label' => 'Bài viết tồn tại'],
        'path'       => ['pass' => false, 'label' => 'File HTML hợp lệ'],
        'status'     => ['pass' => false, 'label' => 'Trạng thái approved'],
        'revision'   => ['pass' => false, 'label' => 'Phiên bản đã duyệt'],
        'snapshot'   => ['pass' => false, 'label' => 'Snapshot toàn vẹn'],
        'assignment' => ['pass' => false, 'label' => 'Phân công hợp lệ'],
        'live_hash'  => ['pass' => false, 'label' => 'Live hash khớp'],
        'lock'       => ['pass' => false, 'label' => 'Không có khóa chỉnh sửa'],
        'backup_dir' => ['pass' => false, 'label' => 'Thư mục backup ghi được'],
    ];

    $failResult = function(string $failedCheck, string $message) use (&$checks): array {
        return ['ok' => false, 'message' => $message, 'checks' => $checks,
                'failed_at' => $failedCheck];
    };

    // 1. Re-verify admin actor
    $admin = editorial_find_user_by_id($adminUserId);
    if (!$admin || empty($admin['is_active']) || $admin['role'] !== 'admin') {
        return $failResult('actor', 'Người dùng không hợp lệ hoặc không có quyền admin.');
    }
    $checks['actor']['pass'] = true;

    // 2. Find article in catalog
    $article = editorial_find_article($articleId);
    if (!$article) {
        return $failResult('article', 'Bài viết không tồn tại trong danh mục.');
    }
    $checks['article']['pass'] = true;

    // 3. Resolve article file path with boundary containment
    $filePath = editorial_resolve_article_path($article);
    if (!$filePath || !file_exists($filePath)) {
        return $failResult('path', 'Không tìm thấy file HTML bài viết.');
    }
    $realRepoRoot = realpath(dirname(dirname(__DIR__)));
    $realFilePath = realpath($filePath);
    if ($realFilePath === false || $realRepoRoot === false
        || strpos($realFilePath, $realRepoRoot . DIRECTORY_SEPARATOR) !== 0) {
        return $failResult('path', 'Đường dẫn file HTML nằm ngoài repo.');
    }
    $checks['path']['pass'] = true;

    // 4. Get article state, verify status='approved'
    $state = editorial_get_article_state($articleId);
    if (!$state || $state['status'] !== 'approved') {
        return $failResult('status', 'Bài viết chưa được duyệt.');
    }
    $approvedRevisionId = $state['approved_revision_id'] ?? '';
    if ($approvedRevisionId === '') {
        return $failResult('status', 'Không tìm thấy phiên bản đã duyệt.');
    }
    if (($state['current_revision_id'] ?? '') !== $approvedRevisionId) {
        return $failResult('status', 'Phiên bản hiện tại không khớp phiên bản đã duyệt.');
    }
    $checks['status']['pass'] = true;

    // 5. Load revision, verify type & article_id
    $revision = editorial_get_revision($approvedRevisionId);
    if (!$revision || $revision['revision_type'] !== 'editorial' || $revision['article_id'] !== $articleId) {
        return $failResult('revision', 'Phiên bản đã duyệt không hợp lệ.');
    }
    $checks['revision']['pass'] = true;

    // 6. Verified snapshot
    $snapshotResult = editorial_get_verified_revision_snapshot($revision);
    if (!$snapshotResult['ok']) {
        return $failResult('snapshot', 'Snapshot không hợp lệ: ' . $snapshotResult['message']);
    }
    $verifiedPayload = $snapshotResult['payload'];
    $checks['snapshot']['pass'] = true;

    // 7. Active assignment matching revision
    $assignment = editorial_get_active_assignment($articleId);
    if (!$assignment || $assignment['id'] !== $revision['assignment_id']) {
        return $failResult('assignment', 'Assignment không khớp với phiên bản đã duyệt.');
    }
    if ($assignment['user_id'] !== ($state['assigned_user_id'] ?? '')) {
        return $failResult('assignment', 'Assignment user không khớp state.');
    }
    $checks['assignment']['pass'] = true;

    // 8. Verify base_live_hash and read live HTML ONCE
    $baseLiveHash = $state['base_live_hash'] ?? '';
    if ($baseLiveHash === '') {
        return $failResult('live_hash', 'Không có base live hash.');
    }
    $liveHtml = file_get_contents($filePath);
    if ($liveHtml === false) {
        return $failResult('live_hash', 'Lỗi đọc nội dung file HTML hiện tại.');
    }
    $currentLiveHash = hash('sha256', $liveHtml);
    if ($currentLiveHash !== $baseLiveHash) {
        return $failResult('live_hash', 'File HTML đã bị thay đổi bên ngoài (hash mismatch).');
    }
    $checks['live_hash']['pass'] = true;

    // 9. No active editing lock
    $db = editorial_db();
    $stmt = $db->prepare('SELECT 1 FROM editorial_locks WHERE article_id = :aid LIMIT 1');
    $stmt->execute([':aid' => $articleId]);
    if ($stmt->fetch()) {
        return $failResult('lock', 'Bài viết đang bị khóa chỉnh sửa.');
    }
    $checks['lock']['pass'] = true;

    // 10. Backup directory writable
    $backupBase = editorial_publish_backup_base_path();
    if (!is_dir($backupBase)) {
        @mkdir($backupBase, 0755, true);
    }
    if (!is_dir($backupBase) || !is_writable($backupBase)) {
        return $failResult('backup_dir', 'Thư mục backup không ghi được.');
    }
    $checks['backup_dir']['pass'] = true;

    return [
        'ok' => true,
        'message' => 'Preflight passed.',
        'checks' => $checks,
        'article' => $article,
        'state' => $state,
        'revision' => $revision,
        'payload' => $verifiedPayload,
        'assignment' => $assignment,
        'live_html' => $liveHtml,
        'live_hash' => $currentLiveHash,
        'file_path' => $filePath,
    ];
}

// ─── Pure Render ─────────────────────────────────────────────────────────────

function editorial_render_approved_html(string $liveHtml, array $article, array $approvedPayload): array
{
    // 1. Parse liveHtml
    $parsed = editorial_parse_article_html($liveHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Lỗi parse HTML hiện tại: ' . ($parsed['message'] ?? '')];
    }

    if (!isset($parsed['prose']['open_tag_end'], $parsed['prose']['close_tag_start'])) {
        return ['ok' => false, 'message' => 'Không tìm thấy vùng article-prose.'];
    }

    // 2. Replace .article-prose inner HTML (offset-based, not regex)
    $proseOpenEnd = $parsed['prose']['open_tag_end'];
    $proseCloseStart = $parsed['prose']['close_tag_start'];
    $newProse = $approvedPayload['prose_html'] ?? '';
    $html = substr($liveHtml, 0, $proseOpenEnd) . "\n" . $newProse . "\n" . substr($liveHtml, $proseCloseStart);

    // 3. Update script#article-meta JSON — re-parse after prose replacement
    $parsed2 = editorial_parse_article_html($html, '');
    if (!$parsed2['ok'] || !isset($parsed2['meta']['open_tag_end'], $parsed2['meta']['close_tag_start'])) {
        return ['ok' => false, 'message' => 'Lỗi parse HTML sau khi chèn prose.'];
    }

    $currentMeta = $parsed2['meta_payload'] ?? [];
    if (!is_array($currentMeta)) {
        return ['ok' => false, 'message' => 'Meta JSON hiện tại không hợp lệ.'];
    }

    // Only update editable keys — NEVER touch taxonomy
    $currentMeta['title'] = $approvedPayload['title'] ?? '';
    $currentMeta['publishDate'] = $approvedPayload['publish_date'] ?? '';
    $currentMeta['modifiedDate'] = $approvedPayload['modified_date'] ?? '';
    $currentMeta['tags'] = editorial_parse_tags_text($approvedPayload['tags_text'] ?? '');
    $currentMeta['excerpt'] = $approvedPayload['excerpt'] ?? '';
    if (isset($approvedPayload['featured_image'])) {
        $currentMeta['image'] = $approvedPayload['featured_image'];
    }

    $newMetaJson = json_encode($currentMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($newMetaJson === false) {
        return ['ok' => false, 'message' => 'Lỗi encode JSON mới.'];
    }

    $metaOpenEnd = $parsed2['meta']['open_tag_end'];
    $metaCloseStart = $parsed2['meta']['close_tag_start'];
    $html = substr($html, 0, $metaOpenEnd) . "\n" . $newMetaJson . "\n" . substr($html, $metaCloseStart);

    // 4. Update <title> — legacy contract: {title} | {section_label} | Kế Toán Diệu Tâm
    $newTitle = $approvedPayload['title'] ?? '';
    $sectionLabel = (string) ($currentMeta['sectionLabel'] ?? $article['sectionLabel'] ?? '');
    $titleTag = $newTitle;
    if ($sectionLabel !== '') {
        $titleTag .= ' | ' . $sectionLabel . ' | Kế Toán Diệu Tâm';
    }
    $html = preg_replace(
        '/<title>.*?<\/title>/is',
        '<title>' . htmlspecialchars($titleTag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>',
        $html, 1
    ) ?? $html;

    // 5. Update meta description — legacy contract: exact attribute reconstruction
    $descEscaped = htmlspecialchars($approvedPayload['excerpt'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace(
        '/<meta\s+name="description"\s+content=".*?">/is',
        '<meta name="description" content="' . $descEscaped . '">',
        $html, 1
    ) ?? $html;

    // 6. Update .article-summary — legacy contract with proper capture groups
    $summaryEscaped = htmlspecialchars($approvedPayload['excerpt'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace(
        '/(<p\b[^>]*class=(["\'])(?:(?!\2).)*\barticle-summary\b(?:(?!\2).)*\2[^>]*>).*?(<\/p>)/is',
        '$1' . $summaryEscaped . '$3',
        $html, 1
    ) ?? $html;

    // 7. Cache busting on JS files
    $assetVersion = date('YmdHis');
    $html = preg_replace_callback(
        '/(<script[^>]+src=["\'])([^"\']*(?:article-layout\.js|data\/article-views\/[^"\']*\.js))(\?v=[^"\']*)?(["\'])/i',
        function($m) use ($assetVersion) {
            return $m[1] . $m[2] . '?v=' . $assetVersion . $m[4];
        },
        $html
    ) ?? $html;

    return ['ok' => true, 'html' => $html];
}

// ─── Pre-write Validation (Enhanced) ─────────────────────────────────────────

function editorial_validate_rendered_html(string $newHtml, array $approvedPayload): array
{
    $parsed = editorial_parse_article_html($newHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Rendered HTML parse failed: ' . ($parsed['message'] ?? '')];
    }

    // Verify prose region exists and contains expected content
    $renderedProse = trim($parsed['prose']['inner'] ?? '');
    $expectedProse = trim($approvedPayload['prose_html'] ?? '');
    if ($renderedProse !== $expectedProse) {
        return ['ok' => false, 'message' => 'Rendered prose mismatch: nội dung prose sau render không khớp approved.'];
    }

    // Verify meta region exists and JSON is valid with correct editable fields
    $metaPayload = $parsed['meta_payload'] ?? null;
    if (!is_array($metaPayload)) {
        return ['ok' => false, 'message' => 'Rendered HTML meta JSON invalid.'];
    }
    if (($metaPayload['title'] ?? '') !== ($approvedPayload['title'] ?? '')) {
        return ['ok' => false, 'message' => 'Rendered meta title mismatch.'];
    }
    if (($metaPayload['excerpt'] ?? '') !== ($approvedPayload['excerpt'] ?? '')) {
        return ['ok' => false, 'message' => 'Rendered meta excerpt mismatch.'];
    }
    if (($metaPayload['publishDate'] ?? '') !== ($approvedPayload['publish_date'] ?? '')) {
        return ['ok' => false, 'message' => 'Rendered meta publishDate mismatch.'];
    }
    if (($metaPayload['modifiedDate'] ?? '') !== ($approvedPayload['modified_date'] ?? '')) {
        return ['ok' => false, 'message' => 'Rendered meta modifiedDate mismatch.'];
    }
    $expectedTags = editorial_parse_tags_text($approvedPayload['tags_text'] ?? '');
    $renderedTags = $metaPayload['tags'] ?? [];
    if ($renderedTags !== $expectedTags) {
        return ['ok' => false, 'message' => 'Rendered meta tags mismatch.'];
    }
    if (isset($approvedPayload['featured_image']) && ($metaPayload['image'] ?? '') !== $approvedPayload['featured_image']) {
        return ['ok' => false, 'message' => 'Rendered meta image mismatch.'];
    }

    // Verify .article-summary text matches excerpt
    $summaryText = $parsed['summary_text'] ?? '';
    $expectedExcerpt = $approvedPayload['excerpt'] ?? '';
    if ($summaryText !== '' && $expectedExcerpt !== '') {
        $normalizedSummary = trim(html_entity_decode(strip_tags($summaryText), ENT_QUOTES, 'UTF-8'));
        $normalizedExcerpt = trim($expectedExcerpt);
        if ($normalizedSummary !== $normalizedExcerpt) {
            return ['ok' => false, 'message' => 'Rendered article-summary text mismatch.'];
        }
    }

    // Verify <title> contains the approved title
    if (preg_match('/<title>(.*?)<\/title>/is', $newHtml, $tm)) {
        $renderedTitle = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
        $expectedTitle = $approvedPayload['title'] ?? '';
        if ($expectedTitle !== '' && strpos($renderedTitle, $expectedTitle) === false) {
            return ['ok' => false, 'message' => 'Rendered <title> không chứa approved title.'];
        }
    }

    return ['ok' => true];
}

// ─── Backup Helpers ──────────────────────────────────────────────────────────

function editorial_publish_backup_base_path(): string
{
    return dirname(__DIR__) . '/storage/backups';
}

function editorial_create_publish_backup(string $liveHtml, string $articleId, string $expectedHash): array
{
    $base = editorial_publish_backup_base_path();
    $articleHash = hash('sha256', $articleId);
    $prefix = substr($articleHash, 0, 2);
    $dir = $base . '/' . $prefix . '/' . $articleHash;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Lỗi tạo thư mục backup.'];
        }
    }

    $filename = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.html';
    $tempPath = $dir . '/.tmp.' . $filename;
    $finalPath = $dir . '/' . $filename;

    $written = file_put_contents($tempPath, $liveHtml);
    if ($written !== strlen($liveHtml)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file backup (byte count mismatch).'];
    }

    if (!rename($tempPath, $finalPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file backup.'];
    }

    $writtenHash = hash_file('sha256', $finalPath);
    if ($writtenHash !== $expectedHash) {
        @unlink($finalPath);
        return ['ok' => false, 'message' => 'Hash file backup không khớp.'];
    }

    $relativePath = 'backups/' . $prefix . '/' . $articleHash . '/' . $filename;
    return ['ok' => true, 'path' => $relativePath, 'absolute_path' => $finalPath];
}

// ─── Atomic File Operations ──────────────────────────────────────────────────

function editorial_atomic_replace_file(string $targetPath, string $newHtml, string $expectedCurrentHash): array
{
    $dir = dirname($targetPath);
    $tempPath = $dir . '/.publish_tmp_' . bin2hex(random_bytes(8)) . '.html';

    // Capture current permissions before replace
    $oldPerms = @fileperms($targetPath);

    $written = file_put_contents($tempPath, $newHtml);
    if ($written !== strlen($newHtml)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file tạm (byte count mismatch).'];
    }

    // Best-effort: preserve original file permissions on temp before rename
    if ($oldPerms !== false) {
        @chmod($tempPath, $oldPerms & 0777);
    }

    // FINAL optimistic lock: re-read target, re-hash, verify IMMEDIATELY before rename
    $currentHash = hash_file('sha256', $targetPath);
    if ($currentHash !== $expectedCurrentHash) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Hash file hiện tại đã thay đổi. Ngừng ghi.'];
    }

    if (!rename($tempPath, $targetPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file tạm.'];
    }

    return ['ok' => true, 'bytes_written' => $written];
}

function editorial_restore_backup(string $targetPath, string $backupAbsolutePath, string $expectedBackupHash): array
{
    if (!file_exists($backupAbsolutePath)) {
        return ['ok' => false, 'message' => 'File backup không tồn tại.'];
    }

    $currentBackupHash = hash_file('sha256', $backupAbsolutePath);
    if ($currentBackupHash !== $expectedBackupHash) {
        return ['ok' => false, 'message' => 'Hash file backup không khớp, không thể restore.'];
    }

    $backupBytes = file_get_contents($backupAbsolutePath);
    if ($backupBytes === false) {
        return ['ok' => false, 'message' => 'Lỗi đọc file backup.'];
    }

    $dir = dirname($targetPath);
    $tempPath = $dir . '/.restore_tmp_' . bin2hex(random_bytes(8)) . '.html';
    $written = file_put_contents($tempPath, $backupBytes);
    if ($written !== strlen($backupBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file restore.'];
    }

    if (!rename($tempPath, $targetPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file restore.'];
    }

    return ['ok' => true];
}

/**
 * Atomic restore from raw bytes with hash verification.
 * Used for catalog (articles.json) compensation.
 */
function editorial_atomic_restore_bytes(string $targetPath, string $bytes, string $expectedHash): array
{
    $actualHash = hash('sha256', $bytes);
    if ($actualHash !== $expectedHash) {
        return ['ok' => false, 'message' => 'Bytes hash mismatch khi chuẩn bị restore.'];
    }

    $dir = dirname($targetPath);
    $tempPath = $dir . '/.restore_tmp_' . bin2hex(random_bytes(8)) . '.json';
    $written = file_put_contents($tempPath, $bytes);
    if ($written !== strlen($bytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file tạm restore.'];
    }

    if (!rename($tempPath, $targetPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file restore.'];
    }

    // Verify final
    $finalHash = hash_file('sha256', $targetPath);
    if ($finalHash !== $expectedHash) {
        return ['ok' => false, 'message' => 'Hash sau restore không khớp.'];
    }

    return ['ok' => true];
}

// ─── Durable Catalog Backup ──────────────────────────────────────────────────

function editorial_create_catalog_backup(string $catalogBytes, string $expectedHash): array
{
    $base = editorial_publish_backup_base_path() . '/catalog';
    if (!is_dir($base)) {
        if (!mkdir($base, 0755, true) && !is_dir($base)) {
            return ['ok' => false, 'message' => 'Lỗi tạo thư mục backup catalog.'];
        }
    }

    $filename = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.json';
    $tempPath = $base . '/.tmp.' . $filename;
    $finalPath = $base . '/' . $filename;

    $written = file_put_contents($tempPath, $catalogBytes);
    if ($written !== strlen($catalogBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi backup catalog.'];
    }

    if (!rename($tempPath, $finalPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi rename backup catalog.'];
    }

    $writtenHash = hash_file('sha256', $finalPath);
    if ($writtenHash !== $expectedHash) {
        @unlink($finalPath);
        return ['ok' => false, 'message' => 'Hash backup catalog không khớp.'];
    }

    return ['ok' => true, 'path' => $finalPath];
}

// ─── Catalog Update ──────────────────────────────────────────────────────────

function editorial_update_article_source(string $articleId, array $approvedPayload): array
{
    $catalogPath = dirname(dirname(__DIR__)) . '/data/articles.json';
    if (!file_exists($catalogPath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy data/articles.json.'];
    }

    $sourceBytes = file_get_contents($catalogPath);
    if ($sourceBytes === false) {
        return ['ok' => false, 'message' => 'Lỗi đọc data/articles.json.'];
    }

    $sourceHash = hash('sha256', $sourceBytes);

    // Durable backup before mutation
    $backupResult = editorial_create_catalog_backup($sourceBytes, $sourceHash);
    if (!$backupResult['ok']) {
        return $backupResult;
    }

    $catalog = json_decode($sourceBytes, true);
    if (!is_array($catalog)) {
        return ['ok' => false, 'message' => 'data/articles.json không hợp lệ.'];
    }

    // Exactly-one article ID match
    $matchCount = 0;
    $foundIndex = -1;
    foreach ($catalog as $i => $item) {
        if (($item['id'] ?? '') === $articleId) {
            $matchCount++;
            $foundIndex = $i;
        }
    }

    if ($matchCount === 0) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết trong data/articles.json.'];
    }
    if ($matchCount > 1) {
        return ['ok' => false, 'message' => 'data/articles.json có article_id trùng lặp. Publish bị chặn.'];
    }

    // Update ONLY editable fields
    if (isset($approvedPayload['title'])) {
        $catalog[$foundIndex]['title'] = $approvedPayload['title'];
    }
    if (isset($approvedPayload['excerpt'])) {
        $catalog[$foundIndex]['excerpt'] = $approvedPayload['excerpt'];
    }
    if (isset($approvedPayload['publish_date'])) {
        $catalog[$foundIndex]['publishDate'] = $approvedPayload['publish_date'];
    }
    if (isset($approvedPayload['modified_date'])) {
        $catalog[$foundIndex]['modifiedDate'] = $approvedPayload['modified_date'];
    }
    if (isset($approvedPayload['tags_text'])) {
        $catalog[$foundIndex]['tags'] = editorial_parse_tags_text($approvedPayload['tags_text']);
    }
    if (isset($approvedPayload['featured_image'])) {
        $catalog[$foundIndex]['image'] = $approvedPayload['featured_image'];
    }

    $newSourceBytes = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($newSourceBytes === false) {
        return ['ok' => false, 'message' => 'Lỗi encode JSON cho articles.json.'];
    }

    // Write temp first
    $tempPath = dirname($catalogPath) . '/.articles_tmp_' . bin2hex(random_bytes(8)) . '.json';
    $written = file_put_contents($tempPath, $newSourceBytes);
    if ($written !== strlen($newSourceBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file tạm articles.json.'];
    }

    // Race protection: re-hash IMMEDIATELY before rename
    $currentHash = hash_file('sha256', $catalogPath);
    if ($currentHash !== $sourceHash) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'articles.json đã thay đổi (Race condition).'];
    }

    if (!rename($tempPath, $catalogPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file tạm articles.json.'];
    }

    return ['ok' => true, 'source_hash' => $sourceHash, 'source_bytes' => $sourceBytes,
            'catalog_backup_path' => $backupResult['path']];
}

// ─── Public Rebuild ──────────────────────────────────────────────────────────

function editorial_public_rebuild_after_publish(string $articleId): array
{
    $scriptPath = dirname(dirname(__DIR__)) . '/tools/rebuild_public_from_articles.py';
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'code' => 'script_not_found',
                'message' => 'Script rebuild không tồn tại. Cần rebuild thủ công.'];
    }

    if ($articleId === '') {
        return ['ok' => false, 'code' => 'missing_article_id',
                'message' => 'Article ID bắt buộc cho public rebuild.'];
    }

    // Determine python binary
    $pythonBin = null;
    $envPython = getenv('KDTD_PYTHON_BIN');
    if ($envPython !== false && $envPython !== '') {
        $pythonBin = $envPython;
    } else {
        // Try python3 first, then python
        foreach (['python3', 'python'] as $candidate) {
            $testCmd = $candidate . ' --version 2>&1';
            $testOutput = null;
            $testCode = -1;
            @exec($testCmd, $testOutput, $testCode);
            if ($testCode === 0) {
                $pythonBin = $candidate;
                break;
            }
        }
    }

    if ($pythonBin === null) {
        // Check if exec is available at all
        if (!function_exists('exec')) {
            return ['ok' => false, 'code' => 'exec_unavailable',
                    'message' => 'Hàm exec() không khả dụng. Cần rebuild thủ công.'];
        }
        return ['ok' => false, 'code' => 'python_not_found',
                'message' => 'Không tìm thấy Python. Cần rebuild thủ công.'];
    }

    // Check exec availability
    if (!function_exists('exec')) {
        return ['ok' => false, 'code' => 'exec_unavailable',
                'message' => 'Hàm exec() không khả dụng. Cần rebuild thủ công.'];
    }

    $cmd = escapeshellarg($pythonBin) . ' '
         . escapeshellarg($scriptPath)
         . ' --mode fast'
         . ' --source editorial-publish'
         . ' --article-id ' . escapeshellarg($articleId)
         . ' 2>&1';

    $output = [];
    $exitCode = -1;
    @exec($cmd, $output, $exitCode);

    $outputStr = implode("\n", $output);
    $outputTail = mb_substr($outputStr, -500);

    // Try parse JSON result
    $summary = null;
    $jsonResult = json_decode($outputStr, true);
    if (is_array($jsonResult)) {
        $summary = $jsonResult;
        if (($jsonResult['ok'] ?? null) === false) {
            return ['ok' => false, 'code' => 'rebuild_failed',
                    'message' => $jsonResult['message'] ?? 'Rebuild script returned failure.',
                    'exit_code' => $exitCode, 'summary' => $summary,
                    'output_tail' => $outputTail, 'python' => $pythonBin];
        }
    }

    if ($exitCode !== 0) {
        return ['ok' => false, 'code' => 'rebuild_exit_nonzero',
                'message' => 'Rebuild script exit code: ' . $exitCode,
                'exit_code' => $exitCode, 'summary' => $summary,
                'output_tail' => $outputTail, 'python' => $pythonBin];
    }

    return ['ok' => true, 'code' => 'rebuild_succeeded',
            'message' => 'Đã rebuild dữ liệu public thành công.',
            'exit_code' => 0, 'summary' => $summary,
            'output_tail' => $outputTail, 'python' => $pythonBin];
}

// ─── Central Compensation Helper ─────────────────────────────────────────────

/**
 * Attempt filesystem compensation after a failed publish.
 * Returns structured result; never throws.
 */
function editorial_compensate_publish(array $ctx): array
{
    $failures = [];

    // 1. Restore live HTML if replaced
    if (!empty($ctx['live_replaced']) && !empty($ctx['live_target_path'])
        && !empty($ctx['live_backup_absolute_path']) && !empty($ctx['live_hash_before'])) {
        $restoreResult = editorial_restore_backup(
            $ctx['live_target_path'],
            $ctx['live_backup_absolute_path'],
            $ctx['live_hash_before']
        );
        if (!$restoreResult['ok']) {
            $failures[] = ['component' => 'live_html', 'message' => $restoreResult['message']];
        }
    }

    // 2. Restore data/articles.json if replaced
    if (!empty($ctx['catalog_replaced']) && !empty($ctx['catalog_target_path'])
        && !empty($ctx['catalog_source_bytes']) && !empty($ctx['catalog_hash_before'])) {
        $restoreResult = editorial_atomic_restore_bytes(
            $ctx['catalog_target_path'],
            $ctx['catalog_source_bytes'],
            $ctx['catalog_hash_before']
        );
        if (!$restoreResult['ok']) {
            $failures[] = ['component' => 'catalog', 'message' => $restoreResult['message']];
        }
    }

    // 3. Remove orphaned published snapshot if created
    if (!empty($ctx['published_snapshot_path'])) {
        $storageRoot = dirname(__DIR__) . '/storage/revisions';
        $absSnapshotPath = $storageRoot . '/' . ltrim($ctx['published_snapshot_path'], '/');
        if (strpos(realpath($absSnapshotPath) ?: '', realpath($storageRoot) ?: '__never__') === 0) {
            @unlink($absSnapshotPath);
        }
    }

    return [
        'ok' => empty($failures),
        'failures' => $failures,
    ];
}

// ─── Core Publish Orchestrator ───────────────────────────────────────────────

function editorial_publish_approved_revision(string $articleId, string $adminUserId): array
{
    // 1. Defense in depth: pre-transaction preflight
    $preflight = editorial_publish_preflight($articleId, $adminUserId);
    if (!$preflight['ok']) {
        return $preflight;
    }

    // Pre-transaction data (used only for initial checks, all re-verified inside tx)
    $article = $preflight['article'];
    $filePath = $preflight['file_path'];

    // Compensation context — tracks what needs undoing
    $compCtx = [
        'live_replaced' => false,
        'catalog_replaced' => false,
        'live_target_path' => $filePath,
        'live_backup_absolute_path' => null,
        'live_hash_before' => null,
        'catalog_target_path' => dirname(dirname(__DIR__)) . '/data/articles.json',
        'catalog_source_bytes' => null,
        'catalog_hash_before' => null,
        'published_snapshot_path' => null,
    ];

    try {
        $txResult = editorial_transaction(function() use (
            $articleId, $adminUserId, $article, $filePath, &$compCtx
        ) {
            $db = editorial_db();
            $now = date('c');

            // ── RE-VERIFY ALL SECURITY STATE INSIDE TRANSACTION ──

            // Re-verify admin actor
            $admin = editorial_find_user_by_id($adminUserId);
            if (!$admin || empty($admin['is_active']) || $admin['role'] !== 'admin') {
                return ['ok' => false, 'message' => 'Admin không hợp lệ trong transaction.'];
            }

            // Re-read state
            $state = editorial_get_article_state($articleId);
            if (!$state || $state['status'] !== 'approved') {
                return ['ok' => false, 'message' => 'Trạng thái không phải approved trong transaction.'];
            }

            $approvedRevisionId = $state['approved_revision_id'] ?? '';
            if ($approvedRevisionId === '' || ($state['current_revision_id'] ?? '') !== $approvedRevisionId) {
                return ['ok' => false, 'message' => 'Revision IDs không hợp lệ trong transaction.'];
            }

            $baseLiveHash = $state['base_live_hash'] ?? '';
            if ($baseLiveHash === '') {
                return ['ok' => false, 'message' => 'Không có base_live_hash trong transaction.'];
            }

            // Re-load and verify approved revision
            $revision = editorial_get_revision($approvedRevisionId);
            if (!$revision || $revision['revision_type'] !== 'editorial'
                || $revision['article_id'] !== $articleId) {
                return ['ok' => false, 'message' => 'Approved revision không hợp lệ trong transaction.'];
            }

            // Re-verify snapshot INSIDE transaction
            $snapshotResult = editorial_get_verified_revision_snapshot($revision);
            if (!$snapshotResult['ok']) {
                return ['ok' => false, 'message' => 'Snapshot verification failed trong transaction.'];
            }
            $verifiedPayload = $snapshotResult['payload'];

            // Re-verify active assignment
            $assignment = editorial_get_active_assignment($articleId);
            if (!$assignment || $assignment['id'] !== $revision['assignment_id']) {
                return ['ok' => false, 'message' => 'Assignment không khớp trong transaction.'];
            }
            if ($assignment['user_id'] !== ($state['assigned_user_id'] ?? '')) {
                return ['ok' => false, 'message' => 'Assignment user không khớp state trong transaction.'];
            }

            // Re-check no editing lock
            $lockStmt = $db->prepare('SELECT 1 FROM editorial_locks WHERE article_id = :aid LIMIT 1');
            $lockStmt->execute([':aid' => $articleId]);
            if ($lockStmt->fetch()) {
                return ['ok' => false, 'message' => 'Bài viết đang bị khóa trong transaction.'];
            }

            // ── RE-READ LIVE HTML BYTES (authoritative read) ──

            $txLiveHtml = file_get_contents($filePath);
            if ($txLiveHtml === false) {
                return ['ok' => false, 'message' => 'Lỗi đọc HTML file trong transaction.'];
            }
            $currentLiveHash = hash('sha256', $txLiveHtml);
            if ($currentLiveHash !== $baseLiveHash) {
                return ['ok' => false, 'message' => 'File HTML đã bị thay đổi (hash mismatch trong transaction).'];
            }
            $compCtx['live_hash_before'] = $currentLiveHash;

            // ── RENDER ──

            $renderResult = editorial_render_approved_html($txLiveHtml, $article, $verifiedPayload);
            if (!$renderResult['ok']) {
                return $renderResult;
            }
            $newHtml = $renderResult['html'];

            // ── PRE-WRITE VALIDATION ──

            $valResult = editorial_validate_rendered_html($newHtml, $verifiedPayload);
            if (!$valResult['ok']) {
                return $valResult;
            }

            // ── VERIFY PUBLISHED CONTENT HASH EQUALITY ──
            // Published revision will have same payload as approved.
            // Verify content hashes match before doing anything destructive.
            try {
                $approvedContentHash = editorial_revision_content_hash($verifiedPayload);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'message' => 'Không thể băm nội dung approved payload.'];
            }
            if ($revision['content_hash'] !== $approvedContentHash) {
                return ['ok' => false, 'message' => 'Content hash approved revision không khớp verified payload.'];
            }

            // ── CREATE BACKUP ── (non-destructive)

            $backupResult = editorial_create_publish_backup($txLiveHtml, $articleId, $currentLiveHash);
            if (!$backupResult['ok']) {
                return $backupResult;
            }
            $compCtx['live_backup_absolute_path'] = $backupResult['absolute_path'];

            $newHtmlHash = hash('sha256', $newHtml);

            // ════════════════════════════════════════════════════
            // ══ POINT OF NO RETURN — destructive filesystem  ══
            // ══ After this: THROW on failure (never return)  ══
            // ════════════════════════════════════════════════════

            // ── ATOMIC REPLACE LIVE FILE ──

            $replaceResult = editorial_atomic_replace_file($filePath, $newHtml, $currentLiveHash);
            if (!$replaceResult['ok']) {
                // Atomic replace itself failed (temp couldn't rename) — no destructive change
                return $replaceResult;
            }
            $compCtx['live_replaced'] = true;

            // From here on: ALL failures must go through compensation + throw

            try {
                // ── POST-WRITE VERIFICATION ──
                $postWriteHtml = file_get_contents($filePath);
                if ($postWriteHtml === false || hash('sha256', $postWriteHtml) !== $newHtmlHash) {
                    throw new EditorialPublishCompensationException('Post-write hash verification failed.');
                }
                // Full parse + field validation of what was actually written
                $postValResult = editorial_validate_rendered_html($postWriteHtml, $verifiedPayload);
                if (!$postValResult['ok']) {
                    throw new EditorialPublishCompensationException(
                        'Post-write field validation failed: ' . $postValResult['message']
                    );
                }

                // ── UPDATE DATA/ARTICLES.JSON ──
                $updateSrcResult = editorial_update_article_source($articleId, $verifiedPayload);
                if (!$updateSrcResult['ok']) {
                    throw new EditorialPublishCompensationException(
                        'Catalog update failed: ' . $updateSrcResult['message']
                    );
                }
                $compCtx['catalog_replaced'] = true;
                $compCtx['catalog_source_bytes'] = $updateSrcResult['source_bytes'];
                $compCtx['catalog_hash_before'] = $updateSrcResult['source_hash'];

                // ── CREATE PUBLISHED REVISION ──
                $publishedRevisionId = editorial_generate_id('rev');
                $stmt = $db->prepare('SELECT MAX(revision_no) FROM editorial_revisions WHERE article_id = :aid');
                $stmt->execute(['aid' => $articleId]);
                $publishedRevisionNo = (int) $stmt->fetchColumn() + 1;

                $publishedSnapshotPath = editorial_write_revision_snapshot(
                    $publishedRevisionId, $articleId, $verifiedPayload
                );
                $compCtx['published_snapshot_path'] = $publishedSnapshotPath;

                // Published content hash MUST equal approved content hash
                $publishedContentHash = $approvedContentHash;

                $stmt = $db->prepare('
                    INSERT INTO editorial_revisions
                    (id, article_id, revision_no, revision_type, snapshot_path, content_hash,
                     base_revision_id, created_by, created_at, assignment_id, source_draft_version)
                    VALUES (:id, :aid, :rno, :rtype, :spath, :chash,
                            :brid, :cby, :cat, :asgn, :sdv)
                ');
                $stmt->execute([
                    'id' => $publishedRevisionId,
                    'aid' => $articleId,
                    'rno' => $publishedRevisionNo,
                    'rtype' => 'published',
                    'spath' => $publishedSnapshotPath,
                    'chash' => $publishedContentHash,
                    'brid' => $approvedRevisionId,
                    'cby' => $adminUserId,
                    'cat' => $now,
                    'asgn' => $assignment['id'],
                    'sdv' => $revision['source_draft_version'],
                ]);

                // Verify published revision snapshot
                $publishedRev = editorial_get_revision($publishedRevisionId);
                if (!$publishedRev) {
                    throw new EditorialPublishCompensationException('Published revision row not found after INSERT.');
                }
                $publishedSnap = editorial_get_verified_revision_snapshot($publishedRev);
                if (!$publishedSnap['ok']) {
                    throw new EditorialPublishCompensationException(
                        'Published snapshot verification failed: ' . $publishedSnap['message']
                    );
                }

                // ── CLOSE ASSIGNMENT ──
                $db->prepare("UPDATE editorial_assignments SET released_at = :r, release_reason = 'published' WHERE id = :id")
                   ->execute([':r' => $now, ':id' => $assignment['id']]);

                // ── DELETE LOCK (defensive) ──
                $db->prepare('DELETE FROM editorial_locks WHERE article_id = :aid')
                   ->execute([':aid' => $articleId]);

                // ── DELETE OLD DRAFT ──
                $db->prepare('DELETE FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid')
                   ->execute([':aid' => $articleId, ':uid' => $assignment['user_id']]);

                // ── UPDATE ARTICLE STATE ──
                $stmtState = $db->prepare("
                    UPDATE editorial_article_state SET
                        status = 'published',
                        assigned_user_id = NULL,
                        assigned_at = NULL,
                        current_revision_id = :pub_rev_id,
                        published_revision_id = :pub_rev_id2,
                        published_by = :pub_by,
                        published_at = :pub_at,
                        published_live_hash = :pub_hash,
                        publish_backup_path = :backup_path,
                        base_live_hash = :new_hash,
                        review_revision_id = NULL,
                        review_requested_by = NULL,
                        review_requested_at = NULL,
                        approved_revision_id = NULL,
                        approved_by = NULL,
                        approved_at = NULL,
                        updated_at = :upd
                    WHERE article_id = :aid
                ");
                $stmtState->execute([
                    ':pub_rev_id' => $publishedRevisionId,
                    ':pub_rev_id2' => $publishedRevisionId,
                    ':pub_by' => $adminUserId,
                    ':pub_at' => $now,
                    ':pub_hash' => $newHtmlHash,
                    ':backup_path' => $backupResult['path'],
                    ':new_hash' => $newHtmlHash,
                    ':upd' => $now,
                    ':aid' => $articleId,
                ]);

            } catch (\Throwable $innerEx) {
                // ── CENTRAL COMPENSATION ──
                // Must compensate filesystem BEFORE re-throwing for DB rollback
                editorial_compensate_publish($compCtx);

                // Re-throw as compensation exception to trigger ROLLBACK
                if ($innerEx instanceof EditorialPublishCompensationException) {
                    throw $innerEx;
                }
                throw new EditorialPublishCompensationException(
                    'Publish failed after destructive point: ' . $innerEx->getMessage(),
                    ['original_exception' => get_class($innerEx)]
                );
            }

            // ── SUCCESS — transaction will COMMIT ──
            return [
                'ok' => true,
                'message' => 'Xuất bản thành công.',
                'published_revision_id' => $publishedRevisionId,
                'published_revision_no' => $publishedRevisionNo,
                'hash_before' => $currentLiveHash,
                'hash_after' => $newHtmlHash,
                'backup_path' => $backupResult['path'],
                'assignment_id' => $assignment['id'],
                'approved_revision_id' => $approvedRevisionId,
            ];
        });

        // Transaction COMMITTED successfully — log success OUTSIDE transaction
        if ($txResult['ok']) {
            editorial_log_activity('article.publish.succeeded', $articleId, $adminUserId, json_encode([
                'approved_revision_id' => $txResult['approved_revision_id'] ?? '',
                'published_revision_id' => $txResult['published_revision_id'] ?? '',
                'assignment_id' => $txResult['assignment_id'] ?? '',
                'hash_before' => $txResult['hash_before'] ?? '',
                'hash_after' => $txResult['hash_after'] ?? '',
                'backup_path' => $txResult['backup_path'] ?? '',
            ]));
        }

        return $txResult;

    } catch (EditorialPublishCompensationException $e) {
        // DB was ROLLED BACK by editorial_transaction().
        // Filesystem compensation already ran inside the catch block.
        // Log failure OUTSIDE the rolled-back transaction so it persists.
        editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode([
            'stage' => 'post_destructive',
            'message' => $e->getMessage(),
            'hash_before' => $compCtx['live_hash_before'],
            'backup_path' => $compCtx['live_backup_absolute_path'],
        ]));

        // Check if compensation had issues
        $compResult = editorial_compensate_publish($compCtx);
        if (!$compResult['ok']) {
            editorial_log_activity('article.publish.compensation_failed', $articleId, $adminUserId, json_encode([
                'stage' => 'post_rollback_recheck',
                'article_id' => $articleId,
                'hash_before' => $compCtx['live_hash_before'],
                'backup_path' => $compCtx['live_backup_absolute_path'],
                'failures' => $compResult['failures'],
            ]));
        }

        return ['ok' => false, 'message' => 'Publish thất bại và đã được khôi phục: ' . $e->getMessage()];

    } catch (\Throwable $e) {
        // Unexpected error (DB error, etc.)
        editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode([
            'stage' => 'unexpected',
            'message' => $e->getMessage(),
            'exception' => get_class($e),
        ]));

        return ['ok' => false, 'message' => 'Lỗi không mong đợi: ' . $e->getMessage()];
    }
}
