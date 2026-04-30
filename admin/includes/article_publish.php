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

function publish_article_root_prefix_from_html(string $html, string $articleId): string
{
  if (preg_match('/<body\b[^>]*\sdata-root=(["\'])(.*?)\1/i', $html, $match)) {
    return (string) ($match[2] ?? '');
  }
  return str_contains($articleId, '/') ? '../' : '';
}

function publish_update_body_nav(string $html, string $sectionKey): string
{
  $sectionAttr = htmlspecialchars($sectionKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return preg_replace_callback('/<body\b([^>]*)>/i', static function (array $match) use ($sectionAttr): string {
    $attrs = (string) ($match[1] ?? '');
    $count = 0;
    $attrs = preg_replace('/\sdata-nav=(["\']).*?\1/i', ' data-nav="' . $sectionAttr . '"', $attrs, 1, $count) ?? $attrs;
    if ($count === 0) {
      $attrs .= ' data-nav="' . $sectionAttr . '"';
    }
    return '<body' . $attrs . '>';
  }, $html, 1) ?? $html;
}

function publish_update_hub_breadcrumb(string $html, string $href, string $label): string
{
  $hrefAttr = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $labelText = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return preg_replace_callback('/<a\b([^>]*\bid=(["\'])articleHubBreadcrumb\2[^>]*)>.*?<\/a>/is', static function (array $match) use ($hrefAttr, $labelText): string {
    $attrs = (string) ($match[1] ?? '');
    $count = 0;
    $attrs = preg_replace('/\shref=(["\']).*?\1/i', ' href="' . $hrefAttr . '"', $attrs, 1, $count) ?? $attrs;
    if ($count === 0) {
      $attrs .= ' href="' . $hrefAttr . '"';
    }
    return '<a' . $attrs . '>' . $labelText . '</a>';
  }, $html, 1) ?? $html;
}

function publish_update_article_kicker(string $html, string $topicLabel): string
{
  $topicLabel = trim($topicLabel);
  if ($topicLabel === '') {
    return $html;
  }
  $topicText = htmlspecialchars($topicLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return preg_replace('/(<span\b[^>]*class=(["\'])(?:(?!\2).)*\barticle-kicker\b(?:(?!\2).)*\2[^>]*>).*?(<\/span>)/is', '$1' . $topicText . '$3', $html, 1) ?? $html;
}

/**
 * Keep visible static article chrome in sync with metadata after section/category changes.
 *
 * @param array<string,string> $taxonomy
 */
function publish_update_static_article_header(string $html, array $taxonomy, string $topicLabel, string $articleId): string
{
  $sectionKey = trim((string) ($taxonomy['section_key'] ?? ''));
  $sectionHref = trim((string) ($taxonomy['section_href'] ?? ''));
  $sectionLabel = trim((string) ($taxonomy['section_label'] ?? ''));
  if ($sectionKey !== '') {
    $html = publish_update_body_nav($html, $sectionKey);
  }
  if ($sectionHref !== '' && $sectionLabel !== '') {
    $rootPrefix = publish_article_root_prefix_from_html($html, $articleId);
    $html = publish_update_hub_breadcrumb($html, $rootPrefix . $sectionHref, $sectionLabel);
  }
  return publish_update_article_kicker($html, $topicLabel);
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
    return rebuild_public_content_native($articleId, [
      'ok' => false,
      'code' => 'missing_rebuild_tool',
      'message' => 'Không tìm thấy tools/rebuild_public_from_articles.py, đã chuyển sang PHP native rebuild.',
    ]);
  }
  if (!function_exists('exec')) {
    return rebuild_public_content_native($articleId, [
      'ok' => false,
      'code' => 'exec_disabled',
      'message' => 'PHP exec() đang bị tắt, đã chuyển sang PHP native rebuild.',
    ]);
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

  return rebuild_public_content_native($articleId, $last ?? [
    'ok' => false,
    'code' => 'python_not_found',
    'message' => 'Không tìm thấy python/python3 để rebuild dữ liệu public.',
  ]);
}

function public_rebuild_root_path(string $relative = ''): string
{
  $root = dirname(dirname(__DIR__));
  return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
}

/**
 * @param mixed $value
 * @return array<int,mixed>
 */
function public_rebuild_list($value): array
{
  return is_array($value) ? array_values($value) : [];
}

function public_rebuild_text($value): string
{
  return trim((string) ($value ?? ''));
}

function public_rebuild_fold(string $value): string
{
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  if (class_exists('Normalizer')) {
    $normalized = Normalizer::normalize($value, Normalizer::FORM_D);
    if (is_string($normalized)) {
      $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $value;
    }
  }
  $value = str_replace('đ', 'd', $value);
  return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
}

/**
 * @return array<string,array<string,string>>
 */
function public_rebuild_library_meta(): array
{
  return [
    'huong-dan' => [
      'label' => 'Hướng dẫn',
      'icon' => 'fa-compass-drafting',
      'description' => 'Quy trình, cách làm, nghiệp vụ thực tế',
    ],
    'bieu-mau' => [
      'label' => 'Biểu mẫu',
      'icon' => 'fa-file-lines',
      'description' => 'Mẫu biểu, hồ sơ, tờ khai dùng ngay',
    ],
    'cong-cu' => [
      'label' => 'Công cụ',
      'icon' => 'fa-screwdriver-wrench',
      'description' => 'Excel, HTKK, MISA và file hỗ trợ',
    ],
    'van-ban' => [
      'label' => 'Văn bản',
      'icon' => 'fa-scale-balanced',
      'description' => 'Luật, nghị định, thông tư, công văn và cập nhật pháp lý',
    ],
  ];
}

function public_rebuild_write_json(string $path, array $data): bool
{
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    return false;
  }
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return $json !== false && file_put_contents($path, $json . PHP_EOL) !== false;
}

function public_rebuild_write_js_store(string $path, string $global, string $key, array $data): bool
{
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    return false;
  }
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return false;
  }
  $content = 'window.' . $global . '=window.' . $global . '||{};window.' . $global . '[' . json_encode($key) . ']=' . $json . ";\n";
  return file_put_contents($path, $content) !== false;
}

/**
 * @return array<int,array<string,mixed>>
 */
function public_rebuild_read_articles(): array
{
  if (!file_exists(ADMIN_ARTICLES_SOURCE_PATH)) {
    return [];
  }
  $raw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($raw === false || trim($raw) === '') {
    return [];
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

/**
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function public_rebuild_hub_item(array $item): array
{
  $href = public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
  return [
    'file' => basename($href, '.html') . '.htm',
    'title' => public_rebuild_text($item['title'] ?? ''),
    'excerpt' => public_rebuild_text($item['excerpt'] ?? ''),
    'topic_lv1_key' => public_rebuild_text($item['topicLv1Key'] ?? ''),
    'topic_lv1_label' => public_rebuild_text($item['topicLv1Label'] ?? ''),
    'topic_lv2_key' => public_rebuild_text($item['topicLv2Key'] ?? ''),
    'topic_lv2_label' => public_rebuild_text($item['topicLv2Label'] ?? ''),
    'topic_lv3_key' => public_rebuild_text($item['topicLv3Key'] ?? ''),
    'topic_lv3_label' => public_rebuild_text($item['topicLv3Label'] ?? ''),
    'tags' => public_rebuild_list($item['tags'] ?? []),
    'badge_label' => public_rebuild_text($item['cardBadgeLabel'] ?? ''),
    'topic_label' => public_rebuild_text($item['cardTopicLabel'] ?? ''),
    'library_kind_key' => public_rebuild_text($item['libraryKindKey'] ?? ''),
    'library_kind_label' => public_rebuild_text($item['libraryKindLabel'] ?? ''),
    'tool_lv3_key' => public_rebuild_text($item['toolLv3Key'] ?? ''),
    'tool_lv3_label' => public_rebuild_text($item['toolLv3Label'] ?? ''),
    'publish_date' => public_rebuild_text($item['publishDate'] ?? ''),
    'image' => public_rebuild_text($item['image'] ?? 'assets/images/content/chia_se_kien_thuc_tai_lieu_KeToanDieuTam.jpg'),
    'href' => $href,
  ];
}

/**
 * @param array<int,array<string,mixed>> $articles
 * @return array<string,array<int,array<string,mixed>>>
 */
function public_rebuild_group_articles(array $articles): array
{
  $grouped = ['thu-vien' => [], 'ban-tin' => []];
  foreach ($articles as $item) {
    $section = public_rebuild_text($item['section'] ?? '');
    if (!isset($grouped[$section])) {
      continue;
    }
    $grouped[$section][] = $item;
  }
  foreach ($grouped as &$items) {
    usort($items, static function (array $a, array $b): int {
      return strcmp(public_rebuild_fold(public_rebuild_text($a['title'] ?? '')), public_rebuild_fold(public_rebuild_text($b['title'] ?? '')));
    });
  }
  unset($items);
  return $grouped;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function public_rebuild_taxonomy(array $items): array
{
  $tree = [];
  foreach ($items as $item) {
    $lv1Key = public_rebuild_text($item['topicLv1Key'] ?? '');
    if ($lv1Key === '') {
      continue;
    }
    if (!isset($tree[$lv1Key])) {
      $tree[$lv1Key] = [
        'key' => $lv1Key,
        'label' => public_rebuild_text($item['topicLv1Label'] ?? $lv1Key),
        'count' => 0,
        'children' => [],
      ];
    }
    $tree[$lv1Key]['count']++;
    $lv2Key = public_rebuild_text($item['topicLv2Key'] ?? '');
    $lv2Label = public_rebuild_text($item['topicLv2Label'] ?? $lv2Key);
    if (!isset($tree[$lv1Key]['children'][$lv2Key])) {
      $tree[$lv1Key]['children'][$lv2Key] = [
        'key' => $lv2Key,
        'label' => $lv2Label,
        'count' => 0,
        'children' => [],
      ];
    }
    $tree[$lv1Key]['children'][$lv2Key]['count']++;
    $lv3Key = public_rebuild_text($item['topicLv3Key'] ?? '');
    if ($lv3Key !== '') {
      if (!isset($tree[$lv1Key]['children'][$lv2Key]['children'][$lv3Key])) {
        $tree[$lv1Key]['children'][$lv2Key]['children'][$lv3Key] = [
          'key' => $lv3Key,
          'label' => public_rebuild_text($item['topicLv3Label'] ?? $lv3Key),
          'count' => 0,
        ];
      }
      $tree[$lv1Key]['children'][$lv2Key]['children'][$lv3Key]['count']++;
    }
  }

  $sortNodes = static function (array $nodes) use (&$sortNodes): array {
    $list = array_values($nodes);
    foreach ($list as &$node) {
      if (isset($node['children']) && is_array($node['children'])) {
        $children = $sortNodes($node['children']);
        if (!empty($children)) {
          $node['children'] = $children;
        } else {
          unset($node['children']);
        }
      }
    }
    unset($node);
    usort($list, static function (array $a, array $b): int {
      $countCmp = ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
      return $countCmp !== 0 ? $countCmp : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
    return $list;
  };

  return $sortNodes($tree);
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function public_rebuild_library_kinds(array $items): array
{
  $meta = public_rebuild_library_meta();
  $counts = [];
  foreach ($items as $item) {
    $key = public_rebuild_text($item['libraryKindKey'] ?? '');
    if ($key !== '') {
      $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
  }
  $out = [];
  foreach ($meta as $key => $row) {
    $count = (int) ($counts[$key] ?? 0);
    if ($count <= 0) {
      continue;
    }
    $out[] = [
      'key' => $key,
      'label' => $row['label'],
      'count' => $count,
      'href' => 'thu-vien.html?kind=' . $key,
      'icon' => $row['icon'],
      'description' => $row['description'],
    ];
  }
  return $out;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function public_rebuild_feed(array $items): array
{
  usort($items, static function (array $a, array $b): int {
    $dateCmp = strcmp(public_rebuild_text($b['publishDate'] ?? ''), public_rebuild_text($a['publishDate'] ?? ''));
    return $dateCmp !== 0 ? $dateCmp : strcmp(public_rebuild_text($a['title'] ?? ''), public_rebuild_text($b['title'] ?? ''));
  });
  $items = array_slice($items, 0, 12);
  return array_map(static function (array $item): array {
    $href = public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
    return [
      'title' => public_rebuild_text($item['title'] ?? ''),
      'href' => $href,
      'canonical' => 'https://ketoandieutam.vn/' . $href,
      'publishDate' => public_rebuild_text($item['publishDate'] ?? ''),
      'modifiedDate' => $item['modifiedDate'] ?? null,
      'image' => public_rebuild_text($item['image'] ?? ''),
      'badgeLabel' => public_rebuild_text($item['cardBadgeLabel'] ?? ''),
      'topicLabel' => public_rebuild_text($item['cardTopicLabel'] ?? ''),
      'libraryKindKey' => public_rebuild_text($item['libraryKindKey'] ?? ''),
      'libraryKindLabel' => public_rebuild_text($item['libraryKindLabel'] ?? ''),
      'toolLv3Key' => public_rebuild_text($item['toolLv3Key'] ?? ''),
      'toolLv3Label' => public_rebuild_text($item['toolLv3Label'] ?? ''),
      'tags' => public_rebuild_list($item['tags'] ?? []),
    ];
  }, $items);
}

/**
 * @return array<string,mixed>
 */
function public_rebuild_existing_index(): array
{
  $path = public_rebuild_root_path('content-index.js');
  if (!file_exists($path)) {
    return [];
  }
  $raw = trim((string) file_get_contents($path));
  $prefix = 'window.KetoanDieuTamContentIndex=';
  if (str_starts_with($raw, $prefix)) {
    $raw = substr($raw, strlen($prefix));
  }
  $raw = rtrim($raw, ";\r\n\t ");
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,array<int,array<string,mixed>>> $grouped
 * @return array<string,mixed>
 */
function public_rebuild_content_index(array $grouped, string $targetArticleId): array
{
  $existing = public_rebuild_existing_index();
  $existingViews = is_array($existing['articleViews'] ?? null) ? $existing['articleViews'] : [];
  $articles = [];
  $views = [];
  foreach ($grouped as $section => $items) {
    $ids = array_map(static fn (array $item): string => public_rebuild_text($item['href'] ?? ($item['id'] ?? '')), $items);
    foreach ($items as $idx => $item) {
      $href = public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
      $articles[$href] = [
        'id' => $href,
        'section' => $section,
        'sectionLabel' => $section === 'ban-tin' ? 'Bản tin' : 'Thư viện',
        'sectionHref' => $section . '.html',
        'href' => $href,
        'canonical' => 'https://ketoandieutam.vn/' . $href,
        'articleHref' => public_rebuild_text($item['articleHref'] ?? ''),
        'legacyHref' => public_rebuild_text($item['legacyHref'] ?? ''),
        'title' => public_rebuild_text($item['title'] ?? ''),
        'excerpt' => public_rebuild_text($item['excerpt'] ?? ''),
        'topicLv1Key' => public_rebuild_text($item['topicLv1Key'] ?? ''),
        'topicLv1Label' => public_rebuild_text($item['topicLv1Label'] ?? ''),
        'topicLv2Key' => public_rebuild_text($item['topicLv2Key'] ?? ''),
        'topicLv2Label' => public_rebuild_text($item['topicLv2Label'] ?? ''),
        'topicLv3Key' => public_rebuild_text($item['topicLv3Key'] ?? ''),
        'topicLv3Label' => public_rebuild_text($item['topicLv3Label'] ?? ''),
        'tags' => public_rebuild_list($item['tags'] ?? []),
        'primarySection' => public_rebuild_text($item['primarySection'] ?? $section),
        'secondarySections' => public_rebuild_list($item['secondarySections'] ?? []),
        'classificationReasons' => is_array($item['classificationReasons'] ?? null) ? $item['classificationReasons'] : [],
        'legacyPrimarySection' => $item['legacyPrimarySection'] ?? null,
        'legacySecondarySections' => public_rebuild_list($item['legacySecondarySections'] ?? []),
        'libraryKindKey' => public_rebuild_text($item['libraryKindKey'] ?? ''),
        'libraryKindLabel' => public_rebuild_text($item['libraryKindLabel'] ?? ''),
        'toolLv3Key' => public_rebuild_text($item['toolLv3Key'] ?? ''),
        'toolLv3Label' => public_rebuild_text($item['toolLv3Label'] ?? ''),
        'cardBadgeLabel' => public_rebuild_text($item['cardBadgeLabel'] ?? ''),
        'cardTopicLabel' => public_rebuild_text($item['cardTopicLabel'] ?? ''),
        'image' => public_rebuild_text($item['image'] ?? ''),
        'publishDate' => public_rebuild_text($item['publishDate'] ?? ''),
        'modifiedDate' => $item['modifiedDate'] ?? null,
        'authorName' => public_rebuild_text($item['authorName'] ?? 'Kế Toán Diệu Tâm'),
        'authorType' => public_rebuild_text($item['authorType'] ?? 'Organization'),
      ];
      if (isset($existingViews[$href]) && is_array($existingViews[$href]) && $href !== $targetArticleId) {
        $views[$href] = $existingViews[$href];
        continue;
      }
      $views[$href] = [
        'currentIndex' => $idx + 1,
        'totalCount' => count($items),
        'prev' => $ids[$idx - 1] ?? null,
        'next' => $ids[$idx + 1] ?? null,
        'newsLatest' => array_slice(array_values(array_filter(array_map(static fn (array $row): string => public_rebuild_text($row['href'] ?? ($row['id'] ?? '')), $grouped['ban-tin'] ?? []), static fn (string $id): bool => $id !== $href)), 0, 3),
        'libraryLatest' => array_slice(array_values(array_filter(array_map(static fn (array $row): string => public_rebuild_text($row['href'] ?? ($row['id'] ?? '')), $grouped['thu-vien'] ?? []), static fn (string $id): bool => $id !== $href)), 0, 3),
        'related' => [],
        'latestOther' => [],
        'fastView' => true,
      ];
    }
  }
  return [
    'generatedAt' => gmdate('c'),
    'sections' => [
      'thu-vien' => ['label' => 'Thư viện', 'href' => 'thu-vien.html'],
      'ban-tin' => ['label' => 'Bản tin', 'href' => 'ban-tin.html'],
    ],
    'articles' => $articles,
    'articleViews' => $views,
  ];
}

/**
 * @param array<string,mixed> $index
 */
function public_rebuild_expand_article(array $index, ?string $articleId): ?array
{
  if ($articleId === null || $articleId === '' || !isset($index['articles'][$articleId]) || !is_array($index['articles'][$articleId])) {
    return null;
  }
  $article = $index['articles'][$articleId];
  return [
    'id' => $article['id'],
    'section' => $article['section'],
    'sectionLabel' => $article['sectionLabel'],
    'sectionHref' => $article['sectionHref'],
    'href' => $article['href'],
    'canonical' => $article['canonical'],
    'title' => $article['title'],
    'excerpt' => $article['excerpt'],
    'topicLabel' => $article['topicLv2Label'],
    'tags' => public_rebuild_list($article['tags'] ?? []),
    'image' => public_rebuild_text($article['image'] ?? ''),
    'libraryKindLabel' => public_rebuild_text($article['libraryKindLabel'] ?? ''),
    'publishDate' => $article['publishDate'] ?? null,
    'modifiedDate' => $article['modifiedDate'] ?? null,
  ];
}

/**
 * @param array<string,mixed> $index
 * @param array<int,string> $ids
 * @return array<int,array<string,mixed>>
 */
function public_rebuild_expand_group(array $index, array $ids): array
{
  $out = [];
  foreach ($ids as $id) {
    $expanded = public_rebuild_expand_article($index, $id);
    if ($expanded !== null) {
      $out[] = $expanded;
    }
  }
  return $out;
}

/**
 * @param array<string,mixed> $index
 */
function public_rebuild_write_target_view(array $index, string $articleId): bool
{
  if ($articleId === '' || !isset($index['articleViews'][$articleId]) || !is_array($index['articleViews'][$articleId])) {
    return false;
  }
  $view = $index['articleViews'][$articleId];
  $expanded = [
    'currentIndex' => (int) ($view['currentIndex'] ?? 0),
    'totalCount' => (int) ($view['totalCount'] ?? 0),
    'prev' => public_rebuild_expand_article($index, is_string($view['prev'] ?? null) ? $view['prev'] : null),
    'next' => public_rebuild_expand_article($index, is_string($view['next'] ?? null) ? $view['next'] : null),
    'newsLatest' => public_rebuild_expand_group($index, public_rebuild_list($view['newsLatest'] ?? [])),
    'libraryLatest' => public_rebuild_expand_group($index, public_rebuild_list($view['libraryLatest'] ?? [])),
    'related' => public_rebuild_expand_group($index, public_rebuild_list($view['related'] ?? [])),
    'latestOther' => public_rebuild_expand_group($index, public_rebuild_list($view['latestOther'] ?? [])),
  ];
  $path = public_rebuild_root_path('data/article-views/' . $articleId . '.json');
  return public_rebuild_write_json($path, $expanded)
    && public_rebuild_write_js_store(substr($path, 0, -5) . '.js', 'KetoanDieuTamArticleViewStore', $articleId, $expanded);
}

/**
 * @param array<string,array<int,array<string,mixed>>> $grouped
 */
function public_rebuild_write_taxonomy_artifacts(array $grouped): bool
{
  $meta = public_rebuild_library_meta();
  $thuVien = $grouped['thu-vien'] ?? [];
  $banTin = $grouped['ban-tin'] ?? [];
  $kindChildren = [];
  $editorKindChildren = [];
  foreach ($meta as $kind => $row) {
    $kindItems = array_values(array_filter($thuVien, static fn (array $item): bool => public_rebuild_text($item['libraryKindKey'] ?? '') === $kind));
    $tree = public_rebuild_taxonomy($kindItems);
    $kindChildren[] = [
      'key' => $kind,
      'label' => $row['label'],
      'count' => count($kindItems),
      'children' => $tree,
    ];
    $editorKindChildren[] = [
      'id' => $kind,
      'label' => $row['label'],
      'children' => $tree,
    ];
  }
  $generated = gmdate('c');
  $taxonomy = [
    'generatedAt' => $generated,
    'roots' => [
      ['key' => 'thu-vien', 'label' => 'Thư viện', 'count' => count($thuVien), 'children' => $kindChildren],
      ['key' => 'ban-tin', 'label' => 'Bản tin', 'count' => count($banTin), 'children' => public_rebuild_taxonomy($banTin)],
    ],
    'toolVariants' => [],
  ];
  $editor = [
    'generatedAt' => $generated,
    'roots' => [
      ['id' => 'thu-vien', 'label' => 'Thư viện', 'children' => $editorKindChildren],
      ['id' => 'ban-tin', 'label' => 'Bản tin', 'children' => public_rebuild_taxonomy($banTin)],
    ],
    'variants' => ['cong-cu' => []],
    'fieldMap' => [
      'section' => 'primary_category_id',
      'library_kind' => 'library_kind',
      'topic_lv1' => 'domain',
      'topic_lv2' => 'subdomain',
      'tool_lv3' => 'variant',
    ],
  ];
  $menu = [
    'generatedAt' => $generated,
    'items' => [
      ['key' => 'home', 'label' => 'Trang Chủ', 'href' => 'index.html'],
      ['key' => 'gioi-thieu', 'label' => 'Giới Thiệu', 'href' => 'gioi-thieu.html'],
      ['key' => 'giai-phap', 'label' => 'Giải Pháp', 'href' => 'giai-phap.html'],
      ['key' => 'dao-tao', 'label' => 'Đào Tạo', 'href' => 'dao-tao.html'],
      [
        'key' => 'thu-vien',
        'label' => 'Thư Viện',
        'href' => 'thu-vien.html',
        'category' => 'thu-vien',
        'children' => array_map(static fn (string $kind, array $row): array => [
          'key' => 'thu-vien-' . $kind,
          'label' => $row['label'],
          'href' => 'thu-vien.html?kind=' . $kind,
          'category' => $kind,
        ], array_keys($meta), array_values($meta)),
      ],
      ['key' => 'ban-tin', 'label' => 'Bản Tin', 'href' => 'ban-tin.html', 'category' => 'ban-tin'],
      ['key' => 'lien-he', 'label' => 'Liên Hệ', 'href' => 'lien-he.html'],
    ],
  ];
  return public_rebuild_write_json(public_rebuild_root_path('data/taxonomy.json'), $taxonomy)
    && public_rebuild_write_json(public_rebuild_root_path('data/editor-taxonomy.json'), $editor)
    && public_rebuild_write_json(public_rebuild_root_path('data/menu-config.json'), $menu);
}

/**
 * @param array<string,array<int,array<string,mixed>>> $grouped
 * @param array<string,mixed> $index
 */
function public_rebuild_write_sitemap(array $grouped, array $index): bool
{
  $today = gmdate('Y-m-d');
  $urls = ['  <url><loc>https://ketoandieutam.vn/index.html</loc></url>'];
  foreach (['thu-vien', 'ban-tin'] as $section) {
    $count = count($grouped[$section] ?? []);
    $pages = max(1, (int) ceil($count / 12));
    for ($page = 1; $page <= $pages; $page++) {
      $href = $page === 1 ? $section . '.html' : $section . '/trang/' . $page . '/index.html';
      $urls[] = '  <url><loc>https://ketoandieutam.vn/' . htmlspecialchars($href, ENT_XML1) . '</loc><lastmod>' . $today . '</lastmod></url>';
    }
  }
  foreach (($index['articles'] ?? []) as $article) {
    if (!is_array($article)) {
      continue;
    }
    $href = public_rebuild_text($article['href'] ?? '');
    if ($href !== '') {
      $lastmod = public_rebuild_text($article['modifiedDate'] ?? ($article['publishDate'] ?? $today)) ?: $today;
      $urls[] = '  <url><loc>https://ketoandieutam.vn/' . htmlspecialchars($href, ENT_XML1) . '</loc><lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . '</lastmod></url>';
    }
  }
  $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode("\n", $urls) . "\n</urlset>\n";
  return file_put_contents(public_rebuild_root_path('sitemap.xml'), $xml) !== false;
}

/**
 * PHP-native public rebuild fallback. This avoids relying on disabled exec()/python on hosting.
 *
 * @param array<string,mixed> $previousAttempt
 * @return array<string,mixed>
 */
function rebuild_public_content_native(string $articleId, array $previousAttempt = []): array
{
  try {
    $articles = public_rebuild_read_articles();
    if (empty($articles)) {
      return [
        'ok' => false,
        'code' => 'native_empty_articles',
        'message' => 'PHP native rebuild không đọc được data/articles.json.',
        'previous_attempt' => $previousAttempt,
      ];
    }
    $grouped = public_rebuild_group_articles($articles);
    $index = public_rebuild_content_index($grouped, $articleId);

    $contentIndexJson = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($contentIndexJson === false || file_put_contents(public_rebuild_root_path('content-index.js'), 'window.KetoanDieuTamContentIndex=' . $contentIndexJson . ";\n") === false) {
      throw new RuntimeException('Không ghi được content-index.js');
    }

    $pageCounts = [];
    foreach (['thu-vien', 'ban-tin'] as $section) {
      $items = $grouped[$section] ?? [];
      $pages = max(1, (int) ceil(count($items) / 12));
      $pageMap = [];
      for ($page = 1; $page <= $pages; $page++) {
        $pageMap[(string) $page] = $page === 1 ? $section . '.html' : $section . '/trang/' . $page . '/index.html';
      }
      $hub = [
        'section' => $section,
        'sectionLabel' => $section === 'ban-tin' ? 'Bản tin' : 'Thư viện',
        'sectionHref' => $section . '.html',
        'pageMap' => $pageMap,
        'libraryKinds' => $section === 'thu-vien' ? public_rebuild_library_kinds($items) : [],
        'taxonomy' => public_rebuild_taxonomy($items),
        'count' => count($items),
        'articles' => array_map('public_rebuild_hub_item', $items),
      ];
      if (!public_rebuild_write_json(public_rebuild_root_path('data/hubs/' . $section . '.json'), $hub)
        || !public_rebuild_write_js_store(public_rebuild_root_path('data/hubs/' . $section . '.js'), 'KetoanDieuTamHubStore', $section, $hub)
        || !public_rebuild_write_json(public_rebuild_root_path('data/feeds/latest-' . $section . '.json'), public_rebuild_feed($items))) {
        throw new RuntimeException('Không ghi được hub/feed cho ' . $section);
      }
      $pageCounts[$section] = $pages;
    }

    if (!public_rebuild_write_taxonomy_artifacts($grouped)) {
      throw new RuntimeException('Không ghi được taxonomy/menu artifacts');
    }
    $targetViewWritten = public_rebuild_write_target_view($index, $articleId);
    public_rebuild_write_sitemap($grouped, $index);

    return [
      'ok' => true,
      'code' => 'ok_native',
      'message' => 'Đã đồng bộ dữ liệu public bằng PHP native rebuild.',
      'mode' => 'php-native-fast',
      'previous_attempt' => $previousAttempt,
      'summary' => [
        'articles' => count($articles),
        'thu_vien_count' => count($grouped['thu-vien'] ?? []),
        'ban_tin_count' => count($grouped['ban-tin'] ?? []),
        'thu_vien_pages' => $pageCounts['thu-vien'] ?? 0,
        'ban_tin_pages' => $pageCounts['ban-tin'] ?? 0,
        'target_article_view' => $articleId,
        'target_article_view_written' => $targetViewWritten,
      ],
    ];
  } catch (Throwable $error) {
    return [
      'ok' => false,
      'code' => 'native_rebuild_failed',
      'message' => 'PHP native rebuild thất bại: ' . $error->getMessage(),
      'previous_attempt' => $previousAttempt,
    ];
  }
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
  $htmlNew = publish_update_static_article_header($htmlNew, $taxonomy, $displayTopicLabel, $articleId);

  // Bust cached article JS for the published article page.
  $assetVersion = date('YmdHis');
  $htmlNew = preg_replace_callback(
    '/<script\b([^>]*\bsrc=(["\'])([^"\']*article-layout\.js)(?:\?[^"\']*)?\2[^>]*)><\/script>/i',
    static function (array $match) use ($assetVersion): string {
      $attrs = preg_replace(
        '/src=(["\'])([^"\']*article-layout\.js)(?:\?[^"\']*)?\1/i',
        'src=$1$2?v=' . $assetVersion . '$1',
        (string) ($match[1] ?? ''),
        1
      );
      return '<script' . ($attrs ?? (string) ($match[1] ?? '')) . '></script>';
    },
    $htmlNew,
    1
  ) ?? $htmlNew;
  $htmlNew = preg_replace_callback(
    '/<script\b([^>]*\bsrc=(["\'])([^"\']*data\/article-views\/[^"\']+\.js)(?:\?[^"\']*)?\2[^>]*)><\/script>/i',
    static function (array $match) use ($assetVersion): string {
      $attrs = preg_replace(
        '/src=(["\'])([^"\']*data\/article-views\/[^"\']+\.js)(?:\?[^"\']*)?\1/i',
        'src=$1$2?v=' . $assetVersion . '$1',
        (string) ($match[1] ?? ''),
        1
      );
      return '<script' . ($attrs ?? (string) ($match[1] ?? '')) . '></script>';
    },
    $htmlNew,
    1
  ) ?? $htmlNew;

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
