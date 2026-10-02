<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

if (!function_exists('formatMonthIndo')) {
    function formatMonthIndo(string $yearMonth): string {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April',   '05' => 'Mei',      '06' => 'Juni',
            '07' => 'Juli',    '08' => 'Agustus',  '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $parts = explode('-', $yearMonth);
        if (count($parts) === 2 && isset($months[$parts[1]])) {
            return $months[$parts[1]] . ' ' . $parts[0];
        }
        return $yearMonth;
    }
}

// ==========================================================================
// 1. DATA STR ALERTS
// ==========================================================================
$daftarBulan = [
    1  => 'Januari',
    2  => 'Februari',
    3  => 'Maret',
    4  => 'April',
    5  => 'Mei',
    6  => 'Juni',
    7  => 'Juli',
    8  => 'Agustus',
    9  => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];

$filterBulanRaw = trim((string)($_GET['bulan'] ?? ''));
$filterBulan = '';
$filterBulanNum = null;
$namaBulanTerpilih = '';

if ($filterBulanRaw !== '') {
    if (is_numeric($filterBulanRaw)) {
        $n = (int)$filterBulanRaw;
        if ($n >= 1 && $n <= 12) {
            $filterBulanNum = $n;
            $filterBulan = (string)$n;
            $namaBulanTerpilih = $daftarBulan[$n];
        }
    } else {
        foreach ($daftarBulan as $mNum => $mName) {
            if (strcasecmp($mName, $filterBulanRaw) === 0) {
                $filterBulanNum = $mNum;
                $filterBulan = (string)$mNum;
                $namaBulanTerpilih = $mName;
                break;
            }
        }
        if ($filterBulanNum === null && preg_match('/-(\d{1,2})$/', $filterBulanRaw, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 12) {
                $filterBulanNum = $n;
                $filterBulan = (string)$n;
                $namaBulanTerpilih = $daftarBulan[$n];
            }
        }
    }
}

$strMonthExpr = "MONTH(CASE 
    WHEN posisi REGEXP '^[0-9]+$' AND CAST(posisi AS UNSIGNED) BETWEEN 30000 AND 60000 
    THEN DATE_ADD('1899-12-30', INTERVAL CAST(posisi AS UNSIGNED) DAY)
    WHEN posisi REGEXP '^[0-9]{4}-[0-9]{2}' 
    THEN STR_TO_DATE(SUBSTRING(posisi, 1, 10), '%Y-%m-%d')
    ELSE NULL 
END)";

$where = [
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KANPUS%'",
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KAMPUS%'"
];
$params = [];

if ($filterBulanNum !== null) {
    $where[] = "$strMonthExpr = :bulan_num";
    $params['bulan_num'] = $filterBulanNum;
}

$whereClause = ' WHERE ' . implode(' AND ', $where);

$statSql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Ya' THEN 1 ELSE 0 END) as tl_ya,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Tidak' THEN 1 ELSE 0 END) as tl_tidak,
    SUM(CASE WHEN (rekomendasi_ukk IS NULL OR TRIM(rekomendasi_ukk) = '' OR TRIM(rekomendasi_ukk) = '-') THEN 1 ELSE 0 END) as belum_tl
FROM str_alerts" . $whereClause;

$statStmt = $pdo->prepare($statSql);
$statStmt->execute($params);
$stats = $statStmt->fetch() ?: ['total' => 0, 'tl_ya' => 0, 'tl_tidak' => 0, 'belum_tl' => 0];

$totalAlerts = (int)($stats['total'] ?? 0);
$tlYa = (int)($stats['tl_ya'] ?? 0);
$tlTidak = (int)($stats['tl_tidak'] ?? 0);
$belumTl = (int)($stats['belum_tl'] ?? 0);
$totalSudahTl = $tlYa + $tlTidak;
$persenTl = $totalAlerts > 0 ? round(($totalSudahTl / $totalAlerts) * 100, 1) : 0.0;

$regionCase = getRegionSqlCaseExpression("COALESCE(kantor_kanwil, regional_office, '')");
$regionSql = "SELECT 
    $regionCase AS region,
    COUNT(*) AS total,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Ya' THEN 1 ELSE 0 END) AS tl_ya,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Tidak' THEN 1 ELSE 0 END) AS tl_tidak,
    SUM(CASE WHEN (rekomendasi_ukk IS NULL OR TRIM(rekomendasi_ukk) = '' OR TRIM(rekomendasi_ukk) = '-') THEN 1 ELSE 0 END) AS belum_tl
FROM str_alerts" . $whereClause . "
GROUP BY region";

$regionStmt = $pdo->prepare($regionSql);
$regionStmt->execute($params);
$regionStatsRaw = $regionStmt->fetchAll();

$regionMap = [];
foreach (MASTER_REGIONS as $num => $name) {
    $regionMap[$name] = [
        'region_num' => $num,
        'region'     => $name,
        'total'      => 0,
        'tl_ya'      => 0,
        'tl_tidak'   => 0,
        'belum_tl'   => 0,
    ];
}

$lainnyaRow = null;
foreach ($regionStatsRaw as $r) {
    $regName = (string)$r['region'];
    if (isset($regionMap[$regName])) {
        $regionMap[$regName]['total']    = (int)$r['total'];
        $regionMap[$regName]['tl_ya']    = (int)$r['tl_ya'];
        $regionMap[$regName]['tl_tidak'] = (int)$r['tl_tidak'];
        $regionMap[$regName]['belum_tl'] = (int)$r['belum_tl'];
    } elseif ((int)$r['total'] > 0) {
        $lainnyaRow = [
            'region_num' => 99,
            'region'     => $regName,
            'total'      => (int)$r['total'],
            'tl_ya'      => (int)$r['tl_ya'],
            'tl_tidak'   => (int)$r['tl_tidak'],
            'belum_tl'   => (int)$r['belum_tl'],
        ];
    }
}

$regionStats = array_values($regionMap);
if ($lainnyaRow !== null) {
    $regionStats[] = $lainnyaRow;
}

$latestSql = "SELECT * FROM str_alerts" . $whereClause . " ORDER BY id DESC LIMIT 5";
$latestStmt = $pdo->prepare($latestSql);
$latestStmt->execute($params);
$latestAlerts = $latestStmt->fetchAll();

// ==========================================================================
// 2. DATA MONITORING PEP (POLITICALLY EXPOSED PERSONS)
// Sesuai dengan data dan alur menu pep.php aslinya
// ==========================================================================
$pepMonthsStmt = $pdo->query("SELECT DISTINCT SUBSTRING(posisi, 1, 7) AS periode 
    FROM pep_alerts 
    WHERE posisi REGEXP '^[0-9]{4}-[0-9]{2}' 
    ORDER BY periode DESC");
$allPepMonths = $pepMonthsStmt->fetchAll(PDO::FETCH_COLUMN);

// Jika filter PEP spesifik tidak diisi, gunakan filter bulan yang sama jika cocok
$filterPepBulan = trim((string)($_GET['pep_bulan'] ?? ''));
if ($filterPepBulan === '' && $filterBulan !== '' && in_array($filterBulan, $allPepMonths, true)) {
    $filterPepBulan = $filterBulan;
}

$pepWhere = [];
$pepParams = [];
if ($filterPepBulan !== '') {
    $pepWhere[] = "posisi LIKE :pep_bulan";
    $pepParams['pep_bulan'] = $filterPepBulan . '%';
}
$pepWhereClause = !empty($pepWhere) ? (' WHERE ' . implode(' AND ', $pepWhere)) : '';

// KPI PEP: Disesuaikan dengan status operasional di menu PEP
// Total Target = Total Sudah TL (Done) + Belum TL (Not Done)
// Total Sudah TL (Done) = Sudah TL Flag YA + Sudah TL Flag TIDAK
$pepStatSql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND flag_pep_bri_updated = 'YA' THEN 1 ELSE 0 END) as done_ya,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND (flag_pep_bri_updated = 'TIDAK' OR flag_pep_bri_updated IS NULL OR flag_pep_bri_updated = '') THEN 1 ELSE 0 END) as done_tidak,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' THEN 1 ELSE 0 END) as total_done,
    SUM(CASE WHEN status_tl IS NULL OR LOWER(TRIM(status_tl)) != 'done' THEN 1 ELSE 0 END) as belum_tl
FROM pep_alerts" . $pepWhereClause;

$pepStatStmt = $pdo->prepare($pepStatSql);
$pepStatStmt->execute($pepParams);
$pepStats = $pepStatStmt->fetch() ?: ['total' => 0, 'done_ya' => 0, 'done_tidak' => 0, 'total_done' => 0, 'belum_tl' => 0];

$pepTotal     = (int)($pepStats['total'] ?? 0);
$pepDoneYa    = (int)($pepStats['done_ya'] ?? 0);
$pepDoneTidak = (int)($pepStats['done_tidak'] ?? 0);
$pepSudahTl   = (int)($pepStats['total_done'] ?? 0);
$pepBelumTl   = (int)($pepStats['belum_tl'] ?? 0);
$pepPersenTl  = $pepTotal > 0 ? round(($pepSudahTl / $pepTotal) * 100, 1) : 0.0;

// Progress PEP per Region (Regional Office)
$pepRegionCase = getRegionSqlCaseExpression("region");
$pepRegionSql = "SELECT 
    $pepRegionCase AS region,
    COUNT(*) AS total,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND flag_pep_bri_updated = 'YA' THEN 1 ELSE 0 END) as done_ya,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND (flag_pep_bri_updated = 'TIDAK' OR flag_pep_bri_updated IS NULL OR flag_pep_bri_updated = '') THEN 1 ELSE 0 END) as done_tidak,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' THEN 1 ELSE 0 END) as total_done,
    SUM(CASE WHEN status_tl IS NULL OR LOWER(TRIM(status_tl)) != 'done' THEN 1 ELSE 0 END) as belum_tl
FROM pep_alerts" . $pepWhereClause . "
GROUP BY region";

$pepRegionStmt = $pdo->prepare($pepRegionSql);
$pepRegionStmt->execute($pepParams);
$pepRegionStatsRaw = $pepRegionStmt->fetchAll();

$pepRegionMap = [];
foreach (MASTER_REGIONS as $num => $name) {
    $pepRegionMap[$name] = [
        'region_num' => $num,
        'region'     => $name,
        'total'      => 0,
        'done_ya'    => 0,
        'done_tidak' => 0,
        'total_done' => 0,
        'belum_tl'   => 0,
    ];
}

$pepLainnyaRow = null;
foreach ($pepRegionStatsRaw as $r) {
    $regName = (string)$r['region'];
    if (isset($pepRegionMap[$regName])) {
        $pepRegionMap[$regName]['total']      = (int)$r['total'];
        $pepRegionMap[$regName]['done_ya']    = (int)$r['done_ya'];
        $pepRegionMap[$regName]['done_tidak'] = (int)$r['done_tidak'];
        $pepRegionMap[$regName]['total_done'] = (int)$r['total_done'];
        $pepRegionMap[$regName]['belum_tl']   = (int)$r['belum_tl'];
    } elseif ((int)$r['total'] > 0) {
        $pepLainnyaRow = [
            'region_num' => 99,
            'region'     => $regName,
            'total'      => (int)$r['total'],
            'done_ya'    => (int)$r['done_ya'],
            'done_tidak' => (int)$r['done_tidak'],
            'total_done' => (int)$r['total_done'],
            'belum_tl'   => (int)$r['belum_tl'],
        ];
    }
}

$pepRegionStats = array_values($pepRegionMap);
if ($pepLainnyaRow !== null) {
    $pepRegionStats[] = $pepLainnyaRow;
}

// Data PEP Terbaru: Diurutkan berdasarkan posisi terbaru agar selalu relevan dengan kondisi operasional
$pepLatestSql = "SELECT * FROM pep_alerts" . $pepWhereClause . " ORDER BY posisi DESC, id DESC LIMIT 5";
$pepLatestStmt = $pdo->prepare($pepLatestSql);
$pepLatestStmt->execute($pepParams);
$pepLatestAlerts = $pepLatestStmt->fetchAll();

// ==========================================================================
// 3. AGREGASI KESELURUHAN DATA 5 PILAR RAC
// ==========================================================================
// 1. Bad Data
$badTotal = (int)$pdo->query("SELECT COUNT(*) FROM bad_data")->fetchColumn();
$badDone = (int)$pdo->query("SELECT COUNT(*) FROM bad_data WHERE LOWER(TRIM(status)) = 'done'")->fetchColumn();
$badNotDone = $badTotal - $badDone;
$badAvg = (float)$pdo->query("SELECT COALESCE(AVG(CAST(REPLACE(REPLACE(persentase, '%', ''), ',', '.') AS DECIMAL(10,2))), 0) FROM bad_data WHERE persentase != ''")->fetchColumn();

// 2. Pengkinian Data
$pengTotal = (int)$pdo->query("SELECT COUNT(*) FROM pengkinian_data")->fetchColumn();
$pengDone = (int)$pdo->query("SELECT COUNT(*) FROM pengkinian_data WHERE LOWER(TRIM(status)) = 'done'")->fetchColumn();
$pengNotDone = $pengTotal - $pengDone;
$pengAvg = (float)$pdo->query("SELECT COALESCE(AVG(CAST(REPLACE(REPLACE(persentase, '%', ''), ',', '.') AS DECIMAL(10,2))), 0) FROM pengkinian_data WHERE persentase != ''")->fetchColumn();

// 3. Uji Petik
$ujiTotal = (int)$pdo->query("SELECT COUNT(*) FROM uji_petik")->fetchColumn();
$ujiDone = (int)$pdo->query("SELECT COUNT(*) FROM uji_petik WHERE LOWER(TRIM(status)) = 'done'")->fetchColumn();
$ujiNotDone = $ujiTotal - $ujiDone;

// 4. Nilai Maturitas
$matTotal = (int)$pdo->query("SELECT COUNT(*) FROM nilai_maturitas")->fetchColumn();
$matDone = (int)$pdo->query("SELECT COUNT(*) FROM nilai_maturitas WHERE rating_maturitas IN ('Sangat Baik', 'Baik')")->fetchColumn();
$matNotDone = $matTotal - $matDone;
$matAvg = (float)$pdo->query("SELECT COALESCE(AVG(nilai_maturitas), 0) FROM nilai_maturitas WHERE nilai_maturitas > 0")->fetchColumn();

// 5. Penilaian Resiko
$resTotal = (int)$pdo->query("SELECT COUNT(*) FROM penilaian_resiko")->fetchColumn();
$resLow = (int)$pdo->query("SELECT COUNT(*) FROM penilaian_resiko WHERE LOWER(TRIM(level_risiko)) IN ('small', 'low')")->fetchColumn();
$resHigh = $resTotal - $resLow;
$resAvg = (float)$pdo->query("SELECT COALESCE(AVG(nilai_resiko), 0) FROM penilaian_resiko WHERE nilai_resiko > 0")->fetchColumn();

$pilarGrandTotal = $badTotal + $pengTotal + $ujiTotal + $matTotal + $resTotal;

$pilarCards = [
    [
        'id'             => 'bad_data',
        'title'          => 'Bad Data',
        'url'            => 'bad-data.php',
        'total'          => $badTotal,
        'done'           => $badDone,
        'not_done'       => $badNotDone,
        'done_label'     => 'Done',
        'not_done_label' => 'Not Done',
        'metric_label'   => 'Rata-rata %',
        'metric_val'     => number_format($badAvg, 2) . '%',
        'theme_color'    => '#dc2626',
        'theme_bg'       => '#fef2f2',
        'theme_border'   => '#fecaca',
        'icon'           => 'alert'
    ],
    [
        'id'             => 'pengkinian_data',
        'title'          => 'Pengkinian Data',
        'url'            => 'Pengkinian_data.php',
        'total'          => $pengTotal,
        'done'           => $pengDone,
        'not_done'       => $pengNotDone,
        'done_label'     => 'Done',
        'not_done_label' => 'Not Done',
        'metric_label'   => 'Rata-rata Terkini',
        'metric_val'     => number_format($pengAvg, 2) . '%',
        'theme_color'    => '#2563eb',
        'theme_bg'       => '#eff6ff',
        'theme_border'   => '#bfdbfe',
        'icon'           => 'refresh'
    ],
    [
        'id'             => 'uji_petik',
        'title'          => 'Uji Petik',
        'url'            => 'uji-petik.php',
        'total'          => $ujiTotal,
        'done'           => $ujiDone,
        'not_done'       => $ujiNotDone,
        'done_label'     => 'Done',
        'not_done_label' => 'Not Done',
        'metric_label'   => 'Tingkat Selesai',
        'metric_val'     => ($ujiTotal > 0 ? round(($ujiDone / $ujiTotal) * 100) : 0) . '%',
        'theme_color'    => '#059669',
        'theme_bg'       => '#ecfdf5',
        'theme_border'   => '#a7f3d0',
        'icon'           => 'check'
    ],
    [
        'id'             => 'nilai_maturitas',
        'title'          => 'Nilai Maturitas',
        'url'            => 'nilai-maturitas.php',
        'total'          => $matTotal,
        'done'           => $matDone,
        'not_done'       => $matNotDone,
        'done_label'     => 'Baik / S.Baik',
        'not_done_label' => 'Cukup / Kurang',
        'metric_label'   => 'Rata-rata Skor',
        'metric_val'     => number_format($matAvg, 2) . ' / 5.0',
        'theme_color'    => '#d97706',
        'theme_bg'       => '#fffbeb',
        'theme_border'   => '#fde68a',
        'icon'           => 'star'
    ],
    [
        'id'             => 'penilaian_resiko',
        'title'          => 'Penilaian Resiko',
        'url'            => 'penilaian-resiko.php',
        'total'          => $resTotal,
        'done'           => $resLow,
        'not_done'       => $resHigh,
        'done_label'     => 'Low / Small',
        'not_done_label' => 'Medium / Large',
        'metric_label'   => 'Rata-rata Risiko',
        'metric_val'     => number_format($resAvg, 2),
        'theme_color'    => '#7c3aed',
        'theme_bg'       => '#f5f3ff',
        'theme_border'   => '#ddd6fe',
        'icon'           => 'shield'
    ]
];

$pageTitle = 'Beranda Overview';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
    <div>
        <h2 class="page-title">Executive Overview Dashboard RAC</h2>
        <div class="page-subtitle">
            Pemantauan terpadu data operasional RAC, Monitoring Alert STR, Monitoring Nasabah PEP, dan 5 Pilar Modul RAC
            <?= $namaBulanTerpilih !== '' ? ' &bull; <strong>Periode Posisi STR: ' . htmlspecialchars($namaBulanTerpilih) . '</strong>' : ' &bull; <strong>Semua Bulan Periode STR</strong>' ?>
            <?= $filterPepBulan !== '' ? ' &bull; <strong>Periode Posisi PEP: ' . htmlspecialchars(formatMonthIndo($filterPepBulan)) . '</strong>' : ' &bull; <strong>Semua Periode PEP</strong>' ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php $exportUrl = 'export-str.php' . ($filterBulan !== '' ? '?bulan=' . urlencode($filterBulan) : ''); ?>
        <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Export Excel STR
        </a>
        <a href="todo-harian.php" class="btn btn-primary btn-sm">Buka To-Do Harian</a>
        <a href="pep.php" class="btn btn-secondary btn-sm">Buka Menu PEP</a>
    </div>
</div>

<!-- ======================================================================== -->
<!-- SEKSI 1: MONITORING & PROGRESS STR ALERT REGIONAL -->
<!-- ======================================================================== -->
<div style="margin-bottom:12px;">
    <h3 style="font-size:17px;font-weight:700;color:var(--text-primary);margin:0 0 4px;">
        1. Monitoring & Progress STR Alert Regional
    </h3>
    <p style="font-size:13px;color:var(--text-muted);margin:0;">
        Pemantauan tindak lanjut alert STR tingkat regional berdasarkan rekomendasi UKK
    </p>
</div>

<!-- Filter Bulan STR (Semua Bulan, Januari - Desember) -->
<form method="GET" action="beranda.php" class="filter-card" style="margin-bottom:20px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <?php if ($filterPepBulan !== ''): ?>
        <input type="hidden" name="pep_bulan" value="<?= htmlspecialchars($filterPepBulan) ?>">
    <?php endif; ?>
    <div class="filter-item" style="min-width:240px;margin-bottom:0;">
        <label class="filter-label" for="filter-bulan">Filter Periode Posisi STR (Bulan)</label>
        <select name="bulan" id="filter-bulan" class="form-control" onchange="this.form.submit()">
            <option value="">Semua Bulan</option>
            <?php foreach ($daftarBulan as $mNum => $mNama): ?>
                <option value="<?= $mNum ?>" <?= $filterBulanNum === $mNum ? 'selected' : '' ?>>
                    <?= htmlspecialchars($mNama) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions" style="margin-bottom:0;">
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <?php if ($filterBulan !== ''): ?>
            <a href="beranda.php<?= $filterPepBulan !== '' ? '?pep_bulan=' . urlencode($filterPepBulan) : '' ?>" class="btn btn-secondary">Reset Filter STR</a>
        <?php endif; ?>
    </div>
</form>

<!-- Ringkasan Statistik KPI STR -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-label">Total Alert STR</div>
        <div class="stat-value"><?= number_format($totalAlerts) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sudah TL (Ya)</div>
        <div class="stat-value" style="color: #16a34a;"><?= number_format($tlYa) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Rekomendasi UKK: Ya</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sudah TL (Tidak)</div>
        <div class="stat-value" style="color: #0284c7;"><?= number_format($tlTidak) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Rekomendasi UKK: Tidak</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Belum TL</div>
        <div class="stat-value" style="color: #dc2626;"><?= number_format($belumTl) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Belum Tindak Lanjut</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Progress Selesai</div>
        <div class="stat-value" style="color: #0f172a;">
            <?= $persenTl ?>%
        </div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
            <?= number_format($totalSudahTl) ?> dari <?= number_format($totalAlerts) ?>
        </div>
    </div>
</div>

<!-- Tabel Progress STR per Region (Kantor Kanwil) -->
<div style="margin-bottom:28px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <h4 style="font-size:15px;font-weight:700;color:var(--text-primary);margin:0;">
            Progress Tindak Lanjut STR per Region (Kantor Kanwil)
        </h4>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:12px;color:var(--text-muted);">
                Total: <?= count($regionStats) ?> Region
            </span>
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:#10b981;color:#fff;border:1px solid #059669;font-weight:600;padding:6px 12px;border-radius:4px;text-decoration:none;" title="Export tabel ini ke file Excel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Export Excel STR
            </a>
        </div>
    </div>

    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;">No</th>
                        <th>Region (Kantor Kanwil)</th>
                        <th style="text-align:right;">Total Alert Target</th>
                        <th style="text-align:right;">Sudah TL (Ya)</th>
                        <th style="text-align:right;">Sudah TL (Tidak)</th>
                        <th style="text-align:right;">Total Sudah TL</th>
                        <th style="text-align:right;">Belum TL</th>
                        <th style="min-width:140px;">Progress</th>
                        <th style="width:100px;text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sumTotal = 0;
                    $sumYa = 0;
                    $sumTidak = 0;
                    $sumBelum = 0;
                    $no = 1;
                    ?>
                    <?php if (empty($regionStats)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:24px;color:var(--text-muted);">
                                Tidak ada data region STR.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($regionStats as $r): 
                            $rTotal = (int)$r['total'];
                            $rYa = (int)$r['tl_ya'];
                            $rTidak = (int)$r['tl_tidak'];
                            $rBelum = (int)$r['belum_tl'];
                            $rSudah = $rYa + $rTidak;
                            $rPct = $rTotal > 0 ? round(($rSudah / $rTotal) * 100, 1) : 0.0;

                            $sumTotal += $rTotal;
                            $sumYa += $rYa;
                            $sumTidak += $rTidak;
                            $sumBelum += $rBelum;

                            $todoParams = ['region' => $r['region_num']];
                            if ($filterBulan !== '') {
                                $todoParams['bulan'] = $filterBulan;
                            }
                            $todoUrl = 'todo-harian.php?' . http_build_query($todoParams);
                        ?>
                            <tr>
                                <td style="text-align:center;color:var(--text-muted);">
                                    <?= $r['region_num'] === 99 ? '-' : $no++ ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$r['region']) ?></strong>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?= number_format($rTotal) ?></td>
                                <td style="text-align:right;color:#16a34a;font-weight:600;"><?= number_format($rYa) ?></td>
                                <td style="text-align:right;color:#0284c7;font-weight:600;"><?= number_format($rTidak) ?></td>
                                <td style="text-align:right;font-weight:700;"><?= number_format($rSudah) ?></td>
                                <td style="text-align:right;color:<?= $rBelum > 0 ? '#dc2626' : 'var(--text-muted)' ?>;font-weight:600;">
                                    <?= number_format($rBelum) ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="flex:1;background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden;">
                                            <div style="background:<?= $rPct >= 80 ? '#16a34a' : ($rPct >= 40 ? '#0284c7' : '#f59e0b') ?>;height:100%;width:<?= min(100, $rPct) ?>%;"></div>
                                        </div>
                                        <span style="font-size:11px;font-weight:700;min-width:42px;text-align:right;">
                                            <?= $rPct ?>%
                                        </span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <a href="<?= htmlspecialchars($todoUrl) ?>" class="btn btn-secondary btn-sm" title="Lihat daftar alert di <?= htmlspecialchars((string)$r['region']) ?>">
                                        Buka Data
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($regionStats)): 
                    $sumSudah = $sumYa + $sumTidak;
                    $totalPct = $sumTotal > 0 ? round(($sumSudah / $sumTotal) * 100, 1) : 0.0;
                ?>
                    <tfoot style="background:#f8fafc;font-weight:700;border-top:2px solid var(--border-color);">
                        <tr>
                            <td colspan="2" style="text-align:center;">TOTAL KESELURUHAN</td>
                            <td style="text-align:right;"><?= number_format($sumTotal) ?></td>
                            <td style="text-align:right;color:#16a34a;"><?= number_format($sumYa) ?></td>
                            <td style="text-align:right;color:#0284c7;"><?= number_format($sumTidak) ?></td>
                            <td style="text-align:right;"><?= number_format($sumSudah) ?></td>
                            <td style="text-align:right;color:<?= $sumBelum > 0 ? '#dc2626' : 'inherit' ?>;"><?= number_format($sumBelum) ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;">
                                        <div style="background:#16a34a;height:100%;width:<?= min(100, $totalPct) ?>%;"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:700;min-width:42px;text-align:right;">
                                        <?= $totalPct ?>%
                                    </span>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Alert STR Terbaru Butuh Perhatian -->
<div style="margin-bottom:36px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h4 style="font-size:15px;font-weight:700;color:var(--text-primary);margin:0;">Alert STR Terbaru</h4>
        <a href="todo-harian.php" class="btn btn-secondary btn-sm">Lihat Semua di To-Do Harian &rarr;</a>
    </div>

    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Posisi</th>
                        <th>Nama Nasabah</th>
                        <th>CIF</th>
                        <th>No Rekening</th>
                        <th>Skenario</th>
                        <th>Region (Kanwil)</th>
                        <th>Rekomendasi UKK</th>
                        <th>Status TL</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($latestAlerts)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:24px;color:var(--text-muted);">
                                Tidak ada data alert STR.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($latestAlerts as $item): 
                            $rekUkk = trim((string)($item['rekomendasi_ukk'] ?? ''));
                            $badgeUkk = match($rekUkk) {
                                'Ya' => '<span class="badge badge-done">Sudah TL (Ya)</span>',
                                'Tidak' => '<span class="badge badge-in-progress">Sudah TL (Tidak)</span>',
                                default => '<span class="badge badge-not-started">Belum TL</span>',
                            };
                            $stTl = strtolower(trim((string)($item['status_tl'] ?? '')));
                            $badgeTl = ($stTl === 'done') ? '<span class="badge badge-done">Done</span>' : '<span class="badge badge-not-started">Not Done</span>';
                            $regionName = formatRegionName($item['kantor_kanwil'] ?: ($item['regional_office'] ?: ''));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$item['posisi']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$item['nama_nasabah']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$item['cif']) ?></td>
                                <td><?= htmlspecialchars((string)$item['no_rekening']) ?></td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars((string)$item['skenario']) ?>">
                                    <?= htmlspecialchars((string)$item['skenario']) ?>
                                </td>
                                <td><?= htmlspecialchars((string)$regionName) ?></td>
                                <td><?= $badgeUkk ?></td>
                                <td><?= $badgeTl ?></td>
                                <td>
                                    <a href="todo-harian.php?q=<?= urlencode((string)$item['no_rekening']) ?>" class="btn btn-secondary btn-sm">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- SEKSI 2: MONITORING & PROGRESS PEP (POLITICALLY EXPOSED PERSONS) -->
<!-- Disesuaikan dengan data dan tampilan menu pep.php aslinya -->
<!-- ======================================================================== -->
<div style="margin-bottom:12px;padding-top:20px;border-top:2px dashed #e2e8f0;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
            <h3 style="font-size:17px;font-weight:700;color:var(--text-primary);margin:0 0 4px;">
                2. Monitoring & Progress Nasabah PEP (Politically Exposed Persons)
            </h3>
            <p style="font-size:13px;color:var(--text-muted);margin:0;">
                Pemantauan tindak lanjut dan penatausahaan nasabah kategori PEP di unit kerja tingkat regional
            </p>
        </div>
        <div>
            <a href="pep.php" class="btn btn-secondary btn-sm" style="font-weight:600;">
                Buka Menu Monitoring PEP &rarr;
            </a>
        </div>
    </div>
</div>

<!-- Filter Bulan PEP -->
<form method="GET" action="beranda.php" class="filter-card" style="margin-bottom:20px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <?php if ($filterBulan !== ''): ?>
        <input type="hidden" name="bulan" value="<?= htmlspecialchars($filterBulan) ?>">
    <?php endif; ?>
    <div class="filter-item" style="min-width:240px;margin-bottom:0;">
        <label class="filter-label" for="filter-pep-bulan">Filter Periode Posisi PEP (Bulan)</label>
        <select name="pep_bulan" id="filter-pep-bulan" class="form-control" onchange="this.form.submit()">
            <option value="">Semua Bulan Posisi PEP</option>
            <?php foreach ($allPepMonths as $ym): ?>
                <option value="<?= htmlspecialchars((string)$ym) ?>" <?= $filterPepBulan === (string)$ym ? 'selected' : '' ?>>
                    <?= htmlspecialchars(formatMonthIndo((string)$ym)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions" style="margin-bottom:0;">
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <?php if ($filterPepBulan !== ''): ?>
            <a href="beranda.php<?= $filterBulan !== '' ? '?bulan=' . urlencode($filterBulan) : '' ?>" class="btn btn-secondary">Reset Filter PEP</a>
        <?php endif; ?>
    </div>
</form>

<!-- Ringkasan Statistik KPI PEP (Persis seperti STR: Total Target, Sudah TL (Flag YA), Sudah TL (Flag TIDAK), Belum TL, Progress) -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-label">Total Target PEP</div>
        <div class="stat-value"><?= number_format($pepTotal) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Seluruh Nasabah Target</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sudah TL (Flag YA)</div>
        <div class="stat-value" style="color: #16a34a;"><?= number_format($pepDoneYa) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Terverifikasi Nasabah PEP</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sudah TL (Flag TIDAK)</div>
        <div class="stat-value" style="color: #0284c7;"><?= number_format($pepDoneTidak) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Bukan Nasabah PEP</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Belum TL</div>
        <div class="stat-value" style="color: #dc2626;"><?= number_format($pepBelumTl) ?></div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Status TL: Not Done</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Progress Selesai</div>
        <div class="stat-value" style="color: #0f172a;">
            <?= $pepPersenTl ?>%
        </div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
            <?= number_format($pepSudahTl) ?> dari <?= number_format($pepTotal) ?>
        </div>
    </div>
</div>

<!-- Tabel Progress PEP per Region (Regional Office) -->
<div style="margin-bottom:28px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <h4 style="font-size:15px;font-weight:700;color:var(--text-primary);margin:0;">
            Progress Tindak Lanjut PEP per Region (Regional Office)
        </h4>
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:12px;color:var(--text-muted);">
                Total: <?= count($pepRegionStats) ?> Region
            </span>
            <?php $exportPepUrl = 'export-pep.php' . ($filterPepBulan !== '' ? '?bulan=' . urlencode($filterPepBulan) : ''); ?>
            <a href="<?= htmlspecialchars($exportPepUrl) ?>" class="btn btn-sm" style="display:inline-flex;align-items:center;gap:6px;background:#0284c7;color:#fff;border:1px solid #0369a1;font-weight:600;padding:6px 12px;border-radius:4px;text-decoration:none;" title="Export ringkasan PEP ke file Excel">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Export Excel PEP
            </a>
        </div>
    </div>

    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;">No</th>
                        <th>Region (Regional Office)</th>
                        <th style="text-align:right;">Total Target PEP</th>
                        <th style="text-align:right;">Sudah TL (Flag YA)</th>
                        <th style="text-align:right;">Sudah TL (Flag TIDAK)</th>
                        <th style="text-align:right;">Total Sudah TL</th>
                        <th style="text-align:right;">Belum TL</th>
                        <th style="min-width:140px;">Progress</th>
                        <th style="width:100px;text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sumPepTotal = 0;
                    $sumPepDoneYa = 0;
                    $sumPepDoneTidak = 0;
                    $sumPepSudah = 0;
                    $sumPepBelum = 0;
                    $noPep = 1;
                    ?>
                    <?php if (empty($pepRegionStats)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;padding:24px;color:var(--text-muted);">
                                Tidak ada data region PEP.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pepRegionStats as $r): 
                            $rTotal     = (int)$r['total'];
                            $rDoneYa    = (int)$r['done_ya'];
                            $rDoneTidak = (int)$r['done_tidak'];
                            $rSudah     = (int)$r['total_done'];
                            $rBelum     = (int)$r['belum_tl'];
                            $rPct       = $rTotal > 0 ? round(($rSudah / $rTotal) * 100, 1) : 0.0;

                            $sumPepTotal     += $rTotal;
                            $sumPepDoneYa    += $rDoneYa;
                            $sumPepDoneTidak += $rDoneTidak;
                            $sumPepSudah     += $rSudah;
                            $sumPepBelum     += $rBelum;

                            $pepParams = ['region' => $r['region_num']];
                            if ($filterPepBulan !== '') {
                                $pepParams['month'] = $filterPepBulan;
                            }
                            $pepUrl = 'pep.php?' . http_build_query($pepParams);
                        ?>
                            <tr>
                                <td style="text-align:center;color:var(--text-muted);">
                                    <?= $r['region_num'] === 99 ? '-' : $noPep++ ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$r['region']) ?></strong>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?= number_format($rTotal) ?></td>
                                <td style="text-align:right;color:#16a34a;font-weight:600;"><?= number_format($rDoneYa) ?></td>
                                <td style="text-align:right;color:#0284c7;font-weight:600;"><?= number_format($rDoneTidak) ?></td>
                                <td style="text-align:right;font-weight:700;"><?= number_format($rSudah) ?></td>
                                <td style="text-align:right;color:<?= $rBelum > 0 ? '#dc2626' : 'var(--text-muted)' ?>;font-weight:600;">
                                    <?= number_format($rBelum) ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="flex:1;background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden;">
                                            <div style="background:<?= $rPct >= 90 ? '#16a34a' : ($rPct >= 50 ? '#0284c7' : '#f59e0b') ?>;height:100%;width:<?= min(100, $rPct) ?>%;"></div>
                                        </div>
                                        <span style="font-size:11px;font-weight:700;min-width:42px;text-align:right;">
                                            <?= $rPct ?>%
                                        </span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <a href="<?= htmlspecialchars($pepUrl) ?>" class="btn btn-secondary btn-sm" title="Lihat nasabah PEP di <?= htmlspecialchars((string)$r['region']) ?>">
                                        Buka Data
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($pepRegionStats)): 
                    $totalPepPct = $sumPepTotal > 0 ? round(($sumPepSudah / $sumPepTotal) * 100, 1) : 0.0;
                ?>
                    <tfoot style="background:#f8fafc;font-weight:700;border-top:2px solid var(--border-color);">
                        <tr>
                            <td colspan="2" style="text-align:center;">TOTAL KESELURUHAN</td>
                            <td style="text-align:right;"><?= number_format($sumPepTotal) ?></td>
                            <td style="text-align:right;color:#16a34a;"><?= number_format($sumPepDoneYa) ?></td>
                            <td style="text-align:right;color:#0284c7;"><?= number_format($sumPepDoneTidak) ?></td>
                            <td style="text-align:right;"><?= number_format($sumPepSudah) ?></td>
                            <td style="text-align:right;color:<?= $sumPepBelum > 0 ? '#dc2626' : 'inherit' ?>;"><?= number_format($sumPepBelum) ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;">
                                        <div style="background:#16a34a;height:100%;width:<?= min(100, $totalPepPct) ?>%;"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:700;min-width:42px;text-align:right;">
                                        <?= $totalPepPct ?>%
                                    </span>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Data Nasabah PEP Terbaru (Kolom persis seperti tabel pep.php aslinya) -->
<div style="margin-bottom:36px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h4 style="font-size:15px;font-weight:700;color:var(--text-primary);margin:0;">Data Nasabah PEP Terbaru</h4>
        <a href="pep.php" class="btn btn-secondary btn-sm">Lihat Semua di Monitoring PEP &rarr;</a>
    </div>

    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Posisi</th>
                        <th>Nama Lengkap</th>
                        <th>CIF</th>
                        <th>NIK</th>
                        <th>Unit Kerja</th>
                        <th>Branch</th>
                        <th>Region</th>
                        <th>Flag Initial</th>
                        <th>Flag Updated</th>
                        <th>Disposisi RAC</th>
                        <th>Status TL</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pepLatestAlerts)): ?>
                        <tr>
                            <td colspan="12" style="text-align:center;padding:24px;color:var(--text-muted);">
                                Tidak ada data nasabah PEP.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pepLatestAlerts as $item): 
                            $flagInit = strtoupper(trim((string)($item['flag_pep_bri_initial'] ?? '-')));
                            $flagUpd  = strtoupper(trim((string)($item['flag_pep_bri_updated'] ?? '-')));
                            $badgeInit = ($flagInit === 'YA') ? '<span class="badge badge-not-started">YA</span>' : '<span class="badge" style="background:#f1f5f9;color:#475569;">' . htmlspecialchars($flagInit) . '</span>';
                            $badgeUpd  = ($flagUpd === 'YA') ? '<span class="badge badge-done" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">YA</span>' : '<span class="badge" style="background:#f1f5f9;color:#475569;">' . htmlspecialchars($flagUpd) . '</span>';
                            
                            $stTl = strtolower(trim((string)($item['status_tl'] ?? '')));
                            $badgeTl = ($stTl === 'done') ? '<span class="badge badge-done">Done</span>' : '<span class="badge badge-not-started">Not Done</span>';
                            $dispRac = trim((string)($item['disposisi_rac'] ?? ''));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$item['posisi']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$item['nama_lengkap']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$item['cif']) ?></td>
                                <td><?= htmlspecialchars((string)($item['nik'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($item['unit_kerja'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($item['branch'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)formatRegionName($item['region'])) ?></td>
                                <td><?= $badgeInit ?></td>
                                <td><?= $badgeUpd ?></td>
                                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($dispRac) ?>">
                                    <?= htmlspecialchars($dispRac ?: '-') ?>
                                </td>
                                <td><?= $badgeTl ?></td>
                                <td>
                                    <a href="pep.php?q=<?= urlencode((string)$item['cif']) ?>" class="btn btn-secondary btn-sm" title="Buka dan tindak lanjuti nasabah ini di menu PEP">
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ======================================================================== -->
<!-- SEKSI 3: DIAGRAM & RINGKASAN KESELURUHAN DATA 5 PILAR RAC -->
<!-- (DITARUH DI BAWAH PEP SESUAI INSTRUKSI) -->
<!-- ======================================================================== -->
<style>
.pilar-overview-section {
    background: #ffffff;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: var(--radius-md, 8px);
    padding: 22px;
    margin-bottom: 28px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
.pilar-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #f1f5f9;
}
.pilar-title-box h3 {
    font-size: 18px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 4px;
}
.pilar-title-box p {
    font-size: 13px;
    color: #64748b;
    margin: 0;
}
.pilar-badge-total {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #334155;
    padding: 6px 14px;
    border-radius: 9999px;
    font-size: 13px;
    font-weight: 500;
}
.pilar-badge-total strong {
    color: #0f172a;
    font-weight: 700;
}
.pilar-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.pilar-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.pilar-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    border-color: #cbd5e1;
}
.pilar-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.pilar-card-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pilar-card-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 9999px;
}
.pilar-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 6px;
}
.pilar-card-value {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
    margin-bottom: 12px;
}
.pilar-card-meta {
    font-size: 12px;
    color: #64748b;
    border-top: 1px dashed #e2e8f0;
    padding-top: 10px;
    margin-bottom: 14px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.pilar-meta-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.pilar-detail-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #1e293b;
    font-size: 12px;
    font-weight: 600;
    padding: 8px 12px;
    border-radius: 6px;
    text-decoration: none;
    transition: all 0.15s ease;
    width: 100%;
}
.pilar-detail-btn:hover {
    background: var(--primary, #0284c7);
    border-color: var(--primary, #0284c7);
    color: #ffffff;
}

.pilar-charts-grid {
    display: grid;
    grid-template-columns: 1.55fr 1fr;
    gap: 18px;
}
@media (max-width: 992px) {
    .pilar-charts-grid {
        grid-template-columns: 1fr;
    }
}
.pilar-chart-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 18px;
}
.pilar-chart-header {
    margin-bottom: 14px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.pilar-chart-header h4 {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 3px;
}
.pilar-chart-header p {
    font-size: 12px;
    color: #64748b;
    margin: 0;
}
.pilar-canvas-container {
    position: relative;
    height: 270px;
    width: 100%;
}
</style>

<section class="pilar-overview-section">
    <div class="pilar-header">
        <div class="pilar-title-box">
            <h3>3. Diagram & Ringkasan Keseluruhan Data RAC</h3>
            <p>Visualisasi terpadu untuk 5 modul utama: Bad Data, Pengkinian Data, Uji Petik, Nilai Maturitas, dan Penilaian Resiko.</p>
        </div>
        <div class="pilar-badge-total">
            Total Keseluruhan 5 Pilar: <strong><?= number_format($pilarGrandTotal) ?> Data</strong>
        </div>
    </div>

    <!-- 5 Pilar Cards Grid -->
    <div class="pilar-grid">
        <?php foreach ($pilarCards as $card): ?>
            <div class="pilar-card">
                <div>
                    <div class="pilar-card-top">
                        <div class="pilar-card-icon" style="background:<?= $card['theme_bg'] ?>;color:<?= $card['theme_color'] ?>;">
                            <?php if ($card['icon'] === 'alert'): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'refresh'): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 21h5v-5"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'check'): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                                </svg>
                            <?php elseif ($card['icon'] === 'star'): ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                            <?php else: ?>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                </svg>
                            <?php endif; ?>
                        </div>
                        <span class="pilar-card-badge" style="background:<?= $card['theme_bg'] ?>;color:<?= $card['theme_color'] ?>;border:1px solid <?= $card['theme_border'] ?>;">
                            <?= number_format($card['total']) ?> Data
                        </span>
                    </div>

                    <div class="pilar-card-title"><?= htmlspecialchars($card['title']) ?></div>
                    <div class="pilar-card-value"><?= number_format($card['total']) ?></div>

                    <div class="pilar-card-meta">
                        <div class="pilar-meta-row">
                            <span style="color:#16a34a;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;"></span>
                                <?= htmlspecialchars($card['done_label']) ?>:
                            </span>
                            <strong><?= number_format($card['done']) ?></strong>
                        </div>
                        <div class="pilar-meta-row">
                            <span style="color:<?= $card['not_done'] > 0 ? '#dc2626' : '#64748b' ?>;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                                <span style="width:6px;height:6px;border-radius:50%;background:<?= $card['not_done'] > 0 ? '#dc2626' : '#94a3b8' ?>;"></span>
                                <?= htmlspecialchars($card['not_done_label']) ?>:
                            </span>
                            <strong><?= number_format($card['not_done']) ?></strong>
                        </div>
                        <div class="pilar-meta-row" style="margin-top:2px;">
                            <span><?= htmlspecialchars($card['metric_label']) ?>:</span>
                            <span style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($card['metric_val']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Tombol Detail yang mengarah ke masing-masing halaman menu -->
                <a href="<?= htmlspecialchars($card['url']) ?>" class="pilar-detail-btn" title="Buka Menu <?= htmlspecialchars($card['title']) ?>">
                    <span>Detail <?= htmlspecialchars($card['title']) ?></span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 2 Charts Grid -->
    <div class="pilar-charts-grid">
        <!-- Chart 1: Bar Chart (Komparasi Volume & Status Data) -->
        <div class="pilar-chart-card">
            <div class="pilar-chart-header">
                <div>
                    <h4>Diagram Komparasi Volume & Status Data</h4>
                    <p>Perbandingan jumlah data selesai/baik vs belum selesai/perhatian pada setiap modul</p>
                </div>
                <span style="font-size:11px;color:#64748b;background:#ffffff;padding:3px 8px;border-radius:4px;border:1px solid #e2e8f0;">
                    Klik batang diagram untuk buka menu
                </span>
            </div>
            <div class="pilar-canvas-container">
                <canvas id="chartPilarBar"></canvas>
            </div>
        </div>

        <!-- Chart 2: Doughnut Chart (Proporsi Keseluruhan Data) -->
        <div class="pilar-chart-card">
            <div class="pilar-chart-header">
                <div>
                    <h4>Diagram Proporsi Distribusi Data</h4>
                    <p>Komposisi persentase total data antar 5 pilar menu</p>
                </div>
            </div>
            <div class="pilar-canvas-container">
                <canvas id="chartPilarDonut"></canvas>
            </div>
        </div>
    </div>
</section>

<script src="assets/js/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const pilarLabels  = <?= json_encode(array_column($pilarCards, 'title')) ?>;
    const pilarTotals  = <?= json_encode(array_column($pilarCards, 'total')) ?>;
    const pilarDone    = <?= json_encode(array_column($pilarCards, 'done')) ?>;
    const pilarNotDone = <?= json_encode(array_column($pilarCards, 'not_done')) ?>;
    const pilarUrls    = <?= json_encode(array_column($pilarCards, 'url')) ?>;

    // 1. Diagram Batang Komparatif Status Data
    const elBar = document.getElementById('chartPilarBar');
    if (elBar) {
        new Chart(elBar, {
            type: 'bar',
            data: {
                labels: pilarLabels,
                datasets: [
                    {
                        label: 'Selesai / Terpenuhi / Low Risk',
                        data: pilarDone,
                        backgroundColor: '#10b981',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.6
                    },
                    {
                        label: 'Belum Selesai / Perlu Perhatian',
                        data: pilarNotDone,
                        backgroundColor: '#ef4444',
                        borderRadius: 4,
                        barPercentage: 0.7,
                        categoryPercentage: 0.6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function() {
                                return '👉 Klik batang untuk membuka menu';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            font: { size: 11 }
                        },
                        grid: {
                            color: '#f1f5f9'
                        }
                    },
                    x: {
                        ticks: {
                            font: { size: 11, weight: '600' }
                        },
                        grid: {
                            display: false
                        }
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        const idx = elements[0].index;
                        if (pilarUrls[idx]) {
                            window.location.href = pilarUrls[idx];
                        }
                    }
                },
                onHover: function(evt, elements) {
                    evt.native.target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
                }
            }
        });
    }

    // 2. Diagram Donat Proporsi Distribusi Data
    const elDonut = document.getElementById('chartPilarDonut');
    if (elDonut) {
        new Chart(elDonut, {
            type: 'doughnut',
            data: {
                labels: pilarLabels,
                datasets: [{
                    data: pilarTotals,
                    backgroundColor: [
                        '#ef4444', // Bad Data
                        '#3b82f6', // Pengkinian
                        '#10b981', // Uji Petik
                        '#f59e0b', // Nilai Maturitas
                        '#8b5cf6'  // Penilaian Resiko
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 10,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = context.raw || 0;
                                const dataset = context.chart.data.datasets[0];
                                const sum = dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                const pct = sum > 0 ? ((val / sum) * 100).toFixed(1) : 0;
                                return ' ' + context.label + ': ' + val + ' data (' + pct + '%)';
                            },
                            afterBody: function() {
                                return '👉 Klik bagian diagram untuk buka menu';
                            }
                        }
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        const idx = elements[0].index;
                        if (pilarUrls[idx]) {
                            window.location.href = pilarUrls[idx];
                        }
                    }
                },
                onHover: function(evt, elements) {
                    evt.native.target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
