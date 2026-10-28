<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

/*
 * Review page: the officer checks the applicant's details and documents,
 * then approves or rejects the application. This is where approval happens.
 */
$pdo   = db();
$me    = staff();
$id    = (int)($_GET['id'] ?? $_POST['application_id'] ?? 0);
$today = date('Y-m-d');

function load_application(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT a.*, p.person_id, p.first_name, p.middle_name, p.surname, p.maiden_name, p.date_of_birth, p.gender, p.place_of_birth,
                p.marital_status, p.nid_number, rc.centre_name, d.district_name,
                cap.employee_no AS cap_emp, CONCAT(cap.first_name, " ", cap.surname) AS cap_name,
                CONCAT(ap.first_name, " ", ap.surname) AS app_name,
                (SELECT COUNT(*) FROM id_card c WHERE c.application_id = a.application_id) AS has_card
         FROM application a
         JOIN person p ON p.person_id = a.person_id
         JOIN registration_centre rc ON rc.centre_id = a.centre_id
         JOIN district d ON d.district_id = rc.district_id
         JOIN officer cap ON cap.officer_id = a.captured_by
         LEFT JOIN officer ap ON ap.officer_id = a.approved_by
         WHERE a.application_id = :id'
    );
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

$app = load_application($pdo, $id);
if (!$app) {
    flash('error', 'Application not found.');
    redirect('applications.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    if (!csrf_valid()) {
        $errors[] = 'The form has expired. Please try again.';
    } elseif ($app['has_card']) {
        $errors[] = 'An ID card has already been issued, so this application can no longer be changed.';
    } elseif ($action === 'verify') {
        // Tick the documents that the officer has seen and checked
        $ticked = array_map('intval', (array)($_POST['verified'] ?? []));
        $segs = $pdo->prepare('SELECT doc_seg FROM supporting_document WHERE application_id = :a');
        $segs->execute([':a' => $id]);
        $upd = $pdo->prepare('UPDATE supporting_document SET verified = :v WHERE application_id = :a AND doc_seg = :s');
        foreach ($segs->fetchAll(PDO::FETCH_COLUMN) as $seg) {
            $upd->execute([':v' => in_array((int)$seg, $ticked, true) ? 1 : 0, ':a' => $id, ':s' => (int)$seg]);
        }
        flash('success', 'Document verification saved.');
        redirect('review.php?id=' . $id);
    } elseif ($app['status'] !== 'Pending') {
        $errors[] = 'Only applications that are waiting for review can be approved or rejected.';
    } elseif ($action === 'approve') {
        $ok = $pdo->prepare('SELECT (SELECT COUNT(*) FROM supporting_document WHERE application_id = :a1 AND verified = 1)
                                  + (SELECT COUNT(*) FROM witness_application WHERE application_id = :a2)');
        $ok->execute([':a1' => $id, ':a2' => $id]);
        if ((int)$ok->fetchColumn() === 0) {
            $errors[] = 'Verify at least one supporting document, or record a witness, before approving.';
        } else {
            try {
                // The reviewing officer takes over an online application as the capturing officer
                $newCapture = is_portal_officer($app['cap_emp']) ? $me['officer_id'] : (int)$app['captured_by'];
                $pdo->prepare("UPDATE application SET status = 'Approved', approved_by = :o, approval_date = :d, rejection_reason = NULL, captured_by = :c
                               WHERE application_id = :a AND status = 'Pending'")
                    ->execute([':o' => $me['officer_id'], ':d' => $today, ':c' => $newCapture, ':a' => $id]);
                flash('success', 'Application ' . $app['receipt_no'] . ' approved. You can now issue the ID card.');
                redirect('review.php?id=' . $id);
            } catch (PDOException $ex) {
                $errors[] = friendly_db_error($ex);
            }
        }
    } elseif ($action === 'reject') {
        $reason = post('rejection_reason');
        if ($reason === '' || strlen($reason) > 255) {
            $errors[] = 'Enter the reason for rejection (maximum 255 characters). The applicant will see it.';
        } else {
            try {
                $newCapture = is_portal_officer($app['cap_emp']) ? $me['officer_id'] : (int)$app['captured_by'];
                $pdo->prepare("UPDATE application SET status = 'Rejected', rejection_reason = :r, approved_by = NULL, approval_date = NULL, captured_by = :c
                               WHERE application_id = :a AND status = 'Pending'")
                    ->execute([':r' => $reason, ':c' => $newCapture, ':a' => $id]);
                flash('success', 'Application ' . $app['receipt_no'] . ' rejected.');
                redirect('review.php?id=' . $id);
            } catch (PDOException $ex) {
                $errors[] = friendly_db_error($ex);
            }
        }
    }
    $app = load_application($pdo, $id);
}

$q = $pdo->prepare('SELECT phone_no FROM person_phone WHERE person_id = :p');
$q->execute([':p' => $app['person_id']]);
$phones = $q->fetchAll(PDO::FETCH_COLUMN);

$q = $pdo->prepare('SELECT * FROM citizen WHERE person_id = :p'); $q->execute([':p' => $app['person_id']]); $citizen = $q->fetch();
$q = $pdo->prepare('SELECT * FROM foreign_resident WHERE person_id = :p'); $q->execute([':p' => $app['person_id']]); $foreign = $q->fetch();

$q = $pdo->prepare(
    'SELECT ad.village, ad.physical_description, ta.ta_name, d.district_name
     FROM resides_at r JOIN address ad ON ad.address_id = r.address_id
     JOIN traditional_authority ta ON ta.ta_id = ad.ta_id JOIN district d ON d.district_id = ta.district_id
     WHERE r.person_id = :p AND r.to_date IS NULL ORDER BY r.from_date DESC LIMIT 1'
);
$q->execute([':p' => $app['person_id']]);
$addr = $q->fetch();

$q = $pdo->prepare('SELECT * FROM supporting_document WHERE application_id = :a ORDER BY doc_seg');
$q->execute([':a' => $id]);
$docs = $q->fetchAll();

$q = $pdo->prepare(
    'SELECT w.witness_type, COALESCE(CONCAT(p.first_name, " ", p.surname), ta.ta_name) AS witness_name, wa.witness_date
     FROM witness_application wa JOIN witness w ON w.witness_id = wa.witness_id
     LEFT JOIN person p ON p.person_id = w.person_id LEFT JOIN traditional_authority ta ON ta.ta_id = w.ta_id
     WHERE wa.application_id = :a'
);
$q->execute([':a' => $id]);
$witnesses = $q->fetchAll();

$q = $pdo->prepare('SELECT card_id, card_serial_no FROM id_card WHERE application_id = :a');
$q->execute([':a' => $id]);
$card = $q->fetch();

$name = trim($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['surname']);
$online = is_portal_officer($app['cap_emp']);
$verifiedCount = count(array_filter($docs, fn($d) => $d['verified']));

page_header('Review application', 'review');
render_errors($errors);
?>
<p><a href="applications.php">&laquo; Back to applications</a></p>

<div class="card">
  <div class="card-head">
    <h2><?= e($name) ?> &middot; <?= e($app['receipt_no']) ?></h2>
    <span><?= status_badge($app['status']) ?> <?= $online ? '<span class="tag">Submitted online</span>' : '' ?></span>
  </div>
  <div class="grid grid-2">
    <dl class="details" style="grid-template-columns:9rem 1fr">
      <dt>Date of birth</dt><dd><?= e($app['date_of_birth']) ?></dd>
      <dt>Gender</dt><dd><?= $app['gender'] === 'M' ? 'Male' : 'Female' ?></dd>
      <dt>Place of birth</dt><dd><?= e($app['place_of_birth'] ?: '-') ?></dd>
      <dt>Marital status</dt><dd><?= e($app['marital_status'] ?: '-') ?></dd>
      <dt>Phone</dt><dd><?= e($phones ? implode(', ', $phones) : '-') ?></dd>
      <dt>Category</dt><dd><?= $citizen ? 'Citizen (' . e($citizen['citizenship_basis']) . ')' : ($foreign ? 'Foreign resident, permit ' . e($foreign['permit_no']) . ' expires ' . e($foreign['permit_expiry']) : '-') ?></dd>
    </dl>
    <dl class="details" style="grid-template-columns:9rem 1fr">
      <dt>Address</dt><dd><?= $addr ? e($addr['village']) . ', TA ' . e($addr['ta_name']) . ', ' . e($addr['district_name']) : '-' ?></dd>
      <dt>Application type</dt><dd><?= e($app['application_type']) ?></dd>
      <dt>Submitted</dt><dd><?= e($app['application_date']) ?></dd>
      <dt>Centre</dt><dd><?= e($app['centre_name']) ?></dd>
      <dt>Captured by</dt><dd><?= $online ? 'Online portal' : e($app['cap_name']) ?></dd>
      <dt>NID number</dt><dd><?= e($app['nid_number'] ?: 'Assigned when the card is issued') ?></dd>
    </dl>
  </div>
  <div class="actions"><a class="btn btn-sm btn-secondary" href="applicant_view.php?id=<?= (int)$app['person_id'] ?>">Full applicant record</a>
    <a class="btn btn-sm btn-secondary" href="applicant_edit.php?id=<?= (int)$app['person_id'] ?>">Correct details</a></div>
</div>

<div class="card">
  <h2>Step 1: Check the documents</h2>
  <?php if ($docs): ?>
  <form method="post" action="review.php?id=<?= $id ?>">
    <?= csrf_field() ?><input type="hidden" name="application_id" value="<?= $id ?>"><input type="hidden" name="action" value="verify">
    <div class="table-wrap"><table>
      <thead><tr><th>Verified</th><th>Document</th><th>Number</th><th>Issued by</th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): ?>
        <tr><td><input type="checkbox" name="verified[]" value="<?= (int)$d['doc_seg'] ?>"<?= $d['verified'] ? ' checked' : '' ?><?= $card ? ' disabled' : '' ?> aria-label="Verified"></td>
            <td><?= e($d['doc_type']) ?></td><td><?= e($d['doc_number'] ?: '-') ?></td><td><?= e($d['issuing_authority'] ?: '-') ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php if (!$card): ?><div class="actions"><button class="btn btn-secondary" type="submit">Save verification</button></div><?php endif; ?>
  </form>
  <?php else: ?><p class="muted">The applicant did not list any document.</p><?php endif; ?>
  <p style="margin-bottom:0"><strong>Witnesses:</strong>
    <?php foreach ($witnesses as $w): ?><?= e($w['witness_name']) ?> (<?= e($w['witness_type']) ?>, <?= e($w['witness_date']) ?>); <?php endforeach; ?><?= $witnesses ? '' : '<span class="muted">none recorded</span>' ?></p>
</div>

<div class="card decision">
  <h2>Step 2: Decision</h2>
  <?php if ($card): ?>
    <div class="alert alert-info">Approved, and ID card <strong><?= e($card['card_serial_no']) ?></strong> has been issued. <a href="card_edit.php?id=<?= (int)$card['card_id'] ?>">Open card</a></div>
  <?php elseif ($app['status'] === 'Approved'): ?>
    <div class="alert alert-success">Approved by <?= e($app['app_name']) ?> on <?= e($app['approval_date']) ?>.</div>
    <a class="btn" href="cards.php?application=<?= $id ?>#issue">Issue ID card</a>
  <?php elseif ($app['status'] === 'Rejected'): ?>
    <div class="alert alert-error">Rejected. Reason shown to the applicant: <?= e($app['rejection_reason']) ?></div>
  <?php else: ?>
    <p>Reviewing as <strong><?= e($me['name']) ?></strong>. Documents verified: <?= $verifiedCount ?> of <?= count($docs) ?>.</p>
    <div class="grid grid-2">
      <form method="post" action="review.php?id=<?= $id ?>">
        <?= csrf_field() ?><input type="hidden" name="application_id" value="<?= $id ?>"><input type="hidden" name="action" value="approve">
        <button class="btn" type="submit" data-confirm="Approve this application?">Approve application</button>
        <div class="help">Needs at least one verified document or a witness.</div>
      </form>
      <form method="post" action="review.php?id=<?= $id ?>">
        <?= csrf_field() ?><input type="hidden" name="application_id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
        <label for="rejection_reason">Reason for rejection</label>
        <input type="text" id="rejection_reason" name="rejection_reason" maxlength="255" placeholder="e.g. Birth certificate not valid">
        <div class="actions"><button class="btn btn-danger" type="submit" data-confirm="Reject this application?">Reject application</button></div>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php page_footer();
