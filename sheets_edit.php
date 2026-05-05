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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Datenblatt bearbeiten – <?= htmlspecialchars($sheet['title']) ?></title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #333;
    line-height: 1.6;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Header */
.header {
    background: white;
    border-bottom: 3px solid #2c3e50;
    padding: 20px 0;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.header h1 {
    font-size: 28px;
    color: #2c3e50;
    margin-bottom: 8px;
}

.header .subtitle {
    color: #7f8c8d;
    font-size: 14px;
}

/* Navigation */
.nav-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.nav-tabs a, .nav-tabs button {
    padding: 10px 16px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    text-decoration: none;
    color: #2c3e50;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.3s ease;
}

.nav-tabs a:hover, .nav-tabs button:hover {
    background: #ecf0f1;
    border-color: #3498db;
}

/* Success Message */
.alert-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success::before {
    content: "✓";
    font-size: 18px;
    font-weight: bold;
}

/* Sections */
.section {
    background: white;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.section h2 {
    font-size: 20px;
    color: #2c3e50;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #ecf0f1;
}

/* Form Fields */
.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: #2c3e50;
    font-size: 14px;
}

.form-group input[type="text"],
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-family: inherit;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-group input[type="text"]:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

/* Base Data Section */
.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 768px) {
    .grid-2 {
        grid-template-columns: 1fr;
    }
}

/* Fields Table */
.fields-table {
    width: 100%;
    border-collapse: collapse;
}

.fields-table thead {
    background: #f8f9fa;
}

.fields-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #ecf0f1;
    color: #2c3e50;
    font-size: 13px;
}

.fields-table td {
    padding: 12px;
    border-bottom: 1px solid #ecf0f1;
}

.fields-table tr:hover {
    background: #f8f9fa;
}

.fields-table input,
.fields-table textarea,
.fields-table select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}

.fields-table textarea {
    min-height: 60px;
    resize: vertical;
}

.delete-btn {
    color: #e74c3c;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.3s ease;
}

.delete-btn:hover {
    background: #fadbd8;
    color: #c0392b;
}

/* Files Section */
.files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
}

.file-card {
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.file-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.file-image {
    width: 100%;
    height: 150px;
    object-fit: cover;
    display: block;
}

.file-info {
    padding: 12px;
}

.file-name {
    font-size: 12px;
    color: #7f8c8d;
    margin-bottom: 8px;
    word-break: break-all;
    white-space: normal;
}

.file-delete {
    color: #e74c3c;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.file-delete:hover {
    color: #c0392b;
}

/* Upload Section */
.upload-area {
    border: 2px dashed #3498db;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    background: #ecf0f1;
    cursor: pointer;
    transition: all 0.3s ease;
}

.upload-area:hover {
    border-color: #2980b9;
    background: #d5dbdb;
}

.upload-area input[type="file"] {
    display: none;
}

.upload-area label {
    cursor: pointer;
    margin: 0;
}

/* Buttons */
.button-group {
    display: flex;
    gap: 12px;
    margin-top: 30px;
    flex-wrap: wrap;
}

button[type="submit"] {
    padding: 12px 24px;
    background: #3498db;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

button[type="submit"]:hover {
    background: #2980b9;
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
}

button[type="submit"]:active {
    transform: scale(0.98);
}

.btn-secondary {
    padding: 12px 20px;
    background: #27ae60;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    display: inline-block;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-secondary:hover {
    background: #229954;
    box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
}

/* Add Field Section */
.add-field-section {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-top: 15px;
}

.add-field-section h3 {
    font-size: 14px;
    color: #2c3e50;
    margin-bottom: 15px;
    font-weight: 600;
}

.grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}

@media (max-width: 768px) {
    .grid-3 {
        grid-template-columns: 1fr;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .container {
        padding: 10px;
    }
    
    .header h1 {
        font-size: 22px;
    }
    
    .section {
        padding: 15px;
    }
    
    .fields-table {
        font-size: 13px;
    }
    
    .files-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<div class="header">
    <div class="container">
        <h1>📋 <?= htmlspecialchars($sheet['title']) ?></h1>
        <div class="subtitle">Datenblatt-ID: <?= (int)$sheet_id ?> | Status: <strong><?= htmlspecialchars($sheet['status']) ?></strong></div>
    </div>
</div>

<div class="container">

    <!-- Success Message -->
    <?php if (isset($_GET['saved'])): ?>
    <div class="alert-success">Erfolgreich gespeichert!</div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <a href="index.php?page=sheets">← Zurück zur Liste</a>
        <a href="sheet_layout.php?id=<?= $sheet_id ?>&type=overview">📐 Layout Übersicht</a>
        <a href="sheet_layout.php?id=<?= $sheet_id ?>&type=wps">📐 Layout WPS</a>
        <a href="sheet_view.php?id=<?= $sheet_id ?>&type=overview">👁️ Vorschau / Druck</a>
        <a href="revisions.php?id=<?= $sheet_id ?>">📜 Versionshistorie</a>
    </div>

    <form method="post" action="sheets_edit.php?id=<?= (int)$sheet_id ?>" enctype="multipart/form-data">

        <!-- Basic Data Section -->
        <div class="section">
            <h2>📝 Grundinformationen</h2>
            <div class="grid-2">
                <div class="form-group">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" value="<?= htmlspecialchars($sheet['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="Nieuw" <?= $sheet['status'] === 'Nieuw' ? 'selected' : '' ?>>Nieuw</option>
                        <option value="In bewerking" <?= $sheet['status'] === 'In bewerking' ? 'selected' : '' ?>>In bewerking</option>
                        <option value="Gereed" <?= $sheet['status'] === 'Gereed' ? 'selected' : '' ?>>Gereed</option>
                        <option value="Fout" <?= $sheet['status'] === 'Fout' ? 'selected' : '' ?>>Fout</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="revision">Revisie</label>
                    <input type="text" id="revision" name="revision" value="<?= htmlspecialchars($sheet['revision']) ?>" maxlength="10">
                </div>
                <div class="form-group">
                    <label for="notes">Notizen</label>
                    <textarea id="notes" name="notes" placeholder="Zusätzliche Notizen..."><?= htmlspecialchars($sheet['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Fields Section -->
        <div class="section">
            <h2>📌 Datenfelder</h2>
            
            <?php if (count($fields) > 0): ?>
            <table class="fields-table">
                <thead>
                    <tr>
                        <th>Label</th>
                        <th>Wert</th>
                        <th>Zielseite</th>
                        <th style="width: 80px;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fields as $f): ?>
                    <tr>
                        <td>
                            <input type="text" name="label_<?= $f['id'] ?>" value="<?= htmlspecialchars($f['label']) ?>" placeholder="Feldname">
                        </td>
                        <td>
                            <textarea name="value_<?= $f['id'] ?>" placeholder="Feldwert"><?= htmlspecialchars($f['value']) ?></textarea>
                        </td>
                        <td>
                            <select name="target_<?= $f['id'] ?>">
                                <option value="overview" <?= $f['target'] === 'overview' ? 'selected' : '' ?>>Übersicht</option>
                                <option value="wps" <?= $f['target'] === 'wps' ? 'selected' : '' ?>>WPS</option>
                                <option value="both" <?= $f['target'] === 'both' ? 'selected' : '' ?>>Beide</option>
                                <option value="hidden" <?= $f['target'] === 'hidden' ? 'selected' : '' ?>>Verbergen</option>
                            </select>
                        </td>
                        <td>
                            <a class="delete-btn" href="sheets_edit.php?id=<?= $sheet_id ?>&delete_field=<?= $f['id'] ?>" onclick="return confirm('Wirklich löschen?')">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color: #7f8c8d; text-align: center; padding: 20px;">Noch keine Felder vorhanden</p>
            <?php endif; ?>

            <!-- Add New Field -->
            <div class="add-field-section">
                <h3>➕ Neues Feld hinzufügen</h3>
                <div class="grid-3">
                    <input type="text" name="new_label" placeholder="Feldname" maxlength="100">
                    <textarea name="new_value" placeholder="Feldwert" style="min-height: 40px;"></textarea>
                    <select name="new_target">
                        <option value="overview">Übersicht</option>
                        <option value="wps">WPS</option>
                        <option value="both">Beide</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Files Section -->
        <div class="section">
            <h2>🖼️ Dateien / Bilder</h2>
            
            <?php if (count($files) > 0): ?>
            <div class="files-grid">
                <?php foreach ($files as $file): ?>
                <div class="file-card">
                    <img src="<?= $upload_url . '/' . $file['filename'] ?>" alt="<?= htmlspecialchars($file['filename']) ?>" class="file-image">
                    <div class="file-info">
                        <div class="file-name">📄 <?= htmlspecialchars(substr($file['filename'], 11)) ?></div>
                        <a class="file-delete" href="sheets_edit.php?id=<?= $sheet_id ?>&delete_file=<?= $file['id'] ?>" onclick="return confirm('Wirklich löschen?')">🗑️ Löschen</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color: #7f8c8d; text-align: center; padding: 20px;">Noch keine Dateien vorhanden</p>
            <?php endif; ?>

            <!-- Upload File -->
            <div class="upload-area" style="margin-top: 20px;">
                <label for="file-input">
                    <div style="font-size: 32px; margin-bottom: 10px;">📁</div>
                    <div><strong>Datei hochladen</strong></div>
                    <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">Klicken oder Dateien hierher ziehen</div>
                </label>
                <input type="file" id="file-input" name="new_file" accept="image/*">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="button-group">
            <button type="submit">💾 Speichern</button>
            <a href="copy_sheet.php?id=<?= $sheet_id ?>" class="btn-secondary">📋 Blatt duplizieren</a>
        </div>

    </form>

</div>

<script>
// File upload drag & drop
const uploadArea = document.querySelector('.upload-area');
const fileInput = document.getElementById('file-input');

uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.style.borderColor = '#2980b9';
    uploadArea.style.background = '#d5dbdb';
});

uploadArea.addEventListener('dragleave', () => {
    uploadArea.style.borderColor = '#3498db';
    uploadArea.style.background = '#ecf0f1';
});

uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
    }
});

// Show selected file name
fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        uploadArea.querySelector('div').textContent = e.target.files[0].name;
    }
});
</script>

</body>
</html>