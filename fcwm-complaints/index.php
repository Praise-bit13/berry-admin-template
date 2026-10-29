<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/app.php';

if (!database_is_installed()) {
    redirect('setup.php');
}

$user = current_user();
if ($user !== null) {
    redirect($user['role'] . '/dashboard.php');
}
redirect('login.php');
