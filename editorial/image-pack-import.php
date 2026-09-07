<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/workspace.php';
require_once __DIR__ . '/includes/revision.php';
require_once __DIR__ . '/includes/media.php';

header('Content-Type: application/json; charset=utf-8');

const EDITORIAL_IMAGE_PACK_PROTOCOL = 'KTDT_IMAGE_PACK';
const EDITORIAL_IMAGE_PACK_VERSION = 1;
const EDITORIAL_IMAGE_PACK_MAX_JSON_BYTES = 256 * 1024;
const EDITORIAL_IMAGE_PACK_MAX_TOTAL_BYTES = 64 * 1024 * 1024;

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$fail = static function (
    int $status,
    string $message,
    string $item = '',
    ?int $index = null
) use ($respond): void {
    $payload = ['ok' => false, 'error' => $message];
    if ($item !== '') {
        $payload['item'] = $item;
    }
    if ($index !== null) {
        $payload['index'] = $index;
    }
    $respond($status, $payload);
};

/**
 * @return array{ok:bool,value?:string,error?:string}
 */
function editorial_image_pack_http_url(string $value): array
{
    $value = trim($value);
    $parts = $value !== '' ? parse_url($value) : false;
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    if (!is_array($parts)
        || !in_array($scheme, ['http', 'https'], true)
        || trim((string) ($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return ['ok' => false, 'error' => 'URL ảnh phải là HTTP(S) hợp lệ và không chứa thông tin đăng nhập.'];
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'error' => 'Cổng URL ảnh không hợp lệ.'];
    }
    return ['ok' => true, 'value' => $value];
}

function editorial_image_pack_text(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

function editorial_image_pack_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function editorial_image_pack_public_ip(string $ip): bool
{
    $normalized = strtolower(trim($ip, '[]'));
    if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/', $normalized, $match) === 1) {
        return editorial_image_pack_public_ip($match[1]);
    }
    return filter_var(
        $normalized,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * @return array{host:string,port:int,ip:string,is_literal:bool}
 */
function editorial_image_pack_resolve_public_target(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new EditorialMediaUploadException('URL ảnh không hợp lệ.');
    }
    $rawHost = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
    if (str_ends_with($rawHost, '.')) {
        throw new EditorialMediaUploadException('Hostname ảnh không được kết thúc bằng dấu chấm.');
    }
    $host = $rawHost;
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new EditorialMediaUploadException('URL ảnh trỏ tới địa chỉ nội bộ hoặc không hợp lệ.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!editorial_image_pack_public_ip($host)) {
            throw new EditorialMediaUploadException('URL ảnh trỏ tới địa chỉ private, reserved hoặc local.');
        }
        return ['host' => $host, 'port' => $port, 'ip' => $host, 'is_literal' => true];
    }
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        throw new EditorialMediaUploadException('Hostname ảnh không hợp lệ.');
    }

    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '') {
                $ips[$ip] = true;
            }
        }
    }
    if ($ips === []) {
        $fallback = @gethostbynamel($host);
        foreach (is_array($fallback) ? $fallback : [] as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ips[$ip] = true;
            }
        }
    }
    if ($ips === []) {
        throw new EditorialMediaUploadException('Không thể phân giải hostname ảnh.');
    }
    foreach (array_keys($ips) as $ip) {
        if (!editorial_image_pack_public_ip($ip)) {
            throw new EditorialMediaUploadException('Hostname ảnh phân giải về địa chỉ private, reserved hoặc local.');
        }
    }
    return [
        'host' => $host,
        'port' => $port,
        'ip' => (string) array_key_first($ips),
        'is_literal' => false,
    ];
}

/**
 * @return array{path:string,size:int}
 */
function editorial_image_pack_download(string $url): array
{
    if (!extension_loaded('curl') || !function_exists('curl_init')) {
        throw new EditorialMediaUploadException('Máy chủ chưa hỗ trợ tải ảnh từ xa bằng cURL.', 500);
    }
    $target = editorial_image_pack_resolve_public_target($url);
    $tempPath = tempnam(EDITORIAL_STORAGE_PATH, 'image-pack-');
    if ($tempPath === false) {
        throw new EditorialMediaUploadException('Không thể tạo file tạm để tải ảnh.', 500);
    }
    $handle = @fopen($tempPath, 'wb');
    if (!is_resource($handle)) {
        @unlink($tempPath);
        throw new EditorialMediaUploadException('Không thể mở file tạm để tải ảnh.', 500);
    }

    $received = 0;
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        fclose($handle);
        @unlink($tempPath);
        throw new EditorialMediaUploadException('Không thể khởi tạo kết nối tải ảnh.', 500);
    }
    $resolveIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'KetoanDieuTam-Editorial-ImagePack/1',
        CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $data) use ($handle, &$received, &$tooLarge): int {
            $length = strlen($data);
            if ($received + $length > EDITORIAL_MEDIA_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $written = fwrite($handle, $data);
            if ($written === false) {
                return 0;
            }
            $received += $written;
            return $written;
        },
    ];
    if (empty($target['is_literal'])) {
        $curlOptions[CURLOPT_RESOLVE] = [
            $target['host'] . ':' . $target['port'] . ':' . $resolveIp,
        ];
    }
    curl_setopt_array($curl, $curlOptions);
    $executed = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    fclose($handle);

    if ($tooLarge) {
        @unlink($tempPath);
        throw new EditorialMediaUploadException('Ảnh từ xa vượt quá 8 MB.', 413);
    }
    if ($executed === false || $received <= 0) {
        @unlink($tempPath);
        throw new EditorialMediaUploadException(
            $curlError !== '' ? 'Không thể tải ảnh từ xa.' : 'Ảnh từ xa không có dữ liệu.',
            502
        );
    }
    if ($status < 200 || $status >= 300) {
        @unlink($tempPath);
        throw new EditorialMediaUploadException(
            $status >= 300 && $status < 400
                ? 'URL ảnh trả về redirect. Vui lòng dùng URL ảnh cuối cùng.'
                : 'Máy chủ ảnh từ xa trả về lỗi HTTP.',
            502
        );
    }
    return ['path' => $tempPath, 'size' => $received];
}

/**
 * @return array<string,mixed>
 */
function editorial_image_pack_normalize_item(array $item, bool $inline, int $index = 0): array
{
    $url = editorial_image_pack_http_url(editorial_image_pack_text($item['image_url'] ?? ''));
    if (empty($url['ok'])) {
        throw new EditorialMediaUploadException(
            ($inline ? 'Ảnh nội dung #' . ($index + 1) . ': ' : 'Ảnh đại diện: ')
                . (string) ($url['error'] ?? 'URL không hợp lệ.')
        );
    }
    $normalized = [
        'image_url' => (string) $url['value'],
        'filename' => trim(editorial_image_pack_text($item['filename'] ?? '')) ?: 'image',
        'alt' => editorial_image_pack_text($item['alt'] ?? ''),
        'title' => editorial_image_pack_text($item['title'] ?? ''),
        'caption' => editorial_image_pack_text($item['caption'] ?? ''),
        'credit' => editorial_image_pack_text($item['credit'] ?? ''),
    ];
    if ($inline) {
        $normalized['old_src'] = trim(editorial_image_pack_text($item['old_src'] ?? ''));
        if ($normalized['old_src'] === '') {
            throw new EditorialMediaUploadException('Ảnh nội dung #' . ($index + 1) . ' thiếu old_src.');
        }
    }
    return $normalized;
}

/**
 * @return array<string,mixed>
 */
function editorial_image_pack_validate(string $json): array
{
    if ($json === '' || strlen($json) > EDITORIAL_IMAGE_PACK_MAX_JSON_BYTES) {
        throw new EditorialMediaUploadException('Gói ảnh trống hoặc vượt quá giới hạn dữ liệu.');
    }
    try {
        $pack = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new EditorialMediaUploadException('JSON gói ảnh không hợp lệ.');
    }
    if (!is_array($pack)
        || (string) ($pack['protocol'] ?? '') !== EDITORIAL_IMAGE_PACK_PROTOCOL
        || ($pack['version'] ?? null) !== EDITORIAL_IMAGE_PACK_VERSION) {
        throw new EditorialMediaUploadException('Gói ảnh không đúng protocol KTDT_IMAGE_PACK v1.');
    }
    $article = is_array($pack['article'] ?? null) ? $pack['article'] : [];
    if (trim(editorial_image_pack_text($article['title'] ?? '')) === '') {
        throw new EditorialMediaUploadException('Gói ảnh thiếu article.title.');
    }
    if (!is_array($pack['featured'] ?? null)) {
        throw new EditorialMediaUploadException('Gói ảnh thiếu Featured Image.');
    }
    if (!is_array($pack['inline_images'] ?? null)
        || !editorial_image_pack_is_list($pack['inline_images'])) {
        throw new EditorialMediaUploadException('inline_images phải là một mảng.');
    }
    $inline = [];
    foreach ($pack['inline_images'] as $index => $item) {
        if (!is_array($item)) {
            throw new EditorialMediaUploadException('Ảnh nội dung #' . ($index + 1) . ' không hợp lệ.');
        }
        $inline[] = editorial_image_pack_normalize_item($item, true, (int) $index);
    }
    return [
        'protocol' => EDITORIAL_IMAGE_PACK_PROTOCOL,
        'version' => EDITORIAL_IMAGE_PACK_VERSION,
        'article' => [
            'title' => trim(editorial_image_pack_text($article['title'] ?? '')),
            'slug' => trim(editorial_image_pack_text($article['slug'] ?? '')),
        ],
        'featured' => editorial_image_pack_normalize_item($pack['featured'], false),
        'inline_images' => $inline,
    ];
}

/**
 * Revalidate every write authority used by the existing upload endpoint.
 *
 * @return array{state:array<string,mixed>,assignment:array<string,mixed>}
 */
function editorial_image_pack_assert_write_authority(
    string $articleId,
    string $userId,
    string $lockToken,
    string $expectedAssignmentId = ''
): array {
    $currentUser = editorial_current_user();
    if (!is_array($currentUser)
        || (string) ($currentUser['user_id'] ?? '') !== $userId
        || (string) ($currentUser['role'] ?? '') !== 'editor'
        || !empty($currentUser['must_change_password'])) {
        throw new EditorialMediaUploadException('Tài khoản hiện tại không được phép nhập gói ảnh.', 403);
    }
    if ($articleId === '' || editorial_find_article($articleId) === null) {
        throw new EditorialMediaUploadException('Bài viết không hợp lệ.');
    }

    $state = editorial_get_article_state($articleId);
    if ($state === null
        || (string) ($state['assigned_user_id'] ?? '') !== $userId
        || !in_array((string) ($state['status'] ?? ''), ['editing', 'returned'], true)) {
        throw new EditorialMediaUploadException('Bạn không có quyền nhập ảnh cho bài viết này.', 403);
    }
    $assignment = editorial_get_active_assignment($articleId);
    if ($assignment === null
        || (string) ($assignment['user_id'] ?? '') !== $userId
        || ($expectedAssignmentId !== ''
            && !hash_equals($expectedAssignmentId, (string) ($assignment['id'] ?? '')))) {
        throw new EditorialMediaUploadException('Phân công biên tập hiện tại không hợp lệ.', 409);
    }

    $lock = editorial_validate_article_lock($articleId, $userId, $lockToken);
    if (empty($lock['ok'])) {
        throw new EditorialMediaUploadException(
            (string) ($lock['message'] ?? 'Phiên chỉnh sửa không hợp lệ.'),
            409
        );
    }
    $lockRow = editorial_get_article_lock($articleId);
    $lockExpiry = $lockRow ? strtotime((string) ($lockRow['expires_at'] ?? '')) : false;
    if ($lockRow === null
        || (string) ($lockRow['user_id'] ?? '') !== $userId
        || !hash_equals((string) ($lockRow['lock_token'] ?? ''), $lockToken)
        || $lockExpiry === false
        || $lockExpiry < time()) {
        throw new EditorialMediaUploadException(
            'Phiên chỉnh sửa không còn hợp lệ. Vui lòng tải lại Workspace.',
            409
        );
    }
    return ['state' => $state, 'assignment' => $assignment];
}

if (!editorial_is_post()) {
    header('Allow: POST');
    $fail(405, 'Phương thức không hợp lệ.');
}
if (!editorial_is_authenticated()) {
    $fail(401, 'Phiên đăng nhập không hợp lệ.');
}
if (!editorial_verify_csrf(isset($_POST['_csrf_token']) ? (string) $_POST['_csrf_token'] : null)) {
    $fail(403, 'CSRF token không hợp lệ.');
}

$currentUser = editorial_current_user();
$userId = (string) ($currentUser['user_id'] ?? '');
$articleId = trim((string) ($_POST['article_id'] ?? ''));
$lockToken = (string) ($_POST['lock_token'] ?? '');
try {
    $authority = editorial_image_pack_assert_write_authority($articleId, $userId, $lockToken);
} catch (\Throwable $e) {
    $status = $e instanceof EditorialMediaUploadException ? $e->httpStatus : 500;
    $message = $e instanceof EditorialMediaUploadException
        ? $e->getMessage()
        : 'Không thể xác minh quyền nhập gói ảnh.';
    $fail($status, $message);
}
$assignmentId = (string) ($authority['assignment']['id'] ?? '');

$tempFiles = [];
$savedFiles = [];
$validated = [];
$failureItem = '';
$failureIndex = null;
try {
    $pack = editorial_image_pack_validate((string) ($_POST['pack_json'] ?? ''));
    $downloads = [
        ['item' => 'featured', 'index' => null, 'data' => $pack['featured']],
    ];
    foreach ($pack['inline_images'] as $index => $item) {
        $downloads[] = ['item' => 'inline', 'index' => $index, 'data' => $item];
    }

    $totalBytes = 0;
    foreach ($downloads as $download) {
        $failureItem = (string) $download['item'];
        $failureIndex = $download['index'] !== null ? (int) $download['index'] : null;
        $remote = editorial_image_pack_download((string) $download['data']['image_url']);
        $tempFiles[] = $remote['path'];
        $totalBytes += (int) $remote['size'];
        if ($totalBytes > EDITORIAL_IMAGE_PACK_MAX_TOTAL_BYTES) {
            throw new EditorialMediaUploadException('Tổng dung lượng gói ảnh vượt quá 64 MB.', 413);
        }
        editorial_media_inspect_image_file($remote['path']);
        $download['temp_path'] = $remote['path'];
        $validated[] = $download;
    }

    $stamp = new DateTimeImmutable('now');
    $responseFeatured = null;
    $responseInline = [];
    editorial_transaction(function () use (
        $articleId,
        $userId,
        $lockToken,
        $assignmentId,
        $validated,
        $stamp,
        &$savedFiles,
        &$failureItem,
        &$failureIndex,
        &$responseFeatured,
        &$responseInline
    ): void {
        editorial_image_pack_assert_write_authority(
            $articleId,
            $userId,
            $lockToken,
            $assignmentId
        );
        foreach ($validated as $download) {
            $failureItem = (string) $download['item'];
            $failureIndex = $download['index'] !== null ? (int) $download['index'] : null;
            $saved = editorial_media_persist_local_image(
                (string) $download['temp_path'],
                (string) $download['data']['filename'],
                $stamp
            );
            $savedFiles[] = $saved['absolute_path'];
            if ($download['item'] === 'featured') {
                $responseFeatured = ['public_path' => $saved['public_path']];
            } else {
                $responseInline[] = [
                    'index' => (int) $download['index'],
                    'old_src' => (string) $download['data']['old_src'],
                    'public_path' => $saved['public_path'],
                ];
            }
        }
    });

    try {
        editorial_log_activity('article.media.image_pack_imported', $articleId, $userId, json_encode([
            'article_id' => $articleId,
            'user_id' => $userId,
            'featured_count' => 1,
            'inline_count' => count($responseInline),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (\Throwable $logError) {
        error_log('Editorial image pack activity log failed: article_id=' . $articleId
            . ' user_id=' . $userId . ' exception=' . get_class($logError));
    }
    foreach ($tempFiles as $path) {
        @unlink((string) $path);
    }
    $tempFiles = [];
    $respond(200, [
        'ok' => true,
        'featured' => $responseFeatured,
        'inline_images' => $responseInline,
    ]);
} catch (\Throwable $e) {
    foreach ($savedFiles as $path) {
        @unlink((string) $path);
    }
    foreach ($tempFiles as $path) {
        @unlink((string) $path);
    }
    error_log('Editorial image pack import failed: article_id=' . $articleId
        . ' user_id=' . $userId . ' exception=' . get_class($e));
    $status = $e instanceof EditorialMediaUploadException ? $e->httpStatus : 500;
    $message = $e instanceof EditorialMediaUploadException
        ? $e->getMessage()
        : 'Không thể tải gói ảnh. Vui lòng thử lại hoặc báo quản trị viên.';
    $fail(
        $status,
        $message,
        $failureItem,
        $failureIndex
    );
} finally {
    foreach ($tempFiles as $path) {
        @unlink((string) $path);
    }
}
