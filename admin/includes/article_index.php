<?php
declare(strict_types=1);

/**
 * Synchronize article index cache from data/articles.json.
 *
 * @return array<string,mixed>
 */
function sync_articles_index(bool $force = false): array
{
  if (!file_exists(ADMIN_ARTICLES_SOURCE_PATH)) {
    return [
      'synced' => false,
      'reason' => 'source_missing',
      'count' => 0,
      'mtime' => 0,
      'cache_updated' => false,
    ];
  }

  $sourceMTime = (int) (filemtime(ADMIN_ARTICLES_SOURCE_PATH) ?: 0);
  $cache = read_articles_index_cache();
  $cacheMTime = (int) ($cache['meta']['source_mtime'] ?? 0);

  if (!$force && !empty($cache['items']) && $cacheMTime === $sourceMTime) {
    return [
      'synced' => true,
      'reason' => 'cache_fresh',
      'count' => count($cache['items']),
      'mtime' => $sourceMTime,
      'cache_updated' => false,
    ];
  }

  $raw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'synced' => false,
      'reason' => 'source_empty',
      'count' => 0,
      'mtime' => $sourceMTime,
      'cache_updated' => false,
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'synced' => false,
      'reason' => 'source_invalid_json',
      'count' => 0,
      'mtime' => $sourceMTime,
      'cache_updated' => false,
    ];
  }

  $items = [];
  foreach ($decoded as $item) {
    if (!is_array($item)) {
      continue;
    }
    $normalized = normalize_article_index_item($item);
    if ($normalized !== null) {
      $items[] = $normalized;
    }
  }

  $cachePayload = [
    'meta' => [
      'source_path' => ADMIN_ARTICLES_SOURCE_PATH,
      'source_mtime' => $sourceMTime,
      'synced_at' => date('c'),
      'count' => count($items),
      'version' => 1,
    ],
    'items' => $items,
    'facets' => build_articles_facets($items),
  ];
  write_articles_index_cache($cachePayload);

  return [
    'synced' => true,
    'reason' => 'cache_rebuilt',
    'count' => count($items),
    'mtime' => $sourceMTime,
    'cache_updated' => true,
  ];
}

/**
 * Read articles index cache from storage.
 *
 * @return array<string,mixed>
 */
function read_articles_index_cache(): array
{
  if (!file_exists(ADMIN_ARTICLES_INDEX_PATH)) {
    return [
      'meta' => [
        'source_mtime' => 0,
        'count' => 0,
      ],
      'items' => [],
      'facets' => default_articles_facets(),
    ];
  }

  $raw = file_get_contents(ADMIN_ARTICLES_INDEX_PATH);
  if ($raw === false || trim($raw) === '') {
    return [
      'meta' => [
        'source_mtime' => 0,
        'count' => 0,
      ],
      'items' => [],
      'facets' => default_articles_facets(),
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'meta' => [
        'source_mtime' => 0,
        'count' => 0,
      ],
      'items' => [],
      'facets' => default_articles_facets(),
    ];
  }

  if (!isset($decoded['meta']) || !is_array($decoded['meta'])) {
    $decoded['meta'] = [];
  }
  if (!isset($decoded['items']) || !is_array($decoded['items'])) {
    $decoded['items'] = [];
  }
  if (!isset($decoded['facets']) || !is_array($decoded['facets'])) {
    $decoded['facets'] = default_articles_facets();
  }

  return $decoded;
}

/**
 * Write article index cache.
 *
 * @param array<string,mixed> $payload
 */
function write_articles_index_cache(array $payload): void
{
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Unable to encode article index cache.');
  }
  file_put_contents(ADMIN_ARTICLES_INDEX_PATH, $json . PHP_EOL);
}

/**
 * Build default facets structure.
 *
 * @return array<string,mixed>
 */
function default_articles_facets(): array
{
  return [
    'sections' => [],
    'library_kinds' => [],
    'topic_lv1' => [],
    'topic_lv2' => [],
  ];
}

/**
 * Normalize source item to compact index entry.
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>|null
 */
function normalize_article_index_item(array $item): ?array
{
  $id = trim((string) ($item['id'] ?? ''));
  $title = trim((string) ($item['title'] ?? ''));
  if ($id === '' || $title === '') {
    return null;
  }

  $section = trim((string) ($item['section'] ?? ''));
  $libraryKindKey = trim((string) ($item['libraryKindKey'] ?? ''));
  $publishDate = normalize_date_ymd((string) ($item['publishDate'] ?? ''));
  $modifiedDate = normalize_date_ymd((string) ($item['modifiedDate'] ?? ''));
  $topicLv1Key = trim((string) ($item['topicLv1Key'] ?? ''));
  $topicLv2Key = trim((string) ($item['topicLv2Key'] ?? ''));

  $searchIndex = build_article_search_index([
    $id,
    $title,
    (string) ($item['href'] ?? ''),
    (string) ($item['canonical'] ?? ''),
    (string) ($item['topicLv1Label'] ?? ''),
    (string) ($item['topicLv2Label'] ?? ''),
    (string) ($item['cardTopicLabel'] ?? ''),
    (string) ($item['sectionLabel'] ?? ''),
  ]);

  $sortDate = $modifiedDate !== '' ? $modifiedDate : $publishDate;
  if ($sortDate === '') {
    $sortDate = '1900-01-01';
  }

  return [
    'id' => $id,
    'title' => $title,
    'href' => (string) ($item['href'] ?? ''),
    'canonical' => (string) ($item['canonical'] ?? ''),
    'section' => $section,
    'section_label' => (string) ($item['sectionLabel'] ?? ''),
    'library_kind_key' => $libraryKindKey,
    'library_kind_label' => (string) ($item['libraryKindLabel'] ?? ''),
    'topic_lv1_key' => $topicLv1Key,
    'topic_lv1_label' => (string) ($item['topicLv1Label'] ?? ''),
    'topic_lv2_key' => $topicLv2Key,
    'topic_lv2_label' => (string) ($item['topicLv2Label'] ?? ''),
    'publish_date' => $publishDate,
    'modified_date' => $modifiedDate,
    'author_name' => (string) ($item['authorName'] ?? ''),
    'card_badge_label' => (string) ($item['cardBadgeLabel'] ?? ''),
    'search_index' => $searchIndex,
    'sort_date' => $sortDate,
  ];
}

/**
 * Normalize date to YYYY-MM-DD.
 */
function normalize_date_ymd(string $value): string
{
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  $time = strtotime($value);
  if ($time === false) {
    return '';
  }
  return date('Y-m-d', $time);
}

/**
 * Build pre-normalized search index string.
 *
 * @param array<int,string> $parts
 */
function build_article_search_index(array $parts): string
{
  $joined = trim(implode(' ', $parts));
  if ($joined === '') {
    return '';
  }
  $normalized = strtolower($joined);
  $normalized = preg_replace('/\s+/', ' ', $normalized);
  return trim((string) $normalized);
}

/**
 * Build facets for fast filter select options.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<string,mixed>
 */
function build_articles_facets(array $items): array
{
  $sections = [];
  $libraryKinds = [];
  $topicLv1 = [];
  $topicLv2 = [];

  foreach ($items as $item) {
    $sectionKey = (string) ($item['section'] ?? '');
    if ($sectionKey !== '') {
      if (!isset($sections[$sectionKey])) {
        $sections[$sectionKey] = [
          'key' => $sectionKey,
          'label' => (string) ($item['section_label'] ?? $sectionKey),
          'count' => 0,
        ];
      }
      $sections[$sectionKey]['count']++;
    }

    $kindKey = (string) ($item['library_kind_key'] ?? '');
    if ($kindKey !== '') {
      if (!isset($libraryKinds[$kindKey])) {
        $libraryKinds[$kindKey] = [
          'key' => $kindKey,
          'label' => (string) ($item['library_kind_label'] ?? $kindKey),
          'count' => 0,
        ];
      }
      $libraryKinds[$kindKey]['count']++;
    }

    $lv1Key = (string) ($item['topic_lv1_key'] ?? '');
    if ($lv1Key !== '') {
      if (!isset($topicLv1[$lv1Key])) {
        $topicLv1[$lv1Key] = [
          'key' => $lv1Key,
          'label' => (string) ($item['topic_lv1_label'] ?? $lv1Key),
          'count' => 0,
        ];
      }
      $topicLv1[$lv1Key]['count']++;
    }

    $lv2Key = (string) ($item['topic_lv2_key'] ?? '');
    if ($lv2Key !== '') {
      if (!isset($topicLv2[$lv2Key])) {
        $topicLv2[$lv2Key] = [
          'key' => $lv2Key,
          'label' => (string) ($item['topic_lv2_label'] ?? $lv2Key),
          'count' => 0,
        ];
      }
      $topicLv2[$lv2Key]['count']++;
    }
  }

  $sortFacet = static function (array &$items): void {
    usort($items, static function (array $a, array $b): int {
      return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
  };

  $sections = array_values($sections);
  $libraryKinds = array_values($libraryKinds);
  $topicLv1 = array_values($topicLv1);
  $topicLv2 = array_values($topicLv2);

  $sortFacet($sections);
  $sortFacet($libraryKinds);
  $sortFacet($topicLv1);
  $sortFacet($topicLv2);

  return [
    'sections' => $sections,
    'library_kinds' => $libraryKinds,
    'topic_lv1' => $topicLv1,
    'topic_lv2' => $topicLv2,
  ];
}

/**
 * Filter and paginate articles.
 *
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function query_articles_index(array $filters): array
{
  $cache = read_articles_index_cache();
  $items = is_array($cache['items']) ? $cache['items'] : [];

  $searchRaw = trim((string) ($filters['q'] ?? ''));
  $search = strtolower($searchRaw);
  $search = trim((string) preg_replace('/\s+/', ' ', $search));
  $section = trim((string) ($filters['section'] ?? ''));
  $libraryKind = trim((string) ($filters['library_kind_key'] ?? ''));
  $topicLv1 = trim((string) ($filters['topic_lv1_key'] ?? ''));
  $topicLv2 = trim((string) ($filters['topic_lv2_key'] ?? ''));
  $dateFrom = normalize_date_ymd((string) ($filters['date_from'] ?? ''));
  $dateTo = normalize_date_ymd((string) ($filters['date_to'] ?? ''));
  $sort = (string) ($filters['sort'] ?? 'latest');
  $page = max(1, (int) ($filters['page'] ?? 1));
  $perPage = (int) ($filters['per_page'] ?? 20);
  if ($perPage < 10) {
    $perPage = 10;
  }
  if ($perPage > 100) {
    $perPage = 100;
  }

  $filtered = array_values(array_filter($items, static function ($item) use ($search, $section, $libraryKind, $topicLv1, $topicLv2, $dateFrom, $dateTo): bool {
    if (!is_array($item)) {
      return false;
    }
    if ($search !== '' && strpos((string) ($item['search_index'] ?? ''), $search) === false) {
      return false;
    }
    if ($section !== '' && (string) ($item['section'] ?? '') !== $section) {
      return false;
    }
    if ($libraryKind !== '' && (string) ($item['library_kind_key'] ?? '') !== $libraryKind) {
      return false;
    }
    if ($topicLv1 !== '' && (string) ($item['topic_lv1_key'] ?? '') !== $topicLv1) {
      return false;
    }
    if ($topicLv2 !== '' && (string) ($item['topic_lv2_key'] ?? '') !== $topicLv2) {
      return false;
    }

    $publishDate = (string) ($item['publish_date'] ?? '');
    if ($dateFrom !== '' && ($publishDate === '' || $publishDate < $dateFrom)) {
      return false;
    }
    if ($dateTo !== '' && ($publishDate === '' || $publishDate > $dateTo)) {
      return false;
    }

    return true;
  }));

  usort($filtered, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'oldest') {
      return strcmp((string) ($a['sort_date'] ?? ''), (string) ($b['sort_date'] ?? ''));
    }
    if ($sort === 'title_asc') {
      return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    }
    if ($sort === 'title_desc') {
      return strcmp((string) ($b['title'] ?? ''), (string) ($a['title'] ?? ''));
    }
    return strcmp((string) ($b['sort_date'] ?? ''), (string) ($a['sort_date'] ?? ''));
  });

  $total = count($filtered);
  $totalPages = max(1, (int) ceil($total / $perPage));
  if ($page > $totalPages) {
    $page = $totalPages;
  }
  $offset = ($page - 1) * $perPage;
  $pageItems = array_slice($filtered, $offset, $perPage);

  return [
    'items' => $pageItems,
    'meta' => [
      'total' => $total,
      'page' => $page,
      'per_page' => $perPage,
      'total_pages' => $totalPages,
      'offset' => $offset,
    ],
    'filters' => [
      'q' => $searchRaw,
      'section' => $section,
      'library_kind_key' => $libraryKind,
      'topic_lv1_key' => $topicLv1,
      'topic_lv2_key' => $topicLv2,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'sort' => $sort,
    ],
    'facets' => is_array($cache['facets']) ? $cache['facets'] : default_articles_facets(),
  ];
}

/**
 * Find one article item by id.
 *
 * @return array<string,mixed>|null
 */
function find_article_index_item(string $id): ?array
{
  $id = trim($id);
  if ($id === '') {
    return null;
  }
  $cache = read_articles_index_cache();
  foreach ($cache['items'] as $item) {
    if (!is_array($item)) {
      continue;
    }
    if ((string) ($item['id'] ?? '') === $id) {
      return $item;
    }
  }
  return null;
}
