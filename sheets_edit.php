<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$sheet_id = $_GET['id'] ?? null;
if (!$sheet_id) die("Kein Datenblatt angegeben.");

// Sheet ophalen
$stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);
$sheet = $stmt->fetch();
if (!$sheet) die("Datenblatt nicht gefunden.");

// Velden ophalen
$stmt = $pdo->prepare("SELECT * FROM sheet_fields WHERE sheet_id = ? ORDER BY id ASC");
$stmt->execute([$sheet_id]);
$fields = $stmt->fetchAll();

// Bestanden ophalen
$stmt = $pdo->prepare("SELECT * FROM sheet_files WHERE sheet_id = ? ORDER BY id ASC");
$stmt->execute([$sheet_id]);
$files = $stmt->fetchAll();

$success = false;

/* -----------------------------
   VELD VERWIJDEREN
------------------------------*/
if (isset($_GET['delete_field'])) {
    $fid = intval($_GET['delete_field']);
    $stmt = $pdo->prepare("DELETE FROM sheet_fields WHERE id = ? AND sheet_id = ?");
    $stmt->execute([$fid, $sheet_id]);
    header("Location: sheets_edit.php?id=$sheet_id");
    exit;
}

/* -----------------------------
   BESTAND VERWIJDEREN
------------------------------*/
if (isset($_GET['delete_file'])) {
    $file_id = intval($_GET['delete_file']);
    $stmt = $pdo->prepare("SELECT filename FROM sheet_files WHERE id = ? AND sheet_id = ?");
    $stmt->execute([$file_id, $sheet_id]);
    $file = $stmt->fetch();
    
    if ($file) {
        // Verwijder bestand van schijf
        $filepath = __DIR__ . "/uploads/" . $file['filename'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        // Verwijder uit database
        $stmt = $pdo->prepare("DELETE FROM sheet_files WHERE id = ? AND sheet_id = ?");
        $stmt->execute([$file_id, $sheet_id]);
    }
    header("Location: sheets_edit.php?id=$sheet_id");
    exit;
}

/* -----------------------------
   OPSLAAN
------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Basisgegevens
    $title    = trim($_POST['title']);
    $status   = trim($_POST['status']);
    $revision = trim($_POST['revision']);
    $notes    = trim($_POST['notes']);

    if ($revision === '') $revision = 'A';

    // Velden opslaan
    foreach ($fields as $f) {
        $fid = $f['id'];
        $label  = $_POST["label_$fid"] ?? '';
        $value  = $_POST["value_$fid"] ?? '';
        $target = $_POST["target_$fid"] ?? 'overview';

        $stmt = $pdo->prepare("
            UPDATE sheet_fields
            SET label = ?, value = ?, target = ?
            WHERE id = ?
        ");
        $stmt->execute([$label, $value, $target, $fid]);
    }

    // Nieuw veld toevoegen
    if (!empty($_POST['new_label'])) {
        $stmt = $pdo->prepare("
            INSERT INTO sheet_fields (sheet_id, label, value, target)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $sheet_id,
            $_POST['new_label'],
            $_POST['new_value'] ?? '',
            $_POST['new_target'] ?? 'overview'
        ]);
    }

    // Bestanden uploaden
    if (!empty($_FILES['new_file']['name'])) {
        $filename = time() . "_" . basename($_FILES['new_file']['name']);
        $target = __DIR__ . "/uploads/" . $filename;

        if (move_uploaded_file($_FILES['new_file']['tmp_name'], $target)) {
            $stmt = $pdo->prepare("
                INSERT INTO sheet_files (sheet_id, filename, target)
                VALUES (?, ?, 'both')
            ");
            $stmt->execute([$sheet_id, $filename]);
        }
    }

    // Sheet opslaan
    $stmt = $pdo->prepare("
        UPDATE sheets
        SET title = ?, status = ?, revision = ?, notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$title, $status, $revision, $notes, $sheet_id]);

    // QR-code revisie bijwerken
 //   $stmt = $pdo->prepare("UPDATE qr_codes SET current_revision = ? WHERE sheet_id = ?");
 //   $stmt->execute([$revision, $sheet_id]);

    // Snapshot opslaan
    $stmt = $pdo->prepare("SELECT id, label, value FROM sheet_fields WHERE sheet_id = ?");
    $stmt->execute([$sheet_id]);
    $fields_snapshot = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, filename FROM sheet_files WHERE sheet_id = ?");
    $stmt->execute([$sheet_id]);
    $files_snapshot = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        INSERT INTO sheet_revisions (sheet_id, revision, fields_json, files_json)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $sheet_id,
        $revision,
        json_encode($fields_snapshot, JSON_UNESCAPED_UNICODE),
        json_encode($files_snapshot, JSON_UNESCAPED_UNICODE)
    ]);

    $success = true;
    header("Location: sheets_edit.php?id=$sheet_id&saved=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Datenblatt bearbeiten – <?= htmlspecialchars($sheet['title']) ?></title>
<style>
body { font-family: Arial; padding: 20px; }
table { border-collapse: collapse; width: 100%; margin-top: 20px; }
th, td { border: 1px solid #ccc; padding: 8px; }
th { background: #eee; }
input[type=text], textarea { width: 100%; }
.delete { color: red; font-weight: bold; }
</style>
</head>
<body>

<h1>Datenblatt bearbeiten</h1>

<p>
    <a href="index.php?page=sheets">Zurück</a> |
    <a href="sheet_layout.php?id=<?= $sheet_id ?>&type=overview">Layout Übersicht</a> |
    <a href="sheet_layout.php?id=<?= $sheet_id ?>&type=wps">Layout WPS</a> |
    <a href="sheet_view.php?id=<?= $sheet_id ?>&type=overview">Ansicht / Print</a>
</p>

<?php if (isset($_GET['saved'])): ?>
<div style="color:green;">Gespeichert.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

<h2>Basisdaten</h2>

<table>
<tr><th>Feld</th><th>Wert</th></tr>

<tr>
    <td>Titel</td>
    <td><input type="text" name="title" value="<?= htmlspecialchars($sheet['title']) ?>"></td>
</tr>

<tr>
    <td>Status</td>
    <td><input type="text" name="status" value="<?= htmlspecialchars($sheet['status']) ?>"></td>
</tr>

<tr>
    <td>Revision</td>
    <td><input type="text" name="revision" value="<?= htmlspecialchars($sheet['revision']) ?>"></td>
</tr>

<tr>
    <td>Notizen</td>
    <td><textarea name="notes" rows="3"><?= htmlspecialchars($sheet['notes'] ?? '') ?></textarea></td>
</tr>
</table>

<h2>Felder</h2>

<table>
<tr>
    <th>Label</th>
    <th>Wert</th>
    <th>Zielseite</th>
    <th>Delete</th>
</tr>

<?php foreach ($fields as $f): ?>
<tr>
    <td><input type="text" name="label_<?= $f['id'] ?>" value="<?= htmlspecialchars($f['label']) ?>"></td>
    <td><textarea name="value_<?= $f['id'] ?>" rows="2"><?= htmlspecialchars($f['value']) ?></textarea></td>
    <td>
     <select name="target_<?= $f['id'] ?>">
    <option value="overview" <?= $f['target'] === 'overview' ? 'selected' : '' ?>>Übersicht</option>
    <option value="wps" <?= $f['target'] === 'wps' ? 'selected' : '' ?>>WPS</option>
    <option value="both" <?= $f['target'] === 'both' ? 'selected' : '' ?>>Beide</option>
    <option value="hidden" <?= $f['target'] === 'hidden' ? 'selected' : '' ?>>Verbergen</option>
</select>

    </td>
    <td><a class="delete" href="sheets_edit.php?id=<?= $sheet_id ?>&delete_field=<?= $f['id'] ?>">X</a></td>
</tr>
<?php endforeach; ?>

<tr>
    <td><input type="text" name="new_label" placeholder="Neues Feld"></td>
    <td><textarea name="new_value" rows="2"></textarea></td>
    <td>
        <select name="new_target">
            <option value="overview">Übersicht</option>
            <option value="wps">WPS</option>
            <option value="both">Beide</option>
        </select>
    </td>
    <td></td>
</tr>

</table>

<h2>Dateien</h2>

<?php foreach ($files as $file): ?>
<div style="margin:10px 0;">
    <img src="<?= $upload_url . '/' . $file['filename'] ?>" style="max-width:200px;"><br>
    <?= htmlspecialchars($file['filename']) ?>
    <a class="delete" href="sheets_edit.php?id=<?= $sheet_id ?>&delete_file=<?= $file['id'] ?>">[Löschen]</a>
</div>
<?php endforeach; ?>

<label>Neue Datei hochladen<br>
<input type="file" name="new_file">
</label>

<br><br>
<button type="submit">Speichern</button><br />
<a href="copy_sheet.php?id=<?= $sheet_id ?>" 
   style="padding:6px 12px; background:#4CAF50; color:white; text-decoration:none;">
   Blad kopiëren
</a>


</form>

</body>
</html>