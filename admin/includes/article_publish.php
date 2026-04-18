<?php
declare(strict_types=1);

/**
 * Initialize publish history and backup directory.
 */
function bootstrap_publish_storage(): void
{
  if (!is_dir(ADMIN_BACKUPS_DIR)) {
    mkdir(ADMIN_BACKUPS_DIR, 0775, true);
  }

  if (!file_exists(ADMIN_PUBLISH_HISTORY_PATH)) {
    $seed = [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'records' => [],
    ];
    write_publish_history($seed);
  }
}

/**
 * @return array<string,mixed>
 */
function read_publish_history(): array
{
  bootstrap_publish_storage();
  $raw = file_get_contents(ADMIN_PUBLISH_HISTORY_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'count' => 0,
      ],
      'records' => [],
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
      'records' => [],
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['records']) || !is_array($decoded['records'])) {
    $decoded['records'] = [];
  }
  $decoded['meta']['count'] = count($decoded['records']);
  $decoded['meta']['updated_at'] = date('c');

  return $decoded;
}

/**
 * @param array<string,mixed> $payload
 */
function write_publish_history(array $payload): void
{
  if (!isset($payload['meta']) || !is_array($payload['meta'])) {
    $payload['meta'] = [];
  }
  if (!isset($payload['records']) || !is_array($payload['records'])) {
    $payload['records'] = [];
  }

  if (!isset($payload['meta']['created_at'])) {
    $payload['meta']['created_at'] = date('c');
  }
  $payload['meta']['updated_at'] = date('c');
  $payload['meta']['count'] = count($payload['records']);

  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode publish history.');
  }
  file_put_contents(ADMIN_PUBLISH_HISTORY_PATH, $json . PHP_EOL);
}

/**
 * @param array<string,mixed> $record
 */
function append_publish_record(array $record): void
{
  $payload = read_publish_history();
  $payload['records'][] = $record;
  if (count($payload['records']) > 500) {
    $payload['records'] = array_slice($payload['records'], -500);
  }
  write_publish_history($payload);
}

/**
 * @return array<string,mixed>|null
 */
function find_latest_publish_record(string $articleId): ?array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return null;
  }

  $payload = read_publish_history();
  $records = array_reverse($payload['records']);
  foreach ($records as $record) {
    if (!is_array($record)) {
      continue;
    }
    if ((string) ($record['article_id'] ?? '') === $articleId) {
      return $record;
    }
  }
  return null;
}

/**
 * Build backup file path for article.
 */
function build_backup_file_path(string $articleId): string
{
  $slug = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $articleId);
  $slug = trim((string) $slug, '-');
  if ($slug === '') {
    $slug = 'article';
  }
  $timestamp = date('Ymd-His');
  return ADMIN_BACKUPS_DIR . '/' . $slug . '--' . $timestamp . '.html';
}

/**
 * Publish one article draft to real HTML file.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed> $draftData
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function publish_article_draft(array $article, array $draftData, ?array $actor = null): array
{
  $articleId = trim((string) ($article['id'] ?? ''));
  if ($articleId === '') {
    return [
      'ok' => false,
      'code' => 'missing_article_id',
      'message' => 'Thiếu article id để publish.',
    ];
  }

  $path = resolve_article_file_path($article);
  $parsed = parse_article_file($path);
  if (empty($parsed['ok'])) {
    return [
      'ok' => false,
      'code' => (string) ($parsed['code'] ?? 'parse_failed'),
      'message' => (string) ($parsed['message'] ?? 'Parser failed'),
    ];
  }

  $html = (string) ($parsed['html'] ?? '');
  $prose = is_array($parsed['prose'] ?? null) ? $parsed['prose'] : [];
  $meta = is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [];
  $metaPayload = is_array($parsed['meta_payload'] ?? null) ? $parsed['meta_payload'] : [];
  if ($html === '' || empty($prose) || empty($meta)) {
    return [
      'ok' => false,
      'code' => 'invalid_parse_regions',
      'message' => 'Không xác định được vùng ghi prose/meta.',
    ];
  }

  $newProse = (string) ($draftData['prose_html'] ?? '');
  $newTitle = (string) ($draftData['title'] ?? '');
  $newExcerpt = (string) ($draftData['excerpt'] ?? '');
  $newPublish = (string) ($draftData['publish_date'] ?? '');
  $newModified = (string) ($draftData['modified_date'] ?? '');
  $newTags = is_array($draftData['tags'] ?? null) ? array_values(array_map('strval', $draftData['tags'])) : [];

  $metaPayload['title'] = $newTitle;
  $metaPayload['publishDate'] = $newPublish;
  $metaPayload['modifiedDate'] = $newModified !== '' ? $newModified : null;
  $metaPayload['tags'] = $newTags;
  $metaPayload['excerpt'] = $newExcerpt;

  $newMetaJson = json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($newMetaJson === false) {
    return [
      'ok' => false,
      'code' => 'meta_encode_failed',
      'message' => 'Không encode được article-meta mới.',
    ];
  }

  // Update prose region
  $proseOpenEnd = (int) ($prose['open_tag_end'] ?? 0);
  $proseCloseStart = (int) ($prose['close_tag_start'] ?? 0);
  $beforeProse = substr($html, 0, $proseOpenEnd);
  $afterProse = substr($html, $proseCloseStart);
  if ($beforeProse === false || $afterProse === false) {
    return [
      'ok' => false,
      'code' => 'prose_slice_failed',
      'message' => 'Không cắt được prose segment.',
    ];
  }
  $htmlWithProse = $beforeProse . $newProse . $afterProse;

  // Re-parse on updated html to get meta offsets after prose length changes
  $metaReparse = extract_article_meta_region($htmlWithProse);
  if (empty($metaReparse['ok'])) {
    return [
      'ok' => false,
      'code' => (string) ($metaReparse['code'] ?? 'meta_reparse_failed'),
      'message' => (string) ($metaReparse['message'] ?? 'Không parse được meta sau update prose.'),
    ];
  }
  $metaOpenEnd = (int) ($metaReparse['open_tag_end'] ?? 0);
  $metaCloseStart = (int) ($metaReparse['close_tag_start'] ?? 0);
  $beforeMeta = substr($htmlWithProse, 0, $metaOpenEnd);
  $afterMeta = substr($htmlWithProse, $metaCloseStart);
  if ($beforeMeta === false || $afterMeta === false) {
    return [
      'ok' => false,
      'code' => 'meta_slice_failed',
      'message' => 'Không cắt được meta segment.',
    ];
  }
  $htmlNew = $beforeMeta . $newMetaJson . $afterMeta;

  // Update head <title>
  $titleTag = $newTitle . ' | ' . ((string) ($article['section_label'] ?? ($article['section'] ?? ''))) . ' | Kế Toán Diệu Tâm';
  $htmlNew = preg_replace('/<title>.*?<\/title>/is', '<title>' . htmlspecialchars($titleTag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>', $htmlNew, 1) ?? $htmlNew;

  // Update meta description
  $descEscaped = htmlspecialchars($newExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $htmlNew = preg_replace('/<meta\s+name="description"\s+content=".*?">/is', '<meta name="description" content="' . $descEscaped . '">', $htmlNew, 1) ?? $htmlNew;

  // Update article summary paragraph
  $summaryEscaped = htmlspecialchars($newExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $htmlNew = preg_replace('/(<p\b[^>]*class=(["\'])(?:(?!\2).)*\barticle-summary\b(?:(?!\2).)*\2[^>]*>).*?(<\/p>)/is', '$1' . $summaryEscaped . '$3', $htmlNew, 1) ?? $htmlNew;

  // Backup current file before write
  $backupPath = build_backup_file_path($articleId);
  if (!copy($path, $backupPath)) {
    return [
      'ok' => false,
      'code' => 'backup_failed',
      'message' => 'Không tạo được backup trước khi publish.',
    ];
  }

  // Persist new html
  $bytes = file_put_contents($path, $htmlNew);
  if ($bytes === false) {
    return [
      'ok' => false,
      'code' => 'write_failed',
      'message' => 'Không ghi được file bài sau publish.',
    ];
  }

  $record = [
    'id' => 'pub-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'publish',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'target_path' => $path,
    'backup_path' => $backupPath,
    'bytes_written' => $bytes,
    'published_at' => date('c'),
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'draft_snapshot' => $draftData,
  ];
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.publish.success',
    'article_id' => $articleId,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'backup' => $backupPath,
  ]);

  // Keep article index in sync for card-like fields
  sync_article_index_entry($articleId, [
    'title' => $newTitle,
    'publishDate' => $newPublish,
    'modifiedDate' => $newModified,
    'cardBadgeLabel' => $newExcerpt,
  ]);

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Publish thành công.',
    'record' => $record,
  ];
}

/**
 * Rollback latest publish for one article.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed>|null $actor
 * @return array<string,mixed>
 */
function rollback_latest_publish(array $article, ?array $actor = null): array
{
  $articleId = trim((string) ($article['id'] ?? ''));
  if ($articleId === '') {
    return [
      'ok' => false,
      'code' => 'missing_article_id',
      'message' => 'Thiếu article id để rollback.',
    ];
  }

  $latest = find_latest_publish_record($articleId);
  if ($latest === null) {
    return [
      'ok' => false,
      'code' => 'no_publish_record',
      'message' => 'Chưa có publish record để rollback.',
    ];
  }

  $targetPath = (string) ($latest['target_path'] ?? '');
  $backupPath = (string) ($latest['backup_path'] ?? '');
  if ($targetPath === '' || $backupPath === '' || !file_exists($backupPath)) {
    return [
      'ok' => false,
      'code' => 'missing_backup',
      'message' => 'Không tìm thấy backup để rollback.',
    ];
  }

  // Backup current target before restoring
  $rollbackBackupPath = build_backup_file_path($articleId . '-rollback-src');
  copy($targetPath, $rollbackBackupPath);

  if (!copy($backupPath, $targetPath)) {
    return [
      'ok' => false,
      'code' => 'rollback_copy_failed',
      'message' => 'Rollback thất bại khi restore backup.',
    ];
  }

  $record = [
    'id' => 'rb-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'rollback',
    'article_id' => $articleId,
    'target_path' => $targetPath,
    'restored_from' => $backupPath,
    'current_backup_before_restore' => $rollbackBackupPath,
    'rolled_back_at' => date('c'),
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'source_publish_id' => (string) ($latest['id'] ?? ''),
  ];
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.rollback.success',
    'article_id' => $articleId,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'restored_from' => $backupPath,
  ]);

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Rollback thành công.',
    'record' => $record,
  ];
}

/**
 * Sync one entry in data/articles.json after publish.
 *
 * @param array<string,mixed> $updates
 */
function sync_article_index_entry(string $articleId, array $updates): void
{
  $articleId = trim($articleId);
  if ($articleId === '' || !file_exists(ADMIN_ARTICLES_SOURCE_PATH)) {
    return;
  }

  $raw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($raw === false || trim($raw) === '') {
    return;
  }
  $items = json_decode($raw, true);
  if (!is_array($items)) {
    return;
  }

  $changed = false;
  foreach ($items as $idx => $item) {
    if (!is_array($item)) {
      continue;
    }
    if ((string) ($item['id'] ?? '') !== $articleId) {
      continue;
    }
    foreach ($updates as $key => $value) {
      $items[$idx][$key] = $value;
    }
    $changed = true;
    break;
  }

  if (!$changed) {
    return;
  }

  $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return;
  }
  file_put_contents(ADMIN_ARTICLES_SOURCE_PATH, $json . PHP_EOL);
  sync_articles_index(true);
}

