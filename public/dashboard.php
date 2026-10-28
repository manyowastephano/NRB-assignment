<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo = db();
$count = static fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();

$totalPersons  = $count('SELECT COUNT(*) FROM person');
$pending       = $count("SELECT COUNT(*) FROM application WHERE status = 'Pending'");
$approved      = $count("SELECT COUNT(*) FROM application WHERE status = 'Approved'");
$rejected      = $count("SELECT COUNT(*) FROM application WHERE status = 'Rejected'");
$activeCards   = $count("SELECT COUNT(*) FROM id_card WHERE card_status = 'Active'");
$uncollected   = $count('SELECT COUNT(*) FROM id_card WHERE collection_date IS NULL');
$totalApps     = max(1, $pending + $approved + $rejected);
$portalId      = portal_officer_id($pdo);
$onlineNew     = (int)$pdo->query("SELECT COUNT(*) FROM application WHERE status = 'Pending' AND captured_by = " . $portalId)->fetchColumn();

$stats = [
    ['Registered persons',       $totalPersons, 'applicants.php',            'people'],
    ['New online applications',  $onlineNew,    'applications.php?status=Pending&source=online', 'inbox'],
    ['Pending applications',     $pending,      'applications.php?status=Pending', 'clock'],
    ['Approved applications',    $approved,     'applications.php?status=Approved', 'check'],
    ['Active ID cards',          $activeCards,  'cards.php?status=Active',   'card'],
    ['Cards not yet collected',  $uncollected,  'cards.php',                 'box'],
];

// Recent applications (JOIN of application, person, registration_centre, district)
$recent = $pdo->query(
    'SELECT a.application_id, a.receipt_no, a.application_date, a.status, p.person_id,
            CONCAT(p.first_name, " ", p.surname) AS applicant, rc.centre_name, d.district_name
     FROM application a
     JOIN person p ON p.person_id = a.person_id
     JOIN registration_centre rc ON rc.centre_id = a.centre_id
     JOIN district d ON d.district_id = rc.district_id
     ORDER BY a.application_date DESC, a.application_id DESC LIMIT 6'
)->fetchAll();

// Work that needs attention
$toApprove = $pdo->query(
    "SELECT a.application_id, a.receipt_no, p.person_id, CONCAT(p.first_name, ' ', p.surname) AS applicant, a.application_date
     FROM application a JOIN person p ON p.person_id = a.person_id
     WHERE a.status = 'Pending' ORDER BY a.application_date LIMIT 4"
)->fetchAll();
$toIssue = $pdo->query(
    "SELECT a.application_id, a.receipt_no, CONCAT(p.first_name, ' ', p.surname) AS applicant
     FROM application a JOIN person p ON p.person_id = a.person_id
     LEFT JOIN id_card c ON c.application_id = a.application_id
     WHERE a.status = 'Approved' AND c.card_id IS NULL ORDER BY a.approval_date LIMIT 4"
)->fetchAll();

// Registered persons per district (current address)
$byDistrict = $pdo->query(
    'SELECT d.district_name, COUNT(*) AS total
     FROM resides_at r
     JOIN address a ON a.address_id = r.address_id
     JOIN traditional_authority ta ON ta.ta_id = a.ta_id
     JOIN district d ON d.district_id = ta.district_id
     WHERE r.to_date IS NULL
     GROUP BY d.district_id, d.district_name ORDER BY total DESC LIMIT 5'
)->fetchAll();
$maxDistrict = max(1, (int)($byDistrict[0]['total'] ?? 1));

$icons = [
    'people' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><path d="M17 4a4 4 0 010 8M22 21c0-3-2-5.5-5-6.5"/>',
    'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'check'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
    'card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    'inbox'  => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 01-2 2H4a2 2 0 01-2-2v-6z"/>',
    'box'    => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
];

page_header('Dashboard', 'home');
?>
<section class="hero">
  <div>
    <h2>Welcome, <?= e(explode(' ', staff()['name'])[0]) ?></h2>
    <p><?= $onlineNew ?> new online application<?= $onlineNew === 1 ? '' : 's' ?> to review. <?= $pending ?> application<?= $pending === 1 ? ' is' : 's are' ?> waiting for approval in total and
       <?= count($toIssue) ?> approved application<?= count($toIssue) === 1 ? '' : 's' ?> still need<?= count($toIssue) === 1 ? 's' : '' ?> a card.</p>
  </div>
  <div class="hero-actions">
    <a class="btn btn-light" href="applications.php?status=Pending">Review applications</a>
    <a class="btn btn-ghost" href="register.php">Walk-in registration</a>
  </div>
</section>

<div class="stats">
  <?php foreach ($stats as [$label, $num, $link, $icon]): ?>
    <a class="stat" href="<?= e($link) ?>">
      <span class="stat-icon"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
      <span class="stat-body"><span class="num"><?= e($num) ?></span><span class="lbl"><?= e($label) ?></span></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="dash-grid">
  <div class="card">
    <div class="card-head"><h2>Recent applications</h2><a href="applications.php">View all</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Receipt no.</th><th>Applicant</th><th>District</th><th>Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="nowrap"><?= e($r['receipt_no']) ?></td>
            <td class="nowrap"><a href="applicant_view.php?id=<?= (int)$r['person_id'] ?>"><?= e($r['applicant']) ?></a></td>
            <td><?= e($r['district_name']) ?></td>
            <td class="nowrap"><?= e($r['application_date']) ?></td>
            <td class="nowrap"><?= status_badge($r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="5" class="muted">No applications yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="dash-side">
    <div class="card">
      <div class="card-head"><h2>Application status</h2></div>
      <?php foreach ([['Approved', $approved, 'bar-approved'], ['Pending', $pending, 'bar-pending'], ['Rejected', $rejected, 'bar-rejected']] as [$lbl, $n, $cls]): ?>
        <div class="bar-row">
          <span class="bar-label"><?= e($lbl) ?></span>
          <span class="bar-track"><span class="bar <?= e($cls) ?>" style="width: <?= round($n / $totalApps * 100) ?>%"></span></span>
          <span class="bar-num"><?= $n ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-head"><h2>Needs attention</h2></div>
      <?php if (!$toApprove && !$toIssue): ?><p class="muted" style="margin:0">Nothing is waiting. Well done.</p><?php endif; ?>
      <ul class="task-list">
        <?php foreach ($toApprove as $t): ?>
          <li><span class="dot dot-pending"></span>
            <span><a href="review.php?id=<?= (int)$t['application_id'] ?>"><?= e($t['applicant']) ?></a><small>Review <?= e($t['receipt_no']) ?></small></span></li>
        <?php endforeach; ?>
        <?php foreach ($toIssue as $t): ?>
          <li><span class="dot dot-approved"></span>
            <span><a href="cards.php?application=<?= (int)$t['application_id'] ?>#issue"><?= e($t['applicant']) ?></a><small>Issue card for <?= e($t['receipt_no']) ?></small></span></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card">
      <div class="card-head"><h2>Applicants by district</h2></div>
      <?php foreach ($byDistrict as $d): ?>
        <div class="bar-row">
          <span class="bar-label"><?= e($d['district_name']) ?></span>
          <span class="bar-track"><span class="bar bar-primary" style="width: <?= round($d['total'] / $maxDistrict * 100) ?>%"></span></span>
          <span class="bar-num"><?= (int)$d['total'] ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$byDistrict): ?><p class="muted" style="margin:0">No address data.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer();
