<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM str_alerts");
    $count = (int)$stmt->fetchColumn();

    $message = "Database `" . DB_NAME . "` dan tabel `str_alerts` berhasil disiapkan. Total records: {$count}.";
    $success = true;
} catch (Throwable $e) {
    $message = "Gagal inisialisasi database: " . $e->getMessage();
    $success = false;
}

if (php_sapi_name() === 'cli') {
    echo ($success ? "[OK] " : "[ERR] ") . $message . PHP_EOL;
    exit($success ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Database AMLO Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f8fafc;">
    <div style="background:#fff;padding:2rem;border-radius:8px;border:1px solid #e2e8f0;max-width:500px;width:100%;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
        <h2 style="margin-top:0;font-size:1.25rem;color:#0f172a;">AMLO Database Setup</h2>
        <p style="color:<?= $success ? '#166534' : '#991b1b' ?>;font-weight:600;"><?= htmlspecialchars($message) ?></p>
        <div style="margin-top:1.5rem;">
            <a href="index.php" class="btn btn-primary" style="display:inline-block;text-decoration:none;">Buka Dashboard</a>
        </div>
    </div>
</body>
</html>
