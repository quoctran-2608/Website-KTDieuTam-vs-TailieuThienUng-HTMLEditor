<?php
declare(strict_types=1);

/**
 * Editorial V2 — Helpers.
 *
 * Prefixed with editorial_ to avoid collision with admin legacy functions.
 */

/**
 * Resolve editorial base path URI from current request.
 */
function editorial_base_path_uri(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/editorial/index.php'));
    $dir = dirname($scriptName);
    if ($dir === '' || $dir === '.') {
        $dir = '/editorial';
    }
    $dir = '/' . trim($dir, '/');
    return $dir === '//' ? '/' : $dir;
}

/**
 * Validate this request is under /editorial path.
 */
function editorial_is_request_context(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName === '') {
        return false;
    }
    return str_contains($scriptName, '/editorial/') || str_ends_with($scriptName, '/editorial');
}

/**
 * Reject non-editorial context requests.
 */
function editorial_enforce_request_context(): void
{
    if (editorial_is_request_context()) {
        return;
    }
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

/**
 * Resolve site base path from editorial base path.
 */
function editorial_site_base_path(): string
{
    $base = editorial_base_path_uri();
    $normalized = rtrim($base, '/');
    if ($normalized === '/editorial') {
        return '';
    }
    if (str_ends_with($normalized, '/editorial')) {
        $site = substr($normalized, 0, -strlen('/editorial'));
        return ($site === '' || $site === '/') ? '' : $site;
    }
    return '';
}

/**
 * Build editorial local URL.
 */
function editorial_url(string $path = ''): string
{
    $base = editorial_base_path_uri();
    $path = ltrim($path, '/');
    if ($path === '') {
        return rtrim($base, '/') . '/';
    }
    return rtrim($base, '/') . '/' . $path;
}

/**
 * Build public site URL.
 */
function editorial_site_url(string $path = ''): string
{
    $path = trim($path);
    if ($path === '') {
        $base = editorial_site_base_path();
        return $base === '' ? '/' : (rtrim($base, '/') . '/');
    }
    if (preg_match('/^(https?:)?\\/\\//i', $path) === 1) {
        return $path;
    }
    $base = editorial_site_base_path();
    $clean = ltrim($path, '/');
    return $base === '' ? '/' . $clean : rtrim($base, '/') . '/' . $clean;
}

/**
 * Build URL to legacy admin CSS/assets.
 */
function editorial_admin_asset_url(string $path): string
{
    $base = editorial_site_base_path();
    $clean = ltrim($path, '/');
    return ($base === '' ? '' : rtrim($base, '/')) . '/admin/' . $clean;
}

/**
 * HTML escape.
 */
function editorial_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect and exit.
 */
function editorial_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Is POST request.
 */
function editorial_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Client IP.
 */
function editorial_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim((string) $parts[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Current request URI.
 */
function editorial_request_uri(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? editorial_url(''));
}

/**
 * CSRF token: get or create.
 */
function editorial_csrf_token(): string
{
    if (empty($_SESSION['_editorial_csrf'])) {
        $_SESSION['_editorial_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['_editorial_csrf'];
}

/**
 * Verify CSRF token.
 */
function editorial_verify_csrf(?string $token): bool
{
    $expected = (string) ($_SESSION['_editorial_csrf'] ?? '');
    if ($expected === '' || $token === null) {
        return false;
    }
    return hash_equals($expected, $token);
}

/**
 * Render CSRF hidden input.
 */
function editorial_csrf_input(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . editorial_h(editorial_csrf_token()) . '">';
}

/**
 * Push flash message.
 */
function editorial_flash_set(string $type, string $message): void
{
    if (!isset($_SESSION['_editorial_flash']) || !is_array($_SESSION['_editorial_flash'])) {
        $_SESSION['_editorial_flash'] = [];
    }
    $_SESSION['_editorial_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Pull and clear flash messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function editorial_flash_pull(): array
{
    $items = $_SESSION['_editorial_flash'] ?? [];
    unset($_SESSION['_editorial_flash']);
    if (!is_array($items)) {
        return [];
    }
    return array_values(array_filter($items, static function ($row): bool {
        return is_array($row) && isset($row['type'], $row['message']);
    }));
}

/**
 * Format datetime for display.
 */
function editorial_format_datetime(?string $iso): string
{
    if ($iso === null || trim($iso) === '') {
        return '—';
    }
    $time = strtotime($iso);
    if ($time === false) {
        return editorial_h($iso);
    }
    return date('d/m/Y H:i', $time);
}

/**
 * Humanize seconds.
 */
function editorial_human_seconds(int $seconds): string
{
    if ($seconds <= 0) return '0 giây';
    if ($seconds < 60) return $seconds . ' giây';
    if ($seconds < 3600) return (int) floor($seconds / 60) . ' phút';
    return (int) floor($seconds / 3600) . ' giờ';
}

/**
 * Generate a unique ID for editorial records.
 */
function editorial_generate_id(string $prefix = 'ed'): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}
