<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_authenticated()) {
  redirect_to(admin_url('dashboard.php'));
}

redirect_to(admin_url('login.php'));

