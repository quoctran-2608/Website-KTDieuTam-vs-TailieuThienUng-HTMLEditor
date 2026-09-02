<?php
declare(strict_types=1);

/**
 * Editorial V2 — Authentication.
 *
 * Adapted from admin/includes/auth.php.
 * All functions prefixed editorial_ to avoid collision.
 * Uses SQLite (editorial_users table) instead of JSON storage.
 */

if (!defined('EDITORIAL_SESSION_TTL')) {
    define('EDITORIAL_SESSION_TTL', 60 * 60 * 8); // 8 hours
}

if (!defined('EDITORIAL_LOCK_WINDOW')) {
    define('EDITORIAL_LOCK_WINDOW', 60 * 10); // 10 min
}

if (!defined('EDITORIAL_LOCK_ATTEMPTS')) {
    define('EDITORIAL_LOCK_ATTEMPTS', 5);
}

/**
 * Start secure session for editorial module.
 */
function editorial_bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookiePath = editorial_base_path_uri();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('ketoan_editorial_session');
    session_start();
}

/**
 * Seed initial admin user from legacy admin storage if DB is empty.
 *
 * Reads admin/storage/admin-data.json to import the admin password_hash
 * so the admin can log in with the same credentials.
 * Falls back to default admin/admin123 if legacy data not available.
 */
function editorial_seed_admin_user(): void
{
    $db = editorial_db();
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM editorial_users');
    $count = (int) $stmt->fetch()['cnt'];
    if ($count > 0) {
        return;
    }

    $now = date('c');
    $passwordHash = null;
    $displayName = 'Quản trị viên';

    // Try to import from legacy admin storage
    $legacyPath = dirname(__DIR__, 2) . '/admin/storage/admin-data.json';
    if (file_exists($legacyPath)) {
        $raw = file_get_contents($legacyPath);
        if ($raw !== false) {
            $legacyData = json_decode($raw, true);
            if (is_array($legacyData) && !empty($legacyData['users'])) {
                foreach ($legacyData['users'] as $user) {
                    if (!is_array($user)) continue;
                    if (($user['role'] ?? '') === 'admin' && !empty($user['is_active'])) {
                        $passwordHash = $user['password_hash'] ?? null;
                        $displayName = $user['display_name'] ?? $displayName;
                        break;
                    }
                }
            }
        }
    }

    // Fallback to default credentials
    if ($passwordHash === null || $passwordHash === '') {
        $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
    }

    $stmt = $db->prepare('
        INSERT INTO editorial_users (id, username, display_name, password_hash, role, is_active, must_change_password, created_at, updated_at)
        VALUES (:id, :username, :display_name, :password_hash, :role, 1, 1, :created_at, :updated_at)
    ');
    $stmt->execute([
        'id' => editorial_generate_id('usr'),
        'username' => 'admin',
        'display_name' => $displayName,
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    editorial_log_activity('system.seed_admin', null, null, json_encode(['source' => file_exists($legacyPath) ? 'legacy_import' : 'default_seed']));
}

/**
 * Find editorial user by username.
 *
 * @return array<string,mixed>|null
 */
function editorial_find_user(string $username): ?array
{
    $username = editorial_normalize_identity($username);
    if ($username === '') return null;

    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_users WHERE LOWER(username) = :username');
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Find editorial user by ID.
 *
 * @return array<string,mixed>|null
 */
function editorial_find_user_by_id(string $id): ?array
{
    $db = editorial_db();
    $stmt = $db->prepare('SELECT * FROM editorial_users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Check login lock status.
 *
 * Uses editorial_activity table to count recent failed logins.
 *
 * @return array{locked: bool, remaining: int, attempts: int}
 */
function editorial_lock_status(string $identity): array
{
    $identity = editorial_normalize_identity($identity);
    $ip = editorial_client_ip();
    $cutoff = date('c', time() - EDITORIAL_LOCK_WINDOW);

    $db = editorial_db();
    $stmt = $db->prepare('
        SELECT COUNT(*) as cnt FROM editorial_activity
        WHERE event_type = :event
          AND created_at > :cutoff
          AND (
            json_extract(payload_json, \'$.identity\') = :identity
            OR json_extract(payload_json, \'$.ip\') = :ip
          )
    ');
    $stmt->execute([
        'event' => 'auth.login.failed',
        'cutoff' => $cutoff,
        'identity' => $identity,
        'ip' => $ip,
    ]);
    $count = (int) $stmt->fetch()['cnt'];

    $locked = $count >= EDITORIAL_LOCK_ATTEMPTS;
    $remaining = 0;
    if ($locked) {
        $remaining = EDITORIAL_LOCK_WINDOW; // approximate
    }

    return [
        'locked' => $locked,
        'remaining' => $remaining,
        'attempts' => $count,
    ];
}

/**
 * Attempt login.
 *
 * @return array{ok: bool, code: string, message: string, user?: array<string,mixed>}
 */
function editorial_attempt_login(string $username, string $password): array
{
    $identity = trim($username);
    $lock = editorial_lock_status($identity);
    if ($lock['locked']) {
        return [
            'ok' => false,
            'code' => 'locked',
            'message' => 'Tài khoản đang tạm khóa. Vui lòng thử lại sau ' . editorial_human_seconds($lock['remaining']) . '.',
        ];
    }

    $user = editorial_find_user($identity);
    if ($user === null || empty($user['is_active'])) {
        editorial_log_activity('auth.login.failed', null, null, json_encode([
            'identity' => editorial_normalize_identity($identity),
            'ip' => editorial_client_ip(),
            'reason' => 'user_not_found',
        ]));
        return [
            'ok' => false,
            'code' => 'invalid_credentials',
            'message' => 'Sai tên đăng nhập hoặc mật khẩu.',
        ];
    }

    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        editorial_log_activity('auth.login.failed', null, null, json_encode([
            'identity' => editorial_normalize_identity($identity),
            'ip' => editorial_client_ip(),
            'reason' => 'wrong_password',
        ]));
        return [
            'ok' => false,
            'code' => 'invalid_credentials',
            'message' => 'Sai tên đăng nhập hoặc mật khẩu.',
        ];
    }

    session_regenerate_id(true);
    $_SESSION['editorial_auth'] = [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
        'must_change_password' => (bool) ($user['must_change_password'] ?? false),
        'login_at' => date('c'),
        'last_seen' => time(),
    ];

    // Update last_login_at
    $db = editorial_db();
    $stmt = $db->prepare('UPDATE editorial_users SET last_login_at = :now, updated_at = :now WHERE id = :id');
    $stmt->execute(['now' => date('c'), 'id' => $user['id']]);

    editorial_log_activity('auth.login.success', null, $user['id'], json_encode([
        'username' => $user['username'],
        'role' => $user['role'],
    ]));

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'Đăng nhập thành công.',
        'user' => $user,
    ];
}

/**
 * Log out current editorial session.
 */
function editorial_logout(): void
{
    $auth = $_SESSION['editorial_auth'] ?? null;
    if (is_array($auth) && isset($auth['user_id'])) {
        editorial_log_activity('auth.logout', null, (string) $auth['user_id']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

/**
 * Is there an authenticated editorial user.
 */
function editorial_is_authenticated(): bool
{
    $auth = $_SESSION['editorial_auth'] ?? null;
    if (!is_array($auth) || empty($auth['user_id'])) {
        return false;
    }

    $lastSeen = (int) ($auth['last_seen'] ?? 0);
    if ($lastSeen <= 0 || (time() - $lastSeen) > EDITORIAL_SESSION_TTL) {
        editorial_logout();
        return false;
    }

    $_SESSION['editorial_auth']['last_seen'] = time();
    return true;
}

/**
 * Get current editorial user session payload.
 *
 * @return array<string,mixed>|null
 */
function editorial_current_user(): ?array
{
    if (!editorial_is_authenticated()) {
        return null;
    }
    $auth = $_SESSION['editorial_auth'] ?? null;
    return is_array($auth) ? $auth : null;
}

/**
 * Require editorial authentication, redirect to login.
 */
function editorial_require_auth(): void
{
    if (editorial_is_authenticated()) {
        return;
    }
    editorial_flash_set('warning', 'Vui lòng đăng nhập để tiếp tục.');
    editorial_redirect(editorial_url('login.php'));
}

/**
 * Require specific role.
 *
 * @param array<int,string> $roles
 */
function editorial_require_role(array $roles): void
{
    editorial_require_auth();
    $user = editorial_current_user();
    $role = (string) ($user['role'] ?? '');
    if (in_array($role, $roles, true)) {
        return;
    }
    editorial_log_activity('auth.forbidden', null, (string) ($user['user_id'] ?? ''), json_encode([
        'uri' => editorial_request_uri(),
        'role' => $role,
    ]));
    http_response_code(403);
    echo '403 Không có quyền truy cập';
    exit;
}

/**
 * Enforce CSRF on POST.
 */
function editorial_enforce_csrf(): void
{
    $token = isset($_POST['_csrf_token']) ? (string) $_POST['_csrf_token'] : null;
    if (editorial_verify_csrf($token)) {
        return;
    }
    editorial_log_activity('auth.csrf.invalid', null, null, json_encode([
        'uri' => editorial_request_uri(),
    ]));
    editorial_flash_set('danger', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
    editorial_redirect(editorial_url('login.php'));
}

/**
 * Normalize identity.
 */
function editorial_normalize_identity(string $value): string
{
    return trim(strtolower($value));
}

/**
 * Log activity event.
 */
function editorial_log_activity(string $eventType, ?string $articleId = null, ?string $actorUserId = null, ?string $payloadJson = null): void
{
    try {
        $db = editorial_db();
        $stmt = $db->prepare('
            INSERT INTO editorial_activity (event_type, article_id, actor_user_id, payload_json, created_at)
            VALUES (:event_type, :article_id, :actor_user_id, :payload_json, :created_at)
        ');
        $stmt->execute([
            'event_type' => $eventType,
            'article_id' => $articleId,
            'actor_user_id' => $actorUserId,
            'payload_json' => $payloadJson,
            'created_at' => date('c'),
        ]);
    } catch (\Throwable $e) {
        // Silent: activity logging should not break application flow
    }
}
