<?php
declare(strict_types=1);

/**
 * Editorial V2 Phase 7 — Publish Service Module (Hardened 7.2).
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
 * - Exactly one compensation attempt per failed publish
 * - Post-commit audit is best-effort (never converts success to failure)
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

// ─── Normalized Publish Payload ──────────────────────────────────────────────

/**
 * Build normalized expected publish payload from approved payload + live meta + article.
 * Renderer and validator use the SAME normalized contract.
 * No taxonomy fields.
 */
function editorial_normalize_publish_payload(array $approvedPayload, array $liveMeta, array $article): array
{
    $title = (string) ($approvedPayload['title'] ?? '');
    $excerpt = (string) ($approvedPayload['excerpt'] ?? '');
    $proseHtml = (string) ($approvedPayload['prose_html'] ?? '');
    $publishDate = (string) ($approvedPayload['publish_date'] ?? '');

    // modifiedDate: legacy semantics — empty string → null
    $rawModified = (string) ($approvedPayload['modified_date'] ?? '');
    $modifiedDate = $rawModified !== '' ? $rawModified : null;

    $tags = editorial_parse_tags_text((string) ($approvedPayload['tags_text'] ?? ''));

    // featured_image: legacy fallback — empty → preserve current article image
    // Explicit non-empty chain: approved → live meta → catalog
    $approvedImage = trim((string) ($approvedPayload['featured_image'] ?? ''));
    $liveImage = trim((string) ($liveMeta['image'] ?? ''));
    $catalogImage = trim((string) ($article['image'] ?? ''));
    $image = $approvedImage !== ''
        ? $approvedImage
        : ($liveImage !== '' ? $liveImage : $catalogImage);

    // Section label from current live meta (canonical), NOT from client/approved
    // Explicit non-empty: live meta → catalog
    $liveSectionLabel = trim((string) ($liveMeta['sectionLabel'] ?? ''));
    $catalogSectionLabel = trim((string) ($article['section_label'] ?? ''));
    $sectionLabel = $liveSectionLabel !== '' ? $liveSectionLabel : $catalogSectionLabel;

    // Expected <title> tag: {title} | {sectionLabel} | Kế Toán Diệu Tâm
    $expectedTitleTag = $title;
    if ($sectionLabel !== '') {
        $expectedTitleTag .= ' | ' . $sectionLabel . ' | Kế Toán Diệu Tâm';
    }

    return [
        'title' => $title,
        'excerpt' => $excerpt,
        'prose_html' => $proseHtml,
        'publishDate' => $publishDate,
        'modifiedDate' => $modifiedDate,
        'tags' => $tags,
        'image' => $image,
        'sectionLabel' => $sectionLabel,
        'expectedTitleTag' => $expectedTitleTag,
    ];
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

function editorial_render_approved_html(string $liveHtml, array $article, array $normalized): array
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
    $html = substr($liveHtml, 0, $proseOpenEnd) . "\n" . $normalized['prose_html'] . "\n" . substr($liveHtml, $proseCloseStart);

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
    $currentMeta['title'] = $normalized['title'];
    $currentMeta['publishDate'] = $normalized['publishDate'];
    $currentMeta['modifiedDate'] = $normalized['modifiedDate'];
    $currentMeta['tags'] = $normalized['tags'];
    $currentMeta['excerpt'] = $normalized['excerpt'];
    $currentMeta['image'] = $normalized['image'];

    $newMetaJson = json_encode($currentMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($newMetaJson === false) {
        return ['ok' => false, 'message' => 'Lỗi encode JSON mới.'];
    }

    $metaOpenEnd = $parsed2['meta']['open_tag_end'];
    $metaCloseStart = $parsed2['meta']['close_tag_start'];
    $html = substr($html, 0, $metaOpenEnd) . "\n" . $newMetaJson . "\n" . substr($html, $metaCloseStart);

    // 4. Update <title> — legacy contract with replacement count validation
    $titleEscaped = htmlspecialchars($normalized['expectedTitleTag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titleCount = 0;
    $html = preg_replace(
        '/<title>.*?<\/title>/is',
        '<title>' . $titleEscaped . '</title>',
        $html, 1, $titleCount
    );
    if ($titleCount !== 1) {
        return ['ok' => false, 'message' => 'Không tìm thấy <title> trong HTML để cập nhật.'];
    }

    // 5. Update meta description — legacy contract with replacement count validation
    $descEscaped = htmlspecialchars($normalized['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $descCount = 0;
    $html = preg_replace(
        '/<meta\s+name="description"\s+content=".*?">/is',
        '<meta name="description" content="' . $descEscaped . '">',
        $html, 1, $descCount
    );
    if ($descCount !== 1) {
        return ['ok' => false, 'message' => 'Không tìm thấy meta description trong HTML để cập nhật.'];
    }

    // 6. Update .article-summary — legacy contract with replacement count validation
    $summaryEscaped = htmlspecialchars($normalized['excerpt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $summaryCount = 0;
    $html = preg_replace(
        '/(<p\b[^>]*class=(["\'])(?:(?!\2).)*\barticle-summary\b(?:(?!\2).)*\2[^>]*>).*?(<\/p>)/is',
        '$1' . $summaryEscaped . '$3',
        $html, 1, $summaryCount
    );
    if ($summaryCount !== 1) {
        return ['ok' => false, 'message' => 'Không tìm thấy .article-summary trong HTML để cập nhật.'];
    }

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

function editorial_validate_rendered_html(string $newHtml, array $normalized): array
{
    $parsed = editorial_parse_article_html($newHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Rendered HTML parse failed: ' . ($parsed['message'] ?? '')];
    }

    // Verify prose region exists and contains expected content
    $renderedProse = trim($parsed['prose']['inner'] ?? '');
    $expectedProse = trim($normalized['prose_html']);
    if ($renderedProse !== $expectedProse) {
        return ['ok' => false, 'message' => 'Rendered prose mismatch.'];
    }

    // Verify meta region exists and JSON valid with correct editable fields
    $metaPayload = $parsed['meta_payload'] ?? null;
    if (!is_array($metaPayload)) {
        return ['ok' => false, 'message' => 'Rendered HTML meta JSON invalid.'];
    }
    if (($metaPayload['title'] ?? '') !== $normalized['title']) {
        return ['ok' => false, 'message' => 'Rendered meta title mismatch.'];
    }
    if (($metaPayload['excerpt'] ?? '') !== $normalized['excerpt']) {
        return ['ok' => false, 'message' => 'Rendered meta excerpt mismatch.'];
    }
    if (($metaPayload['publishDate'] ?? '') !== $normalized['publishDate']) {
        return ['ok' => false, 'message' => 'Rendered meta publishDate mismatch.'];
    }
    // modifiedDate: '' ↔ null normalize
    $renderedMod = $metaPayload['modifiedDate'] ?? null;
    $expectedMod = $normalized['modifiedDate'];
    if ($renderedMod !== $expectedMod) {
        // Also accept '' ↔ null equivalence
        $normRendered = ($renderedMod === '' || $renderedMod === null) ? null : $renderedMod;
        $normExpected = ($expectedMod === '' || $expectedMod === null) ? null : $expectedMod;
        if ($normRendered !== $normExpected) {
            return ['ok' => false, 'message' => 'Rendered meta modifiedDate mismatch.'];
        }
    }
    $renderedTags = $metaPayload['tags'] ?? [];
    if ($renderedTags !== $normalized['tags']) {
        return ['ok' => false, 'message' => 'Rendered meta tags mismatch.'];
    }
    if (($metaPayload['image'] ?? '') !== $normalized['image']) {
        return ['ok' => false, 'message' => 'Rendered meta image mismatch.'];
    }

    // Verify <title> exists and matches exact expected title contract
    if (!preg_match('/<title>(.*?)<\/title>/is', $newHtml, $tm)) {
        return ['ok' => false, 'message' => 'Rendered HTML thiếu <title>.'];
    }
    $renderedTitle = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
    if ($renderedTitle !== $normalized['expectedTitleTag']) {
        return ['ok' => false, 'message' => 'Rendered <title> không khớp expected title contract.'];
    }

    // Verify meta description exists and matches excerpt
    if (!preg_match('/<meta\s+name="description"\s+content="(.*?)"/is', $newHtml, $dm)) {
        return ['ok' => false, 'message' => 'Rendered HTML thiếu meta description.'];
    }
    $renderedDesc = html_entity_decode($dm[1], ENT_QUOTES, 'UTF-8');
    if ($renderedDesc !== $normalized['excerpt']) {
        return ['ok' => false, 'message' => 'Rendered meta description không khớp approved excerpt.'];
    }

    // Verify .article-summary EXISTS (explicit regex detection, not relying on empty string)
    if (!preg_match('/<p\b[^>]*class=(["\'])(?:(?!\1).)*\barticle-summary\b(?:(?!\1).)*\1[^>]*>/is', $newHtml)) {
        return ['ok' => false, 'message' => 'Rendered HTML thiếu .article-summary element.'];
    }
    // Extract and compare text content
    $summaryText = $parsed['summary_text'] ?? '';
    $normalizedSummary = trim(html_entity_decode(strip_tags((string) $summaryText), ENT_QUOTES, 'UTF-8'));
    $normalizedExcerpt = trim($normalized['excerpt']);
    if ($normalizedSummary !== $normalizedExcerpt) {
        return ['ok' => false, 'message' => 'Rendered article-summary text không khớp excerpt.'];
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

    // Preserve permissions of current target before restore
    $currentPerms = @fileperms($targetPath);

    $dir = dirname($targetPath);
    $tempPath = $dir . '/.restore_tmp_' . bin2hex(random_bytes(8)) . '.html';
    $written = file_put_contents($tempPath, $backupBytes);
    if ($written !== strlen($backupBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file restore.'];
    }

    // Best-effort: preserve permissions
    if ($currentPerms !== false) {
        @chmod($tempPath, $currentPerms & 0777);
    }

    if (!rename($tempPath, $targetPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file restore.'];
    }

    // Verify final hash after restore
    $finalBytes = file_get_contents($targetPath);
    if ($finalBytes === false) {
        return ['ok' => false, 'message' => 'Lỗi đọc file HTML sau restore.'];
    }
    $finalHash = hash('sha256', $finalBytes);
    if ($finalHash !== $expectedBackupHash) {
        return ['ok' => false, 'message' => 'Hash file HTML sau restore không khớp.'];
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

function editorial_update_article_source(string $articleId, array $normalized): array
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

    // Update ONLY editable fields using normalized payload
    $catalog[$foundIndex]['title'] = $normalized['title'];
    $catalog[$foundIndex]['excerpt'] = $normalized['excerpt'];
    $catalog[$foundIndex]['publishDate'] = $normalized['publishDate'];
    $catalog[$foundIndex]['modifiedDate'] = $normalized['modifiedDate'];
    $catalog[$foundIndex]['tags'] = $normalized['tags'];
    $catalog[$foundIndex]['image'] = $normalized['image'];

    $newSourceBytes = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($newSourceBytes === false) {
        return ['ok' => false, 'mutated' => false, 'message' => 'Lỗi encode JSON cho articles.json.'];
    }

    // Pre-compute planned hash before any destructive action
    $plannedNewHash = hash('sha256', $newSourceBytes);

    // Write temp first
    $tempPath = dirname($catalogPath) . '/.articles_tmp_' . bin2hex(random_bytes(8)) . '.json';
    $written = file_put_contents($tempPath, $newSourceBytes);
    if ($written !== strlen($newSourceBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'mutated' => false, 'message' => 'Lỗi ghi file tạm articles.json.'];
    }

    // Race protection: re-hash IMMEDIATELY before rename
    $currentHash = hash_file('sha256', $catalogPath);
    if ($currentHash !== $sourceHash) {
        @unlink($tempPath);
        return ['ok' => false, 'mutated' => false, 'message' => 'articles.json đã thay đổi (Race condition).'];
    }

    if (!rename($tempPath, $catalogPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'mutated' => false, 'message' => 'Lỗi đổi tên file tạm articles.json.'];
    }

    // ── FILESYSTEM IS NOW DESTRUCTIVE — all returns must carry mutation context ──

    // Mutation context shared by both success and failure paths
    $mutationCtx = [
        'mutated' => true,
        'source_hash' => $sourceHash,
        'source_bytes' => $sourceBytes,
        'planned_new_hash' => $plannedNewHash,
        'catalog_backup_path' => $backupResult['path'],
    ];

    // Verify written catalog hash using exact bytes read
    $finalBytes = file_get_contents($catalogPath);
    if ($finalBytes === false) {
        return array_merge(['ok' => false, 'message' => 'Lỗi đọc catalog sau ghi.',
                            'current_hash' => null], $mutationCtx);
    }
    $finalHash = hash('sha256', $finalBytes);
    if ($finalHash !== $plannedNewHash) {
        return array_merge(['ok' => false, 'message' => 'Hash catalog sau ghi không khớp planned.',
                            'current_hash' => $finalHash], $mutationCtx);
    }

    return array_merge(['ok' => true, 'new_hash' => $finalHash], $mutationCtx);
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

    // exec() availability MUST be checked BEFORE any exec call
    if (!function_exists('exec')) {
        return ['ok' => false, 'code' => 'exec_unavailable',
                'message' => 'Hàm exec() không khả dụng. Cần rebuild thủ công.'];
    }

    // Build candidate list: env override first, then standard names
    $candidates = [];
    $envPython = getenv('KDTD_PYTHON_BIN');
    if ($envPython !== false && $envPython !== '') {
        $candidates[] = $envPython;
    }
    $candidates[] = 'python3';
    $candidates[] = 'python';

    // Try each candidate with the actual rebuild command
    $lastOutput = [];
    $lastExitCode = -1;
    $lastPython = null;

    foreach ($candidates as $pythonBin) {
        $cmd = escapeshellarg($pythonBin) . ' '
             . escapeshellarg($scriptPath)
             . ' --mode fast'
             . ' --source editorial-publish'
             . ' --article-id ' . escapeshellarg($articleId)
             . ' 2>&1';

        $output = [];
        $exitCode = -1;
        @exec($cmd, $output, $exitCode);
        $lastOutput = $output;
        $lastExitCode = $exitCode;
        $lastPython = $pythonBin;

        // If exit code indicates the binary wasn't found or not executable
        // (127=not found on unix, 9009=not found on win, 126=not executable)
        if ($exitCode === 127 || $exitCode === 9009 || $exitCode === 126) {
            continue;
        }

        // Binary was found — this is our result regardless of script success/failure
        break;
    }

    $outputStr = implode("\n", $lastOutput);
    $outputTail = mb_substr($outputStr, -500);

    // Parse JSON result
    $summary = null;
    $jsonResult = json_decode($outputStr, true);
    if (is_array($jsonResult)) {
        $summary = $jsonResult;
    }

    // Success requires ALL of: exit code 0 AND valid JSON AND summary.ok === true
    if ($lastExitCode === 0 && is_array($summary) && ($summary['ok'] ?? null) === true) {
        return ['ok' => true, 'code' => 'rebuild_succeeded',
                'message' => 'Đã rebuild dữ liệu public thành công.',
                'exit_code' => 0, 'summary' => $summary,
                'output_tail' => $outputTail, 'python' => $lastPython];
    }

    // Failure
    $failCode = 'rebuild_failed';
    $failMsg = 'Rebuild script thất bại.';
    if ($lastExitCode === 127 || $lastExitCode === 9009 || $lastExitCode === 126) {
        $failCode = 'python_not_found';
        $failMsg = 'Không tìm thấy Python. Cần rebuild thủ công.';
    } elseif ($lastExitCode !== 0) {
        $failCode = 'rebuild_exit_nonzero';
        $failMsg = 'Rebuild script exit code: ' . $lastExitCode;
    } elseif (!is_array($summary)) {
        $failCode = 'rebuild_invalid_json';
        $failMsg = 'Rebuild script không trả về JSON hợp lệ.';
    } elseif (($summary['ok'] ?? null) !== true) {
        $failMsg = $summary['message'] ?? 'Rebuild script returned ok !== true.';
    }

    return ['ok' => false, 'code' => $failCode,
            'message' => $failMsg,
            'exit_code' => $lastExitCode, 'summary' => $summary,
            'output_tail' => $outputTail, 'python' => $lastPython];
}

// ─── Central Compensation Helper ─────────────────────────────────────────────

/**
 * Attempt filesystem compensation after a failed publish.
 * Returns structured result; GUARANTEED never throws.
 * Each component restoration is wrapped in try-catch.
 */
function editorial_compensate_publish(array $ctx): array
{
    $failures = [];

    // 1. Restore live HTML if replaced — with optimistic guard
    if (!empty($ctx['live_replaced']) && !empty($ctx['live_target_path'])
        && !empty($ctx['live_backup_absolute_path']) && !empty($ctx['live_hash_before'])) {
        try {
            // Guard: only restore if current file is exactly what WE wrote
            // If someone else changed it after our write, do NOT overwrite their change
            if (!empty($ctx['live_hash_after'])) {
                $currentLiveHash = hash_file('sha256', $ctx['live_target_path']);
                if ($currentLiveHash !== $ctx['live_hash_after']) {
                    $failures[] = [
                        'component' => 'live_html',
                        'code' => 'concurrent_change_detected',
                        'message' => 'File HTML đã bị thay đổi bởi process khác sau publish. Không restore tự động.',
                        'expected_owned_hash' => $ctx['live_hash_after'],
                        'current_hash' => $currentLiveHash,
                    ];
                } else {
                    $restoreResult = editorial_restore_backup(
                        $ctx['live_target_path'],
                        $ctx['live_backup_absolute_path'],
                        $ctx['live_hash_before']
                    );
                    if (!$restoreResult['ok']) {
                        $failures[] = ['component' => 'live_html', 'message' => $restoreResult['message']];
                    }
                }
            } else {
                // No hash_after tracked — ownership unverifiable, do NOT restore
                $failures[] = [
                    'component' => 'live_html',
                    'code' => 'ownership_unverifiable',
                    'message' => 'Không có live_hash_after để xác minh quyền sở hữu file. Không restore tự động.',
                ];
            }
        } catch (\Throwable $e) {
            $failures[] = ['component' => 'live_html', 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    // 2. Restore data/articles.json if replaced — with optimistic guard
    if (!empty($ctx['catalog_replaced']) && !empty($ctx['catalog_target_path'])
        && !empty($ctx['catalog_source_bytes']) && !empty($ctx['catalog_hash_before'])) {
        try {
            // Guard: only restore if current catalog is exactly what WE wrote
            if (!empty($ctx['catalog_hash_after'])) {
                $currentCatalogHash = hash_file('sha256', $ctx['catalog_target_path']);
                if ($currentCatalogHash !== $ctx['catalog_hash_after']) {
                    $failures[] = [
                        'component' => 'catalog',
                        'code' => 'concurrent_change_detected',
                        'message' => 'articles.json đã bị thay đổi bởi process khác sau publish. Không restore tự động.',
                        'expected_owned_hash' => $ctx['catalog_hash_after'],
                        'current_hash' => $currentCatalogHash,
                    ];
                } else {
                    $restoreResult = editorial_atomic_restore_bytes(
                        $ctx['catalog_target_path'],
                        $ctx['catalog_source_bytes'],
                        $ctx['catalog_hash_before']
                    );
                    if (!$restoreResult['ok']) {
                        $failures[] = ['component' => 'catalog', 'message' => $restoreResult['message']];
                    }
                }
            } else {
                // No hash_after tracked — ownership unverifiable, do NOT restore
                $failures[] = [
                    'component' => 'catalog',
                    'code' => 'ownership_unverifiable',
                    'message' => 'Không có catalog_hash_after để xác minh quyền sở hữu. Không restore tự động.',
                ];
            }
        } catch (\Throwable $e) {
            $failures[] = ['component' => 'catalog', 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    // 3. Remove orphaned published snapshot if created
    if (!empty($ctx['published_snapshot_path'])) {
        try {
            $storageRoot = dirname(__DIR__) . '/storage/revisions';
            $absSnapshotPath = $storageRoot . '/' . ltrim($ctx['published_snapshot_path'], '/');
            $realSnapshot = realpath($absSnapshotPath);
            $realStorage = realpath($storageRoot);
            if ($realSnapshot !== false && $realStorage !== false
                && strpos($realSnapshot, $realStorage . DIRECTORY_SEPARATOR) === 0) {
                @unlink($absSnapshotPath);
            }
            // If file doesn't exist, cleanup is idempotent — not a failure
        } catch (\Throwable $e) {
            $failures[] = ['component' => 'snapshot_cleanup', 'message' => 'Exception: ' . $e->getMessage()];
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
        'live_hash_after' => null,
        'catalog_target_path' => dirname(dirname(__DIR__)) . '/data/articles.json',
        'catalog_source_bytes' => null,
        'catalog_hash_before' => null,
        'catalog_hash_after' => null,
        'published_snapshot_path' => null,
        'published_revision_id' => null,
    ];

    // Single compensation result — exactly one attempt allowed
    $compensationResult = null;

    try {
        $txResult = editorial_transaction(function() use (
            $articleId, $adminUserId, $article, $filePath, &$compCtx, &$compensationResult
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

            // ── PARSE LIVE META FOR NORMALIZATION ──
            $liveParsed = editorial_parse_article_html($txLiveHtml, '');
            if (!$liveParsed['ok']) {
                return ['ok' => false, 'message' => 'Lỗi parse live HTML trong transaction.'];
            }
            $liveMeta = $liveParsed['meta_payload'] ?? [];

            // ── NORMALIZE PUBLISH PAYLOAD ──
            $normalized = editorial_normalize_publish_payload($verifiedPayload, $liveMeta, $article);

            // ── VERIFY PUBLISHED CONTENT HASH EQUALITY ──
            try {
                $approvedContentHash = editorial_revision_content_hash($verifiedPayload);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'message' => 'Không thể băm nội dung approved payload.'];
            }
            if ($revision['content_hash'] !== $approvedContentHash) {
                return ['ok' => false, 'message' => 'Content hash approved revision không khớp verified payload.'];
            }

            // ── RENDER ──
            $renderResult = editorial_render_approved_html($txLiveHtml, $article, $normalized);
            if (!$renderResult['ok']) {
                return $renderResult;
            }
            $newHtml = $renderResult['html'];

            // ── PRE-WRITE VALIDATION ──
            $valResult = editorial_validate_rendered_html($newHtml, $normalized);
            if (!$valResult['ok']) {
                return $valResult;
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
            $compCtx['live_hash_after'] = $newHtmlHash;

            // From here on: ALL failures → compensation + throw

            try {
                // ── POST-WRITE VERIFICATION ──
                $postWriteHtml = file_get_contents($filePath);
                if ($postWriteHtml === false || hash('sha256', $postWriteHtml) !== $newHtmlHash) {
                    throw new EditorialPublishCompensationException('Post-write hash verification failed.');
                }
                // Full parse + field validation of what was actually written
                $postValResult = editorial_validate_rendered_html($postWriteHtml, $normalized);
                if (!$postValResult['ok']) {
                    throw new EditorialPublishCompensationException(
                        'Post-write field validation failed: ' . $postValResult['message']
                    );
                }

                // ── UPDATE DATA/ARTICLES.JSON ──
                $updateSrcResult = editorial_update_article_source($articleId, $normalized);

                // ALWAYS copy mutation context BEFORE checking ok
                // If rename succeeded but verify failed, filesystem is still destructive
                if (!empty($updateSrcResult['mutated'])) {
                    $compCtx['catalog_replaced'] = true;
                    $compCtx['catalog_source_bytes'] = $updateSrcResult['source_bytes'];
                    $compCtx['catalog_hash_before'] = $updateSrcResult['source_hash'];
                    $compCtx['catalog_hash_after'] = $updateSrcResult['planned_new_hash'];
                }

                if (!$updateSrcResult['ok']) {
                    throw new EditorialPublishCompensationException(
                        'Catalog update failed: ' . $updateSrcResult['message']
                    );
                }

                // ── CREATE PUBLISHED REVISION ──
                $publishedRevisionId = editorial_generate_id('rev');
                $compCtx['published_revision_id'] = $publishedRevisionId;
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
                // ── SINGLE COMPENSATION ATTEMPT ──
                $compensationResult = editorial_compensate_publish($compCtx);

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

        // Transaction COMMITTED — log success OUTSIDE transaction (best-effort)
        if ($txResult['ok']) {
            try {
                editorial_log_activity('article.publish.succeeded', $articleId, $adminUserId, json_encode([
                    'approved_revision_id' => $txResult['approved_revision_id'] ?? '',
                    'published_revision_id' => $txResult['published_revision_id'] ?? '',
                    'assignment_id' => $txResult['assignment_id'] ?? '',
                    'hash_before' => $txResult['hash_before'] ?? '',
                    'hash_after' => $txResult['hash_after'] ?? '',
                    'backup_path' => $txResult['backup_path'] ?? '',
                ]));
            } catch (\Throwable $logError) {
                // Best-effort: never convert core success to failure
                $txResult['audit_warning'] = 'Success activity log failed: ' . $logError->getMessage();
            }
        }

        return $txResult;

    } catch (EditorialPublishCompensationException $e) {
        // DB was ROLLED BACK by editorial_transaction().
        // Filesystem compensation already ran ONCE inside the inner catch.
        // Log failure OUTSIDE the rolled-back transaction so it persists.
        try {
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode([
                'stage' => 'post_destructive',
                'message' => $e->getMessage(),
                'hash_before' => $compCtx['live_hash_before'],
                'backup_path' => $compCtx['live_backup_absolute_path'],
            ]));
        } catch (\Throwable $logErr) {
            // Best-effort logging
        }

        // Report compensation result (NO second compensation attempt)
        $compOk = ($compensationResult !== null && $compensationResult['ok']);
        if ($compensationResult !== null && !$compensationResult['ok']) {
            try {
                editorial_log_activity('article.publish.compensation_failed', $articleId, $adminUserId, json_encode([
                    'stage' => 'post_rollback',
                    'article_id' => $articleId,
                    'hash_before' => $compCtx['live_hash_before'],
                    'backup_path' => $compCtx['live_backup_absolute_path'],
                    'failures' => $compensationResult['failures'],
                ]));
            } catch (\Throwable $logErr) {
                // Best-effort logging
            }
        }

        if ($compOk) {
            return ['ok' => false,
                    'message' => 'Publish thất bại nhưng hệ thống đã khôi phục trạng thái file trước đó.',
                    'recovery_required' => false];
        } else {
            return ['ok' => false,
                    'message' => 'Publish thất bại và việc khôi phục tự động KHÔNG hoàn tất. Không tiếp tục thao tác trên bài này cho đến khi kiểm tra recovery.',
                    'recovery_required' => true];
        }

    } catch (\Throwable $e) {
        // This catch handles:
        // - Pre-destructive unexpected errors (live_replaced=false)
        // - COMMIT failure AFTER callback returned success (live_replaced=true, compensationResult=null)

        // Case B: COMMIT threw after destructive point — inner catch never ran
        if ($compCtx['live_replaced'] && $compensationResult === null) {
            // Before compensating, check if DB actually committed despite exception
            // (edge case: PDO may throw after successful COMMIT)
            $shouldCompensate = true;
            try {
                $postState = editorial_get_article_state($articleId);
                if ($postState
                    && $postState['status'] === 'published'
                    && !empty($compCtx['published_revision_id'])
                    && ($postState['published_revision_id'] ?? '') === $compCtx['published_revision_id']
                    && ($postState['published_live_hash'] ?? '') === $compCtx['live_hash_after']) {
                    // COMMIT actually succeeded despite exception — do NOT undo a successful publish
                    $shouldCompensate = false;
                }
            } catch (\Throwable $checkErr) {
                // Cannot verify — fail-safe: assume COMMIT failed, compensate
            }

            if ($shouldCompensate) {
                $compensationResult = editorial_compensate_publish($compCtx);

                try {
                    editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode([
                        'stage' => 'commit_failure',
                        'message' => $e->getMessage(),
                        'exception' => get_class($e),
                        'hash_before' => $compCtx['live_hash_before'],
                        'backup_path' => $compCtx['live_backup_absolute_path'],
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort
                }

                if ($compensationResult !== null && !$compensationResult['ok']) {
                    try {
                        editorial_log_activity('article.publish.compensation_failed', $articleId, $adminUserId, json_encode([
                            'stage' => 'commit_failure_compensation',
                            'article_id' => $articleId,
                            'failures' => $compensationResult['failures'],
                        ]));
                    } catch (\Throwable $logErr) {
                        // Best-effort
                    }
                }

                $compOk = ($compensationResult !== null && $compensationResult['ok']);
                if ($compOk) {
                    return ['ok' => false,
                            'message' => 'Publish thất bại (lỗi COMMIT) nhưng hệ thống đã khôi phục trạng thái file trước đó.',
                            'recovery_required' => false];
                } else {
                    return ['ok' => false,
                            'message' => 'Publish thất bại (lỗi COMMIT) và việc khôi phục tự động KHÔNG hoàn tất. Không tiếp tục thao tác trên bài này cho đến khi kiểm tra recovery.',
                            'recovery_required' => true];
                }
            } else {
                // COMMIT actually succeeded — treat as success
                try {
                    editorial_log_activity('article.publish.succeeded', $articleId, $adminUserId, json_encode([
                        'note' => 'COMMIT succeeded despite exception',
                        'exception' => get_class($e),
                        'published_revision_id' => $compCtx['published_revision_id'],
                    ]));
                } catch (\Throwable $logErr) {
                    // Best-effort
                }
                return ['ok' => true,
                        'message' => 'Xuất bản thành công.',
                        'audit_warning' => 'COMMIT threw exception but state verified as committed: ' . $e->getMessage()];
            }
        }

        // Case C: Pre-destructive failure — no compensation needed
        try {
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode([
                'stage' => 'unexpected_pre_destructive',
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]));
        } catch (\Throwable $logErr) {
            // Best-effort logging
        }

        return ['ok' => false, 'message' => 'Lỗi không mong đợi: ' . $e->getMessage()];
    }
}
