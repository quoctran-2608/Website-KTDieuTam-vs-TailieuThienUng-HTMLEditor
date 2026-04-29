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
  $cacheVersion = (int) ($cache['meta']['version'] ?? 0);
  $hasTree = isset($cache['facets']['tree']) && is_array($cache['facets']['tree'])
    && isset($cache['facets']['tree']['sections']) && is_array($cache['facets']['tree']['sections']);
  $hasLv3 = isset($cache['facets']['topic_lv3']) && is_array($cache['facets']['topic_lv3']);
  $hasTags = isset($cache['facets']['tags']) && is_array($cache['facets']['tags']);

  if (
    !$force
    && !empty($cache['items'])
    && $cacheMTime === $sourceMTime
    && $cacheVersion >= 4
    && $hasTree
    && $hasLv3
    && $hasTags
  ) {
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
      'version' => 4,
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
  if (!isset($decoded['facets']['sections']) || !is_array($decoded['facets']['sections'])) {
    $decoded['facets']['sections'] = [];
  }
  if (!isset($decoded['facets']['library_kinds']) || !is_array($decoded['facets']['library_kinds'])) {
    $decoded['facets']['library_kinds'] = [];
  }
  if (!isset($decoded['facets']['topic_lv1']) || !is_array($decoded['facets']['topic_lv1'])) {
    $decoded['facets']['topic_lv1'] = [];
  }
  if (!isset($decoded['facets']['topic_lv2']) || !is_array($decoded['facets']['topic_lv2'])) {
    $decoded['facets']['topic_lv2'] = [];
  }
  if (!isset($decoded['facets']['topic_lv3']) || !is_array($decoded['facets']['topic_lv3'])) {
    $decoded['facets']['topic_lv3'] = [];
  }
  if (!isset($decoded['facets']['tags']) || !is_array($decoded['facets']['tags'])) {
    $decoded['facets']['tags'] = [];
  }
  if (!isset($decoded['facets']['tree']) || !is_array($decoded['facets']['tree'])) {
    $decoded['facets']['tree'] = ['sections' => []];
  }
  if (!isset($decoded['facets']['tree']['sections']) || !is_array($decoded['facets']['tree']['sections'])) {
    $decoded['facets']['tree']['sections'] = [];
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
    'topic_lv3' => [],
    'tags' => [],
    'tree' => [
      'sections' => [],
    ],
  ];
}

/**
 * Read the public taxonomy tree used by thu-vien.html / ban-tin.html.
 *
 * The admin sidebar must mirror the public hub sidebar: Thư viện stops at
 * kind → lv1 → lv2, and Bản tin stops at lv1 → lv2.
 *
 * @return array{sections:array<int,array<string,mixed>>}
 */
function read_public_taxonomy_tree(): array
{
  $path = dirname(dirname(__DIR__)) . '/data/taxonomy.json';
  if (!file_exists($path)) {
    return ['sections' => []];
  }

  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') {
    return ['sections' => []];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded) || !is_array($decoded['roots'] ?? null)) {
    return ['sections' => []];
  }

  $sections = [];
  foreach ($decoded['roots'] as $root) {
    if (!is_array($root)) {
      continue;
    }
    $sectionKey = trim((string) ($root['key'] ?? ($root['id'] ?? '')));
    if ($sectionKey === '') {
      continue;
    }

    $sectionNode = public_taxonomy_base_node($root, 'section');
    $sectionNode['children'] = [];
    $children = is_array($root['children'] ?? null) ? $root['children'] : [];

    if ($sectionKey === 'thu-vien') {
      foreach ($children as $kind) {
        if (is_array($kind)) {
          $sectionNode['children'][] = normalize_public_taxonomy_branch($kind, 'library_kind', 2);
        }
      }
    } elseif ($sectionKey === 'ban-tin') {
      foreach ($children as $lv1) {
        if (is_array($lv1)) {
          $sectionNode['children'][] = normalize_public_taxonomy_branch($lv1, 'topic_lv1', 1);
        }
      }
    }

    $sections[] = $sectionNode;
  }

  return ['sections' => $sections];
}

/**
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function public_taxonomy_base_node(array $node, string $type): array
{
  return [
    'key' => (string) ($node['key'] ?? ($node['id'] ?? '')),
    'label' => (string) ($node['label'] ?? ($node['key'] ?? ($node['id'] ?? ''))),
    'type' => $type,
    'count' => (int) ($node['count'] ?? 0),
  ];
}

/**
 * Normalize one public taxonomy branch while preserving public JSON order.
 *
 * @param array<string,mixed> $node
 * @return array<string,mixed>
 */
function normalize_public_taxonomy_branch(array $node, string $type, int $childLevels): array
{
  $out = public_taxonomy_base_node($node, $type);
  if ($childLevels <= 0) {
    return $out;
  }

  $nextTypeMap = [
    'library_kind' => 'topic_lv1',
    'topic_lv1' => 'topic_lv2',
    'topic_lv2' => 'topic_lv3',
  ];
  $nextType = $nextTypeMap[$type] ?? 'topic';
  $children = is_array($node['children'] ?? null) ? $node['children'] : [];
  $out['children'] = [];
  foreach ($children as $child) {
    if (is_array($child)) {
      $out['children'][] = normalize_public_taxonomy_branch($child, $nextType, $childLevels - 1);
    }
  }
  return $out;
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
  $topicLv3Key = trim((string) ($item['topicLv3Key'] ?? ''));
  $tags = normalize_article_tag_list($item['tags'] ?? []);

  $searchIndex = build_article_search_index([
    $id,
    $title,
    (string) ($item['href'] ?? ''),
    (string) ($item['canonical'] ?? ''),
    (string) ($item['topicLv1Label'] ?? ''),
    (string) ($item['topicLv2Label'] ?? ''),
    (string) ($item['topicLv3Label'] ?? ''),
    $topicLv3Key,
    (string) ($item['cardTopicLabel'] ?? ''),
    (string) ($item['sectionLabel'] ?? ''),
    (string) ($item['libraryKindLabel'] ?? ''),
    implode(' ', $tags),
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
    'section_href' => (string) ($item['sectionHref'] ?? ''),
    'library_kind_key' => $libraryKindKey,
    'library_kind_label' => (string) ($item['libraryKindLabel'] ?? ''),
    'topic_lv1_key' => $topicLv1Key,
    'topic_lv1_label' => (string) ($item['topicLv1Label'] ?? ''),
    'topic_lv2_key' => $topicLv2Key,
    'topic_lv2_label' => (string) ($item['topicLv2Label'] ?? ''),
    'topic_lv3_key' => $topicLv3Key,
    'topic_lv3_label' => (string) ($item['topicLv3Label'] ?? ''),
    'tags' => $tags,
    'publish_date' => $publishDate,
    'modified_date' => $modifiedDate,
    'author_name' => (string) ($item['authorName'] ?? ''),
    'card_badge_label' => (string) ($item['cardBadgeLabel'] ?? ''),
    'image' => (string) ($item['image'] ?? ''),
    'search_index' => $searchIndex,
    'sort_date' => $sortDate,
  ];
}

/**
 * Build compact actor text from review status row.
 *
 * @param array<string,mixed> $row
 */
function review_status_actor_text(array $row): string
{
  $username = trim((string) ($row['edited_by']['username'] ?? ''));
  if ($username !== '') {
    return $username;
  }
  $displayName = trim((string) ($row['edited_by']['display_name'] ?? ''));
  if ($displayName !== '') {
    return $displayName;
  }
  return '';
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
 * Normalize tag list for admin search/filter display.
 *
 * @param mixed $rawTags
 * @return array<int,string>
 */
function normalize_article_tag_list($rawTags): array
{
  if (!is_array($rawTags)) {
    return [];
  }

  $tags = [];
  $seen = [];
  foreach ($rawTags as $rawTag) {
    $tag = trim((string) $rawTag);
    if ($tag === '') {
      continue;
    }
    $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $tags[] = $tag;
  }
  return $tags;
}

/**
 * Normalize tree nodes: value array + label sort + recursive children normalize.
 *
 * @param array<int|string,array<string,mixed>> $nodes
 * @return array<int,array<string,mixed>>
 */
function normalize_tree_nodes(array $nodes): array
{
  $list = array_values($nodes);
  usort($list, static function (array $a, array $b): int {
    $countCmp = ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
    if ($countCmp !== 0) {
      return $countCmp;
    }
    return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
  });

  foreach ($list as &$node) {
    if (isset($node['children']) && is_array($node['children'])) {
      /** @var array<int|string,array<string,mixed>> $children */
      $children = $node['children'];
      $node['children'] = normalize_tree_nodes($children);
    }
  }
  return $list;
}

/**
 * Build facets + navigation tree for intuitive admin browsing.
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
  $topicLv3 = [];
  $tags = [];
  $treeSections = [];

  foreach ($items as $item) {
    $sectionKey = (string) ($item['section'] ?? '');
    $sectionLabel = (string) ($item['section_label'] ?? $sectionKey);
    $kindKey = (string) ($item['library_kind_key'] ?? '');
    $kindLabel = (string) ($item['library_kind_label'] ?? $kindKey);
    $lv1Key = (string) ($item['topic_lv1_key'] ?? '');
    $lv1Label = (string) ($item['topic_lv1_label'] ?? $lv1Key);
    $lv2Key = (string) ($item['topic_lv2_key'] ?? '');
    $lv2Label = (string) ($item['topic_lv2_label'] ?? $lv2Key);
    $lv3Key = (string) ($item['topic_lv3_key'] ?? '');
    $lv3Label = (string) ($item['topic_lv3_label'] ?? $lv3Key);
    $itemTags = is_array($item['tags'] ?? null) ? $item['tags'] : [];

    if ($sectionKey !== '') {
      if (!isset($sections[$sectionKey])) {
        $sections[$sectionKey] = [
          'key' => $sectionKey,
          'label' => $sectionLabel,
          'count' => 0,
        ];
      }
      $sections[$sectionKey]['count']++;

      if (!isset($treeSections[$sectionKey])) {
        $treeSections[$sectionKey] = [
          'key' => $sectionKey,
          'label' => $sectionLabel,
          'type' => 'section',
          'count' => 0,
          'children' => [],
        ];
      }
      $treeSections[$sectionKey]['count']++;
    }

    if ($kindKey !== '') {
      if (!isset($libraryKinds[$kindKey])) {
        $libraryKinds[$kindKey] = [
          'key' => $kindKey,
          'label' => $kindLabel !== '' ? $kindLabel : $kindKey,
          'count' => 0,
        ];
      }
      $libraryKinds[$kindKey]['count']++;
    }

    if ($lv1Key !== '') {
      if (!isset($topicLv1[$lv1Key])) {
        $topicLv1[$lv1Key] = [
          'key' => $lv1Key,
          'label' => $lv1Label !== '' ? $lv1Label : $lv1Key,
          'count' => 0,
        ];
      }
      $topicLv1[$lv1Key]['count']++;
    }

    if ($lv2Key !== '') {
      if (!isset($topicLv2[$lv2Key])) {
        $topicLv2[$lv2Key] = [
          'key' => $lv2Key,
          'label' => $lv2Label !== '' ? $lv2Label : $lv2Key,
          'count' => 0,
        ];
      }
      $topicLv2[$lv2Key]['count']++;
    }

    if ($lv3Key !== '') {
      if (!isset($topicLv3[$lv3Key])) {
        $topicLv3[$lv3Key] = [
          'key' => $lv3Key,
          'label' => $lv3Label !== '' ? $lv3Label : $lv3Key,
          'count' => 0,
        ];
      }
      $topicLv3[$lv3Key]['count']++;
    }

    foreach ($itemTags as $tag) {
      $tag = trim((string) $tag);
      if ($tag === '') {
        continue;
      }
      if (!isset($tags[$tag])) {
        $tags[$tag] = [
          'key' => $tag,
          'label' => $tag,
          'count' => 0,
        ];
      }
      $tags[$tag]['count']++;
    }

    if ($sectionKey === 'thu-vien') {
      $kindNodeKey = $kindKey !== '' ? $kindKey : '__khac';
      $kindNodeLabel = $kindLabel !== '' ? $kindLabel : 'Khác';
      if (!isset($treeSections[$sectionKey]['children'][$kindNodeKey])) {
        $treeSections[$sectionKey]['children'][$kindNodeKey] = [
          'key' => $kindNodeKey,
          'label' => $kindNodeLabel,
          'type' => 'library_kind',
          'count' => 0,
          'children' => [],
        ];
      }
      $treeSections[$sectionKey]['children'][$kindNodeKey]['count']++;

      $lv1NodeKey = $lv1Key !== '' ? $lv1Key : '__khac';
      $lv1NodeLabel = $lv1Label !== '' ? $lv1Label : 'Khác';
      if (!isset($treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey])) {
        $treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey] = [
          'key' => $lv1NodeKey,
          'label' => $lv1NodeLabel,
          'type' => 'topic_lv1',
          'count' => 0,
          'children' => [],
        ];
      }
      $treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey]['count']++;

      if ($lv2Key !== '' || $lv2Label !== '') {
        if (!isset($treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey]['children'][$lv2Key])) {
          $treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey]['children'][$lv2Key] = [
            'key' => $lv2Key,
            'label' => $lv2Label !== '' ? $lv2Label : $lv2Key,
            'type' => 'topic_lv2',
            'count' => 0,
          ];
        }
        $treeSections[$sectionKey]['children'][$kindNodeKey]['children'][$lv1NodeKey]['children'][$lv2Key]['count']++;
      }
    } elseif ($sectionKey === 'ban-tin') {
      $lv1NodeKey = $lv1Key !== '' ? $lv1Key : '__khac';
      $lv1NodeLabel = $lv1Label !== '' ? $lv1Label : 'Khác';
      if (!isset($treeSections[$sectionKey]['children'][$lv1NodeKey])) {
        $treeSections[$sectionKey]['children'][$lv1NodeKey] = [
          'key' => $lv1NodeKey,
          'label' => $lv1NodeLabel,
          'type' => 'topic_lv1',
          'count' => 0,
          'children' => [],
        ];
      }
      $treeSections[$sectionKey]['children'][$lv1NodeKey]['count']++;

      if ($lv2Key !== '' || $lv2Label !== '') {
        if (!isset($treeSections[$sectionKey]['children'][$lv1NodeKey]['children'][$lv2Key])) {
          $treeSections[$sectionKey]['children'][$lv1NodeKey]['children'][$lv2Key] = [
            'key' => $lv2Key,
            'label' => $lv2Label !== '' ? $lv2Label : $lv2Key,
            'type' => 'topic_lv2',
            'count' => 0,
          ];
        }
        $treeSections[$sectionKey]['children'][$lv1NodeKey]['children'][$lv2Key]['count']++;
      }
    }
  }

  $sortFacet = static function (array &$rows): void {
    usort($rows, static function (array $a, array $b): int {
      return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });
  };

  $sections = array_values($sections);
  $libraryKinds = array_values($libraryKinds);
  $topicLv1 = array_values($topicLv1);
  $topicLv2 = array_values($topicLv2);
  $topicLv3 = array_values($topicLv3);
  $tags = array_values($tags);

  $sortFacet($sections);
  $sortFacet($libraryKinds);
  $sortFacet($topicLv1);
  $sortFacet($topicLv2);
  $sortFacet($topicLv3);
  $sortFacet($tags);

  return [
    'sections' => $sections,
    'library_kinds' => $libraryKinds,
    'topic_lv1' => $topicLv1,
    'topic_lv2' => $topicLv2,
    'topic_lv3' => $topicLv3,
    'tags' => $tags,
    'tree' => [
      'sections' => normalize_tree_nodes($treeSections),
    ],
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
  $reviewRows = review_status_map();
  $editedCount = 0;
  foreach ($reviewRows as $row) {
    if (is_array($row) && (string) ($row['status'] ?? '') === 'edited') {
      $editedCount++;
    }
  }

  $searchRaw = trim((string) ($filters['q'] ?? ''));
  $search = strtolower($searchRaw);
  $search = trim((string) preg_replace('/\s+/', ' ', $search));
  $section = trim((string) ($filters['section'] ?? ''));
  $libraryKind = trim((string) ($filters['library_kind_key'] ?? ''));
  $topicLv1 = trim((string) ($filters['topic_lv1_key'] ?? ''));
  $topicLv2 = trim((string) ($filters['topic_lv2_key'] ?? ''));
  $topicLv3 = trim((string) ($filters['topic_lv3_key'] ?? ''));
  $tag = trim((string) ($filters['tag'] ?? ''));
  $treeNodeType = trim((string) ($filters['tree_node_type'] ?? ''));
  $treeNodeKey = trim((string) ($filters['tree_node_key'] ?? ''));
  $dateFrom = normalize_date_ymd((string) ($filters['date_from'] ?? ''));
  $dateTo = normalize_date_ymd((string) ($filters['date_to'] ?? ''));
  $reviewStatus = trim((string) ($filters['review_status'] ?? ''));
  if (!in_array($reviewStatus, ['', 'unreviewed', 'draft_saved', 'edited'], true)) {
    $reviewStatus = '';
  }
  $sort = (string) ($filters['sort'] ?? 'latest');
  $page = max(1, (int) ($filters['page'] ?? 1));
  $perPage = (int) ($filters['per_page'] ?? 20);
  if ($perPage < 10) {
    $perPage = 10;
  }
  if ($perPage > 100) {
    $perPage = 100;
  }

  $filtered = array_values(array_filter($items, static function ($item) use ($search, $section, $libraryKind, $topicLv1, $topicLv2, $topicLv3, $tag, $treeNodeType, $treeNodeKey, $dateFrom, $dateTo, $reviewStatus, $reviewRows): bool {
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
    if ($topicLv3 !== '' && (string) ($item['topic_lv3_key'] ?? '') !== $topicLv3) {
      return false;
    }
    if ($tag !== '') {
      $itemTags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
      if (!in_array($tag, array_map('strval', $itemTags), true)) {
        return false;
      }
    }

    if ($treeNodeType !== '' && $treeNodeKey !== '') {
      if ($treeNodeType === 'section' && (string) ($item['section'] ?? '') !== $treeNodeKey) {
        return false;
      }
      if ($treeNodeType === 'library_kind' && (string) ($item['library_kind_key'] ?? '') !== $treeNodeKey) {
        return false;
      }
      if ($treeNodeType === 'topic_lv1' && (string) ($item['topic_lv1_key'] ?? '') !== $treeNodeKey) {
        return false;
      }
      if ($treeNodeType === 'topic_lv2' && (string) ($item['topic_lv2_key'] ?? '') !== $treeNodeKey) {
        return false;
      }
      if ($treeNodeType === 'topic_lv3' && (string) ($item['topic_lv3_key'] ?? '') !== $treeNodeKey) {
        return false;
      }
    }

    $publishDate = (string) ($item['publish_date'] ?? '');
    if ($dateFrom !== '' && ($publishDate === '' || $publishDate < $dateFrom)) {
      return false;
    }
    if ($dateTo !== '' && ($publishDate === '' || $publishDate > $dateTo)) {
      return false;
    }

    if ($reviewStatus !== '') {
      $articleId = trim((string) ($item['id'] ?? ''));
      $row = $articleId !== '' ? ($reviewRows[$articleId] ?? null) : null;
      $rowStatus = is_array($row) ? normalize_article_review_status((string) ($row['status'] ?? 'unreviewed')) : 'unreviewed';
      if ($reviewStatus === 'edited' && $rowStatus !== 'edited') {
        return false;
      }
      if ($reviewStatus === 'draft_saved' && $rowStatus !== 'draft_saved') {
        return false;
      }
      if ($reviewStatus === 'unreviewed' && $rowStatus !== 'unreviewed') {
        return false;
      }
    }

    return true;
  }));

  foreach ($filtered as &$item) {
    if (!is_array($item)) {
      continue;
    }
    $articleId = trim((string) ($item['id'] ?? ''));
    $row = $articleId !== '' ? ($reviewRows[$articleId] ?? null) : null;
    $rowStatus = is_array($row) ? normalize_article_review_status((string) ($row['status'] ?? 'unreviewed')) : 'unreviewed';
    $editedAtRaw = $rowStatus !== 'unreviewed' ? trim((string) ($row['edited_at'] ?? '')) : '';
    $item['review_status'] = $rowStatus;
    if ($rowStatus === 'edited') {
      $item['review_status_label'] = 'Đã sửa';
      $item['review_status_color'] = 'success';
    } elseif ($rowStatus === 'draft_saved') {
      $item['review_status_label'] = 'Lưu nháp';
      $item['review_status_color'] = 'warning';
    } else {
      $item['review_status_label'] = 'Chưa sửa';
      $item['review_status_color'] = 'danger';
    }
    $item['review_edited_at'] = $editedAtRaw;
    $item['review_edited_at_label'] = format_admin_datetime($editedAtRaw);
    $item['review_edited_by'] = is_array($row) ? review_status_actor_text($row) : '';
    // Stable latest-order key: prefer review edited_at (includes publish/save actions),
    // fallback to modified/publish sort_date, then deterministic id tie-break.
    $latestSortAt = $editedAtRaw;
    if ($latestSortAt === '') {
      $latestSortAt = (string) ($item['sort_date'] ?? '');
    }
    if ($latestSortAt === '') {
      $latestSortAt = '1900-01-01';
    }
    $latestSortAt = str_replace(' ', 'T', $latestSortAt);
    if (strlen($latestSortAt) === 10) {
      $latestSortAt .= 'T00:00:00';
    }
    $item['latest_sort_at'] = $latestSortAt;
  }
  unset($item);

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
    $dateCmp = strcmp((string) ($b['latest_sort_at'] ?? ''), (string) ($a['latest_sort_at'] ?? ''));
    if ($dateCmp !== 0) {
      return $dateCmp;
    }
    return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
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
      'total_edited' => $editedCount,
      'total_unreviewed' => max(0, count($items) - $editedCount),
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
      'topic_lv3_key' => $topicLv3,
      'tag' => $tag,
      'tree_node_type' => $treeNodeType,
      'tree_node_key' => $treeNodeKey,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'review_status' => $reviewStatus,
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
