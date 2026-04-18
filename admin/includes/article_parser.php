<?php
declare(strict_types=1);

/**
 * Resolve absolute article file path from index item.
 */
function resolve_article_file_path(array $article): string
{
  $href = trim((string) ($article['href'] ?? ''));
  if ($href === '') {
    return '';
  }
  $href = strtok($href, '?');
  if ($href === false || trim($href) === '') {
    return '';
  }
  $relative = ltrim(trim((string) $href), '/');
  return dirname(ADMIN_BASE_PATH) . '/' . $relative;
}

/**
 * Parse one article HTML and extract editable regions.
 *
 * @return array<string,mixed>
 */
function parse_article_file(string $path): array
{
  if ($path === '' || !file_exists($path)) {
    return [
      'ok' => false,
      'code' => 'missing_file',
      'message' => 'Không tìm thấy file bài viết.',
      'path' => $path,
    ];
  }

  $html = file_get_contents($path);
  if ($html === false || trim($html) === '') {
    return [
      'ok' => false,
      'code' => 'empty_file',
      'message' => 'File rỗng hoặc không đọc được.',
      'path' => $path,
    ];
  }

  $proseRegion = extract_prose_region($html);
  if (!$proseRegion['ok']) {
    return [
      'ok' => false,
      'code' => $proseRegion['code'],
      'message' => $proseRegion['message'],
      'path' => $path,
    ];
  }

  $metaRegion = extract_article_meta_region($html);
  if (!$metaRegion['ok']) {
    return [
      'ok' => false,
      'code' => $metaRegion['code'],
      'message' => $metaRegion['message'],
      'path' => $path,
    ];
  }

  /** @var array<string,mixed>|null $metaDecoded */
  $metaDecoded = json_decode((string) $metaRegion['inner'], true);
  if (!is_array($metaDecoded)) {
    return [
      'ok' => false,
      'code' => 'invalid_article_meta_json',
      'message' => 'JSON trong script#article-meta không hợp lệ.',
      'path' => $path,
    ];
  }

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Parse thành công.',
    'path' => $path,
    'html' => $html,
    'prose' => $proseRegion,
    'meta' => $metaRegion,
    'meta_payload' => $metaDecoded,
  ];
}

/**
 * Extract `<div class="article-prose"> ... </div>` region with boundary offsets.
 *
 * @return array<string,mixed>
 */
function extract_prose_region(string $html): array
{
  if (!preg_match('/<div\b[^>]*class=(["\'])(?:(?!\1).)*\barticle-prose\b(?:(?!\1).)*\1[^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE)) {
    return [
      'ok' => false,
      'code' => 'missing_prose_region',
      'message' => 'Không tìm thấy khối .article-prose.',
    ];
  }

  $openTag = (string) $match[0][0];
  $openOffset = (int) $match[0][1];
  $openEnd = $openOffset + strlen($openTag);

  $closeOffset = find_matching_div_close_offset($html, $openOffset);
  if ($closeOffset === null) {
    return [
      'ok' => false,
      'code' => 'unbalanced_prose_div',
      'message' => 'Không xác định được thẻ đóng của .article-prose.',
    ];
  }

  $closeTag = '</div>';
  $inner = substr($html, $openEnd, $closeOffset - $openEnd);
  if ($inner === false) {
    $inner = '';
  }

  return [
    'ok' => true,
    'start' => $openOffset,
    'open_tag_end' => $openEnd,
    'close_tag_start' => $closeOffset,
    'end' => $closeOffset + strlen($closeTag),
    'open_tag' => $openTag,
    'close_tag' => $closeTag,
    'inner' => $inner,
    'inner_length' => strlen($inner),
  ];
}

/**
 * Find matching closing `</div>` offset for div opened at $openOffset.
 */
function find_matching_div_close_offset(string $html, int $openOffset): ?int
{
  $tail = substr($html, $openOffset);
  if ($tail === false || $tail === '') {
    return null;
  }

  if (!preg_match_all('/<\/?div\b[^>]*>/i', $tail, $tokens, PREG_OFFSET_CAPTURE)) {
    return null;
  }

  $depth = 0;
  $started = false;
  foreach ($tokens[0] as $entry) {
    $token = strtolower((string) $entry[0]);
    $offset = $openOffset + (int) $entry[1];
    $isClose = str_starts_with($token, '</div');

    if (!$started) {
      if ($offset !== $openOffset) {
        continue;
      }
      $started = true;
      $depth = 1;
      continue;
    }

    if ($isClose) {
      $depth--;
      if ($depth === 0) {
        return $offset;
      }
      continue;
    }

    $depth++;
  }

  return null;
}

/**
 * Extract `<script id="article-meta">...</script>` region.
 *
 * @return array<string,mixed>
 */
function extract_article_meta_region(string $html): array
{
  if (!preg_match('/<script\b[^>]*id=(["\'])article-meta\1[^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE)) {
    return [
      'ok' => false,
      'code' => 'missing_article_meta_script',
      'message' => 'Không tìm thấy script#article-meta.',
    ];
  }

  $openTag = (string) $match[0][0];
  $openOffset = (int) $match[0][1];
  $openEnd = $openOffset + strlen($openTag);

  $closeOffset = stripos($html, '</script>', $openEnd);
  if ($closeOffset === false) {
    return [
      'ok' => false,
      'code' => 'missing_article_meta_close',
      'message' => 'Không tìm thấy thẻ đóng của script#article-meta.',
    ];
  }

  $inner = substr($html, $openEnd, $closeOffset - $openEnd);
  if ($inner === false) {
    $inner = '';
  }

  return [
    'ok' => true,
    'start' => $openOffset,
    'open_tag_end' => $openEnd,
    'close_tag_start' => $closeOffset,
    'end' => $closeOffset + strlen('</script>'),
    'open_tag' => $openTag,
    'close_tag' => '</script>',
    'inner' => trim($inner),
    'inner_length' => strlen(trim($inner)),
  ];
}

/**
 * Build parser audit report for all indexed articles.
 *
 * @return array<string,mixed>
 */
function run_parser_audit(bool $force = false): array
{
  sync_articles_index(false);
  $cache = read_articles_index_cache();
  $items = is_array($cache['items']) ? $cache['items'] : [];

  $sourceMTime = file_exists(ADMIN_ARTICLES_SOURCE_PATH) ? (int) filemtime(ADMIN_ARTICLES_SOURCE_PATH) : 0;
  $existing = read_parser_audit_cache();
  $existingMTime = (int) ($existing['meta']['source_mtime'] ?? 0);

  if (!$force && $existingMTime === $sourceMTime && isset($existing['meta']['safe_count'])) {
    return $existing;
  }

  $safeCount = 0;
  $fails = [];
  foreach ($items as $article) {
    if (!is_array($article)) {
      continue;
    }
    $path = resolve_article_file_path($article);
    $parsed = parse_article_file($path);
    if (!empty($parsed['ok'])) {
      $safeCount++;
      continue;
    }
    $fails[] = [
      'id' => (string) ($article['id'] ?? ''),
      'href' => (string) ($article['href'] ?? ''),
      'path' => $path,
      'code' => (string) ($parsed['code'] ?? 'unknown'),
      'message' => (string) ($parsed['message'] ?? ''),
    ];
  }

  $total = count($items);
  $rate = $total > 0 ? round(($safeCount / $total) * 100, 2) : 0.0;
  $report = [
    'meta' => [
      'generated_at' => date('c'),
      'source_mtime' => $sourceMTime,
      'total_count' => $total,
      'safe_count' => $safeCount,
      'fail_count' => count($fails),
      'safe_rate_percent' => $rate,
    ],
    'fails' => $fails,
  ];

  write_parser_audit_cache($report);
  return $report;
}

/**
 * Read parser audit cache.
 *
 * @return array<string,mixed>
 */
function read_parser_audit_cache(): array
{
  if (!file_exists(ADMIN_PARSER_AUDIT_PATH)) {
    return [
      'meta' => [
        'generated_at' => '',
        'source_mtime' => 0,
        'total_count' => 0,
        'safe_count' => 0,
        'fail_count' => 0,
        'safe_rate_percent' => 0,
      ],
      'fails' => [],
    ];
  }

  $raw = file_get_contents(ADMIN_PARSER_AUDIT_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'generated_at' => '',
        'source_mtime' => 0,
        'total_count' => 0,
        'safe_count' => 0,
        'fail_count' => 0,
        'safe_rate_percent' => 0,
      ],
      'fails' => [],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'meta' => [
        'generated_at' => '',
        'source_mtime' => 0,
        'total_count' => 0,
        'safe_count' => 0,
        'fail_count' => 0,
        'safe_rate_percent' => 0,
      ],
      'fails' => [],
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['fails']) || !is_array($decoded['fails'])) {
    $decoded['fails'] = [];
  }

  return $decoded;
}

/**
 * Persist parser audit cache.
 *
 * @param array<string,mixed> $report
 */
function write_parser_audit_cache(array $report): void
{
  $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode parser audit report.');
  }
  file_put_contents(ADMIN_PARSER_AUDIT_PATH, $json . PHP_EOL);
}

/**
 * Pretty JSON helper for UI rendering.
 *
 * @param array<string,mixed> $payload
 */
function pretty_json(array $payload): string
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return $json === false ? '{}' : $json;
}

