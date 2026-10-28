<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM person WHERE person_id = :id');
$st->execute([':id' => $id]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    flash('error', 'Applicant not found.');
    redirect('applicants.php');
}

$cit = $pdo->prepare('SELECT * FROM citizen WHERE person_id = :id');
$cit->execute([':id' => $id]);
$citizen = $cit->fetch();

$fr = $pdo->prepare('SELECT * FROM foreign_resident WHERE person_id = :id');
$fr->execute([':id' => $id]);
$foreign = $fr->fetch();

$ph = $pdo->prepare('SELECT phone_no FROM person_phone WHERE person_id = :id ORDER BY phone_no');
$ph->execute([':id' => $id]);
$phones = $ph->fetchAll(PDO::FETCH_COLUMN);

$ad = $pdo->prepare(
    'SELECT r.from_date, r.to_date, r.address_type, a.village, a.physical_description, a.postal_box, ta.ta_name, d.district_name
     FROM resides_at r
     JOIN address a ON a.address_id = r.address_id
     JOIN traditional_authority ta ON ta.ta_id = a.ta_id
     JOIN district d ON d.district_id = ta.district_id
     WHERE r.person_id = :id ORDER BY r.from_date DESC'
);
$ad->execute([':id' => $id]);
$addresses = $ad->fetchAll();

$par = $pdo->prepare(
    'SELECT po.parent_type, p.person_id, CONCAT(p.first_name, " ", p.surname) AS name, "Parent" AS rel
     FROM parent_of po JOIN person p ON p.person_id = po.parent_id WHERE po.child_id = :id1
     UNION ALL
     SELECT po.parent_type, p.person_id, CONCAT(p.first_name, " ", p.surname), "Child"
     FROM parent_of po JOIN person p ON p.person_id = po.child_id WHERE po.parent_id = :id2'
);
$par->execute([':id1' => $id, ':id2' => $id]);
$family = $par->fetchAll();

$bio = $pdo->prepare('SELECT biometric_type, quality_score, captured_on FROM biometric WHERE person_id = :id ORDER BY captured_on');
$bio->execute([':id' => $id]);
$biometrics = $bio->fetchAll();

$ap = $pdo->prepare(
    'SELECT a.*, rc.centre_name,
            CONCAT(c.first_name, " ", c.surname) AS captured_name,
            CONCAT(o.first_name, " ", o.surname) AS approved_name,
            ic.card_id, ic.card_serial_no
     FROM application a
     JOIN registration_centre rc ON rc.centre_id = a.centre_id
     JOIN officer c ON c.officer_id = a.captured_by
     LEFT JOIN officer o ON o.officer_id = a.approved_by
     LEFT JOIN id_card ic ON ic.application_id = a.application_id
     WHERE a.person_id = :id ORDER BY a.application_date DESC, a.application_id DESC'
);
$ap->execute([':id' => $id]);
$applications = $ap->fetchAll();

$docSt = $pdo->prepare('SELECT * FROM supporting_document WHERE application_id = :a ORDER BY doc_seg');
$witSt = $pdo->prepare(
    'SELECT w.witness_type, COALESCE(CONCAT(p.first_name, " ", p.surname), ta.ta_name) AS witness_name, wa.witness_date
     FROM witness_application wa
     JOIN witness w ON w.witness_id = wa.witness_id
     LEFT JOIN person p ON p.person_id = w.person_id
     LEFT JOIN traditional_authority ta ON ta.ta_id = w.ta_id
     WHERE wa.application_id = :a'
);

$cd = $pdo->prepare('SELECT * FROM id_card WHERE person_id = :id ORDER BY issue_date DESC');
$cd->execute([':id' => $id]);
$cards = $cd->fetchAll();

$fullName = trim($p['first_name'] . ' ' . $p['middle_name'] . ' ' . $p['surname']);
$canIssue = false;
foreach ($applications as $a) {
    if ($a['status'] === 'Approved' && !$a['card_id']) { $canIssue = true; }
}

page_header($fullName, 'applicants');
?>
<div class="actions" style="margin-top:0;margin-bottom:1rem">
  <a class="btn" href="applicant_edit.php?id=<?= $id ?>">Edit / update</a>
  <?php if ($canIssue): ?><a class="btn btn-secondary" href="cards.php?application=<?= (int)array_values(array_filter($applications, fn($a) => $a['status'] === 'Approved' && !$a['card_id']))[0]['application_id'] ?>#issue">Issue ID card</a><?php endif; ?>
  <a class="btn btn-danger" href="applicant_delete.php?id=<?= $id ?>">Delete record</a>
  <a class="btn btn-secondary" href="applicants.php">Back to list</a>
</div>

<div class="card">
  <h2>Personal details</h2>
  <dl class="details">
    <dt>NID number</dt><dd><?= $p['nid_number'] ? e($p['nid_number']) : '<span class="muted">Not yet assigned (assigned when the first card is issued)</span>' ?></dd>
    <dt>Full name</dt><dd><?= e($fullName) ?></dd>
    <?php if ($p['maiden_name']): ?><dt>Maiden name</dt><dd><?= e($p['maiden_name']) ?></dd><?php endif; ?>
    <dt>Date of birth</dt><dd><?= e($p['date_of_birth']) ?> <span class="muted">(<?= (new DateTime($p['date_of_birth']))->diff(new DateTime($p['date_of_death'] ?: 'today'))->y ?> years)</span></dd>
    <dt>Gender</dt><dd><?= $p['gender'] === 'M' ? 'Male' : 'Female' ?></dd>
    <dt>Place of birth</dt><dd><?= e($p['place_of_birth'] ?: '-') ?></dd>
    <dt>Marital status</dt><dd><?= e($p['marital_status'] ?: '-') ?></dd>
    <dt>Life status</dt><dd><?= e($p['life_status']) ?><?= $p['date_of_death'] ? ' (died ' . e($p['date_of_death']) . ')' : '' ?></dd>
    <dt>Phone numbers</dt><dd><?= $phones ? e(implode(', ', $phones)) : '-' ?></dd>
    <?php if ($citizen): ?>
      <dt>Category</dt><dd>Citizen (<?= e($citizen['citizenship_basis']) ?>)</dd>
      <dt>Certificate no.</dt><dd><?= e($citizen['citizenship_cert_no'] ?: '-') ?></dd>
    <?php elseif ($foreign): ?>
      <dt>Category</dt><dd>Foreign resident</dd>
      <dt>Passport / country</dt><dd><?= e($foreign['passport_no']) ?> &middot; <?= e($foreign['country_of_origin']) ?></dd>
      <dt>Permit</dt><dd><?= e($foreign['permit_no']) ?> (expires <?= e($foreign['permit_expiry']) ?>)</dd>
    <?php endif; ?>
  </dl>
</div>

<div class="card">
  <h2>Addresses</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Village</th><th>Traditional Authority</th><th>District</th><th>Type</th><th>From</th><th>To</th></tr></thead>
    <tbody>
    <?php foreach ($addresses as $a): ?>
      <tr><td><?= e($a['village']) ?><?= $a['physical_description'] ? '<br><span class="muted">' . e($a['physical_description']) . '</span>' : '' ?></td>
          <td><?= e($a['ta_name']) ?></td><td><?= e($a['district_name']) ?></td><td><?= e($a['address_type']) ?></td>
          <td><?= e($a['from_date']) ?></td><td><?= $a['to_date'] ? e($a['to_date']) : 'Current' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$addresses): ?><tr><td colspan="6" class="muted">No address recorded.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="card">
  <h2>Applications</h2>
  <?php foreach ($applications as $a):
      $docSt->execute([':a' => $a['application_id']]); $docs = $docSt->fetchAll();
      $witSt->execute([':a' => $a['application_id']]); $wits = $witSt->fetchAll(); ?>
    <div style="border-top:1px solid var(--line);padding:.7rem 0">
      <strong><?= e($a['receipt_no']) ?></strong> &middot; <?= e($a['application_type']) ?> <?= status_badge($a['status']) ?>
      <?= $a['late_registration'] ? '<span class="badge badge-pending">Late registration</span>' : '' ?>
      <dl class="details" style="margin-top:.4rem">
        <dt>Form / date</dt><dd><?= e($a['form_no']) ?> &middot; <?= e($a['application_date']) ?></dd>
        <dt>Centre</dt><dd><?= e($a['centre_name']) ?></dd>
        <dt>Captured by</dt><dd><?= e($a['captured_name']) ?></dd>
        <dt>Approved by</dt><dd><?= $a['approved_name'] ? e($a['approved_name']) . ' on ' . e($a['approval_date']) : '-' ?></dd>
        <?php if ($a['rejection_reason']): ?><dt>Rejection reason</dt><dd><?= e($a['rejection_reason']) ?></dd><?php endif; ?>
        <dt>Documents</dt><dd><?php foreach ($docs as $d): ?><?= e($d['doc_type']) ?> <?= e($d['doc_number']) ?> <?= $d['verified'] ? '(verified)' : '(not verified)' ?>; <?php endforeach; ?><?= $docs ? '' : '-' ?></dd>
        <dt>Witnesses</dt><dd><?php foreach ($wits as $w): ?><?= e($w['witness_name']) ?> (<?= e($w['witness_type']) ?>, <?= e($w['witness_date']) ?>); <?php endforeach; ?><?= $wits ? '' : '-' ?></dd>
        <?php if ($a['status'] === 'Pending'): ?><dt>Action</dt><dd><a class="btn btn-sm" href="review.php?id=<?= (int)$a['application_id'] ?>">Review and approve</a></dd><?php endif; ?>
        <dt>ID card</dt><dd><?= $a['card_id'] ? '<a href="card_edit.php?id=' . (int)$a['card_id'] . '">' . e($a['card_serial_no']) . '</a>' : '-' ?></dd>
      </dl>
    </div>
  <?php endforeach; ?>
  <?php if (!$applications): ?><p class="muted">No applications.</p><?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>ID cards</h2>
    <?php if ($cards): ?>
    <table><thead><tr><th>Serial no.</th><th>Issued</th><th>Expires</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($cards as $c): ?>
        <tr><td><a href="card_edit.php?id=<?= (int)$c['card_id'] ?>"><?= e($c['card_serial_no']) ?></a></td><td><?= e($c['issue_date']) ?></td>
            <td><?= e($c['expiry_date']) ?></td><td><?= status_badge($c['card_status']) ?></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php else: ?><p class="muted">No card has been issued.</p><?php endif; ?>
  </div>
  <div class="card">
    <h2>Family links and biometrics</h2>
    <?php foreach ($family as $f): ?>
      <p style="margin:.2rem 0"><?= e($f['rel']) ?>: <a href="applicant_view.php?id=<?= (int)$f['person_id'] ?>"><?= e($f['name']) ?></a> (<?= e($f['parent_type']) ?>)</p>
    <?php endforeach; ?>
    <?php if (!$family): ?><p class="muted" style="margin:.2rem 0">No parent or child links recorded.</p><?php endif; ?>
    <hr style="border:0;border-top:1px solid var(--line)">
    <?php foreach ($biometrics as $b): ?>
      <p style="margin:.2rem 0"><?= e($b['biometric_type']) ?> &middot; quality <?= e($b['quality_score']) ?> &middot; <?= e($b['captured_on']) ?></p>
    <?php endforeach; ?>
    <?php if (!$biometrics): ?><p class="muted" style="margin:.2rem 0">No biometric samples captured.</p><?php endif; ?>
  </div>
</div>
<?php page_footer();
