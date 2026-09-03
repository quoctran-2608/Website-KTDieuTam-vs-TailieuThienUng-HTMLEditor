<?php
declare(strict_types=1);

/**
 * Editorial V2 — minimal Composio v3.1 client and Google Handoff verifier.
 *
 * This module never performs network work at include time. It is invoked only
 * by the admin-only verification action and never logs the Project API key.
 */

const EDITORIAL_COMPOSIO_API_BASE = 'https://backend.composio.dev/api/v3.1';
const EDITORIAL_GOOGLE_SUPER_TOOLKIT = 'googlesuper';
const EDITORIAL_GOOGLE_HANDOFF_TOOLS = [
    'GOOGLESUPER_CREATE_FILE_FROM_TEXT',
    'GOOGLESUPER_UPSERT_ROWS',
    'GOOGLESUPER_GET_FILE_METADATA',
    'GOOGLESUPER_GET_SPREADSHEET_INFO',
    'GOOGLESUPER_GET_SHEET_NAMES',
];

/**
 * @return array{ok:bool,http_status:int,json:?array,error:string}
 */
function editorial_composio_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    if (!extension_loaded('curl') || !function_exists('curl_init')) {
        return ['ok' => false, 'http_status' => 0, 'json' => null, 'error' => 'PHP cURL chưa khả dụng trên server.'];
    }

    $apiKey = (string) (editorial_setting_get('composio_api_key', '') ?? '');
    if ($apiKey === '') {
        return ['ok' => false, 'http_status' => 0, 'json' => null, 'error' => 'Chưa lưu Composio API Key.'];
    }

    $url = rtrim(EDITORIAL_COMPOSIO_API_BASE, '/') . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ['ok' => false, 'http_status' => 0, 'json' => null, 'error' => 'Không thể mã hóa yêu cầu Composio.'];
        }
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'http_status' => 0, 'json' => null, 'error' => 'Không thể khởi tạo kết nối Composio.'];
    }
    $headers = [
        'Accept: application/json',
        'x-api-key: ' . $apiKey,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'KetoanDieuTam-Editorial/12A',
    ]);
    if ($payload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    }

    $response = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($response === false) {
        return ['ok' => false, 'http_status' => $httpStatus, 'json' => null, 'error' => 'Không thể kết nối Composio: ' . ($curlError !== '' ? $curlError : 'lỗi không xác định.')];
    }

    $json = json_decode((string) $response, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http_status' => $httpStatus, 'json' => null, 'error' => 'Composio trả về dữ liệu không phải JSON hợp lệ.'];
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['ok' => false, 'http_status' => $httpStatus, 'json' => $json, 'error' => editorial_composio_safe_error($json, 'Composio trả về HTTP ' . $httpStatus . '.')];
    }
    return ['ok' => true, 'http_status' => $httpStatus, 'json' => $json, 'error' => ''];
}

/**
 * @return array{ok:bool,http_status:int,json:?array,error:string}
 */
function editorial_composio_get_connected_account(string $connectedAccountId): array
{
    return editorial_composio_request('GET', '/connected_accounts/' . rawurlencode($connectedAccountId));
}

/**
 * @return array{ok:bool,http_status:int,json:?array,error:string}
 */
function editorial_composio_get_tool(string $toolSlug, ?string $version = null): array
{
    $query = $version !== null && $version !== '' ? ['version' => $version] : [];
    return editorial_composio_request('GET', '/tools/' . rawurlencode($toolSlug), $query);
}

/**
 * @return array{ok:bool,http_status:int,json:?array,error:string,log_id:string}
 */
function editorial_composio_execute(string $toolSlug, string $connectedAccountId, string $version, array $arguments): array
{
    $response = editorial_composio_request('POST', '/tools/execute/' . rawurlencode($toolSlug), [], [
        'connected_account_id' => $connectedAccountId,
        'version' => $version,
        'arguments' => $arguments,
    ]);
    $json = $response['json'];
    $logId = is_array($json) ? (string) ($json['log_id'] ?? ($json['data']['log_id'] ?? '')) : '';
    if (!$response['ok']) {
        return $response + ['log_id' => $logId];
    }
    if (($json['successful'] ?? false) !== true) {
        return [
            'ok' => false,
            'http_status' => $response['http_status'],
            'json' => $json,
            'error' => editorial_composio_safe_error($json, 'Composio không thực thi tool thành công.'),
            'log_id' => $logId,
        ];
    }
    return $response + ['log_id' => $logId];
}

function editorial_composio_safe_error(array $json, string $fallback): string
{
    $value = $json['error'] ?? ($json['message'] ?? ($json['detail'] ?? ''));
    if (is_array($value)) {
        $value = $value['message'] ?? '';
    }
    $value = trim((string) $value);
    $apiKey = (string) (editorial_setting_get('composio_api_key', '') ?? '');
    if ($apiKey !== '') {
        $value = str_replace($apiKey, '[redacted]', $value);
    }
    if (preg_match('/(?:oauth|token|credential|authorization|secret|api[_ -]?key)/i', $value)) {
        return 'Composio trả về lỗi xác thực hoặc quyền truy cập.';
    }
    return $value !== '' ? mb_strimwidth($value, 0, 300, '…') : $fallback;
}

function editorial_composio_execution_error(string $prefix, array $response): string
{
    $message = $prefix . ': ' . (string) ($response['error'] ?? 'Lỗi không xác định.');
    $logId = trim((string) ($response['log_id'] ?? ''));
    if ($logId !== '' && preg_match('/^[A-Za-z0-9_-]{1,160}$/', $logId)) {
        $message .= ' (Composio log: ' . $logId . ')';
    }
    return $message;
}

/**
 * Tool responses can wrap metadata differently across catalog versions.
 *
 * @return array<string,mixed>
 */
function editorial_composio_tool_metadata(array $json): array
{
    foreach (['data', 'tool', 'item'] as $key) {
        if (isset($json[$key]) && is_array($json[$key])) {
            return $json[$key];
        }
    }
    return $json;
}

function editorial_composio_find_concrete_version(mixed $value): string
{
    if (is_array($value)) {
        foreach (['version', 'toolkit_version', 'toolkitVersion'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])
                && preg_match('/^\d{8}_\d{2}$/', $value[$key])) {
                return $value[$key];
            }
        }
        foreach ($value as $child) {
            $found = editorial_composio_find_concrete_version($child);
            if ($found !== '') {
                return $found;
            }
        }
    }
    return '';
}

function editorial_composio_metadata_toolkit_slug(array $metadata): string
{
    $toolkit = $metadata['toolkit'] ?? ($metadata['toolkit_info'] ?? []);
    if (is_array($toolkit)) {
        return strtolower((string) ($toolkit['slug'] ?? ($toolkit['name'] ?? '')));
    }
    return '';
}

/**
 * @return array<string,array<string,mixed>>
 */
function editorial_composio_schema_properties(array $metadata): array
{
    $candidates = [
        $metadata['input_parameters']['properties'] ?? null,
        $metadata['input_parameters'] ?? null,
        $metadata['input_schema']['properties'] ?? null,
        $metadata['inputSchema']['properties'] ?? null,
        $metadata['parameters']['properties'] ?? null,
        $metadata['input_parameters']['properties'] ?? null,
        $metadata['data']['input_schema']['properties'] ?? null,
    ];
    foreach ($candidates as $properties) {
        if (is_array($properties)) {
            return $properties;
        }
    }
    return [];
}

function editorial_composio_schema_text(string $name, mixed $schema): string
{
    $data = is_array($schema) ? $schema : [];
    return strtolower(str_replace(['_', '-'], ' ', $name . ' ' . (string) ($data['title'] ?? '') . ' ' . (string) ($data['description'] ?? '')));
}

function editorial_composio_schema_matches_term(string $text, string $term): bool
{
    $term = strtolower(str_replace(['_', '-'], ' ', trim($term)));
    if ($term === '') {
        return false;
    }
    return preg_match('/(?:^|[^a-z0-9])' . preg_quote($term, '/') . '(?:$|[^a-z0-9])/i', $text) === 1;
}

/**
 * Resolve an actual top-level schema field by semantic capability—not a
 * hard-coded Composio argument name. Empty result means verification stops.
 */
function editorial_composio_schema_field(array $properties, array $mustContainAny, array $preferContains = []): string
{
    $best = '';
    $bestScore = -1;
    foreach ($properties as $name => $schema) {
        $text = editorial_composio_schema_text((string) $name, $schema);
        $matches = 0;
        foreach ($mustContainAny as $needle) {
            if (editorial_composio_schema_matches_term($text, (string) $needle)) {
                $matches++;
            }
        }
        if ($matches === 0) {
            continue;
        }
        $score = $matches * 10;
        foreach ($preferContains as $needle) {
            if (editorial_composio_schema_matches_term($text, (string) $needle)) {
                $score += 2;
            }
        }
        if ($score > $bestScore) {
            $best = (string) $name;
            $bestScore = $score;
        }
    }
    return $best;
}

/**
 * Map a configured resource ID only after inspecting the actual schema.
 *
 * @return array{ok:bool,arguments:array<string,string>,error:string}
 */
function editorial_composio_readonly_arguments(array $metadata, string $resourceType, string $resourceId): array
{
    $properties = editorial_composio_schema_properties($metadata);
    if ($properties === []) {
        return ['ok' => false, 'arguments' => [], 'error' => 'Tool schema không công bố input properties để xác minh ' . $resourceType . '.'];
    }
    $terms = match ($resourceType) {
        'folder' => ['folder', 'file', 'resource'],
        'spreadsheet', 'sheet_names' => ['spreadsheet'],
        default => [],
    };
    $field = '';
    foreach ($properties as $name => $schema) {
        $text = editorial_composio_schema_text((string) $name, $schema);
        $hasResourceTerm = false;
        foreach ($terms as $term) {
            if (editorial_composio_schema_matches_term($text, (string) $term)) {
                $hasResourceTerm = true;
                break;
            }
        }
        if ($hasResourceTerm && editorial_composio_schema_matches_term($text, 'id')) {
            $field = (string) $name;
            break;
        }
    }
    if ($field === '') {
        return ['ok' => false, 'arguments' => [], 'error' => 'Không thể map ' . $resourceType . ' ID theo schema Composio hiện tại.'];
    }
    return ['ok' => true, 'arguments' => [$field => $resourceId], 'error' => ''];
}

/**
 * @return array<int,string>
 */
function editorial_composio_extract_mime_types(mixed $value): array
{
    $types = [];
    if (!is_array($value)) {
        return $types;
    }
    foreach ($value as $key => $child) {
        if (is_string($child) && in_array(strtolower((string) $key), ['mimetype', 'mime_type', 'type'], true)) {
            $types[] = $child;
        }
        if (is_array($child)) {
            $types = array_merge($types, editorial_composio_extract_mime_types($child));
        }
    }
    return array_values(array_unique($types));
}

function editorial_composio_schema_has_capability(array $metadata, array $terms): bool
{
    $properties = editorial_composio_schema_properties($metadata);
    return editorial_composio_schema_field($properties, $terms) !== '';
}

function editorial_composio_response_contains_id(mixed $value, string $expectedId): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (editorial_composio_response_contains_id($item, $expectedId)) {
                return true;
            }
        }
    }
    return is_string($value) && $value === $expectedId;
}

/**
 * @return array<int,string>
 */
function editorial_composio_extract_sheet_names(mixed $value): array
{
    $names = [];
    if (!is_array($value)) {
        return $names;
    }
    foreach ($value as $key => $child) {
        if (is_string($child) && in_array(strtolower((string) $key), ['name', 'title', 'sheet_name', 'sheetname'], true)) {
            $names[] = $child;
        }
        if (is_int($key) && is_string($child)) {
            $names[] = $child;
        }
        if (is_array($child)) {
            $names = array_merge($names, editorial_composio_extract_sheet_names($child));
        }
    }
    return array_values(array_unique(array_filter($names, static fn(string $name): bool => trim($name) !== '')));
}

/**
 * Full read-only verification. Write tools are schema-inspected only.
 *
 * @return array{ok:bool,message:string,version?:string,steps:array<int,array{label:string,ok:bool,message:string}>}
 */
function editorial_verify_google_handoff(): array
{
    $settings = editorial_handoff_settings(true);
    $steps = [];
    if (!editorial_handoff_settings_is_complete()) {
        return ['ok' => false, 'message' => 'Chưa đủ cấu hình: cần API Key, Connected Account, Drive Folder ID, Spreadsheet ID, Sheet Name và Public Base URL hợp lệ.', 'steps' => $steps];
    }
    if (!extension_loaded('curl') || !function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP cURL chưa khả dụng trên server.', 'steps' => $steps];
    }

    $accountResponse = editorial_composio_get_connected_account($settings['connected_account_id']);
    if (!$accountResponse['ok']) {
        $message = match ($accountResponse['http_status']) {
            401, 403 => 'Không xác thực được Composio API Key hoặc Project permission.',
            404 => 'Không tìm thấy Connected Account ID trong Composio Project này.',
            default => 'Không thể kiểm tra Connected Account: ' . $accountResponse['error'],
        };
        return ['ok' => false, 'message' => $message, 'steps' => $steps];
    }
    $account = $accountResponse['json'] ?? [];
    $accountId = (string) ($account['id'] ?? ($account['data']['id'] ?? ''));
    $toolkit = strtolower((string) ($account['toolkit']['slug'] ?? ($account['data']['toolkit']['slug'] ?? '')));
    $status = strtoupper((string) ($account['status'] ?? ($account['data']['status'] ?? '')));
    $disabled = !empty($account['is_disabled']) || !empty($account['auth_config']['is_disabled'])
        || !empty($account['data']['is_disabled']) || !empty($account['data']['auth_config']['is_disabled']);
    if ($accountId !== $settings['connected_account_id'] || $toolkit !== EDITORIAL_GOOGLE_SUPER_TOOLKIT) {
        return ['ok' => false, 'message' => 'Connected Account phải là tài khoản Google Super đang thuộc Composio Project này.', 'steps' => $steps];
    }
    if ($status !== 'ACTIVE' || $disabled) {
        return ['ok' => false, 'message' => 'Google Super Connected Account chưa ACTIVE (trạng thái: ' . ($status !== '' ? $status : 'không rõ') . ').', 'steps' => $steps];
    }
    $steps[] = ['label' => 'Google Super Connected Account', 'ok' => true, 'message' => 'ACTIVE'];

    $toolMetadata = [];
    $versions = [];
    foreach (EDITORIAL_GOOGLE_HANDOFF_TOOLS as $toolSlug) {
        $toolResponse = editorial_composio_get_tool($toolSlug, 'latest');
        if (!$toolResponse['ok']) {
            return ['ok' => false, 'message' => 'Không tìm thấy hoặc không đọc được schema tool ' . $toolSlug . '.', 'steps' => $steps];
        }
        $metadata = editorial_composio_tool_metadata($toolResponse['json'] ?? []);
        $metadataToolkit = editorial_composio_metadata_toolkit_slug($metadata);
        if ($metadataToolkit !== '' && $metadataToolkit !== EDITORIAL_GOOGLE_SUPER_TOOLKIT) {
            return ['ok' => false, 'message' => 'Tool ' . $toolSlug . ' không thuộc Google Super toolkit.', 'steps' => $steps];
        }
        $version = editorial_composio_find_concrete_version($metadata);
        if ($version === '') {
            return ['ok' => false, 'message' => 'Composio không trả về version concrete cho tool ' . $toolSlug . '.', 'steps' => $steps];
        }
        $toolMetadata[$toolSlug] = $metadata;
        $versions[$version] = true;
    }
    if (count($versions) !== 1) {
        return ['ok' => false, 'message' => 'Các tool Google Super không cùng một toolkit version; không thể pin an toàn.', 'steps' => $steps];
    }
    $version = (string) array_key_first($versions);
    if ($version === 'latest') {
        return ['ok' => false, 'message' => 'Composio chỉ trả về latest, chưa có version concrete để pin.', 'steps' => $steps];
    }

    $createSchema = $toolMetadata['GOOGLESUPER_CREATE_FILE_FROM_TEXT'];
    $upsertSchema = $toolMetadata['GOOGLESUPER_UPSERT_ROWS'];
    foreach ([
        ['schema' => $createSchema, 'terms' => ['file', 'filename', 'name'], 'label' => 'tên file'],
        ['schema' => $createSchema, 'terms' => ['text', 'content'], 'label' => 'nội dung text'],
        ['schema' => $createSchema, 'terms' => ['folder', 'parent'], 'label' => 'đích thư mục'],
        ['schema' => $upsertSchema, 'terms' => ['spreadsheet'], 'label' => 'spreadsheet'],
        ['schema' => $upsertSchema, 'terms' => ['sheet', 'tab'], 'label' => 'sheet/tab'],
        ['schema' => $upsertSchema, 'terms' => ['key', 'column'], 'label' => 'key column'],
        ['schema' => $upsertSchema, 'terms' => ['row', 'data'], 'label' => 'rows/data'],
    ] as $capability) {
        if (!editorial_composio_schema_has_capability($capability['schema'], $capability['terms'])) {
            return ['ok' => false, 'message' => 'Schema tool chưa cho thấy capability cần thiết: ' . $capability['label'] . '.', 'steps' => $steps];
        }
    }
    $steps[] = ['label' => 'Tool schemas', 'ok' => true, 'message' => 'Đã xác minh ' . count(EDITORIAL_GOOGLE_HANDOFF_TOOLS) . ' tool ở version ' . $version . '.'];

    $folderArgs = editorial_composio_readonly_arguments($toolMetadata['GOOGLESUPER_GET_FILE_METADATA'], 'folder', $settings['drive_folder_id']);
    if (!$folderArgs['ok']) {
        return ['ok' => false, 'message' => $folderArgs['error'], 'steps' => $steps];
    }
    $folderResponse = editorial_composio_execute('GOOGLESUPER_GET_FILE_METADATA', $settings['connected_account_id'], $version, $folderArgs['arguments']);
    if (!$folderResponse['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể đọc Drive Folder', $folderResponse), 'steps' => $steps];
    }
    $folderData = $folderResponse['json']['data'] ?? $folderResponse['json'];
    if (!editorial_composio_response_contains_id($folderData, $settings['drive_folder_id'])) {
        return ['ok' => false, 'message' => 'Drive metadata không khớp Folder ID đã cấu hình.', 'steps' => $steps];
    }
    $mimeTypes = editorial_composio_extract_mime_types($folderData);
    if ($mimeTypes !== [] && !in_array('application/vnd.google-apps.folder', $mimeTypes, true)) {
        return ['ok' => false, 'message' => 'Drive resource có thể truy cập nhưng không phải Google Drive Folder.', 'steps' => $steps];
    }
    $steps[] = ['label' => 'Drive Folder', 'ok' => true, 'message' => 'Đọc metadata thành công.'];

    $spreadsheetArgs = editorial_composio_readonly_arguments($toolMetadata['GOOGLESUPER_GET_SPREADSHEET_INFO'], 'spreadsheet', $settings['spreadsheet_id']);
    if (!$spreadsheetArgs['ok']) {
        return ['ok' => false, 'message' => $spreadsheetArgs['error'], 'steps' => $steps];
    }
    $spreadsheetResponse = editorial_composio_execute('GOOGLESUPER_GET_SPREADSHEET_INFO', $settings['connected_account_id'], $version, $spreadsheetArgs['arguments']);
    if (!$spreadsheetResponse['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể đọc Spreadsheet', $spreadsheetResponse), 'steps' => $steps];
    }
    $spreadsheetData = $spreadsheetResponse['json']['data'] ?? $spreadsheetResponse['json'];
    if (!editorial_composio_response_contains_id($spreadsheetData, $settings['spreadsheet_id'])) {
        return ['ok' => false, 'message' => 'Spreadsheet metadata không khớp Spreadsheet ID đã cấu hình.', 'steps' => $steps];
    }
    $steps[] = ['label' => 'Spreadsheet', 'ok' => true, 'message' => 'Đọc thông tin thành công.'];

    $sheetArgs = editorial_composio_readonly_arguments($toolMetadata['GOOGLESUPER_GET_SHEET_NAMES'], 'sheet_names', $settings['spreadsheet_id']);
    if (!$sheetArgs['ok']) {
        return ['ok' => false, 'message' => $sheetArgs['error'], 'steps' => $steps];
    }
    $sheetResponse = editorial_composio_execute('GOOGLESUPER_GET_SHEET_NAMES', $settings['connected_account_id'], $version, $sheetArgs['arguments']);
    if (!$sheetResponse['ok']) {
        return ['ok' => false, 'message' => editorial_composio_execution_error('Không thể đọc danh sách tab Sheet', $sheetResponse), 'steps' => $steps];
    }
    $sheetNames = editorial_composio_extract_sheet_names($sheetResponse['json']['data'] ?? $sheetResponse['json']);
    if (!in_array($settings['sheet_name'], $sheetNames, true)) {
        return ['ok' => false, 'message' => 'Spreadsheet hợp lệ nhưng không tìm thấy tab \'' . $settings['sheet_name'] . '\'.', 'steps' => $steps];
    }
    $steps[] = ['label' => 'Sheet: ' . $settings['sheet_name'], 'ok' => true, 'message' => 'Đã tìm thấy tab.'];

    return [
        'ok' => true,
        'version' => $version,
        'message' => 'Kết nối Google Handoff đã được xác minh đầy đủ.',
        'steps' => $steps,
    ];
}
