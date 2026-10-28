<?php
declare(strict_types=1);

function nav_icon(string $name): string
{
    $paths = [
        'home'       => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'register'   => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M19 8v6M16 11h6"/>',
        'applicants' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M17 4a4 4 0 010 8M22 21c0-3-2-5.5-5-6.5"/>',
        'cards'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'review'     => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v2h6V3M9 12l2 2 4-4"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function page_header(string $title, string $active = ''): void
{
    $nav = [
        'home'       => ['dashboard.php', 'Dashboard'],
        'review'     => ['applications.php', 'Applications'],
        'register'   => ['register.php', 'Walk-in Registration'],
        'applicants' => ['applicants.php', 'Applicants'],
        'cards'      => ['cards.php', 'ID Cards'],
    ];
    $me = staff();
    $pendingCount = $me ? (int)db()->query("SELECT COUNT(*) FROM application WHERE status = 'Pending'")->fetchColumn() : 0;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | NRB National ID System</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <span class="brand-mark">NRB</span>
      
    </div>
    <nav class="side-nav" aria-label="Main navigation">
      <span class="nav-label">Staff menu</span>
      <?php foreach ($nav as $key => [$url, $label]): ?>
        <a href="<?= e($url) ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= nav_icon($key) ?><span><?= e($label) ?></span><?= $key === 'review' && $pendingCount > 0 ? '<b class="nav-count">' . $pendingCount . '</b>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <div class="who"><strong><?= e($me['name'] ?? '') ?></strong><small><?= e($me['role'] ?? '') ?><br><?= e($me['centre'] ?? '') ?></small></div>
      <form method="post" action="logout.php"><?= csrf_field() ?><button class="logout" type="submit"><?= nav_icon('logout') ?> Log out</button></form>
    </div>
  </aside>

  <div class="content">
    <header class="topbar">
      <button class="menu-btn" id="menu-btn" type="button" aria-label="Open menu" aria-controls="sidebar">
        <svg class="icon" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <h1><?= e($title) ?></h1>
      <span class="today"><?= e(date('l, j F Y')) ?></span>
    </header>
    <main class="page">
      <?php render_flash(); ?>
<?php
}

function page_footer(): void
{
    ?>
    </main>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}

function status_badge(string $status): string
{
    $class = strtolower(str_replace(' ', '-', $status));
    return '<span class="badge badge-' . e($class) . '">' . e($status) . '</span>';
}

/** Show a list of validation errors */
function render_errors(array $errors): void
{
    if (!$errors) {
        return;
    }
    echo '<div class="alert alert-error"><strong>Please correct the following:</strong><ul>';
    foreach ($errors as $err) {
        echo '<li>' . e($err) . '</li>';
    }
    echo '</ul></div>';
}


/** Layout for the public portal (no sidebar, no login needed) */
function public_header(string $title, string $active = ''): void
{
    $links = ['home' => ['index.php', 'Home'], 'apply' => ['apply.php', 'Apply for an ID'], 'track' => ['track.php', 'Track application']];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | NRB National ID Portal</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="public">
<header class="pub-header">
  <div class="pub-inner">
    <a class="pub-brand" href="index.php"><span class="brand-mark">NRB</span><span>National ID Portal<small>National Registration Bureau, Malawi</small></span></a>
    <nav class="pub-nav" aria-label="Main navigation">
      <?php foreach ($links as $k => [$url, $label]): ?>
        <a href="<?= e($url) ?>"<?= $k === $active ? ' class="active"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <a class="staff-link" href="<?= staff() ? 'dashboard.php' : 'login.php' ?>"><?= staff() ? 'Staff dashboard' : 'Staff login' ?></a>
    </nav>
  </div>
</header>
<main class="pub-main">
  <?php render_flash(); ?>
<?php
}

function public_footer(): void
{
    ?>
</main>
<footer class="pub-footer">National Registration Bureau, Malawi &middot;</footer>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}
