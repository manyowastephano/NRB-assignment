<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

/*
 * Controlled deletion: a person can only be deleted when no ID card, no
 * verification log and no witness record depends on them. Applications
 * (and their documents) are removed first, inside one transaction.
 */
$pdo = db();
$id  = (int)($_GET['id'] ?? $_POST['person_id'] ?? 0);

$st = $pdo->prepare('SELECT person_id, first_name, surname, nid_number FROM person WHERE person_id = :id');
$st->execute([':id' => $id]);
$p = $st->fetch();
if (!$p) {
    flash('error', 'Applicant not found.');
    redirect('applicants.php');
}

function count_of(PDO $pdo, string $sql, int $id): int
{
    $s = $pdo->prepare($sql);
    $s->execute([':id' => $id]);
    return (int)$s->fetchColumn();
}

$cards    = count_of($pdo, 'SELECT COUNT(*) FROM id_card WHERE person_id = :id', $id);
$logs     = count_of($pdo, 'SELECT COUNT(*) FROM verification_log WHERE person_id = :id', $id);
$witness  = count_of($pdo, 'SELECT COUNT(*) FROM witness WHERE person_id = :id', $id);
$apps     = count_of($pdo, 'SELECT COUNT(*) FROM application WHERE person_id = :id', $id);
$parents  = count_of($pdo, 'SELECT COUNT(*) FROM parent_of WHERE parent_id = :id', $id)
          + count_of($pdo, 'SELECT COUNT(*) FROM parent_of WHERE child_id = :id', $id);
$blockers = [];
if ($cards)   { $blockers[] = "$cards ID card(s) have been issued to this person."; }
if ($logs)    { $blockers[] = "$logs verification request(s) from institutions are recorded for this person."; }
if ($witness) { $blockers[] = 'This person is registered as a witness for other applications.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash('error', 'The form has expired. Please try again.');
        redirect('applicant_delete.php?id=' . $id);
    }
    if ($blockers) {
        flash('error', 'The record cannot be deleted. ' . implode(' ', $blockers));
        redirect('applicant_view.php?id=' . $id);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM application WHERE person_id = :id')->execute([':id' => $id]);   // documents and witness links cascade
        $pdo->prepare('DELETE FROM person WHERE person_id = :id')->execute([':id' => $id]);        // citizen, phones, addresses, biometrics cascade
        $pdo->commit();
        flash('success', 'Applicant ' . $p['first_name'] . ' ' . $p['surname'] . ' and the related registration records were deleted.');
        redirect('applicants.php');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash('error', friendly_db_error($ex));
        redirect('applicant_view.php?id=' . $id);
    }
}

page_header('Delete applicant record', 'applicants');
?>
<div class="card">
  <h2><?= e($p['first_name'] . ' ' . $p['surname']) ?> <span class="muted">(<?= e($p['nid_number'] ?: 'no NID yet') ?>)</span></h2>
  <?php if ($blockers): ?>
    <div class="alert alert-error"><strong>This record cannot be deleted.</strong>
      <ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
      These records must be kept for the audit trail, so the database blocks the deletion.</div>
    <a class="btn btn-secondary" href="applicant_view.php?id=<?= $id ?>">Back to record</a>
  <?php else: ?>
    <div class="alert alert-info">Deleting this applicant will also permanently remove:
      <ul><li><?= $apps ?> application(s) with their supporting documents and witness links</li>
          <li>phone numbers, addresses, biometric samples and citizen / foreign-resident details</li>
          <li><?= $parents ?> parent/child link(s)</li></ul></div>
    <form method="post" action="applicant_delete.php?id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="person_id" value="<?= $id ?>">
      <button class="btn btn-danger" type="submit" data-confirm="Delete this applicant permanently?">Yes, delete permanently</button>
      <a class="btn btn-secondary" href="applicant_view.php?id=<?= $id ?>">Cancel</a>
    </form>
  <?php endif; ?>
</div>
<?php page_footer();
