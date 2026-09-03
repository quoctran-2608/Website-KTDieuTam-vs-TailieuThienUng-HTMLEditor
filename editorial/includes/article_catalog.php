<?php
declare(strict_types=1);

/**
 * Editorial V2 — Article Catalog.
 *
 * Reads data/articles.json as the canonical article source.
 * Provides search/filter/pagination and safe HTML file resolution.
 * Does NOT contain assignment business logic (see assignment.php).
 *
 * Adapted from admin/includes/article_index.php patterns.
 */

/**
 * Load all articles from data/articles.json.
 * Result is cached in-memory for the request lifetime.
 *
 * @return array<int, array<string,mixed>>
 */
function editorial_load_articles(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = EDITORIAL_ARTICLES_SOURCE;
    if (!file_exists($path)) {
        $cache = [];
        return $cache;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        $cache = [];
        return $cache;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache = [];
        return $cache;
    }

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $normalized = editorial_normalize_article($item);
        if ($normalized !== null) {
            $items[] = $normalized;
        }
    }

    $cache = $items;
    return $cache;
}

/**
 * Normalize a raw article entry from articles.json.
 *
 * @return array<string,mixed>|null
 */
function editorial_normalize_article(array $item): ?array
{
    $id = trim((string) ($item['id'] ?? ''));
    $title = trim((string) ($item['title'] ?? ''));
    if ($id === '' || $title === '') {
        return null;
    }

    $section = trim((string) ($item['section'] ?? ''));
    $topicLv1Key = trim((string) ($item['topicLv1Key'] ?? ''));
    $topicLv2Key = trim((string) ($item['topicLv2Key'] ?? ''));

    // Build search index
    $searchParts = [
        $id, $title,
        (string) ($item['href'] ?? ''),
        (string) ($item['topicLv1Label'] ?? ''),
        (string) ($item['topicLv2Label'] ?? ''),
        (string) ($item['sectionLabel'] ?? ''),
    ];
    $tags = [];
    if (is_array($item['tags'] ?? null)) {
        foreach ($item['tags'] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $tags[] = trim($tag);
            }
        }
        $searchParts[] = implode(' ', $tags);
    }
    $searchIndex = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $searchParts)) ?? ''));

    return [
        'id' => $id,
        'title' => $title,
        'href' => (string) ($item['href'] ?? ''),
        'canonical' => (string) ($item['canonical'] ?? ''),
        'section' => $section,
        'section_label' => (string) ($item['sectionLabel'] ?? ''),
        'library_kind_key' => (string) ($item['libraryKindKey'] ?? ''),
        'library_kind_label' => (string) ($item['libraryKindLabel'] ?? ''),
        'topic_lv1_key' => $topicLv1Key,
        'topic_lv1_label' => (string) ($item['topicLv1Label'] ?? ''),
        'topic_lv2_key' => $topicLv2Key,
        'topic_lv2_label' => (string) ($item['topicLv2Label'] ?? ''),
        'topic_lv3_key' => (string) ($item['topicLv3Key'] ?? ''),
        'topic_lv3_label' => (string) ($item['topicLv3Label'] ?? ''),
        'tags' => $tags,
        'image' => (string) ($item['image'] ?? ''),
        'publish_date' => (string) ($item['publishDate'] ?? ''),
        'search_index' => $searchIndex,
    ];
}

/**
 * Find a single article by its canonical ID.
 *
 * @return array<string,mixed>|null
 */
function editorial_find_article(string $articleId): ?array
{
    $articleId = trim($articleId);
    if ($articleId === '') return null;

    foreach (editorial_load_articles() as $article) {
        if ($article['id'] === $articleId) {
            return $article;
        }
    }
    return null;
}

/**
 * Total article count.
 */
function editorial_article_count(): int
{
    return count(editorial_load_articles());
}

/**
 * Filter and paginate articles.
 *
 * @param array{q?: string, section?: string, library_kind_key?: string, topic_lv1?: string, topic_lv1_key?: string, topic_lv2_key?: string, topic_lv3_key?: string, assignment?: string, page?: int, per_page?: int} $params
 * @param array<string, array<string,mixed>> $states Map of article_id => state row
 * @param string|null $currentUserId For 'mine' filter
 * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
 */
function editorial_filter_articles(array $params, array $states = [], ?string $currentUserId = null): array
{
    $articles = editorial_load_articles();
    $q = strtolower(trim((string) ($params['q'] ?? '')));
    $section = trim((string) ($params['section'] ?? ''));
    $libraryKindKey = trim((string) ($params['library_kind_key'] ?? ''));
    $topicLv1 = trim((string) ($params['topic_lv1_key'] ?? ($params['topic_lv1'] ?? '')));
    $topicLv2 = trim((string) ($params['topic_lv2_key'] ?? ''));
    $topicLv3 = trim((string) ($params['topic_lv3_key'] ?? ''));
    $assignment = trim((string) ($params['assignment'] ?? ''));
    $page = max(1, (int) ($params['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($params['per_page'] ?? 30)));

    $filtered = [];
    foreach ($articles as $article) {
        if (!editorial_article_matches_taxonomy($article, [
            'section' => $section,
            'library_kind_key' => $libraryKindKey,
            'topic_lv1_key' => $topicLv1,
            'topic_lv2_key' => $topicLv2,
            'topic_lv3_key' => $topicLv3,
        ])) {
            continue;
        }
        if ($q !== '' && strpos($article['search_index'], $q) === false) continue;
        // Assignment filter
        if ($assignment !== '') {
            $state = $states[$article['id']] ?? null;
            $ownerId = $state ? (string) ($state['assigned_user_id'] ?? '') : '';

            if ($assignment === 'available' && $ownerId !== '') continue;
            if ($assignment === 'assigned' && $ownerId === '') continue;
            if ($assignment === 'mine' && $ownerId !== ($currentUserId ?? '')) continue;
        }

        $filtered[] = $article;
    }

    $total = count($filtered);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $items = array_slice($filtered, $offset, $perPage);

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
    ];
}

function editorial_article_matches_taxonomy(array $article, array $filters): bool
{
    $checks = [
        'section' => 'section',
        'library_kind_key' => 'library_kind_key',
        'topic_lv1_key' => 'topic_lv1_key',
        'topic_lv2_key' => 'topic_lv2_key',
        'topic_lv3_key' => 'topic_lv3_key',
    ];
    foreach ($checks as $filterKey => $articleKey) {
        $selected = trim((string) ($filters[$filterKey] ?? ''));
        if ($selected !== '' && (string) ($article[$articleKey] ?? '') !== $selected) {
            return false;
        }
    }
    return true;
}

// ─── Read-only Public Taxonomy ───────────────────────────────────────────────

/**
 * Read the same public taxonomy hierarchy used by the website hubs.
 *
 * Thư viện: library kind → topic level 1 → topic level 2.
 * Bản tin: topic level 1 → topic level 2.
 *
 * @return array{sections:array<int,array<string,mixed>>}
 */
function editorial_read_public_taxonomy_tree(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = dirname(EDITORIAL_BASE_PATH) . '/data/taxonomy.json';
    if (!is_file($path)) {
        return $cache = ['sections' => []];
    }
    $raw = file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (!is_array($decoded) || !is_array($decoded['roots'] ?? null)) {
        return $cache = ['sections' => []];
    }

    $sections = [];
    foreach ($decoded['roots'] as $root) {
        if (!is_array($root)) {
            continue;
        }
        $key = trim((string) ($root['key'] ?? ($root['id'] ?? '')));
        if (!in_array($key, ['thu-vien', 'ban-tin'], true)) {
            continue;
        }
        $section = editorial_taxonomy_base_node($root, 'section');
        $section['children'] = [];
        foreach ((array) ($root['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $section['children'][] = editorial_normalize_public_taxonomy_branch(
                $child,
                $key === 'thu-vien' ? 'library_kind' : 'topic_lv1',
                $key === 'thu-vien' ? 2 : 1
            );
        }
        $sections[] = $section;
    }

    return $cache = ['sections' => $sections];
}

/** @return array<string,mixed> */
function editorial_taxonomy_base_node(array $node, string $type): array
{
    return [
        'key' => trim((string) ($node['key'] ?? ($node['id'] ?? ''))),
        'label' => (string) ($node['label'] ?? ($node['key'] ?? ($node['id'] ?? ''))),
        'type' => $type,
        'count' => (int) ($node['count'] ?? 0),
    ];
}

/** @return array<string,mixed> */
function editorial_normalize_public_taxonomy_branch(array $node, string $type, int $childLevels): array
{
    $out = editorial_taxonomy_base_node($node, $type);
    if ($childLevels <= 0) {
        return $out;
    }

    $nextType = [
        'library_kind' => 'topic_lv1',
        'topic_lv1' => 'topic_lv2',
    ][$type] ?? 'topic';
    $out['children'] = [];
    foreach ((array) ($node['children'] ?? []) as $child) {
        if (is_array($child)) {
            $out['children'][] = editorial_normalize_public_taxonomy_branch($child, $nextType, $childLevels - 1);
        }
    }
    return $out;
}

/**
 * Normalize supported taxonomy/filter query params and map old topic_lv1 URLs.
 *
 * @return array<string,string>
 */
function editorial_taxonomy_filter_params(array $source): array
{
    $topicLv1 = trim((string) ($source['topic_lv1_key'] ?? ($source['topic_lv1'] ?? '')));
    $filters = [
        'q' => trim((string) ($source['q'] ?? '')),
        'section' => trim((string) ($source['section'] ?? '')),
        'library_kind_key' => trim((string) ($source['library_kind_key'] ?? '')),
        'topic_lv1_key' => $topicLv1,
        'topic_lv2_key' => trim((string) ($source['topic_lv2_key'] ?? '')),
        'topic_lv3_key' => trim((string) ($source['topic_lv3_key'] ?? '')),
        'assignment' => trim((string) ($source['assignment'] ?? '')),
    ];
    if ($filters['section'] === 'ban-tin') {
        $filters['library_kind_key'] = '';
    }
    return $filters;
}

/** @return string */
function editorial_taxonomy_url(string $route, array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null && $key !== 'page') {
            $clean[$key] = $value;
        }
    }
    return editorial_url($route) . ($clean ? '?' . http_build_query($clean) : '');
}

/**
 * Render a compact read-only taxonomy tree for article and review queues.
 */
function editorial_render_taxonomy_tree(array $filters, string $route, array $options = []): string
{
    $tree = editorial_read_public_taxonomy_tree();
    $sections = $tree['sections'];
    if ($sections === []) {
        return '';
    }

    $selectedSection = $filters['section'] ?? '';
    $selectedKind = $filters['library_kind_key'] ?? '';
    $selectedLv1 = $filters['topic_lv1_key'] ?? '';
    $selectedLv2 = $filters['topic_lv2_key'] ?? '';
    $displaySection = $selectedSection !== '' ? $selectedSection : 'thu-vien';
    $showCounts = !array_key_exists('show_counts', $options) || !empty($options['show_counts']);
    $scope = $filters;
    unset($scope['section'], $scope['library_kind_key'], $scope['topic_lv1_key'], $scope['topic_lv2_key'], $scope['topic_lv3_key']);

    ob_start();
    ?>
    <section class="editorial-taxonomy-tree">
        <h2>Phân loại bài viết</h2>
        <div class="editorial-taxonomy-sections">
            <?php foreach ($sections as $section): ?>
                <?php $sectionKey = (string) ($section['key'] ?? ''); ?>
                <a class="editorial-taxonomy-section <?= $displaySection === $sectionKey ? 'is-active' : '' ?>"
                   href="<?= editorial_h(editorial_taxonomy_url($route, $scope + ['section' => $sectionKey])) ?>">
                    <span><?= editorial_h((string) ($section['label'] ?? '')) ?></span>
                    <?php if ($showCounts): ?><small><?= editorial_h((string) ($section['count'] ?? 0)) ?></small><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($sections as $section): ?>
            <?php
            $sectionKey = (string) ($section['key'] ?? '');
            if ($sectionKey !== $displaySection) continue;
            foreach ((array) ($section['children'] ?? []) as $root):
                $rootKey = (string) ($root['key'] ?? '');
                $isLibrary = $sectionKey === 'thu-vien';
                $rootActive = $isLibrary ? $selectedKind === $rootKey : $selectedLv1 === $rootKey;
                $rootParams = $scope + ['section' => $sectionKey];
                if ($isLibrary) {
                    $rootParams['library_kind_key'] = $rootKey;
                } else {
                    $rootParams['topic_lv1_key'] = $rootKey;
                }
            ?>
                <div class="editorial-taxonomy-node <?= $rootActive ? 'is-open' : '' ?>">
                    <a class="editorial-taxonomy-root <?= $rootActive && $selectedLv2 === '' ? 'is-active' : '' ?>"
                       href="<?= editorial_h(editorial_taxonomy_url($route, $rootParams)) ?>">
                        <span><?= editorial_h((string) ($root['label'] ?? '')) ?></span>
                        <?php if ($showCounts): ?><small><?= editorial_h((string) ($root['count'] ?? 0)) ?></small><?php endif; ?>
                    </a>
                    <?php if ($rootActive): ?>
                        <div class="editorial-taxonomy-children">
                            <?php foreach ((array) ($root['children'] ?? []) as $lv1): ?>
                                <?php
                                $lv1Key = (string) ($lv1['key'] ?? '');
                                $lv1Active = $isLibrary ? $selectedLv1 === $lv1Key : $selectedLv2 === $lv1Key;
                                $lv1Params = $isLibrary
                                    ? $rootParams + ['topic_lv1_key' => $lv1Key]
                                    : $rootParams + ['topic_lv2_key' => $lv1Key];
                                ?>
                                <a class="editorial-taxonomy-child <?= $lv1Active && ($isLibrary ? $selectedLv2 === '' : true) ? 'is-active' : '' ?>"
                                   href="<?= editorial_h(editorial_taxonomy_url($route, $lv1Params)) ?>">
                                    <span><?= editorial_h((string) ($lv1['label'] ?? '')) ?></span>
                                    <?php if ($showCounts): ?><small><?= editorial_h((string) ($lv1['count'] ?? 0)) ?></small><?php endif; ?>
                                </a>
                                <?php if ($isLibrary && $lv1Active): ?>
                                    <div class="editorial-taxonomy-grandchildren">
                                        <?php foreach ((array) ($lv1['children'] ?? []) as $lv2): ?>
                                            <?php
                                            $lv2Key = (string) ($lv2['key'] ?? '');
                                            $lv2Params = $lv1Params + ['topic_lv2_key' => $lv2Key];
                                            ?>
                                            <a class="editorial-taxonomy-grandchild <?= $selectedLv2 === $lv2Key ? 'is-active' : '' ?>"
                                               href="<?= editorial_h(editorial_taxonomy_url($route, $lv2Params)) ?>">
                                                <span><?= editorial_h((string) ($lv2['label'] ?? '')) ?></span>
                                                <?php if ($showCounts): ?><small><?= editorial_h((string) ($lv2['count'] ?? 0)) ?></small><?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

/**
 * Get unique sections from article catalog for filter dropdown.
 *
 * @return array<int, array{key: string, label: string}>
 */
function editorial_article_sections(): array
{
    $seen = [];
    foreach (editorial_load_articles() as $article) {
        $key = $article['section'];
        if ($key !== '' && !isset($seen[$key])) {
            $seen[$key] = $article['section_label'] ?: $key;
        }
    }
    $result = [];
    foreach ($seen as $key => $label) {
        $result[] = ['key' => $key, 'label' => $label];
    }
    return $result;
}

// ─── Safe HTML file resolution ──────────────────────────────────

/**
 * Resolve the absolute path to an article's live HTML file.
 * Returns null if path is invalid, traversal detected, or file doesn't exist.
 *
 * Adapted from admin/includes/article_parser.php resolve_article_file_path().
 */
function editorial_resolve_article_path(array $article): ?string
{
    $href = trim((string) ($article['href'] ?? ''));
    if ($href === '') return null;

    // Strip query string
    $href = strtok($href, '?');
    if ($href === false || trim($href) === '') return null;

    $relative = ltrim(trim($href), '/');

    // Reject dangerous patterns
    if ($relative === '' ||
        str_contains($relative, '..') ||
        str_contains($relative, "\0") ||
        preg_match('/^[a-zA-Z]+:/', $relative) === 1) {
        return null;
    }

    $repoRoot = dirname(EDITORIAL_BASE_PATH);
    $fullPath = $repoRoot . '/' . $relative;

    // Resolve real path for traversal check
    $realPath = realpath($fullPath);
    if ($realPath === false) return null;

    $realRoot = realpath($repoRoot);
    if ($realRoot === false) return null;

    // Must be inside repo root
    if (!str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR) && $realPath !== $realRoot) {
        return null;
    }

    // Must be a regular file
    if (!is_file($realPath)) return null;

    return $realPath;
}

/**
 * Compute SHA-256 hash of a live HTML file.
 *
 * @return string|null Hash or null if file unreadable
 */
function editorial_live_hash(string $filePath): ?string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }
    $hash = hash_file('sha256', $filePath);
    return $hash !== false ? $hash : null;
}

/**
 * Build a public URL for an article.
 */
function editorial_public_article_url(array $article): string
{
    $href = trim((string) ($article['href'] ?? ''));
    if ($href !== '') {
        return editorial_site_url($href);
    }
    $canonical = trim((string) ($article['canonical'] ?? ''));
    return $canonical !== '' ? $canonical : '#';
}
