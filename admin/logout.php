<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_authenticated()) {
  logout_current_user();
}

bootstrap_session();
flash_set('success', 'Bạn đã đăng xuất khỏi trang quản trị.');
redirect_to(admin_url('login.php'));
