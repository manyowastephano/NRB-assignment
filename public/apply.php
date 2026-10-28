<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

/*
 * Public application form: any person can apply without logging in.
 * The application is saved as "Pending" and appears in the staff review queue.
 */
$pdo    = db();
$errors = [];
$today  = date('Y-m-d');
$data   = ['category' => 'Citizen', 'application_type' => 'New'];

// ---------- Confirmation screen ----------
if (isset($_GET['done']) && !empty($_SESSION['submitted'])) {
    $sub = $_SESSION['submitted'];
    public_header('Application received', 'apply');
    ?>
    <div class="card" style="max-width:720px">
      <div class="alert alert-success" style="margin-bottom:0">Thank you, <?= e($sub['name']) ?>. Your application has been received.</div>
      <div class="ref-box"><div class="muted">Your reference number</div><div class="ref"><?= e($sub['ref']) ?></div></div>
      <p><strong>Keep this number safe.</strong> You need it, together with your date of birth, to track your application.</p>
      <h2>What happens next</h2>
      <ol class="checklist">
        <li>Visit <strong><?= e($sub['centre']) ?></strong> with your original documents.</li>
        <li>An officer verifies your documents and captures your fingerprints and photo.</li>
        <li>The application is approved (or you are told what is missing).</li>
        <li>You collect your ID card at the same centre.</li>
      </ol>
      <div class="actions"><a class="btn" href="track.php">Track my application</a><a class="btn btn-secondary" href="index.php">Back to home</a></div>
    </div>
    <?php
    public_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST);
    if (!csrf_valid()) { $errors[] = 'The form has expired. Please try again.'; }
    if (too_many_attempts('apply', 8, 3600)) { $errors[] = 'Too many applications from this browser. Please try again later.'; }

    // ---------- Validation ----------
    foreach (['first_name' => 'First name', 'surname' => 'Surname'] as $f => $label) {
        if (post($f) === '')           { $errors[] = "$label is required."; }
        elseif (!valid_name(post($f))) { $errors[] = "$label may only contain letters, spaces, hyphens and apostrophes."; }
    }
    foreach (['middle_name' => 'Middle name', 'maiden_name' => 'Maiden name'] as $f => $label) {
        if (post($f) !== '' && !valid_name(post($f))) { $errors[] = "$label contains invalid characters."; }
    }
    if (!valid_date(post('date_of_birth'))) { $errors[] = 'Date of birth is required.'; }
    elseif (post('date_of_birth') > $today || post('date_of_birth') < '1900-01-01') { $errors[] = 'Date of birth must be between 1900 and today.'; }
    if (!in_array(post('gender'), ['M', 'F'], true)) { $errors[] = 'Select your gender.'; }
    if (post('marital_status') !== '' && !in_array(post('marital_status'), MARITAL_OPTIONS, true)) { $errors[] = 'Invalid marital status.'; }
    if (strlen(post('place_of_birth')) > 80) { $errors[] = 'Place of birth is too long.'; }

    $phones = [];
    foreach (['phone_1', 'phone_2'] as $i => $f) {
        $p = str_replace([' ', '-'], '', post($f));
        if ($p === '') { if ($i === 0) { $errors[] = 'A phone number is required.'; } continue; }
        if (!valid_phone($p)) { $errors[] = 'Phone number ' . ($i + 1) . ' must have 9 to 13 digits (for example +265991234567).'; } else { $phones[$p] = $p; }
    }

    $category = post('category');
    if ($category === 'Citizen') {
        if (!in_array(post('citizenship_basis'), CITIZEN_BASES, true)) { $errors[] = 'Select how you became a citizen.'; }
    } elseif ($category === 'Foreign resident') {
        if (!preg_match('/^[A-Za-z0-9]{5,20}$/', post('passport_no')))   { $errors[] = 'Passport number must be 5 to 20 letters or digits.'; }
        if (!valid_name(post('country_of_origin')))                       { $errors[] = 'Country of origin is required.'; }
        if (!preg_match('/^[A-Za-z0-9\-\/]{4,30}$/', post('permit_no'))) { $errors[] = 'Permit number must be 4 to 30 letters, digits, "-" or "/".'; }
        if (!valid_date(post('permit_expiry')) || post('permit_expiry') <= $today) { $errors[] = 'Permit expiry must be a date in the future.'; }
    } else {
        $errors[] = 'Select whether you are a citizen or a foreign resident.';
    }

    if (post('village') === '' || strlen(post('village')) > 60) { $errors[] = 'Village or area is required.'; }
    if (!ctype_digit(post('ta_id')))                             { $errors[] = 'Select your Traditional Authority.'; }
    if (post('from_date') !== '' && (!valid_date(post('from_date')) || post('from_date') > $today)) { $errors[] = 'The date you moved to this address must be a valid past date.'; }
    if (!in_array(post('application_type'), APP_TYPES, true))    { $errors[] = 'Select the reason for your application.'; }
    if (!ctype_digit(post('centre_id')))                         { $errors[] = 'Select the registration centre where you will complete your application.'; }
    if (strlen(post('doc_type')) > 40 || strlen(post('doc_number')) > 40) { $errors[] = 'Document details are too long.'; }
    if (empty($_POST['declaration']))                            { $errors[] = 'Please confirm that the information you gave is true.'; }

    if (!$errors) {
        $st = $pdo->prepare('SELECT 1 FROM person WHERE first_name = :f AND surname = :s AND date_of_birth = :d');
        $st->execute([':f' => post('first_name'), ':s' => post('surname'), ':d' => post('date_of_birth')]);
        if ($st->fetchColumn()) {
            $errors[] = 'A person with these names and date of birth is already registered. Use "Track application" if you applied before, or visit a registration centre for help.';
        }
        $st = $pdo->prepare("SELECT 1 FROM registration_centre WHERE centre_id = :c AND status = 'Active'");
        $st->execute([':c' => (int)post('centre_id')]);
        if (!$st->fetchColumn()) { $errors[] = 'The selected registration centre is not available.'; }
    }

    // ---------- Save in one transaction ----------
    if (!$errors) {
        record_attempt('apply');
        try {
            $pdo->beginTransaction();
            $portal = portal_officer_id($pdo);
            $ref    = generate_reference($pdo);

            $pdo->prepare('INSERT INTO person (first_name, middle_name, surname, maiden_name, date_of_birth, gender, place_of_birth, marital_status)
                           VALUES (:fn,:mn,:sn,:mdn,:dob,:g,:pob,:ms)')
                ->execute([':fn' => post('first_name'), ':mn' => nullable(post('middle_name')), ':sn' => post('surname'),
                           ':mdn' => nullable(post('maiden_name')), ':dob' => post('date_of_birth'), ':g' => post('gender'),
                           ':pob' => nullable(post('place_of_birth')), ':ms' => nullable(post('marital_status'))]);
            $personId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO person_phone (person_id, phone_no) VALUES (:p,:n)');
            foreach ($phones as $ph) { $ins->execute([':p' => $personId, ':n' => $ph]); }

            if ($category === 'Citizen') {
                $pdo->prepare('INSERT INTO citizen (person_id, citizenship_basis, citizenship_cert_no) VALUES (:p,:b,:c)')
                    ->execute([':p' => $personId, ':b' => post('citizenship_basis'), ':c' => nullable(post('citizenship_cert_no'))]);
            } else {
                $pdo->prepare('INSERT INTO foreign_resident (person_id, passport_no, country_of_origin, permit_no, permit_expiry) VALUES (:p,:pp,:c,:pn,:pe)')
                    ->execute([':p' => $personId, ':pp' => post('passport_no'), ':c' => post('country_of_origin'),
                               ':pn' => post('permit_no'), ':pe' => post('permit_expiry')]);
            }

            $pdo->prepare('INSERT INTO address (village, physical_description, postal_box, ta_id) VALUES (:v,:d,:b,:t)')
                ->execute([':v' => post('village'), ':d' => nullable(post('physical_description')), ':b' => nullable(post('postal_box')), ':t' => (int)post('ta_id')]);
            $addressId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO resides_at (person_id, address_id, from_date, to_date, address_type) VALUES (:p,:a,:f,NULL,'Permanent')")
                ->execute([':p' => $personId, ':a' => $addressId, ':f' => post('from_date') !== '' ? post('from_date') : $today]);

            $pdo->prepare("INSERT INTO application (form_no, receipt_no, application_type, application_date, late_registration, status, person_id, centre_id, captured_by)
                           VALUES ('NR1', :r, :t, :d, 0, 'Pending', :p, :c, :o)")
                ->execute([':r' => $ref, ':t' => post('application_type'), ':d' => $today, ':p' => $personId, ':c' => (int)post('centre_id'), ':o' => $portal]);
            $applicationId = (int)$pdo->lastInsertId();

            if (post('doc_type') !== '') {
                $pdo->prepare('INSERT INTO supporting_document (application_id, doc_seg, doc_type, doc_number, issuing_authority, verified) VALUES (:a,1,:t,:n,:i,0)')
                    ->execute([':a' => $applicationId, ':t' => post('doc_type'), ':n' => nullable(post('doc_number')), ':i' => nullable(post('issuing_authority'))]);
            }
            $pdo->commit();

            $cn = $pdo->prepare('SELECT centre_name FROM registration_centre WHERE centre_id = :c');
            $cn->execute([':c' => (int)post('centre_id')]);
            $_SESSION['submitted'] = ['ref' => $ref, 'name' => post('first_name'), 'centre' => (string)$cn->fetchColumn()];
            redirect('apply.php?done=1');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = friendly_db_error($ex);
        }
    }
}

// Lists and default selections
$tas     = lookup_tas();
$centres = lookup_centres();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $first = static function (array $rows, string $key) {
        foreach ($rows as $r) { if (($r['district_name'] ?? '') === 'Blantyre') { return $r[$key]; } }
        return $rows[0][$key] ?? '';
    };
    $data = array_merge(['marital_status' => 'Single', 'citizenship_basis' => 'Birth', 'ta_id' => $first($tas, 'ta_id'), 'centre_id' => $first($centres, 'centre_id')], $data);
}

public_header('Apply for a National ID', 'apply');
?>
<div class="form-narrow">
<p class="muted" style="margin-top:0">Fields marked <span style="color:#B5485A">*</span> are required. Takes about five minutes.</p>
<?php render_errors($errors); ?>
<?php if (!$tas || !$centres): ?><div class="alert alert-error">The online form is not available yet because no Traditional Authorities or registration centres have been set up.</div><?php endif; ?>
<form method="post" action="apply.php" novalidate>
  <?= csrf_field() ?>
  <fieldset>
    <legend>1. About you</legend>
    <div class="grid grid-3">
      <div><label for="first_name">First name <span class="req">*</span></label><input type="text" id="first_name" name="first_name" maxlength="50" required value="<?= old($data, 'first_name') ?>"></div>
      <div><label for="middle_name">Middle name</label><input type="text" id="middle_name" name="middle_name" maxlength="50" value="<?= old($data, 'middle_name') ?>"></div>
      <div><label for="surname">Surname <span class="req">*</span></label><input type="text" id="surname" name="surname" maxlength="50" required value="<?= old($data, 'surname') ?>"></div>
      <div><label for="maiden_name">Maiden name <span class="muted">(if married)</span></label><input type="text" id="maiden_name" name="maiden_name" maxlength="50" value="<?= old($data, 'maiden_name') ?>"></div>
      <div><label for="date_of_birth">Date of birth <span class="req">*</span></label><input type="date" id="date_of_birth" name="date_of_birth" required max="<?= $today ?>" value="<?= old($data, 'date_of_birth') ?>"></div>
      <div><label for="gender">Gender <span class="req">*</span></label>
        <select id="gender" name="gender" required><option value="">Select</option>
          <option value="M"<?= selected($data['gender'] ?? '', 'M') ?>>Male</option><option value="F"<?= selected($data['gender'] ?? '', 'F') ?>>Female</option></select></div>
      <div><label for="place_of_birth">Place of birth</label><input type="text" id="place_of_birth" name="place_of_birth" maxlength="80" value="<?= old($data, 'place_of_birth') ?>"></div>
      <div><label for="marital_status">Marital status</label>
        <select id="marital_status" name="marital_status"><?php foreach (MARITAL_OPTIONS as $m): ?><option<?= selected($data['marital_status'] ?? '', $m) ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label for="phone_1">Phone number <span class="req">*</span></label><input type="tel" id="phone_1" name="phone_1" required placeholder="+265991234567" value="<?= old($data, 'phone_1') ?>"></div>
      <div><label for="phone_2">Second phone</label><input type="tel" id="phone_2" name="phone_2" value="<?= old($data, 'phone_2') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>2. Citizenship</legend>
    <div class="radio-row">
      <label><input type="radio" name="category" value="Citizen"<?= checked($data['category'] ?? 'Citizen', 'Citizen') ?>> I am a Malawian citizen</label>
      <label><input type="radio" name="category" value="Foreign resident"<?= checked($data['category'] ?? '', 'Foreign resident') ?>> I am a foreign resident</label>
    </div>
    <div id="citizen-fields" class="grid grid-2" style="margin-top:.9rem">
      <div><label for="citizenship_basis">How did you become a citizen? <span class="req">*</span></label>
        <select id="citizenship_basis" name="citizenship_basis" data-req><?php foreach (CITIZEN_BASES as $b): ?><option<?= selected($data['citizenship_basis'] ?? '', $b) ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
      <div><label for="citizenship_cert_no">Citizenship certificate no.</label><input type="text" id="citizenship_cert_no" name="citizenship_cert_no" maxlength="30" value="<?= old($data, 'citizenship_cert_no') ?>"></div>
    </div>
    <div id="foreign-fields" class="grid grid-2" style="margin-top:.9rem" hidden>
      <div><label for="passport_no">Passport number <span class="req">*</span></label><input type="text" id="passport_no" name="passport_no" maxlength="20" data-req value="<?= old($data, 'passport_no') ?>"></div>
      <div><label for="country_of_origin">Country of origin <span class="req">*</span></label><input type="text" id="country_of_origin" name="country_of_origin" maxlength="60" data-req value="<?= old($data, 'country_of_origin') ?>"></div>
      <div><label for="permit_no">Residence permit number <span class="req">*</span></label><input type="text" id="permit_no" name="permit_no" maxlength="30" data-req value="<?= old($data, 'permit_no') ?>"></div>
      <div><label for="permit_expiry">Permit expiry date <span class="req">*</span></label><input type="date" id="permit_expiry" name="permit_expiry" data-req value="<?= old($data, 'permit_expiry') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>3. Where you live</legend>
    <div class="grid grid-3">
      <div><label for="village">Village / area <span class="req">*</span></label><input type="text" id="village" name="village" maxlength="60" required value="<?= old($data, 'village') ?>"></div>
      <div><label for="ta_id">Traditional Authority <span class="req">*</span></label>
        <select id="ta_id" name="ta_id" required><option value="">Select</option>
          <?php foreach ($tas as $t): ?><option value="<?= (int)$t['ta_id'] ?>"<?= selected($data['ta_id'] ?? '', $t['ta_id']) ?>><?= e($t['ta_name']) ?> (<?= e($t['district_name']) ?>)</option><?php endforeach; ?></select></div>
      <div><label for="from_date">Living here since</label><input type="date" id="from_date" name="from_date" max="<?= $today ?>" value="<?= old($data, 'from_date') ?>"></div>
      <div><label for="postal_box">Postal box</label><input type="text" id="postal_box" name="postal_box" maxlength="30" value="<?= old($data, 'postal_box') ?>"></div>
      <div style="grid-column: span 2"><label for="physical_description">Directions to your home</label><input type="text" id="physical_description" name="physical_description" maxlength="255" placeholder="e.g. near the market, behind the school" value="<?= old($data, 'physical_description') ?>"></div>
    </div>
  </fieldset>

  <fieldset>
    <legend>4. Your application</legend>
    <div class="grid grid-2">
      <div><label for="application_type">Reason for applying <span class="req">*</span></label>
        <select id="application_type" name="application_type">
          <option value="New"<?= selected($data['application_type'] ?? 'New', 'New') ?>>New ID (first time)</option>
          <option value="Replacement"<?= selected($data['application_type'] ?? '', 'Replacement') ?>>Replacement (lost or damaged card)</option>
          <option value="Renewal"<?= selected($data['application_type'] ?? '', 'Renewal') ?>>Renewal (expired card)</option></select></div>
      <div><label for="centre_id">Registration centre you will visit <span class="req">*</span></label>
        <select id="centre_id" name="centre_id" required><option value="">Select</option>
          <?php foreach ($centres as $c): ?><option value="<?= (int)$c['centre_id'] ?>"<?= selected($data['centre_id'] ?? '', $c['centre_id']) ?>><?= e($c['centre_name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <h2 style="margin-top:1rem">Document you will bring <span class="muted" style="font-weight:400">(optional now, verified at the centre)</span></h2>
    <div class="grid grid-3">
      <div><label for="doc_type">Document</label>
        <select id="doc_type" name="doc_type"><option value="">None yet</option>
          <?php foreach (['Birth certificate', "Parent's National ID", 'Passport', 'Residence permit', 'School certificate'] as $d): ?><option<?= selected($data['doc_type'] ?? '', $d) ?>><?= e($d) ?></option><?php endforeach; ?></select></div>
      <div><label for="doc_number">Document number</label><input type="text" id="doc_number" name="doc_number" maxlength="40" value="<?= old($data, 'doc_number') ?>"></div>
      <div><label for="issuing_authority">Issued by</label><input type="text" id="issuing_authority" name="issuing_authority" maxlength="80" value="<?= old($data, 'issuing_authority') ?>"></div>
    </div>
  </fieldset>

  <p><label class="check"><input type="checkbox" name="declaration" value="1"<?= !empty($data['declaration']) ? ' checked' : '' ?>> I confirm that the information given is true and complete.</label></p>
  <div class="actions"><button type="submit" class="btn">Submit application</button><a class="btn btn-secondary" href="index.php">Cancel</a></div>
</form>
</div>
<?php public_footer();
