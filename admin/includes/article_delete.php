<?php
declare(strict_types=1);

/**
 * Delete one article completely:
 * - remove HTML file
 * - remove data/articles.json row + rebuild index cache
 * - purge draft/review/revisions/media linked to article
 * - append audit + publish-history record
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function delete_article_with_assets(array $article, ?array $actor = null): array
{
  $articleId = trim((string) ($article['id'] ?? ''));
  if ($articleId === '') {
    return [
      'ok' => false,
      'code' => 'missing_article_id',
      'message' => 'Thiếu article id để xóa bài.',
    ];
  }

  $targetPath = resolve_article_file_path($article);
  if ($targetPath === '' || !file_exists($targetPath)) {
    return [
      'ok' => false,
      'code' => 'article_file_missing',
      'message' => 'Không tìm thấy file HTML của bài viết.',
    ];
  }
  if (!is_writable($targetPath)) {
    return [
      'ok' => false,
      'code' => 'article_file_not_writable',
      'message' => 'File bài viết không có quyền xóa.',
    ];
  }

  $sourceRaw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($sourceRaw === false || trim($sourceRaw) === '') {
    return [
      'ok' => false,
      'code' => 'source_read_failed',
      'message' => 'Không đọc được data/articles.json.',
    ];
  }
  $sourceItems = json_decode($sourceRaw, true);
  if (!is_array($sourceItems)) {
    return [
      'ok' => false,
      'code' => 'source_invalid_json',
      'message' => 'data/articles.json không hợp lệ.',
    ];
  }

  $filtered = [];
  $foundInSource = false;
  foreach ($sourceItems as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['id'] ?? '') === $articleId) {
      $foundInSource = true;
      continue;
    }
    $filtered[] = $row;
  }
  if (!$foundInSource) {
    return [
      'ok' => false,
      'code' => 'article_not_in_source',
      'message' => 'Không tìm thấy bài trong data/articles.json để xóa.',
    ];
  }

  $backupPath = build_backup_file_path($articleId . '-delete');
  if (!copy($targetPath, $backupPath)) {
    return [
      'ok' => false,
      'code' => 'delete_backup_failed',
      'message' => 'Không tạo được backup trước khi xóa bài.',
    ];
  }

  $sourceBackupPath = ADMIN_BACKUPS_DIR . '/' . 'articles-json-' . date('Ymd-His') . '-' . substr(md5($articleId . microtime(true)), 0, 8) . '.json';
  if (!copy(ADMIN_ARTICLES_SOURCE_PATH, $sourceBackupPath)) {
    return [
      'ok' => false,
      'code' => 'source_backup_failed',
      'message' => 'Không tạo được backup data/articles.json trước khi xóa.',
    ];
  }

  // Step 1: delete html file.
  if (!unlink($targetPath)) {
    return [
      'ok' => false,
      'code' => 'delete_html_failed',
      'message' => 'Không xóa được file HTML bài viết.',
    ];
  }

  // Step 2: write source without article.
  $json = json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || file_put_contents(ADMIN_ARTICLES_SOURCE_PATH, $json . PHP_EOL) === false) {
    // Rollback HTML if source write fails.
    @copy($backupPath, $targetPath);
    return [
      'ok' => false,
      'code' => 'write_source_failed',
      'message' => 'Không ghi được data/articles.json sau khi xóa bài.',
    ];
  }

  // Step 3: purge side data.
  $draftPurged = purge_article_draft_silent($articleId);
  $reviewPurged = purge_article_review_status_silent($articleId);
  $revisionPurge = purge_article_revisions($articleId);
  $mediaPurge = purge_article_uploaded_images($articleId);

  sync_articles_index(true);

  $record = [
    'id' => 'del-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'article_delete',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'target_path' => $targetPath,
    'backup_path' => $backupPath,
    'source_backup_path' => $sourceBackupPath,
    'deleted_at' => date('c'),
    'cleanup' => [
      'draft_purged' => $draftPurged,
      'review_purged' => $reviewPurged,
      'revisions_removed' => (int) ($revisionPurge['removed_files'] ?? 0),
      'media_removed_items' => (int) ($mediaPurge['removed_items'] ?? 0),
      'media_removed_files' => (int) ($mediaPurge['removed_files'] ?? 0),
      'media_missing_files' => (int) ($mediaPurge['missing_files'] ?? 0),
      'media_failed_files' => is_array($mediaPurge['failed_files'] ?? null) ? $mediaPurge['failed_files'] : [],
    ],
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
  ];
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.delete.success',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'backup' => $backupPath,
    'source_backup' => $sourceBackupPath,
    'media_removed_items' => (int) ($mediaPurge['removed_items'] ?? 0),
    'media_failed_count' => count(is_array($mediaPurge['failed_files'] ?? null) ? $mediaPurge['failed_files'] : []),
  ]);

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Đã xóa bài viết và dữ liệu liên quan.',
    'record' => $record,
  ];
}
