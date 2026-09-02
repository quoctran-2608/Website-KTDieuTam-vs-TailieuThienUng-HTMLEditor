<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

editorial_logout();
editorial_flash_set('success', 'Đã đăng xuất thành công.');
editorial_redirect(editorial_url('login.php'));
