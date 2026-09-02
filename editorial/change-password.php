<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

editorial_require_auth();

$user = editorial_current_user();
$status = null;

if (editorial_is_post()) {
    editorial_enforce_csrf();

    $result = editorial_change_own_password(
        (string) $user['user_id'],
        (string) ($_POST['current_password'] ?? ''),
        (string) ($_POST['new_password'] ?? ''),
        (string) ($_POST['confirm_password'] ?? '')
    );

    $status = ['type' => $result['ok'] ? 'success' : 'danger', 'message' => $result['message']];

    if ($result['ok']) {
        editorial_flash_set('success', $result['message']);
        editorial_redirect(editorial_url('dashboard.php'));
    }
}

$isMandatory = !empty($user['must_change_password']);

editorial_layout_header([
    'title' => 'Đổi mật khẩu',
    'active' => '',
    'description' => $isMandatory
        ? 'Bạn cần đổi mật khẩu trước khi sử dụng hệ thống.'
        : 'Thay đổi mật khẩu tài khoản của bạn.',
]);
?>

<?php if ($status !== null): ?>
    <div class="flash flash-<?= editorial_h($status['type']) ?>">
        <?= editorial_h($status['message']) ?>
    </div>
<?php endif; ?>

<?php if ($isMandatory): ?>
    <div class="flash flash-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Tài khoản của bạn yêu cầu đổi mật khẩu. Vui lòng đặt mật khẩu mới để tiếp tục.
    </div>
<?php endif; ?>

<section class="admin-panel">
    <div class="panel-head">
        <h2><i class="fa-solid fa-key"></i> Đổi mật khẩu</h2>
        <p>Tài khoản: <strong><?= editorial_h((string) ($user['username'] ?? '')) ?></strong></p>
    </div>
    <form method="post" class="editorial-user-form" novalidate>
        <?= editorial_csrf_input() ?>

        <label class="form-field">
            <span>Mật khẩu hiện tại</span>
            <div class="field-input">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="current_password" required placeholder="Nhập mật khẩu hiện tại" autocomplete="current-password">
            </div>
        </label>

        <label class="form-field">
            <span>Mật khẩu mới (tối thiểu <?= EDITORIAL_PASSWORD_MIN_LENGTH ?> ký tự)</span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="new_password" required placeholder="Nhập mật khẩu mới" autocomplete="new-password">
            </div>
        </label>

        <label class="form-field">
            <span>Xác nhận mật khẩu mới</span>
            <div class="field-input">
                <i class="fa-solid fa-key"></i>
                <input type="password" name="confirm_password" required placeholder="Nhập lại mật khẩu mới" autocomplete="new-password">
            </div>
        </label>

        <div class="editorial-form-actions">
            <button type="submit" class="btn-auth-submit"><i class="fa-solid fa-check"></i> Đổi mật khẩu</button>
            <?php if (!$isMandatory): ?>
                <a href="<?= editorial_h(editorial_url('dashboard.php')) ?>" class="editorial-btn-cancel">Hủy</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php editorial_layout_footer(); ?>
