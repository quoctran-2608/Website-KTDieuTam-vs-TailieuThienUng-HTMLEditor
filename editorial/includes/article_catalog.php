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
 * @param array{q?: string, section?: string, topic_lv1?: string, assignment?: string, page?: int, per_page?: int} $params
 * @param array<string, array<string,mixed>> $states Map of article_id => state row
 * @param string|null $currentUserId For 'mine' filter
 * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
 */
function editorial_filter_articles(array $params, array $states = [], ?string $currentUserId = null): array
{
    $articles = editorial_load_articles();
    $q = strtolower(trim((string) ($params['q'] ?? '')));
    $section = trim((string) ($params['section'] ?? ''));
    $topicLv1 = trim((string) ($params['topic_lv1'] ?? ''));
    $assignment = trim((string) ($params['assignment'] ?? ''));
    $page = max(1, (int) ($params['page'] ?? 1));
    $perPage = max(10, min(100, (int) ($params['per_page'] ?? 30)));

    $filtered = [];
    foreach ($articles as $article) {
        // Text search
        if ($q !== '' && strpos($article['search_index'], $q) === false) {
            continue;
        }
        // Section filter
        if ($section !== '' && $article['section'] !== $section) {
            continue;
        }
        // Topic Lv1 filter
        if ($topicLv1 !== '' && $article['topic_lv1_key'] !== $topicLv1) {
            continue;
        }
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
