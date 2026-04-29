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
 * @return array<int,array<string,mixed>>
 */
function list_recent_publish_records(string $articleId, int $limit = 10): array
{
  $articleId = trim($articleId);
  if ($articleId === '') {
    return [];
  }

  $payload = read_publish_history();
  $records = array_reverse($payload['records']);
  $rows = [];
  foreach ($records as $record) {
    if (!is_array($record)) {
      continue;
    }
    if ((string) ($record['article_id'] ?? '') !== $articleId) {
      continue;
    }
    $rows[] = $record;
    if (count($rows) >= max(1, $limit)) {
      break;
    }
  }
  return $rows;
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
 * Resolve one publish field. Draft values are authoritative, including empty strings.
 *
 * @param array<string,mixed> $draftData
 * @param array<string,mixed> $article
 * @param array<string,mixed> $metaPayload
 */
function publish_resolve_field(array $draftData, array $article, array $metaPayload, string $draftKey, string $articleKey, string $metaKey = ''): string
{
  if (array_key_exists($draftKey, $draftData)) {
    return trim((string) $draftData[$draftKey]);
  }
  if (array_key_exists($articleKey, $article)) {
    return trim((string) $article[$articleKey]);
  }
  if ($metaKey !== '' && array_key_exists($metaKey, $metaPayload)) {
    return trim((string) $metaPayload[$metaKey]);
  }
  return '';
}

/**
 * Build canonical taxonomy fields for article-meta and data/articles.json.
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed> $draftData
 * @param array<string,mixed> $metaPayload
 * @return array<string,string>
 */
function build_publish_taxonomy_payload(array $article, array $draftData, array $metaPayload): array
{
  $sectionKey = publish_resolve_field($draftData, $article, $metaPayload, 'section_key', 'section', 'sectionKey');
  if ($sectionKey === '') {
    $sectionKey = publish_resolve_field($draftData, $article, $metaPayload, 'section_key', 'section', 'section');
  }
  $sectionLabel = publish_resolve_field($draftData, $article, $metaPayload, 'section_label', 'section_label', 'sectionLabel');
  if ($sectionLabel === '') {
    $sectionLabel = $sectionKey === 'ban-tin' ? 'Bản tin' : ($sectionKey === 'thu-vien' ? 'Thư viện' : $sectionKey);
  }
  $sectionHref = publish_resolve_field($draftData, $article, $metaPayload, 'section_href', 'section_href', 'sectionHref');
  if ($sectionHref === '' && $sectionKey !== '') {
    $sectionHref = $sectionKey . '.html';
  }

  $libraryKindKey = publish_resolve_field($draftData, $article, $metaPayload, 'library_kind_key', 'library_kind_key', 'libraryKindKey');
  $libraryKindLabel = publish_resolve_field($draftData, $article, $metaPayload, 'library_kind_label', 'library_kind_label', 'libraryKindLabel');
  if ($sectionKey !== 'thu-vien') {
    $libraryKindKey = '';
    $libraryKindLabel = '';
  }

  $topicLv1Key = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv1_key', 'topic_lv1_key', 'topicLv1Key');
  $topicLv1Label = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv1_label', 'topic_lv1_label', 'topicLv1Label');
  $topicLv2Key = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv2_key', 'topic_lv2_key', 'topicLv2Key');
  $topicLv2Label = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv2_label', 'topic_lv2_label', 'topicLv2Label');
  $topicLv3Key = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv3_key', 'topic_lv3_key', 'topicLv3Key');
  $topicLv3Label = publish_resolve_field($draftData, $article, $metaPayload, 'topic_lv3_label', 'topic_lv3_label', 'topicLv3Label');

  return [
    'section_key' => $sectionKey,
    'section_label' => $sectionLabel,
    'section_href' => $sectionHref,
    'library_kind_key' => $libraryKindKey,
    'library_kind_label' => $libraryKindLabel,
    'topic_lv1_key' => $topicLv1Key,
    'topic_lv1_label' => $topicLv1Label,
    'topic_lv2_key' => $topicLv2Key,
    'topic_lv2_label' => $topicLv2Label,
    'topic_lv3_key' => $topicLv3Key,
    'topic_lv3_label' => $topicLv3Label,
  ];
}

/**
 * @param array<string,string> $taxonomy
 */
function publish_taxonomy_topic_label(array $taxonomy): string
{
  foreach (['topic_lv2_label', 'topic_lv3_label', 'topic_lv1_label'] as $key) {
    $label = trim((string) ($taxonomy[$key] ?? ''));
    if ($label !== '') {
      return $label;
    }
  }
  return '';
}

/**
 * @param array<string,string> $taxonomy
 */
function publish_taxonomy_badge_label(array $taxonomy): string
{
  if ((string) ($taxonomy['section_key'] ?? '') === 'thu-vien') {
    return trim((string) ($taxonomy['library_kind_label'] ?? ''));
  }
  return trim((string) ($taxonomy['topic_lv1_label'] ?? ($taxonomy['section_label'] ?? '')));
}

/**
 * @param array<int,mixed> $tags
 * @return array<int,string>
 */
function publish_normalize_tags(array $tags): array
{
  $out = [];
  $seen = [];
  foreach ($tags as $tag) {
    $clean = trim((string) $tag);
    if ($clean === '') {
      continue;
    }
    $key = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $out[] = $clean;
  }
  return $out;
}

/**
 * @param array<int,mixed> $before
 * @param array<int,mixed> $after
 */
function publish_tags_changed(array $before, array $after): bool
{
  $normalize = static function (array $tags): array {
    $out = [];
    foreach (publish_normalize_tags($tags) as $tag) {
      $out[] = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
    }
    sort($out);
    return $out;
  };
  return $normalize($before) !== $normalize($after);
}

/**
 * @param array<string,string> $taxonomy
 * @param array<string,mixed> $article
 */
function publish_taxonomy_changed(array $taxonomy, array $article): bool
{
  $pairs = [
    'section_key' => 'section',
    'library_kind_key' => 'library_kind_key',
    'topic_lv1_key' => 'topic_lv1_key',
    'topic_lv2_key' => 'topic_lv2_key',
    'topic_lv3_key' => 'topic_lv3_key',
  ];
  foreach ($pairs as $taxonomyKey => $articleKey) {
    if (trim((string) ($taxonomy[$taxonomyKey] ?? '')) !== trim((string) ($article[$articleKey] ?? ''))) {
      return true;
    }
  }
  return false;
}

function public_rebuild_python_command(string $binary): string
{
  $binary = trim($binary);
  if ($binary === '') {
    return '';
  }
  if (str_contains($binary, '/') || str_contains($binary, '\\')) {
    return escapeshellarg($binary);
  }
  return escapeshellcmd($binary);
}

/**
 * Rebuild public hub/content artifacts from data/articles.json after publish.
 *
 * @return array<string,mixed>
 */
function rebuild_public_content_after_publish(string $articleId): array
{
  $root = dirname(dirname(__DIR__));
  $script = $root . '/tools/rebuild_public_from_articles.py';
  if (!file_exists($script)) {
    return [
      'ok' => false,
      'code' => 'missing_rebuild_tool',
      'message' => 'Không tìm thấy tools/rebuild_public_from_articles.py.',
    ];
  }
  if (!function_exists('exec')) {
    return [
      'ok' => false,
      'code' => 'exec_disabled',
      'message' => 'PHP exec() đang bị tắt, không thể tự rebuild dữ liệu public.',
    ];
  }

  $candidates = [];
  $envPython = trim((string) getenv('KDTD_PYTHON_BIN'));
  if ($envPython !== '') {
    $candidates[] = $envPython;
  }
  $candidates[] = 'python3';
  $candidates[] = 'python';
  $candidates = array_values(array_unique($candidates));

  $last = null;
  foreach ($candidates as $python) {
    $pythonCmd = public_rebuild_python_command((string) $python);
    if ($pythonCmd === '') {
      continue;
    }
    $command = $pythonCmd
      . ' ' . escapeshellarg($script)
      . ' --mode fast --source ' . escapeshellarg('admin-publish')
      . ' --article-id ' . escapeshellarg($articleId)
      . ' 2>&1';
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    $outputText = implode("\n", $output);
    $decoded = json_decode($outputText, true);
    $last = [
      'ok' => $exitCode === 0,
      'code' => $exitCode === 0 ? 'ok' : 'rebuild_failed',
      'message' => $exitCode === 0 ? 'Đã đồng bộ dữ liệu public.' : 'Rebuild dữ liệu public thất bại.',
      'python' => (string) $python,
      'exit_code' => $exitCode,
      'summary' => is_array($decoded) ? $decoded : null,
      'output_tail' => array_slice($output, -20),
    ];
    if ($exitCode === 0) {
      return $last;
    }
  }

  return $last ?? [
    'ok' => false,
    'code' => 'python_not_found',
    'message' => 'Không tìm thấy python/python3 để rebuild dữ liệu public.',
  ];
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
  $tagsBefore = publish_normalize_tags(is_array($metaPayload['tags'] ?? null) ? $metaPayload['tags'] : (is_array($article['tags'] ?? null) ? $article['tags'] : []));
  $tagsAfter = publish_normalize_tags($newTags);
  $tagsChanged = publish_tags_changed($tagsBefore, $tagsAfter);
  $newImage = trim((string) ($draftData['featured_image'] ?? ''));
  if ($newImage === '') {
    $newImage = trim((string) ($article['image'] ?? ''));
  }

  if (!is_writable(dirname($path)) || !is_writable($path)) {
    return [
      'ok' => false,
      'code' => 'target_not_writable',
      'message' => 'File đích không có quyền ghi.',
    ];
  }

  $taxonomyBefore = build_publish_taxonomy_payload($article, [], $metaPayload);
  $taxonomy = build_publish_taxonomy_payload($article, $draftData, $metaPayload);
  $taxonomyChanged = publish_taxonomy_changed($taxonomy, $article);
  $displayTopicLabel = publish_taxonomy_topic_label($taxonomy);
  $displayBadgeLabel = publish_taxonomy_badge_label($taxonomy);

  $metaPayload['title'] = $newTitle;
  $metaPayload['section'] = $taxonomy['section_key'];
  $metaPayload['sectionKey'] = $taxonomy['section_key'];
  $metaPayload['sectionLabel'] = $taxonomy['section_label'];
  $metaPayload['sectionHref'] = $taxonomy['section_href'];
  $metaPayload['libraryKindKey'] = $taxonomy['library_kind_key'];
  $metaPayload['libraryKindLabel'] = $taxonomy['library_kind_label'];
  $metaPayload['topicLv1Key'] = $taxonomy['topic_lv1_key'];
  $metaPayload['topicLv1Label'] = $taxonomy['topic_lv1_label'];
  $metaPayload['topicLv2Key'] = $taxonomy['topic_lv2_key'];
  $metaPayload['topicLv2Label'] = $taxonomy['topic_lv2_label'];
  $metaPayload['topicLv3Key'] = $taxonomy['topic_lv3_key'];
  $metaPayload['topicLv3Label'] = $taxonomy['topic_lv3_label'];
  if ($displayTopicLabel !== '') {
    $metaPayload['topicLabel'] = $displayTopicLabel;
    if ($taxonomyChanged || trim((string) ($metaPayload['cardTopicLabel'] ?? '')) === '') {
      $metaPayload['cardTopicLabel'] = $displayTopicLabel;
    }
  }
  if ($displayBadgeLabel !== '' && ($taxonomyChanged || trim((string) ($metaPayload['cardBadgeLabel'] ?? '')) === '')) {
    $metaPayload['cardBadgeLabel'] = $displayBadgeLabel;
  }
  $metaPayload['publishDate'] = $newPublish;
  $metaPayload['modifiedDate'] = $newModified !== '' ? $newModified : null;
  $metaPayload['tags'] = $tagsAfter;
  $metaPayload['excerpt'] = $newExcerpt;
  $metaPayload['image'] = $newImage;

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
  $titleTag = $newTitle . ' | ' . $taxonomy['section_label'] . ' | Kế Toán Diệu Tâm';
  $htmlNew = preg_replace('/<title>.*?<\/title>/is', '<title>' . htmlspecialchars($titleTag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>', $htmlNew, 1) ?? $htmlNew;

  // Update meta description
  $descEscaped = htmlspecialchars($newExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $htmlNew = preg_replace('/<meta\s+name="description"\s+content=".*?">/is', '<meta name="description" content="' . $descEscaped . '">', $htmlNew, 1) ?? $htmlNew;

  // Update article summary paragraph
  $summaryEscaped = htmlspecialchars($newExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $htmlNew = preg_replace('/(<p\b[^>]*class=(["\'])(?:(?!\2).)*\barticle-summary\b(?:(?!\2).)*\2[^>]*>).*?(<\/p>)/is', '$1' . $summaryEscaped . '$3', $htmlNew, 1) ?? $htmlNew;

  $contentHashBefore = hash('sha256', $html);

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

  $contentHashAfter = hash('sha256', $htmlNew);

  $record = [
    'id' => 'pub-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'publish',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'target_path' => $path,
    'backup_path' => $backupPath,
    'bytes_written' => $bytes,
    'hash_before' => $contentHashBefore,
    'hash_after' => $contentHashAfter,
    'published_at' => date('c'),
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'draft_snapshot' => $draftData,
    'taxonomy_changed' => $taxonomyChanged,
    'taxonomy_before' => $taxonomyBefore,
    'taxonomy_after' => $taxonomy,
    'tags_changed' => $tagsChanged,
    'tags_before' => $tagsBefore,
    'tags_after' => $tagsAfter,
  ];

  // Keep article index in sync for card-like fields
  $indexUpdates = [
    'section' => $taxonomy['section_key'],
    'sectionLabel' => $taxonomy['section_label'],
    'sectionHref' => $taxonomy['section_href'],
    'primarySection' => $taxonomy['section_key'],
    'libraryKindKey' => $taxonomy['library_kind_key'],
    'libraryKindLabel' => $taxonomy['library_kind_label'],
    'topicLv1Key' => $taxonomy['topic_lv1_key'],
    'topicLv1Label' => $taxonomy['topic_lv1_label'],
    'topicLv2Key' => $taxonomy['topic_lv2_key'],
    'topicLv2Label' => $taxonomy['topic_lv2_label'],
    'topicLv3Key' => $taxonomy['topic_lv3_key'],
    'topicLv3Label' => $taxonomy['topic_lv3_label'],
    'title' => $newTitle,
    'excerpt' => $newExcerpt,
    'publishDate' => $newPublish,
    'modifiedDate' => $newModified,
    'tags' => $tagsAfter,
    'image' => $newImage,
  ];
  if ($taxonomyChanged) {
    $indexUpdates['cardBadgeLabel'] = $displayBadgeLabel;
    $indexUpdates['cardTopicLabel'] = $displayTopicLabel;
  }
  $indexSynced = sync_article_index_entry($articleId, $indexUpdates);
  $publicRebuild = $indexSynced
    ? rebuild_public_content_after_publish($articleId)
    : [
      'ok' => false,
      'code' => 'index_sync_failed',
      'message' => 'Không rebuild public data vì data/articles.json chưa sync.',
    ];
  $record['index_synced'] = $indexSynced;
  $record['public_rebuild'] = $publicRebuild;
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.publish.success',
    'article_id' => $articleId,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'backup' => $backupPath,
    'taxonomy_changed' => $taxonomyChanged,
    'tags_changed' => $tagsChanged,
    'public_rebuild_ok' => !empty($publicRebuild['ok']),
  ]);

  if (!$indexSynced) {
    append_audit_log([
      'event' => 'article.publish.index_sync_failed',
      'article_id' => $articleId,
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ]);
  }
  if (empty($publicRebuild['ok'])) {
    append_audit_log([
      'event' => 'article.publish.public_rebuild_failed',
      'article_id' => $articleId,
      'code' => (string) ($publicRebuild['code'] ?? 'unknown'),
      'message' => (string) ($publicRebuild['message'] ?? 'Không rõ lỗi'),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ]);
  }

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Publish thành công.',
    'record' => $record,
    'index_synced' => $indexSynced,
    'public_rebuild' => $publicRebuild,
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
  $backupParsed = parse_article_file($backupPath);
  if (empty($backupParsed['ok'])) {
    return [
      'ok' => false,
      'code' => (string) ($backupParsed['code'] ?? 'backup_parse_failed'),
      'message' => 'Backup rollback không parse được article-meta/prose, dừng để tránh sync sai dữ liệu public.',
    ];
  }

  if (!is_writable(dirname($targetPath)) || !is_writable($targetPath)) {
    return [
      'ok' => false,
      'code' => 'target_not_writable',
      'message' => 'File đích không có quyền ghi để rollback.',
    ];
  }

  $currentParsed = parse_article_file($targetPath);
  $currentMetaPayload = is_array($currentParsed['meta_payload'] ?? null) ? $currentParsed['meta_payload'] : [];
  $taxonomyBeforeRollback = build_publish_taxonomy_payload($article, [], $currentMetaPayload);
  $tagsBeforeRollback = publish_normalize_tags(is_array($currentMetaPayload['tags'] ?? null) ? $currentMetaPayload['tags'] : (is_array($article['tags'] ?? null) ? $article['tags'] : []));

  // Backup current target before restoring
  $rollbackBackupPath = build_backup_file_path($articleId . '-rollback-src');
  if (!copy($targetPath, $rollbackBackupPath)) {
    return [
      'ok' => false,
      'code' => 'rollback_backup_failed',
      'message' => 'Không tạo được backup trạng thái hiện tại trước rollback.',
    ];
  }

  if (!copy($backupPath, $targetPath)) {
    return [
      'ok' => false,
      'code' => 'rollback_copy_failed',
      'message' => 'Rollback thất bại khi restore backup.',
    ];
  }

  $restoredHtml = file_get_contents($targetPath);
  $restoredHash = $restoredHtml !== false ? hash('sha256', $restoredHtml) : '';
  $restoredParsed = $backupParsed;
  $restoredMetaPayload = is_array($restoredParsed['meta_payload'] ?? null) ? $restoredParsed['meta_payload'] : [];
  $taxonomyAfterRollback = is_array($latest['taxonomy_before'] ?? null)
    ? $latest['taxonomy_before']
    : build_publish_taxonomy_payload($article, [], $restoredMetaPayload);
  $tagsAfterRollback = publish_normalize_tags(is_array($latest['tags_before'] ?? null)
    ? $latest['tags_before']
    : (is_array($restoredMetaPayload['tags'] ?? null) ? $restoredMetaPayload['tags'] : []));
  $rollbackTopicLabel = trim((string) ($restoredMetaPayload['cardTopicLabel'] ?? ''));
  if ($rollbackTopicLabel === '') {
    $rollbackTopicLabel = publish_taxonomy_topic_label($taxonomyAfterRollback);
  }
  $rollbackBadgeLabel = trim((string) ($restoredMetaPayload['cardBadgeLabel'] ?? ''));
  if ($rollbackBadgeLabel === '') {
    $rollbackBadgeLabel = publish_taxonomy_badge_label($taxonomyAfterRollback);
  }
  $restoredTitle = trim((string) ($restoredMetaPayload['title'] ?? ($article['title'] ?? '')));
  $restoredExcerpt = trim((string) ($restoredParsed['summary_text'] ?? ''));
  if ($restoredExcerpt === '') {
    $restoredExcerpt = trim((string) ($restoredMetaPayload['excerpt'] ?? ($restoredMetaPayload['description'] ?? ($article['excerpt'] ?? ''))));
  }
  $restoredPublishDate = trim((string) ($restoredMetaPayload['publishDate'] ?? ($article['publish_date'] ?? '')));
  $restoredModifiedDate = trim((string) ($restoredMetaPayload['modifiedDate'] ?? ($article['modified_date'] ?? '')));
  $restoredImage = trim((string) ($restoredMetaPayload['image'] ?? ($article['image'] ?? '')));

  $rollbackIndexSynced = sync_article_index_entry($articleId, [
    'section' => (string) ($taxonomyAfterRollback['section_key'] ?? ''),
    'sectionLabel' => (string) ($taxonomyAfterRollback['section_label'] ?? ''),
    'sectionHref' => (string) ($taxonomyAfterRollback['section_href'] ?? ''),
    'primarySection' => (string) ($taxonomyAfterRollback['section_key'] ?? ''),
    'libraryKindKey' => (string) ($taxonomyAfterRollback['library_kind_key'] ?? ''),
    'libraryKindLabel' => (string) ($taxonomyAfterRollback['library_kind_label'] ?? ''),
    'topicLv1Key' => (string) ($taxonomyAfterRollback['topic_lv1_key'] ?? ''),
    'topicLv1Label' => (string) ($taxonomyAfterRollback['topic_lv1_label'] ?? ''),
    'topicLv2Key' => (string) ($taxonomyAfterRollback['topic_lv2_key'] ?? ''),
    'topicLv2Label' => (string) ($taxonomyAfterRollback['topic_lv2_label'] ?? ''),
    'topicLv3Key' => (string) ($taxonomyAfterRollback['topic_lv3_key'] ?? ''),
    'topicLv3Label' => (string) ($taxonomyAfterRollback['topic_lv3_label'] ?? ''),
    'cardBadgeLabel' => $rollbackBadgeLabel,
    'cardTopicLabel' => $rollbackTopicLabel,
    'title' => $restoredTitle,
    'excerpt' => $restoredExcerpt,
    'publishDate' => $restoredPublishDate,
    'modifiedDate' => $restoredModifiedDate,
    'tags' => $tagsAfterRollback,
    'image' => $restoredImage,
  ]);
  $rollbackPublicRebuild = $rollbackIndexSynced
    ? rebuild_public_content_after_publish($articleId)
    : [
      'ok' => false,
      'code' => 'index_sync_failed',
      'message' => 'Không rebuild public data sau rollback vì data/articles.json chưa sync.',
    ];

  $record = [
    'id' => 'rb-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'rollback',
    'article_id' => $articleId,
    'target_path' => $targetPath,
    'restored_from' => $backupPath,
    'current_backup_before_restore' => $rollbackBackupPath,
    'rolled_back_at' => date('c'),
    'restored_hash' => $restoredHash,
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
    'source_publish_id' => (string) ($latest['id'] ?? ''),
    'taxonomy_changed' => publish_taxonomy_changed($taxonomyAfterRollback, [
      'section' => (string) ($taxonomyBeforeRollback['section_key'] ?? ''),
      'library_kind_key' => (string) ($taxonomyBeforeRollback['library_kind_key'] ?? ''),
      'topic_lv1_key' => (string) ($taxonomyBeforeRollback['topic_lv1_key'] ?? ''),
      'topic_lv2_key' => (string) ($taxonomyBeforeRollback['topic_lv2_key'] ?? ''),
      'topic_lv3_key' => (string) ($taxonomyBeforeRollback['topic_lv3_key'] ?? ''),
    ]),
    'taxonomy_before' => $taxonomyBeforeRollback,
    'taxonomy_after' => $taxonomyAfterRollback,
    'tags_changed' => publish_tags_changed($tagsBeforeRollback, $tagsAfterRollback),
    'tags_before' => $tagsBeforeRollback,
    'tags_after' => $tagsAfterRollback,
    'index_synced' => $rollbackIndexSynced,
    'public_rebuild' => $rollbackPublicRebuild,
  ];
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.rollback.success',
    'article_id' => $articleId,
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'restored_from' => $backupPath,
    'taxonomy_changed' => (bool) ($record['taxonomy_changed'] ?? false),
    'tags_changed' => (bool) ($record['tags_changed'] ?? false),
    'public_rebuild_ok' => !empty($rollbackPublicRebuild['ok']),
  ]);

  if (!$rollbackIndexSynced || empty($rollbackPublicRebuild['ok'])) {
    append_audit_log([
      'event' => 'article.rollback.sync_warning',
      'article_id' => $articleId,
      'index_synced' => $rollbackIndexSynced,
      'public_rebuild_ok' => !empty($rollbackPublicRebuild['ok']),
      'message' => !$rollbackIndexSynced
        ? 'Không sync được data/articles.json sau rollback.'
        : (string) ($rollbackPublicRebuild['message'] ?? 'Không rõ lỗi rebuild public sau rollback.'),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ]);
  }

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Rollback thành công.',
    'record' => $record,
    'index_synced' => $rollbackIndexSynced,
    'public_rebuild' => $rollbackPublicRebuild,
  ];
}

/**
 * Sync one entry in data/articles.json after publish.
 *
 * @param array<string,mixed> $updates
 */
function sync_article_index_entry(string $articleId, array $updates): bool
{
  $articleId = trim($articleId);
  if ($articleId === '' || !file_exists(ADMIN_ARTICLES_SOURCE_PATH)) {
    return false;
  }

  $raw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($raw === false || trim($raw) === '') {
    return false;
  }
  $items = json_decode($raw, true);
  if (!is_array($items)) {
    return false;
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
    return false;
  }

  $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  $bytes = file_put_contents(ADMIN_ARTICLES_SOURCE_PATH, $json . PHP_EOL);
  if ($bytes === false) {
    return false;
  }
  sync_articles_index(true);
  return true;
}
