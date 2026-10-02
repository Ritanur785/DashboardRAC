<?php
declare(strict_types=1);

$pageTitle = 'Penilaian Resiko';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterRegion = trim((string)($_GET['region'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($filterStatus !== '') {
    $where[] = "LOWER(TRIM(level_risiko)) = :filter_level";
    $params['filter_level'] = strtolower($filterStatus);
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
    $where[] = "(branch LIKE :search_branch OR pic_rac LIKE :search_pic OR level_risiko LIKE :search_level OR keterangan LIKE :search_ket)";
    $params['search_branch'] = '%' . $search . '%';
    $params['search_pic'] = '%' . $search . '%';
    $params['search_level'] = '%' . $search . '%';
    $params['search_ket'] = '%' . $search . '%';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM penilaian_resiko {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$perPage = 50;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$dataSql = "SELECT * FROM penilaian_resiko {$whereSql} ORDER BY id ASC LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $val) {
    $dataStmt->bindValue(':' . $key, $val);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$pageData = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('buildPenilaianResikoPageUrl')) {
    function buildPenilaianResikoPageUrl(int $pageNumber): string {
        $query = $_GET;
        $query['page'] = $pageNumber;
        return 'penilaian-resiko.php?' . http_build_query($query);
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<main class="main-content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;">
        <div>
            <h2 class="page-title">Penilaian Resiko</h2>
            <div class="page-subtitle">Monitoring hasil penilaian risiko data</div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="import-penilaian-resiko.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                Import Excel / CSV
            </a>
            <?php if ($totalRecords > 0): ?>
                <button type="button" class="btn btn-danger btn-sm" id="btnResetPenilaianResiko" onclick="openResetModal()">
                    Reset Seluruh Data
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" action="penilaian-resiko.php" class="filter-card">
        <div class="filter-item">
            <label for="filterStatus" class="filter-label">Level Risiko</label>
            <select id="filterStatus" name="status" class="form-control">
                <option value="">Semua Level</option>
                <option value="small" <?= strtolower($filterStatus) === 'small' ? 'selected' : '' ?>>Small</option>
                <option value="medium" <?= strtolower($filterStatus) === 'medium' ? 'selected' : '' ?>>Medium</option>
                <option value="large" <?= strtolower($filterStatus) === 'large' ? 'selected' : '' ?>>Large</option>
            </select>
        </div>

        <div class="filter-item" style="min-width:220px;">
            <label for="filterRegion" class="filter-label">Region</label>
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
                placeholder="Cari Unit Kerja / PIC RAC / Level..."
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan Filter</button>
            <a href="penilaian-resiko.php" class="btn btn-secondary">Reset Filter</a>
        </div>
    </form>

    <!-- BULK ACTIONS -->
    <div id="bulkActions" style="display:none;margin-bottom:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;align-items:center;justify-content:space-between;">
        <span id="selectedCountText" style="font-size:13px;color:#991b1b;font-weight:600;">0 data dipilih</span>
        <button type="button" class="btn btn-danger btn-sm" onclick="deleteSelectedPenilaianResiko()">
            Hapus Data Terpilih
        </button>
    </div>

    <!-- Tabel -->
    <div class="table-wrapper">
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center;">
                            <input
                                type="checkbox"
                                id="checkAllPenilaianResiko"
                                title="Pilih Semua di Halaman Ini"
                                style="cursor:pointer;width:16px;height:16px;"
                                <?= empty($pageData) ? 'disabled' : '' ?>
                            >
                        </th>
                        <th style="width:50px;text-align:center;">NO</th>
                        <th>UNIT KERJA</th>
                        <th>PIC RAC</th>
                        <th>Nilai Resiko</th>
                        <th>Nilai Risiko PIC</th>
                        <th style="text-align:center;">Level Risiko</th>
                        <th style="width:70px;text-align:center;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pageData)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">
                                Belum ada data penilaian resiko yang sesuai filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pageData as $index => $row): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        class="resiko-check"
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
                                    <strong><?= htmlspecialchars((string)($row['nilai_resiko'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['nilai_risiko_pic'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    $level = (string)($row['level_risiko'] ?? $row['status'] ?? 'Small');
                                    $levelLower = strtolower($level);
                                    if ($levelLower === 'large' || $levelLower === 'high' || $levelLower === 'tinggi') {
                                        $badgeBg = '#fee2e2'; $badgeColor = '#b91c1c'; $levelLabel = 'Large';
                                    } elseif ($levelLower === 'medium' || $levelLower === 'sedang') {
                                        $badgeBg = '#fef3c7'; $badgeColor = '#b45309'; $levelLabel = 'Medium';
                                    } else {
                                        $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; $levelLabel = 'Small';
                                    }
                                    ?>
                                    <span class="badge" style="background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:600;">
                                        <?= htmlspecialchars($levelLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteSinglePenilaianResiko(<?= (int)$row['id'] ?>)" title="Hapus baris ini">Hapus</button>
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
                data penilaian resiko
            </div>

            <ul class="pagination-controls">
                <li>
                    <a href="<?= $page > 1 ? htmlspecialchars(buildPenilaianResikoPageUrl($page - 1), ENT_QUOTES, 'UTF-8') : '#' ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">
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
                    echo '<li><a href="' . htmlspecialchars(buildPenilaianResikoPageUrl(1), ENT_QUOTES, 'UTF-8') . '" class="page-btn">1</a></li>';
                    if ($startPage > 2) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                }
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $activeClass = $i === $page ? 'active' : '';
                    echo '<li><a href="' . htmlspecialchars(buildPenilaianResikoPageUrl($i), ENT_QUOTES, 'UTF-8') . '" class="page-btn ' . $activeClass . '">' . $i . '</a></li>';
                }
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<li><span class="page-ellipsis">&hellip;</span></li>';
                    }
                    echo '<li><a href="' . htmlspecialchars(buildPenilaianResikoPageUrl($totalPages), ENT_QUOTES, 'UTF-8') . '" class="page-btn">' . $totalPages . '</a></li>';
                }
                ?>
                <li>
                    <a href="<?= $page < $totalPages ? htmlspecialchars(buildPenilaianResikoPageUrl($page + 1), ENT_QUOTES, 'UTF-8') : '#' ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
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
            Apakah Anda yakin ingin mereset seluruh data Penilaian Resiko? Seluruh baris yang tersimpan akan dihapus secara permanen.
        </p>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Batal</button>
            <button type="button" class="btn btn-danger" id="btnConfirmReset" onclick="executeResetPenilaianResiko()">Ya, Hapus Semua</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAllPenilaianResiko');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('.resiko-check');
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = checkAll.checked;
            });
            updateBulkActionState();
        });
    }
});

function updateBulkActionState() {
    const checked = document.querySelectorAll('.resiko-check:checked');
    const bulkDiv = document.getElementById('bulkActions');
    const countText = document.getElementById('selectedCountText');
    if (!bulkDiv || !countText) return;

    if (checked.length > 0) {
        bulkDiv.style.display = 'flex';
        countText.textContent = checked.length + ' data dipilih';
    } else {
        bulkDiv.style.display = 'none';
        countText.textContent = '0 data dipilih';
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

async function executeResetPenilaianResiko() {
    const btn = document.getElementById('btnConfirmReset');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Menghapus...';
    }

    try {
        const response = await fetch('api/penilaian-resiko.php?action=clear', {
            method: 'POST'
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Gagal mereset data.');
        }

        window.location.reload();
    } catch (err) {
        alert(err.message || 'Terjadi kesalahan sistem.');
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Ya, Hapus Semua';
        }
        closeResetModal();
    }
}

async function deleteSelectedPenilaianResiko() {
    const checked = document.querySelectorAll('.resiko-check:checked');
    const ids = Array.from(checked).map(c => c.value);
    if (!ids.length) return;

    if (!confirm('Apakah Anda yakin ingin menghapus ' + ids.length + ' data terpilih?')) {
        return;
    }

    try {
        const response = await fetch('api/penilaian-resiko.php?action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        });
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Gagal menghapus data.');
        }

        window.location.reload();
    } catch (err) {
        alert(err.message || 'Terjadi kesalahan saat menghapus data.');
    }
}

async function deleteSinglePenilaianResiko(id) {
    if (!id) return;
    if (!confirm('Apakah Anda yakin ingin menghapus data baris ini secara permanen?')) {
        return;
    }

    try {
        const response = await fetch('api/penilaian-resiko.php?action=delete', {
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