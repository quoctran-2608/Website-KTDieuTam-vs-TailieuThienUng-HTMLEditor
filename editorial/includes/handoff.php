<?php
declare(strict_types=1);

/**
 * Editorial V2 — Google Handoff service.
 *
 * Handoff is deliberately independent from Safe Publish: it resolves the
 * current server-authoritative workflow source, renders non-published
 * revisions without public writes, and archives the result externally.
 */

require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/revision.php';
require_once __DIR__ . '/composio.php';

const EDITORIAL_HANDOFF_HEADERS = [
    'Article ID',
    'Tên bài',
    'URL',
    'Internal Links',
    'Hình ảnh',
    'Category',
    'Biên tập bởi',
    'HTML Archive',
    'Ghi chú',
    'Published Revision',
    'Ngày bàn giao',
];

const EDITORIAL_HANDOFF_VALUES_GET = 'GOOGLESUPER_VALUES_GET';
const EDITORIAL_HANDOFF_CREATE_FILE = 'GOOGLESUPER_CREATE_FILE_FROM_TEXT';
const EDITORIAL_HANDOFF_EDIT_FILE = 'GOOGLESUPER_EDIT_FILE';
const EDITORIAL_HANDOFF_UPSERT_ROWS = 'GOOGLESUPER_UPSERT_ROWS';

/**
 * @return array<string,mixed>|null
 */
function editorial_handoff_get_sync(string $articleId, string $sourceKey): ?array
{
    $stmt = editorial_db()->prepare('
        SELECT * FROM editorial_handoff_sync
        WHERE article_id = :article_id AND source_key = :source_key
    ');
    $stmt->execute(['article_id' => $articleId, 'source_key' => $sourceKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Batch-load the newest handoff state per displayed article.
 *
 * @param array<int,string> $articleIds
 * @return array<string,array<string,array<string,mixed>>>
 */
function editorial_get_handoff_sync_for_articles(array $articleIds): array
{
    $articleIds = array_values(array_unique(array_filter(array_map('strval', $articleIds))));
    if ($articleIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = editorial_db()->prepare("
        SELECT * FROM editorial_handoff_sync
        WHERE article_id IN ($placeholders)
        ORDER BY updated_at DESC, created_at DESC
    ");
    $stmt->execute($articleIds);

    $result = [];
    while ($row = $stmt->fetch()) {
        $articleId = (string) ($row['article_id'] ?? '');
        $sourceKey = (string) ($row['source_key'] ?? '');
        if ($articleId !== '' && $sourceKey !== '' && !isset($result[$articleId][$sourceKey])) {
            $result[$articleId][$sourceKey] = $row;
        }
    }
    return $result;
}

/**
 * @param array<int,string> $articleIds
 * @return array<string,array<string,bool>>
 */
function editorial_get_saved_draft_article_ids(array $articleIds): array
{
    $articleIds = array_values(array_unique(array_filter(array_map('strval', $articleIds))));
    if ($articleIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = editorial_db()->prepare("SELECT DISTINCT article_id, user_id FROM editorial_drafts WHERE article_id IN ($placeholders)");
    $stmt->execute($articleIds);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(string) $row['article_id']][(string) $row['user_id']] = true;
    }
    return $result;
}

/**
 * @return array<string,mixed>
 */
function editorial_handoff_ensure_sync(string $articleId, array $source): array
{
    return editorial_transaction(function () use ($articleId, $source): array {
        $db = editorial_db();
        $now = date('c');
        $stmt = $db->prepare('
            INSERT OR IGNORE INTO editorial_handoff_sync
            (id, article_id, source_key, source_revision_id, source_kind,
             published_revision_id, sync_status, created_at, updated_at)
            VALUES (:id, :article_id, :source_key, :source_revision_id, :source_kind,
                    :published_revision_id, \'pending\', :created_at, :updated_at)
        ');
        $stmt->execute([
            'id' => editorial_generate_id('handoff'),
            'article_id' => $articleId,
            'source_key' => (string) $source['source_key'],
            'source_revision_id' => $source['source_revision_id'] ?? null,
            'source_kind' => (string) $source['source_kind'],
            'published_revision_id' => $source['published_revision_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $refreshSource = $db->prepare('
            UPDATE editorial_handoff_sync
            SET source_revision_id = :source_revision_id,
                source_kind = :source_kind,
                published_revision_id = :published_revision_id
            WHERE article_id = :article_id AND source_key = :source_key
        ');
        $refreshSource->execute([
            'source_revision_id' => $source['source_revision_id'] ?? null,
            'source_kind' => (string) $source['source_kind'],
            'published_revision_id' => $source['published_revision_id'] ?? null,
            'article_id' => $articleId,
            'source_key' => (string) $source['source_key'],
        ]);
        $sync = editorial_handoff_get_sync($articleId, (string) $source['source_key']);
        if ($sync === null) {
            throw new RuntimeException('Không thể tạo trạng thái bàn giao Google.');
        }
        return $sync;
    });
}

function editorial_handoff_update_sync(string $syncId, array $fields, bool $touchUpdatedAt = true): void
{
    $allowed = [
        'drive_file_id',
        'drive_file_url',
        'handoff_note',
        'sheet_synced_at',
        'synced_by',
        'sync_status',
        'last_error',
    ];
    $sets = [];
    $params = ['id' => $syncId];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $sets[] = $key . ' = :' . $key;
        $params[$key] = $value;
    }
    if ($sets === []) {
        return;
    }
    if ($touchUpdatedAt) {
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = date('c');
    }
    $stmt = editorial_db()->prepare('UPDATE editorial_handoff_sync SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
}

/**
 * Select one existing Drive file for an article without deleting legacy files.
 * A recently successful row is more trustworthy than an arbitrary historical
 * source row, so it becomes the canonical file for all future stage updates.
 *
 * @return array<string,mixed>|null
 */
function editorial_handoff_get_canonical_drive_file(string $articleId): ?array
{
    $stmt = editorial_db()->prepare("
        SELECT * FROM editorial_handoff_sync
        WHERE article_id = :article_id
          AND TRIM(COALESCE(drive_file_id, '')) <> ''
        ORDER BY
          CASE WHEN sync_status IN ('synced', 'drive_uploaded') THEN 0 ELSE 1 END,
          updated_at DESC,
          created_at DESC,
          id DESC
    ");
    $stmt->execute(['article_id' => $articleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * A shared Drive file can be referenced by several source rows. Only the most
 * recent server-side Drive success identifies which source currently owns bytes.
 */
function editorial_handoff_get_drive_content_source_key(string $articleId, string $driveFileId): string
{
    if ($driveFileId === '') {
        return '';
    }
    $stmt = editorial_db()->prepare("
        SELECT source_key FROM editorial_handoff_sync
        WHERE article_id = :article_id
          AND drive_file_id = :drive_file_id
          AND sync_status IN ('synced', 'drive_uploaded')
        ORDER BY updated_at DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([
        'article_id' => $articleId,
        'drive_file_id' => $driveFileId,
    ]);
    return trim((string) $stmt->fetchColumn());
}

function editorial_handoff_safe_error(string $message): string
{
    return mb_strimwidth(trim($message), 0, 500, '…');
}

function editorial_handoff_log(string $event, string $articleId, string $actorUserId, array $payload = []): void
{
    $safe = array_intersect_key($payload, array_flip([
        'article_id',
        'source_key',
        'source_revision_id',
        'source_kind',
        'published_revision_id',
        'sheet_match_count',
        'drive_file_id',
        'sync_status',
        'intent',
        'composio_log_id',
        'code',
    ]));
    editorial_log_activity($event, $articleId, $actorUserId, json_encode($safe));
}

/**
 * @return array{ok:bool,message:string,settings?:array<string,mixed>}
 */
function editorial_handoff_verified_settings(): array
{
    return editorial_handoff_config_status();
}

function editorial_handoff_user_can_sync(string $articleId, array $actor): bool
{
    $actorId = (string) ($actor['user_id'] ?? '');
    if ($actorId === '') {
        return false;
    }
    $state = editorial_get_article_state($articleId);
    if ($state && (string) ($state['assigned_user_id'] ?? '') === $actorId) {
        return true;
    }
    foreach (editorial_get_article_contributors([$articleId])[$articleId] ?? [] as $contributor) {
        if ((string) ($contributor['user_id'] ?? '') === $actorId) {
            return true;
        }
    }
    return false;
}

function editorial_handoff_a1_range(string $sheetName): string
{
    return "'" . str_replace("'", "''", trim($sheetName)) . "'!A:K";
}

function editorial_handoff_normalize_url_path(string $path): string
{
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
    return '/' . implode('/', $segments);
}

function editorial_handoff_resolve_url(string $baseUrl, string $reference, bool $allowExternal): ?string
{
    $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($reference === '' || str_starts_with($reference, '#')
        || preg_match('/^(?:mailto|tel|javascript|data|blob):/i', $reference)) {
        return null;
    }
    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return null;
    }
    $scheme = strtolower((string) $base['scheme']);
    $host = strtolower((string) $base['host']);
    $port = isset($base['port']) ? ':' . (int) $base['port'] : '';

    if (str_starts_with($reference, '//')) {
        $reference = $scheme . ':' . $reference;
    }
    if (preg_match('#^https?://#i', $reference)) {
        $parts = parse_url($reference);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }
        if (!$allowExternal && strtolower((string) $parts['host']) !== $host) {
            return null;
        }
        $path = editorial_handoff_normalize_url_path((string) ($parts['path'] ?? '/'));
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    $parts = parse_url($reference);
    if ($parts === false) {
        return null;
    }
    $refPath = (string) ($parts['path'] ?? '');
    $basePath = (string) ($base['path'] ?? '/');
    if ($refPath === '') {
        $path = $basePath;
        return $scheme . '://' . $host . $port . editorial_handoff_normalize_url_path($path)
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
    $baseDir = str_ends_with($basePath, '/')
        ? $basePath
        : rtrim(str_replace('\\', '/', dirname($basePath)), '/') . '/';
    $path = str_starts_with($refPath, '/')
        ? $refPath
        : $baseDir . $refPath;
    return $scheme . '://' . $host . $port . editorial_handoff_normalize_url_path($path)
        . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/**
 * @return array<int,string>
 */
function editorial_handoff_extract_urls(string $proseHtml, string $articlePublicUrl, string $publicBaseUrl, bool $images = false): array
{
    $pattern = $images
        ? '/<img\b[^>]*(?:^|\s)src\s*=\s*(?:(["\'])(.*?)\1|([^\s>"\']+))/is'
        : '/<a\b[^>]*(?:^|\s)href\s*=\s*(?:(["\'])(.*?)\1|([^\s>"\']+))/is';
    if (!preg_match_all($pattern, $proseHtml, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $baseHost = strtolower((string) (parse_url($publicBaseUrl, PHP_URL_HOST) ?? ''));
    $urls = [];
    $seen = [];
    foreach ($matches as $match) {
        $reference = (string) (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        $resolved = editorial_handoff_resolve_url($articlePublicUrl, $reference, $images);
        if ($resolved === null) {
            continue;
        }
        if (!$images && strtolower((string) (parse_url($resolved, PHP_URL_HOST) ?? '')) !== $baseHost) {
            continue;
        }
        if (!isset($seen[$resolved])) {
            $seen[$resolved] = true;
            $urls[] = $resolved;
        }
    }
    return $urls;
}

function editorial_handoff_category(array $article): string
{
    $labels = [];
    foreach (['section_label', 'library_kind_label', 'topic_lv1_label', 'topic_lv2_label', 'topic_lv3_label'] as $key) {
        $value = trim((string) ($article[$key] ?? ''));
        if ($value !== '') {
            $labels[] = $value;
        }
    }
    return implode(' > ', $labels);
}

function editorial_handoff_archive_filename(string $articleId): string
{
    $safeArticle = preg_replace('/[^A-Za-z0-9._-]+/', '-', $articleId) ?? '';
    $safeArticle = trim($safeArticle, '.-');
    if ($safeArticle === '') {
        throw new RuntimeException('Không thể tạo tên archive an toàn.');
    }
    return $safeArticle . '.html';
}

/**
 * Build complete HTML for an immutable editorial/review/approved source
 * without writing public files.
 *
 * @return array{ok:bool,html?:string,message:string}
 */
function editorial_handoff_render_revision_html(array $article, array $state, array $revision): array
{
    require_once __DIR__ . '/publish.php';
    $snapshot = editorial_get_verified_revision_snapshot($revision);
    if (!$snapshot['ok']) {
        return ['ok' => false, 'message' => 'Snapshot nguồn bàn giao không hợp lệ: ' . $snapshot['message']];
    }
    $htmlPath = editorial_resolve_article_path($article);
    if ($htmlPath === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy HTML shell hiện tại để dựng archive.'];
    }
    $liveHtml = file_get_contents($htmlPath);
    if ($liveHtml === false || $liveHtml === '') {
        return ['ok' => false, 'message' => 'Không thể đọc HTML shell hiện tại để dựng archive.'];
    }
    $baseLiveHash = trim((string) ($state['base_live_hash'] ?? ''));
    if ($baseLiveHash === '' || hash('sha256', $liveHtml) !== $baseLiveHash) {
        return ['ok' => false, 'message' => 'HTML public hiện tại không còn khớp base live hash của workflow.'];
    }
    $parsed = editorial_parse_article_html($liveHtml, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Không thể phân tích HTML shell hiện tại.'];
    }
    $normalized = editorial_normalize_publish_payload(
        (array) $snapshot['payload'],
        (array) ($parsed['meta_payload'] ?? []),
        $article
    );
    $rendered = editorial_render_approved_html($liveHtml, $article, $normalized, false);
    if (!$rendered['ok']) {
        return ['ok' => false, 'message' => 'Không thể dựng full HTML bàn giao: ' . $rendered['message']];
    }
    $validated = editorial_validate_rendered_html((string) $rendered['html'], $normalized);
    if (!$validated['ok']) {
        return ['ok' => false, 'message' => 'Full HTML bàn giao không đạt kiểm tra: ' . $validated['message']];
    }
    return ['ok' => true, 'html' => (string) $rendered['html'], 'message' => ''];
}

/**
 * Resolve the current server-authoritative Handoff source for every workflow
 * state. Browser input never supplies revision IDs/source keys.
 *
 * @return array{ok:bool,message:string,source?:array<string,mixed>}
 */
function editorial_handoff_resolve_source(array $article, ?array $state): array
{
    $articleId = (string) $article['id'];
    $status = (string) ($state['status'] ?? 'available');
    $htmlPath = editorial_resolve_article_path($article);
    if ($htmlPath === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy HTML nguồn của bài viết.'];
    }

    if (in_array($status, ['editing', 'returned'], true)) {
        $ownerId = trim((string) ($state['assigned_user_id'] ?? ''));
        if ($ownerId === '') {
            return ['ok' => false, 'message' => 'Bài đang biên tập nhưng không có người phụ trách hợp lệ.'];
        }
        $assignment = editorial_get_active_assignment($articleId);
        if (!$assignment || (string) ($assignment['user_id'] ?? '') !== $ownerId) {
            return ['ok' => false, 'message' => 'Nguồn Chặng bàn giao không khớp active assignment.'];
        }
        $stages = editorial_get_active_stage_bundle($articleId, (string) $assignment['id']);
        $revision = $stages['stage2'] ?? $stages['stage1'] ?? null;
        if ($revision === null) {
            return ['ok' => false, 'message' => 'Hãy hoàn tất Chặng 1 trước khi lưu Drive + Sheet.'];
        }
        $rendered = editorial_handoff_render_revision_html($article, $state, $revision);
        if (!$rendered['ok']) {
            return $rendered;
        }
        return [
            'ok' => true,
            'message' => '',
            'source' => [
                'source_key' => 'revision:' . (string) $revision['id'],
                'source_revision_id' => (string) $revision['id'],
                'source_kind' => (string) $revision['milestone_key'],
                'published_revision_id' => null,
                'source_identifier' => (string) $revision['id'],
                'html' => $rendered['html'],
            ],
        ];
    }

    $pointerField = match ($status) {
        'ready_review' => 'review_revision_id',
        'approved' => 'approved_revision_id',
        default => '',
    };
    if ($pointerField !== '') {
        $revisionId = trim((string) ($state[$pointerField] ?? ''));
        $revision = $revisionId !== '' ? editorial_get_revision($revisionId) : null;
        $assignment = editorial_get_active_assignment($articleId);
        if (!$revision || ($revision['revision_type'] ?? '') !== 'editorial'
            || (string) ($revision['article_id'] ?? '') !== $articleId
            || !$assignment
            || (string) ($assignment['user_id'] ?? '') !== (string) ($state['assigned_user_id'] ?? '')
            || (string) ($revision['assignment_id'] ?? '') !== (string) $assignment['id']) {
            return ['ok' => false, 'message' => 'Revision nguồn bàn giao không hợp lệ hoặc không khớp assignment.'];
        }
        $rendered = editorial_handoff_render_revision_html($article, $state, $revision);
        if (!$rendered['ok']) {
            return $rendered;
        }
        return [
            'ok' => true,
            'message' => '',
            'source' => [
                'source_key' => 'revision:' . $revisionId,
                'source_revision_id' => $revisionId,
                'source_kind' => $status === 'ready_review' ? 'review' : 'approved',
                'published_revision_id' => null,
                'source_identifier' => $revisionId,
                'html' => $rendered['html'],
            ],
        ];
    }

    $liveHtml = file_get_contents($htmlPath);
    if ($liveHtml === false || $liveHtml === '') {
        return ['ok' => false, 'message' => 'Không thể đọc HTML live để bàn giao.'];
    }
    $liveHash = hash('sha256', $liveHtml);
    if ($status === 'published') {
        $publishedRevisionId = trim((string) ($state['published_revision_id'] ?? ''));
        $publishedHash = trim((string) ($state['published_live_hash'] ?? ''));
        $revision = $publishedRevisionId !== '' ? editorial_get_revision($publishedRevisionId) : null;
        if (!$revision || ($revision['revision_type'] ?? '') !== 'published'
            || (string) ($revision['article_id'] ?? '') !== $articleId
            || $publishedHash === ''
            || $liveHash !== $publishedHash) {
            return ['ok' => false, 'message' => 'Published source không hợp lệ hoặc live hash đã thay đổi.'];
        }
        return [
            'ok' => true,
            'message' => '',
            'source' => [
                'source_key' => 'revision:' . $publishedRevisionId,
                'source_revision_id' => $publishedRevisionId,
                'source_kind' => 'published',
                'published_revision_id' => $publishedRevisionId,
                'source_identifier' => $publishedRevisionId,
                'html' => $liveHtml,
            ],
        ];
    }

    // No active workflow source: authorized Admin/contributors may archive the
    // exact current live file using a deterministic hash identity.
    return [
        'ok' => true,
        'message' => '',
        'source' => [
            'source_key' => 'live:' . $liveHash,
            'source_revision_id' => null,
            'source_kind' => 'live',
            'published_revision_id' => null,
            'source_identifier' => 'live:' . $liveHash,
            'html' => $liveHtml,
        ],
    ];
}

function editorial_handoff_safe_drive_url(string $value): string
{
    $parts = parse_url(trim($value));
    if (!is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || !empty($parts['user'])
        || !empty($parts['pass'])) {
        return '';
    }
    return trim($value);
}

/**
 * @return array{ok:bool,metadata?:array<string,string>,message:string}
 */
function editorial_handoff_build_metadata(array $article, string $html, string $publicBaseUrl, string $sourceIdentifier, string $note, string $handoffDate): array
{
    $parsed = editorial_parse_article_html($html, '');
    if (!$parsed['ok']) {
        return ['ok' => false, 'message' => 'Không thể phân tích HTML public để bàn giao.'];
    }
    $meta = is_array($parsed['meta_payload'] ?? null) ? $parsed['meta_payload'] : [];
    $articleUrl = editorial_handoff_resolve_url($publicBaseUrl, (string) ($article['href'] ?? ''), false);
    if ($articleUrl === null) {
        return ['ok' => false, 'message' => 'Không thể tạo URL public canonical cho bài viết.'];
    }

    $links = editorial_handoff_extract_urls((string) ($parsed['prose']['inner'] ?? ''), $articleUrl, $publicBaseUrl, false);
    $images = editorial_handoff_extract_urls((string) ($parsed['prose']['inner'] ?? ''), $articleUrl, $publicBaseUrl, true);
    $featured = trim((string) ($meta['image'] ?? ($article['image'] ?? '')));
    if ($featured !== '') {
        $featuredUrl = editorial_handoff_resolve_url($articleUrl, $featured, true);
        if ($featuredUrl !== null && !in_array($featuredUrl, $images, true)) {
            $images[] = $featuredUrl;
        }
    }
    $contributors = editorial_get_article_contributors(
        [(string) $article['id']]
    )[(string) $article['id']] ?? [];
    $names = [];
    foreach ($contributors as $contributor) {
        $name = trim((string) ($contributor['display_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return [
        'ok' => true,
        'message' => 'Đã chuẩn bị metadata bàn giao.',
        'metadata' => [
            'Article ID' => (string) $article['id'],
            'Tên bài' => trim((string) ($meta['title'] ?? '')) !== ''
                ? trim((string) $meta['title'])
                : trim((string) ($article['title'] ?? '')),
            'URL' => $articleUrl,
            'Internal Links' => implode("\n", $links),
            'Hình ảnh' => implode("\n", $images),
            'Category' => editorial_handoff_category($article),
            'Biên tập bởi' => implode(', ', array_values(array_unique($names))),
            'HTML Archive' => '',
            'Ghi chú' => $note,
            'Published Revision' => $sourceIdentifier,
            'Ngày bàn giao' => $handoffDate,
        ],
    ];
}

/**
 * @return array{ok:bool,metadata?:array<string,mixed>,message:string}
 */
function editorial_handoff_load_pinned_tool_schema(string $toolSlug, string $version): array
{
    $response = editorial_composio_get_tool($toolSlug, $version);
    if (!$response['ok']) {
        return ['ok' => false, 'message' => 'Không thể đọc schema pinned của tool ' . $toolSlug . '.'];
    }
    $metadata = editorial_composio_tool_metadata($response['json'] ?? []);
    $schemaVersion = editorial_composio_find_concrete_version($metadata);
    if ($schemaVersion === '' || $schemaVersion !== $version) {
        return ['ok' => false, 'message' => 'Schema tool ' . $toolSlug . ' không khớp toolkit version đã pin.'];
    }
    return ['ok' => true, 'metadata' => $metadata, 'message' => 'Schema hợp lệ.'];
}

/**
 * @return array{ok:bool,field?:string,message:string}
 */
function editorial_handoff_select_parameter(array $metadata, array $exactNames, array $semanticTerms, bool $requiredOnly = false): array
{
    $parameters = editorial_composio_schema_parameters($metadata);
    foreach ([true, false] as $requiredPass) {
        foreach ($parameters as $name => $parameter) {
            if (($requiredOnly || $requiredPass) && empty($parameter['required'])) {
                continue;
            }
            if (in_array(editorial_composio_normalize_schema_text($name), $exactNames, true)) {
                return ['ok' => true, 'field' => $name, 'message' => ''];
            }
        }
    }
    foreach ([true, false] as $requiredPass) {
        foreach ($parameters as $name => $parameter) {
            if (($requiredOnly || $requiredPass) && empty($parameter['required'])) {
                continue;
            }
            $text = editorial_composio_schema_text($name, $parameter['schema']);
            $matches = true;
            foreach ($semanticTerms as $term) {
                if (!editorial_composio_schema_matches_term($text, (string) $term)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return ['ok' => true, 'field' => $name, 'message' => ''];
            }
        }
    }
    return ['ok' => false, 'message' => 'Không thể map field schema cần thiết.'];
}

/**
 * @return array{ok:bool,arguments:array<string,mixed>,message:string}
 */
function editorial_handoff_values_get_arguments(array $metadata, string $spreadsheetId, string $a1Range): array
{
    $arguments = [];
    $spreadsheet = editorial_handoff_select_parameter($metadata, ['spreadsheet id'], ['spreadsheet', 'id'], true);
    if (!$spreadsheet['ok']) {
        $spreadsheet = editorial_handoff_select_parameter($metadata, ['spreadsheet id'], ['spreadsheet', 'id']);
    }
    $range = editorial_handoff_select_parameter($metadata, ['range', 'a1 range', 'a1 notation'], ['range'], true);
    if (!$range['ok']) {
        $range = editorial_handoff_select_parameter($metadata, ['range', 'a1 range', 'a1 notation'], ['range']);
    }
    if (!$spreadsheet['ok'] || !$range['ok']) {
        return ['ok' => false, 'arguments' => [], 'message' => 'Không thể map Spreadsheet ID hoặc A1 range theo schema VALUES_GET.'];
    }
    $arguments[(string) $spreadsheet['field']] = $spreadsheetId;
    $rangeSchema = (array) (editorial_composio_schema_parameters($metadata)[(string) $range['field']]['schema'] ?? []);
    $arguments[(string) $range['field']] = ($rangeSchema['type'] ?? 'string') === 'array'
        ? [$a1Range]
        : $a1Range;
    $validation = editorial_composio_validate_required_arguments($metadata, $arguments);
    return $validation['ok']
        ? ['ok' => true, 'arguments' => $arguments, 'message' => '']
        : ['ok' => false, 'arguments' => [], 'message' => $validation['error']];
}

/**
 * @return array<int,array<int,string>>|null
 */
function editorial_handoff_extract_values_matrix(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    foreach (['values', 'data', 'result', 'output'] as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            return editorial_handoff_extract_values_matrix($value[$key]);
        }
    }
    if ($value === []) {
        return [];
    }
    foreach ($value as $row) {
        if (!is_array($row)) {
            return null;
        }
    }
    $matrix = [];
    foreach ($value as $row) {
        $normalizedRow = [];
        foreach (array_values($row) as $cell) {
            if (!is_scalar($cell) && $cell !== null) {
                return null;
            }
            $normalizedRow[] = trim((string) $cell);
        }
        $matrix[] = $normalizedRow;
    }
    return $matrix;
}

/**
 * @return array{ok:bool,matrix?:array<int,array<int,string>>,message:string}
 */
function editorial_handoff_values_get(array $settings, string $version, array $metadata): array
{
    $arguments = editorial_handoff_values_get_arguments(
        $metadata,
        (string) $settings['spreadsheet_id'],
        editorial_handoff_a1_range((string) $settings['sheet_name'])
    );
    if (!$arguments['ok']) {
        return ['ok' => false, 'message' => $arguments['message']];
    }
    $response = editorial_composio_execute(
        EDITORIAL_HANDOFF_VALUES_GET,
        (string) $settings['connected_account_id'],
        (string) $settings['connected_user_id'],
        $version,
        $arguments['arguments']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể đọc Google Sheet', $response)];
    }
    $matrix = editorial_handoff_extract_values_matrix($response['json']['data'] ?? $response['json']);
    if ($matrix === null) {
        return ['ok' => false, 'message' => 'Không thể phân tích values matrix từ Google Sheet.'];
    }
    return ['ok' => true, 'matrix' => $matrix, 'message' => ''];
}

/**
 * @return array{ok:bool,count?:int,intent?:string,rows?:array<int,array<int,string>>,message:string}
 */
function editorial_handoff_sheet_preflight(array $matrix, string $articleId): array
{
    $firstRow = $matrix[0] ?? [];
    $hasSheetContent = false;
    foreach ($firstRow as $cell) {
        if (trim((string) $cell) !== '') {
            $hasSheetContent = true;
            break;
        }
    }
    if ($matrix === [] || !$hasSheetContent) {
        return ['ok' => false, 'message' => 'Google Sheet đang trống. Vui lòng tạo hàng tiêu đề Google Handoff trước.'];
    }
    $header = array_slice($matrix[0], 0, count(EDITORIAL_HANDOFF_HEADERS));
    if ($header !== EDITORIAL_HANDOFF_HEADERS) {
        return ['ok' => false, 'message' => 'Google Sheet chưa có đúng hàng tiêu đề Google Handoff.'];
    }
    $matches = [];
    foreach (array_slice($matrix, 1) as $row) {
        if (trim((string) ($row[0] ?? '')) === $articleId) {
            $matches[] = $row;
        }
    }
    $count = count($matches);
    if ($count >= 2) {
        return ['ok' => false, 'count' => $count, 'message' => 'Google Sheet đang có nhiều dòng trùng Article ID \'' . $articleId . '\'. Vui lòng xóa/gộp dòng trùng rồi thử lại.'];
    }
    return [
        'ok' => true,
        'count' => $count,
        'intent' => $count === 1 ? 'UPDATE_EXISTING' : 'INSERT_NEW',
        'rows' => $matches,
        'message' => '',
    ];
}

function editorial_handoff_extract_response_value(mixed $value, array $normalizedKeys): string
{
    if (!is_array($value)) {
        return '';
    }
    foreach ($normalizedKeys as $expectedKey) {
        foreach ($value as $key => $child) {
            if (is_string($key)
                && editorial_composio_normalize_schema_text($key) === $expectedKey
                && is_scalar($child) && trim((string) $child) !== '') {
                return trim((string) $child);
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $found = editorial_handoff_extract_response_value($child, [$expectedKey]);
                if ($found !== '') {
                    return $found;
                }
            }
        }
    }
    return '';
}

/**
 * @return array{ok:bool,file_id?:string,file_url?:string,message:string}
 */
function editorial_handoff_create_html_archive(array $settings, string $version, array $metadata, string $filename, string $html): array
{
    if ($html === '') {
        return ['ok' => false, 'message' => 'HTML archive rỗng.'];
    }
    $arguments = [];
    $fields = [
        ['exact' => ['file name', 'filename', 'name'], 'terms' => ['file', 'name'], 'value' => $filename],
        ['exact' => ['text content', 'content', 'text'], 'terms' => ['content'], 'value' => $html],
        ['exact' => ['parent folder id', 'parent folder ids', 'parent id', 'folder id', 'parent', 'parents'], 'terms' => ['parent'], 'value' => (string) $settings['drive_folder_id']],
    ];
    foreach ($fields as $fieldSpec) {
        $field = editorial_handoff_select_parameter($metadata, $fieldSpec['exact'], $fieldSpec['terms'], true);
        if (!$field['ok']) {
            $field = editorial_handoff_select_parameter($metadata, $fieldSpec['exact'], $fieldSpec['terms']);
        }
        if (!$field['ok']) {
            return ['ok' => false, 'message' => 'Không thể map schema CREATE_FILE_FROM_TEXT.'];
        }
        $fieldName = (string) $field['field'];
        $fieldSchema = (array) (editorial_composio_schema_parameters($metadata)[$fieldName]['schema'] ?? []);
        $value = $fieldSpec['value'];
        if ($fieldSpec['exact'] === ['text content', 'content', 'text']
            && isset($fieldSchema['maxLength'])
            && is_numeric($fieldSchema['maxLength'])
            && strlen((string) $value) > (int) $fieldSchema['maxLength']) {
            return ['ok' => false, 'message' => 'HTML archive vượt giới hạn kích thước mà schema tool cho phép.'];
        }
        if ($fieldSpec['value'] === (string) $settings['drive_folder_id']
            && (($fieldSchema['type'] ?? '') === 'array')) {
            $value = [(string) $settings['drive_folder_id']];
        }
        $arguments[$fieldName] = $value;
    }
    $mime = editorial_handoff_select_parameter($metadata, ['mime type', 'content type'], ['mime'], false);
    if ($mime['ok']) {
        $arguments[(string) $mime['field']] = 'text/html';
    }
    $validation = editorial_composio_validate_required_arguments($metadata, $arguments);
    if (!$validation['ok']) {
        return ['ok' => false, 'message' => $validation['error']];
    }
    $response = editorial_composio_execute(
        EDITORIAL_HANDOFF_CREATE_FILE,
        (string) $settings['connected_account_id'],
        (string) $settings['connected_user_id'],
        $version,
        $arguments
    );
    if (!$response['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể lưu HTML lên Google Drive', $response)];
    }
    $data = $response['json']['data'] ?? $response['json'];
    $fileId = editorial_handoff_extract_response_value($data, ['file id', 'id']);
    if ($fileId === '') {
        return ['ok' => false, 'message' => 'Google Drive tạo file nhưng không trả file ID.'];
    }
    $fileUrl = editorial_handoff_extract_response_value($data, ['web view link', 'webviewlink', 'url']);
    $fileUrl = editorial_handoff_safe_drive_url($fileUrl);
    if ($fileUrl === '') {
        $fileUrl = 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
    }
    return ['ok' => true, 'file_id' => $fileId, 'file_url' => $fileUrl, 'message' => ''];
}

/**
 * @return array{ok:bool,file_id?:string,file_url?:string,message:string}
 */
function editorial_handoff_edit_html_file(array $settings, string $version, array $metadata, string $fileId, string $html): array
{
    if ($fileId === '' || $html === '') {
        return ['ok' => false, 'message' => 'Thiếu file Drive hoặc HTML để cập nhật.'];
    }
    $arguments = [];
    $fields = [
        ['exact' => ['file id', 'drive file id'], 'terms' => ['file', 'id'], 'value' => $fileId],
        ['exact' => ['text content', 'content', 'text'], 'terms' => ['content'], 'value' => $html],
    ];
    foreach ($fields as $fieldSpec) {
        $field = editorial_handoff_select_parameter($metadata, $fieldSpec['exact'], $fieldSpec['terms'], true);
        if (!$field['ok']) {
            $field = editorial_handoff_select_parameter($metadata, $fieldSpec['exact'], $fieldSpec['terms']);
        }
        if (!$field['ok']) {
            return ['ok' => false, 'message' => 'Không thể map schema EDIT_FILE.'];
        }
        $fieldName = (string) $field['field'];
        $fieldSchema = (array) (editorial_composio_schema_parameters($metadata)[$fieldName]['schema'] ?? []);
        if ($fieldSpec['exact'] === ['text content', 'content', 'text']
            && isset($fieldSchema['maxLength'])
            && is_numeric($fieldSchema['maxLength'])
            && strlen($html) > (int) $fieldSchema['maxLength']) {
            return ['ok' => false, 'message' => 'HTML archive vượt giới hạn kích thước mà schema tool cho phép.'];
        }
        $arguments[$fieldName] = $fieldSpec['value'];
    }
    $mime = editorial_handoff_select_parameter($metadata, ['mime type', 'content type'], ['mime'], false);
    if ($mime['ok']) {
        $arguments[(string) $mime['field']] = 'text/html';
    }
    $validation = editorial_composio_validate_required_arguments($metadata, $arguments);
    if (!$validation['ok']) {
        return ['ok' => false, 'message' => $validation['error']];
    }
    $response = editorial_composio_execute(
        EDITORIAL_HANDOFF_EDIT_FILE,
        (string) $settings['connected_account_id'],
        (string) $settings['connected_user_id'],
        $version,
        $arguments
    );
    if (!$response['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể cập nhật HTML Drive', $response)];
    }
    $data = $response['json']['data'] ?? $response['json'];
    $responseFileId = editorial_handoff_extract_response_value($data, ['file id', 'id']);
    if ($responseFileId !== '' && $responseFileId !== $fileId) {
        return ['ok' => false, 'message' => 'Google Drive trả về file ID không khớp file canonical.'];
    }
    $fileUrl = editorial_handoff_safe_drive_url(
        editorial_handoff_extract_response_value($data, ['web view link', 'webviewlink', 'url'])
    );
    if ($fileUrl === '') {
        $fileUrl = 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
    }
    return ['ok' => true, 'file_id' => $fileId, 'file_url' => $fileUrl, 'message' => ''];
}

/**
 * @return 'object'|'array'|''
 */
function editorial_handoff_rows_shape(array $schema): string
{
    $items = $schema['items'] ?? null;
    if (!is_array($items)) {
        return '';
    }
    if (isset($items['properties']) || (($items['type'] ?? '') === 'object')) {
        return 'object';
    }
    if (($items['type'] ?? '') === 'array' || isset($items['items'])) {
        return 'array';
    }
    return '';
}

/**
 * @return array{ok:bool,arguments:array<string,mixed>,message:string}
 */
function editorial_handoff_upsert_arguments(array $settings, array $metadata, array $managedRow): array
{
    $parameters = editorial_composio_schema_parameters($metadata);
    if ($parameters === []) {
        return ['ok' => false, 'arguments' => [], 'message' => 'Tool schema UPSERT_ROWS không công bố input parameters.'];
    }
    $arguments = [];
    $requiredFields = [
        ['exact' => ['spreadsheet id'], 'terms' => ['spreadsheet', 'id'], 'value' => null, 'setting' => 'spreadsheet'],
        ['exact' => ['sheet name', 'tab name', 'sheet', 'tab'], 'terms' => ['sheet'], 'value' => null, 'setting' => 'sheet'],
        ['exact' => ['rows', 'data', 'values'], 'terms' => ['row'], 'value' => null, 'setting' => 'rows'],
    ];
    $rowField = '';
    foreach ($requiredFields as $spec) {
        $field = editorial_handoff_select_parameter($metadata, $spec['exact'], $spec['terms']);
        if (!$field['ok']) {
            return ['ok' => false, 'arguments' => [], 'message' => 'Không thể map schema UPSERT_ROWS.'];
        }
        $fieldName = (string) $field['field'];
        $value = $spec['value'];
        if ($spec['setting'] === 'spreadsheet') {
            $value = (string) $settings['spreadsheet_id'];
        } elseif ($spec['setting'] === 'sheet') {
            $value = (string) $settings['sheet_name'];
        } elseif ($spec['setting'] === 'rows') {
            $rowField = $fieldName;
            continue;
        }
        $arguments[$fieldName] = $value;
    }
    if ($rowField === '') {
        return ['ok' => false, 'arguments' => [], 'message' => 'Không thể map dữ liệu rows theo schema UPSERT_ROWS.'];
    }

    $shape = editorial_handoff_rows_shape((array) ($parameters[$rowField]['schema'] ?? []));
    if ($shape === 'object') {
        $arguments[$rowField] = [$managedRow];
    } elseif ($shape === 'array') {
        $arguments[$rowField] = [array_values($managedRow)];
    } else {
        return ['ok' => false, 'arguments' => [], 'message' => 'Không thể xác định row shape an toàn theo schema UPSERT_ROWS.'];
    }

    $keyColumn = editorial_handoff_select_parameter($metadata, ['key column', 'key field', 'key'], ['key']);
    if (!$keyColumn['ok']) {
        return ['ok' => false, 'arguments' => [], 'message' => 'Schema UPSERT_ROWS không công bố key column để dùng Article ID.'];
    }
    $keySchema = (array) ($parameters[(string) $keyColumn['field']]['schema'] ?? []);
    if (($keySchema['type'] ?? 'string') !== 'string') {
        return ['ok' => false, 'arguments' => [], 'message' => 'Key column của schema UPSERT_ROWS không có kiểu string an toàn.'];
    }
    $arguments[(string) $keyColumn['field']] = 'Article ID';
    $headers = editorial_handoff_select_parameter($metadata, ['headers', 'header'], ['header']);
    if ($headers['ok']) {
        $headerSchema = (array) ($parameters[(string) $headers['field']]['schema'] ?? []);
        if (($headerSchema['type'] ?? '') === 'array') {
            $arguments[(string) $headers['field']] = EDITORIAL_HANDOFF_HEADERS;
        }
    }
    $strict = editorial_handoff_select_parameter($metadata, ['strict', 'strict mode'], ['strict']);
    if ($strict['ok']) {
        $schema = (array) ($parameters[(string) $strict['field']]['schema'] ?? []);
        if (($schema['type'] ?? '') === 'boolean') {
            $arguments[(string) $strict['field']] = true;
        }
    }

    $validation = editorial_composio_validate_required_arguments($metadata, $arguments);
    return $validation['ok']
        ? ['ok' => true, 'arguments' => $arguments, 'message' => '']
        : ['ok' => false, 'arguments' => [], 'message' => $validation['error']];
}

/**
 * @return array{ok:bool,message:string}
 */
function editorial_handoff_upsert_sheet(array $settings, string $version, array $metadata, array $managedRow): array
{
    $arguments = editorial_handoff_upsert_arguments($settings, $metadata, $managedRow);
    if (!$arguments['ok']) {
        return ['ok' => false, 'message' => $arguments['message']];
    }
    $response = editorial_composio_execute(
        EDITORIAL_HANDOFF_UPSERT_ROWS,
        (string) $settings['connected_account_id'],
        (string) $settings['connected_user_id'],
        $version,
        $arguments['arguments']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể cập nhật Google Sheet', $response)];
    }
    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok:bool,message:string}
 */
function editorial_handoff_verify_sheet_write(array $matrix, array $metadata): array
{
    $preflight = editorial_handoff_sheet_preflight($matrix, (string) $metadata['Article ID']);
    if (!$preflight['ok'] || (int) ($preflight['count'] ?? 0) !== 1) {
        return ['ok' => false, 'message' => 'Google Sheet không đạt kiểm tra sau đồng bộ.'];
    }
    $row = $preflight['rows'][0] ?? [];
    if (trim((string) ($row[9] ?? '')) !== (string) $metadata['Published Revision']
        || trim((string) ($row[7] ?? '')) !== (string) $metadata['HTML Archive']) {
        return ['ok' => false, 'message' => 'Google Sheet không đạt kiểm tra sau đồng bộ.'];
    }
    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok:bool,message:string,code:string}
 */
function editorial_handoff_fail(array $sync, string $articleId, string $actorUserId, string $code, string $message): array
{
    $safeMessage = editorial_handoff_safe_error($message);
    if (!empty($sync['id'])) {
        $fields = ['last_error' => $safeMessage];
        // A Drive success is durable evidence that this source owns the shared
        // canonical file bytes. Preserve drive_uploaded when only Sheet or a
        // later step fails so retrying this same source can safely skip Drive.
        if ((string) ($sync['sync_status'] ?? '') !== 'drive_uploaded') {
            $fields['sync_status'] = 'failed';
        }
        editorial_handoff_update_sync((string) $sync['id'], $fields, false);
    }
    editorial_handoff_log('handoff.failed', $articleId, $actorUserId, [
        'article_id' => $articleId,
        'source_key' => (string) ($sync['source_key'] ?? ''),
        'source_revision_id' => (string) ($sync['source_revision_id'] ?? ''),
        'source_kind' => (string) ($sync['source_kind'] ?? ''),
        'published_revision_id' => (string) ($sync['published_revision_id'] ?? ''),
        'drive_file_id' => (string) ($sync['drive_file_id'] ?? ''),
        'sync_status' => (string) ($sync['sync_status'] ?? '') === 'drive_uploaded'
            ? 'drive_uploaded'
            : 'failed',
        'code' => $code,
    ]);
    return ['ok' => false, 'code' => $code, 'message' => $safeMessage];
}

/**
 * Perform an idempotent handoff for the current server-resolved source.
 *
 * @return array{ok:bool,code:string,message:string}
 */
function editorial_handoff_article(string $articleId, string $note, array $actor): array
{
    $lockDirectory = EDITORIAL_STORAGE_PATH . '/handoff-locks';
    if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
        return ['ok' => false, 'code' => 'handoff_lock_storage_failed', 'message' => 'Không thể chuẩn bị khóa bàn giao Google an toàn.'];
    }
    // Serialize all handoffs for an article so two concurrent requests cannot
    // create duplicate archives for the same server-resolved source.
    $lockPath = $lockDirectory . '/' . hash('sha256', $articleId) . '.lock';
    $handle = @fopen($lockPath, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return ['ok' => false, 'code' => 'handoff_in_progress', 'message' => 'Bài viết đang được bàn giao Google bởi một thao tác khác. Vui lòng chờ rồi thử lại.'];
    }
    try {
        return editorial_handoff_article_locked($articleId, $note, $actor);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * The caller holds a per-article handoff lock across external calls.
 *
 * @return array{ok:bool,code:string,message:string}
 */
function editorial_handoff_article_locked(string $articleId, string $note, array $actor): array
{
    $actorId = (string) ($actor['user_id'] ?? '');
    $actorRow = $actorId !== '' ? editorial_find_user_by_id($actorId) : null;
    if (!$actorRow || empty($actorRow['is_active'])) {
        return ['ok' => false, 'code' => 'invalid_actor', 'message' => 'Người dùng không hợp lệ hoặc không còn hoạt động.'];
    }
    $actorRole = (string) ($actorRow['role'] ?? '');
    $article = editorial_find_article($articleId);
    if ($article === null) {
        return ['ok' => false, 'code' => 'article_not_found', 'message' => 'Không tìm thấy bài viết.'];
    }
    if ($actorRole !== 'admin' && !editorial_handoff_user_can_sync($articleId, $actor)) {
        return ['ok' => false, 'code' => 'not_contributor', 'message' => 'Bạn không có quyền bàn giao bài viết này.'];
    }
    $state = editorial_get_article_state($articleId);
    $settingsResult = editorial_handoff_verified_settings();
    if (!$settingsResult['ok']) {
        return [
            'ok' => false,
            'code' => 'handoff_unverified',
            'message' => $actorRole === 'admin' ? 'Google Handoff cần được kiểm tra lại.' : 'Google Handoff chưa sẵn sàng.',
        ];
    }
    $settings = $settingsResult['settings'];
    $note = trim($note);
    if (mb_strlen($note) > 2000) {
        return ['ok' => false, 'code' => 'note_too_long', 'message' => 'Ghi chú bàn giao tối đa 2000 ký tự.'];
    }
    $sourceResult = editorial_handoff_resolve_source($article, $state);
    if (!$sourceResult['ok']) {
        return ['ok' => false, 'code' => 'source_unavailable', 'message' => $sourceResult['message']];
    }
    $source = $sourceResult['source'];
    $html = (string) $source['html'];
    $sourceKey = (string) $source['source_key'];
    $sourceRevisionId = (string) ($source['source_revision_id'] ?? '');
    $sourceKind = (string) $source['source_kind'];
    $publishedRevisionId = (string) ($source['published_revision_id'] ?? '');
    $sourceIdentifier = (string) $source['source_identifier'];

    $sync = editorial_handoff_ensure_sync($articleId, $source);
    editorial_handoff_log('handoff.started', $articleId, $actorId, [
        'article_id' => $articleId,
        'source_key' => $sourceKey,
        'source_revision_id' => $sourceRevisionId,
        'source_kind' => $sourceKind,
        'published_revision_id' => $publishedRevisionId,
        'drive_file_id' => (string) ($sync['drive_file_id'] ?? ''),
        'sync_status' => (string) ($sync['sync_status'] ?? 'pending'),
    ]);

    $canonicalDrive = editorial_handoff_get_canonical_drive_file($articleId);
    $driveTool = $canonicalDrive === null
        ? EDITORIAL_HANDOFF_CREATE_FILE
        : EDITORIAL_HANDOFF_EDIT_FILE;
    foreach ([EDITORIAL_HANDOFF_VALUES_GET, $driveTool, EDITORIAL_HANDOFF_UPSERT_ROWS] as $toolSlug) {
        $schema = editorial_handoff_load_pinned_tool_schema($toolSlug, (string) $settings['pinned_toolkit_version']);
        if (!$schema['ok']) {
            return editorial_handoff_fail($sync, $articleId, $actorId, 'schema_unavailable', $schema['message']);
        }
        $schemas[$toolSlug] = $schema['metadata'];
    }

    $sheetRead = editorial_handoff_values_get($settings, (string) $settings['pinned_toolkit_version'], $schemas[EDITORIAL_HANDOFF_VALUES_GET]);
    if (!$sheetRead['ok']) {
        return editorial_handoff_fail($sync, $articleId, $actorId, 'sheet_preflight_read_failed', $sheetRead['message']);
    }
    $preflight = editorial_handoff_sheet_preflight($sheetRead['matrix'], $articleId);
    if (!$preflight['ok']) {
        $code = (int) ($preflight['count'] ?? 0) >= 2 ? 'sheet_duplicate_article_id' : 'sheet_preflight_invalid';
        editorial_handoff_log(
            $code === 'sheet_duplicate_article_id' ? 'handoff.sheet_duplicate_article_id' : 'handoff.sheet_preflight',
            $articleId,
            $actorId,
            [
                'article_id' => $articleId,
                'source_key' => $sourceKey,
                'source_revision_id' => $sourceRevisionId,
                'source_kind' => $sourceKind,
                'published_revision_id' => $publishedRevisionId,
                'sheet_match_count' => (int) ($preflight['count'] ?? 0),
                'sync_status' => 'failed',
                'code' => $code,
            ]
        );
        return editorial_handoff_fail($sync, $articleId, $actorId, $code, $preflight['message']);
    }
    editorial_handoff_log('handoff.sheet_preflight', $articleId, $actorId, [
        'article_id' => $articleId,
        'source_key' => $sourceKey,
        'source_revision_id' => $sourceRevisionId,
        'source_kind' => $sourceKind,
        'published_revision_id' => $publishedRevisionId,
        'sheet_match_count' => (int) $preflight['count'],
        'intent' => (string) $preflight['intent'],
        'sync_status' => (string) ($sync['sync_status'] ?? 'pending'),
    ]);

    $driveSkipped = false;
    if ($canonicalDrive !== null) {
        $driveId = trim((string) ($canonicalDrive['drive_file_id'] ?? ''));
        $driveUrl = trim((string) ($canonicalDrive['drive_file_url'] ?? ''));
        $latestDriveSourceKey = editorial_handoff_get_drive_content_source_key($articleId, $driveId);
        if ($latestDriveSourceKey === $sourceKey) {
            $driveSkipped = true;
            if ($driveUrl === '') {
                $driveUrl = 'https://drive.google.com/file/d/' . rawurlencode($driveId) . '/view';
            }
        } else {
            $drive = editorial_handoff_edit_html_file(
                $settings,
                (string) $settings['pinned_toolkit_version'],
                $schemas[EDITORIAL_HANDOFF_EDIT_FILE],
                $driveId,
                $html
            );
            if (!$drive['ok']) {
                return editorial_handoff_fail(
                    $sync,
                    $articleId,
                    $actorId,
                    'drive_edit_failed',
                    'Không thể cập nhật file HTML Drive hiện có. Không tạo file mới để tránh trùng hồ sơ.'
                );
            }
            $driveId = (string) $drive['file_id'];
            $driveUrl = (string) $drive['file_url'];
        }
    } else {
        try {
            $filename = editorial_handoff_archive_filename($articleId);
        } catch (RuntimeException $e) {
            return editorial_handoff_fail($sync, $articleId, $actorId, 'archive_filename_invalid', $e->getMessage());
        }
        $drive = editorial_handoff_create_html_archive(
            $settings,
            (string) $settings['pinned_toolkit_version'],
            $schemas[EDITORIAL_HANDOFF_CREATE_FILE],
            $filename,
            $html
        );
        if (!$drive['ok']) {
            return editorial_handoff_fail($sync, $articleId, $actorId, 'drive_upload_failed', $drive['message']);
        }
        $driveId = (string) $drive['file_id'];
        $driveUrl = (string) $drive['file_url'];
    }

    if (!$driveSkipped) {
        editorial_handoff_update_sync((string) $sync['id'], [
            'drive_file_id' => $driveId,
            'drive_file_url' => $driveUrl,
            'handoff_note' => $note,
            'sync_status' => 'drive_uploaded',
            'last_error' => null,
        ]);
        $sync = editorial_handoff_get_sync($articleId, $sourceKey) ?? $sync;
        editorial_handoff_log('handoff.drive_uploaded', $articleId, $actorId, [
            'article_id' => $articleId,
            'source_key' => $sourceKey,
            'source_revision_id' => $sourceRevisionId,
            'source_kind' => $sourceKind,
            'published_revision_id' => $publishedRevisionId,
            'drive_file_id' => $driveId,
            'sync_status' => 'drive_uploaded',
        ]);
    }
    $sheetSyncAt = date('c');
    $metadataResult = editorial_handoff_build_metadata(
        $article,
        $html,
        (string) $settings['public_base_url'],
        $sourceIdentifier,
        $note,
        date('d/m/Y H:i', strtotime($sheetSyncAt))
    );
    if (!$metadataResult['ok']) {
        return editorial_handoff_fail($sync, $articleId, $actorId, 'metadata_failed', $metadataResult['message']);
    }
    $metadata = $metadataResult['metadata'];
    $metadata['HTML Archive'] = $driveUrl;

    $upsert = editorial_handoff_upsert_sheet(
        $settings,
        (string) $settings['pinned_toolkit_version'],
        $schemas[EDITORIAL_HANDOFF_UPSERT_ROWS],
        $metadata
    );
    if (!$upsert['ok']) {
        $message = $driveSkipped
            ? 'HTML Drive hiện đã khớp Chặng này nhưng Google Sheet chưa cập nhật. ' . $upsert['message']
            : 'HTML đã được cập nhật lên Google Drive nhưng Google Sheet chưa cập nhật. Bạn có thể thử lại mà không tạo file HTML trùng.';
        return editorial_handoff_fail($sync, $articleId, $actorId, 'sheet_upsert_failed', $message);
    }
    editorial_handoff_log('handoff.sheet_upserted', $articleId, $actorId, [
        'article_id' => $articleId,
        'source_key' => $sourceKey,
        'source_revision_id' => $sourceRevisionId,
        'source_kind' => $sourceKind,
        'published_revision_id' => $publishedRevisionId,
        'drive_file_id' => $driveId,
        'sheet_match_count' => (int) $preflight['count'],
        'intent' => (string) $preflight['intent'],
        'sync_status' => 'drive_uploaded',
    ]);

    $postRead = editorial_handoff_values_get($settings, (string) $settings['pinned_toolkit_version'], $schemas[EDITORIAL_HANDOFF_VALUES_GET]);
    if (!$postRead['ok']) {
        return editorial_handoff_fail($sync, $articleId, $actorId, 'sheet_post_read_failed', 'Google Sheet không đạt kiểm tra sau đồng bộ.');
    }
    $postVerify = editorial_handoff_verify_sheet_write($postRead['matrix'], $metadata);
    if (!$postVerify['ok']) {
        return editorial_handoff_fail($sync, $articleId, $actorId, 'sheet_post_verify_failed', $postVerify['message']);
    }

    editorial_handoff_update_sync((string) $sync['id'], [
        'drive_file_id' => $driveId,
        'drive_file_url' => $driveUrl,
        'handoff_note' => $note,
        'sheet_synced_at' => $sheetSyncAt,
        'synced_by' => $actorId,
        'sync_status' => 'synced',
        'last_error' => null,
    ]);
    editorial_handoff_log('handoff.sheet_verified', $articleId, $actorId, [
        'article_id' => $articleId,
        'source_key' => $sourceKey,
        'source_revision_id' => $sourceRevisionId,
        'source_kind' => $sourceKind,
        'published_revision_id' => $publishedRevisionId,
        'drive_file_id' => $driveId,
        'sheet_match_count' => 1,
        'sync_status' => 'synced',
    ]);

    if ($driveSkipped) {
        $message = 'Đã cập nhật Google Sheet thành công; HTML Drive hiện đã khớp Chặng đang chọn.';
    } elseif (($preflight['intent'] ?? '') === 'UPDATE_EXISTING') {
        $message = 'Đã lưu HTML lên Google Drive và cập nhật dòng bài hiện có trong Google Sheet.';
    } else {
        $message = 'Đã lưu HTML lên Google Drive và thêm bài vào Google Sheet.';
    }
    return ['ok' => true, 'code' => 'synced', 'message' => $message];
}
