<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';

$sheet_id = $_POST['sheet_id'] ?? null;
$page_type = $_POST['page_type'] ?? null;
$type = $_POST['type'] ?? null; // field or file
$id = $_POST['id'] ?? null;

$x = (int)($_POST['x'] ?? 0);
$y = (int)($_POST['y'] ?? 0);
$w = (int)($_POST['width'] ?? 0);
$h = (int)($_POST['height'] ?? 0);

// Input validation
if (!$sheet_id || !$page_type || !$type || !$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

try {
    $field_id = null;
    $file_id = null;

    if ($type === 'field') $field_id = (int)$id;
    elseif ($type === 'file') $file_id = (int)$id;
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type']);
        exit;
    }

    // Check if layout exists
    $stmt = $pdo->prepare("
        SELECT id FROM sheet_layout 
        WHERE sheet_id=? AND page_type=? AND 
              (field_id=? OR file_id=?)
    ");
    $stmt->execute([(int)$sheet_id, $page_type, $field_id, $file_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE sheet_layout 
            SET x=?, y=?, width=?, height=? 
            WHERE id=?
        ");
        $stmt->execute([$x, $y, $w, $h, $existing['id']]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO sheet_layout (sheet_id, field_id, file_id, page_type, x, y, width, height)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([(int)$sheet_id, $field_id, $file_id, $page_type, $x, $y, $w, $h]);
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Layout saved']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
