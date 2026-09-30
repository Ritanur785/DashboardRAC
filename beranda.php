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

$monthsStmt = $pdo->query("SELECT DISTINCT SUBSTRING(posisi, 1, 7) AS periode 
    FROM str_alerts 
    WHERE posisi >= '2026-07-01' 
      AND UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KANPUS%'
      AND UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KAMPUS%'
    ORDER BY periode DESC");
$allMonths = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);

$filterBulan = trim((string)($_GET['bulan'] ?? ''));

$where = [
    "posisi >= '2026-07-01'",
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KANPUS%'",
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KAMPUS%'"
];
$params = [];

if ($filterBulan !== '') {
    $where[] = "posisi LIKE :bulan";
    $params['bulan'] = $filterBulan . '%';
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
        'region' => $name,
        'total' => 0,
        'tl_ya' => 0,
        'tl_tidak' => 0,
        'belum_tl' => 0,
    ];
}

$lainnyaRow = null;
foreach ($regionStatsRaw as $r) {
    $regName = (string)$r['region'];
    if (isset($regionMap[$regName])) {
        $regionMap[$regName]['total'] = (int)$r['total'];
        $regionMap[$regName]['tl_ya'] = (int)$r['tl_ya'];
        $regionMap[$regName]['tl_tidak'] = (int)$r['tl_tidak'];
        $regionMap[$regName]['belum_tl'] = (int)$r['belum_tl'];
    } elseif ((int)$r['total'] > 0) {
        $lainnyaRow = [
            'region_num' => 99,
            'region' => $regName,
            'total' => (int)$r['total'],
            'tl_ya' => (int)$r['tl_ya'],
            'tl_tidak' => (int)$r['tl_tidak'],
            'belum_tl' => (int)$r['belum_tl'],
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

$pageTitle = 'Beranda Overview';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">Monitoring & Progress STR</h2>
            <div class="page-subtitle">
                Pemantauan tindak lanjut alert STR tingkat regional berdasarkan rekomendasi UKK
                <?= $filterBulan !== '' ? ' &bull; <strong>Periode: ' . htmlspecialchars(formatMonthIndo($filterBulan)) . '</strong>' : ' &bull; <strong>Semua Periode</strong>' ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
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
        </div>
    </div>

    <!-- Filter Bulan -->
    <form method="GET" action="beranda.php" class="filter-card" style="margin-bottom:24px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div class="filter-item" style="min-width:240px;margin-bottom:0;">
            <label class="filter-label" for="filter-bulan">Filter Periode Posisi (Bulan)</label>
            <select name="bulan" id="filter-bulan" class="form-control" onchange="this.form.submit()">
                <option value="">Semua Bulan</option>
                <?php foreach ($allMonths as $ym): ?>
                    <option value="<?= htmlspecialchars((string)$ym) ?>" <?= $filterBulan === (string)$ym ? 'selected' : '' ?>>
                        <?= htmlspecialchars(formatMonthIndo((string)$ym)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions" style="margin-bottom:0;">
            <button type="submit" class="btn btn-primary">Terapkan</button>
            <?php if ($filterBulan !== ''): ?>
                <a href="beranda.php" class="btn btn-secondary">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Ringkasan Statistik KPI -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
        <div class="stat-card">
            <div class="stat-label">Total Alert STR</div>
            <div class="stat-value"><?= number_format($totalAlerts) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Sudah TL (Ya)</div>
            <div class="stat-value" style="color: #16a34a;"><?= number_format($tlYa) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Sudah TL (Tidak)</div>
            <div class="stat-value" style="color: #0284c7;"><?= number_format($tlTidak) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Belum TL</div>
            <div class="stat-value" style="color: #dc2626;"><?= number_format($belumTl) ?></div>
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
    <div style="margin-top:8px;margin-bottom:28px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <h3 style="font-size:16px;font-weight:700;color:var(--text-primary);margin:0;">
                Progress Tindak Lanjut STR per Region (Kantor Kanwil)
            </h3>
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
                    Export Excel
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
                            <th style="text-align:right;">Total Alert</th>
                            <th style="text-align:right;">Sudah TL (Ya)</th>
                            <th style="text-align:right;">Sudah TL (Tidak)</th>
                            <th style="text-align:right;">Total Sudah TL</th>
                            <th style="text-align:right;">Belum TL</th>
                            <th style="min-width:180px;">% Selesai</th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($regionStats)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">
                                    Tidak ada data untuk periode yang dipilih.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $no = 1;
                            $sumTotal = 0;
                            $sumYa = 0;
                            $sumTidak = 0;
                            $sumBelum = 0;

                            foreach ($regionStats as $r): 
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

                                $todoUrlParams = [];
                                if (isset($r['region_num']) && $r['region_num'] <= 18) {
                                    $todoUrlParams['region'] = $r['region_num'];
                                } else {
                                    $todoUrlParams['q'] = $r['region'];
                                }
                                if ($filterBulan !== '') {
                                    $todoUrlParams['posisi'] = $filterBulan;
                                }
                                $todoUrl = 'todo-harian.php?' . http_build_query($todoUrlParams);
                            ?>
                                <tr>
                                    <td style="text-align:center;color:var(--text-muted);"><?= $no++ ?></td>
                                    <td><strong><?= htmlspecialchars((string)$r['region']) ?></strong></td>
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

    <!-- Alert Terbaru Butuh Perhatian -->
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3 style="font-size:16px;font-weight:700;color:var(--text-primary);">Alert Terbaru</h3>
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
                                    Tidak ada data alert.
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
