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

function parseXlsxFile(string $filePath, array $preferredSheetNames = ['uji petik', 'ujipetik', 'uji_petik', 'uji']): array {
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

function formatUjiPetikDate(mixed $val): string {
    if ($val === null) {
        return '-';
    }
    $str = trim((string)$val);
    if ($str === '' || $str === '-') {
        return '-';
    }

    $bulanIndoMap = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    // Numeric Excel serial date (e.g. 45366, 46098, 46273)
    if (is_numeric($str) && (float)$str > 1000 && (float)$str < 100000) {
        $days = (int)(float)$str;
        $unixTimestamp = ($days - 25569) * 86400;
        if ($unixTimestamp > 0) {
            $d = (int)gmdate('j', $unixTimestamp);
            $m = (int)gmdate('n', $unixTimestamp);
            $y = (int)gmdate('Y', $unixTimestamp);
            return "$d " . ($bulanIndoMap[$m] ?? gmdate('F', $unixTimestamp)) . " $y";
        }
    }

    // Already YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $str, $m)) {
        return (int)$m[3] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[1];
    }

    // DD/MM/YYYY or DD-MM-YYYY or DD.MM.YYYY
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $str, $m)) {
        return (int)$m[1] . ' ' . ($bulanIndoMap[(int)$m[2]] ?? $m[2]) . ' ' . $m[3];
    }

    return $str;
}

function formatUjiPetikMonth(mixed $val, string $fallbackDate = ''): string {
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

        if (preg_match('/\b\d{4}-(\d{2})-\d{2}\b/', $str, $m) || preg_match('/\b\d{1,2}[\/\-\.](\d{1,2})[\/\-\.]\d{4}\b/', $str, $m)) {
            $mNum = (int)$m[1];
            return $indoMonths[$mNum] ?? 'Maret';
        }

        if ($str !== '' && $str !== '-') {
            return $str;
        }
    }

    if ($fallbackDate !== '' && $fallbackDate !== '-' && preg_match('/^\d{4}-(\d{2})-\d{2}$/', $fallbackDate, $m)) {
        $mNum = (int)$m[1];
        return $indoMonths[$mNum] ?? 'Maret';
    }

    return 'Maret';
}

function normalizeUjiPetikStatus(mixed $val): string {
    if ($val === null) {
        return 'Not Done';
    }
    $str = strtolower(trim((string)$val));
    if ($str === '') {
        return 'Not Done';
    }

    // Negative keywords
    if (
        str_contains($str, 'not') ||
        str_contains($str, 'belum') ||
        str_contains($str, 'tidak') ||
        str_contains($str, 'pending') ||
        str_contains($str, 'open') ||
        str_contains($str, 'progress')
    ) {
        return 'Not Done';
    }

    // Positive keywords
    if (
        $str === 'done' ||
        $str === 'selesai' ||
        $str === 'sudah' ||
        $str === 'closed' ||
        $str === 'complete' ||
        $str === 'completed' ||
        $str === 'ok' ||
        $str === 'yes' ||
        str_contains($str, 'done') ||
        str_contains($str, 'selesai') ||
        str_contains($str, 'sudah')
    ) {
        return 'Done';
    }

    return 'Not Done';
}

function isDatePattern(string $str): bool {
    $str = trim($str);
    if ($str === '' || $str === '-') return false;
    if (is_numeric($str) && (float)$str > 20000 && (float)$str < 80000) return true;
    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $str)) return true;
    if (preg_match('/^\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4}$/', $str)) return true;
    return false;
}

function isDocPattern(string $str): bool {
    $str = trim($str);
    if ($str === '' || $str === '-') return false;
    if (preg_match('/\.(pdf|xlsx|xls|docx|doc|zip|rar|csv)$/i', $str)) return true;
    if (preg_match('/^(laporan|berkas|dokumen|lampiran|ba[\_\-\s])/i', $str)) return true;
    return false;
}

function isStatusPattern(string $str): bool {
    $str = strtolower(trim($str));
    if ($str === '' || $str === '-') return false;
    return in_array($str, ['done', 'not done', 'selesai', 'belum', 'belum selesai', 'sudah', 'closed', 'open', 'complete', 'in progress', 'pending'], true);
}

function isRencanaPattern(string $str): bool {
    $str = strtolower(trim($str));
    if ($str === '' || $str === '-') return false;
    return (bool)preg_match('/\b(minggu|week|w[1-4]|q[1-4]|tahap|jadwal)\b/i', $str);
}

function resolveUjiPetikHeadersAndRows(array $allRows): array {
    if (empty($allRows)) {
        return [[], []];
    }

    $headerKeywords = [
        'branch', 'cabang', 'kantor cabang', 'unit kerja', 'uker', 'kanca',
        'pic rac', 'pic_rac', 'pic', 'nama pic', 'disposisi rac', 'disposisi', 'rac',
        'rencana pelaksanaan', 'rencana', 'jadwal', 'target pelaksanaan',
        'tanggal uji petik', 'tgl uji petik', 'tanggal', 'tgl', 'pelaksanaan',
        'dokumen', 'nama dokumen', 'berkas', 'file dokumen', 'laporan', 'lampiran',
        'status', 'status uji petik', 'status pelaksanaan', 'status tl',
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

    // Check multi-tier headers (if the next row also looks like a header)
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

function isUjiPetikGarbageBranch(string $branch): bool {
    $bTrim = trim($branch);
    if ($bTrim === '' || $bTrim === '-' || is_numeric($bTrim)) {
        return true;
    }
    $bLower = strtolower($bTrim);
    if (in_array($bLower, ['branch', 'cabang', 'kantor cabang', 'unit kerja', 'uker', 'kanca', 'nihil', 'total', 'jumlah', 'average'], true)) {
        return true;
    }
    // Starts with asterisk, hyphen footnote, contains URL
    if (str_starts_with($bTrim, '*') || str_starts_with($bTrim, '-') || str_contains($bLower, 'http://') || str_contains($bLower, 'https://')) {
        return true;
    }
    // Footnote and legend keywords
    if (str_starts_with($bLower, 'keterangan') || str_starts_with($bLower, 'note') || str_contains($bLower, 'evidence terdiri') || str_contains($bLower, 'diisi jika')) {
        return true;
    }
    // Numbered legend items e.g. "1. Dokumentasi Foto", "2. Berita Acara", "4.Kertas Kerja"
    if (preg_match('/^[0-9]+[\.\)]/i', $bTrim) && !preg_match('/\b(kc|bo|kcp|unit|cabang)\b/i', $bTrim)) {
        return true;
    }
    return false;
}

function getUjiPetikColumnMap(array $header): array {
    $map = [
        'no'                  => findColumnIndex($header, [
            'no', 'no.', 'no urut', 'no_urut', 'nomor', 'nomor urut', 'num'
        ]),
        'branch'              => findColumnIndex($header, [
            'kantor cabang', 'kanca', 'nama branch', 'nama cabang', 'branch office',
            'unit kerja', 'nama uker', 'uker', 'branch', 'cabang', 'kc'
        ]),
        'pic_rac'             => findColumnIndex($header, [
            'pic rac', 'pic_rac', 'pic-rac', 'disposisi rac', 'nama pic', 'pic uji petik',
            'pic amlo', 'officer rac', 'rac officer', 'pic', 'petugas rac', 'auditor rac',
            'nama petugas', 'rac'
        ]),
        'rencana_pelaksanaan' => findColumnIndex($header, [
            'rencana pelaksanaan', 'rencana uji petik', 'rencana', 'jadwal pelaksanaan',
            'jadwal uji petik', 'jadwal', 'target pelaksanaan', 'target uji petik',
            'target', 'timeline', 'minggu pelaksanaan', 'minggu ke', 'minggu', 'week', 'waktu rencana'
        ]),
        'tanggal_uji_petik'   => findColumnIndex($header, [
            'tanggal pelaksanaan', 'tgl pelaksanaan', 'tanggal uji petik', 'tgl uji petik',
            'tanggal realisasi', 'tgl realisasi', 'tanggal selesai', 'tgl selesai',
            'tanggal', 'tgl', 'pelaksanaan', 'realisasi', 'waktu pelaksanaan', 'date'
        ]),
        'dokumen'             => findColumnIndex($header, [
            'dokumen evidence', 'dokumen_evidence', 'evidence', 'nama dokumen',
            'dokumen pendukung', 'dokumen uji petik', 'dokumen',
            'berkas pendukung', 'berkas uji petik', 'berkas', 'file dokumen',
            'file pendukung', 'file', 'lampiran', 'laporan uji petik', 'laporan',
            'ba uji petik', 'berita acara', 'ba', 'bukti uji petik', 'bukti', 'dok'
        ]),
        'status'              => findColumnIndex($header, [
            'status uji petik', 'status pelaksanaan', 'status tl', 'status tindak lanjut',
            'status akhir', 'status hasil', 'status', 'hasil uji petik', 'hasil', 'progress'
        ]),
        'month'               => findColumnIndex($header, [
            'posisi bulan', 'posisi_bulan', 'bulan', 'month', 'periode', 'posisi'
        ]),
        'region'              => findColumnIndex($header, [
            'kantor kanwil', 'kanwil', 'regional office', 'regional', 'region', 'wilayah', 'ro'
        ]),
        'keterangan'          => findColumnIndex($header, [
            'keterangan uji petik', 'keterangan', 'catatan', 'note', 'notes', 'remarks', 'ket'
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

    return $map;
}

function saveUjiPetikRows(PDO $pdo, array $rows, array $map): array {
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $checkStmt = $pdo->prepare('SELECT id, pic_rac, rencana_pelaksanaan, tanggal_uji_petik, dokumen, status, month, region, keterangan FROM uji_petik WHERE branch = :branch LIMIT 1');

    $checkOtherStmt = $pdo->prepare('SELECT pic_rac, region FROM pengkinian_data WHERE branch = :branch AND (pic_rac IS NOT NULL OR region IS NOT NULL) LIMIT 1');

    $checkBadStmt = $pdo->prepare('SELECT pic_rac, region FROM bad_data WHERE branch = :branch AND (pic_rac IS NOT NULL OR region IS NOT NULL) LIMIT 1');

    $updateStmt = $pdo->prepare('
        UPDATE uji_petik
        SET no_urut = :no_urut,
            pic_rac = :pic_rac,
            rencana_pelaksanaan = :rencana_pelaksanaan,
            tanggal_uji_petik = :tanggal_uji_petik,
            dokumen = :dokumen,
            status = :status,
            month = :month,
            region = :region,
            keterangan = :keterangan
        WHERE branch = :branch
    ');

    $insertStmt = $pdo->prepare('
        INSERT INTO uji_petik (
            no_urut, branch, pic_rac, rencana_pelaksanaan, tanggal_uji_petik,
            dokumen, status, month, region, keterangan
        ) VALUES (
            :no_urut, :branch, :pic_rac, :rencana_pelaksanaan, :tanggal_uji_petik,
            :dokumen, :status, :month, :region, :keterangan
        )
    ');

    $rowIdx = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rawBranch = trim((string)($row[$map['branch']] ?? ''));
        if (isUjiPetikGarbageBranch($rawBranch)) {
            $skipped++;
            continue;
        }

        // Canonical branch: normalize BO [Name] to KC [Name]
        $branch = preg_replace('/^BO\s+/i', 'KC ', $rawBranch);

        $rowIdx++;

        $noIdx = $map['no'] ?? null;
        $noUrut = ($noIdx !== null && isset($row[$noIdx]) && trim((string)$row[$noIdx]) !== '' && is_numeric(trim((string)$row[$noIdx])))
            ? trim((string)$row[$noIdx])
            : (string)$rowIdx;

        // Fetch existing branch data (match canonical or raw)
        $checkStmt->execute([':branch' => $branch]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing && $branch !== $rawBranch) {
            $checkStmt->execute([':branch' => $rawBranch]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        }


        $otherExisting = null;
        if (!$existing) {
            try {
                $checkOtherStmt->execute([':branch' => $branch]);
                $otherExisting = $checkOtherStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                // Ignore if pengkinian_data table not available
            }
            if (!$otherExisting || empty($otherExisting['pic_rac']) || empty($otherExisting['region'])) {
                try {
                    $checkBadStmt->execute([':branch' => $branch]);
                    $badExisting = $checkBadStmt->fetch(PDO::FETCH_ASSOC);
                    if ($badExisting) {
                        if (empty($otherExisting['pic_rac']) && !empty($badExisting['pic_rac'])) {
                            $otherExisting['pic_rac'] = $badExisting['pic_rac'];
                        }
                        if (empty($otherExisting['region']) && !empty($badExisting['region'])) {
                            $otherExisting['region'] = $badExisting['region'];
                        }
                    }
                } catch (Throwable $e) {
                    // Ignore if bad_data table not available
                }
            }
        }

        // Raw values from mapped column indices
        $picRac = ($map['pic_rac'] !== null && isset($row[$map['pic_rac']]))
            ? trim((string)$row[$map['pic_rac']])
            : '';

        $rencana = ($map['rencana_pelaksanaan'] !== null && isset($row[$map['rencana_pelaksanaan']]))
            ? trim((string)$row[$map['rencana_pelaksanaan']])
            : '';

        $tgl = ($map['tanggal_uji_petik'] !== null && isset($row[$map['tanggal_uji_petik']]))
            ? trim((string)$row[$map['tanggal_uji_petik']])
            : '';

        $dokumen = ($map['dokumen'] !== null && isset($row[$map['dokumen']]))
            ? trim((string)$row[$map['dokumen']])
            : '';

        $rawStatus = ($map['status'] !== null && isset($row[$map['status']]))
            ? trim((string)$row[$map['status']])
            : '';

        // Intelligent Content Swaps & Heuristic Fixes
        // 1. If status is in Dokumen column and Dokumen is empty or in Status column
        if (!isStatusPattern($rawStatus) && isStatusPattern($dokumen)) {
            if (isDocPattern($rawStatus)) {
                $temp = $rawStatus;
                $rawStatus = $dokumen;
                $dokumen = $temp;
            } else {
                $rawStatus = $dokumen;
                $dokumen = '-';
            }
        } elseif (!isStatusPattern($rawStatus) && isStatusPattern($tgl)) {
            $rawStatus = $tgl;
            $tgl = '-';
        }

        // 2. If Date is in Dokumen column and Dokumen name is in Tanggal column
        if (isDocPattern($tgl) && isDatePattern($dokumen)) {
            $temp = $tgl;
            $tgl = $dokumen;
            $dokumen = $temp;
        }

        // 3. If Rencana is in PIC RAC and PIC RAC name is in Rencana
        if (isRencanaPattern($picRac) && !isRencanaPattern($rencana) && !isDatePattern($rencana) && !isDocPattern($rencana) && !isStatusPattern($rencana)) {
            $temp = $picRac;
            $picRac = $rencana;
            $rencana = $temp;
        }

        // 4. If Date is in PIC RAC column
        if (isDatePattern($picRac) && !isDatePattern($tgl)) {
            $temp = $picRac;
            $picRac = ($existing['pic_rac'] ?? '');
            $tgl = $temp;
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

        // Rencana Pelaksanaan
        $rencana = formatUjiPetikDate($rencana);
        if (in_array(strtolower($rencana), ['rencana pelaksanaan', 'rencana', 'jadwal', '-'], true) || $rencana === '') {
            $rencana = (!empty($existing['rencana_pelaksanaan']) && $existing['rencana_pelaksanaan'] !== '-')
                ? $existing['rencana_pelaksanaan']
                : '-';
        }

        // Tanggal Uji Petik
        $formattedTgl = formatUjiPetikDate($tgl);
        if ($formattedTgl === '-' || $formattedTgl === '') {
            $formattedTgl = (!empty($existing['tanggal_uji_petik']) && $existing['tanggal_uji_petik'] !== '-')
                ? $existing['tanggal_uji_petik']
                : '-';
        }

        // Dokumen
        if (in_array(strtolower($dokumen), ['dokumen', 'nama dokumen', 'berkas', '-'], true) || $dokumen === '') {
            $dokumen = (!empty($existing['dokumen']) && $existing['dokumen'] !== '-')
                ? $existing['dokumen']
                : '-';
        }

        // Status
        $status = normalizeUjiPetikStatus($rawStatus);
        if ($rawStatus === '' && !empty($existing['status'])) {
            $status = $existing['status'];
        }

        // Posisi Bulan
        $month = ($map['month'] !== null && isset($row[$map['month']]) && trim((string)$row[$map['month']]) !== '')
            ? trim((string)$row[$map['month']])
            : ($existing['month'] ?? '');

        $month = formatUjiPetikMonth($month, $formattedTgl);

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
        if ($keterangan === null || $keterangan === '') {
            foreach ($row as $cIdx => $cVal) {
                if ($cIdx >= 5 && trim((string)$cVal) !== '') {
                    $cValTrim = trim((string)$cVal);
                    if (str_starts_with(strtolower($cValTrim), 'note') || strlen($cValTrim) > 8) {
                        $keterangan = $cValTrim;
                        break;
                    }
                }
            }
        }
        if (($keterangan === null || $keterangan === '') && isset($existing['keterangan'])) {
            $keterangan = $existing['keterangan'];
        }

        // Otomatis 'Telah dilaksanakan' jika rencana & tanggal terisi
        $hasRencana = ($rencana !== '' && $rencana !== '-');
        $hasTgl = ($formattedTgl !== '' && $formattedTgl !== '-');
        if (($hasRencana && $hasTgl) || $hasTgl) {
            $keterangan = 'Telah dilaksanakan';
            $status = 'Done';
        }

        $params = [
            ':no_urut'             => $noUrut,
            ':branch'              => $branch,
            ':pic_rac'             => $picRac,
            ':rencana_pelaksanaan' => $rencana,
            ':tanggal_uji_petik'   => $formattedTgl,
            ':dokumen'             => $dokumen,
            ':status'              => $status,
            ':month'               => $month,
            ':region'              => $region,
            ':keterangan'          => $keterangan,
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
                $rows = parseXlsxFile($tmpPath);
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

        [$header, $dataRows] = resolveUjiPetikHeadersAndRows($rows);
        if (!is_array($header) || empty($header)) {
            jsonResponse(false, 'Baris header tidak ditemukan.');
        }

        $map = getUjiPetikColumnMap($header);

        $pdo->beginTransaction();
        $result = saveUjiPetikRows($pdo, $dataRows, $map);
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
        $pdo->exec('TRUNCATE TABLE uji_petik');
        jsonResponse(true, 'Seluruh data uji petik berhasil direset.');
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
        $stmt = $pdo->prepare("DELETE FROM uji_petik WHERE id IN ({$inQuery})");
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
            $where[] = '(branch LIKE :search OR pic_rac LIKE :search OR rencana_pelaksanaan LIKE :search OR dokumen LIKE :search OR keterangan LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT * FROM uji_petik {$whereSql} ORDER BY id ASC";

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
