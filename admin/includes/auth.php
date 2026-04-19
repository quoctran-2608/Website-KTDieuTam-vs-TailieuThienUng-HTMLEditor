<?php
declare(strict_types=1);

/**
 * Ensure seeded admin user exists.
 *
 * Default credentials:
 * - username: admin
 * - password: admin123
 *
 * Must be changed right after first login.
 */
function ensure_default_admin_user(): void
{
  $data = storage_read();
  if (!empty($data['users'])) {
    return;
  }

  $now = date('c');
  $data['users'][] = [
    'id' => 'u-admin-001',
    'username' => 'admin',
    'display_name' => 'Quản trị viên',
    'role' => 'admin',
    'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
    'is_active' => true,
    'must_change_password' => true,
    'created_at' => $now,
    'updated_at' => $now,
    'last_login_at' => null,
  ];
  storage_write($data);
}

/**
 * Find user by username.
 *
 * @return array<string,mixed>|null
 */
function find_user_by_username(string $username): ?array
{
  $username = normalize_identity($username);
  if ($username === '') {
    return null;
  }

  $data = storage_read();
  foreach ($data['users'] as $user) {
    if (!is_array($user)) {
      continue;
    }
    $candidate = normalize_identity((string) ($user['username'] ?? ''));
    if ($candidate === $username) {
      return $user;
    }
  }
  return null;
}

/**
 * Persist user changes.
 *
 * @param array<string,mixed> $newUser
 */
function save_user(array $newUser): void
{
  $data = storage_read();
  foreach ($data['users'] as $idx => $user) {
    if (!is_array($user)) {
      continue;
    }
    if (($user['id'] ?? '') === ($newUser['id'] ?? null)) {
      $newUser['updated_at'] = date('c');
      $data['users'][$idx] = $newUser;
      storage_write($data);
      return;
    }
  }
}

/**
 * Check lock status for identity and ip.
 *
 * @return array{locked:bool,remaining:int,attempts:int}
 */
function lock_status(string $identity): array
{
  $identity = normalize_identity($identity);
  $ip = client_ip();
  $now = time();

  $data = storage_read();
  $attempts = $data['login_attempts'];
  $recent = [];
  foreach ($attempts as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $entryTime = (int) ($entry['time'] ?? 0);
    if ($entryTime <= 0 || ($now - $entryTime) > ADMIN_LOCK_WINDOW) {
      continue;
    }
    $recent[] = $entry;
  }

  if (count($recent) !== count($attempts)) {
    $data['login_attempts'] = $recent;
    storage_write($data);
  }

  $count = 0;
  $oldest = null;
  foreach ($recent as $entry) {
    $entryIdentity = (string) ($entry['identity'] ?? '');
    $entryIp = (string) ($entry['ip'] ?? '');
    if (($identity !== '' && $entryIdentity === $identity) || $entryIp === $ip) {
      $count++;
      if ($oldest === null || (int) $entry['time'] < $oldest) {
        $oldest = (int) $entry['time'];
      }
    }
  }

  $locked = $count >= ADMIN_LOCK_ATTEMPTS;
  $remaining = 0;
  if ($locked && $oldest !== null) {
    $remaining = max(0, ADMIN_LOCK_WINDOW - ($now - $oldest));
  }

  return [
    'locked' => $locked,
    'remaining' => $remaining,
    'attempts' => $count,
  ];
}

/**
 * Record failed login attempt.
 */
function record_failed_login(string $identity): void
{
  $identity = normalize_identity($identity);
  $data = storage_read();
  $data['login_attempts'][] = [
    'identity' => $identity,
    'ip' => client_ip(),
    'time' => time(),
  ];
  storage_write($data);
}

/**
 * Clear attempts by identity and ip after successful login.
 */
function clear_login_attempts(string $identity): void
{
  $identity = normalize_identity($identity);
  $ip = client_ip();
  $data = storage_read();
  $filtered = [];
  foreach ($data['login_attempts'] as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $entryIdentity = (string) ($entry['identity'] ?? '');
    $entryIp = (string) ($entry['ip'] ?? '');
    if ($entryIdentity === $identity || $entryIp === $ip) {
      continue;
    }
    $filtered[] = $entry;
  }
  $data['login_attempts'] = $filtered;
  storage_write($data);
}

/**
 * Attempt login with username and password.
 *
 * @return array{ok:bool,code:string,message:string,user?:array<string,mixed>}
 */
function attempt_login(string $username, string $password): array
{
  $identity = trim($username);
  $lock = lock_status($identity);
  if ($lock['locked']) {
    return [
      'ok' => false,
      'code' => 'locked',
      'message' => 'Tài khoản đang tạm khóa. Vui lòng thử lại sau ' . human_seconds($lock['remaining']) . '.',
    ];
  }

  $user = find_user_by_username($identity);
  if ($user === null || empty($user['is_active'])) {
    record_failed_login($identity);
    return [
      'ok' => false,
      'code' => 'invalid_credentials',
      'message' => 'Sai tên đăng nhập hoặc mật khẩu.',
    ];
  }

  $hash = (string) ($user['password_hash'] ?? '');
  if ($hash === '' || !password_verify($password, $hash)) {
    record_failed_login($identity);
    return [
      'ok' => false,
      'code' => 'invalid_credentials',
      'message' => 'Sai tên đăng nhập hoặc mật khẩu.',
    ];
  }

  clear_login_attempts($identity);

  session_regenerate_id(true);
  $_SESSION['auth'] = [
    'user_id' => $user['id'],
    'username' => $user['username'],
    'display_name' => $user['display_name'],
    'role' => $user['role'],
    'must_change_password' => (bool) ($user['must_change_password'] ?? false),
    'login_at' => date('c'),
    'last_seen' => time(),
  ];

  $user['last_login_at'] = date('c');
  save_user($user);

  append_audit_log([
    'event' => 'auth.login.success',
    'username' => $user['username'],
    'user_id' => $user['id'],
    'role' => $user['role'],
  ]);

  return [
    'ok' => true,
    'code' => 'ok',
    'message' => 'Đăng nhập thành công.',
    'user' => $user,
  ];
}

/**
 * Logout current session.
 */
function logout_current_user(): void
{
  $auth = $_SESSION['auth'] ?? null;
  if (is_array($auth) && isset($auth['username'])) {
    append_audit_log([
      'event' => 'auth.logout',
      'username' => (string) $auth['username'],
      'user_id' => (string) ($auth['user_id'] ?? ''),
      'role' => (string) ($auth['role'] ?? ''),
    ]);
  }

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
  }
  session_destroy();
}

/**
 * Is there an authenticated user.
 */
function is_authenticated(): bool
{
  $auth = $_SESSION['auth'] ?? null;
  if (!is_array($auth) || empty($auth['user_id'])) {
    return false;
  }

  $lastSeen = (int) ($auth['last_seen'] ?? 0);
  if ($lastSeen <= 0 || (time() - $lastSeen) > ADMIN_SESSION_TTL) {
    logout_current_user();
    return false;
  }

  $_SESSION['auth']['last_seen'] = time();
  return true;
}

/**
 * Get current user session payload.
 *
 * @return array<string,mixed>|null
 */
function current_user(): ?array
{
  if (!is_authenticated()) {
    return null;
  }
  $auth = $_SESSION['auth'] ?? null;
  return is_array($auth) ? $auth : null;
}

/**
 * Require authentication, redirect to login when missing.
 */
function require_auth(): void
{
  if (is_authenticated()) {
    return;
  }
  flash_set('warning', 'Vui lòng đăng nhập để tiếp tục.');
  redirect_to(admin_url('login.php'));
}

/**
 * Require specific role.
 *
 * @param array<int,string> $roles
 */
function require_role(array $roles): void
{
  require_auth();
  $user = current_user();
  $role = (string) ($user['role'] ?? '');
  if (in_array($role, $roles, true)) {
    return;
  }
  append_audit_log([
    'event' => 'auth.forbidden',
    'username' => (string) ($user['username'] ?? ''),
    'user_id' => (string) ($user['user_id'] ?? ''),
    'role' => $role,
    'uri' => current_request_uri(),
  ]);
  http_response_code(403);
  echo '403 Không có quyền truy cập';
  exit;
}

/**
 * Verify POST request csrf token and throw user-friendly response when invalid.
 */
function enforce_post_csrf_or_reject(): void
{
  $token = isset($_POST['_csrf_token']) ? (string) $_POST['_csrf_token'] : null;
  if (verify_csrf($token)) {
    return;
  }
  append_audit_log([
    'event' => 'auth.csrf.invalid',
    'uri' => current_request_uri(),
  ]);
  flash_set('danger', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
  redirect_to(admin_url('login.php'));
}

/**
 * Normalize auth identity to avoid extension dependency.
 */
function normalize_identity(string $value): string
{
  return trim(strtolower($value));
}
