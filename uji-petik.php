<?php
declare(strict_types=1);

$pageTitle = 'Uji Petik';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterMonth = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$perPage = 50;

$totalRecords = 0;
$totalPages = 1;
$page = 1;
$offset = 0;

$data = [];

/*
|--------------------------------------------------------------------------
| FUNCTION PAGINATION
|--------------------------------------------------------------------------
*/
if (!function_exists('buildUjiPetikPageUrl')) {
    function buildUjiPetikPageUrl(int $pageNumber): string
    {
        $query = $_GET;
        $query['page'] = $pageNumber;

        return 'uji-petik.php?' . http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| DAFTAR BULAN
|--------------------------------------------------------------------------
*/
$months = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember'
];

$availableMonths = $months;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">

    <div
        class="page-header"
        style="
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
        "
    >
        <div>
            <h2 class="page-title">
                Uji Petik
            </h2>

            <div class="page-subtitle">
                Monitoring pelaksanaan dan hasil uji petik data
            </div>
        </div>

        <!-- Tombol Import Excel/CSV -->
        <button
            type="button"
            class="btn btn-sm"
            id="btnImportUjiPetik"
            onclick="openImportUjiPetik()"
            style="
                background:#ffffff;
                color:#333333;
                border:1px solid #d1d5db;
                box-shadow:none;
            "
        >
            Import Excel/CSV
        </button>
    </div>

    <!-- Filter -->
    <form
        method="GET"
        action="uji-petik.php"
        class="filter-card"
    >

        <!-- STATUS -->
        <div class="filter-item">
            <label class="filter-label">
                Status
            </label>

            <select
                name="status"
                class="form-control"
            >
                <option value="">
                    Semua Status
                </option>

                <option
                    value="Done"
                    <?= $filterStatus === 'Done' ? 'selected' : '' ?>
                >
                    Done
                </option>

                <option
                    value="Not Done"
                    <?= $filterStatus === 'Not Done' ? 'selected' : '' ?>
                >
                    Not Done
                </option>
            </select>
        </div>

        <!-- POSISI BULAN -->
        <div class="filter-item">
            <label class="filter-label">
                Posisi Bulan
            </label>

            <select
                name="month"
                class="form-control"
            >
                <option value="">
                    Semua Bulan
                </option>

                <?php foreach ($months as $month): ?>

                    <option
                        value="<?= htmlspecialchars($month) ?>"
                        <?= $filterMonth === $month ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($month) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>

        <!-- REGION -->
        <div
            class="filter-item"
            style="min-width:220px;"
        >
            <label class="filter-label">
                Region
            </label>

            <select
                name="region"
                class="form-control"
            >
                <option value="">
                    Semua Region
                </option>

                <?php foreach (
                    MASTER_REGIONS
                    as $regionNumber => $regionName
                ): ?>

                    <option
                        value="<?= (int)$regionNumber ?>"
                        <?= $filterRegion === (string)$regionNumber ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars(
                            (string)$regionName
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>
        </div>

        <!-- PENCARIAN -->
        <div
            class="filter-item"
            style="flex:1;min-width:220px;"
        >
            <label class="filter-label">
                Pencarian
            </label>

            <input
                type="text"
                name="q"
                class="form-control"
                placeholder="Cari nama PIC RAC / Unit Kerja..."
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>

        <!-- ACTION -->
        <div class="filter-actions">

            <button
                type="submit"
                class="btn btn-primary"
            >
                Terapkan Filter
            </button>

            <a
                href="uji-petik.php"
                class="btn btn-secondary"
            >
                Reset
            </a>

        </div>

    </form>

    <!-- Tabel -->
    <div class="table-wrapper">

        <div class="table-scroll">

            <table class="data-table">

                <thead>
                    <tr>

                        <th style="width:38px;text-align:center;">
                            <input
                                type="checkbox"
                                id="checkAllAlerts"
                                title="Pilih Semua di Halaman Ini"
                                style="
                                    cursor:pointer;
                                    width:16px;
                                    height:16px;
                                "
                            >
                        </th>

                        <th>BRANCH</th>

                        <th>PIC RAC</th>

                        <th>Rencana Pelaksanaan</th>

                        <th>Tanggal Uji Petik</th>

                        <th>Dokumen</th>

                    </tr>
                </thead>

                <tbody>

                    <tr>
                        <td
                            colspan="6"
                            style="
                                text-align:center;
                                padding:32px;
                                color:var(--text-muted);
                            "
                        >
                            Belum ada data uji petik.
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="table-pagination">

            <div class="pagination-info">
                Menampilkan
                <strong>0 - 0</strong>
                dari
                <strong>0</strong>
                data uji petik
            </div>

            <ul class="pagination-controls">

                <li>
                    <a
                        href="#"
                        class="page-btn disabled"
                    >
                        &lsaquo; Sebelumnya
                    </a>
                </li>

                <li>
                    <a
                        href="#"
                        class="page-btn active"
                    >
                        1
                    </a>
                </li>

                <li>
                    <a
                        href="#"
                        class="page-btn disabled"
                    >
                        Selanjutnya &rsaquo;
                    </a>
                </li>

            </ul>

        </div>

    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>