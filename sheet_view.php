<?php
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$sheet_id  = $_GET['id']  ?? null;
$page_type = $_GET['type'] ?? 'overview';

if (!$sheet_id) {
    die("Kein Datenblatt angegeben.");
}

// Eerst sheet ophalen
$stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
$stmt->execute([$sheet_id]);
$sheet = $stmt->fetch();

// Revisie bepalen
$rev = $_GET['rev'] ?? $sheet['revision'] ?? 'A';


if (!$sheet) {
    die("Datenblatt nicht gefunden.");
}

// Velden
$stmt = $pdo->prepare("
    SELECT * FROM sheet_fields
    WHERE sheet_id = ? AND (target = ? OR target = 'both')
");
$stmt->execute([$sheet_id, $page_type]);
$fields = $stmt->fetchAll();

// Afbeeldingen
$stmt = $pdo->prepare("
    SELECT * FROM sheet_files
    WHERE sheet_id = ? AND (target = ? OR target = 'both')
");
$stmt->execute([$sheet_id, $page_type]);
$files = $stmt->fetchAll();

// Layout
$stmt = $pdo->prepare("
    SELECT * FROM sheet_layout
    WHERE sheet_id = ? AND page_type = ?
");
$stmt->execute([$sheet_id, $page_type]);
$layout = [];
foreach ($stmt->fetchAll() as $l) {
    $key = $l['field_id'] ? $l['field_id'] : ('f' . $l['file_id']);
    $layout[$key] = $l;
}

// QR ophalen
$stmt = $pdo->prepare("SELECT code FROM qr_codes WHERE sheet_id = ?");
$stmt->execute([$sheet_id]);
$qr = $stmt->fetch();

$qr_code = isset($qr['code']) ? $qr['code'] : '';
$qr_url = "https://magdajan.eu/Entwurf/qr.php?code=" . urlencode($qr_code);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>View – <?= htmlspecialchars($sheet['title']) ?></title>

<style>
    body {
        margin: 0;
        padding: 0;
        background: #ccc;
        font-family: Arial, sans-serif;
    }

    #toolbar {
        background: #333;
        color: white;
        padding: 10px;
        text-align: right;
        position: sticky;
        top: 0;
        z-index: 999;
    }

    #toolbar button {
        padding: 8px 14px;
        font-size: 16px;
        cursor: pointer;
    }

    #a4 {
        width: 1123px;
        height: 794px;
        margin: 20px auto;
        background: white;
        border: 4px solid #000;
        position: relative;
        overflow: hidden;
    }

    #titlebar {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 60px;
        border-bottom: 3px solid #000;
        display: flex;
        background: #f2f2f2;
        font-size: 16px;
        padding: 5px 10px;
        box-sizing: border-box;
    }
    #titlebar .left { width: 40%; font-size: 18px; font-weight: bold; }
    #titlebar .center { width: 30%; text-align: center; }
    #titlebar .right { width: 30%; text-align: right; }

    #company-block {
        position: absolute;
        top: 70px;
        left: 20px;
        width: 300px;
        padding: 10px;
        border: 2px solid #000;
        background: #f7f7f7;
        font-size: 14px;
        box-sizing: border-box;
    }

    #qr-block {
        position: absolute;
        top: 20px;
        right: 20px;
    }

    #footer {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 40px;
        border-top: 3px solid #000;
        background: #f2f2f2;
        text-align: center;
        line-height: 40px;
        font-weight: bold;
    }

    .block {
        position: absolute;
        border: 1px solid #444;
        padding: 5px;
        background: #fdfdfd;
        overflow: hidden;
        font-size: 14px;
        box-sizing: border-box;
    }

    /* PRINT CSS */
    @media print {
        #toolbar { display: none; }
        body { background: white; }
        #a4 { margin: 0; border: none; }
    }
</style>
</head>
<body>

<div id="toolbar">
Revisie: <?= htmlspecialchars($rev) ?>
<a href="revisions.php?id=<?= $sheet_id ?>">Alle revisies</a>

    <button onclick="window.print()">Print / PDF</button>
</div>

<div id="a4">

    <div id="titlebar">
        <div class="left"><?= htmlspecialchars($sheet['title']) ?></div>
        <div class="center">
            Status: <?= htmlspecialchars($sheet['status']) ?><br>
            Revisie: <?= htmlspecialchars($rev) ?>
        </div>
        <div class="right">
            Datum: <?= date('d.m.Y') ?>
        </div>
    </div>

    <div id="company-block">
        <img src="<?= $upload_url ?>/logo.png" style="max-width:120px; margin-bottom:10px;"><br>
        <strong>MAGADALE TECHNIK</strong><br>
        Industriestraße 12<br>
        49828 Neuenhaus<br>
        Tel: +49 123 456 789<br>
        E-Mail: info@magadale.eu
    </div>

    <div id="qr-block">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($qr_url) ?>">
    </div>

    <?php foreach ($fields as $f):
    if ($f['target'] === 'hidden') continue;

        $pos = $layout[$f['id']] ?? ['x'=>50,'y'=>150,'width'=>200,'height'=>80];
    ?>
        <div class="block"
             style="left:<?= $pos['x'] ?>px;
                    top:<?= $pos['y'] ?>px;
                    width:<?= $pos['width'] ?>px;
                    height:<?= $pos['height'] ?>px;">
            <strong><?= htmlspecialchars($f['label']) ?></strong><br>
            <?= nl2br(htmlspecialchars($f['value'])) ?>
        </div>
    <?php endforeach; ?>

    <?php foreach ($files as $file):
        $key = 'f' . $file['id'];
        $pos = $layout[$key] ?? ['x'=>100,'y'=>200,'width'=>250,'height'=>200];
    ?>
        <div class="block"
             style="left:<?= $pos['x'] ?>px;
                    top:<?= $pos['y'] ?>px;
                    width:<?= $pos['width'] ?>px;
                    height:<?= $pos['height'] ?>px;">
            <img src="<?= $upload_url . '/' . $file['filename'] ?>"
                 style="width:100%; height:100%; object-fit:contain;">
        </div>
    <?php endforeach; ?>

    <div id="footer">
        Vertraulich behandeln – nur für den internen Gebrauch
    </div>

</div>

</body>
</html>