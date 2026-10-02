<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/importer.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$pdo = getDbConnection();

if ($action === 'get') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM str_alerts WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $alert = $stmt->fetch();

    if (!$alert) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Alert not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $alert]);
    exit;
}

if ($action === 'update_progress' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ID parameter']);
        exit;
    }

    $tglTindakLanjut = !empty($_POST['tgl_tindak_lanjut']) ? $_POST['tgl_tindak_lanjut'] : null;
    $disposisiRac = trim((string)($_POST['disposisi_rac'] ?? ''));
    $statusTl = trim((string)($_POST['status_tl'] ?? 'Not Done'));
    if (!in_array($statusTl, ['Done', 'Not Done'], true)) {
        $statusTl = 'Not Done';
    }

    try {
        $sql = "UPDATE str_alerts SET 
                    tgl_tindak_lanjut = :tgl_tindak_lanjut,
                    disposisi_rac = :disposisi_rac,
                    status_tl = :status_tl,
                    status = :status
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            'tgl_tindak_lanjut' => $tglTindakLanjut,
            'disposisi_rac' => $disposisiRac ?: null,
            'status_tl' => $statusTl,
            'status' => $statusTl,
            'id' => $id,
        ]);

        echo json_encode(['success' => $success, 'message' => 'Progress alert berhasil diperbarui']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'bulk_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) {
        $ids = json_decode($ids, true) ?: explode(',', $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));

    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tidak ada alert yang dipilih.']);
        exit;
    }

    $disposisiRac = trim((string)($_POST['disposisi_rac'] ?? ''));
    $tglTindakLanjut = trim((string)($_POST['tgl_tindak_lanjut'] ?? ''));
    $statusTl = trim((string)($_POST['status_tl'] ?? ''));

    if ($disposisiRac === '' && $tglTindakLanjut === '' && $statusTl === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Harap isi Disposisi RAC terlebih dahulu.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $updateCols = [];
        $params = [];

        if ($disposisiRac !== '') {
            $updateCols[] = 'disposisi_rac = :disposisi_rac';
            $params['disposisi_rac'] = $disposisiRac;
        }

        if ($tglTindakLanjut !== '') {
            $updateCols[] = 'tgl_tindak_lanjut = :tgl_tindak_lanjut';
            $params['tgl_tindak_lanjut'] = $tglTindakLanjut;
        }

        if ($statusTl !== '') {
            $updateCols[] = 'status_tl = :status_tl';
            $updateCols[] = 'status = :status';
            $params['status_tl'] = $statusTl;
            $params['status'] = $statusTl;
        }

        $updateCols[] = 'updated_at = CURRENT_TIMESTAMP';

        $placeholders = [];
        foreach ($ids as $i => $idVal) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $idVal;
        }

        $sql = "UPDATE str_alerts SET " . implode(', ', $updateCols) . " WHERE id IN (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        $count = count($ids);
        echo json_encode([
            'success' => true,
            'count' => $count,
            'message' => "Berhasil memperbarui disposisi RAC untuk {$count} alert terpilih."
        ]);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posisi = trim((string)($_POST['posisi'] ?? ''));
    $nama = trim((string)($_POST['nama_nasabah'] ?? ''));
    $cif = trim((string)($_POST['cif'] ?? ''));
    $noRekening = trim((string)($_POST['no_rekening'] ?? ''));
    $skenario = trim((string)($_POST['skenario'] ?? ''));
    $kategori = trim((string)($_POST['kategori'] ?? 'Narkotika/Judi Online'));
    $unitKerja = trim((string)($_POST['unit_kerja'] ?? 'Kas Kampus'));
    $kantorCabang = trim((string)($_POST['kantor_cabang'] ?? $unitKerja));
    $kantorKanwil = trim((string)($_POST['kantor_kanwil'] ?? 'KAS KAMPUS'));
    $disposisi = trim((string)($_POST['disposisi'] ?? ''));

    if (empty($nama) || empty($noRekening) || empty($skenario)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nama, No Rekening, dan Skenario wajib diisi.']);
        exit;
    }

    $sql = "INSERT INTO str_alerts 
            (posisi, nama_nasabah, cif, no_rekening, skenario, kategori, unit_kerja, kantor_cabang, kantor_kanwil, branch, main_branch, regional_office, status_uker, status_ukk, status, disposisi, disposisi_rac)
            VALUES 
            (:posisi, :nama, :cif, :rekening, :skenario, :kategori, :uker, :kc, :kanwil, :uker, :kc, :kanwil, 'Belum TL', 'Belum TL', 'Belum TL', :disposisi, NULL)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'posisi' => $posisi ?: date('Y-m-d'),
        'nama' => strtoupper($nama),
        'cif' => $cif ?: '0',
        'rekening' => $noRekening,
        'skenario' => $skenario,
        'kategori' => $kategori,
        'uker' => $unitKerja,
        'kc' => $kantorCabang,
        'kanwil' => $kantorKanwil,
        'disposisi' => $disposisi ?: null,
    ]);

    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Alert STR baru berhasil ditambahkan']);
    exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawData = trim((string)($_POST['raw_data'] ?? ''));

    if ($rawData !== '') {
        $rows = AlertImporter::parseCsvString($rawData);
        $result = AlertImporter::processImportRows($rows);
        echo json_encode($result);
        exit;
    }

    if (!empty($_FILES['file']['tmp_name'])) {
        $fileName = (string)($_FILES['file']['name'] ?? '');
        $tmpPath = (string)$_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $rows = AlertImporter::parseFile($tmpPath, $fileName);
            $result = AlertImporter::processImportRows($rows);
            echo json_encode($result);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tidak ada file atau data teks yang dikirimkan.']);
    exit;
}

if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->exec('TRUNCATE TABLE str_alerts');
        echo json_encode(['success' => true, 'message' => 'Seluruh data alert STR berhasil direset.']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal mereset data: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $ids = $input['ids'] ?? ($input['id'] ?? []);
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tidak ada data yang dipilih untuk dihapus.']);
        exit;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM str_alerts WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
        echo json_encode(['success' => true, 'count' => $count, 'message' => "{$count} data alert STR berhasil dihapus."]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Gagal menghapus data: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
