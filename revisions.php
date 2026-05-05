<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$sheet_id = $_GET['id'] ?? null;
if (!$sheet_id) die("Geen sheet ID opgegeven.");

$stmt = $pdo->prepare("SELECT title FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);
$sheet = $stmt->fetch();

if (!$sheet) die("Sheet niet gevonden.");

$stmt = $pdo->prepare("
    SELECT revision, created_at
    FROM sheet_revisions
    WHERE sheet_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$sheet_id]);
$revisions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Revisie-archief – <?= htmlspecialchars($sheet['title']) ?></title>
<style>
    body { font-family: Arial; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
    th, td { border: 1px solid #ccc; padding: 8px; }
    th { background: #eee; }
    a { color: #0066cc; text-decoration: none; }
    a:hover { text-decoration: underline; }
</style>
</head>
<body>

<h1>Revisie-archief</h1>
<h2><?= htmlspecialchars($sheet['title']) ?></h2>

<p><a href="sheets_edit.php?id=<?= $sheet_id ?>">← Terug naar bewerken</a></p>

<?php if (empty($revisions)): ?>
<p><em>Geen revisies beschikbaar.</em></p>
<?php else: ?>
<table>
    <tr>
        <th>Revisie</th>
        <th>Aangemaakt op</th>
        <th>Acties</th>
    </tr>

    <?php foreach ($revisions as $rev): ?>
    <tr>
        <td><strong><?= htmlspecialchars($rev['revision']) ?></strong></td>
        <td><?= htmlspecialchars($rev['created_at']) ?></td>
        <td>
            <a href="sheet_view.php?id=<?= $sheet_id ?>&rev=<?= urlencode($rev['revision']) ?>">Bekijken</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>