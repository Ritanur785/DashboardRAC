<?php
declare(strict_types=1);

$pageTitle = 'Pengkinian Data';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterMonth  = trim((string)($_GET['month'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search       = trim((string)($_GET['q'] ?? ''));

$perPage = 50;

/*
|--------------------------------------------------------------------------
| Data sementara
|--------------------------------------------------------------------------
| Data akan dihubungkan dengan database pada tahap import.
|--------------------------------------------------------------------------
*/

$data = [];

/*
|--------------------------------------------------------------------------
| Daftar bulan
|--------------------------------------------------------------------------
*/

$availableMonths = [
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

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalRecords = count($data);

$totalPages = max(
    1,
    (int)ceil($totalRecords / $perPage)
);

$page = max(
    1,
    min(
        $totalPages,
        (int)($_GET['page'] ?? 1)
    )
);

$offset = ($page - 1) * $perPage;

$pageData = array_slice(
    $data,
    $offset,
    $perPage
);

/*
|--------------------------------------------------------------------------
| URL Pagination
|--------------------------------------------------------------------------
*/

if (!function_exists('buildPengkinianPageUrl')) {

    function buildPengkinianPageUrl(
        int $pageNumber
    ): string {

        $query = $_GET;

        $query['page'] = $pageNumber;

        return 'pengkinian-data.php?' .
            http_build_query($query);
    }
}

/*
|--------------------------------------------------------------------------
| Header & Sidebar
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

?>

<main class="main-content">

    <!-- =========================================================
         PAGE HEADER
    ========================================================== -->

    <div
        class="page-header"
        style="
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:20px;
        "
    >

        <div>

            <h2 class="page-title">
                Pengkinian Data
            </h2>

            <div class="page-subtitle">
                Monitoring dan pengkinian data PEP
            </div>

        </div>


        <!-- =====================================================
             BUTTON IMPORT
        ====================================================== -->

        <div>

            <button
                type="button"
                class="btn btn-sm"
                id="btnImportPengkinian"
                onclick="openImportPengkinian()"
                style="
                    background:#ffffff;
                    color:#333333;
                    border:1px solid #d1d5db;
                    box-shadow:none;
                "
            >
                Import Excel/CSV
            </button>

            <input
                type="file"
                id="fileImportPengkinian"
                accept=".xlsx,.xls,.csv"
                style="display:none;"
                onchange="handleImportPengkinian(this)"
            >

        </div>

    </div>


    <!-- =========================================================
         FILTER
    ========================================================== -->

    <form
        method="GET"
        action="./pengkinian-data.php"
        class="filter-card"
    >

        <!-- STATUS -->

        <div class="filter-item">

            <label
                for="filterStatus"
                class="filter-label"
            >
                Status
            </label>

            <select
                id="filterStatus"
                name="status"
                class="form-control"
            >

                <option value="">
                    Semua Status
                </option>

                <option
                    value="Done"
                    <?= $filterStatus === 'Done'
                        ? 'selected'
                        : '' ?>
                >
                    Done
                </option>

                <option
                    value="Not Done"
                    <?= $filterStatus === 'Not Done'
                        ? 'selected'
                        : '' ?>
                >
                    Not Done
                </option>

            </select>

        </div>


        <!-- BULAN -->

        <div class="filter-item">

            <label
                for="filterMonth"
                class="filter-label"
            >
                Posisi Bulan
            </label>

            <select
                id="filterMonth"
                name="month"
                class="form-control"
            >

                <option value="">
                    Semua Bulan
                </option>

                <?php foreach (
                    $availableMonths as $month
                ): ?>

                    <option
                        value="<?= htmlspecialchars(
                            $month,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        <?= $filterMonth === $month
                            ? 'selected'
                            : '' ?>
                    >
                        <?= htmlspecialchars(
                            $month,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- REGION -->

        <div
            class="filter-item"
            style="min-width:220px;"
        >

            <label
                for="filterRegion"
                class="filter-label"
            >
                Filter Region
            </label>

            <select
                id="filterRegion"
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
                        <?= $filterRegion ===
                            (string)$regionNumber
                            ? 'selected'
                            : '' ?>
                    >
                        <?= htmlspecialchars(
                            (string)$regionName,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- PENCARIAN -->

        <div
            class="filter-item"
            style="
                flex:1;
                min-width:220px;
            "
        >

            <label
                for="filterSearch"
                class="filter-label"
            >
                Pencarian
            </label>

            <input
                type="text"
                id="filterSearch"
                name="q"
                class="form-control"
                placeholder="Cari nama PIC RAC / Unit Kerja..."
                value="<?= htmlspecialchars(
                    $search,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

        </div>


        <!-- ACTION FILTER -->

        <div class="filter-actions">

            <button
                type="submit"
                class="btn btn-primary"
            >
                Terapkan Filter
            </button>

            <a
                href="./pengkinian-data.php"
                class="btn btn-secondary"
            >
                Reset
            </a>

        </div>

    </form>


    <!-- =========================================================
         TABLE
    ========================================================== -->

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
                        <th>%Pengkinian Data</th>
                        <th>Avarage</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if (
                        empty($pageData)
                    ): ?>

                        <tr>

                            <td
                                colspan="10"
                                style="
                                    text-align:center;
                                    padding:40px;
                                    color:var(--text-muted);
                                "
                            >
                                Belum ada data pengkinian.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $pageData
                            as $index => $row
                        ): ?>

                            <tr>

                                <td>
                                    <?= $offset +
                                        $index +
                                        1 ?>
                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row['posisi']
                                            ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <strong>

                                        <?= htmlspecialchars(
                                            (string)(
                                                $row[
                                                    'nama_lengkap'
                                                ] ?? '-'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </strong>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row['cif']
                                            ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row['nik']
                                            ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row[
                                                'unit_kerja'
                                            ] ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row['region']
                                            ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <?= htmlspecialchars(
                                        (string)(
                                            $row[
                                                'tanggal_pengkinian'
                                            ] ?? '-'
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </td>

                                <td>

                                    <?php

                                    $status = (string)(
                                        $row['status']
                                        ?? 'Not Done'
                                    );

                                    $statusClass =
                                        strtolower(
                                            trim($status)
                                        ) === 'done'
                                            ? 'badge-done'
                                            : 'badge-not-started';

                                    ?>

                                    <span
                                        class="badge <?= htmlspecialchars(
                                            $statusClass,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $status,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <button
                                        type="button"
                                        class="
                                            btn
                                            btn-secondary
                                            btn-sm
                                        "
                                        onclick="openImportPengkinian()"
                                    >
                                        Update
                                    </button>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>


        <!-- =====================================================
             PAGINATION
        ====================================================== -->

        <div class="table-pagination">

            <div class="pagination-info">

                Menampilkan

                <strong>

                    <?= $totalRecords > 0
                        ? $offset + 1
                        : 0 ?>

                    -

                    <?= min(
                        $offset +
                        count($pageData),
                        $totalRecords
                    ) ?>

                </strong>

                dari

                <strong>
                    <?= $totalRecords ?>
                </strong>

                data

            </div>


            <ul class="pagination-controls">

                <li>

                    <?php if ($page > 1): ?>

                        <a
                            href="<?= htmlspecialchars(
                                buildPengkinianPageUrl(
                                    $page - 1
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="page-btn"
                        >
                            &lsaquo; Sebelumnya
                        </a>

                    <?php else: ?>

                        <span
                            class="
                                page-btn
                                disabled
                            "
                        >
                            &lsaquo; Sebelumnya
                        </span>

                    <?php endif; ?>

                </li>


                <li>

                    <span
                        class="
                            page-btn
                            active
                        "
                    >
                        <?= $page ?>
                    </span>

                </li>


                <li>

                    <?php if (
                        $page < $totalPages
                    ): ?>

                        <a
                            href="<?= htmlspecialchars(
                                buildPengkinianPageUrl(
                                    $page + 1
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            class="page-btn"
                        >
                            Selanjutnya &rsaquo;
                        </a>

                    <?php else: ?>

                        <span
                            class="
                                page-btn
                                disabled
                            "
                        >
                            Selanjutnya &rsaquo;
                        </span>

                    <?php endif; ?>

                </li>

            </ul>

        </div>

    </div>

</main>


<!-- =============================================================
     MODAL IMPORT EXCEL / CSV
============================================================== -->

<div
    id="importPengkinianModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        z-index:9999;
        align-items:center;
        justify-content:center;
        padding:20px;
    "
>

    <div
        style="
            background:#fff;
            width:100%;
            max-width:500px;
            border-radius:10px;
            padding:24px;
            box-shadow:0 10px 40px rgba(0,0,0,.2);
        "
    >

        <div
            style="
                display:flex;
                justify-content:space-between;
                align-items:center;
                margin-bottom:20px;
            "
        >

            <h3 style="margin:0;">
                Import Data Pengkinian
            </h3>

            <button
                type="button"
                onclick="closeImportPengkinian()"
                style="
                    border:0;
                    background:none;
                    font-size:22px;
                    cursor:pointer;
                "
            >
                &times;
            </button>

        </div>


        <div
            style="
                padding:20px;
                background:#f8f9fa;
                border-radius:8px;
                text-align:center;
                margin-bottom:20px;
            "
        >

            <p style="margin-top:0;">

                Pilih file data yang akan
                diimport.

            </p>

            <p
                style="
                    color:#777;
                    font-size:13px;
                    margin-bottom:0;
                "
            >

                Format yang didukung:

                <strong>
                    Excel (.xlsx, .xls)
                </strong>

                atau

                <strong>
                    CSV (.csv)
                </strong>

            </p>

        </div>


        <div
            id="selectedImportFile"
            style="
                display:none;
                padding:12px;
                background:#f1f5f9;
                border-radius:6px;
                margin-bottom:20px;
                font-size:14px;
            "
        ></div>


        <div
            style="
                display:flex;
                justify-content:flex-end;
                gap:10px;
            "
        >

            <button
                type="button"
                class="btn btn-secondary"
                onclick="closeImportPengkinian()"
            >
                Batal
            </button>

            <button
                type="button"
                class="btn btn-primary"
                onclick="chooseImportFile()"
            >
                Pilih File
            </button>

        </div>

    </div>

</div>


<!-- =============================================================
     JAVASCRIPT IMPORT
============================================================== -->

<script>

function openImportPengkinian() {

    const modal = document.getElementById(
        'importPengkinianModal'
    );

    if (modal) {

        modal.style.display = 'flex';

    }

}


function closeImportPengkinian() {

    const modal = document.getElementById(
        'importPengkinianModal'
    );

    if (modal) {

        modal.style.display = 'none';

    }

}


function chooseImportFile() {

    const fileInput = document.getElementById(
        'fileImportPengkinian'
    );

    if (fileInput) {

        fileInput.click();

    }

}


function handleImportPengkinian(input) {

    const selectedFile =
        document.getElementById(
            'selectedImportFile'
        );

    if (
        !input.files ||
        input.files.length === 0
    ) {

        return;

    }


    const file = input.files[0];


    const allowedExtensions = [
        'xlsx',
        'xls',
        'csv'
    ];

    const fileName =
        file.name.toLowerCase();

    const extension =
        fileName.split('.').pop();


    if (
        !allowedExtensions.includes(
            extension
        )
    ) {

        alert(
            'Format file tidak didukung. ' +
            'Silakan pilih file Excel atau CSV.'
        );

        input.value = '';

        return;

    }


    if (selectedFile) {

        selectedFile.style.display =
            'block';

        selectedFile.innerHTML =
            '<strong>File dipilih:</strong> ' +
            escapeHtml(file.name);

    }

}


function escapeHtml(value) {

    const div =
        document.createElement('div');

    div.textContent = value;

    return div.innerHTML;

}


window.addEventListener(
    'click',
    function(event) {

        const modal =
            document.getElementById(
                'importPengkinianModal'
            );

        if (
            modal &&
            event.target === modal
        ) {

            modal.style.display = 'none';

        }

    }
);

</script>


<?php
require_once __DIR__ . '/includes/footer.php';
?>