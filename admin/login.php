<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_authenticated()) {
  redirect_to(admin_url('dashboard.php'));
}

$status = null;
$usernameInput = '';

if (is_post_request()) {
  enforce_post_csrf_or_reject();

  $usernameInput = trim((string) ($_POST['username'] ?? ''));
  $passwordInput = (string) ($_POST['password'] ?? '');
  $rememberInput = !empty($_POST['remember_me']);

  if ($usernameInput === '' || $passwordInput === '') {
    $status = [
      'type' => 'danger',
      'message' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.',
    ];
  } else {
    $attempt = attempt_login($usernameInput, $passwordInput);
    if ($attempt['ok']) {
      if ($rememberInput) {
        $_SESSION['auth']['remember_me'] = true;
      }
      flash_set('success', 'Đăng nhập thành công. Chào mừng bạn quay lại.');
      redirect_to(admin_url('dashboard.php'));
    }

    $status = [
      'type' => 'danger',
      'message' => (string) ($attempt['message'] ?? 'Đăng nhập thất bại.'),
    ];
    append_audit_log([
      'event' => 'auth.login.failed',
      'username' => $usernameInput,
      'reason' => (string) ($attempt['code'] ?? 'unknown'),
    ]);
  }
}

$lockInfo = lock_status($usernameInput);
$debugAuth = false;
if (isset($_GET['debug']) && (string) $_GET['debug'] === '1') {
  $debugAuth = true;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng nhập quản trị | Kế Toán Diệu Tâm</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="<?= h(admin_url('assets/css/admin.css')) ?>">
</head>
<body class="auth-body">
  <main class="auth-shell">
    <section class="auth-hero">
      <span class="auth-kicker"><i class="fa-solid fa-lock"></i> Khu vực bảo mật</span>
      <h1>Đăng nhập quản trị nội dung</h1>
      <p>Đăng nhập để quản lý bài viết trong Thư viện và Bản tin.</p>
      <ul class="auth-feature-list">
        <li><i class="fa-solid fa-shield-heart"></i> Phiên đăng nhập được bảo vệ an toàn</li>
        <li><i class="fa-solid fa-filter-circle-dollar"></i> Mỗi biểu mẫu đều có mã xác thực hợp lệ</li>
        <li><i class="fa-solid fa-clipboard-check"></i> Lưu lịch sử khi đăng nhập và đăng xuất</li>
      </ul>
    </section>

    <section class="auth-card-wrap">
      <div class="auth-card">
        <div class="auth-card-head">
          <h2>Chào mừng trở lại</h2>
          <p>Nhập thông tin tài khoản để tiếp tục.</p>
        </div>

        <?php if ($status !== null): ?>
          <div class="flash flash-<?= h($status['type']) ?>">
            <?= h($status['message']) ?>
          </div>
        <?php endif; ?>

        <?php if ($debugAuth): ?>
          <div class="flash flash-warning">
            <strong>Thông tin kiểm tra đăng nhập:</strong>
            có mã phiên=<?= h((string) (isset($_SESSION['_csrf_token']) ? 'có' : 'không')) ?> |
            phương thức=<?= h((string) ($_SERVER['REQUEST_METHOD'] ?? '')) ?> |
            đường dẫn cookie=<?= h((string) (session_get_cookie_params()['path'] ?? '')) ?> |
            đường dẫn quản trị=<?= h(admin_base_path_uri()) ?> |
            đường dẫn trang=<?= h(site_base_path_uri()) ?> |
            độ dài mã phiên=<?= h((string) strlen((string) session_id())) ?>
          </div>
        <?php endif; ?>

        <?php foreach (flash_pull() as $flash): ?>
          <div class="flash flash-<?= h($flash['type']) ?>">
            <?= h($flash['message']) ?>
          </div>
        <?php endforeach; ?>

        <form method="post" class="auth-form" novalidate>
          <?= csrf_input_html() ?>
          <label class="form-field">
            <span>Tên đăng nhập</span>
            <div class="field-input">
              <i class="fa-solid fa-user"></i>
              <input
                type="text"
                name="username"
                autocomplete="username"
                placeholder="Ví dụ: admin"
                value="<?= h($usernameInput) ?>"
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
            <label class="remember-toggle">
              <input type="checkbox" name="remember_me" value="1">
              <span>Giữ phiên đăng nhập lâu hơn</span>
            </label>
            <?php if ($lockInfo['locked']): ?>
              <small class="lock-note">Tạm khóa <?= h(human_seconds($lockInfo['remaining'])) ?></small>
            <?php endif; ?>
          </div>

          <button class="btn-auth-submit" type="submit">
            <i class="fa-solid fa-right-to-bracket"></i>
            <span>Đăng nhập</span>
          </button>
        </form>

        <div class="auth-help">
          <p><strong>Tài khoản mặc định để thử:</strong> admin / admin123</p>
          <p>Đổi mật khẩu ngay sau khi triển khai môi trường thật.</p>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
