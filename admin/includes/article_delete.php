<?php
declare(strict_types=1);

/**
 * Build host allow-list from the article canonical URL.
 *
 * @param array<string,mixed> $article
 * @return array<int,string>
 */
function article_delete_target_hosts(array $article): array
{
  $hosts = [];
  foreach (['canonical', 'href', 'article_href', 'articleHref'] as $field) {
    $value = trim((string) ($article[$field] ?? ''));
    if ($value === '' || preg_match('/^https?:\/\//i', $value) !== 1) {
      continue;
    }
    $host = strtolower((string) (parse_url($value, PHP_URL_HOST) ?: ''));
    if ($host === '') {
      continue;
    }
    $hosts[$host] = true;
    if (str_starts_with($host, 'www.')) {
      $hosts[substr($host, 4)] = true;
    } else {
      $hosts['www.' . $host] = true;
    }
  }
  return array_keys($hosts);
}

/**
 * Normalize a link href to the site-local route used by static article files.
 *
 * @param array<int,string> $allowedHosts Empty means accept all hosts.
 */
function article_delete_normalize_href_route(string $href, array $allowedHosts = []): string
{
  $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  if ($href === '' || str_starts_with($href, '#')) {
    return '';
  }
  if (preg_match('/^(mailto|tel|javascript|data):/i', $href) === 1) {
    return '';
  }

  $href = str_replace('\\', '/', $href);
  $parts = parse_url($href);
  if (!is_array($parts)) {
    return '';
  }

  $host = strtolower((string) ($parts['host'] ?? ''));
  if ($host !== '' && !empty($allowedHosts)) {
    $allowed = array_fill_keys(array_map('strtolower', $allowedHosts), true);
    if (!isset($allowed[$host])) {
      return '';
    }
  }

  $path = trim((string) ($parts['path'] ?? ''));
  if ($path === '') {
    return '';
  }
  $path = preg_replace('#/+#', '/', $path);
  $path = ltrim((string) $path, '/');
  while (str_starts_with($path, './')) {
    $path = substr($path, 2);
  }

  $segments = [];
  foreach (explode('/', $path) as $segment) {
    if ($segment === '' || $segment === '.') {
      continue;
    }
    if ($segment === '..') {
      array_pop($segments);
      continue;
    }
    $segments[] = $segment;
  }

  $route = implode('/', $segments);
  return urldecode($route);
}

/**
 * Build all route variants that should be considered the target article.
 *
 * @param array<string,mixed> $article
 * @return array<int,string>
 */
function article_delete_target_routes(array $article): array
{
  $rawHrefs = [
    (string) ($article['id'] ?? ''),
    (string) ($article['href'] ?? ''),
    (string) ($article['article_href'] ?? ''),
    (string) ($article['articleHref'] ?? ''),
    (string) ($article['legacy_href'] ?? ''),
    (string) ($article['legacyHref'] ?? ''),
    (string) ($article['canonical'] ?? ''),
  ];

  $routes = [];
  foreach ($rawHrefs as $rawHref) {
    $route = article_delete_normalize_href_route($rawHref);
    if ($route !== '') {
      $routes[$route] = true;
    }
  }
  return array_keys($routes);
}

/**
 * Build cheap string needles before running anchor extraction.
 *
 * @param array<string,mixed> $article
 * @param array<int,string> $routes
 * @return array<int,string>
 */
function article_delete_target_needles(array $article, array $routes): array
{
  $needles = [];
  foreach ($routes as $route) {
    $route = trim((string) $route);
    if ($route === '') {
      continue;
    }
    $needles[$route] = true;
    $basename = basename($route);
    if ($basename !== '' && $basename !== $route) {
      $needles[$basename] = true;
    }
  }
  foreach (['canonical', 'href', 'legacy_href', 'legacyHref'] as $field) {
    $value = trim((string) ($article[$field] ?? ''));
    if ($value !== '') {
      $needles[$value] = true;
    }
  }
  return array_values(array_filter(array_keys($needles), static fn($needle): bool => strlen((string) $needle) > 4));
}

/**
 * Extract anchors from an HTML string.
 *
 * @return array<int,array{href:string,text:string,line:int}>
 */
function article_delete_extract_anchor_links(string $html): array
{
  $anchors = [];
  $pattern = '/<a\b(?=[^>]*\bhref\s*=)[^>]*\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))[^>]*>(.*?)<\/a>/is';
  if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    return $anchors;
  }

  foreach ($matches as $match) {
    $href = '';
    for ($i = 1; $i <= 3; $i++) {
      if (isset($match[$i][0]) && trim((string) $match[$i][0]) !== '') {
        $href = trim((string) $match[$i][0]);
        break;
      }
    }
    if ($href === '') {
      continue;
    }

    $inner = (string) ($match[4][0] ?? '');
    $text = trim(strip_tags($inner));
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
      $text = $href;
    }
    if (function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > 120) {
      $text = mb_substr($text, 0, 117, 'UTF-8') . '...';
    } elseif (strlen($text) > 120) {
      $text = substr($text, 0, 117) . '...';
    }

    $offset = (int) ($match[0][1] ?? 0);
    $line = substr_count(substr($html, 0, $offset), "\n") + 1;
    $anchors[] = [
      'href' => $href,
      'text' => $text,
      'line' => $line,
    ];
  }

  return $anchors;
}

/**
 * Scan all indexed article files for links pointing to the target article.
 *
 * @param array<string,mixed> $article
 * @return array<string,mixed>
 */
function build_article_delete_internal_link_report(array $article, int $sourceLimit = 200): array
{
  $targetRoutes = article_delete_target_routes($article);
  $targetRouteMap = array_fill_keys($targetRoutes, true);
  if (empty($targetRouteMap)) {
    return [
      'ok' => true,
      'target_routes' => [],
      'scanned_count' => 0,
      'source_count' => 0,
      'occurrence_count' => 0,
      'sources' => [],
      'missing_files' => [],
      'truncated' => false,
    ];
  }

  $allowedHosts = article_delete_target_hosts($article);
  $needles = article_delete_target_needles($article, $targetRoutes);
  $cache = read_articles_index_cache();
  $items = is_array($cache['items'] ?? null) ? $cache['items'] : [];
  $targetId = trim((string) ($article['id'] ?? ''));
  $sources = [];
  $missingFiles = [];
  $scannedCount = 0;
  $sourceCount = 0;
  $occurrenceCount = 0;
  $truncated = false;

  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $sourceId = trim((string) ($item['id'] ?? ''));
    if ($sourceId === '' || ($targetId !== '' && $sourceId === $targetId)) {
      continue;
    }

    $path = resolve_article_file_path($item);
    if ($path === '' || !file_exists($path)) {
      if (count($missingFiles) < 30) {
        $missingFiles[] = [
          'id' => $sourceId,
          'title' => (string) ($item['title'] ?? ''),
          'path' => $path,
        ];
      }
      continue;
    }

    $html = file_get_contents($path);
    if ($html === false || $html === '') {
      continue;
    }
    $scannedCount++;

    $couldContainTarget = false;
    foreach ($needles as $needle) {
      if ($needle !== '' && strpos($html, $needle) !== false) {
        $couldContainTarget = true;
        break;
      }
    }
    if (!$couldContainTarget) {
      continue;
    }

    $occurrences = [];
    $sourceOccurrenceCount = 0;
    foreach (article_delete_extract_anchor_links($html) as $anchor) {
      $route = article_delete_normalize_href_route((string) $anchor['href'], $allowedHosts);
      if ($route === '' || !isset($targetRouteMap[$route])) {
        continue;
      }
      $occurrenceCount++;
      $sourceOccurrenceCount++;
      if (count($occurrences) < 8) {
        $occurrences[] = $anchor;
      }
    }
    if (empty($occurrences)) {
      continue;
    }

    $sourceCount++;
    if (count($sources) >= $sourceLimit) {
      $truncated = true;
      continue;
    }
    $sources[] = [
      'id' => $sourceId,
      'title' => (string) ($item['title'] ?? ''),
      'href' => (string) ($item['href'] ?? ''),
      'section' => (string) ($item['section'] ?? ''),
      'library_kind_key' => (string) ($item['library_kind_key'] ?? ''),
      'topic_lv1_key' => (string) ($item['topic_lv1_key'] ?? ''),
      'topic_lv2_key' => (string) ($item['topic_lv2_key'] ?? ''),
      'topic_lv3_key' => (string) ($item['topic_lv3_key'] ?? ''),
      'occurrences' => $occurrences,
      'occurrence_count' => $sourceOccurrenceCount,
    ];
  }

  return [
    'ok' => true,
    'target_routes' => $targetRoutes,
    'scanned_count' => $scannedCount,
    'source_count' => $sourceCount,
    'occurrence_count' => $occurrenceCount,
    'sources' => $sources,
    'missing_files' => $missingFiles,
    'truncated' => $truncated,
  ];
}

/**
 * Compact link report for audit/history storage.
 *
 * @param array<string,mixed>|null $report
 * @return array<string,mixed>
 */
function compact_article_delete_internal_link_report(?array $report, bool $forced): array
{
  if ($report === null) {
    return [
      'checked' => false,
      'forced' => $forced,
    ];
  }

  $sources = [];
  foreach (array_slice(is_array($report['sources'] ?? null) ? $report['sources'] : [], 0, 50) as $source) {
    if (!is_array($source)) {
      continue;
    }
    $sources[] = [
      'id' => (string) ($source['id'] ?? ''),
      'title' => (string) ($source['title'] ?? ''),
      'href' => (string) ($source['href'] ?? ''),
      'occurrence_count' => (int) ($source['occurrence_count'] ?? 0),
    ];
  }

  return [
    'checked' => true,
    'forced' => $forced,
    'source_count' => (int) ($report['source_count'] ?? 0),
    'occurrence_count' => (int) ($report['occurrence_count'] ?? 0),
    'target_routes' => is_array($report['target_routes'] ?? null) ? array_values($report['target_routes']) : [],
    'truncated' => !empty($report['truncated']),
    'sources' => $sources,
  ];
}

/**
 * Delete one article completely:
 * - remove HTML file
 * - remove data/articles.json row + rebuild index cache
 * - purge draft/review/revisions/media linked to article
 * - append audit + publish-history record
 *
 * @param array<string,mixed> $article
 * @param array<string,mixed>|null $actor
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function delete_article_with_assets(array $article, ?array $actor = null, array $options = []): array
{
  $articleId = trim((string) ($article['id'] ?? ''));
  if ($articleId === '') {
    return [
      'ok' => false,
      'code' => 'missing_article_id',
      'message' => 'Thiếu article id để xóa bài.',
    ];
  }

  $forceDeleteWithInternalLinks = !empty($options['force_delete_with_internal_links']);
  $internalLinkReport = is_array($options['internal_link_report'] ?? null)
    ? $options['internal_link_report']
    : build_article_delete_internal_link_report($article);
  if (!empty($internalLinkReport['occurrence_count']) && !$forceDeleteWithInternalLinks) {
    return [
      'ok' => false,
      'code' => 'internal_links_exist',
      'message' => 'Bài đang được liên kết nội bộ bởi bài khác. Hãy kiểm tra danh sách liên kết trước khi xóa.',
      'internal_link_report' => $internalLinkReport,
    ];
  }

  $targetPath = resolve_article_file_path($article);
  if ($targetPath === '' || !file_exists($targetPath)) {
    return [
      'ok' => false,
      'code' => 'article_file_missing',
      'message' => 'Không tìm thấy file HTML của bài viết.',
    ];
  }
  if (!is_writable($targetPath)) {
    return [
      'ok' => false,
      'code' => 'article_file_not_writable',
      'message' => 'File bài viết không có quyền xóa.',
    ];
  }

  $legacyHref = trim((string) (($article['legacyHref'] ?? '') ?: ($article['legacy_href'] ?? '')));
  $legacyPath = '';
  if ($legacyHref !== '') {
    $legacyRelative = ltrim(strtok($legacyHref, '?') ?: '', '/');
    if ($legacyRelative !== '' && preg_match('/^(thu-vien|ban-tin)\/[^\/]+\.html$/', $legacyRelative) === 1) {
      $legacyPath = dirname(ADMIN_BASE_PATH) . '/' . $legacyRelative;
    }
  }

  $sourceRaw = file_get_contents(ADMIN_ARTICLES_SOURCE_PATH);
  if ($sourceRaw === false || trim($sourceRaw) === '') {
    return [
      'ok' => false,
      'code' => 'source_read_failed',
      'message' => 'Không đọc được data/articles.json.',
    ];
  }
  $sourceItems = json_decode($sourceRaw, true);
  if (!is_array($sourceItems)) {
    return [
      'ok' => false,
      'code' => 'source_invalid_json',
      'message' => 'data/articles.json không hợp lệ.',
    ];
  }

  $filtered = [];
  $foundInSource = false;
  foreach ($sourceItems as $row) {
    if (!is_array($row)) {
      continue;
    }
    if ((string) ($row['id'] ?? '') === $articleId) {
      $foundInSource = true;
      continue;
    }
    $filtered[] = $row;
  }
  if (!$foundInSource) {
    return [
      'ok' => false,
      'code' => 'article_not_in_source',
      'message' => 'Không tìm thấy bài trong data/articles.json để xóa.',
    ];
  }

  $backupPath = build_backup_file_path($articleId . '-delete');
  if (!copy($targetPath, $backupPath)) {
    return [
      'ok' => false,
      'code' => 'delete_backup_failed',
      'message' => 'Không tạo được backup trước khi xóa bài.',
    ];
  }

  $sourceBackupPath = ADMIN_BACKUPS_DIR . '/' . 'articles-json-' . date('Ymd-His') . '-' . substr(md5($articleId . microtime(true)), 0, 8) . '.json';
  if (!copy(ADMIN_ARTICLES_SOURCE_PATH, $sourceBackupPath)) {
    return [
      'ok' => false,
      'code' => 'source_backup_failed',
      'message' => 'Không tạo được backup data/articles.json trước khi xóa.',
    ];
  }

  // Step 1: delete html file.
  if (!unlink($targetPath)) {
    return [
      'ok' => false,
      'code' => 'delete_html_failed',
      'message' => 'Không xóa được file HTML bài viết.',
    ];
  }
  $legacyStubRemoved = false;
  $legacyBackupPath = '';
  if ($legacyPath !== '' && file_exists($legacyPath) && is_writable($legacyPath)) {
    $legacyBackupPath = build_backup_file_path($articleId . '-legacy-redirect-delete');
    if (@copy($legacyPath, $legacyBackupPath) && @unlink($legacyPath)) {
      $legacyStubRemoved = true;
    }
  }

  // Step 2: write source without article.
  $json = json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || file_put_contents(ADMIN_ARTICLES_SOURCE_PATH, $json . PHP_EOL) === false) {
    // Rollback HTML if source write fails.
    @copy($backupPath, $targetPath);
    if ($legacyStubRemoved && $legacyBackupPath !== '') {
      @copy($legacyBackupPath, $legacyPath);
    }
    return [
      'ok' => false,
      'code' => 'write_source_failed',
      'message' => 'Không ghi được data/articles.json sau khi xóa bài.',
    ];
  }

  // Step 3: purge side data.
  $draftPurged = purge_article_draft_silent($articleId);
  $reviewPurged = purge_article_review_status_silent($articleId);
  $revisionPurge = purge_article_revisions($articleId);
  $mediaPurge = purge_article_uploaded_images($articleId);

  sync_articles_index(true);

  $record = [
    'id' => 'del-' . date('YmdHis') . '-' . substr(md5($articleId . microtime(true)), 0, 8),
    'event' => 'article_delete',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'target_path' => $targetPath,
    'backup_path' => $backupPath,
    'source_backup_path' => $sourceBackupPath,
    'deleted_at' => date('c'),
    'cleanup' => [
      'draft_purged' => $draftPurged,
      'review_purged' => $reviewPurged,
      'revisions_removed' => (int) ($revisionPurge['removed_files'] ?? 0),
      'media_removed_items' => (int) ($mediaPurge['removed_items'] ?? 0),
      'media_removed_files' => (int) ($mediaPurge['removed_files'] ?? 0),
      'media_missing_files' => (int) ($mediaPurge['missing_files'] ?? 0),
      'media_failed_files' => is_array($mediaPurge['failed_files'] ?? null) ? $mediaPurge['failed_files'] : [],
      'legacy_redirect_stub_removed' => $legacyStubRemoved,
      'legacy_redirect_backup_path' => $legacyBackupPath,
    ],
    'internal_links' => compact_article_delete_internal_link_report($internalLinkReport, $forceDeleteWithInternalLinks),
    'actor' => [
      'user_id' => (string) (($actor['user_id'] ?? '') ?: ''),
      'username' => (string) (($actor['username'] ?? '') ?: ''),
      'display_name' => (string) (($actor['display_name'] ?? '') ?: ''),
      'role' => (string) (($actor['role'] ?? '') ?: ''),
    ],
  ];
  append_publish_record($record);

  append_audit_log([
    'event' => 'article.delete.success',
    'article_id' => $articleId,
    'article_href' => (string) ($article['href'] ?? ''),
    'username' => (string) (($actor['username'] ?? '') ?: ''),
    'role' => (string) (($actor['role'] ?? '') ?: ''),
    'backup' => $backupPath,
    'source_backup' => $sourceBackupPath,
    'legacy_redirect_stub_removed' => $legacyStubRemoved,
    'legacy_redirect_backup' => $legacyBackupPath,
    'media_removed_items' => (int) ($mediaPurge['removed_items'] ?? 0),
    'media_failed_count' => count(is_array($mediaPurge['failed_files'] ?? null) ? $mediaPurge['failed_files'] : []),
    'internal_link_source_count' => (int) ($internalLinkReport['source_count'] ?? 0),
    'internal_link_occurrence_count' => (int) ($internalLinkReport['occurrence_count'] ?? 0),
    'forced_delete_with_internal_links' => $forceDeleteWithInternalLinks,
  ]);

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Đã xóa bài viết và dữ liệu liên quan.',
    'record' => $record,
  ];
}
