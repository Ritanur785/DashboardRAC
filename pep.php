<?php
declare(strict_types=1);

$pageTitle = 'Monitoring PEP';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status_tl'] ?? ''));
$filterPosisi = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($filterStatus !== '') {
    if (strtolower($filterStatus) === 'done') {
        $where[] = "LOWER(TRIM(status_tl)) = 'done'";
    } elseif (strtolower($filterStatus) === 'not done' || strtolower($filterStatus) === 'not_done') {
        $where[] = "(status_tl IS NULL OR LOWER(TRIM(status_tl)) != 'done')";
    }
}

if ($filterPosisi !== '') {
    $where[] = "posisi LIKE :posisi";
    $params['posisi'] = $filterPosisi . '%';
}

if ($filterRegion !== '') {
    if (isset(MASTER_REGIONS[(int)$filterRegion])) {
        $regName = MASTER_REGIONS[(int)$filterRegion];
        $where[] = getRegionSqlCondition($regName, "region");
    } else {
        $where[] = "region LIKE :f_region";
        $params['f_region'] = '%' . $filterRegion . '%';
    }
}

if ($search !== '') {
    $searchCols = [
        'nama_lengkap',
        'cif',
        'nik',
        'jabatan_bri',
        'instansi_bri',
        'unit_kerja',
        'branch',
        'region',
        'jabatan_ppatk',
        'instansi_ppatk',
        'disposisi_rac'
    ];
    $searchParts = [];
    foreach ($searchCols as $i => $col) {
        $pName = 'search_' . $i;
        $searchParts[] = "{$col} LIKE :{$pName}";
        $params[$pName] = '%' . $search . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$countSql = "SELECT COUNT(*) FROM pep_alerts";
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

$sql = "SELECT * FROM pep_alerts";
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
        return 'pep.php?' . http_build_query($query);
    }
}

if (!function_exists('formatMonthIndo')) {
    function formatMonthIndo(string $yearMonth): string {
        $months = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
            '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
            '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $parts = explode('-', $yearMonth);
        if (count($parts) === 2 && isset($months[$parts[1]])) {
            return $months[$parts[1]] . ' ' . $parts[0];
        }
        return $yearMonth;
    }
}

$monthsStmt = $pdo->query("SELECT DISTINCT SUBSTRING(posisi, 1, 7) as ym FROM pep_alerts WHERE posisi REGEXP '^[0-9]{4}-[0-9]{2}' ORDER BY ym DESC");
$availableMonths = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h2 class="page-title">Monitoring PEP (Politically Exposed Persons)</h2>
            <div class="page-subtitle">Daftar tindak lanjut dan penatausahaan nasabah kategori PEP di unit kerja</div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="import-pep.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Import Excel / CSV
            </a>
        </div>
    </div>

    <form method="GET" action="pep.php" class="filter-card">
        <div class="filter-item">
            <label class="filter-label" for="filter-status">Status Tindak Lanjut</label>
            <select name="status_tl" id="filter-status" class="form-control">
                <option value="">Semua Status Tindak Lanjut</option>
                <option value="Done" <?= strtolower($filterStatus) === 'done' ? 'selected' : '' ?>>Done</option>
                <option value="Not Done" <?= in_array(strtolower($filterStatus), ['not done', 'not_done'], true) ? 'selected' : '' ?>>Not Done</option>
            </select>
        </div>

        <div class="filter-item">
            <label class="filter-label" for="filter-month">Posisi (Bulan)</label>
            <select name="month" id="filter-month" class="form-control">
                <option value="">Semua Bulan</option>
                <?php foreach ($availableMonths as $ym): ?>
                    <option value="<?= $ym ?>" <?= $filterPosisi === $ym ? 'selected' : '' ?>>
                        <?= htmlspecialchars(formatMonthIndo($ym)) ?>
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
            <label class="filter-label" for="search-box">Cari Nasabah / CIF / NIK / Jabatan / Disposisi</label>
            <input type="text" name="q" id="search-box" class="form-control" placeholder="Ketik kata kunci pencarian..." value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="pep.php" class="btn btn-secondary">Reset</a>
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
                        <th>Nama Lengkap</th>
                        <th>CIF</th>
                        <th>NIK</th>
                        <th>Open Date</th>
                        <th>Tempat & Tgl Lahir</th>
                        <th>Jabatan BRI</th>
                        <th>Instansi BRI</th>
                        <th>Unit Kerja</th>
                        <th>Branch</th>
                        <th>Region</th>
                        <th>Flag Initial</th>
                        <th>Flag Updated</th>
                        <th>Jabatan PPATK</th>
                        <th>Instansi PPATK</th>
                        <th>Analisa</th>
                        <th>Tanggal TL</th>
                        <th>Disposisi RAC</th>
                        <th>Status TL</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="bulkActionRow" style="display: none;">
                        <td colspan="21" style="padding: 8px 12px; background: #ffffff; border-bottom: 1px solid var(--border-color);">
                            <button type="button" id="btnOpenBulkModal" class="btn btn-primary btn-bulk-fullwidth">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                <span id="bulkBtnText">Update Item Terpilih</span>
                            </button>
                        </td>
                    </tr>
                    <?php if (empty($alerts)): ?>
                        <tr>
                            <td colspan="21" style="text-align: center; padding: 32px; color: var(--text-muted);">
                                Tidak ada data PEP yang sesuai dengan kriteria filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $row): 
                            $displayRegion = formatRegionName($row['region']);
                            $stTl = strtolower(trim((string)($row['status_tl'] ?? '')));
                            $ttl = trim((string)($row['tempat_lahir'] ?? ''));
                            if (!empty($row['tanggal_lahir'])) {
                                $ttl .= ($ttl !== '' ? ', ' : '') . $row['tanggal_lahir'];
                            }
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="alert-check-item" value="<?= $row['id'] ?>" style="cursor: pointer; width: 16px; height: 16px;">
                                </td>
                                <td><?= htmlspecialchars((string)$row['posisi']) ?></td>
                                <td><strong><?= htmlspecialchars((string)$row['nama_lengkap']) ?></strong></td>
                                <td><?= htmlspecialchars((string)$row['cif']) ?></td>
                                <td><?= htmlspecialchars((string)($row['nik'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['open_date'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars($ttl ?: '-') ?></td>
                                <td><?= htmlspecialchars((string)($row['jabatan_bri'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['instansi_bri'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['unit_kerja'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['branch'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)$displayRegion) ?></td>
                                <td><?= htmlspecialchars((string)($row['flag_pep_bri_initial'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['flag_pep_bri_updated'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['jabatan_ppatk'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string)($row['instansi_ppatk'] ?? '-')) ?></td>
                                <td style="max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars((string)($row['analisa'] ?? '')) ?>">
                                    <?= htmlspecialchars((string)($row['analisa'] ?? '-')) ?>
                                </td>
                                <td><?= $row['tgl_tindak_lanjut'] ? date('d M Y', strtotime((string)$row['tgl_tindak_lanjut'])) : '-' ?></td>
                                <td><?= htmlspecialchars((string)($row['disposisi_rac'] ?? '-')) ?></td>
                                <td>
                                    <?php if ($stTl === 'done'): ?>
                                        <span class="badge badge-done">Done</span>
                                    <?php else: ?>
                                        <span class="badge badge-not-started">Not Done</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-secondary btn-sm btn-update-progress" data-id="<?= $row['id'] ?>">Update</button>
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

<!-- Modal Update Progress PEP (Individual) -->
<div class="modal-overlay" id="updateModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title">Update Tindak Lanjut PEP</h3>
            <button type="button" class="modal-close" data-close-modal>&times;</button>
        </div>
        <form id="updateProgressForm">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div style="background:#f8fafc;padding:12px;border-radius:4px;border:1px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Nasabah PEP Terkait</div>
                    <div id="display_nama" style="font-weight:700;font-size:14px;color:#0f172a;margin-top:2px;"></div>
                    <div id="display_cif" style="font-size:12px;color:#475569;margin-top:2px;"></div>
                </div>

                <div class="form-group">
                    <label for="edit_disposisi_rac">Disposisi RAC <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="disposisi_rac" id="edit_disposisi_rac" class="form-control" placeholder="Contoh: 00212345 - Fikri Kipli" required>
                    <div style="font-size: 11px; color: var(--text-muted);">Masukkan PN dan nama PIC RAC.</div>
                </div>

                <div class="form-group">
                    <label for="edit_tgl_tindak_lanjut">Tanggal Tindak Lanjut</label>
                    <input type="date" name="tgl_tindak_lanjut" id="edit_tgl_tindak_lanjut" class="form-control">
                </div>

                <div class="form-group">
                    <label for="edit_status_tl">Status TL</label>
                    <select name="status_tl" id="edit_status_tl" class="form-control" required>
                        <option value="Not Done">Not Done</option>
                        <option value="Done">Done</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close-modal>Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitProgress">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bulk Update PEP (Serentak) -->
<div class="modal-overlay" id="bulkModal">
    <div class="modal-box" style="max-width: 520px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Input Disposisi RAC Serentak</h3>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                    Menerapkan disposisi RAC sekaligus untuk <strong id="bulkSelectedCountText">0</strong> item PEP terpilih
                </div>
            </div>
            <button type="button" class="modal-close" data-close-modal>&times;</button>
        </div>
        <form id="bulkUpdateForm">
            <div class="modal-body">
                <div style="background:#f1f5f9;border:1px solid #e2e8f0;padding:12px 14px;border-radius:6px;font-size:12px;color:#334155;line-height:1.5;">
                    Data disposisi di bawah ini akan disimpan secara serentak ke semua item PEP yang sedang Anda centang.
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

<div id="toast" class="toast"></div>

<script src="assets/js/pep.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
