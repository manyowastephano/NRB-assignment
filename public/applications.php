<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';
require_staff();

/* Review queue: every application, filtered by status and where it came from */
$pdo      = db();
$portalId = portal_officer_id($pdo);
$status   = (string)($_GET['status'] ?? 'Pending');
$source   = (string)($_GET['source'] ?? '');
$mine     = isset($_GET['mine']);
if ($status === 'all' || !in_array($status, APP_STATUSES, true)) { $status = ''; }

$where = []; $params = [];
if ($status !== '')    { $where[] = 'a.status = :s';        $params[':s'] = $status; }
if ($source === 'online')  { $where[] = 'a.captured_by = :po';  $params[':po'] = $portalId; }
if ($source === 'walkin')  { $where[] = 'a.captured_by <> :pw'; $params[':pw'] = $portalId; }
if ($mine)             { $where[] = 'a.centre_id = :mc';    $params[':mc'] = staff()['centre_id']; }

$st = $pdo->prepare(
    'SELECT a.application_id, a.receipt_no, a.application_type, a.application_date, a.status, a.captured_by,
            CONCAT(p.first_name, " ", p.surname) AS applicant, rc.centre_name,
            (SELECT COUNT(*) FROM supporting_document sd WHERE sd.application_id = a.application_id) AS docs,
            (SELECT COUNT(*) FROM supporting_document sd WHERE sd.application_id = a.application_id AND sd.verified = 1) AS docs_ok,
            (SELECT COUNT(*) FROM id_card c WHERE c.application_id = a.application_id) AS has_card
     FROM application a
     JOIN person p ON p.person_id = a.person_id
     JOIN registration_centre rc ON rc.centre_id = a.centre_id '
    . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.application_date DESC, a.application_id DESC LIMIT 200'
);
$st->execute($params);
$rows = $st->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) n FROM application GROUP BY status')->fetchAll() as $c) { $counts[$c['status']] = (int)$c['n']; }
$tab = static fn(string $s, string $label) => '<a href="applications.php?' . http_build_query(array_filter(['status' => $s === '' ? 'all' : $s, 'source' => $GLOBALS['source'], 'mine' => $GLOBALS['mine'] ? '1' : ''])) . '"'
    . ($GLOBALS['status'] === $s ? ' class="active"' : '') . '>' . e($label) . ($s !== '' ? ' (' . ($GLOBALS['counts'][$s] ?? 0) . ')' : '') . '</a>';

page_header('Applications', 'review');
?>
<div class="queue-tabs">
  <?= $tab('Pending', 'Waiting for review') ?>
  <?= $tab('Approved', 'Approved') ?>
  <?= $tab('Rejected', 'Rejected') ?>
  <?= $tab('', 'All') ?>
</div>
<form method="get" action="applications.php" class="card">
  <input type="hidden" name="status" value="<?= e($status === '' ? 'all' : $status) ?>">
  <div class="grid grid-3" style="align-items:end">
    <div><label for="source">Submitted</label>
      <select id="source" name="source"><option value="">Online and walk-in</option>
        <option value="online"<?= selected($source, 'online') ?>>Online (by the applicant)</option>
        <option value="walkin"<?= selected($source, 'walkin') ?>>Walk-in (captured by an officer)</option></select></div>
    <div><label class="check"><input type="checkbox" name="mine" value="1"<?= $mine ? ' checked' : '' ?>> Only my centre (<?= e(staff()['centre']) ?>)</label></div>
    <div><button class="btn" type="submit">Filter</button></div>
  </div>
</form>

<div class="table-wrap"><table>
  <thead><tr><th>Reference</th><th>Applicant</th><th>Type</th><th>Centre</th><th>Submitted</th><th>Documents</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap"><?= e($r['receipt_no']) ?><br><?= (int)$r['captured_by'] === $portalId ? '<span class="tag">Online</span>' : '<span class="tag" style="background:#EDE8D3;color:#5a4a10">Walk-in</span>' ?></td>
      <td><?= e($r['applicant']) ?></td>
      <td><?= e($r['application_type']) ?></td>
      <td><?= e($r['centre_name']) ?></td>
      <td class="nowrap"><?= e($r['application_date']) ?></td>
      <td><?= (int)$r['docs'] === 0 ? '<span class="muted">None</span>' : (int)$r['docs_ok'] . ' of ' . (int)$r['docs'] . ' verified' ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td><a class="btn btn-sm<?= $r['status'] === 'Pending' ? '' : ' btn-secondary' ?>" href="review.php?id=<?= (int)$r['application_id'] ?>"><?= $r['status'] === 'Pending' ? 'Review' : 'Open' ?></a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No applications in this list.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php page_footer();
