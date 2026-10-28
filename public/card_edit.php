<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo = db();
$id  = (int)($_GET['id'] ?? $_POST['card_id'] ?? 0);
$st = $pdo->prepare(
    'SELECT c.*, p.nid_number, CONCAT(p.first_name, " ", p.surname) AS holder, a.receipt_no
     FROM id_card c JOIN person p ON p.person_id = c.person_id JOIN application a ON a.application_id = c.application_id
     WHERE c.card_id = :id'
);
$st->execute([':id' => $id]);
$card = $st->fetch();
if (!$card) {
    flash('error', 'Card not found.');
    redirect('cards.php');
}

$errors = [];
$today  = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { $errors[] = 'The form has expired. Please try again.'; }
    if (!in_array(post('card_status'), CARD_STATUSES, true)) { $errors[] = 'Select a valid card status.'; }
    if (post('collection_date') !== '') {
        if (!valid_date(post('collection_date')) || post('collection_date') > $today) { $errors[] = 'Collection date must be a valid date that is not in the future.'; }
        elseif (post('collection_date') < $card['issue_date']) { $errors[] = 'Collection date cannot be before the issue date (' . $card['issue_date'] . ').'; }
    }
    if (!$errors) {
        try {
            $pdo->prepare('UPDATE id_card SET card_status = :s, collection_date = :c WHERE card_id = :id')
                ->execute([':s' => post('card_status'), ':c' => nullable(post('collection_date')), ':id' => $id]);
            flash('success', 'Card ' . $card['card_serial_no'] . ' updated successfully.');
            redirect('cards.php');
        } catch (PDOException $ex) {
            $errors[] = friendly_db_error($ex);
        }
    }
}
$v = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_map('trim', array_map('strval', $_POST))
   : ['card_status' => $card['card_status'], 'collection_date' => (string)$card['collection_date']];

page_header('Update ID card', 'cards');
render_errors($errors);
?>
<div class="card">
  <dl class="details">
    <dt>Serial number</dt><dd><?= e($card['card_serial_no']) ?></dd>
    <dt>Holder</dt><dd><a href="applicant_view.php?id=<?= (int)$card['person_id'] ?>"><?= e($card['holder']) ?></a></dd>
    <dt>NID number</dt><dd><?= e($card['nid_number']) ?></dd>
    <dt>Application</dt><dd><?= e($card['receipt_no']) ?></dd>
    <dt>Card type</dt><dd><?= e($card['card_type']) ?></dd>
    <dt>Issued / expires</dt><dd><?= e($card['issue_date']) ?> to <?= e($card['expiry_date']) ?></dd>
  </dl>
</div>
<form method="post" action="card_edit.php?id=<?= $id ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="card_id" value="<?= $id ?>">
  <fieldset>
    <legend>Status and collection</legend>
    <div class="grid grid-2">
      <div><label for="card_status">Card status</label>
        <select id="card_status" name="card_status"><?php foreach (CARD_STATUSES as $s): ?><option<?= selected($v['card_status'] ?? '', $s) ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div><label for="collection_date">Collection date</label>
        <input type="date" id="collection_date" name="collection_date" max="<?= $today ?>" value="<?= old($v, 'collection_date') ?>">
        <div class="help">Leave blank if the card has not been collected yet.</div></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Save changes</button><a class="btn btn-secondary" href="cards.php">Cancel</a></div>
  </fieldset>
</form>
<?php page_footer();
