<?php
declare(strict_types=1);

$pageTitle = 'Monitoring Bad Data';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterMonth  = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));

$availableMonths = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$where = [];
$params = [];

if ($filterMonth !== '') {
    $where[] = "month LIKE :filter_month";
    $params['filter_month'] = '%' . $filterMonth . '%';
}

if ($filterRegion !== '') {
    $cond = getRegionSqlCondition($filterRegion, "region");
    if ($cond) {
        $where[] = $cond;
    } else {
        $where[] = "region LIKE :filter_region";
        $params['filter_region'] = '%' . $filterRegion . '%';
    }
}

if ($search !== '') {
    $where[] = "(branch LIKE :search_branch OR pic_rac LIKE :search_pic OR keterangan LIKE :search_ket)";
    $params['search_branch'] = '%' . $search . '%';
    $params['search_pic'] = '%' . $search . '%';
    $params['search_ket'] = '%' . $search . '%';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bad_data {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$dataSql = "SELECT * FROM bad_data {$whereSql} ORDER BY CASE WHEN pic_rac IS NULL OR TRIM(pic_rac) = '' OR pic_rac = '-' THEN 1 ELSE 0 END, pic_rac ASC, id ASC LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $val) {
    $dataStmt->bindValue(':' . $key, $val);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$pageData = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/pic_rac_helper.php';
$pageData = attachPicRacGroupAverages($pageData, 'persentase');

if (!function_exists('buildBadDataPageUrl')) {
    function buildBadDataPageUrl(int $pageNumber): string {
        $query = $_GET;
        $query['page'] = $pageNumber;
        return 'bad-data.php?' . http_build_query($query);
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;">
        <div>
            <h2 class="page-title">Monitoring Bad Data</h2>
            <div class="page-subtitle">Monitoring evaluasi dan perbaikan data RAC</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="import-bad-data.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Import Excel / CSV
            </a>
            <?php if ($totalRecords > 0): ?>
                <button type="button" class="btn btn-danger btn-sm" id="btnResetBadData" onclick="openResetModal()">
                    Reset Seluruh Data
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FILTER -->
    <form method="GET" action="bad-data.php" class="filter-card">
        <div class="filter-item">
            <label for="filterMonth" class="filter-label">Posisi Bulan</label>
            <select id="filterMonth" name="month" class="form-control">
                <option value="">Semua Bulan</option>
                <?php foreach ($availableMonths as $month): ?>
                    <option value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" <?= $filterMonth === $month ? 'selected' : '' ?>>
                        <?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item" style="min-width:220px;">
            <label for="filterRegion" class="filter-label">Filter Regional</label>
            <select id="filterRegion" name="region" class="form-control">
                <option value="">Semua Regional</option>
                <?php foreach (MASTER_REGIONS as $regionNumber => $regionName): ?>
                    <option value="<?= (int)$regionNumber ?>" <?= $filterRegion === (string)$regionNumber ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$regionName, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-item" style="flex:1;min-width:220px;">
            <label for="filterSearch" class="filter-label">Pencarian</label>
            <input
                type="text"
                id="filterSearch"
                name="q"
                class="form-control"
                placeholder="Cari Unit Kerja / PIC RAC..."
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="bad-data.php" class="btn btn-secondary">Reset Filter</a>
        </div>
    </form>

    <!-- BULK ACTIONS -->
    <div id="bulkActions" style="display:none;margin-bottom:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;align-items:center;justify-content:space-between;">
        <span id="selectedCountText" style="font-size:13px;color:#991b1b;font-weight:600;">0 data dipilih</span>
        <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelectedBadData()">
            Hapus Data Terpilih
        </button>
    </div>

    <!-- TABLE -->
    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center;">
                            <input
                                type="checkbox"
                                id="checkAllBadData"
                                title="Pilih Semua di Halaman Ini"
                                style="cursor:pointer;width:16px;height:16px;"
                                <?= empty($pageData) ? 'disabled' : '' ?>
                            >
                        </th>
                        <th style="width:50px;text-align:center;">NO</th>
                        <th>UNIT KERJA</th>
                        <th>PIC RAC</th>
                        <th>% BAD DATA</th>
                        <th>AVERAGE</th>
                        <th style="width:70px;text-align:center;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pageData)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
                                Belum ada data bad data yang sesuai filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pageData as $index => $row): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        class="bad-data-check"
                                        value="<?= htmlspecialchars((string)($row['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        style="cursor:pointer;width:16px;height:16px;"
                                        onchange="updateBulkActionState()"
                                    >
                                </td>
                                <td style="text-align:center;">
                                    <?= (int)($offset + $index + 1) ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['branch'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['pic_rac'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['persentase'] ?? '0%'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <?php if (!empty($row['is_first_in_pic_group'])): ?>
                                    <td <?= ($row['pic_group_rowspan'] > 1) ? 'rowspan="' . (int)$row['pic_group_rowspan'] . '"' : '' ?> style="text-align:center;font-weight:700;vertical-align:middle;<?= ($row['pic_group_rowspan'] > 1) ? 'background:#f8fafc;' : '' ?>">
                                        <?= htmlspecialchars((string)($row['pic_group_average'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                <?php endif; ?>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleBadData(<?= (int)$row['id'] ?>)" title="Hapus baris ini">Hapus</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalRecords > 0): ?>
        <div class="table-pagination">
            <div class="pagination-info">
                Menampilkan
                <strong><?= $offset + 1 ?> - <?= min($offset + count($pageData), $totalRecords) ?></strong>
                dari
                <strong><?= $totalRecords ?></strong>
                data
            </div>

            <ul class="pagination-controls">
                <li>
                    <a href="<?= $page > 1 ? htmlspecialchars(buildBadDataPageUrl($page - 1), ENT_QUOTES, 'UTF-8') : '#' ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
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
                    echo '<li><a href="' . htmlspecialchars(buildBadDataPageUrl(1), ENT_QUOTES, 'UTF-8') . '" class="page-btn">1</a></li>';
                    if ($startPage > 2) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                }
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $activeClass = $i === $page ? 'active' : '';
                    echo '<li><a href="' . htmlspecialchars(buildBadDataPageUrl($i), ENT_QUOTES, 'UTF-8') . '" class="page-btn ' . $activeClass . '">' . $i . '</a></li>';
                }
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                    echo '<li><a href="' . htmlspecialchars(buildBadDataPageUrl($totalPages), ENT_QUOTES, 'UTF-8') . '" class="page-btn">' . $totalPages . '</a></li>';
                }
                ?>
                <li>
                    <a href="<?= $page < $totalPages ? htmlspecialchars(buildBadDataPageUrl($page + 1), ENT_QUOTES, 'UTF-8') : '#' ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        Selanjutnya &rsaquo;
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- MODAL RESET KONFIRMASI -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#ffffff;border-radius:10px;max-width:440px;width:100%;padding:24px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin:0 0 10px;font-size:18px;color:#0f172a;font-weight:700;">Konfirmasi Reset Data</h3>
        <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.5;">
            Apakah Anda yakin ingin mereset seluruh data Bad Data? Seluruh baris yang tersimpan akan dihapus secara permanen.
        </p>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
            <button type="button" class="btn btn-danger" id="btnConfirmReset" onclick="executeResetBadData()">Ya, Hapus Semua</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAllBadData');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('.bad-data-check');
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = checkAll.checked;
            });
            updateBulkActionState();
        });
    }
});

function updateBulkActionState() {
    const checked = document.querySelectorAll('.bad-data-check:checked');
    const bulkDiv = document.getElementById('bulkActions');
    const countText = document.getElementById('selectedCountText');
    if (!bulkDiv || !countText) return;

    if (checked.length > 0) {
        bulkDiv.style.display = 'flex';
        countText.textContent = checked.length + ' data dipilih';
    } else {
        bulkDiv.style.display = 'none';
    }
}

function openResetModal() {
    const modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'flex';
}

function closeResetModal() {
    const modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'none';
}

async function executeResetBadData() {
    const btn = document.getElementById('btnConfirmReset');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Menghapus...';
    }

    try {
        const response = await fetch('api/bad-data.php?action=clear', { method: 'POST' });
        const result = await response.json();
        if (!result.success) {
            alert(result.message || 'Gagal mereset data.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat mereset data.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Ya, Hapus Semua';
        }
    }
}

async function deleteSelectedBadData() {
    const checked = Array.from(document.querySelectorAll('.bad-data-check:checked')).map(cb => cb.value);
    if (!checked.length) {
        alert('Pilih setidaknya satu data yang ingin dihapus.');
        return;
    }

    if (!confirm('Apakah Anda yakin ingin menghapus ' + checked.length + ' data terpilih?')) {
        return;
    }

    try {
        const response = await fetch('api/bad-data.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: checked })
        });
        const result = await response.json();
        if (!result.success) {
            alert(result.message || 'Gagal menghapus data.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat menghapus data terpilih.');
    }
}

async function deleteSingleBadData(id) {
    if (!id) return;
    if (!confirm('Apakah Anda yakin ingin menghapus data baris ini secara permanen?')) {
        return;
    }

    try {
        const response = await fetch('api/bad-data.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: [id] })
        });
        const result = await response.json();
        if (!result.success) {
            alert(result.message || 'Gagal menghapus data.');
            return;
        }
        window.location.reload();
    } catch (err) {
        alert('Terjadi kesalahan saat menghapus data.');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>