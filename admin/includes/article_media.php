<?php
declare(strict_types=1);

/**
 * Ensure shared media upload/index storage exists.
 */
function bootstrap_article_media_storage(): void
{
  if (!is_dir(ADMIN_UPLOADS_DIR)) {
    mkdir(ADMIN_UPLOADS_DIR, 0775, true);
  }

  $root = rtrim(ADMIN_UPLOADS_DIR, '/') . '/articles';
  if (!is_dir($root)) {
    mkdir($root, 0775, true);
  }

  if (!file_exists(ADMIN_MEDIA_INDEX_PATH)) {
    $seed = [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'items' => [],
    ];
    write_article_media_index($seed);
  }
}

/**
 * Build YYYY/MM upload directory (WordPress-like).
 */
function article_upload_dir(string $articleId = '', ?DateTimeImmutable $at = null): string
{
  $stamp = $at ?? new DateTimeImmutable('now');
  $year = $stamp->format('Y');
  $month = $stamp->format('m');
  return rtrim(ADMIN_UPLOADS_DIR, '/') . '/articles/' . $year . '/' . $month;
}

/**
 * Build upload path saved into article content/index (site-root relative).
 */
function article_upload_public_path(string $articleId, string $filename, ?DateTimeImmutable $at = null): string
{
  $stamp = $at ?? new DateTimeImmutable('now');
  $year = $stamp->format('Y');
  $month = $stamp->format('m');
  $name = basename($filename);
  return 'uploads/articles/' . rawurlencode($year) . '/' . rawurlencode($month) . '/' . rawurlencode($name);
}

/**
 * Build upload URL for browser usage in admin pages (subfolder-safe).
 */
function article_upload_url(string $articleId, string $filename, ?DateTimeImmutable $at = null): string
{
  return site_url(article_upload_public_path($articleId, $filename, $at));
}

/**
 * @return array<string,mixed>
 */
function read_article_media_index(): array
{
  bootstrap_article_media_storage();
  $raw = file_get_contents(ADMIN_MEDIA_INDEX_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'items' => [],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'items' => [],
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['items']) || !is_array($decoded['items'])) {
    $decoded['items'] = [];
  }
  $decoded['meta']['count'] = count($decoded['items']);
  $decoded['meta']['updated_at'] = date('c');
  if (!isset($decoded['meta']['created_at'])) {
    $decoded['meta']['created_at'] = date('c');
  }

  return $decoded;
}

/**
 * @param array<string,mixed> $payload
 */
function write_article_media_index(array $payload): void
{
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = [];
  }
  if (!isset($payload['items']) || !is_array($payload['items'])) {
    $payload['items'] = [];
  }
  if (!isset($payload['meta']['created_at'])) {
    $payload['meta']['created_at'] = date('c');
  }
  $payload['meta']['updated_at'] = date('c');
  $payload['meta']['count'] = count($payload['items']);

  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Không encode được media index.');
  }
  file_put_contents(ADMIN_MEDIA_INDEX_PATH, $json . PHP_EOL);
}

/**
 * @param array<string,mixed> $item
 */
function append_article_media_item(array $item): void
{
  $payload = read_article_media_index();
  $payload['items'][] = $item;
  if (count($payload['items']) > 20000) {
    $payload['items'] = array_slice($payload['items'], -20000);
  }
  write_article_media_index($payload);
}

/**
 * Remove one media item by id.
 */
function remove_article_media_item_by_id(string $mediaId): bool
{
  $mediaId = trim($mediaId);
  if ($mediaId === '') {
    return false;
  }

  $payload = read_article_media_index();
  $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
  $changed = false;
  $next = [];
  foreach ($items as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['id'] ?? '') === $mediaId) {
      $changed = true;
      continue;
    }
    $next[] = $row;
  }

  if (!$changed) {
    return false;
  }
  $payload['items'] = $next;
  write_article_media_index($payload);
  return true;
}

/**
 * @return array<int,array<string,mixed>>
 */
function list_article_uploaded_images(string $articleId): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return [];
  }

  $payload = read_article_media_index();
  $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
  $rows = [];
  foreach ($items as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['article_id'] ?? '') !== $articleId) {
      continue;
    }
    $diskPath = (string) ($row['disk_path'] ?? '');
    if ($diskPath === '' || !is_file($diskPath)) {
      continue;
    }

    $mtime = (int) (filemtime($diskPath) ?: 0);
    $size = filesize($diskPath);
    $rows[] = [
      'id' => (string) ($row['id'] ?? ''),
      'article_id' => $articleId,
      'name' => (string) ($row['name'] ?? basename($diskPath)),
      'size' => $size === false ? (int) ($row['size'] ?? 0) : (int) $size,
      'url' => (string) ($row['url'] ?? site_url((string) ($row['public_path'] ?? ''))),
      'public_path' => (string) ($row['public_path'] ?? ''),
      'mtime' => $mtime > 0 ? $mtime : (int) ($row['mtime'] ?? 0),
      'disk_path' => $diskPath,
      'year' => (string) ($row['year'] ?? ''),
      'month' => (string) ($row['month'] ?? ''),
      'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
    ];
  }

  usort($rows, static function (array $a, array $b): int {
    return (int) ($b['mtime'] ?? 0) <=> (int) ($a['mtime'] ?? 0);
  });

  return $rows;
}

/**
 * Save an uploaded image from $_FILES.
 *
 * @param array<string,mixed> $fileInput
 * @return array<string,mixed>
 */
function save_article_uploaded_image(string $articleId, array $fileInput): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    throw new RuntimeException('Thiếu article id.');
  }

  $tmpName = (string) ($fileInput['tmp_name'] ?? '');
  $originalName = (string) ($fileInput['name'] ?? 'image');
  $size = (int) ($fileInput['size'] ?? 0);
  $error = (int) ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE);

  if ($error !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload ảnh thất bại.');
  }
  if ($size <= 0 || $size > 8 * 1024 * 1024) {
    throw new RuntimeException('Ảnh phải nhỏ hơn 8MB.');
  }
  if (!is_uploaded_file($tmpName)) {
    throw new RuntimeException('File upload không hợp lệ.');
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
  if ($finfo) {
    finfo_close($finfo);
  }

  $extMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
  ];
  if (!isset($extMap[$mime])) {
    throw new RuntimeException('Chỉ hỗ trợ JPG, PNG, GIF, WEBP.');
  }

  $base = pathinfo($originalName, PATHINFO_FILENAME);
  $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $base) ?? 'image';
  $base = trim($base, '-_');
  if ($base === '') {
    $base = 'image';
  }

  $stamp = new DateTimeImmutable('now');
  $dir = article_upload_dir($articleId, $stamp);
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }

  $ext = $extMap[$mime];
  $filename = article_upload_unique_filename($dir, $base, $ext);
  $target = $dir . '/' . $filename;

  if (!move_uploaded_file($tmpName, $target)) {
    throw new RuntimeException('Không lưu được ảnh upload.');
  }

  $publicPath = article_upload_public_path($articleId, $filename, $stamp);
  $url = article_upload_url($articleId, $filename, $stamp);
  $mtime = (int) (filemtime($target) ?: time());
  $actualSize = (int) (filesize($target) ?: 0);
  $mediaId = 'med-' . $stamp->format('YmdHis') . '-' . substr(md5($articleId . '|' . $filename . '|' . microtime(true)), 0, 10);

  append_article_media_item([
    'id' => $mediaId,
    'article_id' => $articleId,
    'name' => $filename,
    'original_name' => $originalName,
    'mime' => $mime,
    'size' => $actualSize,
    'disk_path' => $target,
    'public_path' => $publicPath,
    'url' => $url,
    'year' => $stamp->format('Y'),
    'month' => $stamp->format('m'),
    'mtime' => $mtime,
    'uploaded_at' => $stamp->format('c'),
  ]);

  return [
    'id' => $mediaId,
    'name' => $filename,
    'size' => $actualSize,
    'path' => $target,
    'url' => $url,
    'public_path' => $publicPath,
    'mime' => $mime,
    'year' => $stamp->format('Y'),
    'month' => $stamp->format('m'),
  ];
}

/**
 * Remove uploaded file (metadata + disk).
 */
function delete_article_uploaded_image(string $articleId, string $uploadName, string $uploadYear = '', string $uploadMonth = '', string $uploadId = ''): bool
{
  $articleId = trim($articleId);
  $name = basename(trim($uploadName));
  $mediaId = trim($uploadId);
  if ($articleId === '' || $name === '' || $name === '.' || $name === '..') {
    return false;
  }

  $payload = read_article_media_index();
  $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
  $targetItem = null;

  foreach ($items as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['article_id'] ?? '') !== $articleId) {
      continue;
    }
    if ($mediaId !== '' && (string) ($row['id'] ?? '') !== $mediaId) {
      continue;
    }
    if ((string) ($row['name'] ?? '') !== $name) {
      continue;
    }
    if ($uploadYear !== '' && (string) ($row['year'] ?? '') !== $uploadYear) {
      continue;
    }
    if ($uploadMonth !== '' && (string) ($row['month'] ?? '') !== $uploadMonth) {
      continue;
    }
    $targetItem = $row;
    break;
  }

  if (!is_array($targetItem)) {
    return false;
  }

  $diskPath = (string) ($targetItem['disk_path'] ?? '');
  if ($diskPath === '' || !is_file($diskPath)) {
    remove_article_media_item_by_id((string) ($targetItem['id'] ?? ''));
    return false;
  }

  if (!unlink($diskPath)) {
    return false;
  }

  remove_article_media_item_by_id((string) ($targetItem['id'] ?? ''));
  cleanup_article_upload_empty_dirs((string) ($targetItem['year'] ?? ''), (string) ($targetItem['month'] ?? ''));
  return true;
}

/**
 * Delete all uploaded images linked to one article.
 *
 * @return array<string,mixed>
 */
function purge_article_uploaded_images(string $articleId): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return [
      'removed_items' => 0,
      'removed_files' => 0,
      'missing_files' => 0,
      'failed_files' => [],
    ];
  }

  $payload = read_article_media_index();
  $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
  $keep = [];
  $removedItems = 0;
  $removedFiles = 0;
  $missingFiles = 0;
  $failedFiles = [];

  foreach ($items as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['article_id'] ?? '') !== $articleId) {
      $keep[] = $row;
      continue;
    }

    $diskPath = (string) ($row['disk_path'] ?? '');
    $year = (string) ($row['year'] ?? '');
    $month = (string) ($row['month'] ?? '');

    if ($diskPath !== '' && is_file($diskPath)) {
      if (@unlink($diskPath)) {
        $removedFiles++;
        cleanup_article_upload_empty_dirs($year, $month);
      } else {
        $failedFiles[] = $diskPath;
        $keep[] = $row;
        continue;
      }
    } else {
      $missingFiles++;
    }

    $removedItems++;
  }

  if ($removedItems > 0 || count($failedFiles) > 0) {
    $payload['items'] = $keep;
    write_article_media_index($payload);
  }

  return [
    'removed_items' => $removedItems,
    'removed_files' => $removedFiles,
    'missing_files' => $missingFiles,
    'failed_files' => $failedFiles,
  ];
}

/**
 * Try removing empty month/year upload folders after file deletion.
 */
function cleanup_article_upload_empty_dirs(string $year, string $month): void
{
  $year = trim($year);
  $month = trim($month);
  if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{2}$/', $month)) {
    return;
  }

  $root = rtrim(ADMIN_UPLOADS_DIR, '/') . '/articles';
  $monthDir = $root . '/' . $year . '/' . $month;
  if (is_dir($monthDir) && count(scandir($monthDir) ?: []) <= 2) {
    @rmdir($monthDir);
  }

  $yearDir = $root . '/' . $year;
  if (is_dir($yearDir) && count(scandir($yearDir) ?: []) <= 2) {
    @rmdir($yearDir);
  }
}

/**
 * Generate unique filename while preserving original-base readability.
 */
function article_upload_unique_filename(string $dir, string $base, string $ext): string
{
  $ext = trim($ext, '.');
  $base = trim($base);
  if ($base === '') {
    $base = 'image';
  }

  $candidate = $base . '.' . $ext;
  if (!file_exists($dir . '/' . $candidate)) {
    return $candidate;
  }

  $suffix = 2;
  while ($suffix <= 9999) {
    $candidate = $base . '-' . $suffix . '.' . $ext;
    if (!file_exists($dir . '/' . $candidate)) {
      return $candidate;
    }
    $suffix++;
  }

  return $base . '-' . date('Ymd-His') . '-' . substr(md5((string) microtime(true)), 0, 6) . '.' . $ext;
}
