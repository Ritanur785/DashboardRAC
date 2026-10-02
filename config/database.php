<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'DashboardRAC');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection(): PDO {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $hosts = [DB_HOST, '127.0.0.1', 'localhost'];
    $hosts = array_values(array_unique($hosts));

    $lastException = null;
    foreach ($hosts as $host) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, DB_PORT);
            $rootPdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            $dbDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, DB_PORT, DB_NAME);
            $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
            
            ensureSchemaExists($pdo);
            return $pdo;
        } catch (PDOException $e) {
            $lastException = $e;
        }
    }

    http_response_code(500);
    echo '<div style="font-family:Segoe UI,sans-serif;max-width:620px;margin:60px auto;padding:24px 28px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.06);color:#991b1b;">';
    echo '<h3 style="margin-top:0;color:#b91c1c;display:flex;align-items:center;gap:8px;">';
    echo '<span>Gagal Terhubung ke Database MySQL</span>';
    echo '</h3>';
    echo '<p style="color:#4b5563;font-size:14px;line-height:1.6;margin-bottom:16px;">Layanan MySQL di komputer Anda sedang tidak aktif atau terhenti (<strong>Connection refused</strong>).</p>';
    echo '<div style="background:#ffffff;padding:16px;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#1f2937;line-height:1.7;">';
    echo '<strong style="color:#b91c1c;">Langkah Solusi Cepat:</strong><br>';
    echo '1. Buka aplikasi <strong>XAMPP Control Panel</strong>.<br>';
    echo '2. Klik tombol <strong>Start</strong> pada baris modul <strong>MySQL</strong>.<br>';
    echo '3. Pastikan lampu indikator MySQL berubah menjadi hijau (<strong>Port 3306</strong>).<br>';
    echo '4. Kembali ke browser dan tekan <strong>Refresh (F5)</strong>.';
    echo '</div>';
    echo '<p style="margin-top:16px;margin-bottom:0;font-size:12px;color:#9ca3af;">Pesan Sistem: ' . htmlspecialchars($lastException ? $lastException->getMessage() : 'Connection refused') . '</p>';
    echo '</div>';
    exit;
}

function ensureSchemaExists(PDO $pdo): void {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'str_alerts'");
    if ($tableCheck->rowCount() === 0) {
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        }
    }

    $pepCheck = $pdo->query("SHOW TABLES LIKE 'pep_alerts'");
    if ($pepCheck->rowCount() === 0) {
        $pepMigration = __DIR__ . '/../database/migrations/create_pep_alerts.sql';
        if (file_exists($pepMigration)) {
            $sql = file_get_contents($pepMigration);
            $pdo->exec($sql);
        }
    }

    $badDataCheck = $pdo->query("SHOW TABLES LIKE 'bad_data'");
    if ($badDataCheck->rowCount() === 0) {
        $badDataMigration = __DIR__ . '/../database/migrations/create_bad_data.sql';
        if (file_exists($badDataMigration)) {
            $sql = file_get_contents($badDataMigration);
            $pdo->exec($sql);
        }
    } else {
        $cols = $pdo->query("DESCRIBE bad_data")->fetchAll(PDO::FETCH_COLUMN);
        $newCols = [
            'no_urut'            => "VARCHAR(50) NULL DEFAULT NULL AFTER id",
            'total_cif'          => "VARCHAR(50) NULL DEFAULT NULL",
            'total'              => "VARCHAR(50) NULL DEFAULT NULL",
            'reguler'            => "VARCHAR(50) NULL DEFAULT NULL",
            'kerjasama'          => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_prev'      => "VARCHAR(50) NULL DEFAULT NULL",
            'persentase_prev'    => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_curr'      => "VARCHAR(50) NULL DEFAULT NULL",
            'persentase_curr'    => "VARCHAR(50) NULL DEFAULT NULL",
            'perbaikan_bad_data' => "VARCHAR(50) NULL DEFAULT NULL",
            'bad_data_baru'      => "VARCHAR(50) NULL DEFAULT NULL",
            'keterangan'         => "TEXT NULL DEFAULT NULL",
            'tgl_prev'           => "VARCHAR(50) NULL DEFAULT NULL",
            'tgl_curr'           => "VARCHAR(50) NULL DEFAULT NULL",
        ];
        foreach ($newCols as $c => $def) {
            if (!in_array($c, $cols, true)) {
                $pdo->exec("ALTER TABLE bad_data ADD COLUMN `{$c}` {$def}");
            }
        }
    }

    $racCheck = $pdo->query("SHOW TABLES LIKE 'data_rac'");
    if ($racCheck->rowCount() === 0) {
        $racMigration = __DIR__ . '/../database/migrations/create_data_rac.sql';
        if (file_exists($racMigration)) {
            $sql = file_get_contents($racMigration);
            $pdo->exec($sql);
        }
    }

    $ujiPetikCheck = $pdo->query("SHOW TABLES LIKE 'uji_petik'");
    if ($ujiPetikCheck->rowCount() === 0) {
        $ujiPetikMigration = __DIR__ . '/../database/migrations/create_uji_petik.sql';
        if (file_exists($ujiPetikMigration)) {
            $sql = file_get_contents($ujiPetikMigration);
            $pdo->exec($sql);
        }
    }

    $nilaiMaturitasCheck = $pdo->query("SHOW TABLES LIKE 'nilai_maturitas'");
    if ($nilaiMaturitasCheck->rowCount() === 0) {
        $nilaiMaturitasMigration = __DIR__ . '/../database/migrations/create_nilai_maturitas.sql';
        if (file_exists($nilaiMaturitasMigration)) {
            $sql = file_get_contents($nilaiMaturitasMigration);
            $pdo->exec($sql);
        }
    }

    $penilaianResikoCheck = $pdo->query("SHOW TABLES LIKE 'penilaian_resiko'");
    if ($penilaianResikoCheck->rowCount() === 0) {
        $penilaianResikoMigration = __DIR__ . '/../database/migrations/create_penilaian_resiko.sql';
        if (file_exists($penilaianResikoMigration)) {
            $sql = file_get_contents($penilaianResikoMigration);
            $pdo->exec($sql);
        }
    }

    // Auto-koreksi otomatis data rating maturitas jika terdapat nilai non-rating
    try {
        $pdo->exec("
            UPDATE nilai_maturitas
            SET rating_maturitas = CASE
                WHEN CAST(nilai_maturitas AS DECIMAL(10,2)) >= 8.0 THEN 'Sangat Baik'
                WHEN CAST(nilai_maturitas AS DECIMAL(10,2)) >= 6.5 THEN 'Baik'
                WHEN CAST(nilai_maturitas AS DECIMAL(10,2)) >= 4.0 THEN 'Cukup'
                ELSE 'Kurang'
            END
            WHERE rating_maturitas IS NULL 
               OR rating_maturitas NOT IN ('Sangat Baik', 'Baik', 'Cukup', 'Kurang')
        ");
    } catch (Throwable $e) {}

    // Auto-koreksi otomatis data level risiko jika terdapat nilai non-level
    try {
        $pdo->exec("
            UPDATE penilaian_resiko
            SET level_risiko = CASE
                WHEN CAST(nilai_resiko AS DECIMAL(10,2)) >= 3.5 THEN 'Large'
                WHEN CAST(nilai_resiko AS DECIMAL(10,2)) >= 2.0 THEN 'Medium'
                ELSE 'Small'
            END
            WHERE level_risiko IS NULL 
               OR level_risiko NOT IN ('Large', 'Medium', 'Small')
        ");
        $pdo->exec("UPDATE penilaian_resiko SET status = level_risiko WHERE status != level_risiko");
    } catch (Throwable $e) {}

    // Auto-pembersihan otomatis data sampah/footnote/legend pada tabel uji_petik
    try {
        $pdo->exec("
            DELETE FROM uji_petik
            WHERE branch LIKE '*%'
               OR branch LIKE '**%'
               OR branch LIKE '%http%'
               OR branch LIKE 'Evidence%'
               OR branch LIKE 'Keterangan%'
               OR branch LIKE 'Note%'
               OR branch LIKE '(sedang%'
               OR branch LIKE '(%'
               OR branch REGEXP '^[0-9]+[\\.\\)]'
               OR branch IN ('-', 'Cabang', 'Unit Kerja', 'Branch', 'NIHIL', 'Total')
        ");

        // Expand dokumen column to TEXT
        $pdo->exec("ALTER TABLE uji_petik MODIFY dokumen TEXT");

        // Merge duplicate BO Pringsewu into KC Pringsewu if both exist
        $pdo->exec("
            UPDATE uji_petik dst
            JOIN uji_petik src ON src.branch = 'BO Pringsewu'
            SET dst.keterangan = COALESCE(NULLIF(src.keterangan, ''), dst.keterangan),
                dst.dokumen = CASE WHEN dst.dokumen = '-' AND src.dokumen != '-' THEN src.dokumen ELSE dst.dokumen END,
                dst.rencana_pelaksanaan = CASE WHEN dst.rencana_pelaksanaan = '-' AND src.rencana_pelaksanaan != '-' THEN src.rencana_pelaksanaan ELSE dst.rencana_pelaksanaan END,
                dst.tanggal_uji_petik = CASE WHEN dst.tanggal_uji_petik = '-' AND src.tanggal_uji_petik != '-' THEN src.tanggal_uji_petik ELSE dst.tanggal_uji_petik END,
                dst.status = CASE WHEN src.status = 'Done' THEN 'Done' ELSE dst.status END
            WHERE dst.branch = 'KC Pringsewu'
        ");
        $pdo->exec("DELETE FROM uji_petik WHERE branch = 'BO Pringsewu'");

        // Regional Sharepoint evidence links map
        $regionSharepointLinks = [
            'Bandar Lampung' => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAru_JVUAH_SY44J0xwJJirAYNZRODW4xGG6_aVuZV02Q8?e=eoxPe3',
            'Bandung'        => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgDMlTrUq5noRLL6pF5n7XWFAXQb1qYaRfjoiHenMl06kX8?e=9PfaLg',
            'Banjarmasin'    => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAn3QhTEiYlRLk5cCfzsCRmAUkhmSYGxoNmELVmYfDCIJ8?e=OBe1DL',
            'Denpasar'       => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgCVH86XOXLlRqmBmCLe7YBRAXEe0byOs2w2tf2USoA7RBc?e=hKgU6W',
            'Jakarta 1'      => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAVXSWEmAfbQ5mg2nHxWg2ZATDMLT5GguXvHObWaU1FcJY?e=tDVEZG',
            'Jakarta 2'      => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgBxKKO1DH9aT6uDsEcjnb-IAZNDDDPCI811IL1T3q9k6mo?e=XntHQ3',
            'Jakarta 3'      => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgBJeCik4Z7wS7xDr6KS-LSxAcM63-YeUrm5AH8fl3J7WJs?e=SdwVul',
            'Jayapura'       => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAdamfSWVWjSYRPLrn7s4h-AcmMWh2cssSPT1wRNni0aA4?e=v1nbdf',
            'Makassar'       => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgCGwuT0cgq_T4ft0OcCQq79AcEzQ03ilYEfMtmYaDPFOWc?e=6QuFmx',
            'Malang'         => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgDKyb_LCrC8RL6aLet5fhAnAVgP5YmVANRkNo95lLX-DEw?e=1Igwpo',
            'Manado'         => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAAOrycLaTjSbjFB_9SRvP6AdrUptnm15BcKLDeKuIoBwY?e=55BuUO',
            'Medan'          => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgAuv24GYPy3RpGb6TL-1UiQAWtriwDtmLPjkUVVkls_5vg?e=04gQla',
            'Padang'         => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgBbyN_Zu5t5R5ymboW0gOtOAeAR-uzB9a_NOgYgtlZdmR8?e=m80c3z',
            'Palembang'      => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgCyAznK-tOuRql7WphBOOIiAbaa6PYz63jt4CsdpgTNFmk?e=RAGbgh',
            'Pekanbaru'      => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgB-hJNPUDAbTa_K1yXpXfOpASmrhaAtHVRVdN0baGKUVXQ?e=Tj6JES',
            'Semarang'       => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgDa3hABZ1toR7yxMRAO163UAXn4_97WpTspKfjy6Z32Xms?e=UtcLii',
            'Surabaya'       => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgA2xCIqyY_VQJZwUO56CWbmASWJH9-KYf8fbheVvNa2wB0?e=KnWgyV',
            'Yogyakarta'     => '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgB5O797f-zsTIl0esjTOvBxAb0gTAYXhy8v3l1MKs9tUpY?e=I5ZeQv',
        ];

        // Ensure Jakarta 2 drafting branches exist
        $jkt2Link = '**https://bri2-my.sharepoint.com/:f:/g/personal/00136153_hq_bri_co_id/IgBxKKO1DH9aT6uDsEcjnb-IAZNDDDPCI811IL1T3q9k6mo?e=XntHQ3';
        $jkt2Branches = [
            ['no' => '1', 'branch' => 'KC Jakarta Gatot Subroto', 'pic' => 'Imelda Mayestika P.'],
            ['no' => '2', 'branch' => 'SBO Patra Jasa', 'pic' => 'Imelda Mayestika P.'],
            ['no' => '3', 'branch' => 'SBO Telkom Landmark', 'pic' => 'Imelda Mayestika P.'],
        ];
        foreach ($jkt2Branches as $jb) {
            $stCheck = $pdo->prepare("SELECT id FROM uji_petik WHERE branch = :branch LIMIT 1");
            $stCheck->execute([':branch' => $jb['branch']]);
            if (!$stCheck->fetch()) {
                $stIns = $pdo->prepare("INSERT INTO uji_petik (no_urut, branch, pic_rac, rencana_pelaksanaan, tanggal_uji_petik, dokumen, keterangan, status, month, region) VALUES (:no, :branch, :pic, '-', '-', :dok, 'Sedang dalam tahap drafting laporan uji petik', 'Not Done', 'September', 'Region 7 - RO Jakarta 2')");
                $stIns->execute([':no' => $jb['no'], ':branch' => $jb['branch'], ':pic' => $jb['pic'], ':dok' => $jkt2Link]);
            }
        }

        // Format dates, update links, set keterangan 'Telah dilaksanakan' when executed
        $ujiRows = $pdo->query("SELECT id, region, rencana_pelaksanaan, tanggal_uji_petik, dokumen, keterangan FROM uji_petik")->fetchAll(PDO::FETCH_ASSOC);
        $stmtUpdUji = $pdo->prepare("
            UPDATE uji_petik 
            SET rencana_pelaksanaan = :rencana,
                tanggal_uji_petik = :tgl,
                dokumen = :dok,
                keterangan = :ket,
                status = :st,
                month = :m
            WHERE id = :id
        ");

        $bulanIndoMap = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $formatDateDb = function(mixed $val) use ($bulanIndoMap): string {
            if ($val === null) return '-';
            $str = trim((string)$val);
            if ($str === '' || $str === '-') return '-';
            if (is_numeric($str)) {
                $num = (float)$str;
                if ($num >= 1000 && $num <= 100000) {
                    $unix = round(($num - 25569) * 86400);
                    $d = (int)gmdate('j', $unix);
                    $m = (int)gmdate('n', $unix);
                    $y = (int)gmdate('Y', $unix);
                    return "$d " . ($bulanIndoMap[$m] ?? gmdate('F', $unix)) . " $y";
                }
            }
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $str, $m)) {
                return (int)$m[3] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[1];
            }
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $str, $m)) {
                return (int)$m[1] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[3];
            }
            return $str;
        };

        foreach ($ujiRows as $ur) {
            $rencana = $formatDateDb($ur['rencana_pelaksanaan']);
            $tgl = $formatDateDb($ur['tanggal_uji_petik']);
            $reg = $ur['region'] ?? '';
            $link = $ur['dokumen'];
            foreach ($regionSharepointLinks as $rName => $rLink) {
                if (stripos($reg, $rName) !== false) {
                    $link = $rLink;
                    break;
                }
            }

            $ket = trim((string)($ur['keterangan'] ?? ''));
            $hasRencana = ($rencana !== '' && $rencana !== '-');
            $hasTgl = ($tgl !== '' && $tgl !== '-');

            if (($hasRencana && $hasTgl) || $hasTgl) {
                $ket = 'Telah dilaksanakan';
                $status = 'Done';
            } else {
                if ($ket === 'Telah dilaksanakan') $ket = '';
                $status = 'Not Done';
            }

            $month = 'September';
            $searchDates = $tgl . ' ' . $rencana;
            foreach ($bulanIndoMap as $im) {
                if (stripos($searchDates, $im) !== false) {
                    $month = $im;
                    break;
                }
            }

            $stmtUpdUji->execute([
                ':rencana' => $rencana,
                ':tgl'     => $tgl,
                ':dok'     => $link,
                ':ket'     => $ket,
                ':st'      => $status,
                ':m'       => $month,
                ':id'      => $ur['id']
            ]);
        }
    } catch (Throwable $e) {}

    // Auto-sinkronisasi rating maturitas berdasarkan nilai average
    try {
        $pdo->exec("
            UPDATE nilai_maturitas
            SET rating_maturitas = CASE
                WHEN CAST(NULLIF(REPLACE(average, '%', ''), '-') AS DECIMAL(10,2)) >= 8.0 THEN 'Sangat Baik'
                WHEN CAST(NULLIF(REPLACE(average, '%', ''), '-') AS DECIMAL(10,2)) >= 6.5 THEN 'Baik'
                WHEN CAST(NULLIF(REPLACE(average, '%', ''), '-') AS DECIMAL(10,2)) >= 4.0 THEN 'Cukup'
                ELSE 'Kurang'
            END
            WHERE average IS NOT NULL 
              AND average != '' 
              AND average != '-'
              AND CAST(NULLIF(REPLACE(average, '%', ''), '-') AS DECIMAL(10,2)) > 0
        ");
    } catch (Throwable $e) {}

    // Auto-sinkronisasi posisi bulan September untuk modul bad_data dan pengkinian_data
    try {
        $pdo->exec("UPDATE bad_data SET month = 'September' WHERE month IS NULL OR month = '' OR month = 'Agustus'");
        $pdo->exec("UPDATE pengkinian_data SET month = 'September' WHERE month IS NULL OR month = '' OR month = 'Agustus'");
    } catch (Throwable $e) {}
}

