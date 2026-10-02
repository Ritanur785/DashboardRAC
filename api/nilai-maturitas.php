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
    if ($value === '' || $value === '-') {
        return null;
    }
    $value = str_replace(['%', ' '], '', $value);
    $value = str_replace(',', '.', $value);
    if (is_numeric($value)) {
        return number_format((float)$value, 2, '.', '');
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

        // Only unwrap if the entire line was enclosed in outer quotes with escaped inner double quotes
        if (str_starts_with($cleanLine, '"') && str_ends_with($cleanLine, '"') && str_contains($cleanLine, '""')) {
            $cleanLine = substr($cleanLine, 1, -1);
            $cleanLine = str_replace('""', '"', $cleanLine);
        }

        $row = str_getcsv($cleanLine, $delimiter, '"', '\\');
        $rows[] = array_map(function ($v) {
            $val = trim((string)$v);
            if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                $val = trim(substr($val, 1, -1));
            }
            return trim($val, "\"' \t\n\r\0\x0B");
        }, $row);
    }

    return $rows;
}

function parseXlsxFile(string $filePath, array $preferredSheetNames = ['nilai maturitas', 'maturitas', 'nilai_maturitas']): array {
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

    // Select the best worksheet based on preferred names
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

            if ($type === 's') {
                $strIdx = (int)$val;
                $row[] = $sharedStrings[$strIdx] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $row[] = (string)$cell->is->t;
            } else {
                $row[] = $val;
            }

            $lastColIdx = $colIdx;
        }

        $allEmpty = true;
        foreach ($row as $v) {
            if (trim((string)$v) !== '') {
                $allEmpty = false;
                break;
            }
        }

        if (!$allEmpty) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function normalizeRatingMaturitas(mixed $val, ?float $numericScore = null): string {
    $str = trim((string)($val ?? ''));
    $lower = strtolower($str);

    if ($lower === 'a' || str_contains($lower, 'sangat baik') || str_contains($lower, 'very good') || str_contains($lower, 'tinggi') || str_contains($lower, 'high')) {
        return 'Sangat Baik';
    }
    if ($lower === 'b' || str_contains($lower, 'baik') || str_contains($lower, 'good')) {
        return 'Baik';
    }
    if ($lower === 'c' || str_contains($lower, 'cukup') || str_contains($lower, 'fair') || str_contains($lower, 'sedang') || str_contains($lower, 'medium')) {
        return 'Cukup';
    }
    if ($lower === 'd' || $lower === 'e' || str_contains($lower, 'sangat kurang') || str_contains($lower, 'buruk') || str_contains($lower, 'very low') || str_contains($lower, 'poor') || str_contains($lower, 'kurang') || str_contains($lower, 'low') || str_contains($lower, 'rendah')) {
        return 'Kurang';
    }

    if ($numericScore !== null && $numericScore > 0) {
        if ($numericScore >= 8.0 || ($numericScore >= 4.5 && $numericScore <= 5.0)) return 'Sangat Baik';
        if ($numericScore >= 6.5 || ($numericScore >= 3.5 && $numericScore < 4.5)) return 'Baik';
        if ($numericScore >= 4.0 || ($numericScore >= 2.5 && $numericScore < 3.5)) return 'Cukup';
        return 'Kurang';
    }

    return 'Cukup';
}

function formatMaturitasMonth(mixed $val): string {
    $indoMonths = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    if ($val !== null) {
        $str = trim((string)$val);
        foreach ($indoMonths as $num => $name) {
            if (strcasecmp($str, $name) === 0) {
                return $name;
            }
        }

        $engMonths = [
            'january' => 'Januari', 'february' => 'Februari', 'march' => 'Maret',
            'april' => 'April', 'may' => 'Mei', 'june' => 'Juni',
            'july' => 'Juli', 'august' => 'Agustus', 'september' => 'September',
            'october' => 'Oktober', 'november' => 'November', 'december' => 'Desember'
        ];
        if (isset($engMonths[strtolower($str)])) {
            return $engMonths[strtolower($str)];
        }

        if (is_numeric($str) && (int)$str >= 1 && (int)$str <= 12 && strlen($str) <= 2) {
            return $indoMonths[(int)$str];
        }

        if (is_numeric($str) && (float)$str > 20000 && (float)$str < 80000) {
            $days = (int)(float)$str;
            $ts = ($days - 25569) * 86400;
            $mNum = (int)gmdate('n', $ts);
            return $indoMonths[$mNum] ?? 'Maret';
        }

        if ($str !== '' && $str !== '-') {
            return $str;
        }
    }

    return 'Maret';
}

function isScorePattern(string $str): bool {
    $str = trim($str);
    if ($str === '' || $str === '-') return false;
    $c = str_replace(['%', ' '], '', $str);
    $c = str_replace(',', '.', $c);
    return is_numeric($c);
}

function isRatingPattern(string $str): bool {
    $lower = strtolower(trim($str));
    if ($lower === '' || $lower === '-') return false;
    return (bool)preg_match('/\b(sangat baik|baik|cukup|kurang|rendah|sedang|tinggi|good|fair|poor|low|high)\b/i', $lower);
}

function resolveNilaiMaturitasHeadersAndRows(array $allRows): array {
    if (empty($allRows)) {
        return [[], []];
    }

    $headerKeywords = [
        'branch', 'cabang', 'kantor cabang', 'unit kerja', 'uker', 'kanca',
        'pic rac', 'pic_rac', 'pic', 'nama pic', 'disposisi rac',
        'nilai maturitas', 'nilai', 'maturitas', 'skor maturitas', 'skor',
        'average', 'avarage', 'rata-rata', 'rata rata', 'avg',
        'rating maturitas', 'rating', 'kategori maturitas', 'predikat', 'level maturitas',
        'posisi bulan', 'bulan', 'region', 'wilayah', 'no'
    ];

    $headerRowIdx = 0;
    $maxMatches = 0;
    $scanLimit = min(15, count($allRows));

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

    // Check multi-tier headers
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

function getNilaiMaturitasColumnMap(array $header): array {
    $map = [
        'no'               => findColumnIndex($header, [
            'no', 'no.', 'no urut', 'no_urut', 'nomor', 'nomor urut', 'num'
        ]),
        'branch'           => findColumnIndex($header, [
            'kantor cabang', 'kanca', 'nama branch', 'nama cabang', 'branch office',
            'unit kerja', 'nama uker', 'uker', 'branch', 'cabang', 'kc'
        ]),
        'pic_rac'          => findColumnIndex($header, [
            'pic rac', 'pic_rac', 'pic-rac', 'disposisi rac', 'nama pic', 'pic amlo',
            'officer rac', 'rac officer', 'pic', 'petugas rac', 'auditor rac', 'nama petugas', 'rac'
        ]),
        'nilai_maturitas'  => findColumnIndex($header, [
            'nilai maturitas', 'nilai_maturitas', 'skor nilai maturitas', 'skor maturitas',
            'hasil maturitas', 'nilai akhir', 'skor akhir', 'maturitas', 'nilai', 'skor',
            'score maturitas', 'score'
        ]),
        'average'          => findColumnIndex($header, [
            'average maturitas', 'avg maturitas', 'rata-rata maturitas', 'rata rata maturitas',
            'average', 'avarage', 'rata-rata', 'rata rata', 'avg'
        ]),
        'rating_maturitas' => findColumnIndex($header, [
            'rating maturitas', 'rating_maturitas', 'kategori maturitas', 'level maturitas',
            'predikat maturitas', 'peringkat maturitas', 'status maturitas', 'rating',
            'kategori', 'predikat', 'level', 'peringkat'
        ]),
        'month'            => findColumnIndex($header, [
            'posisi bulan', 'posisi_bulan', 'bulan', 'month', 'periode', 'posisi'
        ]),
        'region'           => findColumnIndex($header, [
            'kantor kanwil', 'kanwil', 'regional office', 'regional', 'region', 'wilayah', 'ro'
        ]),
        'keterangan'       => findColumnIndex($header, [
            'keterangan maturitas', 'keterangan', 'catatan', 'note', 'notes', 'remarks', 'ket'
        ])
    ];

    if ($map['branch'] === null) {
        foreach ($header as $idx => $h) {
            $hLower = strtolower(trim((string)$h));
            if (str_contains($hLower, 'branch') || str_contains($hLower, 'cabang') || str_contains($hLower, 'uker')) {
                $map['branch'] = (int)$idx;
                break;
            }
        }
    }

    if ($map['branch'] === null) {
        throw new RuntimeException('Kolom UNIT KERJA / Kantor Cabang tidak ditemukan dalam header berkas.');
    }

    if ($map['average'] === null && $map['nilai_maturitas'] !== null) {
        foreach ($header as $idx => $h) {
            if ($idx > $map['nilai_maturitas'] && ($map['rating_maturitas'] === null || $idx < $map['rating_maturitas'])) {
                $hStrip = stripAlphanumeric($h);
                if (str_contains($hStrip, 'maturitas') || str_contains($hStrip, 'nilai') || str_contains($hStrip, 'rata') || str_contains($hStrip, 'average') || str_contains($hStrip, 'skor') || str_contains($hStrip, 'pic')) {
                    $map['average'] = (int)$idx;
                    break;
                }
            }
        }
        if ($map['average'] === null && $map['rating_maturitas'] !== null && ($map['rating_maturitas'] - $map['nilai_maturitas']) === 2) {
            $map['average'] = $map['nilai_maturitas'] + 1;
        }
    }

    return $map;
}

function saveNilaiMaturitasRows(PDO $pdo, array $rows, array $map): array {
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $checkStmt = $pdo->prepare('SELECT id, pic_rac, nilai_maturitas, average, rating_maturitas, month, region, keterangan FROM nilai_maturitas WHERE branch = :branch LIMIT 1');

    $checkPengkinian = $pdo->prepare('SELECT pic_rac, region FROM pengkinian_data WHERE branch = :branch AND (pic_rac IS NOT NULL OR region IS NOT NULL) LIMIT 1');
    $checkBad = $pdo->prepare('SELECT pic_rac, region FROM bad_data WHERE branch = :branch AND (pic_rac IS NOT NULL OR region IS NOT NULL) LIMIT 1');
    $checkUji = $pdo->prepare('SELECT pic_rac, region FROM uji_petik WHERE branch = :branch AND (pic_rac IS NOT NULL OR region IS NOT NULL) LIMIT 1');

    $updateStmt = $pdo->prepare('
        UPDATE nilai_maturitas
        SET no_urut = :no_urut,
            pic_rac = :pic_rac,
            nilai_maturitas = :nilai_maturitas,
            average = :average,
            rating_maturitas = :rating_maturitas,
            month = :month,
            region = :region,
            keterangan = :keterangan
        WHERE branch = :branch
    ');

    $insertStmt = $pdo->prepare('
        INSERT INTO nilai_maturitas (
            no_urut, branch, pic_rac, nilai_maturitas, average,
            rating_maturitas, month, region, keterangan
        ) VALUES (
            :no_urut, :branch, :pic_rac, :nilai_maturitas, :average,
            :rating_maturitas, :month, :region, :keterangan
        )
    ');

    $lastPicRac = '';
    $lastAverage = '-';
    $lastRating = '';
    $rowIdx = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $branch = trim((string)($row[$map['branch']] ?? ''));
        if ($branch === '' || $branch === '-' || is_numeric($branch) || strcasecmp($branch, 'branch') === 0 || strcasecmp($branch, 'cabang') === 0 || strcasecmp($branch, 'unit kerja') === 0) {
            $skipped++;
            continue;
        }

        $bLower = strtolower($branch);
        if (str_contains($bLower, 'keterangan') || str_contains($bLower, 'note:')) {
            $skipped++;
            continue;
        }

        $rowIdx++;

        $noIdx = $map['no'] ?? null;
        $noUrut = ($noIdx !== null && isset($row[$noIdx]) && trim((string)$row[$noIdx]) !== '' && is_numeric(trim((string)$row[$noIdx])))
            ? trim((string)$row[$noIdx])
            : (string)$rowIdx;

        // Fetch existing branch data
        $checkStmt->execute([':branch' => $branch]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $otherExisting = null;
        if (!$existing) {
            try {
                $checkPengkinian->execute([':branch' => $branch]);
                $otherExisting = $checkPengkinian->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
            if (!$otherExisting || empty($otherExisting['pic_rac']) || empty($otherExisting['region'])) {
                try {
                    $checkBad->execute([':branch' => $branch]);
                    $bad = $checkBad->fetch(PDO::FETCH_ASSOC);
                    if ($bad) {
                        if (empty($otherExisting['pic_rac']) && !empty($bad['pic_rac'])) {
                            $otherExisting['pic_rac'] = $bad['pic_rac'];
                        }
                        if (empty($otherExisting['region']) && !empty($bad['region'])) {
                            $otherExisting['region'] = $bad['region'];
                        }
                    }
                } catch (Throwable $e) {}
            }
            if (!$otherExisting || empty($otherExisting['pic_rac']) || empty($otherExisting['region'])) {
                try {
                    $checkUji->execute([':branch' => $branch]);
                    $uji = $checkUji->fetch(PDO::FETCH_ASSOC);
                    if ($uji) {
                        if (empty($otherExisting['pic_rac']) && !empty($uji['pic_rac'])) {
                            $otherExisting['pic_rac'] = $uji['pic_rac'];
                        }
                        if (empty($otherExisting['region']) && !empty($uji['region'])) {
                            $otherExisting['region'] = $uji['region'];
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        // Raw values from mapped column indices
        $picRac = ($map['pic_rac'] !== null && isset($row[$map['pic_rac']]))
            ? trim((string)$row[$map['pic_rac']])
            : '';

        $rawNilai = ($map['nilai_maturitas'] !== null && isset($row[$map['nilai_maturitas']]))
            ? trim((string)$row[$map['nilai_maturitas']])
            : '';

        $rawAverage = ($map['average'] !== null && isset($row[$map['average']]))
            ? trim((string)$row[$map['average']])
            : '';

        $rawRating = ($map['rating_maturitas'] !== null && isset($row[$map['rating_maturitas']]))
            ? trim((string)$row[$map['rating_maturitas']])
            : '';

        // Intelligent Content Swaps & Heuristic Fixes
        // 1. If Rating contains a score (numeric) and Nilai contains a rating keyword
        if (isScorePattern($rawRating) && isRatingPattern($rawNilai)) {
            $temp = $rawRating;
            $rawRating = $rawNilai;
            $rawNilai = $temp;
        }

        // 2. If PIC RAC contains a score and Nilai is non-numeric
        if (isScorePattern($picRac) && !isScorePattern($rawNilai) && !isRatingPattern($rawNilai)) {
            $temp = $picRac;
            $picRac = $rawNilai;
            $rawNilai = $temp;
        }

        // Filter out headers being accidentally stored as PIC
        if (in_array(strtolower($picRac), ['pic rac', 'pic_rac', 'pic', 'branch', 'cabang', 'nama branch', '-'], true)) {
            $picRac = '';
        }

        // Fallback PIC RAC from existing data
        if ($picRac === '') {
            if (!empty($existing['pic_rac']) && $existing['pic_rac'] !== '-') {
                $picRac = $existing['pic_rac'];
            } elseif (!empty($otherExisting['pic_rac']) && $otherExisting['pic_rac'] !== '-') {
                $picRac = $otherExisting['pic_rac'];
            } else {
                $picRac = '-';
            }
        }

        // Nilai Maturitas
        $nilaiMaturitas = normalizeNumber($rawNilai);
        if ($nilaiMaturitas === null || $nilaiMaturitas === '') {
            $nilaiMaturitas = (!empty($existing['nilai_maturitas'])) ? $existing['nilai_maturitas'] : '0.00';
        }

        // Average
        $average = normalizeNumber($rawAverage);
        if ($average === null || $average === '' || $average === '-') {
            if ($picRac !== '' && $picRac !== '-' && strtolower($picRac) === strtolower($lastPicRac)) {
                $average = $lastAverage;
            } elseif (!empty($existing['average']) && $existing['average'] !== '-') {
                $average = $existing['average'];
            } else {
                $average = '-';
            }
        } else {
            $lastPicRac = $picRac;
            $lastAverage = $average;
        }

        // Rating Maturitas - derived primarily from Average
        $avgNum = (is_numeric($average) && (float)$average > 0) ? (float)$average : null;
        if ($rawRating !== '') {
            $rating = normalizeRatingMaturitas($rawRating, $avgNum);
            $lastRating = $rating;
        } else {
            if ($lastRating !== '') {
                $rating = $lastRating;
            } elseif ($avgNum !== null && $avgNum > 0) {
                $rating = normalizeRatingMaturitas('', $avgNum);
            } elseif (!empty($existing['rating_maturitas'])) {
                $rating = normalizeRatingMaturitas($existing['rating_maturitas'], $avgNum);
            } else {
                $numericVal = is_numeric($nilaiMaturitas) ? (float)$nilaiMaturitas : null;
                $rating = normalizeRatingMaturitas('', $numericVal);
            }
        }

        // Posisi Bulan
        $month = ($map['month'] !== null && isset($row[$map['month']]) && trim((string)$row[$map['month']]) !== '')
            ? trim((string)$row[$map['month']])
            : ($existing['month'] ?? '');
        $month = formatMaturitasMonth($month);

        // Region
        $region = ($map['region'] !== null && isset($row[$map['region']]) && trim((string)$row[$map['region']]) !== '')
            ? trim((string)$row[$map['region']])
            : '';

        if ($region === '' || $region === '-') {
            if (!empty($existing['region']) && $existing['region'] !== '-') {
                $region = $existing['region'];
            } elseif (!empty($otherExisting['region']) && $otherExisting['region'] !== '-') {
                $region = $otherExisting['region'];
            } else {
                $region = '-';
            }
        }

        // Keterangan
        $keterangan = ($map['keterangan'] !== null && isset($row[$map['keterangan']]))
            ? trim((string)$row[$map['keterangan']])
            : null;
        if ($keterangan === null && isset($existing['keterangan'])) {
            $keterangan = $existing['keterangan'];
        }

        $params = [
            ':no_urut'          => $noUrut,
            ':branch'           => $branch,
            ':pic_rac'          => $picRac,
            ':nilai_maturitas'  => $nilaiMaturitas,
            ':average'          => $average,
            ':rating_maturitas' => $rating,
            ':month'            => $month,
            ':region'           => $region,
            ':keterangan'       => $keterangan,
        ];

        if ($existing) {
            $updateStmt->execute($params);
            $updated++;
        } else {
            $insertStmt->execute($params);
            $inserted++;
        }
    }

    return [
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped
    ];
}

// --------------------------------------------------------------------------
// POST: Import handler
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'import') {
    try {
        $rows = [];

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = (string)$_FILES['file']['tmp_name'];
            $origName = (string)$_FILES['file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext === 'xlsx') {
                $rows = parseXlsxFile($tmpPath, ['nilai maturitas', 'maturitas', 'nilai_maturitas']);
            } elseif (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
                $content = file_get_contents($tmpPath);
                if ($content === false) {
                    jsonResponse(false, 'Gagal membaca berkas CSV/teks.');
                }
                $rows = parseDelimitedText($content);
            } else {
                jsonResponse(false, 'Format berkas tidak didukung. Harap unggah .csv, .txt, atau .xlsx.');
            }
        } elseif (!empty($_POST['raw_data'])) {
            $rows = parseDelimitedText((string)$_POST['raw_data']);
        } else {
            jsonResponse(false, 'Tidak ada berkas atau teks data yang dikirim.');
        }

        if (empty($rows)) {
            jsonResponse(false, 'Tidak ada baris data yang ditemukan.');
        }

        [$header, $dataRows] = resolveNilaiMaturitasHeadersAndRows($rows);
        if (!is_array($header) || empty($header)) {
            jsonResponse(false, 'Baris header tidak ditemukan.');
        }

        $map = getNilaiMaturitasColumnMap($header);

        $pdo->beginTransaction();
        $result = saveNilaiMaturitasRows($pdo, $dataRows, $map);
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
        $pdo->exec('TRUNCATE TABLE nilai_maturitas');
        jsonResponse(true, 'Seluruh data nilai maturitas berhasil direset.');
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
        $stmt = $pdo->prepare("DELETE FROM nilai_maturitas WHERE id IN ({$inQuery})");
        $stmt->execute(array_values($ids));

        jsonResponse(true, count($ids) . ' data terpilih berhasil dihapus.');
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal menghapus data terpilih: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
// GET: Fetch list handler
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    try {
        $filterMonth = trim((string)($_GET['month'] ?? ''));
        $filterRegion = trim((string)($_GET['region'] ?? ''));
        $search = trim((string)($_GET['q'] ?? ''));

        $where = [];
        $params = [];

        if ($filterMonth !== '') {
            $where[] = 'month LIKE :month';
            $params[':month'] = '%' . $filterMonth . '%';
        }

        if ($filterRegion !== '') {
            $where[] = 'region LIKE :region';
            $params[':region'] = '%' . $filterRegion . '%';
        }

        if ($search !== '') {
            $where[] = '(branch LIKE :search_branch OR pic_rac LIKE :search_pic OR rating_maturitas LIKE :search_rating OR keterangan LIKE :search_ket)';
            $params[':search_branch'] = '%' . $search . '%';
            $params[':search_pic'] = '%' . $search . '%';
            $params[':search_rating'] = '%' . $search . '%';
            $params[':search_ket'] = '%' . $search . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM nilai_maturitas {$whereSql} ORDER BY id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($data as &$item) {
            $avgVal = is_numeric($item['average'] ?? null) ? (float)$item['average'] : (is_numeric($item['nilai_maturitas'] ?? null) ? (float)$item['nilai_maturitas'] : null);
            $item['rating_maturitas'] = normalizeRatingMaturitas($item['rating_maturitas'] ?? '', $avgVal);
        }
        unset($item);

        jsonResponse(true, 'Data berhasil diambil.', ['data' => $data, 'total' => count($data)]);
    } catch (Throwable $e) {
        jsonResponse(false, 'Gagal mengambil data: ' . $e->getMessage());
    }
}

if (PHP_SAPI !== 'cli') {
    jsonResponse(false, 'Metode request tidak didukung.');
}
