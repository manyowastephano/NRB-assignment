<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

if (staff()) {
    redirect('dashboard.php');
}

$pdo    = db();
$errors = [];
$empNo  = '';
$password = env('STAFF_PASSWORD');

if ($password === '') {
    $errors[] = 'Staff login is not configured. Add STAFF_PASSWORD=your_password to the .env file and refresh this page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $password !== '') {
    $empNo = post('employee_no');
    if (!csrf_valid()) {
        $errors[] = 'The form has expired. Please try again.';
    } elseif (too_many_attempts('login', 5, 300)) {
        $errors[] = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $st = $pdo->prepare(
            'SELECT o.officer_id, o.employee_no, o.first_name, o.surname, o.job_role, o.centre_id, c.centre_name
             FROM officer o JOIN registration_centre c ON c.centre_id = o.centre_id
             WHERE o.employee_no = :e AND o.employee_no <> :p'
        );
        $st->execute([':e' => $empNo, ':p' => PORTAL_EMPLOYEE_NO]);
        $o = $st->fetch();
        if ($o && hash_equals($password, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['staff'] = [
                'officer_id'  => (int)$o['officer_id'],
                'employee_no' => $o['employee_no'],
                'name'        => $o['first_name'] . ' ' . $o['surname'],
                'role'        => $o['job_role'] ?: 'Officer',
                'centre_id'   => (int)$o['centre_id'],
                'centre'      => $o['centre_name'],
            ];
            unset($_SESSION['attempts']['login']);
            $target = $_SESSION['after_login'] ?? 'dashboard.php';
            unset($_SESSION['after_login']);
            if (!preg_match('#^/?[A-Za-z0-9_./?=&%-]*$#', $target) || str_contains($target, '//')) { $target = 'dashboard.php'; }
            redirect(basename(parse_url($target, PHP_URL_PATH) ?: 'dashboard.php') . (parse_url($target, PHP_URL_QUERY) ? '?' . parse_url($target, PHP_URL_QUERY) : ''));
        }
        record_attempt('login');
        $errors[] = 'Invalid employee number or password.';
    }
}

public_header('Staff login', '');
?>
<div class="card login-box">
  <h2>Registration staff login</h2>
  <p class="muted">For NRB officers only. Applicants do not need to log in; they can <a href="apply.php">apply here</a>.</p>
  <?php render_errors($errors); ?>
  <form method="post" action="login.php" autocomplete="off">
    <?= csrf_field() ?>
    <div style="margin-bottom:.8rem"><label for="employee_no">Employee number</label>
      <input type="text" id="employee_no" name="employee_no" required maxlength="20" placeholder="NRB-0101" value="<?= e($empNo) ?>"></div>
    <div style="margin-bottom:.8rem"><label for="password">Password</label>
      <input type="password" id="password" name="password" required style="width:100%;padding:.52rem .65rem;border:1px solid #B5C0A8;border-radius:6px;font:inherit"></div>
    <button class="btn" type="submit">Log in</button>
  </form>
</div>
<?php public_footer();
