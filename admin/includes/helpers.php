<?php
declare(strict_types=1);

/**
 * Resolve admin base path from current request (supports subfolder deploy).
 * Example:
 * - /admin/login.php -> /admin
 * - /Ketoandieutam.com/admin/login.php -> /Ketoandieutam.com/admin
 */
function admin_base_path_uri(): string
{
  $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'));
  $dir = dirname($scriptName);
  if ($dir === '' || $dir === '.') {
    $dir = '/admin';
  }
  $dir = '/' . trim($dir, '/');
  return $dir === '//' ? '/' : $dir;
}

/**
 * Resolve site base path from admin base path.
 * Example:
 * - /admin -> '' (site at root)
 * - /Ketoandieutam.com/admin -> /Ketoandieutam.com
 */
function site_base_path_uri(): string
{
  $adminBase = admin_base_path_uri();
  $normalized = rtrim($adminBase, '/');
  if ($normalized === '/admin') {
    return '';
  }
  if (str_ends_with($normalized, '/admin')) {
    $site = substr($normalized, 0, -strlen('/admin'));
    if ($site === '' || $site === '/') {
      return '';
    }
    return $site;
  }
  return '';
}

/**
 * Start secure session for admin module.
 */
function bootstrap_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  // IMPORTANT:
  // Do not hardcode '/admin' because this project can run under a subfolder
  // (e.g. /Ketoandieutam.com/admin). Hardcoding breaks session cookie scope
  // and causes silent CSRF/login loops.
  $cookiePath = admin_base_path_uri();
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
  session_name('ketoan_admin_session');
  session_start();
}

/**
 * Escape html output.
 */
function h(?string $value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build admin local URL.
 */
function admin_url(string $path = ''): string
{
  $base = admin_base_path_uri();
  $path = ltrim($path, '/');
  if ($path === '') {
    return rtrim($base, '/') . '/';
  }
  return rtrim($base, '/') . '/' . $path;
}

/**
 * Build public site URL from path/href, aware of subfolder deployment.
 */
function site_url(string $path = ''): string
{
  $path = trim($path);
  if ($path === '') {
    $base = site_base_path_uri();
    return $base === '' ? '/' : (rtrim($base, '/') . '/');
  }
  if (preg_match('/^(https?:)?\/\//i', $path) === 1) {
    return $path;
  }

  $base = site_base_path_uri();
  $clean = ltrim($path, '/');
  if ($base === '') {
    return '/' . $clean;
  }
  return rtrim($base, '/') . '/' . $clean;
}

/**
 * Resolve article public URL.
 *
 * Prefer local href (subfolder-safe), fallback to canonical.
 *
 * @param array<string,mixed> $article
 */
function public_article_url(array $article): string
{
  $href = trim((string) ($article['href'] ?? ''));
  if ($href !== '') {
    return site_url($href);
  }
  $canonical = trim((string) ($article['canonical'] ?? ''));
  if ($canonical !== '') {
    return $canonical;
  }
  return '#';
}

/**
 * Redirect and exit.
 */
function redirect_to(string $url): void
{
  header('Location: ' . $url);
  exit;
}

/**
 * Determine post method.
 */
function is_post_request(): bool
{
  return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Client IP helper.
 */
function client_ip(): string
{
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim((string) $parts[0]);
  }
  if (!empty($_SERVER['REMOTE_ADDR'])) {
    return (string) $_SERVER['REMOTE_ADDR'];
  }
  return 'unknown';
}

/**
 * Current path including query.
 */
function current_request_uri(): string
{
  return (string) ($_SERVER['REQUEST_URI'] ?? (admin_url('')));
}

/**
 * Get or create CSRF token.
 */
function csrf_token(): string
{
  if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(24));
  }
  return (string) $_SESSION['_csrf_token'];
}

/**
 * Validate csrf token.
 */
function verify_csrf(?string $token): bool
{
  $expected = (string) ($_SESSION['_csrf_token'] ?? '');
  if ($expected === '' || $token === null) {
    return false;
  }
  return hash_equals($expected, $token);
}

/**
 * Render csrf hidden input.
 */
function csrf_input_html(): string
{
  return '<input type="hidden" name="_csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * Push flash message.
 */
function flash_set(string $type, string $message): void
{
  if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
    $_SESSION['_flash'] = [];
  }
  $_SESSION['_flash'][] = [
    'type' => $type,
    'message' => $message,
  ];
}

/**
 * Pull flash messages and clear.
 *
 * @return array<int,array{type:string,message:string}>
 */
function flash_pull(): array
{
  $items = $_SESSION['_flash'] ?? [];
  unset($_SESSION['_flash']);
  if (!is_array($items)) {
    return [];
  }

  return array_values(array_filter($items, static function ($row): bool {
    return is_array($row) && isset($row['type'], $row['message']);
  }));
}

/**
 * Format datetime for dashboard table.
 */
function format_admin_datetime(?string $isoDatetime): string
{
  if ($isoDatetime === null || trim($isoDatetime) === '') {
    return '—';
  }
  $time = strtotime($isoDatetime);
  if ($time === false) {
    return h($isoDatetime);
  }
  return date('d/m/Y H:i:s', $time);
}

/**
 * Humanize relative seconds.
 */
function human_seconds(int $seconds): string
{
  if ($seconds <= 0) {
    return '0 giây';
  }
  if ($seconds < 60) {
    return $seconds . ' giây';
  }
  if ($seconds < 3600) {
    return floor($seconds / 60) . ' phút';
  }
  return floor($seconds / 3600) . ' giờ';
}
