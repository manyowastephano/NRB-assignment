<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$centres = (int)db()->query("SELECT COUNT(*) FROM registration_centre WHERE status = 'Active'")->fetchColumn();

public_header('Home', 'home');
?>
<section class="landing-hero">
  <h1>Apply for your Malawi National ID</h1>
  <p>Fill in your details online, receive a reference number, and follow your application until your ID card is ready for collection.</p>
  <div class="actions">
    <a class="btn btn-light" href="apply.php">Start your application</a>
    <a class="btn btn-ghost" href="track.php">Track an application</a>
  </div>
</section>

<h2>How it works</h2>
<div class="steps">
  <div class="step"><div class="n">1</div><h3>Apply online</h3><p>Enter your personal details, address and the documents you have. You receive a reference number straight away.</p></div>
  <div class="step"><div class="n">2</div><h3>Visit your centre</h3><p>Take your original documents to the registration centre you chose. An officer verifies them and captures your fingerprints and photo.</p></div>
  <div class="step"><div class="n">3</div><h3>Approval</h3><p>An officer approves the application, or tells you what is missing. You can check the progress at any time.</p></div>
  <div class="step"><div class="n">4</div><h3>Collect your card</h3><p>When the status shows that your card is ready, collect it at the same centre.</p></div>
</div>

<div class="two-col">
  <div class="card">
    <h2>What you need</h2>
    <ul class="checklist">
      <li>Your birth certificate, or a parent's National ID</li>
      <li>A phone number the bureau can reach you on</li>
      <li>The village and Traditional Authority where you live</li>
      <li>No birth certificate or parent's ID? Bring a witness (a chief or a person who already holds an ID)</li>
      <li>Foreign residents: passport and residence permit</li>
    </ul>
  </div>
  <div class="card">
    <h2>Already applied?</h2>
    <p>Use the reference number you received and your date of birth to see where your application is.</p>
    <a class="btn" href="track.php">Track my application</a>
    <p class="help" style="margin-top:1rem"><?= $centres ?> active registration centre<?= $centres === 1 ? '' : 's' ?> across Malawi.</p>
  </div>
</div>
<?php public_footer();
