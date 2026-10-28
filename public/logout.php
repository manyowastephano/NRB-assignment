<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    unset($_SESSION['staff'], $_SESSION['after_login']);
    session_regenerate_id(true);
    flash('success', 'You have been logged out.');
}
redirect('index.php');
