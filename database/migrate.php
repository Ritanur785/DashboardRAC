<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();

$columnsToAdd = [
    'resiko' => "VARCHAR(50) NULL DEFAULT NULL AFTER no_rekening",
    'brilink' => "VARCHAR(30) NULL DEFAULT NULL AFTER resiko",
    'status_pekerja' => "VARCHAR(50) NULL DEFAULT NULL AFTER brilink",
    'digital_saving' => "VARCHAR(50) NULL DEFAULT NULL AFTER status_pekerja",
    'scoring' => "VARCHAR(30) NULL DEFAULT NULL AFTER skenario",
    'info_param' => "VARCHAR(100) NULL DEFAULT NULL AFTER scoring",
    'unit_kerja' => "VARCHAR(120) NULL DEFAULT NULL AFTER kategori",
    'kantor_cabang' => "VARCHAR(120) NULL DEFAULT NULL AFTER unit_kerja",
    'kantor_kanwil' => "VARCHAR(120) NULL DEFAULT NULL AFTER kantor_cabang",
    'disposisi' => "VARCHAR(150) NULL DEFAULT NULL AFTER status",
    'info_lainnya' => "TEXT NULL DEFAULT NULL AFTER disposisi",
];

$existingColumns = $pdo->query("DESCRIBE str_alerts")->fetchAll(PDO::FETCH_COLUMN);

foreach ($columnsToAdd as $col => $definition) {
    if (!in_array($col, $existingColumns, true)) {
        $pdo->exec("ALTER TABLE str_alerts ADD COLUMN `{$col}` {$definition}");
    }
}

$pdo->exec("ALTER TABLE str_alerts MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Belum TL'");

echo "[OK] Tabel str_alerts berhasil diperbarui dengan kolom-kolom baru.\n";
