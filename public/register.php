<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo     = db();
$errors  = [];
$data    = ['category' => 'Citizen', 'application_type' => 'New', 'form_no' => 'NR1',
            'application_date' => date('Y-m-d'), 'address_type' => 'Permanent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);

    if (!csrf_valid()) {
        $errors[] = 'The form has expired. Please try again.';
    }

    // ---------- Validation ----------
    $today = date('Y-m-d');
    foreach (['first_name' => 'First name', 'surname' => 'Surname'] as $f => $label) {
        if (post($f) === '')            { $errors[] = "$label is required."; }
        elseif (!valid_name(post($f)))  { $errors[] = "$label may only contain letters, spaces, hyphens and apostrophes."; }
    }
    foreach (['middle_name' => 'Middle name', 'maiden_name' => 'Maiden name'] as $f => $label) {
        if (post($f) !== '' && !valid_name(post($f))) { $errors[] = "$label contains invalid characters."; }
    }
    if (!valid_date(post('date_of_birth'))) {
        $errors[] = 'Date of birth is required and must be a valid date.';
    } elseif (post('date_of_birth') > $today || post('date_of_birth') < '1900-01-01') {
        $errors[] = 'Date of birth must be between 1900 and today.';
    }
    if (!in_array(post('gender'), ['M', 'F'], true)) { $errors[] = 'Select a gender.'; }
    if (post('marital_status') !== '' && !in_array(post('marital_status'), MARITAL_OPTIONS, true)) {
        $errors[] = 'Invalid marital status.';
    }
    if (strlen(post('place_of_birth')) > 80) { $errors[] = 'Place of birth is too long (maximum 80 characters).'; }

    // Phones
    $phones = [];
    foreach (['phone_1', 'phone_2'] as $i => $f) {
        $p = str_replace([' ', '-'], '', post($f));
        if ($p === '') {
            if ($i === 0) { $errors[] = 'At least one phone number is required.'; }
            continue;
        }
        if (!valid_phone($p)) { $errors[] = 'Phone number ' . ($i + 1) . ' must have 9 to 13 digits (optional + at the start).'; }
        else { $phones[$p] = $p; }
    }

    // Category
    $category = post('category');
    if (!in_array($category, ['Citizen', 'Foreign resident'], true)) {
        $errors[] = 'Select citizen or foreign resident.';
    } elseif ($category === 'Citizen') {
        if (!in_array(post('citizenship_basis'), CITIZEN_BASES, true)) { $errors[] = 'Select the basis of citizenship.'; }
        if (post('citizenship_cert_no') !== '' && !preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('citizenship_cert_no'))) {
            $errors[] = 'Citizenship certificate number must be 4 to 30 letters, digits, "-" or "/".';
        }
        if (post('acquired_on') !== '' && (!valid_date(post('acquired_on')) || post('acquired_on') > $today)) {
            $errors[] = 'Date citizenship acquired must be a valid date that is not in the future.';
        }
    } else {
        if (!preg_match('/^[A-Za-z0-9]{5,20}$/', post('passport_no')))  { $errors[] = 'Passport number must be 5 to 20 letters or digits.'; }
        if (!valid_name(post('country_of_origin')))                      { $errors[] = 'Country of origin is required.'; }
        if (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('permit_no'))) { $errors[] = 'Permit number must be 4 to 30 letters, digits, "-" or "/".'; }
        if (!valid_date(post('permit_expiry')) || post('permit_expiry') <= $today) {
            $errors[] = 'Permit expiry must be a valid date in the future.';
        }
    }

    // Address
    if (post('village') === '' || strlen(post('village')) > 60) { $errors[] = 'Village / area is required (maximum 60 characters).'; }
    if (!ctype_digit(post('ta_id')))                             { $errors[] = 'Select a Traditional Authority.'; }
    if (!in_array(post('address_type'), ADDRESS_TYPES, true))   { $errors[] = 'Select an address type.'; }
    if (!valid_date(post('from_date')) || post('from_date') > $today) {
        $errors[] = 'Residing since must be a valid date that is not in the future.';
    }

    // Application
    if (!preg_match('/^[A-Za-z0-9\-\/]{2,20}$/', post('form_no')))      { $errors[] = 'Form number is required (for example NR1).'; }
    if (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('receipt_no')))   { $errors[] = 'Receipt number is required (4 to 30 letters, digits, "-" or "/").'; }
    if (!in_array(post('application_type'), APP_TYPES, true))           { $errors[] = 'Select an application type.'; }
    if (!valid_date(post('application_date')) || post('application_date') > $today) {
        $errors[] = 'Application date must be a valid date that is not in the future.';
    } elseif (valid_date(post('date_of_birth')) && post('application_date') < post('date_of_birth')) {
        $errors[] = 'Application date cannot be before the date of birth.';
    }
    if (!ctype_digit(post('centre_id')))   { $errors[] = 'Select a registration centre.'; }
    if (!ctype_digit(post('captured_by'))) { $errors[] = 'Select the capturing officer.'; }
    if (post('doc_type') !== '' && strlen(post('doc_type')) > 40) { $errors[] = 'Document type is too long.'; }

    // Checks that need the database
    if (!$errors) {
        $st = $pdo->prepare('SELECT 1 FROM officer WHERE officer_id = :o AND centre_id = :c');
        $st->execute([':o' => (int)post('captured_by'), ':c' => (int)post('centre_id')]);
        if (!$st->fetchColumn()) { $errors[] = 'The selected officer does not work at the selected registration centre.'; }

        $st = $pdo->prepare('SELECT 1 FROM application WHERE receipt_no = :r');
        $st->execute([':r' => post('receipt_no')]);
        if ($st->fetchColumn()) { $errors[] = 'This receipt number is already used by another application.'; }

        $st = $pdo->prepare('SELECT person_id FROM person WHERE first_name = :f AND surname = :s AND date_of_birth = :d');
        $st->execute([':f' => post('first_name'), ':s' => post('surname'), ':d' => post('date_of_birth')]);
        if ($dup = $st->fetchColumn()) {
            $errors[] = 'A person with the same name and date of birth is already registered (person no. ' . (int)$dup . '). Possible duplicate.';
        }
    }

    // ---------- Save everything in one transaction ----------
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO person (first_name, middle_name, surname, maiden_name, date_of_birth, gender, place_of_birth, marital_status)
                 VALUES (:fn, :mn, :sn, :mdn, :dob, :g, :pob, :ms)'
            )->execute([
                ':fn' => post('first_name'), ':mn' => nullable(post('middle_name')), ':sn' => post('surname'),
                ':mdn' => nullable(post('maiden_name')), ':dob' => post('date_of_birth'), ':g' => post('gender'),
                ':pob' => nullable(post('place_of_birth')), ':ms' => nullable(post('marital_status')),
            ]);
            $personId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO person_phone (person_id, phone_no) VALUES (:p, :n)');
            foreach ($phones as $ph) { $ins->execute([':p' => $personId, ':n' => $ph]); }

            if ($category === 'Citizen') {
                $pdo->prepare('INSERT INTO citizen (person_id, citizenship_basis, citizenship_cert_no, acquired_on) VALUES (:p,:b,:c,:a)')
                    ->execute([':p' => $personId, ':b' => post('citizenship_basis'),
                               ':c' => nullable(post('citizenship_cert_no')), ':a' => nullable(post('acquired_on'))]);
            } else {
                $pdo->prepare('INSERT INTO foreign_resident (person_id, passport_no, country_of_origin, permit_no, permit_expiry) VALUES (:p,:pp,:c,:pn,:pe)')
                    ->execute([':p' => $personId, ':pp' => post('passport_no'), ':c' => post('country_of_origin'),
                               ':pn' => post('permit_no'), ':pe' => post('permit_expiry')]);
            }

            $pdo->prepare('INSERT INTO address (village, physical_description, postal_box, ta_id) VALUES (:v,:d,:b,:t)')
                ->execute([':v' => post('village'), ':d' => nullable(post('physical_description')),
                           ':b' => nullable(post('postal_box')), ':t' => (int)post('ta_id')]);
            $addressId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO resides_at (person_id, address_id, from_date, to_date, address_type) VALUES (:p,:a,:f,NULL,:t)')
                ->execute([':p' => $personId, ':a' => $addressId, ':f' => post('from_date'), ':t' => post('address_type')]);

            $pdo->prepare(
                "INSERT INTO application (form_no, receipt_no, application_type, application_date, late_registration, status, person_id, centre_id, captured_by)
                 VALUES (:fn, :rn, :t, :d, :late, 'Pending', :p, :c, :o)"
            )->execute([
                ':fn' => post('form_no'), ':rn' => post('receipt_no'), ':t' => post('application_type'),
                ':d' => post('application_date'), ':late' => isset($_POST['late_registration']) ? 1 : 0,
                ':p' => $personId, ':c' => (int)post('centre_id'), ':o' => (int)post('captured_by'),
            ]);
            $applicationId = (int)$pdo->lastInsertId();

            if (post('doc_type') !== '') {
                $pdo->prepare('INSERT INTO supporting_document (application_id, doc_seg, doc_type, doc_number, issuing_authority, verified) VALUES (:a,1,:t,:n,:i,0)')
                    ->execute([':a' => $applicationId, ':t' => post('doc_type'),
                               ':n' => nullable(post('doc_number')), ':i' => nullable(post('issuing_authority'))]);
            }

            $pdo->commit();
            flash('success', 'Applicant registered successfully. Application receipt ' . post('receipt_no') . ' is now pending approval.');
            redirect('applicant_view.php?id=' . $personId);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = friendly_db_error($ex);
        }
    }
}

$tas      = lookup_tas();
$centres  = lookup_centres();
$officers = lookup_officers();

// Default values for the selection fields (only when the form is first opened)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Prefer Blantyre (the local area) when it exists, otherwise the first item in each list
    $pickFirst = static function (array $rows, string $key) {
        foreach ($rows as $r) { if (($r['district_name'] ?? '') === 'Blantyre') { return $r[$key]; } }
        return $rows[0][$key] ?? '';
    };
    $defaultCentre = $pickFirst($centres, 'centre_id');
    if (staff()) { $defaultCentre = staff()['centre_id']; }      // officer registers at their own centre
    $defaultOfficer = '';
    foreach ($officers as $o) {                       // the logged-in officer, otherwise the first officer at the centre
        if ((string)$o['centre_id'] === (string)$defaultCentre) { $defaultOfficer = $defaultOfficer ?: $o['officer_id']; }
        if ((int)$o['officer_id'] === (int)(staff()['officer_id'] ?? 0)) { $defaultOfficer = $o['officer_id']; break; }
    }
    // Suggested receipt number that is not used yet, e.g. RCP-2026-0012
    $next = (int)$pdo->query('SELECT COALESCE(MAX(application_id), 0) + 1 FROM application')->fetchColumn();
    $chk  = $pdo->prepare('SELECT 1 FROM application WHERE receipt_no = :r');
    do {
        $suggested = sprintf('RCP-%s-%04d', date('Y'), $next++);
        $chk->execute([':r' => $suggested]);
    } while ($chk->fetchColumn());

    $data = array_merge([
        'marital_status'    => 'Single',
        'citizenship_basis' => 'Birth',
        'ta_id'             => $pickFirst($tas, 'ta_id'),
        'centre_id'         => $defaultCentre,
        'captured_by'       => $defaultOfficer,
        'receipt_no'        => $suggested,
    ], $data);
}

page_header('Walk-in Registration', 'register');
render_errors($errors);
if (!$tas || !$centres || !$officers) {
    echo '<div class="alert alert-error"><strong>The selection lists are empty.</strong> '
       . 'The database has no Traditional Authorities, registration centres or officers yet, so an applicant cannot be registered. '
       . 'Import <code>database/reference_data.sql</code> (or the full <code>database/nrb_full_import.sql</code>) and refresh this page.</div>';
}
?>
<p class="muted">For applicants who come to the centre without applying online. Online applicants are in the <a href="applications.php?status=Pending&amp;source=online">review queue</a>.</p>
<form method="post" action="register.php" novalidate>
  <?= csrf_field() ?>

  <fieldset>
    <legend>1. Personal details</legend>
    <div class="grid grid-3">
      <div><label for="first_name">First name <span class="req">*</span></label>
        <input type="text" id="first_name" name="first_name" maxlength="50" required value="<?= old($data, 'first_name') ?>"></div>
      <div><label for="middle_name">Middle name</label>
        <input type="text" id="middle_name" name="middle_name" maxlength="50" value="<?= old($data, 'middle_name') ?>"></div>
      <div><label for="surname">Surname <span class="req">*</span></label>
        <input type="text" id="surname" name="surname" maxlength="50" required value="<?= old($data, 'surname') ?>"></div>
      <div><label for="maiden_name">Maiden name</label>
        <input type="text" id="maiden_name" name="maiden_name" maxlength="50" value="<?= old($data, 'maiden_name') ?>"></div>
      <div><label for="date_of_birth">Date of birth <span class="req">*</span></label>
        <input type="date" id="date_of_birth" name="date_of_birth" required max="<?= date('Y-m-d') ?>" value="<?= old($data, 'date_of_birth') ?>"></div>
      <div><label for="gender">Gender <span class="req">*</span></label>
        <select id="gender" name="gender" required>
          <option value="">Select</option>
          <option value="M"<?= selected($data['gender'] ?? '', 'M') ?>>Male</option>
          <option value="F"<?= selected($data['gender'] ?? '', 'F') ?>>Female</option>
        </select></div>
      <div><label for="place_of_birth">Place of birth</label>
        <input type="text" id="place_of_birth" name="place_of_birth" maxlength="80" value="<?= old($data, 'place_of_birth') ?>"></div>
      <div><label for="marital_status">Marital status</label>
        <select id="marital_status" name="marital_status">
          <option value="">Select</option>
          <?php foreach (MARITAL_OPTIONS as $m): ?><option<?= selected($data['marital_status'] ?? '', $m) ?>><?= e($m) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="grid grid-2" style="margin-top:.9rem">
      <div><label for="phone_1">Phone number <span class="req">*</span></label>
        <input type="tel" id="phone_1" name="phone_1" required placeholder="+265991234567" value="<?= old($data, 'phone_1') ?>"></div>
      <div><label for="phone_2">Second phone number</label>
        <input type="tel" id="phone_2" name="phone_2" placeholder="Optional" value="<?= old($data, 'phone_2') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>2. Citizenship status</legend>
    <div class="radio-row">
      <label><input type="radio" name="category" value="Citizen"<?= checked($data['category'] ?? 'Citizen', 'Citizen') ?>> Malawian citizen</label>
      <label><input type="radio" name="category" value="Foreign resident"<?= checked($data['category'] ?? '', 'Foreign resident') ?>> Foreign resident</label>
    </div>
    <div id="citizen-fields" class="grid grid-3" style="margin-top:.9rem">
      <div><label for="citizenship_basis">Basis of citizenship <span class="req">*</span></label>
        <select id="citizenship_basis" name="citizenship_basis" data-req>
          <option value="">Select</option>
          <?php foreach (CITIZEN_BASES as $b): ?><option<?= selected($data['citizenship_basis'] ?? '', $b) ?>><?= e($b) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="citizenship_cert_no">Citizenship certificate no.</label>
        <input type="text" id="citizenship_cert_no" name="citizenship_cert_no" maxlength="30" value="<?= old($data, 'citizenship_cert_no') ?>"></div>
      <div><label for="acquired_on">Date acquired</label>
        <input type="date" id="acquired_on" name="acquired_on" max="<?= date('Y-m-d') ?>" value="<?= old($data, 'acquired_on') ?>"></div>
    </div>
    <div id="foreign-fields" class="grid grid-2" style="margin-top:.9rem" hidden>
      <div><label for="passport_no">Passport number <span class="req">*</span></label>
        <input type="text" id="passport_no" name="passport_no" maxlength="20" data-req value="<?= old($data, 'passport_no') ?>"></div>
      <div><label for="country_of_origin">Country of origin <span class="req">*</span></label>
        <input type="text" id="country_of_origin" name="country_of_origin" maxlength="60" data-req value="<?= old($data, 'country_of_origin') ?>"></div>
      <div><label for="permit_no">Permit number <span class="req">*</span></label>
        <input type="text" id="permit_no" name="permit_no" maxlength="30" data-req value="<?= old($data, 'permit_no') ?>"></div>
      <div><label for="permit_expiry">Permit expiry <span class="req">*</span></label>
        <input type="date" id="permit_expiry" name="permit_expiry" data-req value="<?= old($data, 'permit_expiry') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>3. Residential address</legend>
    <div class="grid grid-3">
      <div><label for="village">Village / area <span class="req">*</span></label>
        <input type="text" id="village" name="village" maxlength="60" required value="<?= old($data, 'village') ?>"></div>
      <div><label for="ta_id">Traditional Authority <span class="req">*</span></label>
        <select id="ta_id" name="ta_id" required>
          <option value="">Select</option>
          <?php foreach ($tas as $t): ?>
            <option value="<?= (int)$t['ta_id'] ?>"<?= selected($data['ta_id'] ?? '', $t['ta_id']) ?>><?= e($t['ta_name']) ?> (<?= e($t['district_name']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div><label for="address_type">Address type <span class="req">*</span></label>
        <select id="address_type" name="address_type">
          <?php foreach (ADDRESS_TYPES as $t): ?><option<?= selected($data['address_type'] ?? 'Permanent', $t) ?>><?= e($t) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="from_date">Residing since <span class="req">*</span></label>
        <input type="date" id="from_date" name="from_date" required max="<?= date('Y-m-d') ?>" value="<?= old($data, 'from_date') ?>"></div>
      <div><label for="postal_box">Postal box</label>
        <input type="text" id="postal_box" name="postal_box" maxlength="30" value="<?= old($data, 'postal_box') ?>"></div>
      <div><label for="physical_description">Physical description</label>
        <input type="text" id="physical_description" name="physical_description" maxlength="255" value="<?= old($data, 'physical_description') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>4. Application details</legend>
    <div class="grid grid-3">
      <div><label for="form_no">Form number <span class="req">*</span></label>
        <input type="text" id="form_no" name="form_no" maxlength="20" required value="<?= old($data, 'form_no') ?>"></div>
      <div><label for="receipt_no">Receipt number <span class="req">*</span></label>
        <input type="text" id="receipt_no" name="receipt_no" maxlength="30" required value="<?= old($data, 'receipt_no') ?>"><div class="help">Suggested number, change it if the paper receipt is different.</div></div>
      <div><label for="application_type">Application type <span class="req">*</span></label>
        <select id="application_type" name="application_type">
          <?php foreach (APP_TYPES as $t): ?><option<?= selected($data['application_type'] ?? 'New', $t) ?>><?= e($t) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="application_date">Application date <span class="req">*</span></label>
        <input type="date" id="application_date" name="application_date" required max="<?= date('Y-m-d') ?>" value="<?= old($data, 'application_date') ?>"></div>
      <div><label for="centre_id">Registration centre <span class="req">*</span></label>
        <select id="centre_id" name="centre_id" required>
          <option value="">Select</option>
          <?php foreach ($centres as $c): ?>
            <option value="<?= (int)$c['centre_id'] ?>"<?= selected($data['centre_id'] ?? '', $c['centre_id']) ?>><?= e($c['centre_name']) ?> (<?= e($c['district_name']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div><label for="captured_by">Capturing officer <span class="req">*</span></label>
        <select id="captured_by" name="captured_by" required>
          <option value="">Select</option>
          <?php foreach ($officers as $o): ?>
            <option value="<?= (int)$o['officer_id'] ?>" data-centre="<?= (int)$o['centre_id'] ?>"<?= selected($data['captured_by'] ?? '', $o['officer_id']) ?>><?= e($o['label']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <p style="margin:.9rem 0 0"><label class="check"><input type="checkbox" name="late_registration" value="1"<?= isset($data['late_registration']) ? ' checked' : '' ?>> Late registration</label></p>
  </fieldset>

  <fieldset>
    <legend>5. Supporting document (optional)</legend>
    <div class="grid grid-3">
      <div><label for="doc_type">Document type</label>
        <input type="text" id="doc_type" name="doc_type" maxlength="40" placeholder="Birth certificate" value="<?= old($data, 'doc_type') ?>"></div>
      <div><label for="doc_number">Document number</label>
        <input type="text" id="doc_number" name="doc_number" maxlength="40" value="<?= old($data, 'doc_number') ?>"></div>
      <div><label for="issuing_authority">Issuing authority</label>
        <input type="text" id="issuing_authority" name="issuing_authority" maxlength="80" value="<?= old($data, 'issuing_authority') ?>"></div>
    </div>
  </fieldset>

  <div class="actions">
    <button type="submit" class="btn">Register applicant</button>
    <a class="btn btn-secondary" href="dashboard.php">Cancel</a>
  </div>
</form>
<?php page_footer();
