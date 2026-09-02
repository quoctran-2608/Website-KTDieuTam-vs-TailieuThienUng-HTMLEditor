<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (editorial_is_authenticated()) {
    editorial_redirect(editorial_url('dashboard.php'));
}

$status = null;
$usernameInput = '';

if (editorial_is_post()) {
    editorial_enforce_csrf();

    $usernameInput = trim((string) ($_POST['username'] ?? ''));
    $passwordInput = (string) ($_POST['password'] ?? '');

    if ($usernameInput === '' || $passwordInput === '') {
        $status = [
            'type' => 'danger',
            'message' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.',
        ];
    } else {
        $attempt = editorial_attempt_login($usernameInput, $passwordInput);
        if ($attempt['ok']) {
            editorial_flash_set('success', 'Đăng nhập thành công. Chào mừng bạn quay lại.');
            editorial_redirect(editorial_url('dashboard.php'));
        }
        $status = [
            'type' => 'danger',
            'message' => (string) ($attempt['message'] ?? 'Đăng nhập thất bại.'),
        ];
    }
}

$lockInfo = editorial_lock_status($usernameInput);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập | Editorial Admin | Kế Toán Diệu Tâm</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= editorial_h(editorial_admin_asset_url('assets/css/admin.css')) ?>">
    <link rel="stylesheet" href="<?= editorial_h(editorial_url('assets/css/editorial.css')) ?>">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-hero">
            <span class="auth-kicker"><i class="fa-solid fa-pen-ruler"></i> Editorial Admin</span>
            <h1>Đăng nhập biên tập nội dung</h1>
            <p>Hệ thống biên tập đa người dùng cho Kế Toán Diệu Tâm.</p>
            <ul class="auth-feature-list">
                <li><i class="fa-solid fa-users"></i> Nhiều thành viên cùng biên tập</li>
                <li><i class="fa-solid fa-shield-heart"></i> Phiên đăng nhập được bảo vệ an toàn</li>
                <li><i class="fa-solid fa-code-branch"></i> Lịch sử chỉnh sửa và revision</li>
            </ul>
        </section>

        <section class="auth-card-wrap">
            <div class="auth-card">
                <div class="auth-card-head">
                    <h2>Chào mừng trở lại</h2>
                    <p>Nhập thông tin tài khoản để tiếp tục.</p>
                </div>

                <?php if ($status !== null): ?>
                    <div class="flash flash-<?= editorial_h($status['type']) ?>">
                        <?= editorial_h($status['message']) ?>
                    </div>
                <?php endif; ?>

                <?php foreach (editorial_flash_pull() as $flash): ?>
                    <div class="flash flash-<?= editorial_h($flash['type']) ?>">
                        <?= editorial_h($flash['message']) ?>
                    </div>
                <?php endforeach; ?>

                <form method="post" class="auth-form" novalidate>
                    <?= editorial_csrf_input() ?>
                    <label class="form-field">
                        <span>Tên đăng nhập</span>
                        <div class="field-input">
                            <i class="fa-solid fa-user"></i>
                            <input
                                type="text"
                                name="username"
                                autocomplete="username"
                                placeholder="Ví dụ: admin"
                                value="<?= editorial_h($usernameInput) ?>"
                                required
                            >
                        </div>
                    </label>

                    <label class="form-field">
                        <span>Mật khẩu</span>
                        <div class="field-input">
                            <i class="fa-solid fa-key"></i>
                            <input
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Nhập mật khẩu"
                                required
                            >
                        </div>
                    </label>

                    <div class="form-inline-row">
                        <span></span>
                        <?php if ($lockInfo['locked']): ?>
                            <small class="lock-note">Tạm khóa <?= editorial_h(editorial_human_seconds($lockInfo['remaining'])) ?></small>
                        <?php endif; ?>
                    </div>

                    <button class="btn-auth-submit" type="submit">
                        <i class="fa-solid fa-right-to-bracket"></i>
                        <span>Đăng nhập</span>
                    </button>
                </form>

                <div class="auth-help">
                    <p>Dùng tài khoản admin đã cấp hoặc tài khoản từ hệ thống quản trị cũ.</p>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
