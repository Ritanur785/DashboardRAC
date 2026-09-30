<?php
declare(strict_types=1);

$pageTitle = 'Nilai Maturitas';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterMonth = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$data = [];

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
                Nilai Maturitas
            </h2>

            <div class="page-subtitle">
                Monitoring penilaian tingkat maturitas
            </div>
        </div>

        <!-- Tombol Import Excel/CSV -->
        <button
            type="button"
            class="btn btn-sm"
            id="btnImportNilaiMaturitas"
            onclick="openImportNilaiMaturitas()"
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
        action="nilai-maturitas.php"
        class="filter-card"
    >

        <!-- Posisi Bulan -->
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

        <!-- Region -->
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

        <!-- Pencarian -->
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
                placeholder="Cari nama PIC RAC / unit kerja..."
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>

        <!-- Tombol Filter -->
        <div class="filter-actions">

            <button
                type="submit"
                class="btn btn-primary"
            >
                Terapkan Filter
            </button>

            <a
                href="nilai-maturitas.php"
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

                        <th>Nilai Maturitas</th>

                        <th>Avarage</th>

                        <th>Rating Maturitas</th>

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
                            Belum ada data nilai maturitas.
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

        <!-- Pagination -->
        <div class="table-pagination">

            <div class="pagination-info">
                Menampilkan
                <strong>0 - 0</strong>
                dari
                <strong>0</strong>
                data nilai maturitas
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