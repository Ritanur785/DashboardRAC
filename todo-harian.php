<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterPosisi = trim((string)($_GET['posisi'] ?? ($_GET['bulan'] ?? '')));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$daftarBulan = [
    1  => 'Januari', 2  => 'Februari', 3  => 'Maret', 4  => 'April',
    5  => 'Mei',     6  => 'Juni',     7  => 'Juli',  8  => 'Agustus',
    9  => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$where = [
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KANPUS%'",
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KAMPUS%'"
];
$params = [];

if ($filterStatus !== '') {
    if ($filterStatus === 'sudah_ya' || $filterStatus === 'Ya') {
        $where[] = "TRIM(COALESCE(rekomendasi_ukk, '')) = 'Ya'";
    } elseif ($filterStatus === 'sudah_tidak' || $filterStatus === 'Tidak') {
        $where[] = "TRIM(COALESCE(rekomendasi_ukk, '')) = 'Tidak'";
    } elseif ($filterStatus === 'belum_tl' || strtolower($filterStatus) === 'belum tl') {
        $where[] = "(rekomendasi_ukk IS NULL OR TRIM(rekomendasi_ukk) = '' OR TRIM(rekomendasi_ukk) = '-')";
    }
}

if ($filterPosisi !== '') {
    $filterMonthNum = null;
    if (is_numeric($filterPosisi)) {
        $n = (int)$filterPosisi;
        if ($n >= 1 && $n <= 12) {
            $filterMonthNum = $n;
        }
    }
    if ($filterMonthNum !== null) {
        $strMonthExpr = "MONTH(CASE 
            WHEN posisi REGEXP '^[0-9]+$' AND CAST(posisi AS UNSIGNED) BETWEEN 30000 AND 60000 
            THEN DATE_ADD('1899-12-30', INTERVAL CAST(posisi AS UNSIGNED) DAY)
            WHEN posisi REGEXP '^[0-9]{4}-[0-9]{2}' 
            THEN STR_TO_DATE(SUBSTRING(posisi, 1, 10), '%Y-%m-%d')
            ELSE NULL 
        END)";
        $where[] = "$strMonthExpr = :bulan_num";
        $params['bulan_num'] = $filterMonthNum;
    } else {
        $where[] = "posisi LIKE :posisi";
        $params['posisi'] = $filterPosisi . '%';
    }
}

if ($filterRegion !== '') {
    $regCond = getRegionSqlCondition($filterRegion);
    if ($regCond !== null) {
        $where[] = $regCond;
    }
}

if ($search !== '') {
    $searchCols = [
        'nama_nasabah',
        'cif',
        'no_rekening',
        'skenario',
        'disposisi',
        'disposisi_rac',
        'rekomendasi_ukk',
        'kantor_kanwil',
        'regional_office',
        'unit_kerja',
        'kantor_cabang'
    ];
    $searchParts = [];
    foreach ($searchCols as $i => $col) {
        $pName = 'search_' . $i;
        $searchParts[] = "{$col} LIKE :{$pName}";
        $params[$pName] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$countSql = "SELECT COUNT(*) FROM str_alerts";
if (!empty($where)) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM str_alerts";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue(':' . $key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$alerts = $stmt->fetchAll();

if (!function_exists('buildPageUrl')) {
    function buildPageUrl(int $p): string {
        $query = $_GET;
        $query['page'] = $p;
        return 'todo-harian.php?' . http_build_query($query);
    }
}

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

$pageTitle = 'Tindak Lanjut Alert STR';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h2 class="page-title">Tindak Lanjut Alert STR</h2>
            <div class="page-subtitle">Input progress tugas secara real-time</div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="import.php" class="btn btn-secondary btn-sm" style="display:inline-flex;gap:6px;">
                Import Excel / CSV
            </a>
            <?php if ($totalRecords > 0): ?>
                <button type="button" class="btn btn-danger btn-sm" id="btnResetAlerts" onclick="openResetModal()">
                    Reset Seluruh Data
                </button>
            <?php endif; ?>
        </div>
    </div>

    <form method="GET" action="todo-harian.php" class="filter-card">
        <div class="filter-item">
            <label class="filter-label" for="filter-status">Status Tindak Lanjut</label>
            <select name="status" id="filter-status" class="form-control">
                <option value="">Semua Status Tindak Lanjut</option>
                <option value="sudah_ya" <?= in_array($filterStatus, ['sudah_ya', 'Ya'], true) ? 'selected' : '' ?>>Sudah TL (Ya)</option>
                <option value="sudah_tidak" <?= in_array($filterStatus, ['sudah_tidak', 'Tidak'], true) ? 'selected' : '' ?>>Sudah TL (Tidak)</option>
                <option value="belum_tl" <?= in_array($filterStatus, ['belum_tl', 'Belum TL'], true) ? 'selected' : '' ?>>Belum TL</option>
            </select>
        </div>

        <div class="filter-item">
            <label class="filter-label" for="filter-posisi">Posisi (Bulan)</label>
            <select name="posisi" id="filter-posisi" class="form-control">
                <option value="">Semua Bulan</option>
                <?php foreach ($daftarBulan as $mNum => $mNama): ?>
                    <option value="<?= $mNum ?>" <?= (string)$filterPosisi === (string)$mNum ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mNama) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item" style="min-width: 220px;">
            <label class="filter-label" for="filter-region">Filter Region (Kanwil)</label>
            <select name="region" id="filter-region" class="form-control">
                <option value="">Semua Region</option>
                <?php foreach (MASTER_REGIONS as $rNum => $rName): ?>
                    <option value="<?= $rNum ?>" <?= ($filterRegion === (string)$rNum || $filterRegion === $rName) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item" style="flex: 1; min-width: 220px;">
            <label class="filter-label" for="search-box">Cari Nasabah / CIF / Rekening / Disposisi</label>
            <input type="text" name="q" id="search-box" class="form-control" placeholder="Ketik kata kunci pencarian..." value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="todo-harian.php" class="btn btn-secondary">Reset Filter</a>
        </div>
    </form>

    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 38px; text-align: center;">
                            <input type="checkbox" id="checkAllAlerts" title="Pilih Semua di Halaman Ini" style="cursor: pointer; width: 16px; height: 16px;">
                        </th>
                        <th>Posisi</th>
                        <th>Nama</th>
                        <th>CIF</th>
                        <th>No Rek / Kartu Kredit</th>
                        <th>Skenario</th>
                        <th>Kategori</th>
                        <th>Unit Kerja</th>
                        <th>Kantor Cabang</th>
                        <th>Kantor Kanwil</th>
                        <th>Rekomendasi UKK</th>
                        <th>Tanggal TL</th>
                        <th>Disposisi RAC</th>
                        <th>Status TL</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="bulkActionRow" style="display: none;">
                        <td colspan="15" style="padding: 10px 14px; background: #fef2f2; border-bottom: 1px solid #fecaca;">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <span id="bulkSelectedCountText" style="font-size:13px;color:#991b1b;font-weight:700;">0 data dipilih</span>
                                <div style="display:flex;gap:8px;">
                                    <button type="button" id="btnOpenBulkModal" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                        <span id="bulkBtnText">Update Item Terpilih</span>
                                    </button>
                                    <button type="button" id="btnDeleteBulkAlerts" class="btn btn-danger btn-sm" onclick="deleteSelectedAlerts()" style="display:inline-flex;align-items:center;gap:6px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                                        </svg>
                                        Hapus Data Terpilih
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php if (empty($alerts)): ?>
                        <tr>
                            <td colspan="15" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                Tidak ada data alert yang sesuai dengan kriteria filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $row): 
                            $st = strtolower(trim((string)$row['status']));
                            $statusClass = match(true) {
                                in_array($st, ['done', 'selesai'], true) => 'badge-done',
                                in_array($st, ['in progress', 'proses', 'sedang diproses'], true) => 'badge-in-progress',
                                default => 'badge-not-started',
                            };
                            $displayBranch = $row['unit_kerja'] ?: $row['branch'];
                            $displayCabang = $row['kantor_cabang'] ?: $row['main_branch'];
                            $displayKanwil = formatRegionName($row['kantor_kanwil'] ?: $row['regional_office']);
                            $displayDisposisi = $row['disposisi'] ?: $row['disposisi_rac'];
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="alert-check-item" value="<?= $row['id'] ?>" style="cursor: pointer; width: 16px; height: 16px;">
                                </td>
                                <td><?= htmlspecialchars((string)$row['posisi']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$row['nama_nasabah']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$row['cif']) ?></td>
                                <td><?= htmlspecialchars((string)$row['no_rekening']) ?></td>
                                <td style="max-width: 220px; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars((string)$row['skenario']) ?>">
                                    <?= htmlspecialchars((string)$row['skenario']) ?>
                                </td>
                                <td><?= htmlspecialchars((string)$row['kategori']) ?></td>
                                <td><?= htmlspecialchars((string)$displayBranch) ?></td>
                                <td><?= htmlspecialchars((string)$displayCabang) ?></td>
                                <td><?= htmlspecialchars((string)$displayKanwil) ?></td>
                                <td><?= htmlspecialchars((string)($row['rekomendasi_ukk'] ?? '') ?: '-') ?></td>
                                <td><?= $row['tgl_tindak_lanjut'] ? date('d M Y', strtotime((string)$row['tgl_tindak_lanjut'])) : '-' ?></td>
                                <td><?= htmlspecialchars((string)($row['disposisi_rac'] ?? '-')) ?></td>
                                <td>
                                    <?php 
                                    $stTl = strtolower(trim((string)($row['status_tl'] ?? '')));
                                    if ($stTl === 'done') {
                                        echo '<span class="badge badge-done">Done</span>';
                                    } elseif ($stTl === 'not done') {
                                        echo '<span class="badge badge-not-started">Not Done</span>';
                                    } else {
                                        echo htmlspecialchars((string)($row['status_tl'] ?? '-'));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div style="display:inline-flex;gap:4px;">
                                        <button type="button" class="btn btn-secondary btn-sm btn-update-progress" data-id="<?= $row['id'] ?>">Update</button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleAlert(<?= (int)$row['id'] ?>)" title="Hapus alert ini">Hapus</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-pagination">
            <div class="pagination-info">
                Menampilkan <strong><?= $totalRecords > 0 ? $offset + 1 : 0 ?> - <?= min($offset + count($alerts), $totalRecords) ?></strong> dari <strong><?= $totalRecords ?></strong> data
                (Halaman <?= $page ?> dari <?= $totalPages ?>)
            </div>
            <ul class="pagination-controls">
                <li>
                    <a href="<?= $page > 1 ? buildPageUrl($page - 1) : '#' ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        &lsaquo; Sebelumnya
                    </a>
                </li>
                <?php
                $visibleRange = 5;
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $startPage + $visibleRange - 1);
                if ($endPage - $startPage + 1 < $visibleRange) {
                    $startPage = max(1, $endPage - $visibleRange + 1);
                }

                if ($startPage > 1) {
                    echo '<li><a href="' . buildPageUrl(1) . '" class="page-btn">1</a></li>';
                    if ($startPage > 2) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                }
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $activeClass = $i === $page ? 'active' : '';
                    echo '<li><a href="' . buildPageUrl($i) . '" class="page-btn ' . $activeClass . '">' . $i . '</a></li>';
                }
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                    echo '<li><a href="' . buildPageUrl($totalPages) . '" class="page-btn">' . $totalPages . '</a></li>';
                }
                ?>
                <li>
                    <a href="<?= $page < $totalPages ? buildPageUrl($page + 1) : '#' ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        Selanjutnya &rsaquo;
                    </a>
                </li>
            </ul>
        </div>
    </div>
</main>


<div class="modal-overlay" id="updateModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Update Tindak Lanjut STR</h3>
            <button type="button" class="modal-close" data-close-modal>&times;</button>
        </div>
        <form id="updateProgressForm">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div style="background:#f8fafc;padding:12px;border-radius:4px;border:1px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Nasabah Terkait</div>
                    <div id="display_nama" style="font-weight:700;font-size:14px;color:#0f172a;margin-top:2px;"></div>
                    <div id="display_skenario" style="font-size:12px;color:#475569;margin-top:2px;"></div>
                </div>

                <div class="form-group">
                    <label for="edit_tgl_tindak_lanjut">Tanggal TL</label>
                    <input type="date" name="tgl_tindak_lanjut" id="edit_tgl_tindak_lanjut" class="form-control">
                </div>

                <div class="form-group">
                    <label for="edit_disposisi_rac">Disposisi RAC</label>
                    <input type="text" name="disposisi_rac" id="edit_disposisi_rac" class="form-control" placeholder="Contoh: 00212345 - Fikri Kipli">
                </div>

                <div class="form-group">
                    <label for="edit_status_tl">Status TL</label>
                    <select name="status_tl" id="edit_status_tl" class="form-control">
                        <option value="Not Done">Not Done</option>
                        <option value="Done">Done</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="bulkModal">
    <div class="modal-box" style="max-width: 520px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Input Disposisi RAC Serentak</h3>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                    Menerapkan disposisi RAC sekaligus untuk <strong id="bulkSelectedCountText">0</strong> alert terpilih
                </div>
            </div>
            <button type="button" class="modal-close" data-close-modal>&times;</button>
        </div>
        <form id="bulkUpdateForm">
            <div class="modal-body">
                <div style="background:#f1f5f9;border:1px solid #e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;color:#334155;line-height:1.5;">
                    Data disposisi di bawah ini akan disimpan secara serentak ke semua alert yang sedang Anda centang.
                </div>

                <div class="form-group">
                    <label for="bulk_disposisi_rac">Disposisi RAC <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="disposisi_rac" id="bulk_disposisi_rac" class="form-control" placeholder="Contoh: 00212345 - Fikri Kipli" required>
                    <div style="font-size: 11px; color: var(--text-muted);">Masukkan PN dan nama PIC RAC.</div>
                </div>

                <div class="form-group">
                    <label for="bulk_tgl_tindak_lanjut">Tanggal Tindak Lanjut (Opsional)</label>
                    <input type="date" name="tgl_tindak_lanjut" id="bulk_tgl_tindak_lanjut" class="form-control" value="">
                </div>

                <div class="form-group">
                    <label for="bulk_status_tl">Status TL (Opsional)</label>
                    <select name="status_tl" id="bulk_status_tl" class="form-control">
                        <option value="">Tetap</option>
                        <option value="Done">Done</option>
                        <option value="Not Done">Not Done</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitBulk">
                    Terapkan Disposisi Serentak
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Tambah Alert STR Baru</h3>
            <button type="button" class="modal-close" data-close-modal>&times;</button>
        </div>
        <form id="createAlertForm">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="add_posisi">Posisi / Tanggal</label>
                        <input type="text" name="posisi" id="add_posisi" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label for="add_kategori">Kategori</label>
                        <input type="text" name="kategori" id="add_kategori" class="form-control" value="Narkotika/Judi Online">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="add_nama">Nama Nasabah / Entitas</label>
                        <input type="text" name="nama_nasabah" id="add_nama" class="form-control" required placeholder="Nama lengkap nasabah">
                    </div>
                    <div class="form-group">
                        <label for="add_cif">Nomor CIF</label>
                        <input type="text" name="cif" id="add_cif" class="form-control" required value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="add_rekening">Nomor Rekening / Kartu Kredit</label>
                        <input type="text" name="no_rekening" id="add_rekening" class="form-control" required placeholder="10301005146301">
                    </div>
                    <div class="form-group">
                        <label for="add_uker">Unit Kerja</label>
                        <input type="text" name="unit_kerja" id="add_uker" class="form-control" value="Kas Kampus">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="add_kc">Kantor Cabang</label>
                        <input type="text" name="kantor_cabang" id="add_kc" class="form-control" value="Kas Kampus">
                    </div>
                    <div class="form-group">
                        <label for="add_kanwil">Kantor Kanwil</label>
                        <input type="text" name="kantor_kanwil" id="add_kanwil" class="form-control" value="KAS KAMPUS">
                    </div>
                </div>

                <div class="form-group">
                    <label for="add_skenario">Skenario Alert STR</label>
                    <input type="text" name="skenario" id="add_skenario" class="form-control" required value="Money Mules">
                </div>

                <div class="form-group">
                    <label for="add_disposisi">Disposisi PIC</label>
                    <input type="text" name="disposisi" id="add_disposisi" class="form-control" placeholder="00212345 - Fikri Kipli">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary">Tambah Alert</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RESET KONFIRMASI -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#ffffff;border-radius:10px;max-width:440px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 10px;font-size:18px;color:#0f172a;font-weight:700;">Konfirmasi Reset Data</h3>
        <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.5;">
            Apakah Anda yakin ingin mereset seluruh data Alert STR? Seluruh baris yang tersimpan akan dihapus secara permanen.
        </p>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
            <button type="button" class="btn btn-danger" id="btnConfirmReset" onclick="executeResetAlerts()">Ya, Hapus Semua</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
