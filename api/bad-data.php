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

function stripAlphanumeric(mixed $value): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$value)));
}

function findColumnIndex(array $headers, array $possibleNames): ?int {
    // 1. Exact normalized match
    foreach ($possibleNames as $possibleName) {
        $normalizedTarget = normalizeHeader($possibleName);
        foreach ($headers as $index => $header) {
            if (normalizeHeader($header) === $normalizedTarget) {
                return (int)$index;
            }
        }
    }
    // 2. Alphanumeric stripped exact match
    foreach ($possibleNames as $possibleName) {
        $stripTarget = stripAlphanumeric($possibleName);
        if ($stripTarget === '') continue;
        foreach ($headers as $index => $header) {
            if (stripAlphanumeric($header) === $stripTarget) {
                return (int)$index;
            }
        }
    }
    // 3. Substring / contains match
    foreach ($possibleNames as $possibleName) {
        $stripTarget = stripAlphanumeric($possibleName);
        if (strlen($stripTarget) < 3) continue;
        foreach ($headers as $index => $header) {
            $hStrip = stripAlphanumeric($header);
            if (str_contains($hStrip, $stripTarget)) {
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
function parseXlsxFile(string $filePath, array $preferredSheetNames = ['bad data', 'baddata', 'bad_data', 'bad']): array {
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

    // Map sheet names to sheet XML targets using workbook.xml and workbook.xml.rels
    $sheetMap = [];
    $wbXmlStr = $zip->getFromName('xl/workbook.xml');
    $relsXmlStr = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($wbXmlStr !== false && $relsXmlStr !== false) {
        $wbXml = simplexml_load_string($wbXmlStr);
        $relsXml = simplexml_load_string($relsXmlStr);

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

    // Select the best worksheet
    $selectedSheetPath = null;
    if (!empty($preferredSheetNames) && !empty($sheetMap)) {
        foreach ($preferredSheetNames as $pref) {
            $prefLower = strtolower(trim($pref));
            foreach ($sheetMap as $sName => $sPath) {
                if (strtolower(trim($sName)) === $prefLower) {
                    $selectedSheetPath = $sPath;
                    break 2;
                }
            }
        }
        if (!$selectedSheetPath) {
            foreach ($preferredSheetNames as $pref) {
                $prefLower = strtolower(trim($pref));
                foreach ($sheetMap as $sName => $sPath) {
                    $sNameLower = strtolower(trim($sName));
                    if (str_contains($sNameLower, $prefLower) || str_contains($prefLower, $sNameLower)) {
                        $selectedSheetPath = $sPath;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$selectedSheetPath && !empty($sheetMap)) {
        $selectedSheetPath = reset($sheetMap);
    }

    if (!$selectedSheetPath) {
        $candidates = ['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml', 'xl/worksheets/sheet.xml'];
        foreach ($candidates as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                $selectedSheetPath = $candidate;
                break;
            }
        }
    }

    if (!$selectedSheetPath) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/.*\.xml$#i', $entry)) {
                $selectedSheetPath = $entry;
                break;
            }
        }
    }

    if (!$selectedSheetPath) {
        $zip->close();
        throw new RuntimeException('Worksheet tidak ditemukan dalam file Excel.');
    }

    $sheetXmlStr = $zip->getFromName($selectedSheetPath);
    $zip->close();

    if ($sheetXmlStr === false) {
        throw new RuntimeException("Gagal membaca worksheet: {$selectedSheetPath}");
    }

    $xml = simplexml_load_string($sheetXmlStr);
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

            $type = (string)($cell['t'] ?? '');
            $val = isset($cell->v) ? (string)$cell->v : '';

            if ($type === 's' && is_numeric($val) && isset($sharedStrings[(int)$val])) {
                $val = $sharedStrings[(int)$val];
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $val = (string)$cell->is->t;
            }

            $row[] = trim((string)$val);
            $lastColIdx = $colIdx;
        }

        if (!empty(array_filter($row, fn($c) => $c !== ''))) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function resolveBadDataHeadersAndRows(array $allRows): array {
    if (empty($allRows)) {
        return [[], []];
    }

    $headerKeywords = [
        'branch', 'cabang', 'kantor cabang', 'unit kerja', 'branch office', 'uker',
        'pic rac', 'pic_rac', 'pic', 'disposisi rac', 'nama pic', 'disposisi', 'rac',
        '%bad data', '% bad data', 'bad data', 'persentase', 'average', 'rata-rata', 'no', 'cif'
    ];

    $headerRowIdx = 0;
    $maxMatches = 0;
    $scanLimit = min(10, count($allRows));

    for ($i = 0; $i < $scanLimit; $i++) {
        $row = $allRows[$i];
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $matches = 0;
        foreach ($row as $cell) {
            $cellStr = stripAlphanumeric($cell);
            if ($cellStr === '') {
                continue;
            }
            foreach ($headerKeywords as $kw) {
                if (str_contains($cellStr, stripAlphanumeric($kw))) {
                    $matches++;
                    break;
                }
            }
        }
        if ($matches > $maxMatches) {
            $maxMatches = $matches;
            $headerRowIdx = $i;
        }
    }

    $dataStartIdx = $headerRowIdx + 1;

    // Check if subsequent 1 or 2 rows are continuation of multi-tier headers (common in consolidation reports)
    if ($headerRowIdx + 1 < count($allRows)) {
        $nextRow = $allRows[$headerRowIdx + 1];
        $nextMatches = 0;
        foreach ($nextRow as $cell) {
            $cellStr = stripAlphanumeric($cell);
            if ($cellStr === '') {
                continue;
            }
            foreach ($headerKeywords as $kw) {
                if (str_contains($cellStr, stripAlphanumeric($kw))) {
                    $nextMatches++;
                    break;
                }
            }
        }
        if ($nextMatches >= 2) {
            $dataStartIdx = $headerRowIdx + 2;
            if ($headerRowIdx + 2 < count($allRows)) {
                $row2 = $allRows[$headerRowIdx + 2];
                $r2Matches = 0;
                foreach ($row2 as $cell) {
                    $cellStr = stripAlphanumeric($cell);
                    if ($cellStr === '') {
                        continue;
                    }
                    foreach ($headerKeywords as $kw) {
                        if (str_contains($cellStr, stripAlphanumeric($kw))) {
                            $r2Matches++;
                            break;
                        }
                    }
                }
                if ($r2Matches >= 2) {
                    $dataStartIdx = $headerRowIdx + 3;
                }
            }
        }
    }

    $maxCols = 0;
    for ($r = $headerRowIdx; $r < $dataStartIdx; $r++) {
        if (isset($allRows[$r])) {
            $maxCols = max($maxCols, count($allRows[$r]));
        }
    }

    $headers = [];
    for ($c = 0; $c < $maxCols; $c++) {
        $parts = [];
        for ($r = $headerRowIdx; $r < $dataStartIdx; $r++) {
            $val = trim((string)($allRows[$r][$c] ?? ''));
            if ($val !== '' && !in_array($val, $parts, true)) {
                $parts[] = $val;
            }
        }
        $headers[$c] = implode(' ', $parts);
    }

    $dataRows = array_slice($allRows, $dataStartIdx);
    return [$headers, $dataRows];
}

function getBadDataColumnMap(array $header): array {
    $map = [
        'no'                 => findColumnIndex($header, ['no', 'no.', 'no urut', 'no_urut', 'nomor', 'nomor urut']),
        'branch'             => findColumnIndex($header, [
            'kantor cabang', 'kanca', 'nama branch', 'nama cabang', 'branch office', 'unit kerja', 'nama uker', 'uker', 'branch', 'cabang'
        ]),
        'pic_rac'            => findColumnIndex($header, [
            'disposisi rac', 'disposisi_rac', 'pic rac', 'pic_rac', 'pic-rac', 'pic amlo', 'nama pic', 'rac officer', 'officer rac', 'pic', 'disposisi', 'rac'
        ]),
        'persentase'         => findColumnIndex($header, [
            '%bad data', '% bad data', '% bad_data', 'bad data (%)', 'persentase bad data', '% persentase bad data', '%persentase', 'persentase_curr', 'persentase curr', '% bad data curr', '% bad data (current)', 'persentase', '%bad_data', 'bad data', 'scoring'
        ]),
        'average'            => findColumnIndex($header, [
            'average', 'avarage', 'rata-rata', 'rata rata', 'avg', 'average bad data', 'avg bad data', 'rata-rata bad data', 'persentase_prev', 'persentase prev', '% bad data prev'
        ]),
        'status'             => findColumnIndex($header, ['status uker', 'status tl', 'status pelaksanaan', 'status']),
        'month'              => findColumnIndex($header, ['posisi bulan', 'bulan', 'month', 'periode', 'posisi', 'tanggal', 'tgl']),
        'region'             => findColumnIndex($header, ['kantor kanwil', 'kanwil', 'regional office', 'regional', 'region', 'wilayah', 'ro']),
        'total_cif'          => findColumnIndex($header, ['total cif', 'cif total', 'total_cif']),
        'total'              => findColumnIndex($header, ['total bad data', 'total']),
        'reguler'            => findColumnIndex($header, ['reguler']),
        'kerjasama'          => findColumnIndex($header, ['kerjasama']),
        'bad_data_prev'      => findColumnIndex($header, ['bad data prev', 'bad_data_prev']),
        'persentase_prev'    => findColumnIndex($header, ['persentase prev', 'persentase_prev', '% bad data prev']),
        'bad_data_curr'      => findColumnIndex($header, ['bad data curr', 'bad_data_curr']),
        'persentase_curr'    => findColumnIndex($header, ['persentase curr', 'persentase_curr', '% bad data curr']),
        'perbaikan_bad_data' => findColumnIndex($header, ['perbaikan bad data', 'perbaikan_bad_data', 'perbaikan']),
        'bad_data_baru'      => findColumnIndex($header, ['bad data baru', 'bad_data_baru']),
        'keterangan'         => findColumnIndex($header, ['keterangan', 'catatan', 'note', 'notes'])
    ];

    if ($map['branch'] === null) {
        throw new RuntimeException('Kolom BRANCH / Kantor Cabang tidak ditemukan dalam header berkas.');
    }

    return $map;
}

function saveBadDataRows(PDO $pdo, array $rows, array $map): array {
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $checkStmt = $pdo->prepare('SELECT id, pic_rac, persentase, average, region, month FROM bad_data WHERE branch = :branch LIMIT 1');

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

    $lastAverage = '-';
    $rowIdx = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowIdx++;
        $branch = trim((string)($row[$map['branch']] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch)) {
            $skipped++;
            continue;
        }

        // PIC RAC: Ambil dari kolom file jika tersedia dan bukan 'Kantor Cabang'
        $picRac = '';
        if ($map['pic_rac'] !== null && isset($row[$map['pic_rac']])) {
            $rawPic = trim((string)$row[$map['pic_rac']]);
            if ($rawPic !== '' && $rawPic !== '-' && strtolower($rawPic) !== 'kantor cabang') {
                $picRac = $rawPic;
            }
        }

        // Cek data yang sudah ada di database untuk cabang ini
        $checkStmt->execute([':branch' => $branch]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($picRac === '') {
            if ($existing && !empty($existing['pic_rac']) && $existing['pic_rac'] !== '-' && strtolower($existing['pic_rac']) !== 'kantor cabang') {
                $picRac = $existing['pic_rac'];
            } else {
                $picRac = '-';
            }
        }

        // % BAD DATA (persentase)
        $rawPct = '';
        if ($map['persentase'] !== null && isset($row[$map['persentase']])) {
            $rawPct = trim((string)$row[$map['persentase']]);
        } elseif ($map['persentase_curr'] !== null && isset($row[$map['persentase_curr']])) {
            $rawPct = trim((string)$row[$map['persentase_curr']]);
        } elseif ($map['persentase_prev'] !== null && isset($row[$map['persentase_prev']])) {
            $rawPct = trim((string)$row[$map['persentase_prev']]);
        }

        // Validasi ketat: pastikan bukan nama cabang atau teks non-numerik yang salah terpetakan
        if (preg_match('/^(kc|kanwil|kcp|unit|kantor|kk)\b/i', $rawPct) || !preg_match('/[0-9]/', $rawPct)) {
            $persentase = ($existing && !empty($existing['persentase'])) ? $existing['persentase'] : '0.00%';
        } else {
            $cleanedPct = str_replace(['%', ' '], '', $rawPct);
            $cleanedPct = str_replace(',', '.', $cleanedPct);
            if (is_numeric($cleanedPct)) {
                $f = (float)$cleanedPct;
                if ($f > 0 && $f <= 1 && !str_contains($rawPct, '%')) {
                    $persentase = number_format($f * 100, 2, '.', '') . '%';
                } else {
                    $persentase = number_format($f, 2, '.', '') . '%';
                }
            } elseif (str_contains($rawPct, '%')) {
                $persentase = $rawPct;
            } else {
                $persentase = $rawPct !== '' ? $rawPct : '0.00%';
            }
        }

        // AVERAGE
        $average = '-';
        if ($map['average'] !== null && isset($row[$map['average']])) {
            $rawAvg = trim((string)$row[$map['average']]);
            $cleanedAvg = str_replace(['%', ' '], '', $rawAvg);
            $cleanedAvg = str_replace(',', '.', $cleanedAvg);
            if (is_numeric($cleanedAvg)) {
                $f = (float)$cleanedAvg;
                if ($f > 0 && $f <= 1 && !str_contains($rawAvg, '%')) {
                    $average = number_format($f * 100, 2, '.', '') . '%';
                } else {
                    $average = number_format($f, 2, '.', '') . '%';
                }
            } elseif (str_contains($rawAvg, '%')) {
                $average = $rawAvg;
            } elseif (!preg_match('/^(ya|tidak|done|open|belum)\b/i', $rawAvg) && $rawAvg !== '') {
                $average = $rawAvg;
            }
            if ($average !== '-') {
                $lastAverage = $average;
            }
        }

        if ($average === '-' && $lastAverage !== '-') {
            $average = $lastAverage;
        }

        // NO URUT
        $noUrut = (string)$rowIdx;
        if ($map['no'] !== null && isset($row[$map['no']])) {
            $rawNo = trim((string)$row[$map['no']]);
            if ($rawNo !== '' && (!is_numeric($rawNo) || (int)$rawNo < 30000)) {
                $noUrut = $rawNo;
            }
        }

        // STATUS
        $status = 'Open';
        if ($map['status'] !== null && isset($row[$map['status']])) {
            $rawStatus = trim((string)$row[$map['status']]);
            if (stripos($rawStatus, 'done') !== false || stripos($rawStatus, 'selesai') !== false || stripos($rawStatus, 'ya') !== false) {
                $status = 'Done';
            } elseif (stripos($rawStatus, 'open') !== false || stripos($rawStatus, 'belum') !== false) {
                $status = 'Open';
            }
        }

        // MONTH / PERIODE
        $month = date('F Y');
        if ($map['month'] !== null && isset($row[$map['month']])) {
            $rawMonth = trim((string)$row[$map['month']]);
            if (is_numeric($rawMonth) && (int)$rawMonth >= 40000 && (int)$rawMonth <= 55000) {
                $month = date('F Y', ((int)$rawMonth - 25569) * 86400);
            } elseif ($rawMonth !== '' && $rawMonth !== '-') {
                $month = $rawMonth;
            }
        }

        // REGION / WILAYAH
        $region = '-';
        if ($map['region'] !== null && isset($row[$map['region']])) {
            $rawReg = trim((string)$row[$map['region']]);
            if ($rawReg !== '') {
                $region = $rawReg;
            }
        }

        // Kolom opsional rekapan komparasi MDM
        $totalCif         = ($map['total_cif'] !== null && isset($row[$map['total_cif']])) ? normalizeNumber($row[$map['total_cif']]) : null;
        $total            = ($map['total'] !== null && isset($row[$map['total']])) ? normalizeNumber($row[$map['total']]) : null;
        $reguler          = ($map['reguler'] !== null && isset($row[$map['reguler']])) ? normalizeNumber($row[$map['reguler']]) : null;
        $kerjasama        = ($map['kerjasama'] !== null && isset($row[$map['kerjasama']])) ? normalizeNumber($row[$map['kerjasama']]) : null;
        $badDataPrev      = ($map['bad_data_prev'] !== null && isset($row[$map['bad_data_prev']])) ? normalizeNumber($row[$map['bad_data_prev']]) : null;
        $persentasePrev   = ($map['persentase_prev'] !== null && isset($row[$map['persentase_prev']])) ? normalizeNumber($row[$map['persentase_prev']]) : null;
        $badDataCurr      = ($map['bad_data_curr'] !== null && isset($row[$map['bad_data_curr']])) ? normalizeNumber($row[$map['bad_data_curr']]) : null;
        $persentaseCurr   = ($map['persentase_curr'] !== null && isset($row[$map['persentase_curr']])) ? normalizeNumber($row[$map['persentase_curr']]) : $persentase;
        $perbaikanBadData = ($map['perbaikan_bad_data'] !== null && isset($row[$map['perbaikan_bad_data']])) ? normalizeNumber($row[$map['perbaikan_bad_data']]) : null;
        $badDataBaru      = ($map['bad_data_baru'] !== null && isset($row[$map['bad_data_baru']])) ? normalizeNumber($row[$map['bad_data_baru']]) : null;
        $keterangan       = ($map['keterangan'] !== null && isset($row[$map['keterangan']])) ? trim((string)$row[$map['keterangan']]) : null;

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
            ':status'             => $status,
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'import') {
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
                $rows = parseXlsxFile($tmpName, ['bad data', 'baddata', 'bad_data', 'bad']);
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

        [$header, $dataRows] = resolveBadDataHeadersAndRows($rows);
        if (!is_array($header) || empty($header)) {
            jsonResponse(false, 'Baris header tidak ditemukan.');
        }

        $map = getBadDataColumnMap($header);

        $pdo->beginTransaction();
        $result = saveBadDataRows($pdo, $dataRows, $map);
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
// POST: Clear / Reset handler
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'clear') {
    try {
        $pdo->exec('TRUNCATE TABLE bad_data');
        jsonResponse(true, 'Seluruh data bad data berhasil direset.');
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal menghapus data: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// POST: Delete selected handler
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $ids = $input['ids'] ?? ($input['id'] ?? []);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            jsonResponse(false, 'Tidak ada data yang dipilih untuk dihapus.');
        }

        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM bad_data WHERE id IN ({$inQuery})");
        $stmt->execute(array_values($ids));

        jsonResponse(true, count($ids) . ' data terpilih berhasil dihapus.');
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal menghapus data terpilih: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// GET: Fetch list handler
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && PHP_SAPI !== 'cli') {
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
        $sql = "SELECT * FROM bad_data {$whereSql} ORDER BY id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(true, 'Data berhasil diambil.', ['data' => $data, 'total' => count($data)]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal mengambil data: ' . $e->getMessage());
    }
}

if (PHP_SAPI !== 'cli') {
    jsonResponse(false, 'Metode request tidak didukung.');
}
