<?php
declare(strict_types=1);

/**
 * Editorial public derived-data rebuild and publication-ready markers.
 *
 * Python remains the preferred builder. The native path mirrors the current
 * fast rebuild contract without depending on legacy /admin at runtime.
 */

const EDITORIAL_PUBLIC_READY_SCHEMA = 1;

function editorial_public_rebuild_root(string $relative = ''): string
{
    $root = dirname(EDITORIAL_BASE_PATH);
    return $relative === '' ? $root : $root . '/' . ltrim($relative, '/');
}

function editorial_public_rebuild_atomic_write(string $path, string $content): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    $temporary = $directory . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(6));
    $written = @file_put_contents($temporary, $content, LOCK_EX);
    if ($written !== strlen($content)) {
        @unlink($temporary);
        return false;
    }
    $verified = @file_get_contents($temporary);
    if ($verified === false || !hash_equals(hash('sha256', $content), hash('sha256', $verified))) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function editorial_public_rebuild_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false && editorial_public_rebuild_atomic_write($path, $json . PHP_EOL);
}

function editorial_public_rebuild_write_js_store(string $path, string $global, string $key, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $keyJson = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($keyJson === false) {
        return false;
    }
    return editorial_public_rebuild_atomic_write(
        $path,
        'window.' . $global . '=window.' . $global . '||{};window.'
            . $global . '[' . $keyJson . ']=' . $json . ";\n"
    );
}

function editorial_public_rebuild_read_json(string $path)
{
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    return json_decode($raw, true);
}

function editorial_public_rebuild_text($value): string
{
    return trim((string) ($value ?? ''));
}

function editorial_public_rebuild_list($value): array
{
    return is_array($value) ? array_values($value) : [];
}

function editorial_public_rebuild_fold(string $value): string
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
 * @return array<int,array<string,mixed>>
 */
function editorial_public_rebuild_read_articles(): array
{
    $decoded = editorial_public_rebuild_read_json(EDITORIAL_ARTICLES_SOURCE);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function editorial_public_rebuild_article_map(array $item): array
{
    $href = editorial_public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
    $section = editorial_public_rebuild_text($item['section'] ?? '');
    return [
        'id' => $href,
        'section' => $section,
        'sectionLabel' => editorial_public_rebuild_text($item['sectionLabel'] ?? ($section === 'ban-tin' ? 'Bản tin' : 'Thư viện')),
        'sectionHref' => editorial_public_rebuild_text($item['sectionHref'] ?? ($section . '.html')),
        'href' => $href,
        'canonical' => editorial_public_rebuild_text($item['canonical'] ?? ('https://ketoandieutam.vn/' . $href)),
        'title' => editorial_public_rebuild_text($item['title'] ?? ''),
        'excerpt' => editorial_public_rebuild_text($item['excerpt'] ?? ''),
        'topicLv1Key' => editorial_public_rebuild_text($item['topicLv1Key'] ?? ''),
        'topicLv1Label' => editorial_public_rebuild_text($item['topicLv1Label'] ?? ''),
        'topicLv2Key' => editorial_public_rebuild_text($item['topicLv2Key'] ?? ''),
        'topicLv2Label' => editorial_public_rebuild_text($item['topicLv2Label'] ?? ''),
        'topicLv3Key' => editorial_public_rebuild_text($item['topicLv3Key'] ?? ''),
        'topicLv3Label' => editorial_public_rebuild_text($item['topicLv3Label'] ?? ''),
        'tags' => editorial_public_rebuild_list($item['tags'] ?? []),
        'primarySection' => $item['primarySection'] ?? $section,
        'secondarySections' => editorial_public_rebuild_list($item['secondarySections'] ?? []),
        'classificationReasons' => is_array($item['classificationReasons'] ?? null) ? $item['classificationReasons'] : [],
        'legacyPrimarySection' => $item['legacyPrimarySection'] ?? null,
        'legacySecondarySections' => editorial_public_rebuild_list($item['legacySecondarySections'] ?? []),
        'libraryKindKey' => editorial_public_rebuild_text($item['libraryKindKey'] ?? ''),
        'libraryKindLabel' => editorial_public_rebuild_text($item['libraryKindLabel'] ?? ''),
        'toolLv3Key' => editorial_public_rebuild_text($item['toolLv3Key'] ?? ''),
        'toolLv3Label' => editorial_public_rebuild_text($item['toolLv3Label'] ?? ''),
        'cardBadgeLabel' => editorial_public_rebuild_text($item['cardBadgeLabel'] ?? ''),
        'cardTopicLabel' => editorial_public_rebuild_text($item['cardTopicLabel'] ?? ''),
        // data/articles.json is the authority. Never infer from body images.
        'image' => editorial_public_rebuild_text($item['image'] ?? ''),
        'publishDate' => editorial_public_rebuild_text($item['publishDate'] ?? ''),
        'modifiedDate' => $item['modifiedDate'] ?? null,
        'authorName' => editorial_public_rebuild_text($item['authorName'] ?? 'Kế Toán Diệu Tâm'),
        'authorType' => editorial_public_rebuild_text($item['authorType'] ?? 'Organization'),
    ];
}

function editorial_public_rebuild_hub_item(array $item): array
{
    $href = editorial_public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
    return [
        'file' => basename($href, '.html') . '.htm',
        'title' => editorial_public_rebuild_text($item['title'] ?? ''),
        'excerpt' => editorial_public_rebuild_text($item['excerpt'] ?? ''),
        'topic_lv1_key' => editorial_public_rebuild_text($item['topicLv1Key'] ?? ''),
        'topic_lv1_label' => editorial_public_rebuild_text($item['topicLv1Label'] ?? ''),
        'topic_lv2_key' => editorial_public_rebuild_text($item['topicLv2Key'] ?? ''),
        'topic_lv2_label' => editorial_public_rebuild_text($item['topicLv2Label'] ?? ''),
        'topic_lv3_key' => editorial_public_rebuild_text($item['topicLv3Key'] ?? ''),
        'topic_lv3_label' => editorial_public_rebuild_text($item['topicLv3Label'] ?? ''),
        'tags' => editorial_public_rebuild_list($item['tags'] ?? []),
        'badge_label' => editorial_public_rebuild_text($item['cardBadgeLabel'] ?? ''),
        'topic_label' => editorial_public_rebuild_text($item['cardTopicLabel'] ?? ''),
        'library_kind_key' => editorial_public_rebuild_text($item['libraryKindKey'] ?? ''),
        'library_kind_label' => editorial_public_rebuild_text($item['libraryKindLabel'] ?? ''),
        'tool_lv3_key' => editorial_public_rebuild_text($item['toolLv3Key'] ?? ''),
        'tool_lv3_label' => editorial_public_rebuild_text($item['toolLv3Label'] ?? ''),
        'publish_date' => editorial_public_rebuild_text($item['publishDate'] ?? ''),
        'image' => editorial_public_rebuild_text($item['image'] ?? ''),
        'href' => $href,
    ];
}

function editorial_public_rebuild_feed(array $items): array
{
    usort($items, static function (array $left, array $right): int {
        $date = strcmp(
            editorial_public_rebuild_text($right['publishDate'] ?? ''),
            editorial_public_rebuild_text($left['publishDate'] ?? '')
        );
        return $date !== 0 ? $date : strcmp(
            editorial_public_rebuild_text($left['title'] ?? ''),
            editorial_public_rebuild_text($right['title'] ?? '')
        );
    });
    return array_map(static function (array $item): array {
        $href = editorial_public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
        return [
            'title' => editorial_public_rebuild_text($item['title'] ?? ''),
            'href' => $href,
            'canonical' => editorial_public_rebuild_text($item['canonical'] ?? ('https://ketoandieutam.vn/' . $href)),
            'publishDate' => editorial_public_rebuild_text($item['publishDate'] ?? ''),
            'modifiedDate' => $item['modifiedDate'] ?? null,
            'image' => editorial_public_rebuild_text($item['image'] ?? ''),
            'badgeLabel' => editorial_public_rebuild_text($item['cardBadgeLabel'] ?? ''),
            'topicLabel' => editorial_public_rebuild_text($item['cardTopicLabel'] ?? ''),
            'libraryKindKey' => editorial_public_rebuild_text($item['libraryKindKey'] ?? ''),
            'libraryKindLabel' => editorial_public_rebuild_text($item['libraryKindLabel'] ?? ''),
            'toolLv3Key' => editorial_public_rebuild_text($item['toolLv3Key'] ?? ''),
            'toolLv3Label' => editorial_public_rebuild_text($item['toolLv3Label'] ?? ''),
            'tags' => editorial_public_rebuild_list($item['tags'] ?? []),
        ];
    }, array_slice($items, 0, 12));
}

function editorial_public_rebuild_existing_index(): array
{
    $raw = @file_get_contents(editorial_public_rebuild_root('content-index.js'));
    if ($raw === false) {
        return [];
    }
    $raw = trim($raw);
    $prefix = 'window.KetoanDieuTamContentIndex=';
    if (str_starts_with($raw, $prefix)) {
        $raw = substr($raw, strlen($prefix));
    }
    $decoded = json_decode(rtrim($raw, ";\r\n\t "), true);
    return is_array($decoded) ? $decoded : [];
}

function editorial_public_rebuild_pick_latest(array $items, int $limit, string $exclude = ''): array
{
    usort($items, static function (array $left, array $right): int {
        return strcmp(
            editorial_public_rebuild_text($right['publishDate'] ?? ''),
            editorial_public_rebuild_text($left['publishDate'] ?? '')
        );
    });
    $ids = [];
    foreach ($items as $item) {
        $id = editorial_public_rebuild_text($item['href'] ?? ($item['id'] ?? ''));
        if ($id !== '' && $id !== $exclude) {
            $ids[] = $id;
        }
        if (count($ids) >= $limit) {
            break;
        }
    }
    return $ids;
}

/**
 * @return array{index:array<string,mixed>,grouped:array<string,array<int,array<string,mixed>>>}
 */
function editorial_public_rebuild_build_index(array $articles, string $targetArticleId): array
{
    $grouped = ['thu-vien' => [], 'ban-tin' => []];
    foreach ($articles as $item) {
        $section = editorial_public_rebuild_text($item['section'] ?? '');
        if (isset($grouped[$section])) {
            $grouped[$section][] = $item;
        }
    }
    foreach ($grouped as &$items) {
        usort($items, static fn(array $a, array $b): int => strcmp(
            editorial_public_rebuild_fold(editorial_public_rebuild_text($a['title'] ?? '')),
            editorial_public_rebuild_fold(editorial_public_rebuild_text($b['title'] ?? ''))
        ));
    }
    unset($items);

    $existing = editorial_public_rebuild_existing_index();
    $existingViews = is_array($existing['articleViews'] ?? null) ? $existing['articleViews'] : [];
    $latestNews = editorial_public_rebuild_pick_latest($grouped['ban-tin'], 3);
    $latestLibrary = editorial_public_rebuild_pick_latest($grouped['thu-vien'], 3);
    $articleMap = [];
    $views = [];

    foreach ($grouped as $section => $items) {
        foreach ($items as $index => $item) {
            $article = editorial_public_rebuild_article_map($item);
            $id = $article['id'];
            if ($id === '') {
                continue;
            }
            $articleMap[$id] = $article;
            if ($id !== $targetArticleId && isset($existingViews[$id]) && is_array($existingViews[$id])) {
                $views[$id] = $existingViews[$id];
                continue;
            }
            $related = [];
            foreach ($items as $candidate) {
                $candidateId = editorial_public_rebuild_text($candidate['href'] ?? ($candidate['id'] ?? ''));
                if ($candidateId === $id
                    || editorial_public_rebuild_text($candidate['libraryKindKey'] ?? '') !== $article['libraryKindKey']
                    || editorial_public_rebuild_text($candidate['topicLv1Key'] ?? '') !== $article['topicLv1Key']
                    || editorial_public_rebuild_text($candidate['topicLv2Key'] ?? '') !== $article['topicLv2Key']) {
                    continue;
                }
                $related[] = $candidateId;
                if (count($related) >= 3) {
                    break;
                }
            }
            $views[$id] = [
                'currentIndex' => $index + 1,
                'totalCount' => count($items),
                'prev' => $index > 0 ? editorial_public_rebuild_text($items[$index - 1]['href'] ?? '') : null,
                'next' => isset($items[$index + 1]) ? editorial_public_rebuild_text($items[$index + 1]['href'] ?? '') : null,
                'newsLatest' => array_values(array_filter($latestNews, static fn(string $value): bool => $value !== $id)),
                'libraryLatest' => array_values(array_filter($latestLibrary, static fn(string $value): bool => $value !== $id)),
                'related' => $related,
                'latestOther' => $section === 'ban-tin' ? $latestLibrary : $latestNews,
                'fastView' => true,
            ];
        }
    }
    return [
        'index' => [
            'generatedAt' => gmdate('c'),
            'sections' => [
                'thu-vien' => ['label' => 'Thư viện', 'href' => 'thu-vien.html'],
                'ban-tin' => ['label' => 'Bản tin', 'href' => 'ban-tin.html'],
            ],
            'articles' => $articleMap,
            'articleViews' => $views,
        ],
        'grouped' => $grouped,
    ];
}

function editorial_public_rebuild_expand_article(array $index, ?string $articleId): ?array
{
    if ($articleId === null || !isset($index['articles'][$articleId]) || !is_array($index['articles'][$articleId])) {
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
        'tags' => editorial_public_rebuild_list($article['tags'] ?? []),
        'image' => editorial_public_rebuild_text($article['image'] ?? ''),
        'libraryKindLabel' => editorial_public_rebuild_text($article['libraryKindLabel'] ?? ''),
        'publishDate' => $article['publishDate'] ?? null,
        'modifiedDate' => $article['modifiedDate'] ?? null,
    ];
}

function editorial_public_rebuild_expand_group(array $index, array $ids): array
{
    $expanded = [];
    foreach ($ids as $id) {
        $article = editorial_public_rebuild_expand_article($index, is_string($id) ? $id : null);
        if ($article !== null) {
            $expanded[] = $article;
        }
    }
    return $expanded;
}

function editorial_public_rebuild_write_target_view(array $index, string $articleId): bool
{
    $view = $index['articleViews'][$articleId] ?? null;
    if (!is_array($view)) {
        return false;
    }
    $expanded = [
        'currentIndex' => (int) ($view['currentIndex'] ?? 0),
        'totalCount' => (int) ($view['totalCount'] ?? 0),
        'prev' => editorial_public_rebuild_expand_article($index, is_string($view['prev'] ?? null) ? $view['prev'] : null),
        'next' => editorial_public_rebuild_expand_article($index, is_string($view['next'] ?? null) ? $view['next'] : null),
        'newsLatest' => editorial_public_rebuild_expand_group($index, editorial_public_rebuild_list($view['newsLatest'] ?? [])),
        'libraryLatest' => editorial_public_rebuild_expand_group($index, editorial_public_rebuild_list($view['libraryLatest'] ?? [])),
        'related' => editorial_public_rebuild_expand_group($index, editorial_public_rebuild_list($view['related'] ?? [])),
        'latestOther' => editorial_public_rebuild_expand_group($index, editorial_public_rebuild_list($view['latestOther'] ?? [])),
    ];
    $path = editorial_public_rebuild_root('data/article-views/' . $articleId . '.json');
    return editorial_public_rebuild_write_json($path, $expanded)
        && editorial_public_rebuild_write_js_store(
            substr($path, 0, -5) . '.js',
            'KetoanDieuTamArticleViewStore',
            $articleId,
            $expanded
        );
}

function editorial_public_rebuild_write_hubs(array $grouped): bool
{
    foreach (['thu-vien', 'ban-tin'] as $section) {
        $items = $grouped[$section] ?? [];
        $existingPath = editorial_public_rebuild_root('data/hubs/' . $section . '.json');
        $existing = editorial_public_rebuild_read_json($existingPath);
        if (!is_array($existing)) {
            return false;
        }
        $pages = max(1, (int) ceil(count($items) / 12));
        $pageMap = [];
        for ($page = 1; $page <= $pages; $page++) {
            $pageMap[(string) $page] = $page === 1
                ? $section . '.html'
                : $section . '/trang/' . $page . '/index.html';
        }
        $hub = [
            'section' => $section,
            'sectionLabel' => $section === 'ban-tin' ? 'Bản tin' : 'Thư viện',
            'sectionHref' => $section . '.html',
            'pageMap' => $pageMap,
            'libraryKinds' => $section === 'thu-vien'
                ? editorial_public_rebuild_list($existing['libraryKinds'] ?? [])
                : [],
            'taxonomy' => editorial_public_rebuild_list($existing['taxonomy'] ?? []),
            'count' => count($items),
            'articles' => array_map('editorial_public_rebuild_hub_item', $items),
        ];
        if ($section === 'thu-vien' && is_array($existing['taxonomyByKind'] ?? null)) {
            $hub['taxonomyByKind'] = $existing['taxonomyByKind'];
        }
        if (!editorial_public_rebuild_write_json($existingPath, $hub)
            || !editorial_public_rebuild_write_js_store(
                editorial_public_rebuild_root('data/hubs/' . $section . '.js'),
                'KetoanDieuTamHubStore',
                $section,
                $hub
            )
            || !editorial_public_rebuild_write_json(
                editorial_public_rebuild_root('data/feeds/latest-' . $section . '.json'),
                editorial_public_rebuild_feed($items)
            )) {
            return false;
        }
    }
    return true;
}

function editorial_public_rebuild_taxonomy_node_key(array $node): string
{
    return (string) (array_key_exists('key', $node) ? $node['key'] : ($node['id'] ?? ''));
}

function editorial_public_rebuild_taxonomy_children(array $node): array
{
    return array_values(array_filter($node['children'] ?? [], 'is_array'));
}

function editorial_public_rebuild_taxonomy_find(array $master, array $parts): ?array
{
    $nodes = array_values(array_filter($master['roots'] ?? [], 'is_array'));
    $current = null;
    foreach ($parts as $part) {
        $current = null;
        foreach ($nodes as $node) {
            if (editorial_public_rebuild_taxonomy_node_key($node) === $part) {
                $current = $node;
                break;
            }
        }
        if ($current === null) {
            return null;
        }
        $nodes = editorial_public_rebuild_taxonomy_children($current);
    }
    return $current;
}

function editorial_public_rebuild_article_taxonomy_path(array $article, array $master): array
{
    $section = editorial_public_rebuild_text($article['section'] ?? '');
    $parts = [$section];
    if ($section === 'thu-vien') {
        $parts[] = editorial_public_rebuild_text($article['libraryKindKey'] ?? '');
        $parts[] = editorial_public_rebuild_text($article['topicLv1Key'] ?? '');
        $level2 = editorial_public_rebuild_text($article['topicLv2Key'] ?? '');
        $level1Node = editorial_public_rebuild_taxonomy_find($master, $parts);
        $hasEmptyChild = false;
        foreach (editorial_public_rebuild_taxonomy_children($level1Node ?? []) as $child) {
            if (editorial_public_rebuild_taxonomy_node_key($child) === '') {
                $hasEmptyChild = true;
                break;
            }
        }
        if ($level2 !== '' || $hasEmptyChild) {
            $parts[] = $level2;
        }
        $level3 = editorial_public_rebuild_text($article['topicLv3Key'] ?? '');
        if ($level3 !== '') {
            $parts[] = $level3;
        }
    } elseif ($section === 'ban-tin') {
        $parts[] = editorial_public_rebuild_text($article['topicLv1Key'] ?? '');
        $level2 = editorial_public_rebuild_text($article['topicLv2Key'] ?? '');
        $level1Node = editorial_public_rebuild_taxonomy_find($master, $parts);
        $hasEmptyChild = false;
        foreach (editorial_public_rebuild_taxonomy_children($level1Node ?? []) as $child) {
            if (editorial_public_rebuild_taxonomy_node_key($child) === '') {
                $hasEmptyChild = true;
                break;
            }
        }
        if ($level2 !== '' || $hasEmptyChild) {
            $parts[] = $level2;
        }
        $level3 = editorial_public_rebuild_text($article['topicLv3Key'] ?? '');
        if ($level3 !== '') {
            $parts[] = $level3;
        }
    }
    return $parts;
}

function editorial_public_rebuild_taxonomy_count(array $master, array $articles, array $parts): int
{
    $count = 0;
    foreach ($articles as $article) {
        $articleParts = editorial_public_rebuild_article_taxonomy_path($article, $master);
        if (count($articleParts) >= count($parts)
            && array_slice($articleParts, 0, count($parts)) === $parts) {
            $count++;
        }
    }
    return $count;
}

function editorial_public_rebuild_taxonomy_public_node(
    array $master,
    array $articles,
    array $node,
    array $path
): array {
    $key = editorial_public_rebuild_taxonomy_node_key($node);
    $current = array_merge($path, [$key]);
    $public = [
        'key' => $key,
        'label' => editorial_public_rebuild_text($node['label'] ?? $key),
        'count' => editorial_public_rebuild_taxonomy_count($master, $articles, $current),
    ];
    $children = [];
    foreach (editorial_public_rebuild_taxonomy_children($node) as $child) {
        if (empty($child['hidden'])) {
            $children[] = editorial_public_rebuild_taxonomy_public_node($master, $articles, $child, $current);
        }
    }
    if ($children !== []) {
        $public['children'] = $children;
    }
    return $public;
}

function editorial_public_rebuild_taxonomy_editor_node(array $node, bool $useId = false): array
{
    $key = editorial_public_rebuild_taxonomy_node_key($node);
    $editor = [
        $useId ? 'id' : 'key' => $key,
        'label' => editorial_public_rebuild_text($node['label'] ?? $key),
    ];
    $children = array_map(
        static fn(array $child): array => editorial_public_rebuild_taxonomy_editor_node($child),
        editorial_public_rebuild_taxonomy_children($node)
    );
    if ($children !== []) {
        $editor['children'] = $children;
    }
    return $editor;
}

function editorial_public_rebuild_taxonomy_merge_groups(array $groups): array
{
    $merged = [];
    $mergeNode = static function (array &$targets, array $node) use (&$mergeNode): void {
        $key = editorial_public_rebuild_taxonomy_node_key($node);
        if (!isset($targets[$key])) {
            $targets[$key] = [
                'key' => $key,
                'label' => editorial_public_rebuild_text($node['label'] ?? $key),
                'count' => 0,
                '_children' => [],
            ];
        }
        $targets[$key]['count'] += (int) ($node['count'] ?? 0);
        foreach (editorial_public_rebuild_taxonomy_children($node) as $child) {
            $mergeNode($targets[$key]['_children'], $child);
        }
    };
    foreach ($groups as $group) {
        foreach ($group as $node) {
            if (is_array($node)) {
                $mergeNode($merged, $node);
            }
        }
    }
    $finalize = static function (array $nodes) use (&$finalize): array {
        $result = [];
        foreach ($nodes as $node) {
            $item = [
                'key' => $node['key'],
                'label' => $node['label'],
                'count' => (int) $node['count'],
            ];
            $children = $finalize($node['_children']);
            if ($children !== []) {
                $item['children'] = $children;
            }
            $result[] = $item;
        }
        usort($result, static function (array $left, array $right): int {
            $count = ((int) $right['count']) <=> ((int) $left['count']);
            return $count !== 0 ? $count : strcmp((string) $left['label'], (string) $right['label']);
        });
        return $result;
    };
    return $finalize($merged);
}

function editorial_public_rebuild_refresh_taxonomy_artifacts(array $articles): bool
{
    $master = editorial_public_rebuild_read_json(editorial_public_rebuild_root('data/taxonomy-master.json'));
    if (!is_array($master) || (string) ($master['schema'] ?? '') !== 'taxonomy-master.v1') {
        return false;
    }
    $generatedAt = gmdate('c');
    $roots = [];
    foreach (array_values(array_filter($master['roots'] ?? [], 'is_array')) as $root) {
        if (empty($root['hidden'])) {
            $roots[] = editorial_public_rebuild_taxonomy_public_node($master, $articles, $root, []);
        }
    }
    $public = [
        'generatedAt' => $generatedAt,
        'roots' => $roots,
        'toolVariants' => is_array($master['toolVariants'] ?? null) ? $master['toolVariants'] : [],
    ];
    $editorRoots = [];
    foreach ($roots as $root) {
        $editorRoot = [
            'id' => editorial_public_rebuild_taxonomy_node_key($root),
            'label' => editorial_public_rebuild_text($root['label'] ?? ''),
        ];
        $children = array_map(
            static fn(array $child): array => editorial_public_rebuild_taxonomy_editor_node($child, true),
            editorial_public_rebuild_taxonomy_children($root)
        );
        if ($children !== []) {
            $editorRoot['children'] = $children;
        }
        $editorRoots[] = $editorRoot;
    }
    $editor = [
        'generatedAt' => $generatedAt,
        'roots' => $editorRoots,
        'variants' => ['cong-cu' => $public['toolVariants']],
        'fieldMap' => is_array($master['fieldMap'] ?? null) ? $master['fieldMap'] : [],
    ];
    if (!editorial_public_rebuild_write_json(editorial_public_rebuild_root('data/taxonomy.json'), $public)
        || !editorial_public_rebuild_write_json(editorial_public_rebuild_root('data/editor-taxonomy.json'), $editor)) {
        return false;
    }

    $thuVienRoot = null;
    foreach ($roots as $root) {
        if (editorial_public_rebuild_taxonomy_node_key($root) === 'thu-vien') {
            $thuVienRoot = $root;
            break;
        }
    }
    $taxonomyByKind = [];
    $libraryKinds = [];
    $masterThuVien = editorial_public_rebuild_taxonomy_find($master, ['thu-vien']);
    foreach (editorial_public_rebuild_taxonomy_children($thuVienRoot ?? []) as $kind) {
        $key = editorial_public_rebuild_taxonomy_node_key($kind);
        $taxonomyByKind[$key] = editorial_public_rebuild_taxonomy_children($kind);
        $masterKind = editorial_public_rebuild_taxonomy_find($master, ['thu-vien', $key]) ?? [];
        $libraryKinds[] = [
            'key' => $key,
            'label' => editorial_public_rebuild_text($kind['label'] ?? $key),
            'count' => (int) ($kind['count'] ?? 0),
            'href' => 'thu-vien.html?kind=' . $key,
            'icon' => editorial_public_rebuild_text($masterKind['icon'] ?? 'fa-layer-group'),
            'description' => editorial_public_rebuild_text($masterKind['description'] ?? ''),
        ];
    }
    $thuVienHubPath = editorial_public_rebuild_root('data/hubs/thu-vien.json');
    $thuVienHub = editorial_public_rebuild_read_json($thuVienHubPath);
    if (!is_array($thuVienHub)) {
        return false;
    }
    $thuVienHub['libraryKinds'] = $libraryKinds;
    $thuVienHub['taxonomyByKind'] = $taxonomyByKind;
    $thuVienHub['taxonomy'] = editorial_public_rebuild_taxonomy_merge_groups(array_values($taxonomyByKind));
    if (!editorial_public_rebuild_write_json($thuVienHubPath, $thuVienHub)
        || !editorial_public_rebuild_write_js_store(
            editorial_public_rebuild_root('data/hubs/thu-vien.js'),
            'KetoanDieuTamHubStore',
            'thu-vien',
            $thuVienHub
        )) {
        return false;
    }
    $banTinRoot = null;
    foreach ($roots as $root) {
        if (editorial_public_rebuild_taxonomy_node_key($root) === 'ban-tin') {
            $banTinRoot = $root;
            break;
        }
    }
    $banTinHubPath = editorial_public_rebuild_root('data/hubs/ban-tin.json');
    $banTinHub = editorial_public_rebuild_read_json($banTinHubPath);
    if (!is_array($banTinHub)) {
        return false;
    }
    $banTinHub['taxonomy'] = editorial_public_rebuild_taxonomy_children($banTinRoot ?? []);
    if (!editorial_public_rebuild_write_json($banTinHubPath, $banTinHub)
        || !editorial_public_rebuild_write_js_store(
            editorial_public_rebuild_root('data/hubs/ban-tin.js'),
            'KetoanDieuTamHubStore',
            'ban-tin',
            $banTinHub
        )) {
        return false;
    }
    $menuPath = editorial_public_rebuild_root('data/menu-config.json');
    $menu = editorial_public_rebuild_read_json($menuPath);
    if (!is_array($menu)) {
        return false;
    }
    $menu['generatedAt'] = $generatedAt;
    foreach ($menu['items'] as &$item) {
        if (is_array($item) && ($item['key'] ?? '') === 'thu-vien') {
            $item['children'] = array_map(static fn(array $kind): array => [
                'key' => 'thu-vien-' . $kind['key'],
                'label' => $kind['label'],
                'href' => $kind['href'],
                'category' => $kind['key'],
            ], $libraryKinds);
        }
    }
    unset($item);
    return editorial_public_rebuild_write_json($menuPath, $menu);
}

function editorial_public_rebuild_write_sitemap(array $index, array $grouped): bool
{
    $today = gmdate('Y-m-d');
    $urls = ['  <url><loc>https://ketoandieutam.vn/index.html</loc></url>'];
    foreach (['thu-vien', 'ban-tin'] as $section) {
        $pages = max(1, (int) ceil(count($grouped[$section] ?? []) / 12));
        for ($page = 1; $page <= $pages; $page++) {
            $href = $page === 1 ? $section . '.html' : $section . '/trang/' . $page . '/index.html';
            $urls[] = '  <url><loc>https://ketoandieutam.vn/' . htmlspecialchars($href, ENT_XML1)
                . '</loc><lastmod>' . $today . '</lastmod></url>';
        }
    }
    foreach ($index['articles'] as $article) {
        $href = editorial_public_rebuild_text($article['href'] ?? '');
        if ($href === '') {
            continue;
        }
        $lastModified = editorial_public_rebuild_text($article['modifiedDate'] ?? ($article['publishDate'] ?? $today));
        $urls[] = '  <url><loc>https://ketoandieutam.vn/' . htmlspecialchars($href, ENT_XML1)
            . '</loc><lastmod>' . htmlspecialchars($lastModified !== '' ? $lastModified : $today, ENT_XML1)
            . '</lastmod></url>';
    }
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
        . implode("\n", $urls) . "\n</urlset>\n";
    return editorial_public_rebuild_atomic_write(editorial_public_rebuild_root('sitemap.xml'), $xml);
}

function editorial_public_rebuild_normalize_article_href(string $href): string
{
    $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $href = preg_split('/[?#]/', $href, 2)[0] ?? '';
    $path = parse_url($href, PHP_URL_PATH);
    $href = is_string($path) ? $path : $href;
    $href = ltrim($href, '/');
    while (str_starts_with($href, '../')) {
        $href = substr($href, 3);
    }
    while (str_starts_with($href, './')) {
        $href = substr($href, 2);
    }
    return $href;
}

function editorial_public_rebuild_static_image_src(string $image, string $pageHtml): string
{
    $image = trim($image);
    if ($image === ''
        || preg_match('#^(?:https?:)?//#i', $image) === 1
        || str_starts_with($image, '/')
        || preg_match('#^(?:data|blob):#i', $image) === 1) {
        return $image;
    }
    $root = '';
    if (preg_match('/<body\b[^>]*\bdata-root=(["\'])(.*?)\1/is', $pageHtml, $match) === 1) {
        $root = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $root . ltrim($image, '/');
}

function editorial_public_rebuild_replace_html_attribute(string $tag, string $name, string $value): string
{
    $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pattern = '/(\s' . preg_quote($name, '/') . '\s*=\s*)(["\']).*?\2/is';
    if (preg_match($pattern, $tag) === 1) {
        return (string) preg_replace_callback(
            $pattern,
            static fn(array $match): string => (string) $match[1] . '"' . $escaped . '"',
            $tag,
            1
        );
    }
    $closing = str_ends_with(rtrim($tag), '/>') ? ' />' : '>';
    return (string) preg_replace('/\s*\/?>$/', ' ' . $name . '="' . $escaped . '"' . $closing, $tag, 1);
}

function editorial_public_rebuild_card_targets_article(string $cardHtml, string $articleId): bool
{
    if (preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/is', $cardHtml, $matches) !== false) {
        foreach ($matches[2] as $href) {
            if (editorial_public_rebuild_normalize_article_href((string) $href) === $articleId) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @return array{ok:bool,changed:bool,matched:bool,html?:string,image_src?:string,message:string}
 */
function editorial_public_rebuild_patch_card_html(
    string $pageHtml,
    string $articleId,
    string $image,
    string $title
): array {
    $matched = false;
    $changed = false;
    $matchedImage = '';
    $result = preg_replace_callback(
        '/<article\b[^>]*\bclass=(["\'])[^"\']*\bcatalog-card\b[^"\']*\1[^>]*>.*?<\/article>/is',
        static function (array $cardMatch) use (
            $articleId,
            $image,
            $title,
            $pageHtml,
            &$matched,
            &$changed,
            &$matchedImage
        ): string {
            $card = (string) $cardMatch[0];
            if (!editorial_public_rebuild_card_targets_article($card, $articleId)) {
                return $card;
            }
            $matched = true;
            $desiredSrc = editorial_public_rebuild_static_image_src($image, $pageHtml);
            $patched = preg_replace_callback(
                '/(<a\b[^>]*\bclass=(["\'])[^"\']*\bcatalog-card__media\b[^"\']*\2[^>]*>)(.*?)(<\/a>)/is',
                static function (array $mediaMatch) use ($desiredSrc, $title, &$matchedImage): string {
                    $inside = (string) $mediaMatch[3];
                    $updated = preg_replace_callback(
                        '/<img\b[^>]*>/is',
                        static function (array $imageMatch) use ($desiredSrc, $title, &$matchedImage): string {
                            $tag = editorial_public_rebuild_replace_html_attribute(
                                (string) $imageMatch[0],
                                'src',
                                $desiredSrc
                            );
                            $tag = editorial_public_rebuild_replace_html_attribute($tag, 'alt', $title);
                            $matchedImage = $desiredSrc;
                            return $tag;
                        },
                        $inside,
                        1,
                        $imageCount
                    );
                    if ($imageCount !== 1 || !is_string($updated)) {
                        return (string) $mediaMatch[0];
                    }
                    return (string) $mediaMatch[1] . $updated . (string) $mediaMatch[4];
                },
                $card,
                1,
                $mediaCount
            );
            if ($mediaCount !== 1 || !is_string($patched)) {
                return $card;
            }
            if (!hash_equals($card, $patched)) {
                $changed = true;
            }
            return $patched;
        },
        $pageHtml
    );
    if (!is_string($result)) {
        return ['ok' => false, 'changed' => false, 'matched' => false, 'message' => 'Không thể xử lý HTML hub tĩnh.'];
    }
    if ($matched && $matchedImage === '') {
        return ['ok' => false, 'changed' => false, 'matched' => true, 'message' => 'Card bài viết không có ảnh media hợp lệ.'];
    }
    return [
        'ok' => true,
        'changed' => $changed,
        'matched' => $matched,
        'html' => $result,
        'image_src' => $matchedImage,
        'message' => '',
    ];
}

/**
 * @return array{ok:bool,message:string,pages?:array<int,string>}
 */
function editorial_public_rebuild_sync_static_hub_card(array $article): array
{
    $articleId = editorial_public_rebuild_text($article['id'] ?? ($article['href'] ?? ''));
    $section = editorial_public_rebuild_text($article['section'] ?? '');
    $image = editorial_public_rebuild_text($article['image'] ?? '');
    $title = editorial_public_rebuild_text($article['title'] ?? '');
    $hub = editorial_public_rebuild_read_json(editorial_public_rebuild_root('data/hubs/' . $section . '.json'));
    $pageMap = is_array($hub['pageMap'] ?? null) ? $hub['pageMap'] : [];
    if ($articleId === '' || !in_array($section, ['thu-vien', 'ban-tin'], true) || $pageMap === []) {
        return ['ok' => false, 'message' => 'Không xác định được trang hub tĩnh của bài viết.'];
    }
    $matchedPages = [];
    foreach ($pageMap as $relativePath) {
        $relativePath = editorial_public_rebuild_text($relativePath);
        $path = editorial_public_rebuild_root($relativePath);
        $html = @file_get_contents($path);
        if ($relativePath === '' || $html === false) {
            continue;
        }
        $patched = editorial_public_rebuild_patch_card_html($html, $articleId, $image, $title);
        if (empty($patched['ok'])) {
            return ['ok' => false, 'message' => (string) $patched['message']];
        }
        if (empty($patched['matched'])) {
            continue;
        }
        if (!empty($patched['changed'])
            && !editorial_public_rebuild_atomic_write($path, (string) $patched['html'])) {
            return ['ok' => false, 'message' => 'Không thể ghi atomic card ảnh trên hub tĩnh.'];
        }
        $matchedPages[] = $relativePath;
    }
    if ($matchedPages === []) {
        return ['ok' => false, 'message' => 'Không tìm thấy card bài viết trong các trang hub tĩnh.'];
    }
    return ['ok' => true, 'message' => '', 'pages' => $matchedPages];
}

function editorial_public_rebuild_normalize_static_asset(string $value, string $pageRelative): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_split('/[?#]/', $value, 2)[0] ?? '';
    if ($value === '' || preg_match('#^(?:https?:)?//#i', $value) === 1 || preg_match('#^(?:data|blob):#i', $value) === 1) {
        return $value;
    }
    if (str_starts_with($value, '/')) {
        return ltrim($value, '/');
    }
    $directory = dirname(str_replace('\\', '/', $pageRelative));
    $parts = $directory === '.' ? [] : explode('/', $directory);
    foreach (explode('/', str_replace('\\', '/', $value)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

/**
 * @return array{ok:bool,message:string,pages?:array<int,string>}
 */
function editorial_public_rebuild_verify_static_card(array $article): array
{
    $articleId = editorial_public_rebuild_text($article['id'] ?? ($article['href'] ?? ''));
    $section = editorial_public_rebuild_text($article['section'] ?? '');
    $expectedImage = editorial_public_rebuild_text($article['image'] ?? '');
    $hub = editorial_public_rebuild_read_json(editorial_public_rebuild_root('data/hubs/' . $section . '.json'));
    $pageMap = is_array($hub['pageMap'] ?? null) ? $hub['pageMap'] : [];
    $matchedPages = [];
    foreach ($pageMap as $relativePath) {
        $relativePath = editorial_public_rebuild_text($relativePath);
        $html = $relativePath !== '' ? @file_get_contents(editorial_public_rebuild_root($relativePath)) : false;
        if ($html === false) {
            continue;
        }
        $actualSrc = '';
        if (preg_match_all(
            '/<article\b[^>]*\bclass=(["\'])[^"\']*\bcatalog-card\b[^"\']*\1[^>]*>.*?<\/article>/is',
            $html,
            $cards
        ) === false) {
            return ['ok' => false, 'message' => 'Không thể đọc card hub tĩnh để xác minh.'];
        }
        foreach ($cards[0] as $card) {
            if (!editorial_public_rebuild_card_targets_article((string) $card, $articleId)) {
                continue;
            }
            if (preg_match(
                '/<a\b[^>]*\bclass=(["\'])[^"\']*\bcatalog-card__media\b[^"\']*\1[^>]*>.*?<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\2/is',
                (string) $card,
                $imageMatch
            ) !== 1) {
                return ['ok' => false, 'message' => 'Card bài viết không có ảnh media để xác minh.'];
            }
            $actualSrc = (string) $imageMatch[3];
            break;
        }
        if ($actualSrc === '') {
            continue;
        }
        $actual = editorial_public_rebuild_normalize_static_asset($actualSrc, $relativePath);
        $expected = editorial_public_rebuild_normalize_static_asset($expectedImage, '');
        if (!hash_equals($expected, $actual)) {
            return ['ok' => false, 'message' => 'Ảnh card hub tĩnh chưa khớp dữ liệu Publish.'];
        }
        $matchedPages[] = $relativePath;
    }
    if ($matchedPages === []) {
        return ['ok' => false, 'message' => 'Không tìm thấy card bài viết để xác minh ảnh hub tĩnh.'];
    }
    return ['ok' => true, 'message' => '', 'pages' => $matchedPages];
}

/**
 * @return array{ok:bool,message:string,summary?:array<string,mixed>}
 */
function editorial_public_rebuild_verify_target_image(string $articleId): array
{
    $articles = editorial_public_rebuild_read_articles();
    $target = null;
    foreach ($articles as $article) {
        if (editorial_public_rebuild_text($article['id'] ?? ($article['href'] ?? '')) === $articleId) {
            $target = $article;
            break;
        }
    }
    if ($target === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy ảnh bài trong data/articles.json sau rebuild.'];
    }
    $expectedImage = editorial_public_rebuild_text($target['image'] ?? '');
    $index = editorial_public_rebuild_existing_index();
    $indexArticle = $index['articles'][$articleId] ?? null;
    if (!is_array($indexArticle)
        || !hash_equals($expectedImage, editorial_public_rebuild_text($indexArticle['image'] ?? ''))) {
        return ['ok' => false, 'message' => 'Ảnh bài trong content-index.js chưa khớp dữ liệu Publish.'];
    }
    $section = editorial_public_rebuild_text($target['section'] ?? '');
    $hub = editorial_public_rebuild_read_json(editorial_public_rebuild_root('data/hubs/' . $section . '.json'));
    $hubArticle = null;
    foreach (is_array($hub['articles'] ?? null) ? $hub['articles'] : [] as $article) {
        if (is_array($article)
            && editorial_public_rebuild_text($article['href'] ?? '') === $articleId) {
            $hubArticle = $article;
            break;
        }
    }
    if ($hubArticle === null
        || !hash_equals($expectedImage, editorial_public_rebuild_text($hubArticle['image'] ?? ''))) {
        return ['ok' => false, 'message' => 'Ảnh bài trong hub JSON chưa khớp dữ liệu Publish.'];
    }
    $static = editorial_public_rebuild_verify_static_card($target);
    if (empty($static['ok'])) {
        return ['ok' => false, 'message' => (string) $static['message']];
    }
    return [
        'ok' => true,
        'message' => '',
        'summary' => [
            'article_id' => $articleId,
            'section' => $section,
            'image' => $expectedImage,
            'static_pages' => $static['pages'] ?? [],
        ],
    ];
}

function editorial_public_rebuild_native(string $articleId, array $pythonAttempt = []): array
{
    try {
        $articles = editorial_public_rebuild_read_articles();
        if ($articles === []) {
            return [
                'ok' => false,
                'code' => 'native_articles_source_failed',
                'message' => 'Không đọc được data/articles.json.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        try {
            $built = editorial_public_rebuild_build_index($articles, $articleId);
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'code' => 'native_index_build_failed',
                'message' => 'Không thể dựng dữ liệu content index bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
                'native_error_class' => get_class($error),
            ];
        }
        $index = $built['index'];
        $grouped = $built['grouped'];
        $indexJson = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $contentIndexWritten = $indexJson !== false && editorial_public_rebuild_atomic_write(
                editorial_public_rebuild_root('content-index.js'),
                'window.KetoanDieuTamContentIndex=' . $indexJson . ";\n"
            );
        } catch (\Throwable $error) {
            $contentIndexWritten = false;
        }
        if (!$contentIndexWritten) {
            return [
                'ok' => false,
                'code' => 'native_content_index_failed',
                'message' => 'Không thể ghi content-index.js bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        try {
            $hubsWritten = editorial_public_rebuild_write_hubs($grouped);
        } catch (\Throwable $error) {
            $hubsWritten = false;
        }
        if (!$hubsWritten) {
            return [
                'ok' => false,
                'code' => 'native_hubs_failed',
                'message' => 'Không thể ghi dữ liệu hub và feed bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        try {
            $taxonomyWritten = editorial_public_rebuild_refresh_taxonomy_artifacts($articles);
        } catch (\Throwable $error) {
            $taxonomyWritten = false;
        }
        if (!$taxonomyWritten) {
            return [
                'ok' => false,
                'code' => 'native_taxonomy_failed',
                'message' => 'Không thể cập nhật taxonomy/menu bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        try {
            $articleViewWritten = editorial_public_rebuild_write_target_view($index, $articleId);
        } catch (\Throwable $error) {
            $articleViewWritten = false;
        }
        if (!$articleViewWritten) {
            return [
                'ok' => false,
                'code' => 'native_article_view_failed',
                'message' => 'Không thể ghi article view mục tiêu bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        try {
            $sitemapWritten = editorial_public_rebuild_write_sitemap($index, $grouped);
        } catch (\Throwable $error) {
            $sitemapWritten = false;
        }
        if (!$sitemapWritten) {
            return [
                'ok' => false,
                'code' => 'native_sitemap_failed',
                'message' => 'Không thể cập nhật sitemap bằng PHP native.',
                'rebuild_method' => 'native',
                'python_attempt' => $pythonAttempt,
            ];
        }
        return [
            'ok' => true,
            'code' => 'native_rebuild_succeeded',
            'message' => 'Đã rebuild dữ liệu public bằng PHP native.',
            'rebuild_method' => 'native',
            'python_attempt' => $pythonAttempt,
            'summary' => [
                'articles' => count($articles),
                'thu_vien_count' => count($grouped['thu-vien']),
                'ban_tin_count' => count($grouped['ban-tin']),
                'target_article_view_written' => true,
            ],
        ];
    } catch (\Throwable $error) {
        return [
            'ok' => false,
            'code' => 'native_unexpected_failure',
            'message' => 'PHP native rebuild không hoàn tất.',
            'rebuild_method' => 'native',
            'python_attempt' => $pythonAttempt,
            'native_error_class' => get_class($error),
            'native_message' => $error->getMessage(),
        ];
    }
}

function editorial_public_ready_directory(): string
{
    return EDITORIAL_STORAGE_PATH . '/public-ready';
}

function editorial_public_ready_marker_path(string $articleId): string
{
    return editorial_public_ready_directory() . '/' . hash('sha256', $articleId) . '.json';
}

function editorial_public_ready_read_marker(string $articleId): ?array
{
    $payload = editorial_public_rebuild_read_json(editorial_public_ready_marker_path($articleId));
    if (!is_array($payload)
        || (int) ($payload['schema_version'] ?? 0) !== EDITORIAL_PUBLIC_READY_SCHEMA
        || (string) ($payload['article_id'] ?? '') !== $articleId
        || trim((string) ($payload['published_revision_id'] ?? '')) === ''
        || trim((string) ($payload['published_live_hash'] ?? '')) === '') {
        return null;
    }
    return $payload;
}

function editorial_public_ready_write_current_marker(
    string $articleId,
    string $expectedRevisionId,
    string $expectedLiveHash,
    string $method
): array
{
    $state = editorial_get_article_state($articleId);
    $revisionId = trim((string) ($state['published_revision_id'] ?? ''));
    $liveHash = trim((string) ($state['published_live_hash'] ?? ''));
    if ($state === null
        || $revisionId === ''
        || $liveHash === ''
        || !hash_equals($expectedRevisionId, $revisionId)
        || !hash_equals($expectedLiveHash, $liveHash)) {
        return ['ok' => false, 'code' => 'publication_facts_missing', 'message' => 'Thiếu dữ liệu Publish để ghi public-ready marker.'];
    }
    $marker = [
        'schema_version' => EDITORIAL_PUBLIC_READY_SCHEMA,
        'article_id' => $articleId,
        'published_revision_id' => $revisionId,
        'published_live_hash' => $liveHash,
        'completed_at' => date('c'),
        'rebuild_method' => $method,
    ];
    if (!editorial_public_rebuild_write_json(editorial_public_ready_marker_path($articleId), $marker)) {
        return ['ok' => false, 'code' => 'public_ready_marker_write_failed', 'message' => 'Rebuild xong nhưng không thể ghi trạng thái public-ready.'];
    }
    $verified = editorial_public_ready_read_marker($articleId);
    if ($verified === null
        || !hash_equals($revisionId, (string) $verified['published_revision_id'])
        || !hash_equals($liveHash, (string) $verified['published_live_hash'])) {
        return ['ok' => false, 'code' => 'public_ready_marker_verify_failed', 'message' => 'Không thể xác minh trạng thái public-ready sau khi ghi.'];
    }
    return ['ok' => true, 'marker' => $verified, 'message' => ''];
}

function editorial_public_rebuild_exec_available(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    return !in_array('exec', $disabled, true);
}

function editorial_public_rebuild_python(string $articleId): array
{
    $scriptPath = editorial_public_rebuild_root('tools/rebuild_public_from_articles.py');
    if (!is_file($scriptPath)) {
        return ['ok' => false, 'code' => 'script_not_found', 'message' => 'Không tìm thấy script rebuild.'];
    }
    if (!editorial_public_rebuild_exec_available()) {
        return ['ok' => false, 'code' => 'exec_unavailable', 'message' => 'PHP exec() không khả dụng.'];
    }
    $candidates = [];
    $environment = trim((string) getenv('KDTD_PYTHON_BIN'));
    if ($environment !== '') {
        $candidates[] = $environment;
    }
    $candidates[] = 'python3';
    $candidates[] = 'python';
    $candidates = array_values(array_unique($candidates));
    $last = ['ok' => false, 'code' => 'python_not_found', 'message' => 'Không tìm thấy Python.'];
    foreach ($candidates as $binary) {
        $command = escapeshellarg($binary) . ' ' . escapeshellarg($scriptPath)
            . ' --mode fast --source editorial-publish --article-id ' . escapeshellarg($articleId)
            . ' 2>&1';
        $output = [];
        $exitCode = -1;
        try {
            @exec($command, $output, $exitCode);
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'code' => 'python_exec_failed',
                'message' => 'Không thể chạy Python rebuild.',
                'exit_code' => null,
                'summary' => null,
                'output_tail' => '',
                'python' => $binary,
                'exception' => get_class($error),
            ];
        }
        $outputText = implode("\n", $output);
        $summary = json_decode($outputText, true);
        $last = [
            'ok' => $exitCode === 0 && is_array($summary) && ($summary['ok'] ?? null) === true,
            'code' => $exitCode === 0 ? 'python_invalid_result' : 'python_exit_nonzero',
            'message' => 'Python rebuild không hoàn tất.',
            'exit_code' => $exitCode,
            'summary' => is_array($summary) ? $summary : null,
            'output_tail' => mb_substr($outputText, -500),
            'python' => $binary,
        ];
        if ($last['ok']) {
            $last['code'] = 'python_rebuild_succeeded';
            $last['message'] = 'Đã rebuild dữ liệu public bằng Python.';
            $last['rebuild_method'] = 'python';
            return $last;
        }
        if (!in_array($exitCode, [126, 127, 9009], true)) {
            break;
        }
        $last['code'] = 'python_not_found';
    }
    return $last;
}

function editorial_public_rebuild_run(string $articleId): array
{
    if ($articleId === '') {
        return ['ok' => false, 'code' => 'missing_article_id', 'message' => 'Article ID bắt buộc cho public rebuild.'];
    }
    $stateBefore = editorial_get_article_state($articleId);
    $expectedRevisionId = trim((string) ($stateBefore['published_revision_id'] ?? ''));
    $expectedLiveHash = trim((string) ($stateBefore['published_live_hash'] ?? ''));
    if ($stateBefore === null || $expectedRevisionId === '' || $expectedLiveHash === '') {
        return ['ok' => false, 'code' => 'publication_facts_missing', 'message' => 'Thiếu dữ liệu Publish để rebuild public.'];
    }
    $python = editorial_public_rebuild_python($articleId);
    $result = !empty($python['ok'])
        ? $python
        : editorial_public_rebuild_native($articleId, $python);
    if (empty($result['ok'])) {
        return [
            'ok' => false,
            'code' => 'rebuild_failed_all',
            'message' => 'Python và PHP native đều không rebuild được dữ liệu public.',
            'detail_code' => (string) ($result['code'] ?? 'native_unexpected_failure'),
            'python_attempt' => $python,
            'native_attempt' => $result,
            'exit_code' => $python['exit_code'] ?? null,
            'output_tail' => $python['output_tail'] ?? null,
        ];
    }
    $targetArticle = null;
    foreach (editorial_public_rebuild_read_articles() as $article) {
        if (editorial_public_rebuild_text($article['id'] ?? ($article['href'] ?? '')) === $articleId) {
            $targetArticle = $article;
            break;
        }
    }
    if ($targetArticle === null) {
        return array_merge($result, [
            'ok' => false,
            'code' => 'target_article_missing_after_rebuild',
            'message' => 'Không tìm thấy bài mục tiêu trong dữ liệu public sau rebuild.',
        ]);
    }
    $staticHub = editorial_public_rebuild_sync_static_hub_card($targetArticle);
    if (empty($staticHub['ok'])) {
        return array_merge($result, [
            'ok' => false,
            'code' => 'static_hub_sync_failed',
            'message' => (string) ($staticHub['message'] ?? 'Không thể đồng bộ card hub tĩnh.'),
            'static_hub_sync' => $staticHub,
        ]);
    }
    $result['static_hub_sync'] = ['pages' => $staticHub['pages'] ?? []];
    $verification = editorial_public_rebuild_verify_target_image($articleId);
    if (empty($verification['ok'])) {
        return array_merge($result, [
            'ok' => false,
            'code' => 'target_image_verification_failed',
            'message' => $verification['message'] ?? 'Ảnh bìa public chưa đồng bộ đầy đủ.',
            'target_image_verification' => $verification,
        ]);
    }
    $result['target_image_verification'] = $verification['summary'] ?? [];
    $marker = editorial_public_ready_write_current_marker(
        $articleId,
        $expectedRevisionId,
        $expectedLiveHash,
        (string) ($result['rebuild_method'] ?? (!empty($python['ok']) ? 'python' : 'native'))
    );
    if (empty($marker['ok'])) {
        return array_merge($result, [
            'ok' => false,
            'code' => $marker['code'] ?? 'public_ready_marker_failed',
            'message' => $marker['message'] ?? 'Không thể ghi trạng thái public-ready.',
        ]);
    }
    $result['public_ready_marker'] = $marker['marker'];
    return $result;
}
