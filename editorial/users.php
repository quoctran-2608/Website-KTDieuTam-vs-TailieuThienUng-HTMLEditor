<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_role(['admin']);

$currentUser = editorial_current_user();
$actorId = (string) $currentUser['user_id'];
$db = editorial_db();

// ─── Handle POST actions ────────────────────────────────────────

$status = null;
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$editUser = null;
$resetUser = null;
$showCreate = isset($_GET['action']) && $_GET['action'] === 'create';

if (editorial_is_post()) {
    editorial_enforce_csrf();

    if ($action === 'create') {
        $result = editorial_create_user(
            (string) ($_POST['display_name'] ?? ''),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['role'] ?? 'editor'),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? ''),
            !empty($_POST['must_change_password']),
            $actorId
        );
        $status = ['type' => $result['ok'] ? 'success' : 'danger', 'message' => $result['message']];
        if ($result['ok']) {
            editorial_flash_set('success', $result['message']);
            editorial_redirect(editorial_url('users.php'));
        }
        $showCreate = true;

    } elseif ($action === 'update') {
        $targetId = (string) ($_POST['user_id'] ?? '');
        $result = editorial_update_user(
            $targetId,
            (string) ($_POST['display_name'] ?? ''),
            (string) ($_POST['role'] ?? 'editor'),
            !empty($_POST['is_active']),
            $actorId
        );
        $status = ['type' => $result['ok'] ? 'success' : 'danger', 'message' => $result['message']];
        if ($result['ok']) {
            editorial_flash_set('success', $result['message']);
            editorial_redirect(editorial_url('users.php'));
        }
        $editUser = editorial_find_user_by_id($targetId);

    } elseif ($action === 'reset_password') {
        $targetId = (string) ($_POST['user_id'] ?? '');
        $result = editorial_reset_user_password(
            $targetId,
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? ''),
            $actorId
        );
        $status = ['type' => $result['ok'] ? 'success' : 'danger', 'message' => $result['message']];
        if ($result['ok']) {
            editorial_flash_set('success', $result['message']);
            editorial_redirect(editorial_url('users.php'));
        }
        $resetUser = editorial_find_user_by_id($targetId);
    }
}

// ─── Handle GET actions ─────────────────────────────────────────

if ($action === 'edit' && $editUser === null) {
    $editUser = editorial_find_user_by_id((string) ($_GET['id'] ?? ''));
    if ($editUser === null) {
        editorial_flash_set('danger', 'Không tìm thấy thành viên.');
        editorial_redirect(editorial_url('users.php'));
    }
}

if ($action === 'reset_password' && $resetUser === null && !editorial_is_post()) {
    $resetUser = editorial_find_user_by_id((string) ($_GET['id'] ?? ''));
    if ($resetUser === null) {
        editorial_flash_set('danger', 'Không tìm thấy thành viên.');
        editorial_redirect(editorial_url('users.php'));
    }
}

// ─── Data ───────────────────────────────────────────────────────

$users = editorial_list_users();

// ─── Render ─────────────────────────────────────────────────────

editorial_layout_header([
    'title' => 'Quản lý thành viên',
    'active' => 'members',
    'description' => 'Tạo, sửa và quản lý tài khoản biên tập viên.',
]);
?>

<?php if ($status !== null): ?>
    <div class="flash flash-<?= editorial_h($status['type']) ?>">
        <?= editorial_h($status['message']) ?>
    </div>
<?php endif; ?>

<?php if ($showCreate): ?>
<!-- ─── Create User Form ──────────────────────────────────────── -->
<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-user-plus"></i> Thêm thành viên mới</h2>
    </div>
    <form method="post" class="editorial-user-form" novalidate>
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="action" value="create">

        <label class="form-field">
            <span>Tên hiển thị <em>*</em></span>
            <div class="field-input">
                <i class="fa-solid fa-id-badge"></i>
                <input type="text" name="display_name" value="<?= editorial_h((string) ($_POST['display_name'] ?? '')) ?>" required placeholder="Nguyễn Văn A">
            </div>
        </label>

        <label class="form-field">
            <span>Tên đăng nhập <em>*</em></span>
            <div class="field-input">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="username" value="<?= editorial_h((string) ($_POST['username'] ?? '')) ?>" required placeholder="nguyenvana" autocomplete="off">
            </div>
        </label>

        <label class="form-field">
            <span>Vai trò</span>
            <div class="field-input">
                <i class="fa-solid fa-shield-halved"></i>
                <select name="role">
                    <option value="editor" <?= (($_POST['role'] ?? 'editor') === 'editor') ? 'selected' : '' ?>>Editor</option>
                    <option value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </label>

        <label class="form-field">
            <span>Mật khẩu <em>*</em> (tối thiểu <?= EDITORIAL_PASSWORD_MIN_LENGTH ?> ký tự)</span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="password" required placeholder="Mật khẩu" autocomplete="new-password">
            </div>
        </label>

        <label class="form-field">
            <span>Xác nhận mật khẩu <em>*</em></span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="confirm_password" required placeholder="Nhập lại mật khẩu" autocomplete="new-password">
            </div>
        </label>

        <div class="form-inline-row">
            <label class="remember-toggle">
                <input type="checkbox" name="must_change_password" value="1" checked>
                <span>Bắt buộc đổi mật khẩu sau đăng nhập</span>
            </label>
        </div>

        <div class="editorial-form-actions">
            <button type="submit" class="btn-auth-submit"><i class="fa-solid fa-plus"></i> Tạo thành viên</button>
            <a href="<?= editorial_h(editorial_url('users.php')) ?>" class="editorial-btn-cancel">Hủy</a>
        </div>
    </form>
</section>

<?php elseif ($editUser !== null): ?>
<!-- ─── Edit User Form ────────────────────────────────────────── -->
<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-user-pen"></i> Sửa thành viên: <?= editorial_h((string) $editUser['display_name']) ?></h2>
        <p>Username: <strong><?= editorial_h((string) $editUser['username']) ?></strong></p>
    </div>
    <form method="post" class="editorial-user-form" novalidate>
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?= editorial_h((string) $editUser['id']) ?>">

        <label class="form-field">
            <span>Tên hiển thị</span>
            <div class="field-input">
                <i class="fa-solid fa-id-badge"></i>
                <input type="text" name="display_name" value="<?= editorial_h((string) $editUser['display_name']) ?>" required>
            </div>
        </label>

        <label class="form-field">
            <span>Vai trò</span>
            <div class="field-input">
                <i class="fa-solid fa-shield-halved"></i>
                <select name="role">
                    <option value="editor" <?= ($editUser['role'] === 'editor') ? 'selected' : '' ?>>Editor</option>
                    <option value="admin" <?= ($editUser['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
        </label>

        <div class="form-inline-row">
            <label class="remember-toggle">
                <input type="checkbox" name="is_active" value="1" <?= (!empty($editUser['is_active'])) ? 'checked' : '' ?>>
                <span>Tài khoản đang hoạt động</span>
            </label>
        </div>

        <div class="editorial-form-actions">
            <button type="submit" class="btn-auth-submit"><i class="fa-solid fa-save"></i> Lưu thay đổi</button>
            <a href="<?= editorial_h(editorial_url('users.php')) ?>" class="editorial-btn-cancel">Hủy</a>
        </div>
    </form>
</section>

<?php elseif ($resetUser !== null): ?>
<!-- ─── Reset Password Form ───────────────────────────────────── -->
<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-key"></i> Đặt lại mật khẩu: <?= editorial_h((string) $resetUser['display_name']) ?></h2>
        <p>Username: <strong><?= editorial_h((string) $resetUser['username']) ?></strong>. Sau khi đặt lại, thành viên sẽ phải đổi mật khẩu khi đăng nhập.</p>
    </div>
    <form method="post" class="editorial-user-form" novalidate>
        <?= editorial_csrf_input() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= editorial_h((string) $resetUser['id']) ?>">

        <label class="form-field">
            <span>Mật khẩu mới (tối thiểu <?= EDITORIAL_PASSWORD_MIN_LENGTH ?> ký tự)</span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="new_password" required placeholder="Mật khẩu mới" autocomplete="new-password">
            </div>
        </label>

        <label class="form-field">
            <span>Xác nhận mật khẩu mới</span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="confirm_password" required placeholder="Nhập lại mật khẩu" autocomplete="new-password">
            </div>
        </label>

        <div class="editorial-form-actions">
            <button type="submit" class="btn-auth-submit"><i class="fa-solid fa-rotate"></i> Đặt lại mật khẩu</button>
            <a href="<?= editorial_h(editorial_url('users.php')) ?>" class="editorial-btn-cancel">Hủy</a>
        </div>
    </form>
</section>

<?php else: ?>
<!-- ─── User List ─────────────────────────────────────────────── -->
<div class="editorial-list-toolbar">
    <a href="<?= editorial_h(editorial_url('users.php?action=create')) ?>" class="btn-auth-submit">
        <i class="fa-solid fa-user-plus"></i> Thêm thành viên
    </a>
</div>

<section class="admin-panel">
    <div class="panel-head">
        <h2>Danh sách thành viên (<?= editorial_h((string) count($users)) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Tên hiển thị</th>
                    <th>Username</th>
                    <th>Vai trò</th>
                    <th>Trạng thái</th>
                    <th>Tạo lúc</th>
                    <th>Đăng nhập lần cuối</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><strong><?= editorial_h((string) $u['display_name']) ?></strong></td>
                        <td><code><?= editorial_h((string) $u['username']) ?></code></td>
                        <td>
                            <span class="editorial-badge editorial-badge-<?= editorial_h((string) $u['role']) ?>">
                                <?= editorial_h(($u['role'] === 'admin') ? 'Admin' : 'Editor') ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($u['is_active'])): ?>
                                <span class="editorial-badge editorial-badge-active">Đang hoạt động</span>
                            <?php else: ?>
                                <span class="editorial-badge editorial-badge-inactive">Đã khóa</span>
                            <?php endif; ?>
                        </td>
                        <td><?= editorial_h(editorial_format_datetime((string) ($u['created_at'] ?? ''))) ?></td>
                        <td>
                            <?php if (!empty($u['last_login_at'])): ?>
                                <?= editorial_h(editorial_format_datetime((string) $u['last_login_at'])) ?>
                            <?php else: ?>
                                <em style="color:#868e96;">Chưa từng đăng nhập</em>
                            <?php endif; ?>
                        </td>
                        <td class="editorial-action-cell">
                            <a href="<?= editorial_h(editorial_url('users.php?action=edit&id=' . urlencode((string) $u['id']))) ?>" title="Sửa">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            <a href="<?= editorial_h(editorial_url('users.php?action=reset_password&id=' . urlencode((string) $u['id']))) ?>" title="Đặt lại mật khẩu">
                                <i class="fa-solid fa-key"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php editorial_layout_footer(); ?>
