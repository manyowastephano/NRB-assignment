<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo = db();
$id  = (int)($_GET['id'] ?? $_POST['person_id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM person WHERE person_id = :id');
$st->execute([':id' => $id]);
$person = $st->fetch();
if (!$person) {
    flash('error', 'Applicant not found.');
    redirect('applicants.php');
}
$st = $pdo->prepare('SELECT * FROM citizen WHERE person_id = :id');
$st->execute([':id' => $id]);
$citizen = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT * FROM foreign_resident WHERE person_id = :id');
$st->execute([':id' => $id]);
$foreign = $st->fetch() ?: null;
$st = $pdo->prepare('SELECT phone_no FROM person_phone WHERE person_id = :id ORDER BY phone_no');
$st->execute([':id' => $id]);
$phoneList = $st->fetchAll(PDO::FETCH_COLUMN);

$errorsP = [];   // errors for the personal details form
$errorsA = [];   // errors for the application form
$today   = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = post('section');
    if (!csrf_valid()) {
        $errorsP[] = 'The form has expired. Please try again.';
        $section = '';
    }

    // =============== Personal details ===============
    if ($section === 'person') {
        foreach (['first_name' => 'First name', 'surname' => 'Surname'] as $f => $label) {
            if (post($f) === '' || !valid_name(post($f))) { $errorsP[] = "$label is required and may only contain letters."; }
        }
        foreach (['middle_name', 'maiden_name'] as $f) {
            if (post($f) !== '' && !valid_name(post($f))) { $errorsP[] = ucfirst(str_replace('_', ' ', $f)) . ' contains invalid characters.'; }
        }
        if (!valid_date(post('date_of_birth')) || post('date_of_birth') > $today || post('date_of_birth') < '1900-01-01') {
            $errorsP[] = 'Date of birth must be a valid date between 1900 and today.';
        }
        if (!in_array(post('gender'), ['M', 'F'], true)) { $errorsP[] = 'Select a gender.'; }
        if (post('marital_status') !== '' && !in_array(post('marital_status'), MARITAL_OPTIONS, true)) { $errorsP[] = 'Invalid marital status.'; }
        if (!in_array(post('life_status'), ['Alive', 'Deceased'], true)) { $errorsP[] = 'Invalid life status.'; }
        if (post('life_status') === 'Deceased') {
            if (!valid_date(post('date_of_death')) || post('date_of_death') > $today) { $errorsP[] = 'Enter a valid date of death.'; }
            elseif (valid_date(post('date_of_birth')) && post('date_of_death') < post('date_of_birth')) { $errorsP[] = 'Date of death cannot be before the date of birth.'; }
        }
        $newPhones = [];
        foreach (['phone_1', 'phone_2'] as $i => $f) {
            $ph = str_replace([' ', '-'], '', post($f));
            if ($ph === '') { if ($i === 0) { $errorsP[] = 'At least one phone number is required.'; } continue; }
            if (!valid_phone($ph)) { $errorsP[] = 'Phone number ' . ($i + 1) . ' must have 9 to 13 digits.'; } else { $newPhones[$ph] = $ph; }
        }
        if ($citizen) {
            if (!in_array(post('citizenship_basis'), CITIZEN_BASES, true)) { $errorsP[] = 'Select the basis of citizenship.'; }
            if (post('citizenship_cert_no') !== '' && !preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('citizenship_cert_no'))) { $errorsP[] = 'Invalid citizenship certificate number.'; }
            if (post('acquired_on') !== '' && !valid_date(post('acquired_on'))) { $errorsP[] = 'Invalid date citizenship acquired.'; }
        } elseif ($foreign) {
            if (!preg_match('/^[A-Za-z0-9]{5,20}$/', post('passport_no'))) { $errorsP[] = 'Passport number must be 5 to 20 letters or digits.'; }
            if (!valid_name(post('country_of_origin'))) { $errorsP[] = 'Country of origin is required.'; }
            if (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('permit_no'))) { $errorsP[] = 'Invalid permit number.'; }
            if (!valid_date(post('permit_expiry'))) { $errorsP[] = 'Invalid permit expiry date.'; }
        }
        if (!$errorsP) {
            $dup = $pdo->prepare('SELECT person_id FROM person WHERE first_name = :f AND surname = :s AND date_of_birth = :d AND person_id <> :id');
            $dup->execute([':f' => post('first_name'), ':s' => post('surname'), ':d' => post('date_of_birth'), ':id' => $id]);
            if ($dup->fetchColumn()) { $errorsP[] = 'Another person with the same name and date of birth already exists.'; }
        }
        if (!$errorsP) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'UPDATE person SET first_name=:fn, middle_name=:mn, surname=:sn, maiden_name=:mdn, date_of_birth=:dob, gender=:g,
                            place_of_birth=:pob, marital_status=:ms, life_status=:ls, date_of_death=:dod WHERE person_id=:id'
                )->execute([
                    ':fn' => post('first_name'), ':mn' => nullable(post('middle_name')), ':sn' => post('surname'),
                    ':mdn' => nullable(post('maiden_name')), ':dob' => post('date_of_birth'), ':g' => post('gender'),
                    ':pob' => nullable(post('place_of_birth')), ':ms' => nullable(post('marital_status')),
                    ':ls' => post('life_status'), ':dod' => post('life_status') === 'Deceased' ? post('date_of_death') : null, ':id' => $id,
                ]);
                $pdo->prepare('DELETE FROM person_phone WHERE person_id = :id')->execute([':id' => $id]);
                $ins = $pdo->prepare('INSERT INTO person_phone (person_id, phone_no) VALUES (:p, :n)');
                foreach ($newPhones as $ph) { $ins->execute([':p' => $id, ':n' => $ph]); }
                if ($citizen) {
                    $pdo->prepare('UPDATE citizen SET citizenship_basis=:b, citizenship_cert_no=:c, acquired_on=:a WHERE person_id=:id')
                        ->execute([':b' => post('citizenship_basis'), ':c' => nullable(post('citizenship_cert_no')), ':a' => nullable(post('acquired_on')), ':id' => $id]);
                } elseif ($foreign) {
                    $pdo->prepare('UPDATE foreign_resident SET passport_no=:pp, country_of_origin=:c, permit_no=:pn, permit_expiry=:pe WHERE person_id=:id')
                        ->execute([':pp' => post('passport_no'), ':c' => post('country_of_origin'), ':pn' => post('permit_no'), ':pe' => post('permit_expiry'), ':id' => $id]);
                }
                $pdo->commit();
                flash('success', 'Personal details updated successfully.');
                redirect('applicant_view.php?id=' . $id);
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errorsP[] = friendly_db_error($ex);
            }
        }
    }

    // =============== Application details ===============
    if ($section === 'application') {
        $appId = (int)post('application_id');
        $st = $pdo->prepare('SELECT a.*, (SELECT COUNT(*) FROM id_card c WHERE c.application_id = a.application_id) AS has_card
                             FROM application a WHERE a.application_id = :a AND a.person_id = :p');
        $st->execute([':a' => $appId, ':p' => $id]);
        $app = $st->fetch();
        if (!$app) {
            $errorsA[] = 'Application not found for this applicant.';
        } else {
            $status = post('status');
            if (!in_array($status, APP_STATUSES, true))              { $errorsA[] = 'Select a valid status.'; }
            if (!in_array(post('application_type'), APP_TYPES, true)) { $errorsA[] = 'Select a valid application type.'; }
            if (!preg_match('/^[A-Za-z0-9\-\/]{2,20}$/', post('form_no'))) { $errorsA[] = 'Form number is invalid.'; }
            if ($status === 'Approved') {
                if (!ctype_digit(post('approved_by')))                { $errorsA[] = 'Select the approving officer.'; }
                if (!valid_date(post('approval_date')))               { $errorsA[] = 'Enter a valid approval date.'; }
                elseif (post('approval_date') < $app['application_date'] || post('approval_date') > $today) {
                    $errorsA[] = 'Approval date must be between the application date and today.';
                }
            }
            if ($status === 'Rejected' && (post('rejection_reason') === '' || strlen(post('rejection_reason')) > 255)) {
                $errorsA[] = 'A rejection reason (maximum 255 characters) is required when rejecting an application.';
            }
            if ($app['has_card'] && $status !== 'Approved') {
                $errorsA[] = 'The status cannot be changed because an ID card has already been issued for this application.';
            }
            if (!$errorsA) {
                try {
                    $pdo->prepare(
                        'UPDATE application SET form_no=:fn, application_type=:t, late_registration=:late, status=:s,
                                rejection_reason=:rr, approved_by=:ab, approval_date=:ad WHERE application_id=:a'
                    )->execute([
                        ':fn' => post('form_no'), ':t' => post('application_type'), ':late' => isset($_POST['late_registration']) ? 1 : 0,
                        ':s' => $status,
                        ':rr' => $status === 'Rejected' ? post('rejection_reason') : null,
                        ':ab' => $status === 'Approved' ? (int)post('approved_by') : null,
                        ':ad' => $status === 'Approved' ? post('approval_date') : null,
                        ':a' => $appId,
                    ]);
                    flash('success', 'Application ' . $app['receipt_no'] . ' updated successfully.');
                    redirect('applicant_view.php?id=' . $id);
                } catch (PDOException $ex) {
                    $errorsA[] = friendly_db_error($ex);
                }
            }
        }
    }
}

$st = $pdo->prepare('SELECT a.*, (SELECT COUNT(*) FROM id_card c WHERE c.application_id = a.application_id) AS has_card
                     FROM application a WHERE a.person_id = :p ORDER BY a.application_date DESC, a.application_id DESC');
$st->execute([':p' => $id]);
$applications = $st->fetchAll();
$officers = $pdo->query(
    'SELECT officer_id, centre_id, job_role, CONCAT(first_name, " ", surname, " (", employee_no, ")") AS label FROM officer WHERE employee_no <> "ONLINE-PORTAL" ORDER BY first_name, surname'
)->fetchAll();

// Values shown in the personal details form (posted values if validation failed)
$v = ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'person') ? array_map('trim', array_map('strval', $_POST)) : array_merge(
    $person, $citizen ?: [], $foreign ?: [],
    ['phone_1' => $phoneList[0] ?? '', 'phone_2' => $phoneList[1] ?? '']
);

page_header('Update: ' . $person['first_name'] . ' ' . $person['surname'], 'applicants');
?>
<p><a href="applicant_view.php?id=<?= $id ?>">&laquo; Back to applicant record</a></p>

<?php render_errors($errorsP); ?>
<form method="post" action="applicant_edit.php?id=<?= $id ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="person">
  <input type="hidden" name="person_id" value="<?= $id ?>">
  <fieldset>
    <legend>Personal details</legend>
    <p class="help" style="margin-top:0">NID number: <strong><?= e($person['nid_number'] ?: 'not yet assigned') ?></strong> (assigned automatically when a card is issued, cannot be edited).</p>
    <div class="grid grid-3">
      <div><label>First name <span class="req">*</span></label><input type="text" name="first_name" maxlength="50" required value="<?= old($v, 'first_name') ?>"></div>
      <div><label>Middle name</label><input type="text" name="middle_name" maxlength="50" value="<?= old($v, 'middle_name') ?>"></div>
      <div><label>Surname <span class="req">*</span></label><input type="text" name="surname" maxlength="50" required value="<?= old($v, 'surname') ?>"></div>
      <div><label>Maiden name</label><input type="text" name="maiden_name" maxlength="50" value="<?= old($v, 'maiden_name') ?>"></div>
      <div><label>Date of birth <span class="req">*</span></label><input type="date" name="date_of_birth" max="<?= $today ?>" required value="<?= old($v, 'date_of_birth') ?>"></div>
      <div><label>Gender <span class="req">*</span></label>
        <select name="gender"><option value="M"<?= selected($v['gender'] ?? '', 'M') ?>>Male</option><option value="F"<?= selected($v['gender'] ?? '', 'F') ?>>Female</option></select></div>
      <div><label>Place of birth</label><input type="text" name="place_of_birth" maxlength="80" value="<?= old($v, 'place_of_birth') ?>"></div>
      <div><label>Marital status</label>
        <select name="marital_status"><option value="">Select</option>
          <?php foreach (MARITAL_OPTIONS as $m): ?><option<?= selected($v['marital_status'] ?? '', $m) ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label>Life status</label>
        <select name="life_status"><option<?= selected($v['life_status'] ?? 'Alive', 'Alive') ?>>Alive</option><option<?= selected($v['life_status'] ?? '', 'Deceased') ?>>Deceased</option></select></div>
      <div><label>Date of death</label><input type="date" name="date_of_death" max="<?= $today ?>" value="<?= old($v, 'date_of_death') ?>"></div>
      <div><label>Phone number <span class="req">*</span></label><input type="tel" name="phone_1" value="<?= old($v, 'phone_1') ?>"></div>
      <div><label>Second phone number</label><input type="tel" name="phone_2" value="<?= old($v, 'phone_2') ?>"></div>
    </div>
    <?php if ($citizen): ?>
      <h2 style="margin-top:1.1rem">Citizen details</h2>
      <div class="grid grid-3">
        <div><label>Basis of citizenship</label>
          <select name="citizenship_basis"><?php foreach (CITIZEN_BASES as $b): ?><option<?= selected($v['citizenship_basis'] ?? '', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        <div><label>Certificate no.</label><input type="text" name="citizenship_cert_no" maxlength="30" value="<?= old($v, 'citizenship_cert_no') ?>"></div>
        <div><label>Date acquired</label><input type="date" name="acquired_on" value="<?= old($v, 'acquired_on') ?>"></div>
      </div>
    <?php elseif ($foreign): ?>
      <h2 style="margin-top:1.1rem">Foreign resident details</h2>
      <div class="grid grid-2">
        <div><label>Passport number</label><input type="text" name="passport_no" maxlength="20" value="<?= old($v, 'passport_no') ?>"></div>
        <div><label>Country of origin</label><input type="text" name="country_of_origin" maxlength="60" value="<?= old($v, 'country_of_origin') ?>"></div>
        <div><label>Permit number</label><input type="text" name="permit_no" maxlength="30" value="<?= old($v, 'permit_no') ?>"></div>
        <div><label>Permit expiry</label><input type="date" name="permit_expiry" value="<?= old($v, 'permit_expiry') ?>"></div>
      </div>
    <?php endif; ?>
    <div class="actions"><button class="btn" type="submit">Save personal details</button></div>
  </fieldset>
</form>

<h2>Registration (application) information</h2>
<?php render_errors($errorsA); ?>
<?php foreach ($applications as $a):
    $posted = ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'application' && (int)post('application_id') === (int)$a['application_id']);
    $av = $posted ? array_map('trim', array_map('strval', $_POST)) : array_map(fn($x) => (string)($x ?? ''), $a);
    if (!$posted) {                                    // defaults for the approval fields
        if ($av['approved_by'] === '') {
            $pick = '';
            foreach ($officers as $o) {                // prefer a supervisor at the same centre, then any officer there
                if ((string)$o['centre_id'] !== (string)$a['centre_id']) { continue; }
                if ($pick === '') { $pick = $o['officer_id']; }
                if (stripos($o['job_role'] ?? '', 'supervisor') !== false) { $pick = $o['officer_id']; break; }
            }
            $av['approved_by'] = (string)$pick;
        }
        if ($av['approval_date'] === '') { $av['approval_date'] = $today; }
    } ?>
<form method="post" action="applicant_edit.php?id=<?= $id ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="application">
  <input type="hidden" name="person_id" value="<?= $id ?>">
  <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
  <fieldset>
    <legend>Receipt <?= e($a['receipt_no']) ?> (<?= e($a['application_date']) ?>)</legend>
    <div class="grid grid-3">
      <div><label>Form number</label><input type="text" name="form_no" maxlength="20" value="<?= old($av, 'form_no') ?>"></div>
      <div><label>Application type</label>
        <select name="application_type"><?php foreach (APP_TYPES as $t): ?><option<?= selected($av['application_type'] ?? '', $t) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div><label>Status</label>
        <select name="status"><?php foreach (APP_STATUSES as $s): ?><option<?= selected($av['status'] ?? '', $s) ?>><?= e($s) ?></option><?php endforeach; ?></select>
        <?php if ($a['has_card']): ?><div class="help">A card has been issued, so the status must stay Approved.</div><?php endif; ?></div>
      <div><label>Approving officer</label>
        <select name="approved_by"><option value="">Select</option>
          <?php foreach ($officers as $o): ?><option value="<?= (int)$o['officer_id'] ?>"<?= selected($av['approved_by'] ?? '', $o['officer_id']) ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
      <div><label>Approval date</label><input type="date" name="approval_date" max="<?= $today ?>" value="<?= old($av, 'approval_date') ?>"></div>
      <div><label>Rejection reason</label><input type="text" name="rejection_reason" maxlength="255" value="<?= old($av, 'rejection_reason') ?>"></div>
    </div>
    <p style="margin:.8rem 0 0"><label class="check"><input type="checkbox" name="late_registration" value="1"<?= !empty($av['late_registration']) ? ' checked' : '' ?>> Late registration</label></p>
    <div class="actions"><button class="btn" type="submit">Save application</button></div>
  </fieldset>
</form>
<?php endforeach; ?>
<?php if (!$applications): ?><p class="muted">This applicant has no applications.</p><?php endif; ?>
<?php page_footer();
