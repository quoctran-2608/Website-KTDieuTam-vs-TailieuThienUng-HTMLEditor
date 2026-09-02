<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (editorial_is_authenticated()) {
    editorial_redirect(editorial_url('dashboard.php'));
} else {
    editorial_redirect(editorial_url('login.php'));
}
