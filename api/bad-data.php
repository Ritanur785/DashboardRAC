<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(bool $success, string $message = '', array $extra = []): never {
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    jsonResponse(false, 'Koneksi database gagal: ' . $e->getMessage());
}

function normalizeHeader(mixed $value): string {
    $value = (string)$value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtolower($value ?? '');
}

function findColumnIndex(array $headers, array $possibleNames): ?int {
    foreach ($possibleNames as $possibleName) {
        $normalizedTarget = normalizeHeader($possibleName);
        foreach ($headers as $index => $header) {
            if (normalizeHeader($header) === $normalizedTarget) {
                return (int)$index;
            }
        }
    }
    return null;
}

function normalizeNumber(mixed $value): ?string {
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    return $value;
}

function detectDelimiter(string $content): string {
    $firstLine = strtok($content, "\r\n") ?: '';
    $delimiters = ['|' => 0, ';' => 0, ',' => 0, "\t" => 0];
    foreach ($delimiters as $delim => &$count) {
        $count = substr_count($firstLine, $delim);
    }
    arsort($delimiters);
    $top = array_key_first($delimiters);
    return ($delimiters[$top] ?? 0) > 0 ? (string)$top : '|';
}

function parseDelimitedText(string $content, ?string $delimiter = null): array {
    if ($delimiter === null) {
        $delimiter = detectDelimiter($content);
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $rows = [];

    foreach ($lines as $line) {
        $cleanLine = trim($line);
        if ($cleanLine === '') {
            continue;
        }

        // Strip enclosing quotes from lines exported by Excel with pipe delimiters
        if (str_starts_with($cleanLine, '"') && str_ends_with($cleanLine, '"') && strpos($cleanLine, $delimiter) !== false) {
            $cleanLine = substr($cleanLine, 1, -1);
            $cleanLine = str_replace('""', '"', $cleanLine);
        }

        $row = str_getcsv($cleanLine, $delimiter, '"', '\\');
        $rows[] = array_map(function ($v) {
            $val = trim((string)$v);
            if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                $val = trim(substr($val, 1, -1));
            }
            return $val;
        }, $row);
    }

    return $rows;
}

// Built-in parser for XLSX files without requiring Composer/phpspreadsheet
function parseXlsxFile(string $filePath): array {
    $zip = new ZipArchive();
    $openResult = $zip->open($filePath, ZipArchive::RDONLY);
    if ($openResult !== true) {
        $openResult = $zip->open($filePath);
    }
    if ($openResult !== true) {
        throw new RuntimeException('Gagal membaca file Excel (.xlsx).');
    }

    $sharedStrings = [];
    $sharedStringXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringXml !== false) {
        $xml = simplexml_load_string($sharedStringXml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $sheetXml = false;
    $candidates = ['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml', 'xl/worksheets/sheet.xml'];
    foreach ($candidates as $candidate) {
        $sheetXml = $zip->getFromName($candidate);
        if ($sheetXml !== false) {
            break;
        }
    }

    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/.*\.xml$#i', $entry)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }

    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('Worksheet tidak ditemukan dalam file Excel.');
    }

    $xml = simplexml_load_string($sheetXml);
    $zip->close();

    if (!$xml || !isset($xml->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($xml->sheetData->row as $rowNode) {
        $row = [];
        $lastColIdx = 0;

        foreach ($rowNode->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)/', $ref, $matches);
            $colLetters = $matches[1] ?? 'A';

            $colIdx = 0;
            $len = strlen($colLetters);
            for ($k = 0; $k < $len; $k++) {
                $colIdx = $colIdx * 26 + (ord($colLetters[$k]) - ord('A') + 1);
            }

            while ($lastColIdx < $colIdx - 1) {
                $row[] = '';
                $lastColIdx++;
            }

            $type = (string)$cell['t'];
            $val = isset($cell->v) ? (string)$cell->v : '';

            if ($type === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                $val = $sharedStrings[(int)$val];
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $val = (string)$cell->is->t;
            }

            $row[] = trim($val);
            $lastColIdx = $colIdx;
        }

        if (!empty(array_filter($row, fn($c) => $c !== ''))) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function detectFormatMode(array $header): string {
    $headerStr = strtolower(implode(' ', array_map('strval', $header)));
    if (
        str_contains($headerStr, 'branch office') ||
        str_contains($headerStr, 'perbaikan bad data') ||
        str_contains($headerStr, 'bad data baru') ||
        count($header) >= 14
    ) {
        return 'comparison_17';
    }
    return 'standard_7';
}

function getBadDataColumnMap(array $header): array {
    $mode = detectFormatMode($header);

    if ($mode === 'comparison_17') {
        return [
            'mode' => 'comparison_17',
            'raw_header' => $header
        ];
    }

    $map = [
        'mode'       => 'standard_7',
        'branch'     => findColumnIndex($header, ['branch', 'nama branch', 'cabang']),
        'pic_rac'    => findColumnIndex($header, ['pic rac', 'pic_rac', 'pic']),
        'persentase' => findColumnIndex($header, ['%bad data', '% bad data', 'bad data', 'persentase', 'persentase bad data', '% bad_data']),
        'average'    => findColumnIndex($header, ['avarage', 'average', 'rata-rata', 'avg']),
        'status'     => findColumnIndex($header, ['status']),
        'month'      => findColumnIndex($header, ['posisi bulan', 'bulan', 'month', 'periode', 'posisi']),
        'region'     => findColumnIndex($header, ['region', 'wilayah', 'kanwil', 'ro'])
    ];

    $missing = [];
    foreach (['branch', 'pic_rac', 'persentase'] as $req) {
        if ($map[$req] === null) {
            $missing[] = strtoupper($req);
        }
    }

    if (!empty($missing)) {
        throw new RuntimeException('Kolom tidak sesuai. Kolom wajib: ' . implode(', ', $missing));
    }

    return $map;
}

function saveBadDataRows(PDO $pdo, array $rows, array $map): array {
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $isComparison = ($map['mode'] ?? '') === 'comparison_17';

    $checkStmt = $pdo->prepare('SELECT id FROM bad_data WHERE branch = :branch LIMIT 1');

    $updateStmt = $pdo->prepare('
        UPDATE bad_data
        SET no_urut = :no_urut,
            pic_rac = :pic_rac,
            total_cif = :total_cif,
            total = :total,
            reguler = :reguler,
            kerjasama = :kerjasama,
            bad_data_prev = :bad_data_prev,
            persentase_prev = :persentase_prev,
            bad_data_curr = :bad_data_curr,
            persentase_curr = :persentase_curr,
            persentase = :persentase,
            average = :average,
            perbaikan_bad_data = :perbaikan_bad_data,
            bad_data_baru = :bad_data_baru,
            keterangan = :keterangan,
            status = :status,
            month = :month,
            region = :region
        WHERE branch = :branch
    ');

    $insertStmt = $pdo->prepare('
        INSERT INTO bad_data (
            no_urut, branch, pic_rac, total_cif, total, reguler, kerjasama,
            bad_data_prev, persentase_prev, bad_data_curr, persentase_curr,
            persentase, average, perbaikan_bad_data, bad_data_baru, keterangan,
            status, month, region
        ) VALUES (
            :no_urut, :branch, :pic_rac, :total_cif, :total, :reguler, :kerjasama,
            :bad_data_prev, :persentase_prev, :bad_data_curr, :persentase_curr,
            :persentase, :average, :perbaikan_bad_data, :bad_data_baru, :keterangan,
            :status, :month, :region
        )
    ');

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ($isComparison) {
            $noUrut           = trim((string)($row[0] ?? ''));
            $branch           = trim((string)($row[1] ?? ''));
            $badDataPrev      = normalizeNumber($row[2] ?? null);
            $totalCifPrev     = normalizeNumber($row[3] ?? null);
            $totalPrev        = normalizeNumber($row[4] ?? null);
            $regulerPrev      = normalizeNumber($row[5] ?? null);
            $kerjasamaPrev    = normalizeNumber($row[6] ?? null);
            $persentasePrev   = normalizeNumber($row[7] ?? null);
            $badDataCurr      = normalizeNumber($row[8] ?? null);
            $totalCifCurr     = normalizeNumber($row[9] ?? null);
            $totalCurr        = normalizeNumber($row[10] ?? null);
            $regulerCurr      = normalizeNumber($row[11] ?? null);
            $kerjasamaCurr    = normalizeNumber($row[12] ?? null);
            $persentaseCurr   = normalizeNumber($row[13] ?? null);
            $perbaikanBadData = normalizeNumber($row[14] ?? null);
            $badDataBaru      = normalizeNumber($row[15] ?? null);
            $keterangan       = trim((string)($row[16] ?? ''));

            if ($branch === '' && $noUrut === '') {
                continue;
            }
            if ($branch === '') {
                $skipped++;
                continue;
            }

            $totalCif = $totalCifCurr ?? $totalCifPrev;
            $total    = $totalCurr ?? $totalPrev;
            $reguler  = $regulerCurr ?? $regulerPrev;
            $kerjasama = $kerjasamaCurr ?? $kerjasamaPrev;
            $persentase = $persentaseCurr ?? $persentasePrev;
            $average = $persentasePrev ?? $persentaseCurr;
            $picRac = 'Kantor Cabang';
            $status = ($perbaikanBadData !== null && (float)$perbaikanBadData > 0) ? 'Done' : 'Open';
            $month = date('F Y');
            $region = '-';
        } else {
            $noUrut           = null;
            $branch           = trim((string)($row[$map['branch']] ?? ''));
            $picRac           = trim((string)($row[$map['pic_rac']] ?? ''));
            $persentase       = normalizeNumber($row[$map['persentase']] ?? null);
            $average          = normalizeNumber($row[$map['average']] ?? null);
            $status           = trim((string)($row[$map['status']] ?? 'Open'));
            $month            = trim((string)($row[$map['month']] ?? ''));
            $region           = trim((string)($row[$map['region']] ?? ''));
            $totalCif         = null;
            $total            = null;
            $reguler          = null;
            $kerjasama        = null;
            $badDataPrev      = null;
            $persentasePrev   = null;
            $badDataCurr      = null;
            $persentaseCurr   = $persentase;
            $perbaikanBadData = null;
            $badDataBaru      = null;
            $keterangan       = null;

            if ($branch === '' && $picRac === '') {
                continue;
            }
            if ($branch === '') {
                $skipped++;
                continue;
            }
        }

        $checkStmt->execute([':branch' => $branch]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $params = [
            ':no_urut'            => $noUrut,
            ':branch'             => $branch,
            ':pic_rac'            => $picRac,
            ':total_cif'          => $totalCif,
            ':total'              => $total,
            ':reguler'            => $reguler,
            ':kerjasama'          => $kerjasama,
            ':bad_data_prev'      => $badDataPrev,
            ':persentase_prev'    => $persentasePrev,
            ':bad_data_curr'      => $badDataCurr,
            ':persentase_curr'    => $persentaseCurr,
            ':persentase'         => $persentase,
            ':average'            => $average,
            ':perbaikan_bad_data' => $perbaikanBadData,
            ':bad_data_baru'      => $badDataBaru,
            ':keterangan'         => $keterangan,
            ':status'             => $status !== '' ? $status : 'Open',
            ':month'              => $month,
            ':region'             => $region
        ];

        if ($existing) {
            $updateStmt->execute($params);
            $updated++;
        } else {
            $insertStmt->execute($params);
            $inserted++;
        }
    }

    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
}

// --------------------------------------------------------------------------
// POST: Import handler
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'import') {
    try {
        $rows = [];

        if (isset($_FILES['file']) && is_array($_FILES['file'])) {
            $file = $_FILES['file'];
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(false, 'Gagal mengunggah file.');
            }

            $originalName = (string)($file['name'] ?? '');
            $tmpName = (string)($file['tmp_name'] ?? '');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ((int)($file['size'] ?? 0) > 15 * 1024 * 1024) {
                jsonResponse(false, 'Ukuran file maksimal 15MB.');
            }

            if ($extension === 'xlsx') {
                $rows = parseXlsxFile($tmpName);
            } elseif ($extension === 'csv' || $extension === 'txt') {
                $content = (string)file_get_contents($tmpName);
                if (trim($content) === '') {
                    jsonResponse(false, 'File kosong atau tidak dapat dibaca.');
                }
                $rows = parseDelimitedText($content);
            } else {
                jsonResponse(false, 'Format file tidak didukung. Harap gunakan berkas CSV, TXT, atau XLSX.');
            }
        } elseif (isset($_POST['raw_data'])) {
            $rawData = trim((string)$_POST['raw_data']);
            if ($rawData === '') {
                jsonResponse(false, 'Teks yang ditempel masih kosong.');
            }
            $rows = parseDelimitedText($rawData);
        } else {
            jsonResponse(false, 'Tidak ada file atau data teks yang dikirimkan.');
        }

        if (empty($rows)) {
            jsonResponse(false, 'Tidak ada baris data yang ditemukan.');
        }

        $header = array_shift($rows);
        if (!is_array($header) || empty($header)) {
            jsonResponse(false, 'Baris header tidak ditemukan.');
        }

        $map = getBadDataColumnMap($header);

        $pdo->beginTransaction();
        $result = saveBadDataRows($pdo, $rows, $map);
        $pdo->commit();

        $total = $result['inserted'] + $result['updated'];
        jsonResponse(
            true,
            "Import berhasil: {$total} data diproses ({$result['inserted']} baru, {$result['updated']} diperbarui, {$result['skipped']} dilewati).",
            [
                'imported' => $total,
                'inserted' => $result['inserted'],
                'updated'  => $result['updated'],
                'skipped'  => $result['skipped']
            ]
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(false, 'Import gagal: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// GET: Fetch list handler
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $filterStatus = trim((string)($_GET['status'] ?? ''));
        $filterMonth = trim((string)($_GET['month'] ?? ''));
        $filterRegion = trim((string)($_GET['region'] ?? ''));
        $search = trim((string)($_GET['q'] ?? ''));

        $where = [];
        $params = [];

        if ($filterStatus !== '') {
            $where[] = 'LOWER(TRIM(status)) = :status';
            $params[':status'] = strtolower($filterStatus);
        }

        if ($filterMonth !== '') {
            $where[] = 'month LIKE :month';
            $params[':month'] = '%' . $filterMonth . '%';
        }

        if ($filterRegion !== '') {
            $where[] = 'region LIKE :region';
            $params[':region'] = '%' . $filterRegion . '%';
        }

        if ($search !== '') {
            $where[] = '(branch LIKE :search OR pic_rac LIKE :search OR keterangan LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM bad_data {$whereSql} ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Data berhasil diambil.', ['data' => $data, 'total' => count($data)]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal mengambil data: ' . $e->getMessage());
    }
}

jsonResponse(false, 'Metode request tidak didukung.');
