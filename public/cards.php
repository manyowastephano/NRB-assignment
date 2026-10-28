<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo    = db();
$errors = [];
$today  = date('Y-m-d');
$data   = ['application_id' => (string)($_GET['application'] ?? ''), 'issue_date' => $today,
           'expiry_date' => date('Y-m-d', strtotime('+10 years'))];

/** Generate a unique NID number such as MW4KD92LA */
function generate_nid(PDO $pdo): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $nid = 'MW';
        for ($i = 0; $i < 7; $i++) { $nid .= $chars[random_int(0, strlen($chars) - 1)]; }
        $s = $pdo->prepare('SELECT 1 FROM person WHERE nid_number = :n');
        $s->execute([':n' => $nid]);
    } while ($s->fetchColumn());
    return $nid;
}

function generate_serial(PDO $pdo, string $date): string
{
    do {
        $sn = 'SN-' . substr($date, 0, 4) . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $s = $pdo->prepare('SELECT 1 FROM id_card WHERE card_serial_no = :n');
        $s->execute([':n' => $sn]);
    } while ($s->fetchColumn());
    return $sn;
}

// ---------------- Issue a new card ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    if (!csrf_valid()) { $errors[] = 'The form has expired. Please try again.'; }

    $appId = (int)post('application_id');
    $st = $pdo->prepare(
        'SELECT a.application_id, a.status, a.person_id, a.application_date, p.nid_number,
                (SELECT COUNT(*) FROM id_card c WHERE c.application_id = a.application_id) AS has_card,
                fr.permit_expiry
         FROM application a
         JOIN person p ON p.person_id = a.person_id
         LEFT JOIN foreign_resident fr ON fr.person_id = a.person_id
         WHERE a.application_id = :a'
    );
    $st->execute([':a' => $appId]);
    $app = $st->fetch();

    if (!$app)                                { $errors[] = 'Select an approved application.'; }
    elseif ($app['status'] !== 'Approved')    { $errors[] = 'A card can only be issued for an approved application.'; }
    elseif ($app['has_card'])                 { $errors[] = 'A card has already been issued for this application.'; }

    if (!valid_date(post('issue_date')) || post('issue_date') > $today) { $errors[] = 'Issue date must be a valid date that is not in the future.'; }
    elseif ($app && post('issue_date') < $app['application_date'])      { $errors[] = 'Issue date cannot be before the application date.'; }
    if (!valid_date(post('expiry_date')))                                { $errors[] = 'Expiry date must be a valid date.'; }
    elseif (valid_date(post('issue_date')) && post('expiry_date') <= post('issue_date')) { $errors[] = 'Expiry date must be after the issue date.'; }
    if ($app && $app['permit_expiry'] && valid_date(post('expiry_date')) && post('expiry_date') > $app['permit_expiry']) {
        $errors[] = 'A foreign resident\'s card cannot expire after the residence permit (' . $app['permit_expiry'] . ').';
    }
    if (post('card_serial_no') !== '' && !preg_match('/^[A-Za-z0-9\-]{6,20}$/', post('card_serial_no'))) {
        $errors[] = 'Card serial number must be 6 to 20 letters, digits or "-" (leave blank to generate one).';
    }
    if (post('card_type') !== '' && strlen(post('card_type')) > 20) { $errors[] = 'Card type is too long.'; }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $serial = post('card_serial_no') !== '' ? post('card_serial_no') : generate_serial($pdo, post('issue_date'));
            $type   = post('card_type') !== '' ? post('card_type') : ($app['permit_expiry'] ? 'Foreign Resident' : 'Citizen');
            $pdo->prepare(
                "INSERT INTO id_card (card_serial_no, card_type, issue_date, expiry_date, card_status, collection_date, application_id, person_id)
                 VALUES (:s, :t, :i, :e, 'Active', NULL, :a, :p)"
            )->execute([':s' => $serial, ':t' => $type, ':i' => post('issue_date'), ':e' => post('expiry_date'),
                        ':a' => $appId, ':p' => (int)$app['person_id']]);
            $newId = (int)$pdo->lastInsertId();

            $nidNote = '';
            if (!$app['nid_number']) {      // the NID number stays with the person for life
                $nid = generate_nid($pdo);
                $pdo->prepare('UPDATE person SET nid_number = :n WHERE person_id = :p AND nid_number IS NULL')
                    ->execute([':n' => $nid, ':p' => (int)$app['person_id']]);
                $nidNote = ' NID number ' . $nid . ' was assigned to the person.';
            }
            $pdo->commit();
            flash('success', 'ID card ' . $serial . ' issued successfully.' . $nidNote);
            redirect('card_edit.php?id=' . $newId);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = friendly_db_error($ex);
        }
    }
}

// ---------------- Search / list cards ----------------
$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = []; $params = [];
if ($q !== '') {
    $where[] = '(c.card_serial_no LIKE :q1 OR p.nid_number LIKE :q2 OR p.first_name LIKE :q3 OR p.surname LIKE :q4)';
    for ($i = 1; $i <= 4; $i++) { $params[":q$i"] = '%' . $q . '%'; }
}
if (in_array($status, CARD_STATUSES, true)) { $where[] = 'c.card_status = :st'; $params[':st'] = $status; }
$sql = 'SELECT c.card_id, c.card_serial_no, c.card_type, c.issue_date, c.expiry_date, c.card_status, c.collection_date,
               p.person_id, p.nid_number, CONCAT(p.first_name, " ", p.surname) AS holder, a.receipt_no
        FROM id_card c
        JOIN person p ON p.person_id = c.person_id
        JOIN application a ON a.application_id = c.application_id '
     . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.issue_date DESC, c.card_id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$cards = $st->fetchAll();

// Approved applications that still need a card
$eligible = $pdo->query(
    "SELECT a.application_id, a.receipt_no, CONCAT(p.first_name, ' ', p.surname) AS applicant
     FROM application a
     JOIN person p ON p.person_id = a.person_id
     LEFT JOIN id_card c ON c.application_id = a.application_id
     WHERE a.status = 'Approved' AND c.card_id IS NULL
     ORDER BY a.approval_date, a.application_id"
)->fetchAll();

// Default selection: the first approved application waiting for a card
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($data['application_id'] ?? '') === '' && $eligible) {
    $data['application_id'] = (string)$eligible[0]['application_id'];
}

page_header('National ID Cards', 'cards');
render_errors($errors);
?>
<form method="get" action="cards.php" class="card">
  <div class="search-bar">
    <div><label for="q">Search cards</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Serial number, NID number or holder name"></div>
    <div><label for="status">Card status</label>
      <select id="status" name="status"><option value="">All</option>
        <?php foreach (CARD_STATUSES as $s): ?><option<?= selected($status, $s) ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div></div>
    <div><button class="btn" type="submit">Search</button> <a class="btn btn-secondary" href="cards.php">Reset</a></div>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead><tr><th>Serial no.</th><th>Holder</th><th>NID number</th><th>Type</th><th>Issued</th><th>Expires</th><th>Collected</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cards as $c): ?>
      <tr>
        <td><?= e($c['card_serial_no']) ?></td>
        <td><a href="applicant_view.php?id=<?= (int)$c['person_id'] ?>"><?= e($c['holder']) ?></a></td>
        <td><?= e($c['nid_number']) ?></td><td><?= e($c['card_type']) ?></td>
        <td><?= e($c['issue_date']) ?></td><td><?= e($c['expiry_date']) ?></td>
        <td><?= $c['collection_date'] ? e($c['collection_date']) : '<span class="muted">Not collected</span>' ?></td>
        <td><?= status_badge($c['card_status']) ?></td>
        <td><a class="btn btn-sm btn-secondary" href="card_edit.php?id=<?= (int)$c['card_id'] ?>">Update</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$cards): ?><tr><td colspan="9" class="muted">No cards found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<h2 id="issue" style="margin-top:1.6rem">Issue a new ID card</h2>
<?php if (!$eligible): ?>
  <div class="alert alert-info">There are no approved applications waiting for a card.</div>
<?php else: ?>
<form method="post" action="cards.php#issue" novalidate>
  <?= csrf_field() ?>
  <fieldset>
    <legend>Card issuance details</legend>
    <div class="grid grid-3">
      <div><label for="application_id">Approved application <span class="req">*</span></label>
        <select id="application_id" name="application_id" required><option value="">Select</option>
          <?php foreach ($eligible as $e): ?>
            <option value="<?= (int)$e['application_id'] ?>"<?= selected($data['application_id'] ?? '', $e['application_id']) ?>><?= e($e['receipt_no']) ?> - <?= e($e['applicant']) ?></option>
          <?php endforeach; ?></select></div>
      <div><label for="card_serial_no">Card serial number</label>
        <input type="text" id="card_serial_no" name="card_serial_no" maxlength="20" placeholder="Leave blank to generate" value="<?= old($data, 'card_serial_no') ?>"></div>
      <div><label for="card_type">Card type</label>
        <input type="text" id="card_type" name="card_type" maxlength="20" placeholder="Citizen / Foreign Resident" value="<?= old($data, 'card_type') ?>"></div>
      <div><label for="issue_date">Issue date <span class="req">*</span></label>
        <input type="date" id="issue_date" name="issue_date" max="<?= $today ?>" required value="<?= old($data, 'issue_date') ?>"></div>
      <div><label for="expiry_date">Expiry date <span class="req">*</span></label>
        <input type="date" id="expiry_date" name="expiry_date" required value="<?= old($data, 'expiry_date') ?>"></div>
    </div>
    <p class="help">The NID number is assigned to the person the first time a card is issued and does not change when a replacement card is issued.</p>
    <div class="actions"><button class="btn" type="submit">Issue card</button></div>
  </fieldset>
</form>
<?php endif; ?>
<?php page_footer();
