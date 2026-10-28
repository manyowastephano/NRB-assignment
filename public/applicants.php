<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

$pdo = db();
$q        = trim((string)($_GET['q'] ?? ''));
$status   = (string)($_GET['status'] ?? '');
$district = (string)($_GET['district'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 10;

// ---------- Build the search conditions with named parameters ----------
$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.first_name LIKE :q1 OR p.surname LIKE :q2 OR CONCAT(p.first_name, " ", p.surname) LIKE :q3
                 OR p.nid_number LIKE :q4 OR a.receipt_no LIKE :q5 OR a.form_no LIKE :q6
                 OR EXISTS (SELECT 1 FROM person_phone ph WHERE ph.person_id = p.person_id AND ph.phone_no LIKE :q7))';
    for ($i = 1; $i <= 7; $i++) { $params[":q$i"] = '%' . $q . '%'; }
}
if (in_array($status, APP_STATUSES, true)) {
    $where[] = 'a.status = :status';
    $params[':status'] = $status;
}
if (ctype_digit($district)) {
    $where[] = 'd.district_id = :district';
    $params[':district'] = (int)$district;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Each person is shown with their latest application and current address
$from = '
  FROM person p
  LEFT JOIN citizen c ON c.person_id = p.person_id
  LEFT JOIN application a ON a.application_id = (SELECT MAX(a2.application_id) FROM application a2 WHERE a2.person_id = p.person_id)
  LEFT JOIN resides_at r ON r.person_id = p.person_id AND r.to_date IS NULL
       AND r.from_date = (SELECT MAX(r2.from_date) FROM resides_at r2 WHERE r2.person_id = p.person_id AND r2.to_date IS NULL)
  LEFT JOIN address ad ON ad.address_id = r.address_id
  LEFT JOIN traditional_authority ta ON ta.ta_id = ad.ta_id
  LEFT JOIN district d ON d.district_id = ta.district_id ';

$count = $pdo->prepare('SELECT COUNT(DISTINCT p.person_id) ' . $from . $whereSql);
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$sql = 'SELECT p.person_id, p.nid_number, p.first_name, p.middle_name, p.surname, p.gender, p.date_of_birth,
               IF(c.person_id IS NULL, "Foreign resident", "Citizen") AS category,
               a.receipt_no, a.status, d.district_name, ad.village '
     . $from . $whereSql . ' ORDER BY p.surname, p.first_name LIMIT :lim OFFSET :off';
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) { $st->bindValue($k, $v); }
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$qs = static fn(int $p): string => '?' . http_build_query(['q' => $q, 'status' => $status, 'district' => $district, 'page' => $p]);

page_header('Applicants', 'applicants');
?>
<form method="get" action="applicants.php" class="card">
  <div class="search-bar">
    <div><label for="q">Search</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, NID number, receipt no., form no. or phone"></div>
    <div><label for="status">Application status</label>
      <select id="status" name="status"><option value="">All</option>
        <?php foreach (APP_STATUSES as $s): ?><option<?= selected($status, $s) ?>><?= e($s) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="district">District</label>
      <select id="district" name="district"><option value="">All</option>
        <?php foreach (lookup_districts() as $dist): ?>
          <option value="<?= (int)$dist['district_id'] ?>"<?= selected($district, $dist['district_id']) ?>><?= e($dist['district_name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><button class="btn" type="submit">Search</button> <a class="btn btn-secondary" href="applicants.php">Reset</a></div>
  </div>
</form>

<p class="muted"><?= $total ?> applicant<?= $total === 1 ? '' : 's' ?> found.</p>
<div class="table-wrap">
  <table>
    <thead><tr><th>Name</th><th>NID number</th><th>Gender</th><th>Date of birth</th><th>Category</th><th>District</th><th>Latest application</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="applicant_view.php?id=<?= (int)$r['person_id'] ?>"><?= e(trim($r['first_name'] . ' ' . $r['middle_name'] . ' ' . $r['surname'])) ?></a></td>
        <td><?= $r['nid_number'] ? e($r['nid_number']) : '<span class="muted">Not yet assigned</span>' ?></td>
        <td><?= e($r['gender']) ?></td>
        <td><?= e($r['date_of_birth']) ?></td>
        <td><?= e($r['category']) ?></td>
        <td><?= e($r['district_name'] ?? '-') ?></td>
        <td><?= $r['receipt_no'] ? e($r['receipt_no']) . ' ' . status_badge($r['status']) : '-' ?></td>
        <td><a class="btn btn-sm btn-secondary" href="applicant_view.php?id=<?= (int)$r['person_id'] ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">No applicants match your search.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">&laquo; Previous</a><?php endif; ?>
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?= $i === $page ? '<span class="current">' . $i . '</span>' : '<a href="' . e($qs($i)) . '">' . $i . '</a>' ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= e($qs($page + 1)) ?>">Next &raquo;</a><?php endif; ?>
  </div>
<?php endif; ?>
<?php page_footer();
