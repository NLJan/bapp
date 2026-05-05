<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

try {
    $sheet_id = $_POST['sheet_id'] ?? null;
    $page_type = $_POST['page_type'] ?? null;
    $type = $_POST['type'] ?? null; // 'field' or 'file'
    $id = $_POST['id'] ?? null;

    $x = (int)($_POST['x'] ?? 0);
    $y = (int)($_POST['y'] ?? 0);
    $w = (int)($_POST['width'] ?? 200);
    $h = (int)($_POST['height'] ?? 100);

    // Validate required parameters
    if (!$sheet_id || !$page_type || !$type || !$id) {
        http_response_code(400);
        die("Fehlende Parameter");
    }

    $field_id = null;
    $file_id = null;

    if ($type === 'field') {
        $field_id = (int)$id;
    } elseif ($type === 'file') {
        $file_id = (int)$id;
    } else {
        http_response_code(400);
        die("Ungültiger Typ");
    }

    // Check if layout already exists
    $stmt = $pdo->prepare("
        SELECT id FROM sheet_layout 
        WHERE sheet_id = ? AND page_type = ? AND 
              (field_id = ? OR file_id = ?)
    ");
    $stmt->execute([$sheet_id, $page_type, $field_id, $file_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update existing layout
        $stmt = $pdo->prepare("
            UPDATE sheet_layout 
            SET x = ?, y = ?, width = ?, height = ? 
            WHERE id = ?
        ");
        $stmt->execute([$x, $y, $w, $h, $existing['id']]);
    } else {
        // Insert new layout
        $stmt = $pdo->prepare("
            INSERT INTO sheet_layout (sheet_id, field_id, file_id, page_type, x, y, width, height)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sheet_id, $field_id, $file_id, $page_type, $x, $y, $w, $h]);
    }

    http_response_code(200);
    echo "OK";

} catch (Exception $e) {
    http_response_code(500);
    error_log("save_sheet.php error: " . $e->getMessage());
    die("Fehler beim Speichern der Position");
}
?>