<?php
declare(strict_types=1);

$pageTitle = 'Data RAC';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

$pdo = getDbConnection();

$allowedTeams = [
    'Team Leader',
    'Team Member',
    'Buddy Out'
];

$importMessage = '';
$importType    = '';

function normalizeHeader(string $value): string {
    $value = trim(strtolower($value));
    return str_replace([' ', '-', '_', '.', '/', '\\', '(', ')', '[', ']', ':', ';'], '', $value);
}

function cleanCellValue(mixed $val): string {
    if ($val === null) {
        return '';
    }
    $str = trim((string)$val);
    if ($str === '' || $str === '-') {
        return '';
    }
    if (str_starts_with($str, '"') && str_ends_with($str, '"') && strlen($str) >= 2) {
        $str = trim(substr($str, 1, -1));
    }
    if (str_starts_with($str, "'") && strlen($str) >= 2) {
        $str = trim(substr($str, 1));
    }
    if (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $str)) {
        $num = (float)$str;
        $str = sprintf('%.0f', $num);
    }
    if (preg_match('/^(\d+)\.0+$/', $str, $m)) {
        $str = $m[1];
    }
    return $str;
}

function cleanPhoneNumber(string $val): string {
    $val = cleanCellValue($val);
    if ($val === '') {
        return '';
    }
    $val = preg_replace('/[^\d+]/', '', $val);
    if (str_starts_with($val, '+62')) {
        $val = '0' . substr($val, 3);
    } elseif (str_starts_with($val, '62') && strlen($val) >= 10) {
        $val = '0' . substr($val, 2);
    } elseif (str_starts_with($val, '8') && strlen($val) >= 9 && strlen($val) <= 13) {
        $val = '0' . $val;
    }
    return $val;
}

function normalizeRegion(string $value, array $masterRegions): string {
    $val = trim($value);
    if ($val === '') {
        return '';
    }

    if (isset($masterRegions[$val])) {
        return (string)$val;
    }

    if (is_numeric($val)) {
        $num = (int)$val;
        if (isset($masterRegions[$num])) {
            return (string)$num;
        }
    }

    $upper = strtoupper($val);

    if (preg_match('/(?:regional|region|rewgion|ro|wilayah|kanwil)\s*(\d+)/i', $upper, $matches)) {
        $num = (int)$matches[1];
        if (isset($masterRegions[$num])) {
            return (string)$num;
        }
    }

    $romanMap = [
        'XVIII' => '18', 'XVII' => '17', 'XVI' => '16', 'XV' => '15', 'XIV' => '14',
        'XIII' => '13', 'XII' => '12', 'XI' => '11', 'X' => '10', 'IX' => '9',
        'VIII' => '8', 'VII' => '7', 'VI' => '6', 'V' => '5', 'IV' => '4',
        'III' => '3', 'II' => '2', 'I' => '1'
    ];
    if (preg_match('/(?:regional|region|rewgion|ro|wilayah|kanwil)\s*([ivx]+)\b/i', $upper, $matches)) {
        $roman = strtoupper($matches[1]);
        if (isset($romanMap[$roman])) {
            return $romanMap[$roman];
        }
    }
    if (isset($romanMap[$upper])) {
        return $romanMap[$upper];
    }

    if (strpos($upper, 'MEDAN') !== false) return '1';
    if (strpos($upper, 'PEKANBARU') !== false) return '2';
    if (strpos($upper, 'PADANG') !== false) return '3';
    if (strpos($upper, 'PALEMBANG') !== false) return '4';
    if (strpos($upper, 'LAMPUNG') !== false) return '5';
    if (strpos($upper, 'JAKARTA 3') !== false || strpos($upper, 'DKI 3') !== false || strpos($upper, 'JKT 3') !== false) return '8';
    if (strpos($upper, 'JAKARTA 2') !== false || strpos($upper, 'DKI 2') !== false || strpos($upper, 'DKI2') !== false || strpos($upper, 'JKT 2') !== false) return '7';
    if (strpos($upper, 'JAKARTA 1') !== false || strpos($upper, 'DKI 1') !== false || strpos($upper, 'DKI1') !== false || $upper === 'DKI' || strpos($upper, 'JKT 1') !== false) return '6';
    if (strpos($upper, 'BANDUNG') !== false) return '9';
    if (strpos($upper, 'SEMARANG') !== false) return '10';
    if (strpos($upper, 'YOGYA') !== false || strpos($upper, 'JOGJA') !== false) return '11';
    if (strpos($upper, 'SURABAYA') !== false) return '12';
    if (strpos($upper, 'MALANG') !== false) return '13';
    if (strpos($upper, 'BANJARMASIN') !== false) return '14';
    if (strpos($upper, 'MAKASSAR') !== false) return '15';
    if (strpos($upper, 'MANADO') !== false) return '16';
    if (strpos($upper, 'DENPASAR') !== false || strpos($upper, 'BALI') !== false) return '17';
    if (strpos($upper, 'JAYAPURA') !== false || strpos($upper, 'PAPUA') !== false) return '18';

    return '';
}

function normalizeTeam(string $value): string {
    $val = trim(strtolower($value));
    $val = str_replace(['-', '_', '/', '\\', '.', ':', ';', '(', ')', '[', ']', '='], ' ', $val);
    $val = preg_replace('/\s+/', ' ', $val);

    $exactMap = [
        'team leader'  => 'Team Leader',
        'teamleader'   => 'Team Leader',
        'tl'           => 'Team Leader',
        'leader'       => 'Team Leader',
        'ketua'        => 'Team Leader',
        'ketua tim'    => 'Team Leader',
        'ka tim'       => 'Team Leader',
        'katim'        => 'Team Leader',
        'koordinator'  => 'Team Leader',
        'penyelia'     => 'Team Leader',
        'pengawas'     => 'Team Leader',
        'spv'          => 'Team Leader',
        'supervisor'   => 'Team Leader',
        'head'         => 'Team Leader',
        'pic'          => 'Team Leader',
        'lead'         => 'Team Leader',

        'team member'  => 'Team Member',
        'teammember'   => 'Team Member',
        'tm'           => 'Team Member',
        'member'       => 'Team Member',
        'anggota'      => 'Team Member',
        'staff'        => 'Team Member',
        'staf'         => 'Team Member',
        'petugas'      => 'Team Member',
        'pelaksana'    => 'Team Member',
        'pekerja'      => 'Team Member',
        'officer'      => 'Team Member',
        'analis'       => 'Team Member',
        'analyst'      => 'Team Member',
        'admin'        => 'Team Member',
        'administrasi' => 'Team Member',
        'operator'     => 'Team Member',
        'frontliner'   => 'Team Member',

        'buddy out'    => 'Buddy Out',
        'buddyout'     => 'Buddy Out',
        'buddy ojt'    => 'Buddy Out',
        'buddyojt'     => 'Buddy Out',
        'ojt'          => 'Buddy Out',
        'bo'           => 'Buddy Out',
        'buddy'        => 'Buddy Out',
        'pendamping'   => 'Buddy Out',
        'penyanding'   => 'Buddy Out',
        'out'          => 'Buddy Out',
        'bko'          => 'Buddy Out',
        'bantuan'      => 'Buddy Out',
        'support'      => 'Buddy Out',
        'magang'       => 'Buddy Out',
        'intern'       => 'Buddy Out',
        'outsourcing'  => 'Buddy Out',
        'outsource'    => 'Buddy Out',
        'mitra'        => 'Buddy Out',
        'eksternal'    => 'Buddy Out'
    ];

    if (isset($exactMap[$val])) {
        return $exactMap[$val];
    }

    if (strpos($val, 'lead') !== false || strpos($val, 'ketua') !== false || strpos($val, 'katim') !== false || strpos($val, 'spv') !== false || strpos($val, 'penyelia') !== false || preg_match('/\btl\b/', $val)) {
        return 'Team Leader';
    }
    if (strpos($val, 'buddy') !== false || strpos($val, 'pendamping') !== false || strpos($val, 'magang') !== false || strpos($val, 'mitra') !== false || preg_match('/\bbo\b/', $val)) {
        return 'Buddy Out';
    }
    if (strpos($val, 'member') !== false || strpos($val, 'anggota') !== false || strpos($val, 'staf') !== false || strpos($val, 'staff') !== false || strpos($val, 'officer') !== false || strpos($val, 'petugas') !== false || strpos($val, 'pelaksana') !== false || preg_match('/\btm\b/', $val)) {
        return 'Team Member';
    }

    return '';
}

function isHeaderLabel(string $norm): bool {
    $exactHeaderLabels = [
        'nama', 'namalengkap', 'pic', 'personil', 'karyawan', 'namapersonil', 'namarac', 'namaanggota', 'namapetugas',
        'pn', 'perner', 'personalnumber', 'personalno', 'nopekerja', 'nomorpekerja', 'nip', 'npp', 'id', 'nopegawai', 'nomorpegawai', 'nik', 'nomorinduk', 'badgeno', 'badge', 'nrp', 'idrac',
        'telepon', 'telp', 'notelp', 'notelepon', 'hp', 'nohp', 'nomorhp', 'handphone', 'nohandphone', 'kontak', 'wa', 'nowa', 'whatsapp', 'phone', 'mobile',
        'team', 'tim', 'peran', 'role', 'posisi', 'jabatan', 'kategori', 'status', 'bagian', 'divisi', 'keterangan', 'ket',
        'region', 'ro', 'wilayah', 'kanwil', 'kantorwilayah', 'regional', 'regionaloffice',
        'no', 'nomor'
    ];
    return in_array($norm, $exactHeaderLabels, true);
}

function isNonPersonName(string $name): bool {
    $norm = trim(strtolower($name));
    $normClean = str_replace([' ', '-', '_', '.', '/', '\\', ':', ';', '(', ')'], '', $norm);

    $badExact = [
        'teammember', 'teamleader', 'buddyout', 'formasi', 'pemenuhan', 'gap', 'target', 'realisasi', 'selisih', 'kebutuhan',
        'jumlah', 'total', 'subtotal', 'grandtotal',
        'kategori', 'posisi', 'jabatan', 'peran', 'status', 'bagian', 'divisi', 'keterangan', 'ket',
        'region', 'ro', 'wilayah', 'kanwil', 'kantorwilayah', 'regional', 'regionaloffice',
        'medan', 'pekanbaru', 'padang', 'palembang', 'bandarlampung', 'lampung',
        'jakarta1', 'jakarta2', 'jakarta3', 'dki1', 'dki2', 'dki3', 'dki',
        'bandung', 'semarang', 'yogyakarta', 'jogja', 'surabaya', 'malang',
        'banjarmasin', 'makassar', 'manado', 'denpasar', 'bali', 'jayapura', 'papua',
        'nama', 'namalengkap', 'namapersonil', 'personil', 'karyawan', 'pn', 'perner', 'nip',
        'telepon', 'nohp', 'handphone', 'kontak', 'no', 'nomor', 'noperner'
    ];

    if (in_array($normClean, $badExact, true)) {
        return true;
    }

    if (preg_match('/^(total|jumlah|grand\s*total|sub\s*total|formasi|rekap|pemenuhan|gap|target|realisasi)(\s|$|:)/i', $norm)) {
        return true;
    }

    if (preg_match('/^(regional|region|rewgion|ro)\s*\d+$/i', $norm)) {
        return true;
    }

    return false;
}

function parseXlsxSheets(string $filePath): array {
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

    $sheetsMeta = [];
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbookXml !== false && $relsXml !== false) {
        $wbObj = simplexml_load_string($workbookXml);
        $relsObj = simplexml_load_string($relsXml);

        $idToTarget = [];
        if ($relsObj && isset($relsObj->Relationship)) {
            foreach ($relsObj->Relationship as $rel) {
                $rId = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . ltrim($target, '/');
                }
                $idToTarget[$rId] = $target;
            }
        }

        if ($wbObj && isset($wbObj->sheets->sheet)) {
            foreach ($wbObj->sheets->sheet as $s) {
                $name = (string)$s['name'];
                $rId = '';
                $attrR = $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                if (isset($attrR['id'])) {
                    $rId = (string)$attrR['id'];
                } elseif (isset($s['r:id'])) {
                    $rId = (string)$s['r:id'];
                }
                $target = $idToTarget[$rId] ?? null;
                if ($target !== null) {
                    $sheetsMeta[] = [
                        'name' => $name,
                        'file' => $target
                    ];
                }
            }
        }
    }

    if (empty($sheetsMeta)) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/.*\.xml$#i', $entry)) {
                $sheetsMeta[] = [
                    'name' => 'Sheet ' . (count($sheetsMeta) + 1),
                    'file' => $entry
                ];
            }
        }
    }

    $result = [];
    foreach ($sheetsMeta as $meta) {
        $sheetXml = $zip->getFromName($meta['file']);
        if ($sheetXml === false) {
            continue;
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData->row)) {
            continue;
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

        if (!empty($rows)) {
            $result[] = [
                'name' => $meta['name'],
                'rows' => $rows
            ];
        }
    }

    $zip->close();
    return $result;
}

function parseCsvSheets(string $filePath): array {
    $content = (string)file_get_contents($filePath);
    if (trim($content) === '') {
        return [];
    }

    if (stripos($content, '<table') !== false && stripos($content, '<tr') !== false) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        $trNodes = $dom->getElementsByTagName('tr');
        $htmlRows = [];
        foreach ($trNodes as $tr) {
            $row = [];
            foreach ($tr->childNodes as $child) {
                if ($child->nodeName === 'td' || $child->nodeName === 'th') {
                    $row[] = trim($child->textContent);
                }
            }
            if (!empty(array_filter($row, fn($c) => $c !== ''))) {
                $htmlRows[] = $row;
            }
        }
        if (!empty($htmlRows)) {
            return [
                [
                    'name' => 'Table',
                    'rows' => $htmlRows
                ]
            ];
        }
    }

    $firstLine = strtok($content, "\r\n") ?: '';
    $delimiters = ['|' => 0, ';' => 0, ',' => 0, "\t" => 0];
    foreach ($delimiters as $delim => &$count) {
        $count = substr_count($firstLine, $delim);
    }
    arsort($delimiters);
    $delimiter = array_key_first($delimiters);
    if (!isset($delimiters[$delimiter]) || $delimiters[$delimiter] === 0) {
        $delimiter = ',';
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $rows = [];

    foreach ($lines as $line) {
        $clean = trim($line);
        if ($clean === '') {
            continue;
        }

        if (str_starts_with($clean, '"') && str_ends_with($clean, '"') && strpos($clean, $delimiter) !== false) {
            $clean = substr($clean, 1, -1);
            $clean = str_replace('""', '"', $clean);
        }

        $row = str_getcsv($clean, $delimiter, '"', '\\');
        $rows[] = array_map(function ($v) {
            $val = trim((string)$v);
            if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                $val = trim(substr($val, 1, -1));
            }
            return $val;
        }, $row);
    }

    return [
        [
            'name' => 'CSV',
            'rows' => $rows
        ]
    ];
}

function extractRacRecords(
    array $sheets, 
    array $masterRegions, 
    string $defaultFallbackRegion = '', 
    string $defaultFallbackTeam = ''
): array {
    $extracted = [];

    // Filter sheets: skip summary, pivot, and duplicate sheets
    $hasNamedSheet = false;
    foreach ($sheets as $s) {
        $n = trim($s['name'] ?? '');
        if (!preg_match('/^sheet\d+$/i', $n)) {
            $hasNamedSheet = true;
            break;
        }
    }

    $validSheets = [];
    foreach ($sheets as $s) {
        $n = trim($s['name'] ?? '');
        $lower = strtolower($n);
        if (preg_match('/^(copy of|salinan|formasi|rekap|summary|ringkasan|grafik|chart|dashboard|pivot)/i', $lower)) {
            continue;
        }
        if ($hasNamedSheet && preg_match('/^sheet\d+$/i', $lower)) {
            continue;
        }
        $validSheets[] = $s;
    }

    if (empty($validSheets)) {
        $validSheets = $sheets;
    }

    foreach ($validSheets as $sheet) {
        $sheetName = $sheet['name'] ?? 'Sheet';
        $rows = $sheet['rows'] ?? [];
        if (empty($rows)) {
            continue;
        }

        $sheetTeam = normalizeTeam($sheetName);
        $sheetRegion = normalizeRegion($sheetName, $masterRegions);

        $currentSectionTeam = $sheetTeam !== '' ? $sheetTeam : $defaultFallbackTeam;
        $currentSectionRegion = $sheetRegion !== '' ? $sheetRegion : $defaultFallbackRegion;
        $lastKnownRegion = $currentSectionRegion;

        $headerMap = null;
        $sideBySideMap = null;
        $pendingRoleCols = [];
        $pendingTopHeaderRow = null;

        foreach ($rows as $rawRow) {
            $row = array_map('cleanCellValue', $rawRow);

            $nonEmptyCells = array_values(array_filter($row, fn($c) => $c !== ''));
            if (empty($nonEmptyCells)) {
                continue;
            }

            $joinedRow = implode(' ', $nonEmptyCells);

            // 1. Check if this row is a Top-Level Merged Header with multiple teams
            // (e.g. [No, Region, Team Leader, ..., Team Member, ..., Buddy OJT])
            $rowTeamPositions = [];
            foreach ($row as $cIdx => $cellVal) {
                $cTeam = normalizeTeam($cellVal);
                if ($cTeam !== '') {
                    $rowTeamPositions[$cIdx] = $cTeam;
                }
            }
            if (count(array_unique(array_values($rowTeamPositions))) >= 2) {
                $pendingRoleCols = $rowTeamPositions;
                $pendingTopHeaderRow = $row;
                $headerMap = null;
                $sideBySideMap = null;
                continue;
            }

            // 2. Detect Section Banner ONLY if row does not have data cells (PN, phone, person name)
            $hasDataLikeValues = false;
            foreach ($nonEmptyCells as $c) {
                if (preg_match('/^\d{4,}$/', $c) || preg_match('/^(?:\+?62|08|02)\d{6,}/', $c)) {
                    $hasDataLikeValues = true;
                    break;
                }
            }

            if (!$hasDataLikeValues && count($nonEmptyCells) <= 3 && ($headerMap === null && $sideBySideMap === null)) {
                $possibleTeam = normalizeTeam($joinedRow);
                $possibleRegion = normalizeRegion($joinedRow, $masterRegions);

                $normJoined = normalizeHeader($joinedRow);
                $hasHeaderWords = preg_match('/(namalengkap|pic|personil|karyawan|perner|telepon|handphone|nomorhp|pn|jabatan)/i', $normJoined);

                if (!$hasHeaderWords && ($possibleTeam !== '' || $possibleRegion !== '')) {
                    if ($possibleTeam !== '' && $possibleRegion === '') {
                        $currentSectionTeam = $possibleTeam;
                        $headerMap = null;
                        $sideBySideMap = null;
                        $pendingRoleCols = [];
                        $pendingTopHeaderRow = null;
                        continue;
                    }
                    if ($possibleRegion !== '' && $possibleTeam === '') {
                        $currentSectionRegion = $possibleRegion;
                        $lastKnownRegion = $possibleRegion;
                        continue;
                    }
                    if ($possibleTeam !== '' && $possibleRegion !== '') {
                        $currentSectionTeam = $possibleTeam;
                        $currentSectionRegion = $possibleRegion;
                        $lastKnownRegion = $possibleRegion;
                        $headerMap = null;
                        $sideBySideMap = null;
                        $pendingRoleCols = [];
                        $pendingTopHeaderRow = null;
                        continue;
                    }
                }
            }

            // 3. Detect Table Header
            $normRow = array_map('normalizeHeader', $row);

            $hasNamaKeyword = false;
            $hasPnKeyword   = false;
            $hasTelpKeyword = false;
            $hasTeamKeyword = false;
            $hasRegionKeyword = false;

            foreach ($normRow as $colIdx => $h) {
                if ($h === '') continue;
                if (preg_match('/(nama|namalengkap|pic|personil|namarac|karyawan|petugas)/i', $h) && !preg_match('/(^pn\b|pn$|perner|nip|telp|hp|telepon)/i', $h)) {
                    $hasNamaKeyword = true;
                }
                if (preg_match('/(^pn\b|pn$|perner|personal|pekerja|pegawai|nip|npp|nik|nrp|badge|^id$|idrac|idpegawai|idkaryawan)/i', $h) && !preg_match('/(nama|pic|personil)/i', $h)) {
                    $hasPnKeyword = true;
                }
                if (preg_match('/(^hp$|^hp|nohp|nomorhp|telepon|telp|notelp|notelepon|handphone|nohandphone|kontak|wa|nowa|phone|mobile)/i', $h)) {
                    $hasTelpKeyword = true;
                }
                if (preg_match('/(team|tim|peran|role|posisi|jabatan|kategori|status|bagian|divisi)/i', $h) && !preg_match('/(nama|pn|telp|hp)/i', $h)) {
                    $hasTeamKeyword = true;
                }
                if (preg_match('/(region|ro|wilayah|kanwil|kantorwilayah)/i', $h)) {
                    $hasRegionKeyword = true;
                }
            }

            $isHeader = ($hasNamaKeyword && ($hasPnKeyword || $hasTelpKeyword || $hasTeamKeyword || $hasRegionKeyword || in_array('no', $normRow, true) || in_array('nomor', $normRow, true))) ||
                        ($hasPnKeyword && $hasTelpKeyword);

            if ($isHeader) {
                // A. Check Two-Row Merged Header with pendingRoleCols
                if (!empty($pendingRoleCols)) {
                    $sbMap = [];
                    $assignedRoles = [];
                    $colIndices = array_keys($pendingRoleCols);
                    sort($colIndices);

                    foreach ($normRow as $colIdx => $h) {
                        if ($h === '') continue;

                        $activeRole = null;
                        for ($idx = count($colIndices) - 1; $idx >= 0; $idx--) {
                            if ($colIdx >= $colIndices[$idx]) {
                                $activeRole = $pendingRoleCols[$colIndices[$idx]];
                                break;
                            }
                        }

                        if ($activeRole !== null) {
                            $assignedRoles[$activeRole] = true;
                            if (preg_match('/(^pn\b|pn$|perner|personal|pekerja|pegawai|nip|npp|nik|nrp|badge|^id$|idrac)/i', $h) && !preg_match('/(nama|pic|personil)/i', $h)) {
                                $sbMap[$activeRole]['pn'] = $colIdx;
                            } elseif (preg_match('/(nama|namalengkap|pic|personil|namarac|karyawan)/i', $h)) {
                                $sbMap[$activeRole]['nama'] = $colIdx;
                            } elseif (preg_match('/(^hp$|^hp|nohp|nomorhp|telepon|telp|notelp|notelepon|handphone|kontak|wa)/i', $h)) {
                                $sbMap[$activeRole]['telepon'] = $colIdx;
                            }
                        }
                    }

                    if (count($assignedRoles) >= 2) {
                        $commonRegionCol = null;
                        foreach ($normRow as $colIdx => $h) {
                            if (preg_match('/(region|ro|wilayah|kanwil)/i', $h)) {
                                $commonRegionCol = $colIdx;
                                break;
                            }
                        }
                        if ($commonRegionCol === null && $pendingTopHeaderRow !== null) {
                            foreach ($pendingTopHeaderRow as $colIdx => $val) {
                                if (preg_match('/(region|ro|wilayah|kanwil)/i', normalizeHeader($val))) {
                                    $commonRegionCol = $colIdx;
                                    break;
                                }
                            }
                        }

                        foreach ($sbMap as $r => $cols) {
                            $sbMap[$r]['region'] = $commonRegionCol;
                        }
                        $sideBySideMap = $sbMap;
                        $headerMap = null;
                        $pendingRoleCols = [];
                        $pendingTopHeaderRow = null;
                        continue;
                    }
                }

                // B. Single-Row Side-by-Side header
                $sbMap = [];
                $rolesDetected = [];

                foreach ($normRow as $colIdx => $h) {
                    if ($h === '') continue;

                    $matchedRole = '';
                    if (preg_match('/^(tl|teamleader|leader|ketua|katim|spv|penyelia|pengawas)/i', $h)) {
                        $matchedRole = 'Team Leader';
                    } elseif (preg_match('/^(tm|teammember|member|anggota|staff|staf|petugas|pelaksana|pekerja|officer|analis)/i', $h)) {
                        $matchedRole = 'Team Member';
                    } elseif (preg_match('/^(bo|buddyout|buddy|pendamping|penyanding|magang|bko|mitra|out)/i', $h)) {
                        $matchedRole = 'Buddy Out';
                    }

                    if ($matchedRole !== '') {
                        $rolesDetected[$matchedRole] = true;
                        if (preg_match('/(pn|perner|personal|pekerja|pegawai|nip|npp|nik|nrp|badge|^id$|idrac)/i', $h) && !preg_match('/(nama|pic|personil)/i', $h)) {
                            $sbMap[$matchedRole]['pn'] = $colIdx;
                        } elseif (preg_match('/(nama|namalengkap|pic|personil|namarac|karyawan)/i', $h)) {
                            $sbMap[$matchedRole]['nama'] = $colIdx;
                        } elseif (preg_match('/(^hp$|^hp|nohp|nomorhp|telepon|telp|notelp|notelepon|handphone|kontak|wa)/i', $h)) {
                            $sbMap[$matchedRole]['telepon'] = $colIdx;
                        }
                    }
                }

                if (count($rolesDetected) >= 2) {
                    $commonRegionCol = null;
                    foreach ($normRow as $colIdx => $h) {
                        if (preg_match('/(region|ro|wilayah|kanwil)/i', $h)) {
                            $commonRegionCol = $colIdx;
                            break;
                        }
                    }
                    foreach ($sbMap as $r => $cols) {
                        $sbMap[$r]['region'] = $commonRegionCol;
                    }
                    $sideBySideMap = $sbMap;
                    $headerMap = null;
                    $pendingRoleCols = [];
                    $pendingTopHeaderRow = null;
                    continue;
                }

                // C. Standard Single Table Header
                $headerMap = [
                    'region'    => null,
                    'team'      => null,
                    'pn'        => null,
                    'nama'      => null,
                    'telepon'   => null
                ];

                foreach ($normRow as $colIdx => $h) {
                    if ($headerMap['region'] === null && preg_match('/(region|ro|wilayah|kanwil|kantorwilayah)/i', $h)) {
                        $headerMap['region'] = $colIdx;
                    } elseif ($headerMap['team'] === null && preg_match('/(team|tim|peran|role|posisi|jabatan|kategori|status|bagian|divisi)/i', $h) && !preg_match('/(nama|pn|telp|hp)/i', $h)) {
                        $headerMap['team'] = $colIdx;
                    } elseif ($headerMap['pn'] === null && preg_match('/(^pn\b|pn$|perner|personal|pekerja|pegawai|nip|npp|nik|nrp|badge|^id$|idrac|idpegawai|idkaryawan)/i', $h) && !preg_match('/(nama|pic|personil)/i', $h)) {
                        $headerMap['pn'] = $colIdx;
                    } elseif ($headerMap['nama'] === null && preg_match('/(nama|namalengkap|pic|personil|namarac|karyawan|petugas)/i', $h)) {
                        $headerMap['nama'] = $colIdx;
                    } elseif ($headerMap['telepon'] === null && preg_match('/(^hp$|^hp|nohp|nomorhp|telepon|telp|notelp|notelepon|handphone|nohandphone|kontak|wa|phone|mobile)/i', $h)) {
                        $headerMap['telepon'] = $colIdx;
                    }
                }

                $sideBySideMap = null;
                $pendingRoleCols = [];
                $pendingTopHeaderRow = null;
                continue;
            }

            // 4. Process Data Row via Side-by-Side Map
            if ($sideBySideMap !== null) {
                $rowRegion = '';
                $firstRoleKey = array_key_first($sideBySideMap);
                $commonRegionIdx = $sideBySideMap[$firstRoleKey]['region'] ?? null;
                if ($commonRegionIdx !== null && isset($row[$commonRegionIdx]) && $row[$commonRegionIdx] !== '') {
                    $rowRegion = normalizeRegion($row[$commonRegionIdx], $masterRegions);
                }
                if ($rowRegion === '') {
                    for ($c = 0; $c < min(3, count($row)); $c++) {
                        if ($row[$c] !== '') {
                            $r = normalizeRegion($row[$c], $masterRegions);
                            if ($r !== '') {
                                $rowRegion = $r;
                                break;
                            }
                        }
                    }
                }
                if ($rowRegion === '') {
                    $rowRegion = $currentSectionRegion !== '' ? $currentSectionRegion : ($lastKnownRegion !== '' ? $lastKnownRegion : ($defaultFallbackRegion ?: '1'));
                } else {
                    $lastKnownRegion = $rowRegion;
                }

                foreach ($sideBySideMap as $role => $cols) {
                    $nama = isset($cols['nama'], $row[$cols['nama']]) ? trim($row[$cols['nama']]) : '';
                    if ($nama === '' || $nama === '-' || isHeaderLabel(normalizeHeader($nama)) || isNonPersonName($nama)) continue;

                    $pn = isset($cols['pn'], $row[$cols['pn']]) ? cleanCellValue($row[$cols['pn']]) : '';
                    $telp = isset($cols['telepon'], $row[$cols['telepon']]) ? cleanPhoneNumber($row[$cols['telepon']]) : '';

                    $extracted[] = [
                        'region'  => $rowRegion ?: ($defaultFallbackRegion ?: '1'),
                        'team'    => $role,
                        'pn'      => $pn,
                        'nama'    => $nama,
                        'telepon' => $telp
                    ];
                }
                continue;
            }

            // 5. Process Data Row via Header Map
            if ($headerMap !== null) {
                $rawRegion = $headerMap['region'] !== null && isset($row[$headerMap['region']]) ? $row[$headerMap['region']] : '';
                $rawTeam   = $headerMap['team'] !== null && isset($row[$headerMap['team']]) ? $row[$headerMap['team']] : '';
                $rawPn     = $headerMap['pn'] !== null && isset($row[$headerMap['pn']]) ? $row[$headerMap['pn']] : '';
                $rawNama   = $headerMap['nama'] !== null && isset($row[$headerMap['nama']]) ? $row[$headerMap['nama']] : '';
                $rawTelp   = $headerMap['telepon'] !== null && isset($row[$headerMap['telepon']]) ? $row[$headerMap['telepon']] : '';

                $nama = trim($rawNama);
                if ($nama === '' || $nama === '-' || isHeaderLabel(normalizeHeader($nama)) || isNonPersonName($nama)) {
                    continue;
                }

                $region = normalizeRegion($rawRegion, $masterRegions);
                if ($region === '') {
                    $region = $currentSectionRegion !== '' ? $currentSectionRegion : ($lastKnownRegion !== '' ? $lastKnownRegion : ($defaultFallbackRegion ?: '1'));
                } else {
                    $lastKnownRegion = $region;
                }

                $team = normalizeTeam($rawTeam);
                if ($team === '') {
                    $team = $currentSectionTeam;
                }
                if ($team === '') {
                    $team = $defaultFallbackTeam;
                }
                if ($team === '') {
                    $rowText = strtolower($joinedRow);
                    if (str_contains($rowText, 'leader') || str_contains($rowText, 'ketua') || str_contains($rowText, 'katim') || str_contains($rowText, 'spv') || preg_match('/\btl\b/', $rowText)) {
                        $team = 'Team Leader';
                    } elseif (str_contains($rowText, 'buddy') || str_contains($rowText, 'pendamping') || str_contains($rowText, 'magang') || preg_match('/\bbo\b/', $rowText)) {
                        $team = 'Buddy Out';
                    } elseif ($rawPn === '' || $rawPn === '-') {
                        $team = 'Buddy Out';
                    } else {
                        $team = 'Team Member';
                    }
                }

                $pn = cleanCellValue($rawPn);
                $telp = cleanPhoneNumber($rawTelp);

                $extracted[] = [
                    'region'  => $region ?: ($defaultFallbackRegion ?: '1'),
                    'team'    => $team,
                    'pn'      => $pn,
                    'nama'    => $nama,
                    'telepon' => $telp
                ];
                continue;
            }

            // 6. Headerless Fallback (Heuristic Cell Detection)
            $detectedTelp = '';
            $detectedPn = '';
            $detectedRegion = '';
            $detectedTeam = '';
            $detectedNama = '';

            foreach ($row as $cell) {
                if ($cell === '' || $cell === '-' || isHeaderLabel(normalizeHeader($cell))) continue;

                if ($detectedRegion === '') {
                    $reg = normalizeRegion($cell, $masterRegions);
                    if ($reg !== '') {
                        $detectedRegion = $reg;
                        continue;
                    }
                }

                if ($detectedTeam === '') {
                    $tm = normalizeTeam($cell);
                    if ($tm !== '') {
                        $detectedTeam = $tm;
                        continue;
                    }
                }

                if ($detectedTelp === '' && preg_match('/^(?:\+?62|08|02)\d{7,13}$/', preg_replace('/[^\d+]/', '', $cell))) {
                    $detectedTelp = cleanPhoneNumber($cell);
                    continue;
                }

                if ($detectedPn === '' && preg_match('/^\d{5,10}$/', $cell)) {
                    $detectedPn = cleanCellValue($cell);
                    continue;
                }

                if ($detectedNama === '' && preg_match('/^[a-zA-Z\s.,\']{3,}$/', $cell)) {
                    if (!isNonPersonName($cell)) {
                        $detectedNama = $cell;
                        continue;
                    }
                }
            }

            if ($detectedNama !== '' && !isNonPersonName($detectedNama)) {
                $region = $detectedRegion !== '' ? $detectedRegion : ($currentSectionRegion !== '' ? $currentSectionRegion : ($lastKnownRegion !== '' ? $lastKnownRegion : ($defaultFallbackRegion ?: '1')));
                if ($detectedRegion !== '') {
                    $lastKnownRegion = $detectedRegion;
                }

                $team = $detectedTeam !== '' ? $detectedTeam : ($currentSectionTeam !== '' ? $currentSectionTeam : $defaultFallbackTeam);
                if ($team === '') {
                    if ($detectedPn === '') {
                        $team = 'Buddy Out';
                    } else {
                        $team = 'Team Member';
                    }
                }

                $extracted[] = [
                    'region'  => $region ?: ($defaultFallbackRegion ?: '1'),
                    'team'    => $team,
                    'pn'      => $detectedPn,
                    'nama'    => $detectedNama,
                    'telepon' => $detectedTelp
                ];
            }
        }
    }

    // Deduplicate & Merge Records
    $merged = [];
    foreach ($extracted as $item) {
        $reg = $item['region'];
        $team = $item['team'];
        $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $item['nama']));
        $keyName = $reg . '|' . $team . '|' . $cleanName;
        $keyPn = ($item['pn'] !== '') ? ($reg . '|' . $team . '|pn_' . $item['pn']) : null;

        $targetKey = null;
        if ($keyPn !== null && isset($merged[$keyPn])) {
            $targetKey = $keyPn;
        } elseif (isset($merged[$keyName])) {
            $targetKey = $keyName;
        }

        if ($targetKey !== null) {
            if ($merged[$targetKey]['pn'] === '' && $item['pn'] !== '') {
                $merged[$targetKey]['pn'] = $item['pn'];
            }
            if ($merged[$targetKey]['telepon'] === '' && $item['telepon'] !== '') {
                $merged[$targetKey]['telepon'] = $item['telepon'];
            }
        } else {
            $chosenKey = $keyPn ?? $keyName;
            $merged[$chosenKey] = $item;
            if ($keyPn !== null) {
                $merged[$keyName] = &$merged[$chosenKey];
            }
        }
    }

    $final = [];
    $seenIds = [];
    foreach ($merged as $it) {
        $id = $it['region'] . '|' . $it['team'] . '|' . $it['pn'] . '|' . strtolower(trim($it['nama']));
        if (!isset($seenIds[$id])) {
            $seenIds[$id] = true;
            $final[] = $it;
        }
    }

    return $final;
}

// --------------------------------------------------------------------------
// PROSES IMPORT EXCEL / CSV LANGSUNG KE DATABASE
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'import_rac') {
        $uploadError = $_FILES['file_import']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!isset($_FILES['file_import']) || $uploadError !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas upload server (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas form.',
                UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian. Silakan coba lagi.',
                UPLOAD_ERR_NO_FILE    => 'Silakan pilih file Excel atau CSV terlebih dahulu.',
                UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
                UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server.',
            ];
            $importMessage = $errMap[$uploadError] ?? 'Gagal mengunggah file (kode error: ' . $uploadError . ').';
            $importType = 'error';
        } else {
            $fileName = (string)$_FILES['file_import']['name'];
            $fileTmp  = (string)$_FILES['file_import']['tmp_name'];
            $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            try {
                $sheets = [];
                if ($ext === 'xlsx') {
                    try {
                        $sheets = parseXlsxSheets($fileTmp);
                    } catch (Throwable $e) {
                        $sheets = parseCsvSheets($fileTmp);
                        if (empty($sheets)) {
                            throw $e;
                        }
                    }
                } elseif ($ext === 'csv' || $ext === 'txt') {
                    $sheets = parseCsvSheets($fileTmp);
                } elseif ($ext === 'xls') {
                    try {
                        $sheets = parseXlsxSheets($fileTmp);
                    } catch (Throwable $e) {
                        $sheets = parseCsvSheets($fileTmp);
                    }
                } else {
                    throw new RuntimeException('Format berkas tidak didukung (' . htmlspecialchars($ext) . '). Harap gunakan CSV atau XLSX.');
                }

            if (empty($sheets)) {
                throw new RuntimeException('Berkas kosong atau tidak ada data yang dapat dibaca.');
            }

            $targetRegion = trim((string)($_POST['target_region'] ?? ''));
            $targetTeam   = trim((string)($_POST['target_team'] ?? ''));

            // Jika user memilih "Otomatis dari Excel", deteksi apakah nama berkas menyertakan Region/Team
            if ($targetRegion === '') {
                $fileRegion = normalizeRegion($fileName, MASTER_REGIONS);
                if ($fileRegion !== '') {
                    $targetRegion = $fileRegion;
                }
            }
            if ($targetTeam === '') {
                $fileTeam = normalizeTeam($fileName);
                if ($fileTeam !== '') {
                    $targetTeam = $fileTeam;
                }
            }

            $extracted = extractRacRecords($sheets, MASTER_REGIONS, $targetRegion, $targetTeam);

            if (empty($extracted)) {
                throw new RuntimeException('Tidak ditemukan data personil RAC dalam berkas tersebut.');
            }

            $checkByPnStmt = $pdo->prepare('SELECT id, region, team, pn, nama, telepon FROM data_rac WHERE region = :region AND team = :team AND pn = :pn AND pn != "" LIMIT 1');
            $checkByNameStmt = $pdo->prepare('SELECT id, region, team, pn, nama, telepon FROM data_rac WHERE region = :region AND team = :team AND LOWER(TRIM(nama)) = LOWER(TRIM(:nama)) LIMIT 1');
            $insertStmt = $pdo->prepare('INSERT INTO data_rac (region, team, pn, nama, telepon) VALUES (:region, :team, :pn, :nama, :telepon)');
            $updateStmt = $pdo->prepare('
                UPDATE data_rac 
                SET pn = :pn,
                    nama = :nama,
                    telepon = :telepon,
                    region = :region
                WHERE id = :id
            ');

            $stats = [
                'Team Leader' => ['new' => 0, 'updated' => 0],
                'Team Member' => ['new' => 0, 'updated' => 0],
                'Buddy Out'   => ['new' => 0, 'updated' => 0]
            ];

            $replaceAll = !empty($_POST['replace_all']);
            $pdo->beginTransaction();
            if ($replaceAll) {
                $pdo->exec("DELETE FROM data_rac");
            }

            foreach ($extracted as $item) {
                $team = $item['team'];
                if (!isset($stats[$team])) {
                    continue;
                }

                if ($replaceAll) {
                    // Mode sinkronisasi data: simpan seluruh baris 1-to-1 sesuai file Excel
                    $insertStmt->execute([
                        ':region'  => $item['region'],
                        ':team'    => $team,
                        ':pn'      => $item['pn'],
                        ':nama'    => $item['nama'],
                        ':telepon' => $item['telepon']
                    ]);
                    $stats[$team]['new']++;
                } else {
                    $existing = false;
                    if (!empty($item['pn'])) {
                        $checkByPnStmt->execute([
                            ':region' => $item['region'],
                            ':team'   => $team,
                            ':pn'     => $item['pn']
                        ]);
                        $existing = $checkByPnStmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if (!$existing) {
                        $checkByNameStmt->execute([
                            ':region' => $item['region'],
                            ':team'   => $team,
                            ':nama'   => $item['nama']
                        ]);
                        $existing = $checkByNameStmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($existing) {
                        $newPn = $item['pn'] !== '' ? $item['pn'] : (string)($existing['pn'] ?? '');
                        $newNama = $item['nama'] !== '' ? $item['nama'] : (string)($existing['nama'] ?? '');
                        $newTelp = $item['telepon'] !== '' ? $item['telepon'] : (string)($existing['telepon'] ?? '');
                        $newRegion = $item['region'] !== '' ? $item['region'] : (string)($existing['region'] ?? '1');

                        $updateStmt->execute([
                            ':pn'      => $newPn,
                            ':nama'    => $newNama,
                            ':telepon' => $newTelp,
                            ':region'  => $newRegion,
                            ':id'      => $existing['id']
                        ]);
                        $stats[$existing['team']]['updated']++;
                    } else {
                        $insertStmt->execute([
                            ':region'  => $item['region'],
                            ':team'    => $team,
                            ':pn'      => $item['pn'],
                            ':nama'    => $item['nama'],
                            ':telepon' => $item['telepon']
                        ]);
                        $stats[$team]['new']++;
                    }
                }
            }
            $pdo->commit();

            $totalNew = $stats['Team Leader']['new'] + $stats['Team Member']['new'] + $stats['Buddy Out']['new'];
            $totalUpdated = $stats['Team Leader']['updated'] + $stats['Team Member']['updated'] + $stats['Buddy Out']['updated'];
            $total = $totalNew + $totalUpdated;

            if ($total > 0) {
                $details = [];
                foreach ($stats as $teamName => $cnt) {
                    $tTotal = $cnt['new'] + $cnt['updated'];
                    if ($tTotal > 0) {
                        $details[] = "<strong>{$teamName}</strong>: {$tTotal} ({$cnt['new']} baru, {$cnt['updated']} diperbarui)";
                    }
                }
                $detailStr = implode(' | ', $details);
                $importMessage = "Import berhasil dipisahkan otomatis ke tabel masing-masing: total {$total} data tersimpan ({$detailStr}).";
                $importType = 'success';
            } else {
                $importMessage = 'Tidak ada baris data valid yang berhasil disimpan.';
                $importType = 'error';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $importMessage = 'Import gagal: ' . $e->getMessage();
            $importType = 'error';
        }
    }
}

    if ($action === 'reset_rac') {
        $resetScope = trim((string)($_POST['reset_scope'] ?? 'all'));
        try {
            $pdo->beginTransaction();
            if ($resetScope === 'all') {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM data_rac");
                $deletedCount = (int)$countStmt->fetchColumn();
                $pdo->exec("DELETE FROM data_rac");
                $pdo->commit();
                $importMessage = "Berhasil mereset seluruh data RAC. Total {$deletedCount} data telah dihapus dari database.";
                $importType = 'success';
            } elseif ($resetScope === 'partial') {
                $targetRegion = trim((string)($_POST['target_region'] ?? ''));
                $targetTeam   = trim((string)($_POST['target_team'] ?? ''));

                $delWhere = [];
                $delParams = [];
                $labels = [];

                if ($targetRegion !== '') {
                    $delWhere[] = 'region = :region';
                    $delParams[':region'] = $targetRegion;
                    $labels[] = MASTER_REGIONS[(int)$targetRegion] ?? ('Region ' . $targetRegion);
                }

                if ($targetTeam !== '') {
                    $delWhere[] = 'team = :team';
                    $delParams[':team'] = $targetTeam;
                    $labels[] = $targetTeam;
                }

                if (empty($delWhere)) {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM data_rac");
                    $deletedCount = (int)$countStmt->fetchColumn();
                    $pdo->exec("DELETE FROM data_rac");
                    $pdo->commit();
                    $importMessage = "Berhasil mereset seluruh data RAC (Semua Region & Semua Team). Total {$deletedCount} data telah dihapus.";
                    $importType = 'success';
                } else {
                    $whereClause = implode(' AND ', $delWhere);
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM data_rac WHERE {$whereClause}");
                    $countStmt->execute($delParams);
                    $deletedCount = (int)$countStmt->fetchColumn();

                    $delStmt = $pdo->prepare("DELETE FROM data_rac WHERE {$whereClause}");
                    $delStmt->execute($delParams);
                    $pdo->commit();

                    $targetLabel = implode(', ', $labels);
                    $importMessage = "Berhasil mereset data terpilih ({$targetLabel}). Total {$deletedCount} data telah dihapus.";
                    $importType = 'success';
                }
            } elseif ($resetScope === 'single') {
                $targetId = (int)($_POST['target_id'] ?? 0);
                if ($targetId > 0) {
                    $delStmt = $pdo->prepare("DELETE FROM data_rac WHERE id = :id");
                    $delStmt->execute([':id' => $targetId]);
                    $pdo->commit();
                    $importMessage = "Berhasil menghapus data personil terpilih.";
                    $importType = 'success';
                } else {
                    $pdo->rollBack();
                    $importMessage = 'ID personil tidak valid.';
                    $importType = 'error';
                }
            } else {
                $pdo->rollBack();
                $importMessage = 'Pilihan reset tidak dikenali.';
                $importType = 'error';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $importMessage = 'Gagal mereset data: ' . $e->getMessage();
            $importType = 'error';
        }
    }
}

// --------------------------------------------------------------------------
// FILTER & QUERY DATABASE
// --------------------------------------------------------------------------
if (isset($action) && $action === 'import_rac' && !empty($extracted)) {
    $importedRegions = array_unique(array_filter(array_column($extracted, 'region')));
    if (!empty($_POST['target_region'])) {
        $filterRegion = (string)$_POST['target_region'];
    } elseif (count($importedRegions) === 1) {
        $filterRegion = (string)reset($importedRegions);
    } else {
        $filterRegion = '';
    }

    if (!empty($_POST['target_team'])) {
        $filterTeam = (string)$_POST['target_team'];
    } else {
        $filterTeam = '';
    }
    $search = '';
} else {
    $filterRegion = trim((string)($_GET['region'] ?? ''));
    $filterTeam   = trim((string)($_GET['team'] ?? ''));
    $search       = trim((string)($_GET['q'] ?? ''));
}

$where = [];
$params = [];

if ($filterRegion !== '') {
    $where[] = 'region = :region';
    $params[':region'] = $filterRegion;
}

if ($filterTeam !== '') {
    $where[] = 'team = :team';
    $params[':team'] = $filterTeam;
}

if ($search !== '') {
    $searchLower = strtolower($search);
    $cleanDigits = preg_replace('/[^\d]/', '', $search);

    $searchConditions = [];
    $searchConditions[] = 'nama LIKE :search_nama';
    $searchConditions[] = 'pn LIKE :search_pn';
    $searchConditions[] = 'telepon LIKE :search_telp';
    $params[':search_nama'] = '%' . $search . '%';
    $params[':search_pn']   = '%' . $search . '%';
    $params[':search_telp'] = '%' . $search . '%';

    if ($cleanDigits !== '' && strlen($cleanDigits) >= 3) {
        $searchConditions[] = 'REPLACE(REPLACE(REPLACE(telepon, "-", ""), " ", ""), "+", "") LIKE :digits_telp';
        $searchConditions[] = 'pn LIKE :digits_pn';
        $params[':digits_telp'] = '%' . $cleanDigits . '%';
        $params[':digits_pn']   = '%' . $cleanDigits . '%';
    }

    // Dukungan pencarian Region (misal "regional 2", "region 2", "ro 2", "pekanbaru", "medan")
    $regMatchNum = null;
    if (preg_match('/(?:regional|region|rewgion|ro|wilayah|kanwil)\s*(\d+)/i', $search, $m)) {
        $num = (int)$m[1];
        if (isset(MASTER_REGIONS[$num])) {
            $regMatchNum = (string)$num;
            $searchConditions[] = 'region = :matched_region';
            $params[':matched_region'] = $regMatchNum;
        }
    }

    if ($regMatchNum === null) {
        $detectedRegionNum = normalizeRegion($search, MASTER_REGIONS);
        if ($detectedRegionNum !== '') {
            $searchConditions[] = 'region = :matched_region';
            $params[':matched_region'] = $detectedRegionNum;
        }

        foreach (MASTER_REGIONS as $rNum => $rName) {
            $rNameLower = strtolower($rName);
            $normRName = str_replace('region', 'regional', $rNameLower);
            if (preg_match('/\b' . preg_quote($searchLower, '/') . '\b/i', $rNameLower) || 
                preg_match('/\b' . preg_quote($searchLower, '/') . '\b/i', $normRName) ||
                (strlen($searchLower) >= 4 && str_contains($rNameLower, $searchLower))) {
                $k = ':reg_label_' . $rNum;
                $searchConditions[] = "region = {$k}";
                $params[$k] = (string)$rNum;
            }
        }

        if (is_numeric($search) && isset(MASTER_REGIONS[(int)$search])) {
            $searchConditions[] = 'region = :search_exact_num';
            $params[':search_exact_num'] = (string)(int)$search;
        }
    }

    $where[] = '(' . implode(' OR ', array_unique($searchConditions)) . ')';
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM data_rac {$whereSql} ORDER BY CAST(region AS UNSIGNED) ASC, id ASC");
$stmt->execute($params);
$dataRac = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pengelompokan Data per Tim
$teamGroups = [
    'Team Leader' => [],
    'Team Member' => [],
    'Buddy Out'   => []
];

foreach ($dataRac as $row) {
    if (isset($teamGroups[$row['team']])) {
        $teamGroups[$row['team']][] = $row;
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<style>
.rac-page {
    padding: 0;
}

.rac-page-header {
    margin-bottom: 20px;
}

.rac-page-title {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: #111827;
}

.rac-action-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 24px;
}

.rac-action-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.rac-action-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #1f2937;
}

.rac-action-info {
    margin: 6px 0 0;
    font-size: 13px;
    color: #6b7280;
}

.rac-filter-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 24px;
}

.rac-filter-grid {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
}

.rac-filter-item {
    min-width: 200px;
}

.rac-filter-item.search {
    flex: 1;
    min-width: 250px;
}

.rac-filter-label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}

.rac-filter-actions {
    display: flex;
    gap: 10px;
}

.rac-team-section {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 24px;
    overflow: hidden;
}

.rac-team-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.rac-team-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #1f2937;
}

.rac-team-count {
    font-size: 13px;
    color: #6b7280;
}

.rac-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.rac-table {
    width: 100%;
    border-collapse: collapse;
}

.rac-table th {
    padding: 13px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    color: #374151;
    font-size: 13px;
    font-weight: 600;
    text-align: left;
}

.rac-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f0f0f0;
    color: #4b5563;
    font-size: 13px;
}

.rac-table tbody tr:last-child td {
    border-bottom: none;
}

.rac-table tbody tr:hover {
    background: #f9fafb;
}

.rac-empty {
    padding: 35px 20px !important;
    text-align: center !important;
    color: #6b7280 !important;
    font-size: 14px !important;
}

.rac-import-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
}

.rac-import-modal-overlay.show {
    display: flex;
}

.rac-import-modal-box {
    background: #ffffff;
    border-radius: 10px;
    width: 100%;
    max-width: 520px;
    padding: 24px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.rac-import-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.rac-import-modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #111827;
}

.rac-import-modal-close {
    border: none;
    background: transparent;
    font-size: 22px;
    cursor: pointer;
    color: #9ca3af;
    line-height: 1;
}

.rac-import-modal-close:hover {
    color: #374151;
}

.rac-import-modal-description {
    font-size: 13px;
    color: #6b7280;
    line-height: 1.6;
    margin-bottom: 16px;
}

.rac-import-file {
    width: 100%;
    box-sizing: border-box;
    padding: 10px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 13px;
    background: #ffffff;
    cursor: pointer;
}

.rac-import-modal-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

.rac-import-message {
    margin-top: 15px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
}

.rac-import-message.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
}

.rac-import-message.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
}

.rac-reset-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 12px;
    cursor: pointer;
    background: #ffffff;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.rac-reset-card:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}

.rac-reset-card.active {
    border-color: #dc2626;
    background: #fef2f2;
}

.rac-reset-card input[type="radio"] {
    margin-top: 3px;
    cursor: pointer;
    accent-color: #dc2626;
}

.rac-reset-card-content {
    flex: 1;
}

.rac-reset-card-title {
    display: block;
    font-size: 14px;
    color: #1f2937;
    margin-bottom: 2px;
}

.rac-reset-card-desc {
    font-size: 12px;
    color: #6b7280;
    line-height: 1.4;
}

@media (max-width: 768px) {
    .rac-action-header {
        display: block;
    }
    .rac-action-header .btn {
        margin-top: 12px;
        width: 100%;
    }
    .rac-filter-item,
    .rac-filter-item.search {
        width: 100%;
        min-width: 100%;
    }
    .rac-filter-actions {
        width: 100%;
    }
    .rac-filter-actions .btn {
        flex: 1;
    }
}
</style>

<main class="main-content">
    <div class="rac-page">
        <div class="rac-page-header">
            <h2 class="rac-page-title">DATA RAC</h2>
        </div>

        <div class="rac-action-card">
            <div class="rac-action-header">
                <div>
                    <h3 class="rac-action-title">Data RAC</h3>
                    <p class="rac-action-info">
                        Import berkas Excel (.xlsx) atau CSV. Data campuran (Team Leader, Team Member, Buddy Out) otomatis dipisah ke tabel masing-masing.
                    </p>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <button
                        type="button"
                        class="btn btn-sm"
                        id="btnImportDataRac"
                        onclick="openImportDataRac()"
                        style="background:#ffffff;color:#333333;border:1px solid #d1d5db;"
                    >
                        Import Excel / CSV
                    </button>
                </div>
            </div>

            <?php if ($importMessage !== ''): ?>
                <div class="rac-import-message <?= htmlspecialchars($importType, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $importMessage ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="GET" action="data_rac.php" class="rac-filter-card">
            <div class="rac-filter-grid">
                <div class="rac-filter-item">
                    <label for="filterRegion" class="rac-filter-label">REGION</label>
                    <select id="filterRegion" name="region" class="form-control" onchange="this.form.submit()">
                        <option value="">Semua Region</option>
                        <?php foreach (MASTER_REGIONS as $regionNumber => $regionName): ?>
                            <option
                                value="<?= htmlspecialchars((string)$regionNumber, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterRegion === (string)$regionNumber ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars((string)$regionName, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="rac-filter-item">
                    <label for="filterTeam" class="rac-filter-label">TEAM</label>
                    <select id="filterTeam" name="team" class="form-control" onchange="this.form.submit()">
                        <option value="">Semua Team</option>
                        <?php foreach ($allowedTeams as $teamOption): ?>
                            <option
                                value="<?= htmlspecialchars($teamOption, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $filterTeam === $teamOption ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($teamOption, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="rac-filter-item search">
                    <label for="filterSearch" class="rac-filter-label">PENCARIAN</label>
                    <input
                        type="text"
                        id="filterSearch"
                        name="q"
                        class="form-control"
                        placeholder="Ketik Nama, PN, atau No. Telepon..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="off"
                    >
                </div>

                <div class="rac-filter-actions">
                    <button type="submit" class="btn btn-primary">Terapkan Filter</button>
                    <a href="data_rac.php" class="btn btn-secondary">Reset Filter</a>
                    <button
                        type="button"
                        class="btn"
                        onclick="openResetDataRacModal()"
                        style="background:#dc2626;color:#ffffff;border:none;display:inline-flex;align-items:center;gap:6px;"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                        Reset Data
                    </button>
                </div>
            </div>
        </form>

        <?php if ($filterRegion !== '' || $filterTeam !== '' || $search !== ''): ?>
            <div style="margin-top:-14px;margin-bottom:20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#4b5563;background:#f9fafb;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;">
                <strong>Filter Aktif:</strong>
                <?php if ($filterRegion !== ''): ?>
                    <span style="background:#e0f2fe;color:#0369a1;padding:3px 10px;border-radius:15px;font-weight:600;">
                        Region: <?= htmlspecialchars(MASTER_REGIONS[(int)$filterRegion] ?? ('Region ' . $filterRegion), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <?php if ($filterTeam !== ''): ?>
                    <span style="background:#f1f5f9;color:#334155;padding:3px 10px;border-radius:15px;font-weight:600;">
                        Team: <?= htmlspecialchars($filterTeam, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:15px;font-weight:600;">
                        Pencarian: "<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    </span>
                <?php endif; ?>
                <a href="data_rac.php" style="color:#ef4444;text-decoration:none;font-weight:600;margin-left:auto;">Reset Filter</a>
            </div>
        <?php endif; ?>

        <!-- TEAM LEADER -->
        <?php if ($filterTeam === '' || $filterTeam === 'Team Leader'): ?>
            <section class="rac-team-section">
                <div class="rac-team-header">
                    <h3 class="rac-team-title">Team Leader</h3>
                    <span class="rac-team-count"><?= count($teamGroups['Team Leader']) ?> Data</span>
                </div>
                <div class="rac-table-wrapper">
                    <table class="rac-table">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th>PN</th>
                                <th>Nama</th>
                                <th>No Telepon</th>
                                <th style="width:70px;text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teamGroups['Team Leader'])): ?>
                                <tr>
                                    <td colspan="5" class="rac-empty">
                                        <?= $filterRegion !== '' ? 'Tidak ada data Team Leader untuk ' . htmlspecialchars(MASTER_REGIONS[(int)$filterRegion] ?? ('Region ' . $filterRegion), ENT_QUOTES, 'UTF-8') . '.' : 'Tidak ada data Team Leader.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($teamGroups['Team Leader'] as $row): ?>
                                    <tr data-region="<?= htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') ?>" data-region-name="<?= htmlspecialchars(strtolower(MASTER_REGIONS[(int)$row['region']] ?? ('region ' . $row['region'])), ENT_QUOTES, 'UTF-8') ?>">
                                        <td><span style="display:inline-block;padding:3px 8px;background:#e0f2fe;color:#0369a1;border-radius:4px;font-size:12px;font-weight:600;"><?= htmlspecialchars(MASTER_REGIONS[(int)$row['region']] ?? ('Region ' . $row['region']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars((string)($row['pn'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><strong><?= htmlspecialchars((string)$row['nama'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars((string)($row['telepon'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleRac(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama']), ENT_QUOTES, 'UTF-8') ?>')" title="Hapus personil ini">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- TEAM MEMBER -->
        <?php if ($filterTeam === '' || $filterTeam === 'Team Member'): ?>
            <section class="rac-team-section">
                <div class="rac-team-header">
                    <h3 class="rac-team-title">Team Member</h3>
                    <span class="rac-team-count"><?= count($teamGroups['Team Member']) ?> Data</span>
                </div>
                <div class="rac-table-wrapper">
                    <table class="rac-table">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th>PN</th>
                                <th>Nama</th>
                                <th>No Telepon</th>
                                <th style="width:70px;text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teamGroups['Team Member'])): ?>
                                <tr>
                                    <td colspan="5" class="rac-empty">
                                        <?= $filterRegion !== '' ? 'Tidak ada data Team Member untuk ' . htmlspecialchars(MASTER_REGIONS[(int)$filterRegion] ?? ('Region ' . $filterRegion), ENT_QUOTES, 'UTF-8') . '.' : 'Tidak ada data Team Member.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($teamGroups['Team Member'] as $row): ?>
                                    <tr data-region="<?= htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') ?>" data-region-name="<?= htmlspecialchars(strtolower(MASTER_REGIONS[(int)$row['region']] ?? ('region ' . $row['region'])), ENT_QUOTES, 'UTF-8') ?>">
                                        <td><span style="display:inline-block;padding:3px 8px;background:#f1f5f9;color:#334155;border-radius:4px;font-size:12px;font-weight:600;"><?= htmlspecialchars(MASTER_REGIONS[(int)$row['region']] ?? ('Region ' . $row['region']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars((string)($row['pn'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><strong><?= htmlspecialchars((string)$row['nama'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars((string)($row['telepon'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleRac(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama']), ENT_QUOTES, 'UTF-8') ?>')" title="Hapus personil ini">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- BUDDY OUT -->
        <?php if ($filterTeam === '' || $filterTeam === 'Buddy Out'): ?>
            <section class="rac-team-section">
                <div class="rac-team-header">
                    <h3 class="rac-team-title">Buddy Out</h3>
                    <span class="rac-team-count"><?= count($teamGroups['Buddy Out']) ?> Data</span>
                </div>
                <div class="rac-table-wrapper">
                    <table class="rac-table">
                        <thead>
                            <tr>
                                <th>Region</th>
                                <th>PN</th>
                                <th>Nama</th>
                                <th>No Telepon</th>
                                <th style="width:70px;text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teamGroups['Buddy Out'])): ?>
                                <tr>
                                    <td colspan="5" class="rac-empty">
                                        <?= $filterRegion !== '' ? 'Tidak ada data Buddy Out untuk ' . htmlspecialchars(MASTER_REGIONS[(int)$filterRegion] ?? ('Region ' . $filterRegion), ENT_QUOTES, 'UTF-8') . '.' : 'Tidak ada data Buddy Out.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($teamGroups['Buddy Out'] as $row): ?>
                                    <tr data-region="<?= htmlspecialchars((string)$row['region'], ENT_QUOTES, 'UTF-8') ?>" data-region-name="<?= htmlspecialchars(strtolower(MASTER_REGIONS[(int)$row['region']] ?? ('region ' . $row['region'])), ENT_QUOTES, 'UTF-8') ?>">
                                        <td><span style="display:inline-block;padding:3px 8px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:12px;font-weight:600;"><?= htmlspecialchars(MASTER_REGIONS[(int)$row['region']] ?? ('Region ' . $row['region']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars((string)($row['pn'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><strong><?= htmlspecialchars((string)$row['nama'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars((string)($row['telepon'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteSingleRac(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama']), ENT_QUOTES, 'UTF-8') ?>')" title="Hapus personil ini">Hapus</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>

<!-- MODAL IMPORT DATA RAC -->
<div class="rac-import-modal-overlay" id="modalImportDataRac">
    <div class="rac-import-modal-box">
        <div class="rac-import-modal-header">
            <h3 class="rac-import-modal-title">Import Data RAC</h3>
            <button type="button" class="rac-import-modal-close" onclick="closeImportDataRac()">&times;</button>
        </div>

        <p class="rac-import-modal-description">
            Pilih berkas <strong>.xlsx</strong> atau <strong>.csv</strong>. Sistem otomatis membaca data campuran, mendeteksi posisi/jabatan atau susunan kolom/sheet, lalu memisahkannya ke tabel <strong>Team Leader</strong>, <strong>Team Member</strong>, dan <strong>Buddy Out</strong>.
        </p>

        <form id="formImportDataRac" method="POST" action="data_rac.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_rac">
            <input type="hidden" name="filter_region" value="<?= htmlspecialchars($filterRegion, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="filter_team" value="<?= htmlspecialchars($filterTeam, ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom:14px;">
                <label for="file_import_rac" style="display:block;font-size:13px;font-weight:600;color:#1e293b;margin-bottom:6px;">
                    Pilih File Excel (.xlsx) atau CSV:
                </label>
                <input
                    type="file"
                    name="file_import"
                    id="file_import_rac"
                    class="rac-import-file"
                    accept=".xlsx,.xls,.csv"
                    required
                    onchange="handleAutoImport(this)"
                >
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label for="importTargetRegion" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">
                        Wilayah (Jika file tanpa kolom Region):
                    </label>
                    <select name="target_region" id="importTargetRegion" class="form-control" style="font-size:12.5px;padding:6px 10px;">
                        <option value="" selected>Otomatis dari Excel</option>
                        <?php foreach (MASTER_REGIONS as $rNum => $rName): ?>
                            <option value="<?= $rNum ?>">
                                Region <?= $rNum ?> - <?= htmlspecialchars($rName, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="importTargetTeam" style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">
                        Tim (Jika file tanpa kolom Tim):
                    </label>
                    <select name="target_team" id="importTargetTeam" class="form-control" style="font-size:12.5px;padding:6px 10px;">
                        <option value="" selected>Otomatis dari Excel</option>
                        <?php foreach ($allowedTeams as $tOption): ?>
                            <option value="<?= htmlspecialchars($tOption, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($tOption, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:10px;margin-bottom:14px;background:#f8fafc;border:1px solid #e2e8f0;padding:12px;border-radius:6px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#1e293b;cursor:pointer;">
                    <input type="checkbox" name="replace_all" value="1" checked style="accent-color:#2563eb;width:16px;height:16px;">
                    Sinkronkan data: Kosongkan data lama agar tabel 100% sama persis dengan file Excel
                </label>
                <div style="font-size:12px;color:#64748b;margin-top:4px;margin-left:24px;">
                    Centang ini memastikan tabel hanya berisi data dari file Excel yang baru diunggah (tanpa tercampur data lama).
                </div>
            </div>

            <div id="racUploadStatus" style="display:none;margin-top:14px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;color:#1e40af;font-weight:600;">
                Sedang memproses dan menyimpan berkas ke database...
            </div>

            <div class="rac-import-modal-actions">
                <a href="template_import_data_rac.csv" download class="btn btn-secondary btn-sm" style="margin-right:auto;">
                    Unduh Template CSV
                </a>
                <button type="button" class="btn btn-secondary" onclick="closeImportDataRac()">
                    Batal
                </button>
                <button type="submit" id="btnSubmitRac" class="btn btn-primary">
                    Import Data
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RESET DATA RAC -->
<div class="rac-import-modal-overlay" id="modalResetDataRac">
    <div class="rac-import-modal-box" style="max-width:560px;">
        <div class="rac-import-modal-header">
            <h3 class="rac-import-modal-title" style="color:#b91c1c;display:flex;align-items:center;gap:8px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                Konfirmasi Reset Data RAC
            </h3>
            <button type="button" class="rac-import-modal-close" onclick="closeResetDataRacModal()">&times;</button>
        </div>

        <p class="rac-import-modal-description" style="margin-bottom:18px;font-size:14px;color:#374151;">
            Apakah Anda yakin ingin mereset data? Silakan pilih untuk <strong>menghapus seluruh data</strong> atau <strong>menghapus data terpilih</strong> sesuai kebutuhan:
        </p>

        <form id="formResetDataRac" method="POST" action="data_rac.php">
            <input type="hidden" name="action" value="reset_rac">
            <input type="hidden" name="reset_scope" id="inputResetScope" value="all">

            <!-- OPSI 1: HAPUS SELURUH DATA -->
            <div style="border:2px solid #fca5a5;background:#fef2f2;border-radius:8px;padding:16px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap;">
                    <div>
                        <strong style="color:#991b1b;font-size:15px;display:block;">
                            1. Hapus Seluruh Data
                        </strong>
                        <span style="font-size:12px;color:#7f1d1d;">
                            Menghapus semua data tanpa terkecuali (Team Leader, Team Member, Buddy Out).
                        </span>
                    </div>
                    <button
                        type="button"
                        id="btnResetAllModal"
                        class="btn btn-sm"
                        onclick="executeReset('all')"
                        style="background:#dc2626;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;cursor:pointer;white-space:nowrap;"
                    >
                        Hapus Seluruh Data
                    </button>
                </div>
            </div>

            <!-- OPSI 2: HAPUS DATA TERPILIH -->
            <div style="border:2px solid #e5e7eb;background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:16px;">
                <div style="margin-bottom:10px;">
                    <strong style="color:#1f2937;font-size:15px;display:block;">
                        2. Hapus Data Terpilih
                    </strong>
                    <span style="font-size:12px;color:#4b5563;">
                        Hapus baris data hanya untuk Region dan/atau Team tertentu yang dipilih di bawah ini.
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;">Pilih Region Target:</label>
                        <select id="targetRegionSelect" name="target_region" class="form-control" style="font-size:13px;padding:8px 10px;width:100%;border-radius:6px;">
                            <option value="">Semua Region</option>
                            <?php foreach (MASTER_REGIONS as $rNum => $rName): ?>
                                <option value="<?= htmlspecialchars((string)$rNum, ENT_QUOTES, 'UTF-8') ?>" <?= $filterRegion === (string)$rNum ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$rName, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;">Pilih Team Target:</label>
                        <select id="targetTeamSelect" name="target_team" class="form-control" style="font-size:13px;padding:8px 10px;width:100%;border-radius:6px;">
                            <option value="">Semua Team</option>
                            <?php foreach ($allowedTeams as $tOption): ?>
                                <option value="<?= htmlspecialchars($tOption, ENT_QUOTES, 'UTF-8') ?>" <?= $filterTeam === $tOption ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tOption, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex;justify-content:flex-end;">
                    <button
                        type="button"
                        id="btnResetPartialModal"
                        class="btn btn-sm"
                        onclick="executeReset('partial')"
                        style="background:#ea580c;color:#ffffff;border:none;font-weight:700;padding:8px 16px;border-radius:6px;cursor:pointer;white-space:nowrap;"
                    >
                        Hapus Data Terpilih
                    </button>
                </div>
            </div>

            <div class="rac-import-modal-actions" style="margin-top:12px;display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeResetDataRacModal()">
                    Batal
                </button>
            </div>
        </form>
    </div>
</div>

<!-- FORM TERSEMBUNYI UNTUK HAPUS SINGLE PERSONIL -->
<form id="formDeleteSingleRac" method="POST" action="data_rac.php" style="display:none;">
    <input type="hidden" name="action" value="reset_rac">
    <input type="hidden" name="reset_scope" value="single">
    <input type="hidden" name="target_id" id="inputDeleteSingleTargetId" value="">
</form>

<script>
function openImportDataRac() {
    const modal = document.getElementById('modalImportDataRac');
    if (modal) {
        const fileInput = document.getElementById('file_import_rac');
        if (fileInput) fileInput.value = '';
        const statusBox = document.getElementById('racUploadStatus');
        if (statusBox) statusBox.style.display = 'none';
        const submitBtn = document.getElementById('btnSubmitRac');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Import Data';
        }
        modal.classList.add('show');
    }
}

function closeImportDataRac() {
    const modal = document.getElementById('modalImportDataRac');
    if (modal) {
        const fileInput = document.getElementById('file_import_rac');
        if (fileInput) fileInput.value = '';
        const statusBox = document.getElementById('racUploadStatus');
        if (statusBox) statusBox.style.display = 'none';
        const submitBtn = document.getElementById('btnSubmitRac');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Import Data';
        }
        modal.classList.remove('show');
    }
}

function openResetDataRacModal() {
    const modal = document.getElementById('modalResetDataRac');
    if (modal) modal.classList.add('show');
}

function closeResetDataRacModal() {
    const modal = document.getElementById('modalResetDataRac');
    if (modal) modal.classList.remove('show');
}

function executeReset(scope) {
    const inputScope = document.getElementById('inputResetScope');
    const form = document.getElementById('formResetDataRac');
    if (!form || !inputScope) return;

    inputScope.value = scope;

    const btnAll = document.getElementById('btnResetAllModal');
    const btnPartial = document.getElementById('btnResetPartialModal');
    if (btnAll) btnAll.disabled = true;
    if (btnPartial) btnPartial.disabled = true;

    if (scope === 'all') {
        if (btnAll) btnAll.textContent = 'Menghapus Seluruh Data...';
    } else {
        if (btnPartial) btnPartial.textContent = 'Menghapus Data Terpilih...';
    }

    form.submit();
}

function deleteSingleRac(id, name) {
    if (!id) return;
    const label = name ? ` "${name}"` : '';
    if (!confirm(`Apakah Anda yakin ingin menghapus data personil${label} secara permanen?`)) {
        return;
    }
    document.getElementById('inputDeleteSingleTargetId').value = id;
    document.getElementById('formDeleteSingleRac').submit();
}

function handleAutoImport(input) {
    if (input.files && input.files.length > 0) {
        const statusBox = document.getElementById('racUploadStatus');
        const submitBtn = document.getElementById('btnSubmitRac');
        if (statusBox) statusBox.style.display = 'block';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        }
        document.getElementById('formImportDataRac').submit();
    }
}

const formImport = document.getElementById('formImportDataRac');
if (formImport) {
    formImport.addEventListener('submit', function(e) {
        const fileInput = document.getElementById('file_import_rac');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('Silakan pilih berkas Excel atau CSV terlebih dahulu.');
            return;
        }
        const statusBox = document.getElementById('racUploadStatus');
        if (statusBox) statusBox.style.display = 'block';
        const submitBtn = document.getElementById('btnSubmitRac');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        }
    });
}

// Live search client-side for instantaneous feedback on Nama / PN / Telepon / Region
const searchInput = document.getElementById('filterSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        const normQuery = query.replace(/\bregional\b/gi, 'region');
        const cleanQuery = query.replace(/[^\d]/g, '');
        const regMatch = query.match(/(?:regional|region|rewgion|ro|wilayah)\s*(\d+)/i);
        const queryRegNum = regMatch ? String(parseInt(regMatch[1], 10)) : '';

        document.querySelectorAll('.rac-team-section').forEach(section => {
            let visibleCount = 0;
            const rows = section.querySelectorAll('tbody tr:not(.rac-client-empty):not(.rac-empty)');
            rows.forEach(tr => {
                const text = tr.innerText.toLowerCase();
                const numText = text.replace(/[^\d]/g, '');
                const rowRegion = tr.getAttribute('data-region') || '';
                const rowRegionName = tr.getAttribute('data-region-name') || '';
                
                let matches = false;
                if (query === '') {
                    matches = true;
                } else if (queryRegNum !== '') {
                    matches = (rowRegion === queryRegNum);
                } else if (text.includes(query) || text.includes(normQuery) || rowRegionName.includes(query) || rowRegionName.includes(normQuery)) {
                    matches = true;
                } else if (cleanQuery.length >= 3 && numText.includes(cleanQuery)) {
                    matches = true;
                }

                tr.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            const countBadge = section.querySelector('.rac-team-count');
            if (countBadge) {
                if (query !== '') {
                    countBadge.textContent = visibleCount + ' Data Sesuai';
                } else {
                    countBadge.textContent = rows.length + ' Data';
                }
            }

            let clientEmpty = section.querySelector('.rac-client-empty');
            if (visibleCount === 0 && rows.length > 0) {
                if (!clientEmpty) {
                    clientEmpty = document.createElement('tr');
                    clientEmpty.className = 'rac-client-empty';
                    const colSpan = section.querySelectorAll('thead th').length || 4;
                    clientEmpty.innerHTML = `<td colspan="${colSpan}" class="rac-empty">Tidak ada data yang cocok dengan "${searchInput.value}".</td>`;
                    section.querySelector('tbody').appendChild(clientEmpty);
                }
                clientEmpty.style.display = '';
            } else if (clientEmpty) {
                clientEmpty.style.display = 'none';
            }
        });
    });
}

document.addEventListener('click', function(event) {
    const modalImport = document.getElementById('modalImportDataRac');
    if (modalImport && modalImport.classList.contains('show') && event.target === modalImport) {
        closeImportDataRac();
    }
    const modalReset = document.getElementById('modalResetDataRac');
    if (modalReset && modalReset.classList.contains('show') && event.target === modalReset) {
        closeResetDataRacModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeImportDataRac();
        closeResetDataRacModal();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
