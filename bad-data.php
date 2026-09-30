<?php
declare(strict_types=1);

$pageTitle = 'Monitoring Bad Data';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterMonth  = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($filterStatus !== '') {
    if (strtolower($filterStatus) === 'done') {
        $where[] = "LOWER(TRIM(status)) = 'done'";
    } elseif (strtolower($filterStatus) === 'open') {
        $where[] = "LOWER(TRIM(status)) = 'open'";
    } elseif (strtolower($filterStatus) === 'not done' || strtolower($filterStatus) === 'not_done') {
        $where[] = "(status IS NULL OR LOWER(TRIM(status)) NOT IN ('done', 'open'))";
    } else {
        $where[] = "LOWER(TRIM(status)) = :filter_status";
        $params['filter_status'] = strtolower($filterStatus);
    }
}

if ($filterMonth !== '') {
    $where[] = "month LIKE :filter_month";
    $params['filter_month'] = '%' . $filterMonth . '%';
}

if ($filterRegion !== '') {
    if (isset(MASTER_REGIONS[(int)$filterRegion])) {
        $regName = MASTER_REGIONS[(int)$filterRegion];
        $where[] = getRegionSqlCondition($regName, "region");
    } else {
        $where[] = "region LIKE :filter_region";
        $params['filter_region'] = '%' . $filterRegion . '%';
    }
}

if ($search !== '') {
    $where[] = "(branch LIKE :search OR pic_rac LIKE :search OR keterangan LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bad_data {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$dataSql = "SELECT * FROM bad_data {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $val) {
    $dataStmt->bindValue(':' . $key, $val);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$pageData = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$availableMonths = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

if (!function_exists('buildBadDataPageUrl')) {
    function buildBadDataPageUrl(int $pageNumber): string {
        $query = $_GET;
        $query['page'] = $pageNumber;
        return 'bad-data.php?' . http_build_query($query);
    }
}

if (!function_exists('renderBadDataBadge')) {
    function renderBadDataBadge(string $status): string {
        $st = strtolower(trim($status));
        if ($st === 'done' || $st === 'selesai') {
            return '<span class="badge badge-done">Done</span>';
        }
        if ($st === 'open' || $st === 'proses' || $st === 'in progress') {
            return '<span class="badge badge-in-progress">' . htmlspecialchars($status !== '' ? $status : 'Open', ENT_QUOTES, 'UTF-8') . '</span>';
        }
        return '<span class="badge badge-not-started">' . htmlspecialchars($status !== '' ? $status : 'Not Done', ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;">
        <div>
            <h2 class="page-title">Monitoring Bad Data</h2>
            <div class="page-subtitle">Monitoring evaluasi, mutasi harian, dan perbaikan data RAC</div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="import-bad-data.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Import Excel / CSV
            </a>
        </div>
    </div>

    <form method="GET" action="bad-data.php" class="filter-card">
        <div class="filter-item">
            <label for="filterStatus" class="filter-label">Filter Status</label>
            <select id="filterStatus" name="status" class="form-control">
                <option value="">Semua Status</option>
                <option value="Done" <?= $filterStatus === 'Done' ? 'selected' : '' ?>>Done</option>
                <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
                <option value="Not Done" <?= $filterStatus === 'Not Done' ? 'selected' : '' ?>>Not Done</option>
            </select>
        </div>

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
            <label for="filterRegion" class="filter-label">Filter Region</label>
            <select id="filterRegion" name="region" class="form-control">
                <option value="">Semua Region</option>
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
                placeholder="Cari Branch / PIC RAC / Keterangan..."
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="bad-data.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

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
                            >
                        </th>
                        <th>NO</th>
                        <th>BRANCH OFFICE</th>
                        <th>TOTAL CIF</th>
                        <th>BAD DATA (LALU)</th>
                        <th>BAD DATA (KINI)</th>
                        <th>% BAD DATA</th>
                        <th>PERBAIKAN</th>
                        <th>BAD DATA BARU</th>
                        <th>STATUS</th>
                        <th>KETERANGAN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pageData)): ?>
                        <tr>
                            <td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted);">
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
                                    >
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['no_urut'] ?: ($offset + $index + 1)), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['branch'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['total_cif'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['bad_data_prev'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['bad_data_curr'] ?? $row['average'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['persentase'] ?? '0%'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td style="color:#15803d;font-weight:600;">
                                    <?= htmlspecialchars((string)($row['perbaikan_bad_data'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="color:#b91c1c;font-weight:600;">
                                    <?= htmlspecialchars((string)($row['bad_data_baru'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= renderBadDataBadge((string)($row['status'] ?? '')) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['keterangan'] ?: ($row['month'] ?? '-')), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-pagination">
            <div class="pagination-info">
                Menampilkan
                <strong><?= $totalRecords > 0 ? $offset + 1 : 0 ?> - <?= min($offset + count($pageData), $totalRecords) ?></strong>
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
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAllBadData = document.getElementById('checkAllBadData');
    if (checkAllBadData) {
        checkAllBadData.addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('.bad-data-check');
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = checkAllBadData.checked;
            });
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';