<?php
declare(strict_types=1);

/**
 * Ensure per-article draft revision storage exists.
 */
function bootstrap_article_revision_storage(): void
{
  if (!is_dir(ADMIN_DRAFT_REVISIONS_DIR)) {
    mkdir(ADMIN_DRAFT_REVISIONS_DIR, 0775, true);
  }
}

/**
 * Build article revision directory path.
 */
function article_revision_dir(string $articleId): string
{
  $safeId = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', trim($articleId));
  $safeId = trim((string) $safeId, '-');
  if ($safeId === '') {
    $safeId = 'article';
  }
  return rtrim(ADMIN_DRAFT_REVISIONS_DIR, '/') . '/' . $safeId;
}

/**
 * Save full HTML snapshot as revision.
 */
function save_article_revision_snapshot(string $articleId, string $html): string
{
  $dir = article_revision_dir($articleId);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }

  $stamp = date('Ymd_His') . '_' . substr(md5((string) microtime(true)), 0, 8);
  $path = $dir . '/' . $stamp . '.html';
  if (file_put_contents($path, $html) === false) {
    throw new RuntimeException('Không thể tạo revision snapshot.');
  }

  // Keep latest 30 snapshots to avoid uncontrolled growth.
  $files = glob($dir . '/*.html') ?: [];
  rsort($files, SORT_STRING);
  if (count($files) > 30) {
    $toDelete = array_slice($files, 30);
    foreach ($toDelete as $oldPath) {
      if (is_file($oldPath)) {
        @unlink($oldPath);
      }
    }
  }

  return $path;
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_article_revisions(string $articleId): array
{
  $dir = article_revision_dir($articleId);
  if (!is_dir($dir)) {
    return [];
  }

  $rows = [];
  foreach (glob($dir . '/*.html') ?: [] as $path) {
    $name = basename($path);
    $stamp = preg_replace('/\.html$/', '', $name) ?? $name;
    $display = $stamp;
    if (preg_match('/^(\d{8})_(\d{6})/', $stamp, $match)) {
      $display = substr($match[1], 6, 2) . '/' . substr($match[1], 4, 2) . '/' . substr($match[1], 0, 4)
        . ' ' . substr($match[2], 0, 2) . ':' . substr($match[2], 2, 2) . ':' . substr($match[2], 4, 2);
    }
    $rows[] = [
      'name' => $name,
      'path' => $path,
      'display' => $display,
      'size' => (int) (filesize($path) ?: 0),
      'mtime' => (int) (filemtime($path) ?: 0),
    ];
  }

  usort($rows, static function (array $a, array $b): int {
    return strcmp((string) ($b['name'] ?? ''), (string) ($a['name'] ?? ''));
  });

  return $rows;
}

/**
 * Validate revision filename from user input.
 */
function validate_revision_name(string $name): string
{
  $name = basename(trim($name));
  if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
    throw new RuntimeException('Tên revision không hợp lệ.');
  }
  return $name;
}

/**
 * Restore a revision file to target article HTML.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function restore_article_revision(array $article, string $revisionName, ?array $actor = null): array
{
  $articleId = trim((string) ($article['id'] ?? ''));
  if ($articleId === '') {
    return [
      'ok' => false,
      'message' => 'Thiếu article id.',
    ];
  }

  $safeName = validate_revision_name($revisionName);
  $revisionPath = article_revision_dir($articleId) . '/' . $safeName;
  if (!is_file($revisionPath)) {
    return [
      'ok' => false,
      'message' => 'Không tìm thấy revision cần khôi phục.',
    ];
  }

  $targetPath = resolve_article_file_path($article);
  if ($targetPath === '' || !file_exists($targetPath)) {
    return [
      'ok' => false,
      'message' => 'Không tìm thấy file bài đích.',
    ];
  }

  $currentHtml = file_get_contents($targetPath);
  if (!is_string($currentHtml) || $currentHtml === '') {
    return [
      'ok' => false,
      'message' => 'Không đọc được nội dung hiện tại để backup.',
    ];
  }

  // Always backup current state before restoring revision.
  save_article_revision_snapshot($articleId, $currentHtml);
  $backupPath = build_backup_file_path($articleId . '-revision-restore');
  if (!copy($targetPath, $backupPath)) {
    return [
      'ok' => false,
      'message' => 'Không tạo được backup trước khi restore revision.',
    ];
  }

  $revisionHtml = file_get_contents($revisionPath);
  if (!is_string($revisionHtml) || $revisionHtml === '') {
    return [
      'ok' => false,
      'message' => 'Nội dung revision rỗng hoặc không đọc được.',
    ];
  }

  if (file_put_contents($targetPath, $revisionHtml) === false) {
    return [
      'ok' => false,
      'message' => 'Không ghi được nội dung revision vào bài viết.',
    ];
  }

  append_publish_record([
    'id' => 'rev-restore-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'revision_restore',
    'article_id' => $articleId,
    'target_path' => $targetPath,
    'restored_from_revision' => $revisionPath,
    'backup_path' => $backupPath,
    'restored_at' => date('c'),
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
  ]);

  append_audit_log([
    'event' => 'article.revision.restore',
    'article_id' => $articleId,
    'revision' => $safeName,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
  ]);

  return [
    'ok' => true,
    'message' => 'Đã khôi phục revision thành công.',
  ];
}

/**
 * Purge all draft revision snapshots for one article.
 *
 * @return array<string,mixed>
 */
function purge_article_revisions(string $articleId): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return [
      'removed_files' => 0,
      'failed_files' => [],
      'dir_removed' => false,
    ];
  }

  $dir = article_revision_dir($articleId);
  if (!is_dir($dir)) {
    return [
      'removed_files' => 0,
      'failed_files' => [],
      'dir_removed' => false,
    ];
  }

  $removed = 0;
  $failed = [];
  foreach (glob($dir . '/*.html') ?: [] as $path) {
    if (!is_file($path)) {
      continue;
    }
    if (@unlink($path)) {
      $removed++;
    } else {
      $failed[] = $path;
    }
  }

  $dirRemoved = false;
  if (count($failed) === 0 && count(scandir($dir) ?: []) <= 2) {
    $dirRemoved = @rmdir($dir);
  }

  return [
    'removed_files' => $removed,
    'failed_files' => $failed,
    'dir_removed' => $dirRemoved,
  ];
}
