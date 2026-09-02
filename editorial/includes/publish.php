<?php
declare(strict_types=1);

/**
 * Editorial V2 Phase 7 — Publish Service Module.
 *
 * Handles safe publish of approved revisions to original live HTML files.
 * Only admin can publish. Only approved revision can be published.
 *
 * Key invariants:
 * - Writes to THE ORIGINAL HTML file (not a copy)
 * - Backup before write
 * - Atomic file operations (temp + rename)
 * - Post-write verification
 * - Automatic compensation on failure
 * - Taxonomy fields NOT overwritten
 */

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

function editorial_publish_preflight(string $articleId, string $adminUserId): array
{
    // 1. Re-verify admin actor
    $admin = editorial_find_user_by_id($adminUserId);
    if (!$admin || empty($admin['is_active']) || $admin['role'] !== 'admin') {
        return ['ok' => false, 'message' => 'Người dùng không hợp lệ hoặc không có quyền admin.'];
    }

    // 2. Find article in catalog
    $article = editorial_find_article($articleId);
    if (!$article) {
        return ['ok' => false, 'message' => 'Bài viết không tồn tại trong danh mục.'];
    }

    // 3. Resolve article file path, verify file exists, verify it's inside repo root
    $filePath = editorial_resolve_article_path($article);
    if (!$filePath || !file_exists($filePath)) {
        return ['ok' => false, 'message' => 'Không tìm thấy file HTML bài viết.'];
    }
    $realRepoRoot = realpath(dirname(dirname(__DIR__)));
    $realFilePath = realpath($filePath);
    if ($realFilePath === false || strpos($realFilePath, $realRepoRoot) !== 0) {
        return ['ok' => false, 'message' => 'Đường dẫn file HTML không hợp lệ.'];
    }

    // 4. Get article state, verify status='approved'
    $state = editorial_get_article_state($articleId);
    if (!$state || $state['status'] !== 'approved') {
        return ['ok' => false, 'message' => 'Bài viết chưa được duyệt.'];
    }

    // 5. Get approved_revision_id, verify not empty
    $approvedRevisionId = $state['approved_revision_id'];
    if (empty($approvedRevisionId)) {
        return ['ok' => false, 'message' => 'Không tìm thấy phiên bản đã duyệt.'];
    }

    // 6. Load revision, verify revision_type='editorial', article_id matches
    $revision = editorial_get_revision($approvedRevisionId);
    if (!$revision || $revision['revision_type'] !== 'editorial' || $revision['article_id'] !== $articleId) {
        return ['ok' => false, 'message' => 'Phiên bản đã duyệt không hợp lệ.'];
    }

    // 7. Verify snapshot via editorial_get_verified_revision_snapshot()
    $snapshotResult = editorial_get_verified_revision_snapshot($revision);
    if (!$snapshotResult['ok']) {
        return ['ok' => false, 'message' => 'Snapshot không hợp lệ: ' . $snapshotResult['message']];
    }
    $verifiedPayload = $snapshotResult['payload'];

    // 8. Exactly one active assignment matching revision.assignment_id
    $assignment = editorial_get_active_assignment($articleId);
    if (!$assignment || $assignment['id'] !== $revision['assignment_id']) {
        return ['ok' => false, 'message' => 'Assignment không khớp với phiên bản đã duyệt.'];
    }

    // 9. Verify current_revision_id == approved_revision_id
    if ($state['current_revision_id'] !== $approvedRevisionId) {
        return ['ok' => false, 'message' => 'Phiên bản hiện tại không khớp với phiên bản đã duyệt.'];
    }

    // 10. Verify base_live_hash exists and not empty
    $baseLiveHash = $state['base_live_hash'];
    if (empty($baseLiveHash)) {
        return ['ok' => false, 'message' => 'Không có base live hash.'];
    }

    // 11. Read live HTML bytes ONCE
    $liveHtml = file_get_contents($filePath);
    if ($liveHtml === false) {
        return ['ok' => false, 'message' => 'Lỗi đọc nội dung file HTML hiện tại.'];
    }

    // 12. Hash those exact bytes
    $currentLiveHash = editorial_live_hash($filePath);
    $htmlHash = hash('sha256', $liveHtml);
    if ($currentLiveHash !== $htmlHash) {
        return ['ok' => false, 'message' => 'Hash bất đồng bộ khi đọc file.'];
    }

    // 13. Verify current_live_hash == base_live_hash
    if ($currentLiveHash !== $baseLiveHash) {
        return ['ok' => false, 'message' => 'File HTML đã bị thay đổi (Hash mismatch). Không thể xuất bản.'];
    }

    // 14. No active editing lock
    $db = editorial_db();
    $stmt = $db->prepare('SELECT id FROM editorial_locks WHERE article_id = :aid');
    $stmt->execute([':aid' => $articleId]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => 'Bài viết đang bị khóa. Vui lòng mở khóa trước khi xuất bản.'];
    }

    return [
        'ok' => true,
        'article' => $article,
        'state' => $state,
        'revision' => $revision,
        'payload' => $verifiedPayload,
        'assignment' => $assignment,
        'live_html' => $liveHtml,
        'live_hash' => $currentLiveHash,
        'file_path' => $filePath,
        'message' => 'Preflight passed.'
    ];
}

function editorial_render_approved_html(string $liveHtml, array $article, array $approvedPayload): array
{
    // 1. Parse liveHtml
    $parsed = editorial_parse_article_html($liveHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Lỗi parse HTML hiện tại.'];
    }

    if (!isset($parsed['prose']['open_tag_end']) || !isset($parsed['prose']['close_tag_start'])) {
        return ['ok' => false, 'message' => 'Không tìm thấy vùng article-prose.'];
    }

    // 3. Replace .article-prose inner HTML
    $proseOpenEnd = $parsed['prose']['open_tag_end'];
    $proseCloseStart = $parsed['prose']['close_tag_start'];
    $newProse = $approvedPayload['prose_html'] ?? '';
    $html = substr($liveHtml, 0, $proseOpenEnd) . "\n" . $newProse . "\n" . substr($liveHtml, $proseCloseStart);

    // 4. Update script#article-meta JSON
    $parsed2 = editorial_parse_article_html($html, '');
    if (!$parsed2['ok'] || !isset($parsed2['meta']['open_tag_end']) || !isset($parsed2['meta']['close_tag_start'])) {
        return ['ok' => false, 'message' => 'Lỗi parse HTML sau khi chèn prose.'];
    }

    $currentMetaJson = $parsed2['meta']['inner'] ?? '';
    $currentMeta = json_decode($currentMetaJson, true);
    if (!is_array($currentMeta)) {
        return ['ok' => false, 'message' => 'Meta JSON hiện tại không hợp lệ.'];
    }

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

    // 5. Update <title> tag
    $newTitle = $approvedPayload['title'] ?? '';
    $html = preg_replace('/<title>[^<]*<\/title>/i', '<title>' . htmlspecialchars($newTitle, ENT_QUOTES, 'UTF-8') . '</title>', $html, 1);

    // 6. Update meta description
    $newExcerpt = $approvedPayload['excerpt'] ?? '';
    $html = preg_replace('/(<meta\s+name=["\']description["\']\s+content=["\'])[^"\']*(["\'\s])/i',
        '${1}' . htmlspecialchars($newExcerpt, ENT_QUOTES, 'UTF-8') . '${2}', $html, 1);

    // 7. Update .article-summary
    $html = preg_replace('/(<p\b[^>]*class=["\'][^"\']*\barticle-summary\b[^"\']*["\'][^>]*>)[^<]*(<\/p>)/is',
        '${1}' . htmlspecialchars($newExcerpt, ENT_QUOTES, 'UTF-8') . '${2}', $html, 1);

    // 8. Cache busting on JS files
    $assetVersion = date('YmdHis');
    $html = preg_replace_callback(
        '/(<script[^>]+src=["\'])([^"\']*(?:article-layout\.js|data\/article-views\/[^"\']*\.js))(\?v=[^"\']*)?(["\'])/i',
        function($m) use ($assetVersion) {
            return $m[1] . $m[2] . '?v=' . $assetVersion . $m[4];
        },
        $html
    );

    return ['ok' => true, 'html' => $html];
}

function editorial_validate_rendered_html(string $newHtml, array $approvedPayload): array
{
    $parsed = editorial_parse_article_html($newHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Rendered HTML parse error.'];
    }
    if (!isset($parsed['prose']['open_tag_end'])) {
        return ['ok' => false, 'message' => 'Rendered HTML missing prose region.'];
    }
    if (!isset($parsed['meta']['open_tag_end'])) {
        return ['ok' => false, 'message' => 'Rendered HTML missing meta region.'];
    }
    
    $metaInner = $parsed['meta']['inner'] ?? '';
    $metaJson = json_decode($metaInner, true);
    if (!is_array($metaJson)) {
        return ['ok' => false, 'message' => 'Rendered HTML meta JSON invalid.'];
    }

    if (empty($metaJson['title'])) {
        return ['ok' => false, 'message' => 'Rendered HTML meta title is empty.'];
    }

    return ['ok' => true];
}

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

    $filename = date('Ymdmd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.html';
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

function editorial_atomic_replace_file(string $targetPath, string $newHtml, string $expectedCurrentHash): array
{
    $dir = dirname($targetPath);
    $tempPath = $dir . '/.publish_tmp_' . bin2hex(random_bytes(8)) . '.html';

    $written = file_put_contents($tempPath, $newHtml);
    if ($written !== strlen($newHtml)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file tạm (byte count mismatch).'];
    }

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
    $catalog = json_decode($sourceBytes, true);
    if (!is_array($catalog)) {
        return ['ok' => false, 'message' => 'data/articles.json không hợp lệ.'];
    }

    $foundIndex = -1;
    foreach ($catalog as $i => $item) {
        if (($item['id'] ?? '') === $articleId) {
            $foundIndex = $i;
            break;
        }
    }

    if ($foundIndex === -1) {
        return ['ok' => false, 'message' => 'Không tìm thấy bài viết trong data/articles.json.'];
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

    // Race protection
    $currentHash = hash_file('sha256', $catalogPath);
    if ($currentHash !== $sourceHash) {
        return ['ok' => false, 'message' => 'articles.json đã thay đổi (Race condition).'];
    }

    $tempPath = dirname($catalogPath) . '/.articles_tmp_' . bin2hex(random_bytes(8)) . '.json';
    $written = file_put_contents($tempPath, $newSourceBytes);
    if ($written !== strlen($newSourceBytes)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi ghi file tạm articles.json.'];
    }

    if (!rename($tempPath, $catalogPath)) {
        @unlink($tempPath);
        return ['ok' => false, 'message' => 'Lỗi đổi tên file tạm articles.json.'];
    }

    return ['ok' => true, 'source_hash' => $sourceHash];
}

function editorial_public_rebuild_after_publish(string $articleId): array
{
    $scriptPath = dirname(dirname(__DIR__)) . '/tools/rebuild_public_from_articles.py';
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'message' => 'Script rebuild không tồn tại. Cần rebuild thủ công.'];
    }
    $cmd = 'python3 ' . escapeshellarg($scriptPath) . ' 2>&1';
    $output = shell_exec($cmd);
    if ($output === null) {
        $cmd = 'python ' . escapeshellarg($scriptPath) . ' 2>&1';
        $output = shell_exec($cmd);
    }
    return ['ok' => true, 'message' => 'Đã gọi script rebuild.', 'warning' => false];
}

function editorial_publish_approved_revision(string $articleId, string $adminUserId): array
{
    // 1. Defense in depth: re-verify admin actor, pre-transaction preflight
    $preflight = editorial_publish_preflight($articleId, $adminUserId);
    if (!$preflight['ok']) {
        return $preflight;
    }

    $article = $preflight['article'];
    $assignment = $preflight['assignment'];
    $liveHtml = $preflight['live_html'];
    $currentLiveHash = $preflight['live_hash'];
    $filePath = $preflight['file_path'];
    $verifiedPayload = $preflight['payload'];

    $result = editorial_transaction(function() use ($articleId, $adminUserId, $article, $assignment, $liveHtml, $currentLiveHash, $filePath, $verifiedPayload) {
        $db = editorial_db();

        // a. Re-read state inside transaction
        $state = editorial_get_article_state($articleId);
        if (!$state || $state['status'] !== 'approved') {
            return ['ok' => false, 'message' => 'Trạng thái bài viết không hợp lệ trong transaction.'];
        }

        // b. Re-verify approved_revision_id
        $approvedRevisionId = $state['approved_revision_id'];
        if (empty($approvedRevisionId) || $state['current_revision_id'] !== $approvedRevisionId) {
            return ['ok' => false, 'message' => 'Phiên bản duyệt không hợp lệ trong transaction.'];
        }
        
        $revision = editorial_get_revision($approvedRevisionId);
        if (!$revision || $revision['assignment_id'] !== $assignment['id']) {
            return ['ok' => false, 'message' => 'Revision không khớp assignment trong transaction.'];
        }

        // c. Re-read and verify live HTML
        $txLiveHtml = file_get_contents($filePath);
        if ($txLiveHtml === false) {
            return ['ok' => false, 'message' => 'Lỗi đọc HTML file trong transaction.'];
        }
        $txLiveHash = hash('sha256', $txLiveHtml);
        if ($txLiveHash !== $currentLiveHash || $txLiveHash !== $state['base_live_hash']) {
            return ['ok' => false, 'message' => 'File HTML đã bị thay đổi trước khi ghi.'];
        }

        // d. Parse those same bytes (implied by rendering)
        // e. Get verified snapshot (already have verified payload)
        
        // f. Render new HTML
        $renderResult = editorial_render_approved_html($txLiveHtml, $article, $verifiedPayload);
        if (!$renderResult['ok']) {
            return $renderResult;
        }
        $newHtml = $renderResult['html'];

        // g. Pre-write validation
        $valResult = editorial_validate_rendered_html($newHtml, $verifiedPayload);
        if (!$valResult['ok']) {
            return $valResult;
        }

        // h. Create backup + verify
        $backupResult = editorial_create_publish_backup($txLiveHtml, $articleId, $currentLiveHash);
        if (!$backupResult['ok']) {
            return $backupResult;
        }
        $backupAbsPath = $backupResult['absolute_path'];

        // i. Compute new HTML hash
        $newHtmlHash = hash('sha256', $newHtml);

        $compensationNeeded = ['html' => false, 'catalog' => false];
        $catalogPath = dirname(dirname(__DIR__)) . '/data/articles.json';
        $catalogBackupBytes = file_exists($catalogPath) ? file_get_contents($catalogPath) : null;

        // j. === POINT OF NO RETURN ===
        // k. Atomic replace live file
        $replaceResult = editorial_atomic_replace_file($filePath, $newHtml, $currentLiveHash);
        if (!$replaceResult['ok']) {
            return $replaceResult; // Safe to return, no changes made to compensate
        }
        $compensationNeeded['html'] = true;

        // l. Post-write verification
        $postWriteHtml = file_get_contents($filePath);
        if ($postWriteHtml === false || hash('sha256', $postWriteHtml) !== $newHtmlHash) {
            // Restore backup
            if ($compensationNeeded['html']) {
                $restoreResult = editorial_restore_backup($filePath, $backupAbsPath, $currentLiveHash);
                if (!$restoreResult['ok']) {
                    editorial_log_activity('article.publish.compensation_failed', $articleId, $adminUserId, json_encode(['reason' => 'post-write compensation failed', 'msg' => $restoreResult['message']]));
                }
            }
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode(['reason' => 'post-write verification failed']));
            return ['ok' => false, 'message' => 'Post-write verification failed.'];
        }

        // m. Update data/articles.json
        $updateSrcResult = editorial_update_article_source($articleId, $verifiedPayload);
        if (!$updateSrcResult['ok']) {
            // Restore backup
            if ($compensationNeeded['html']) {
                $restoreResult = editorial_restore_backup($filePath, $backupAbsPath, $currentLiveHash);
                if (!$restoreResult['ok']) {
                    editorial_log_activity('article.publish.compensation_failed', $articleId, $adminUserId, json_encode(['reason' => 'articles.json html restore failed']));
                }
            }
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode(['reason' => 'articles.json update failed', 'msg' => $updateSrcResult['message']]));
            return $updateSrcResult;
        }
        $compensationNeeded['catalog'] = true;

        // n. Create published revision
        $publishedRevisionId = editorial_generate_id('rev');
        $stmt = $db->prepare('SELECT MAX(revision_no) FROM editorial_revisions WHERE article_id = :aid');
        $stmt->execute(['aid' => $articleId]);
        $publishedRevisionNo = (int) $stmt->fetchColumn() + 1;

        $publishedSnapshotPath = editorial_write_revision_snapshot($publishedRevisionId, $articleId, $verifiedPayload);

        try {
            $publishedContentHash = editorial_revision_content_hash($verifiedPayload);
        } catch (RuntimeException $e) {
            if ($compensationNeeded['html']) {
                editorial_restore_backup($filePath, $backupAbsPath, $currentLiveHash);
            }
            if ($compensationNeeded['catalog'] && $catalogBackupBytes !== null) {
                file_put_contents($catalogPath, $catalogBackupBytes);
            }
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode(['reason' => 'revision content hash failed']));
            return ['ok' => false, 'message' => 'Không thể băm nội dung phiên bản mới.'];
        }

        $stmt = $db->prepare('
            INSERT INTO editorial_revisions
            (id, article_id, revision_no, revision_type, snapshot_path, content_hash, created_by, created_at, assignment_id, source_draft_version)
            VALUES (:id, :aid, :rno, :rtype, :spath, :chash, :cby, :cat, :asgn, :sdv)
        ');
        $stmt->execute([
            'id' => $publishedRevisionId,
            'aid' => $articleId,
            'rno' => $publishedRevisionNo,
            'rtype' => 'published',
            'spath' => $publishedSnapshotPath,
            'chash' => $publishedContentHash,
            'cby' => $adminUserId,
            'cat' => date('c'),
            'asgn' => $assignment['id'],
            'sdv' => $revision['source_draft_version'],
        ]);

        // o. Verify published revision snapshot
        $publishedRev = editorial_get_revision($publishedRevisionId);
        $publishedSnap = editorial_get_verified_revision_snapshot($publishedRev);
        if (!$publishedSnap['ok']) {
            if ($compensationNeeded['html']) {
                editorial_restore_backup($filePath, $backupAbsPath, $currentLiveHash);
            }
            if ($compensationNeeded['catalog'] && $catalogBackupBytes !== null) {
                file_put_contents($catalogPath, $catalogBackupBytes);
            }
            editorial_log_activity('article.publish.failed', $articleId, $adminUserId, json_encode(['reason' => 'published snapshot verify failed']));
            return ['ok' => false, 'message' => 'Xác minh snapshot mới thất bại.'];
        }

        // p. Close assignment
        $now = date('c');
        $db->prepare("UPDATE editorial_assignments SET released_at = :r, release_reason = 'published' WHERE id = :id")
           ->execute([':r' => $now, ':id' => $assignment['id']]);

        // q. Delete lock
        $db->prepare('DELETE FROM editorial_locks WHERE article_id = :aid')->execute([':aid' => $articleId]);

        // r. Delete old draft
        $db->prepare('DELETE FROM editorial_drafts WHERE article_id = :aid AND user_id = :uid')
           ->execute([':aid' => $articleId, ':uid' => $assignment['user_id']]);

        // s. Update article state
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
            ':aid' => $articleId
        ]);

        // Activity log on success
        editorial_log_activity('article.publish.succeeded', $articleId, $adminUserId, json_encode([
            'approved_revision_id' => $revision['id'],
            'published_revision_id' => $publishedRevisionId,
            'assignment_id' => $assignment['id'],
            'hash_before' => $currentLiveHash,
            'hash_after' => $newHtmlHash,
            'backup_path' => $backupResult['path'],
            'bytes_written' => strlen($newHtml),
            'public_rebuild_status' => 'pending',
        ]));

        // t. Return success
        return [
            'ok' => true,
            'message' => 'Xuất bản thành công.',
            'published_revision_id' => $publishedRevisionId,
            'published_revision_no' => $publishedRevisionNo,
            'hash_before' => $currentLiveHash,
            'hash_after' => $newHtmlHash,
            'backup_path' => $backupResult['path']
        ];
    });

    return $result;
}
