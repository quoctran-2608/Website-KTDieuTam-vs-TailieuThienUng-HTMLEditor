<?php
declare(strict_types=1);

/**
 * Editorial V2 — Google Handoff service.
 *
 * Handoff is deliberately independent from Safe Publish: it reads exact
 * published bytes, archives them externally, and never mutates public HTML,
 * catalog data, taxonomy, article workflow, revisions, or assignments.
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
const EDITORIAL_HANDOFF_UPSERT_ROWS = 'GOOGLESUPER_UPSERT_ROWS';

/**
 * @return array<string,mixed>|null
 */
function editorial_handoff_get_sync(string $articleId, string $publishedRevisionId): ?array
{
    $stmt = editorial_db()->prepare('
        SELECT * FROM editorial_handoff_sync
        WHERE article_id = :article_id AND published_revision_id = :revision_id
    ');
    $stmt->execute(['article_id' => $articleId, 'revision_id' => $publishedRevisionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Batch-load sync state for displayed current published revisions.
 *
 * @param array<string,array<string,mixed>> $states
 * @return array<string,array<string,mixed>>
 */
function editorial_get_handoff_sync_for_published_states(array $states): array
{
    $revisionIds = [];
    $articleByRevision = [];
    foreach ($states as $articleId => $state) {
        if (($state['status'] ?? '') !== 'published') {
            continue;
        }
        $revisionId = trim((string) ($state['published_revision_id'] ?? ''));
        if ($revisionId === '') {
            continue;
        }
        $revisionIds[] = $revisionId;
        $articleByRevision[$revisionId] = (string) $articleId;
    }
    if ($revisionIds === []) {
        return [];
    }

    $revisionIds = array_values(array_unique($revisionIds));
    $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
    $stmt = editorial_db()->prepare("
        SELECT * FROM editorial_handoff_sync
        WHERE published_revision_id IN ($placeholders)
    ");
    $stmt->execute($revisionIds);

    $result = [];
    while ($row = $stmt->fetch()) {
        $revisionId = (string) ($row['published_revision_id'] ?? '');
        $articleId = (string) ($row['article_id'] ?? '');
        if ($revisionId !== '' && ($articleByRevision[$revisionId] ?? '') === $articleId) {
            $result[$articleId] = $row;
        }
    }
    return $result;
}

/**
 * @return array<string,mixed>
 */
function editorial_handoff_ensure_sync(string $articleId, string $publishedRevisionId): array
{
    return editorial_transaction(function () use ($articleId, $publishedRevisionId): array {
        $db = editorial_db();
        $now = date('c');
        $stmt = $db->prepare('
            INSERT OR IGNORE INTO editorial_handoff_sync
            (id, article_id, published_revision_id, sync_status, created_at, updated_at)
            VALUES (:id, :article_id, :revision_id, \'pending\', :created_at, :updated_at)
        ');
        $stmt->execute([
            'id' => editorial_generate_id('handoff'),
            'article_id' => $articleId,
            'revision_id' => $publishedRevisionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sync = editorial_handoff_get_sync($articleId, $publishedRevisionId);
        if ($sync === null) {
            throw new RuntimeException('Không thể tạo trạng thái bàn giao Google.');
        }
        return $sync;
    });
}

function editorial_handoff_update_sync(string $syncId, array $fields): void
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
    $params = ['id' => $syncId, 'updated_at' => date('c')];
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
    $sets[] = 'updated_at = :updated_at';
    $stmt = editorial_db()->prepare('UPDATE editorial_handoff_sync SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($params);
}

function editorial_handoff_safe_error(string $message): string
{
    return mb_strimwidth(trim($message), 0, 500, '…');
}

function editorial_handoff_log(string $event, string $articleId, string $actorUserId, array $payload = []): void
{
    $safe = array_intersect_key($payload, array_flip([
        'article_id',
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
    $settings = editorial_handoff_settings(true);
    if (($settings['last_verify_status'] ?? '') !== 'verified'
        || trim((string) ($settings['api_key'] ?? '')) === ''
        || trim((string) ($settings['pinned_toolkit_version'] ?? '')) === ''
        || trim((string) ($settings['connected_user_id'] ?? '')) === ''
        || trim((string) ($settings['connected_account_id'] ?? '')) === ''
        || trim((string) ($settings['drive_folder_id'] ?? '')) === ''
        || trim((string) ($settings['spreadsheet_id'] ?? '')) === ''
        || trim((string) ($settings['sheet_name'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'Google Handoff cần được kiểm tra lại.'];
    }
    $base = editorial_handoff_normalize_public_base_url((string) ($settings['public_base_url'] ?? ''));
    if (empty($base['ok']) || trim((string) ($base['value'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'Google Handoff cần Public Base URL hợp lệ và đã được kiểm tra lại.'];
    }
    $settings['public_base_url'] = (string) $base['value'];
    return ['ok' => true, 'message' => 'Cấu hình Google Handoff hợp lệ.', 'settings' => $settings];
}

function editorial_handoff_user_can_sync(string $articleId, array $actor): bool
{
    if (($actor['role'] ?? '') === 'admin') {
        return true;
    }
    $actorId = (string) ($actor['user_id'] ?? '');
    if ($actorId === '') {
        return false;
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

function editorial_handoff_archive_filename(string $articleId, string $revisionId): string
{
    $safeArticle = preg_replace('/[^A-Za-z0-9._-]+/', '-', $articleId) ?? '';
    $safeArticle = trim($safeArticle, '.-');
    $safeRevision = preg_replace('/[^A-Za-z0-9._-]+/', '-', $revisionId) ?? '';
    $safeRevision = trim($safeRevision, '.-');
    if ($safeArticle === '' || $safeRevision === '') {
        throw new RuntimeException('Không thể tạo tên archive an toàn.');
    }
    return $safeArticle . '__' . $safeRevision . '.html';
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
function editorial_handoff_build_metadata(array $article, string $html, string $publicBaseUrl, string $publishedRevisionId, string $note, string $handoffDate): array
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
    $contributors = editorial_get_article_contributors([(string) $article['id'])[(string) $article['id']] ?? [];
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
            'Published Revision' => $publishedRevisionId,
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
        editorial_handoff_update_sync((string) $sync['id'], [
            'sync_status' => 'failed',
            'last_error' => $safeMessage,
        ]);
    }
    editorial_handoff_log('handoff.failed', $articleId, $actorUserId, [
        'article_id' => $articleId,
        'published_revision_id' => (string) ($sync['published_revision_id'] ?? ''),
        'drive_file_id' => (string) ($sync['drive_file_id'] ?? ''),
        'sync_status' => 'failed',
        'code' => $code,
    ]);
    return ['ok' => false, 'code' => $code, 'message' => $safeMessage];
}

/**
 * Perform an idempotent handoff for the current published revision only.
 *
 * @return array{ok:bool,code:string,message:string}
 */
function editorial_handoff_article(string $articleId, string $note, array $actor): array
{
    $lockDirectory = EDITORIAL_STORAGE_PATH . '/handoff-locks';
    if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
        return ['ok' => false, 'code' => 'handoff_lock_storage_failed', 'message' => 'Không thể chuẩn bị khóa bàn giao Google an toàn.'];
    }
    // Serialize all handoffs for an article. A new published revision cannot
    // appear without a separate Safe Publish, so this safely covers its
    // article+revision sync identity while preventing double-click archives.
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
 * The caller holds a per article/revision handoff lock across external calls.
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
    $publishedRevisionId = trim((string) ($state['published_revision_id'] ?? ''));
    $publishedHash = trim((string) ($state['published_live_hash'] ?? ''));
    if (!$state || ($state['status'] ?? '') !== 'published' || $publishedRevisionId === '' || $publishedHash === '') {
        return ['ok' => false, 'code' => 'not_published', 'message' => 'Chỉ có thể bàn giao bài đã Publish hợp lệ.'];
    }
    $publishedRevision = editorial_get_revision($publishedRevisionId);
    if (!$publishedRevision
        || (string) ($publishedRevision['article_id'] ?? '') !== $articleId
        || (string) ($publishedRevision['revision_type'] ?? '') !== 'published') {
        return ['ok' => false, 'code' => 'published_revision_invalid', 'message' => 'Published revision không hợp lệ để bàn giao.'];
    }
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
    $htmlPath = editorial_resolve_article_path($article);
    if ($htmlPath === null) {
        return ['ok' => false, 'code' => 'live_file_missing', 'message' => 'Không tìm thấy HTML public để bàn giao.'];
    }
    $html = file_get_contents($htmlPath);
    if ($html === false || $html === '') {
        return ['ok' => false, 'code' => 'live_html_empty', 'message' => 'Không thể đọc HTML public để bàn giao.'];
    }
    if (hash('sha256', $html) !== $publishedHash) {
        return ['ok' => false, 'code' => 'live_hash_mismatch', 'message' => 'Nội dung public hiện tại không còn khớp bản Publish đã ghi nhận. Vui lòng kiểm tra lại trước khi lưu hồ sơ.'];
    }

    $sync = editorial_handoff_ensure_sync($articleId, $publishedRevisionId);
    editorial_handoff_log('handoff.started', $articleId, $actorId, [
        'article_id' => $articleId,
        'published_revision_id' => $publishedRevisionId,
        'drive_file_id' => (string) ($sync['drive_file_id'] ?? ''),
        'sync_status' => (string) ($sync['sync_status'] ?? 'pending'),
    ]);

    foreach ([EDITORIAL_HANDOFF_VALUES_GET, EDITORIAL_HANDOFF_CREATE_FILE, EDITORIAL_HANDOFF_UPSERT_ROWS] as $toolSlug) {
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
        'published_revision_id' => $publishedRevisionId,
        'sheet_match_count' => (int) $preflight['count'],
        'intent' => (string) $preflight['intent'],
        'sync_status' => (string) ($sync['sync_status'] ?? 'pending'),
    ]);

    $driveReused = trim((string) ($sync['drive_file_id'] ?? '')) !== '';
    if ($driveReused) {
        $driveId = (string) $sync['drive_file_id'];
        $driveUrl = trim((string) ($sync['drive_file_url'] ?? ''));
        if ($driveUrl === '') {
            $driveUrl = 'https://drive.google.com/file/d/' . rawurlencode($driveId) . '/view';
        }
    } else {
        try {
            $filename = editorial_handoff_archive_filename($articleId, $publishedRevisionId);
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
        editorial_handoff_update_sync((string) $sync['id'], [
            'drive_file_id' => $driveId,
            'drive_file_url' => $driveUrl,
            'handoff_note' => $note,
            'sync_status' => 'drive_uploaded',
            'last_error' => null,
        ]);
        $sync = editorial_handoff_get_sync($articleId, $publishedRevisionId) ?? $sync;
        editorial_handoff_log('handoff.drive_uploaded', $articleId, $actorId, [
            'article_id' => $articleId,
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
        $publishedRevisionId,
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
        $message = $driveReused
            ? 'HTML archive hiện có được giữ nguyên nhưng Google Sheet chưa cập nhật. ' . $upsert['message']
            : 'HTML đã được lưu lên Google Drive nhưng Google Sheet chưa cập nhật. Bạn có thể thử lại mà không tạo file HTML trùng.';
        return editorial_handoff_fail($sync, $articleId, $actorId, 'sheet_upsert_failed', $message);
    }
    editorial_handoff_log('handoff.sheet_upserted', $articleId, $actorId, [
        'article_id' => $articleId,
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
        'published_revision_id' => $publishedRevisionId,
        'drive_file_id' => $driveId,
        'sheet_match_count' => 1,
        'sync_status' => 'synced',
    ]);

    if ($driveReused) {
        $message = 'Đã cập nhật Google Sheet thành công; HTML archive hiện có được giữ nguyên.';
    } elseif (($preflight['intent'] ?? '') === 'UPDATE_EXISTING') {
        $message = 'Đã lưu HTML lên Google Drive và cập nhật dòng bài hiện có trong Google Sheet.';
    } else {
        $message = 'Đã lưu HTML lên Google Drive và thêm bài vào Google Sheet.';
    }
    return ['ok' => true, 'code' => 'synced', 'message' => $message];
}
