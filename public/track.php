<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

/* Public tracking: reference number + date of birth, nothing else is revealed */
$pdo    = db();
$errors = [];
$result = null;
$ref    = trim((string)($_POST['ref'] ?? ''));
$dob    = trim((string)($_POST['dob'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'The form has expired. Please try again.';
    } elseif (too_many_attempts('track', 10, 600)) {
        $errors[] = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif ($ref === '' || !valid_date($dob)) {
        $errors[] = 'Enter your reference number and your date of birth.';
    } else {
        $st = $pdo->prepare(
            'SELECT a.application_id, a.receipt_no, a.application_type, a.application_date, a.status, a.rejection_reason, a.approval_date,
                    p.first_name, rc.centre_name, d.district_name,
                    c.card_status, c.issue_date, c.collection_date,
                    (SELECT COUNT(*) FROM supporting_document sd WHERE sd.application_id = a.application_id AND sd.verified = 1) AS verified_docs
             FROM application a
             JOIN person p ON p.person_id = a.person_id
             JOIN registration_centre rc ON rc.centre_id = a.centre_id
             JOIN district d ON d.district_id = rc.district_id
             LEFT JOIN id_card c ON c.application_id = a.application_id
             WHERE a.receipt_no = :r AND p.date_of_birth = :d'
        );
        $st->execute([':r' => $ref, ':d' => $dob]);
        $result = $st->fetch();
        if (!$result) {
            record_attempt('track');
            $errors[] = 'No application matches that reference number and date of birth. Check both and try again.';
        }
    }
}

public_header('Track your application', 'track');
?>
<div class="form-narrow">
<?php render_errors($errors); ?>
<?php if (!$result): ?>
<form method="post" action="track.php" class="card" novalidate>
  <?= csrf_field() ?>
  <h2>Find your application</h2>
  <div class="grid grid-2">
    <div><label for="ref">Reference number</label><input type="text" id="ref" name="ref" required placeholder="NRB-2026-123456" value="<?= e($ref) ?>"></div>
    <div><label for="dob">Date of birth</label><input type="date" id="dob" name="dob" required value="<?= e($dob) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Check status</button></div>
</form>
<?php else:
    $r = $result;
    $reviewed = in_array($r['status'], ['Approved', 'Rejected'], true);
    $hasCard  = $r['card_status'] !== null;
    $steps = [
        ['Application submitted', 'On ' . $r['application_date'] . '. Reference ' . $r['receipt_no'] . '.', 'done'],
        ['Documents verified at the centre', $r['verified_docs'] > 0 || $reviewed ? 'Your documents have been checked.' : 'Visit ' . $r['centre_name'] . ' with your original documents.', $r['verified_docs'] > 0 || $reviewed ? 'done' : 'current'],
    ];
    if ($r['status'] === 'Rejected') {
        $steps[] = ['Application not approved', $r['rejection_reason'] ?: 'Please visit the centre for details.', 'bad'];
    } else {
        $steps[] = ['Application approved', $r['status'] === 'Approved' ? 'Approved on ' . $r['approval_date'] . '.' : 'An officer will review your application.', $r['status'] === 'Approved' ? 'done' : ($r['verified_docs'] > 0 ? 'current' : '')];
        $steps[] = ['ID card printed', $hasCard ? 'Card issued on ' . $r['issue_date'] . '.' : 'Your card will be prepared after approval.', $hasCard ? 'done' : ($r['status'] === 'Approved' ? 'current' : '')];
        $steps[] = ['Card collected', $r['collection_date'] ? 'Collected on ' . $r['collection_date'] . '.' : ($hasCard ? 'Your card is ready. Collect it at ' . $r['centre_name'] . '.' : 'Collect at ' . $r['centre_name'] . ' when ready.'), $r['collection_date'] ? 'done' : ($hasCard ? 'current' : '')];
    }
?>
<div class="card">
  <h2>Hello <?= e($r['first_name']) ?>, here is your application</h2>
  <p>Reference <strong><?= e($r['receipt_no']) ?></strong> &middot; <?= e($r['application_type']) ?> &middot; <?= status_badge($r['status']) ?></p>
  <ul class="timeline">
    <?php foreach ($steps as [$title, $text, $state]): ?>
      <li class="<?= e($state) ?>"><strong><?= e($title) ?></strong><?= e($text) ?></li>
    <?php endforeach; ?>
  </ul>
  <div class="actions"><a class="btn btn-secondary" href="track.php">Check another application</a></div>
</div>
<?php endif; ?>
</div>
<?php public_footer();
