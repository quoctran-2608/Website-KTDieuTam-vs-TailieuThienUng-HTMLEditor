<?php
declare(strict_types=1);

/**
 * Editorial V2 — Authentication & User Management.
 *
 * Adapted from admin/includes/auth.php.
 * All functions prefixed editorial_ to avoid collision.
 * Uses SQLite (editorial_users table) instead of JSON storage.
 *
 * Phase 2: DB revalidation, must_change_password enforcement,
 *          user CRUD helpers.
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

if (!defined('EDITORIAL_PASSWORD_MIN_LENGTH')) {
    define('EDITORIAL_PASSWORD_MIN_LENGTH', 8);
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
 * Seed the required Editorial V2 accounts when they do not exist yet.
 *
 * Passwords are stored here only as precomputed bcrypt hashes. Existing
 * accounts are never changed, so a deployed site's credentials stay intact.
 */
function editorial_seed_admin_user(): void
{
    $seedUsers = [
        [
            'username' => 'admin',
            'display_name' => 'Quản trị viên',
            'role' => 'admin',
            'password_hash' => '$2y$12$01v7sCE1vOdtZ5SZxyomBuwJxigIM1dpUsFQfb5OZMIOLW9KqK8.m',
        ],
        [
            'username' => 'Thanhthuytran2266@gmail.com',
            'display_name' => 'Thanh Thủy Trần',
            'role' => 'editor',
            'password_hash' => '$2y$12$zeW8Tymnp5VWUSkwBexEDePe2VSBeiuCeMMNq3fQPLENfeSKTipXm',
        ],
    ];

    $hasMissingUser = false;
    foreach ($seedUsers as $seedUser) {
        if (editorial_find_user($seedUser['username']) === null) {
            $hasMissingUser = true;
            break;
        }
    }
    if (!$hasMissingUser) {
        return;
    }

    editorial_transaction(function () use ($seedUsers): void {
        $db = editorial_db();
        $stmt = $db->prepare('
            INSERT INTO editorial_users (id, username, display_name, password_hash, role, is_active, must_change_password, created_at, updated_at)
            VALUES (:id, :username, :display_name, :password_hash, :role, 1, 0, :created_at, :updated_at)
        ');

        foreach ($seedUsers as $seedUser) {
            // Recheck inside BEGIN IMMEDIATE to avoid duplicate seed accounts.
            if (editorial_find_user($seedUser['username']) !== null) {
                continue;
            }

            $now = date('c');
            $stmt->execute([
                'id' => editorial_generate_id('usr'),
                'username' => $seedUser['username'],
                'display_name' => $seedUser['display_name'],
                'password_hash' => $seedUser['password_hash'],
                'role' => $seedUser['role'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            editorial_log_activity('system.seed_user', null, null, json_encode([
                'username' => $seedUser['username'],
                'role' => $seedUser['role'],
            ]));
        }
    });
}

// ─── User lookup ────────────────────────────────────────────────

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
 * List all editorial users.
 *
 * @return array<int, array<string,mixed>>
 */
function editorial_list_users(): array
{
    $db = editorial_db();
    return $db->query('SELECT * FROM editorial_users ORDER BY created_at ASC')->fetchAll();
}

/**
 * Count active admins.
 */
function editorial_count_active_admins(): int
{
    $db = editorial_db();
    $stmt = $db->query("SELECT COUNT(*) FROM editorial_users WHERE role = 'admin' AND is_active = 1");
    return (int) $stmt->fetchColumn();
}

// ─── User CRUD ──────────────────────────────────────────────────

/**
 * Create a new editorial user.
 *
 * @return array{ok: bool, message: string, user_id?: string}
 */
function editorial_create_user(string $displayName, string $username, string $role, string $password, string $confirmPassword, bool $mustChangePassword, string $actorUserId): array
{
    $displayName = trim($displayName);
    $username = trim($username);

    if ($displayName === '') {
        return ['ok' => false, 'message' => 'Tên hiển thị không được để trống.'];
    }
    if ($username === '') {
        return ['ok' => false, 'message' => 'Tên đăng nhập không được để trống.'];
    }
    if (!in_array($role, ['admin', 'editor'], true)) {
        return ['ok' => false, 'message' => 'Vai trò không hợp lệ.'];
    }
    if (strlen($password) < EDITORIAL_PASSWORD_MIN_LENGTH) {
        return ['ok' => false, 'message' => 'Mật khẩu phải có ít nhất ' . EDITORIAL_PASSWORD_MIN_LENGTH . ' ký tự.'];
    }
    if ($password !== $confirmPassword) {
        return ['ok' => false, 'message' => 'Xác nhận mật khẩu không khớp.'];
    }

    // Check unique username
    $existing = editorial_find_user($username);
    if ($existing !== null) {
        return ['ok' => false, 'message' => 'Tên đăng nhập "' . $username . '" đã tồn tại.'];
    }

    $now = date('c');
    $userId = editorial_generate_id('usr');
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $db = editorial_db();
    $stmt = $db->prepare('
        INSERT INTO editorial_users (id, username, display_name, password_hash, role, is_active, must_change_password, created_at, updated_at)
        VALUES (:id, :username, :display_name, :password_hash, :role, 1, :mcp, :created_at, :updated_at)
    ');
    $stmt->execute([
        'id' => $userId,
        'username' => $username,
        'display_name' => $displayName,
        'password_hash' => $hash,
        'role' => $role,
        'mcp' => $mustChangePassword ? 1 : 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    editorial_log_activity('user.created', null, $actorUserId, json_encode([
        'target_user_id' => $userId,
        'username' => $username,
        'display_name' => $displayName,
        'role' => $role,
    ]));

    return ['ok' => true, 'message' => 'Đã tạo thành viên "' . $displayName . '" thành công.', 'user_id' => $userId];
}

/**
 * Update editorial user profile (display_name, role, is_active).
 *
 * @return array{ok: bool, message: string}
 */
function editorial_update_user(string $targetUserId, string $displayName, string $role, bool $isActive, string $actorUserId): array
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return ['ok' => false, 'message' => 'Tên hiển thị không được để trống.'];
    }
    if (!in_array($role, ['admin', 'editor'], true)) {
        return ['ok' => false, 'message' => 'Vai trò không hợp lệ.'];
    }

    return editorial_transaction(function () use ($targetUserId, $displayName, $role, $isActive, $actorUserId): array {
        $target = editorial_find_user_by_id($targetUserId);
        if ($target === null) {
            return ['ok' => false, 'message' => 'Không tìm thấy thành viên.'];
        }

        $oldRole = (string) $target['role'];
        $oldActive = (bool) $target['is_active'];
        $oldDisplayName = (string) $target['display_name'];

        // Safety: cannot deactivate self
        if (!$isActive && $targetUserId === $actorUserId) {
            return ['ok' => false, 'message' => 'Bạn không thể khóa tài khoản đang sử dụng.'];
        }

        // Safety: cannot remove last active admin
        if ($oldRole === 'admin' && $oldActive) {
            $wouldLoseAdmin = (!$isActive || $role !== 'admin');
            if ($wouldLoseAdmin) {
                $activeAdmins = editorial_count_active_admins();
                if ($activeAdmins <= 1) {
                    return ['ok' => false, 'message' => 'Không thể thực hiện. Hệ thống cần ít nhất một quản trị viên đang hoạt động.'];
                }
            }
        }

        $now = date('c');
        $db = editorial_db();
        $stmt = $db->prepare('
            UPDATE editorial_users
            SET display_name = :display_name, role = :role, is_active = :is_active, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->execute([
            'display_name' => $displayName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'updated_at' => $now,
            'id' => $targetUserId,
        ]);

        // Log changes
        $changes = [];
        if ($oldDisplayName !== $displayName) $changes['display_name'] = ['old' => $oldDisplayName, 'new' => $displayName];
        if ($oldRole !== $role) $changes['role'] = ['old' => $oldRole, 'new' => $role];
        if ($oldActive !== $isActive) $changes['is_active'] = ['old' => $oldActive, 'new' => $isActive];

        if (!empty($changes)) {
            editorial_log_activity('user.updated', null, $actorUserId, json_encode([
                'target_user_id' => $targetUserId,
                'username' => $target['username'],
                'changes' => $changes,
            ]));
        }

        // Specific activation/deactivation events
        if ($oldActive && !$isActive) {
            editorial_log_activity('user.deactivated', null, $actorUserId, json_encode([
                'target_user_id' => $targetUserId,
                'username' => $target['username'],
            ]));
        }
        if (!$oldActive && $isActive) {
            editorial_log_activity('user.activated', null, $actorUserId, json_encode([
                'target_user_id' => $targetUserId,
                'username' => $target['username'],
            ]));
        }

        return ['ok' => true, 'message' => 'Đã cập nhật thành viên "' . $displayName . '" thành công.'];
    });
}

/**
 * Reset user password (admin action).
 *
 * @return array{ok: bool, message: string}
 */
function editorial_reset_user_password(string $targetUserId, string $newPassword, string $confirmPassword, string $actorUserId): array
{
    if (strlen($newPassword) < EDITORIAL_PASSWORD_MIN_LENGTH) {
        return ['ok' => false, 'message' => 'Mật khẩu phải có ít nhất ' . EDITORIAL_PASSWORD_MIN_LENGTH . ' ký tự.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'message' => 'Xác nhận mật khẩu không khớp.'];
    }

    $target = editorial_find_user_by_id($targetUserId);
    if ($target === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy thành viên.'];
    }

    $now = date('c');
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db = editorial_db();
    $stmt = $db->prepare('
        UPDATE editorial_users
        SET password_hash = :hash, must_change_password = 1, updated_at = :updated_at
        WHERE id = :id
    ');
    $stmt->execute(['hash' => $hash, 'updated_at' => $now, 'id' => $targetUserId]);

    editorial_log_activity('user.password_reset', null, $actorUserId, json_encode([
        'target_user_id' => $targetUserId,
        'username' => $target['username'],
    ]));

    return ['ok' => true, 'message' => 'Đã đặt lại mật khẩu cho "' . $target['display_name'] . '". Thành viên sẽ phải đổi mật khẩu khi đăng nhập.'];
}

/**
 * Change own password (self-service).
 *
 * @return array{ok: bool, message: string}
 */
function editorial_change_own_password(string $userId, string $currentPassword, string $newPassword, string $confirmPassword): array
{
    if (strlen($newPassword) < EDITORIAL_PASSWORD_MIN_LENGTH) {
        return ['ok' => false, 'message' => 'Mật khẩu mới phải có ít nhất ' . EDITORIAL_PASSWORD_MIN_LENGTH . ' ký tự.'];
    }
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'message' => 'Xác nhận mật khẩu mới không khớp.'];
    }

    $user = editorial_find_user_by_id($userId);
    if ($user === null) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản.'];
    }

    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
    }

    $now = date('c');
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db = editorial_db();
    $stmt = $db->prepare('
        UPDATE editorial_users
        SET password_hash = :hash, must_change_password = 0, updated_at = :updated_at
        WHERE id = :id
    ');
    $stmt->execute(['hash' => $newHash, 'updated_at' => $now, 'id' => $userId]);

    // Update session immediately
    if (isset($_SESSION['editorial_auth'])) {
        $_SESSION['editorial_auth']['must_change_password'] = false;
    }

    editorial_log_activity('user.password_changed', null, $userId);

    return ['ok' => true, 'message' => 'Đổi mật khẩu thành công.'];
}

// ─── Login lock ─────────────────────────────────────────────────

/**
 * Check login lock status.
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

// ─── Login / Logout ─────────────────────────────────────────────

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

// ─── Authentication & Revalidation ──────────────────────────────

/**
 * Is there an authenticated editorial user.
 *
 * Phase 2: Revalidates user from database every request.
 * Database is source of truth for is_active, role, display_name, must_change_password.
 * Session only holds identity (user_id) and session state (login_at, last_seen).
 */
function editorial_is_authenticated(): bool
{
    $auth = $_SESSION['editorial_auth'] ?? null;
    if (!is_array($auth) || empty($auth['user_id'])) {
        return false;
    }

    // Check session TTL
    $lastSeen = (int) ($auth['last_seen'] ?? 0);
    if ($lastSeen <= 0 || (time() - $lastSeen) > EDITORIAL_SESSION_TTL) {
        editorial_logout();
        return false;
    }

    // Revalidate from database
    $dbUser = editorial_find_user_by_id((string) $auth['user_id']);
    if ($dbUser === null || empty($dbUser['is_active'])) {
        editorial_logout();
        return false;
    }

    // Refresh session with current DB state
    $_SESSION['editorial_auth']['last_seen'] = time();
    $_SESSION['editorial_auth']['username'] = $dbUser['username'];
    $_SESSION['editorial_auth']['display_name'] = $dbUser['display_name'];
    $_SESSION['editorial_auth']['role'] = $dbUser['role'];
    $_SESSION['editorial_auth']['must_change_password'] = (bool) $dbUser['must_change_password'];

    return true;
}

/**
 * Get current editorial user session payload.
 *
 * Returns revalidated state from DB (via editorial_is_authenticated).
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
 *
 * Phase 2: Also enforces must_change_password redirect.
 */
function editorial_require_auth(): void
{
    if (!editorial_is_authenticated()) {
        editorial_flash_set('warning', 'Vui lòng đăng nhập để tiếp tục.');
        editorial_redirect(editorial_url('login.php'));
    }

    // Enforce must_change_password
    $user = $_SESSION['editorial_auth'] ?? null;
    if (is_array($user) && !empty($user['must_change_password'])) {
        // Allow access only to change-password.php and logout.php
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $allowed = ['/change-password.php', '/logout.php'];
        $isAllowed = false;
        foreach ($allowed as $suffix) {
            if (str_ends_with($script, $suffix)) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            editorial_flash_set('warning', 'Bạn cần đổi mật khẩu trước khi tiếp tục sử dụng hệ thống.');
            editorial_redirect(editorial_url('change-password.php'));
        }
    }
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
