<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/regions.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && PHP_SAPI !== 'cli') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan. Gunakan POST.']);
    exit;
}

try {
    $pdo = getDbConnection();

    // 1. Collect files to process
    $tempExtractionDir = null;
    $filesToProcess = [];

    // Check if zip_file is uploaded
    if (!empty($_FILES['zip_file']['tmp_name']) && (is_uploaded_file($_FILES['zip_file']['tmp_name']) || PHP_SAPI === 'cli')) {
        $zipPath = $_FILES['zip_file']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Gagal membuka berkas ZIP yang diunggah.');
        }

        $tempExtractionDir = sys_get_temp_dir() . '/batch_import_' . uniqid();
        mkdir($tempExtractionDir, 0777, true);
        $zip->extractTo($tempExtractionDir);
        $zip->close();

        // Scan all .xlsx files in the extracted directory
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempExtractionDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $ext = strtolower($item->getExtension());
                $name = $item->getBasename();
                // Filter only valid Excel files, skip temporary files (~$...)
                if (($ext === 'xlsx' || $ext === 'xls') && !str_starts_with($name, '~$')) {
                    $filesToProcess[] = [
                        'name' => $name,
                        'path' => $item->getRealPath(),
                    ];
                }
            }
        }
    }
    // Check if multiple excel_files uploaded (from folder picker or multi-select)
    elseif (!empty($_FILES['excel_files']['name'])) {
        $names = (array)$_FILES['excel_files']['name'];
        $tmpNames = (array)$_FILES['excel_files']['tmp_name'];
        $errors = (array)$_FILES['excel_files']['error'];

        foreach ($names as $idx => $name) {
            if (($errors[$idx] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($tmpNames[$idx]) && (is_uploaded_file($tmpNames[$idx]) || PHP_SAPI === 'cli')) {

                $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
                if (($ext === 'xlsx' || $ext === 'xls') && !str_starts_with((string)$name, '~$')) {
                    $filesToProcess[] = [
                        'name' => (string)$name,
                        'path' => (string)$tmpNames[$idx],
                    ];
                }
            }
        }
    }

    if (empty($filesToProcess)) {
        throw new RuntimeException('Tidak ada berkas Excel (.xlsx) yang ditemukan untuk diproses.');
    }

    // Sort files alphabetically by name
    usort($filesToProcess, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    // 2. Prepare processors and statements
    $results = [];
    $totalSaved = 0;
    $moduleBreakdown = [
        'bad_data'         => 0,
        'pengkinian_data'  => 0,
        'uji_petik'        => 0,
        'nilai_maturitas'  => 0,
        'penilaian_resiko' => 0,
    ];

    foreach ($filesToProcess as $fileItem) {
        $fileName = $fileItem['name'];
        $filePath = $fileItem['path'];

        $fileReport = [
            'filename' => $fileName,
            'region'   => '-',
            'status'   => 'success',
            'modules'  => [],
            'error'    => null,
        ];

        try {
            // Detect Region from filename
            $detectedRegion = detectRegionFromFilename($fileName);
            $fileReport['region'] = $detectedRegion;

            // Process the workbook
            $modStats = processSingleExcelWorkbook($pdo, $filePath, $detectedRegion);
            $fileReport['modules'] = $modStats;

            foreach ($modStats as $mName => $count) {
                if (isset($moduleBreakdown[$mName])) {
                    $moduleBreakdown[$mName] += $count;
                    $totalSaved += $count;
                }
            }
        } catch (Throwable $e) {
            $fileReport['status'] = 'partial_error';
            $fileReport['error'] = $e->getMessage();
        }

        $results[] = $fileReport;
    }

    // Clean up temporary extracted folder if exists
    if ($tempExtractionDir && is_dir($tempExtractionDir)) {
        deleteDirRecursively($tempExtractionDir);
    }

    // Run database self-healing routines
    ensureSchemaExists($pdo);

    echo json_encode([
        'success' => true,
        'message' => 'Berhasil memproses ' . count($results) . ' berkas Excel Kanwil.',
        'data'    => [
            'total_files'       => count($results),
            'total_rows_saved'  => $totalSaved,
            'modules_breakdown' => $moduleBreakdown,
            'files'             => $results,
        ]
    ]);

} catch (Throwable $e) {
    if (isset($tempExtractionDir) && is_dir($tempExtractionDir)) {
        deleteDirRecursively($tempExtractionDir);
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

/**
 * Detect regional office from filename
 */
function detectRegionFromFilename(string $filename): string {
    $clean = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
    return formatRegionName($clean);
}

/**
 * Process a single workbook with all 5 sheets
 */
function processSingleExcelWorkbook(PDO $pdo, string $filePath, string $defaultRegion): array {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException("Gagal membuka file Excel: " . basename($filePath));
    }

    // 1. Shared Strings
    $sharedStrings = [];
    $sXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sXml !== false) {
        $xml = @simplexml_load_string($sXml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $t = '';
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                    $sharedStrings[] = $t;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    // 2. Sheet Mapping
    $wbXmlStr = $zip->getFromName('xl/workbook.xml');
    $relsXmlStr = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $sheetMap = [];

    if ($wbXmlStr !== false && $relsXmlStr !== false) {
        $wbXml = @simplexml_load_string($wbXmlStr);
        $relsXml = @simplexml_load_string($relsXmlStr);

        $relIdToTarget = [];
        if ($relsXml && isset($relsXml->Relationship)) {
            foreach ($relsXml->Relationship as $rel) {
                $id = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $relIdToTarget[$id] = $target;
            }
        }

        if ($wbXml && isset($wbXml->sheets->sheet)) {
            foreach ($wbXml->sheets->sheet as $s) {
                $name = trim((string)$s['name']);
                $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (isset($relIdToTarget[$rId])) {
                    $sheetMap[$name] = $relIdToTarget[$rId];
                }
            }
        }
    }

    $modStats = [
        'bad_data'         => 0,
        'pengkinian_data'  => 0,
        'uji_petik'        => 0,
        'nilai_maturitas'  => 0,
        'penilaian_resiko' => 0,
    ];

    // Helper to find sheet path by keyword
    $findSheetPath = function(array $keywords) use ($sheetMap, $zip): ?string {
        foreach ($keywords as $kw) {
            $kwLower = strtolower(trim($kw));
            foreach ($sheetMap as $sName => $sPath) {
                if (str_contains(strtolower(trim($sName)), $kwLower)) {
                    return $sPath;
                }
            }
        }
        return null;
    };

    // Helper to extract rows from sheet xml
    $extractSheetRows = function(string $sheetPath) use ($zip, $sharedStrings): array {
        $xmlStr = $zip->getFromName($sheetPath);
        if ($xmlStr === false) return [];
        $xml = @simplexml_load_string($xmlStr);
        if (!$xml || !isset($xml->sheetData->row)) return [];

        $rows = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $row = [];
            $lastCol = 0;
            foreach ($rowNode->c as $cell) {
                $ref = (string)$cell['r'];
                preg_match('/^([A-Z]+)/', $ref, $m);
                $colLetters = $m[1] ?? 'A';
                $colIdx = 0;
                for ($k = 0; $k < strlen($colLetters); $k++) {
                    $colIdx = $colIdx * 26 + (ord($colLetters[$k]) - ord('A') + 1);
                }
                while ($lastCol < $colIdx - 1) {
                    $row[] = '';
                    $lastCol++;
                }
                $t = (string)($cell['t'] ?? '');
                $v = isset($cell->v) ? (string)$cell->v : '';
                if ($t === 's') {
                    $row[] = $sharedStrings[(int)$v] ?? '';
                } elseif ($t === 'inlineStr' && isset($cell->is->t)) {
                    $row[] = (string)$cell->is->t;
                } else {
                    $row[] = $v;
                }
                $lastCol = $colIdx;
            }
            $nonEmpty = array_filter($row, fn($val) => trim((string)$val) !== '');
            if (!empty($nonEmpty)) {
                $rows[] = $row;
            }
        }
        return $rows;
    };

    // 1. Process Bad Data
    $badSheetPath = $findSheetPath(['bad data', 'baddata', 'bad_data', 'bad']);
    if ($badSheetPath) {
        $rows = $extractSheetRows($badSheetPath);
        $modStats['bad_data'] = importRowsBadData($pdo, $rows, $defaultRegion);
    }

    // 2. Process Pengkinian Data
    $pengkinianSheetPath = $findSheetPath(['pengkinian data', 'pengkiniandata', 'pengkinian_data', 'pengkinian']);
    if ($pengkinianSheetPath) {
        $rows = $extractSheetRows($pengkinianSheetPath);
        $modStats['pengkinian_data'] = importRowsPengkinianData($pdo, $rows, $defaultRegion);
    }

    // 3. Process Uji Petik
    $ujiSheetPath = $findSheetPath(['uji petik', 'ujipetik', 'uji_petik', 'uji']);
    if ($ujiSheetPath) {
        $rows = $extractSheetRows($ujiSheetPath);
        // Find evidence link from sheet XML and rels
        $evidenceLink = '';
        $sheetXmlStr = $zip->getFromName($ujiSheetPath);
        if ($sheetXmlStr !== false) {
            if (preg_match('/(\*{1,3}https?:\/\/[^\s<"\']+|https?:\/\/[^\s<"\']+)/i', $sheetXmlStr, $lm)) {
                $evidenceLink = $lm[1];
            }
        }
        if (!$evidenceLink) {
            $sheetRelsPath = dirname($ujiSheetPath) . '/_rels/' . basename($ujiSheetPath) . '.rels';
            $relsXmlStr = $zip->getFromName($sheetRelsPath);
            if ($relsXmlStr !== false && preg_match('/(\*{1,3}https?:\/\/[^\s<"\']+|https?:\/\/[^\s<"\']+)/i', $relsXmlStr, $lm)) {
                $evidenceLink = $lm[1];
            }
        }
        $modStats['uji_petik'] = importRowsUjiPetik($pdo, $rows, $defaultRegion, $evidenceLink);
    }

    // 4. Process Nilai Maturitas
    $maturitasSheetPath = $findSheetPath(['nilai maturitas', 'nilaimaturitas', 'nilai_maturitas', 'maturitas']);
    if ($maturitasSheetPath) {
        $rows = $extractSheetRows($maturitasSheetPath);
        $modStats['nilai_maturitas'] = importRowsNilaiMaturitas($pdo, $rows, $defaultRegion);
    }

    // 5. Process Penilaian Resiko
    $resikoSheetPath = $findSheetPath(['penilaian resiko', 'penilaian risiko', 'penilaianresiko', 'penilaian_resiko', 'risiko', 'resiko']);
    if ($resikoSheetPath) {
        $rows = $extractSheetRows($resikoSheetPath);
        $modStats['penilaian_resiko'] = importRowsPenilaianResiko($pdo, $rows, $defaultRegion);
    }

    $zip->close();
    return $modStats;
}

/**
 * Helper to strip and normalize strings
 */
function cleanBatchStr(mixed $val): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$val)));
}

/**
 * Format percentage helper
 */
function formatBatchPercent(mixed $val): string {
    if ($val === null) return '-';
    $str = trim((string)$val);
    if ($str === '' || $str === '-') return '-';
    if (str_ends_with($str, '%')) return $str;
    if (is_numeric($str)) {
        $num = (float)$str;
        if ($num >= 0 && $num <= 1.0) $num *= 100;
        return round($num, 2) . '%';
    }
    return $str;
}

/**
 * Import Bad Data Rows
 */
function importRowsBadData(PDO $pdo, array $allRows, string $defaultRegion): int {
    if (count($allRows) < 2) return 0;
    // Find header row
    $headerRowIdx = 0;
    foreach (array_slice($allRows, 0, 5) as $i => $row) {
        foreach ($row as $cell) {
            $c = cleanBatchStr($cell);
            if (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'baddata')) {
                $headerRowIdx = $i;
                break 2;
            }
        }
    }
    $headers = $allRows[$headerRowIdx] ?? [];
    $dataRows = array_slice($allRows, $headerRowIdx + 1);

    // Find indices
    $colBranch = null; $colPic = null; $colPercent = null; $colAvg = null;
    foreach ($headers as $idx => $h) {
        $c = cleanBatchStr($h);
        if ($colBranch === null && (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'cabang'))) $colBranch = $idx;
        elseif ($colPic === null && (str_contains($c, 'pic') || str_contains($c, 'rac'))) $colPic = $idx;
        elseif ($colPercent === null && str_contains($c, 'baddata')) $colPercent = $idx;
        elseif ($colAvg === null && (str_contains($c, 'average') || str_contains($c, 'avg'))) $colAvg = $idx;
    }
    if ($colBranch === null) return 0;

    $stmtCheck = $pdo->prepare('SELECT id FROM bad_data WHERE branch = :branch LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE bad_data SET no_urut = :no_urut, pic_rac = :pic, persentase = :pct, average = :avg, month = :m, region = :reg WHERE branch = :branch');
    $stmtInsert = $pdo->prepare('INSERT INTO bad_data (no_urut, branch, pic_rac, persentase, average, month, region, status) VALUES (:no_urut, :branch, :pic, :pct, :avg, :m, :reg, :st)');

    $saved = 0;
    $lastAvg = '-';
    foreach ($dataRows as $rIdx => $row) {
        $branch = trim((string)($row[$colBranch] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch) || in_array(strtolower($branch), ['branch', 'unit kerja', 'total', 'average', 'jumlah'], true)) continue;

        $pic = ($colPic !== null) ? trim((string)($row[$colPic] ?? '-')) : '-';
        $pct = ($colPercent !== null) ? formatBatchPercent($row[$colPercent] ?? null) : '-';
        $avgRaw = ($colAvg !== null) ? trim((string)($row[$colAvg] ?? '')) : '';
        if ($avgRaw !== '' && $avgRaw !== '-') {
            $lastAvg = formatBatchPercent($avgRaw);
        }

        $stmtCheck->execute([':branch' => $branch]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':pic'     => $pic !== '' ? $pic : '-',
                ':pct'     => $pct,
                ':avg'     => $lastAvg,
                ':m'       => 'September',
                ':reg'     => $defaultRegion,
                ':branch'  => $branch,
            ]);
        } else {
            $stmtInsert->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':branch'  => $branch,
                ':pic'     => $pic !== '' ? $pic : '-',
                ':pct'     => $pct,
                ':avg'     => $lastAvg,
                ':m'       => 'September',
                ':reg'     => $defaultRegion,
                ':st'      => 'Active',
            ]);
        }
        $saved++;
    }
    return $saved;
}

/**
 * Import Pengkinian Data Rows
 */
function importRowsPengkinianData(PDO $pdo, array $allRows, string $defaultRegion): int {
    if (count($allRows) < 2) return 0;
    $headerRowIdx = 0;
    foreach (array_slice($allRows, 0, 5) as $i => $row) {
        foreach ($row as $cell) {
            $c = cleanBatchStr($cell);
            if (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'pengkinian')) {
                $headerRowIdx = $i;
                break 2;
            }
        }
    }
    $headers = $allRows[$headerRowIdx] ?? [];
    $dataRows = array_slice($allRows, $headerRowIdx + 1);

    $colBranch = null; $colPic = null; $colPercent = null; $colAvg = null;
    foreach ($headers as $idx => $h) {
        $c = cleanBatchStr($h);
        if ($colBranch === null && (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'cabang'))) $colBranch = $idx;
        elseif ($colPic === null && (str_contains($c, 'pic') || str_contains($c, 'rac'))) $colPic = $idx;
        elseif ($colPercent === null && str_contains($c, 'pengkinian')) $colPercent = $idx;
        elseif ($colAvg === null && (str_contains($c, 'average') || str_contains($c, 'avg'))) $colAvg = $idx;
    }
    if ($colBranch === null) return 0;

    $stmtCheck = $pdo->prepare('SELECT id FROM pengkinian_data WHERE branch = :branch LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE pengkinian_data SET no_urut = :no_urut, pic_rac = :pic, persentase = :pct, average = :avg, month = :m, region = :reg WHERE branch = :branch');
    $stmtInsert = $pdo->prepare('INSERT INTO pengkinian_data (no_urut, branch, pic_rac, persentase, average, month, region, status) VALUES (:no_urut, :branch, :pic, :pct, :avg, :m, :reg, :st)');

    $saved = 0;
    $lastAvg = '-';
    foreach ($dataRows as $rIdx => $row) {
        $branch = trim((string)($row[$colBranch] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch) || in_array(strtolower($branch), ['branch', 'unit kerja', 'total', 'average', 'jumlah'], true)) continue;

        $pic = ($colPic !== null) ? trim((string)($row[$colPic] ?? '-')) : '-';
        $pct = ($colPercent !== null) ? formatBatchPercent($row[$colPercent] ?? null) : '-';
        $avgRaw = ($colAvg !== null) ? trim((string)($row[$colAvg] ?? '')) : '';
        if ($avgRaw !== '' && $avgRaw !== '-') {
            $lastAvg = formatBatchPercent($avgRaw);
        }

        $stmtCheck->execute([':branch' => $branch]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':pic'     => $pic !== '' ? $pic : '-',
                ':pct'     => $pct,
                ':avg'     => $lastAvg,
                ':m'       => 'September',
                ':reg'     => $defaultRegion,
                ':branch'  => $branch,
            ]);
        } else {
            $stmtInsert->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':branch'  => $branch,
                ':pic'     => $pic !== '' ? $pic : '-',
                ':pct'     => $pct,
                ':avg'     => $lastAvg,
                ':m'       => 'September',
                ':reg'     => $defaultRegion,
                ':st'      => 'Active',
            ]);
        }
        $saved++;
    }
    return $saved;
}

/**
 * Helper to format Excel dates into Indonesian strings
 */
function formatBatchUjiPetikDate(mixed $val): string {
    if ($val === null) return '-';
    $str = trim((string)$val);
    if ($str === '' || $str === '-') return '-';
    if (is_numeric($str)) {
        $num = (float)$str;
        if ($num >= 1000 && $num <= 100000) {
            $unix = round(($num - 25569) * 86400);
            $bulanIndoMap = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            $d = (int)gmdate('j', $unix);
            $m = (int)gmdate('n', $unix);
            $y = (int)gmdate('Y', $unix);
            return "$d " . ($bulanIndoMap[$m] ?? gmdate('F', $unix)) . " $y";
        }
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $str, $m)) {
        $bulanIndoMap = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        return (int)$m[3] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[1];
    }
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $str, $m)) {
        $bulanIndoMap = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        return (int)$m[1] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[3];
    }
    return $str;
}

/**
 * Import Uji Petik Rows
 */
function importRowsUjiPetik(PDO $pdo, array $allRows, string $defaultRegion, string $evidenceLink = ''): int {
    if (count($allRows) < 2) return 0;
    $headerRowIdx = 0;
    foreach (array_slice($allRows, 0, 5) as $i => $row) {
        foreach ($row as $cell) {
            $c = cleanBatchStr($cell);
            if (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'rencana')) {
                $headerRowIdx = $i;
                break 2;
            }
        }
    }
    $headers = $allRows[$headerRowIdx] ?? [];
    $dataRows = array_slice($allRows, $headerRowIdx + 1);

    $colBranch = null; $colPic = null; $colRencana = null; $colTgl = null; $colDok = null;
    foreach ($headers as $idx => $h) {
        $c = cleanBatchStr($h);
        if ($colBranch === null && (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'cabang'))) $colBranch = $idx;
        elseif ($colPic === null && (str_contains($c, 'pic') || str_contains($c, 'rac'))) $colPic = $idx;
        elseif ($colRencana === null && str_contains($c, 'rencana')) $colRencana = $idx;
        elseif ($colTgl === null && (str_contains($c, 'tanggal') || str_contains($c, 'tgl') || str_contains($c, 'pelaksanaan'))) $colTgl = $idx;
        elseif ($colDok === null && (str_contains($c, 'dokumen') || str_contains($c, 'evidence'))) $colDok = $idx;
    }
    if ($colBranch === null) return 0;

    $stmtCheck = $pdo->prepare('SELECT id FROM uji_petik WHERE branch = :branch LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE uji_petik SET no_urut = :no_urut, pic_rac = :pic, rencana_pelaksanaan = :rencana, tanggal_uji_petik = :tgl, dokumen = :dok, keterangan = :ket, status = :st, month = :m, region = :reg WHERE branch = :branch');
    $stmtInsert = $pdo->prepare('INSERT INTO uji_petik (no_urut, branch, pic_rac, rencana_pelaksanaan, tanggal_uji_petik, dokumen, keterangan, status, month, region) VALUES (:no_urut, :branch, :pic, :rencana, :tgl, :dok, :ket, :st, :m, :reg)');

    $saved = 0;
    foreach ($dataRows as $rIdx => $row) {
        $rawBranch = trim((string)($row[$colBranch] ?? ''));
        // Garbage footnote check
        if ($rawBranch === '' || $rawBranch === '-' || is_numeric($rawBranch) || str_starts_with($rawBranch, '*') || str_starts_with($rawBranch, '-') || str_starts_with($rawBranch, '(') || str_contains(strtolower($rawBranch), 'evidence') || str_contains(strtolower($rawBranch), 'keterangan') || str_contains(strtolower($rawBranch), 'http')) continue;
        if (preg_match('/^[0-9]+[\.\)]/i', $rawBranch) && !preg_match('/\b(kc|bo|kcp|unit|cabang)\b/i', $rawBranch)) continue;

        // Canonical branch
        $branch = preg_replace('/^BO\s+/i', 'KC ', $rawBranch);

        $pic = ($colPic !== null) ? trim((string)($row[$colPic] ?? '-')) : '-';
        $rawRencana = ($colRencana !== null) ? trim((string)($row[$colRencana] ?? '-')) : '-';
        $rawTgl = ($colTgl !== null) ? trim((string)($row[$colTgl] ?? '-')) : '-';
        $rencana = formatBatchUjiPetikDate($rawRencana);
        $tgl = formatBatchUjiPetikDate($rawTgl);

        // Dokumen: prioritize evidenceLink from footnote
        $dok = !empty($evidenceLink) ? $evidenceLink : (($colDok !== null && !empty($row[$colDok])) ? trim((string)$row[$colDok]) : '-');

        // Check extra note/keterangan in row
        $keterangan = '';
        foreach ($row as $cIdx => $cVal) {
            $cTrim = trim((string)$cVal);
            if ($cIdx >= 5 && (str_starts_with(strtolower($cTrim), 'note') || strlen($cTrim) > 10)) {
                $keterangan = $cTrim;
                break;
            }
        }

        $hasRencana = ($rencana !== '' && $rencana !== '-');
        $hasTgl = ($tgl !== '' && $tgl !== '-');
        if (($hasRencana && $hasTgl) || $hasTgl) {
            $keterangan = 'Telah dilaksanakan';
            $status = 'Done';
        } else {
            $status = 'Not Done';
        }

        $month = 'September';
        $searchDates = $tgl . ' ' . $rencana;
        $indoMonths = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        foreach ($indoMonths as $im) {
            if (stripos($searchDates, $im) !== false) {
                $month = $im;
                break;
            }
        }

        $stmtCheck->execute([':branch' => $branch]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':pic'     => $pic !== '' ? $pic : '-',
                ':rencana' => $rencana !== '' ? $rencana : '-',
                ':tgl'     => $tgl !== '' ? $tgl : '-',
                ':dok'     => $dok !== '' ? $dok : '-',
                ':ket'     => $keterangan,
                ':st'      => $status,
                ':m'       => $month,
                ':reg'     => $defaultRegion,
                ':branch'  => $branch,
            ]);
        } else {
            $stmtInsert->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':branch'  => $branch,
                ':pic'     => $pic !== '' ? $pic : '-',
                ':rencana' => $rencana !== '' ? $rencana : '-',
                ':tgl'     => $tgl !== '' ? $tgl : '-',
                ':dok'     => $dok !== '' ? $dok : '-',
                ':ket'     => $keterangan,
                ':st'      => $status,
                ':m'       => $month,
                ':reg'     => $defaultRegion,
            ]);
        }
        $saved++;
    }
    return $saved;
}

/**
 * Import Nilai Maturitas Rows
 */
function importRowsNilaiMaturitas(PDO $pdo, array $allRows, string $defaultRegion): int {
    if (count($allRows) < 2) return 0;
    $headerRowIdx = 0;
    foreach (array_slice($allRows, 0, 5) as $i => $row) {
        foreach ($row as $cell) {
            $c = cleanBatchStr($cell);
            if (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'maturitas')) {
                $headerRowIdx = $i;
                break 2;
            }
        }
    }
    $headers = $allRows[$headerRowIdx] ?? [];
    $dataRows = array_slice($allRows, $headerRowIdx + 1);

    $colBranch = null; $colPic = null; $colScore = null; $colAvg = null; $colRating = null;
    $maturitasCount = 0;
    foreach ($headers as $idx => $h) {
        $c = cleanBatchStr($h);
        if ($colBranch === null && (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'cabang'))) $colBranch = $idx;
        elseif ($colPic === null && (str_contains($c, 'pic') || str_contains($c, 'rac'))) $colPic = $idx;
        elseif (str_contains($c, 'maturitas')) {
            if (str_contains($c, 'rating')) {
                $colRating = $idx;
            } else {
                $maturitasCount++;
                if ($maturitasCount === 1) $colScore = $idx;
                elseif ($maturitasCount === 2) $colAvg = $idx;
            }
        }
    }
    if ($colBranch === null) return 0;

    $stmtCheck = $pdo->prepare('SELECT id FROM nilai_maturitas WHERE branch = :branch LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE nilai_maturitas SET no_urut = :no_urut, pic_rac = :pic, nilai_maturitas = :score, average = :avg, rating_maturitas = :rating, month = :m, region = :reg WHERE branch = :branch');
    $stmtInsert = $pdo->prepare('INSERT INTO nilai_maturitas (no_urut, branch, pic_rac, nilai_maturitas, average, rating_maturitas, month, region) VALUES (:no_urut, :branch, :pic, :score, :avg, :rating, :m, :reg)');

    $saved = 0;
    $lastAvg = '0';
    $lastRating = 'Cukup';

    foreach ($dataRows as $rIdx => $row) {
        $branch = trim((string)($row[$colBranch] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch) || in_array(strtolower($branch), ['branch', 'unit kerja', 'total', 'average', 'jumlah'], true)) continue;

        $pic = ($colPic !== null) ? trim((string)($row[$colPic] ?? '-')) : '-';
        $scoreRaw = ($colScore !== null) ? trim((string)($row[$colScore] ?? '0')) : '0';
        $score = is_numeric($scoreRaw) ? (string)round((float)$scoreRaw, 2) : '0';

        $avgRaw = ($colAvg !== null) ? trim((string)($row[$colAvg] ?? '')) : '';
        if ($avgRaw !== '' && $avgRaw !== '-' && is_numeric($avgRaw)) {
            $lastAvg = (string)round((float)$avgRaw, 2);
        }

        $ratingRaw = ($colRating !== null) ? trim((string)($row[$colRating] ?? '')) : '';
        if ($ratingRaw !== '' && $ratingRaw !== '-') {
            $u = strtoupper($ratingRaw);
            if ($u === 'A' || str_contains($u, 'SANGAT')) $lastRating = 'Sangat Baik';
            elseif ($u === 'B' || str_contains($u, 'BAIK')) $lastRating = 'Baik';
            elseif ($u === 'C' || str_contains($u, 'CUKUP')) $lastRating = 'Cukup';
            elseif ($u === 'D' || $u === 'E' || str_contains($u, 'KURANG')) $lastRating = 'Kurang';
        } else {
            // Compute from score if rating is empty
            $numScore = (float)$score;
            if ($numScore >= 8.0) $lastRating = 'Sangat Baik';
            elseif ($numScore >= 6.5) $lastRating = 'Baik';
            elseif ($numScore >= 4.0) $lastRating = 'Cukup';
            else $lastRating = 'Kurang';
        }

        $stmtCheck->execute([':branch' => $branch]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':pic'     => $pic !== '' ? $pic : '-',
                ':score'   => $score,
                ':avg'     => $lastAvg,
                ':rating'  => $lastRating,
                ':m'       => 'Agustus',
                ':reg'     => $defaultRegion,
                ':branch'  => $branch,
            ]);
        } else {
            $stmtInsert->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':branch'  => $branch,
                ':pic'     => $pic !== '' ? $pic : '-',
                ':score'   => $score,
                ':avg'     => $lastAvg,
                ':rating'  => $lastRating,
                ':m'       => 'Agustus',
                ':reg'     => $defaultRegion,
            ]);
        }
        $saved++;
    }
    return $saved;
}

/**
 * Import Penilaian Resiko Rows
 */
function importRowsPenilaianResiko(PDO $pdo, array $allRows, string $defaultRegion): int {
    if (count($allRows) < 2) return 0;
    $headerRowIdx = 0;
    foreach (array_slice($allRows, 0, 5) as $i => $row) {
        foreach ($row as $cell) {
            $c = cleanBatchStr($cell);
            if (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'risiko') || str_contains($c, 'resiko')) {
                $headerRowIdx = $i;
                break 2;
            }
        }
    }
    $headers = $allRows[$headerRowIdx] ?? [];
    $dataRows = array_slice($allRows, $headerRowIdx + 1);

    $colBranch = null; $colPic = null; $colScore = null; $colAvg = null; $colRating = null;
    $riskCount = 0;
    foreach ($headers as $idx => $h) {
        $c = cleanBatchStr($h);
        if ($colBranch === null && (str_contains($c, 'unitkerja') || str_contains($c, 'branch') || str_contains($c, 'cabang'))) $colBranch = $idx;
        elseif ($colPic === null && (str_contains($c, 'pic') || str_contains($c, 'rac'))) $colPic = $idx;
        elseif (str_contains($c, 'risiko') || str_contains($c, 'resiko')) {
            if (str_contains($c, 'rating') || str_contains($c, 'level')) {
                $colRating = $idx;
            } else {
                $riskCount++;
                if ($riskCount === 1) $colScore = $idx;
                elseif ($riskCount === 2) $colAvg = $idx;
            }
        }
    }
    if ($colBranch === null) return 0;

    $stmtCheck = $pdo->prepare('SELECT id FROM penilaian_resiko WHERE branch = :branch LIMIT 1');
    $stmtUpdate = $pdo->prepare('UPDATE penilaian_resiko SET no_urut = :no_urut, pic_rac = :pic, nilai_resiko = :score, nilai_risiko_pic = :avg, level_risiko = :lvl, month = :m, region = :reg WHERE branch = :branch');
    $stmtInsert = $pdo->prepare('INSERT INTO penilaian_resiko (no_urut, branch, pic_rac, nilai_resiko, nilai_risiko_pic, level_risiko, month, region) VALUES (:no_urut, :branch, :pic, :score, :avg, :lvl, :m, :reg)');

    $saved = 0;
    $lastAvg = '0';
    $lastLvl = 'Medium';

    foreach ($dataRows as $rIdx => $row) {
        $branch = trim((string)($row[$colBranch] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch) || in_array(strtolower($branch), ['branch', 'unit kerja', 'total', 'average', 'jumlah'], true)) continue;

        $pic = ($colPic !== null) ? trim((string)($row[$colPic] ?? '-')) : '-';
        $scoreRaw = ($colScore !== null) ? trim((string)($row[$colScore] ?? '0')) : '0';
        $score = is_numeric($scoreRaw) ? (string)round((float)$scoreRaw, 2) : '0';

        $avgRaw = ($colAvg !== null) ? trim((string)($row[$colAvg] ?? '')) : '';
        if ($avgRaw !== '' && $avgRaw !== '-' && is_numeric($avgRaw)) {
            $lastAvg = (string)round((float)$avgRaw, 2);
        }

        $lvlRaw = ($colRating !== null) ? trim((string)($row[$colRating] ?? '')) : '';
        if ($lvlRaw !== '' && $lvlRaw !== '-') {
            $u = strtoupper($lvlRaw);
            if (str_contains($u, 'LARGE') || str_contains($u, 'TINGGI') || $u === 'L') $lastLvl = 'Large';
            elseif (str_contains($u, 'MEDIUM') || str_contains($u, 'SEDANG') || $u === 'M') $lastLvl = 'Medium';
            elseif (str_contains($u, 'SMALL') || str_contains($u, 'RENDAH') || $u === 'S') $lastLvl = 'Small';
        } else {
            $numScore = (float)$score;
            if ($numScore >= 3.5) $lastLvl = 'Large';
            elseif ($numScore >= 2.0) $lastLvl = 'Medium';
            else $lastLvl = 'Small';
        }

        $stmtCheck->execute([':branch' => $branch]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':pic'     => $pic !== '' ? $pic : '-',
                ':score'   => $score,
                ':avg'     => $lastAvg,
                ':lvl'     => $lastLvl,
                ':m'       => 'Agustus',
                ':reg'     => $defaultRegion,
                ':branch'  => $branch,
            ]);
        } else {
            $stmtInsert->execute([
                ':no_urut' => (string)($rIdx + 1),
                ':branch'  => $branch,
                ':pic'     => $pic !== '' ? $pic : '-',
                ':score'   => $score,
                ':avg'     => $lastAvg,
                ':lvl'     => $lastLvl,
                ':m'       => 'Agustus',
                ':reg'     => $defaultRegion,
            ]);
        }
        $saved++;
    }
    return $saved;
}

/**
 * Recursively delete a directory
 */
function deleteDirRecursively(string $dir): void {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($files as $file) {
        $p = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($p) ? deleteDirRecursively($p) : @unlink($p);
    }
    @rmdir($dir);
}
