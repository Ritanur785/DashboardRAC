<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$pdo = getDbConnection();

if ($action === 'get') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ID parameter']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM pep_alerts WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Data PEP tidak ditemukan']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);
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
        $sql = "UPDATE pep_alerts SET 
                    tgl_tindak_lanjut = :tgl_tindak_lanjut,
                    disposisi_rac = :disposisi_rac,
                    status_tl = :status_tl
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            'tgl_tindak_lanjut' => $tglTindakLanjut,
            'disposisi_rac' => $disposisiRac ?: null,
            'status_tl' => $statusTl,
            'id' => $id,
        ]);

        echo json_encode(['success' => $success, 'message' => 'Progress PEP berhasil diperbarui']);
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
        echo json_encode(['success' => false, 'error' => 'Tidak ada item PEP yang dipilih.']);
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
            $params['status_tl'] = $statusTl;
        }

        $updateCols[] = 'updated_at = CURRENT_TIMESTAMP';

        $placeholders = [];
        foreach ($ids as $i => $idVal) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $idVal;
        }

        $sql = "UPDATE pep_alerts SET " . implode(', ', $updateCols) . " WHERE id IN (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $pdo->commit();

        $count = count($ids);
        echo json_encode([
            'success' => true,
            'count' => $count,
            'message' => "Berhasil memperbarui disposisi RAC untuk {$count} item PEP terpilih."
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

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/pep_importer.php';

    $rawData = trim((string)($_POST['raw_data'] ?? ''));

    if ($rawData !== '') {
        $rows = PepImporter::parseCsvString($rawData);
        $result = PepImporter::processImportRows($rows);
        echo json_encode($result);
        exit;
    }

    if (!empty($_FILES['file']['tmp_name'])) {
        $fileName = (string)($_FILES['file']['name'] ?? '');
        $tmpPath = (string)$_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        try {
            $rows = PepImporter::parseFile($tmpPath, $fileName);
            $result = PepImporter::processImportRows($rows);
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

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action requested']);
exit;
