<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class AlertImporter {
    public static function detectDelimiter(string $content): string {
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiters = ['|' => 0, ';' => 0, ',' => 0, "\t" => 0];

        foreach ($delimiters as $delim => &$count) {
            $count = substr_count($firstLine, $delim);
        }

        arsort($delimiters);
        $topDelimiter = array_key_first($delimiters);
        return $delimiters[$topDelimiter] > 0 ? $topDelimiter : '|';
    }

    public static function parseCsvString(string $content, ?string $delimiter = null): array {
        if ($delimiter === null) {
            $delimiter = self::detectDelimiter($content);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $rows = [];

        foreach ($lines as $line) {
            $cleanLine = trim($line);
            if ($cleanLine === '') continue;

            // Handle whole-line wrapped in quotes with doubled inner quotes (common Excel export)
            if (str_starts_with($cleanLine, '"') && str_ends_with($cleanLine, '"') && strpos($cleanLine, $delimiter) !== false) {
                $cleanLine = substr($cleanLine, 1, -1);
                $cleanLine = str_replace('""', '"', $cleanLine);
            }

            $row = str_getcsv($cleanLine, $delimiter, '"', '\\');
            $rows[] = array_map(function($v) {
                $val = trim((string)$v);
                if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                    $val = trim(substr($val, 1, -1));
                }
                return $val;
            }, $row);
        }

        return $rows;
    }

    public static function parseHtmlTable(string $html): array {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = [];
        $trs = $dom->getElementsByTagName('tr');
        foreach ($trs as $tr) {
            $row = [];
            $cells = $tr->childNodes;
            foreach ($cells as $cell) {
                if ($cell->nodeName === 'th' || $cell->nodeName === 'td') {
                    $row[] = trim($cell->textContent);
                }
            }
            if (!empty(array_filter($row, fn($c) => $c !== ''))) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public static function parseFile(string $filePath, string $originalName = ''): array {
        $content = (string)@file_get_contents($filePath);
        if ($content === '') {
            throw new RuntimeException("Berkas yang diunggah kosong atau tidak dapat dibaca.");
        }

        // Check if file is a valid ZIP archive (XLSX signature starts with PK\x03\x04 or PK\x05\x06)
        $isZip = str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "PK\x05\x06");
        
        if ($isZip) {
            try {
                return self::parseXlsxFile($filePath);
            } catch (Throwable $e) {
                // If opening as XLSX failed, fallback to text/csv check
            }
        }

        // Check if HTML table (often exported by web portals as .xls or .xlsx)
        if (stripos($content, '<table') !== false) {
            $htmlRows = self::parseHtmlTable($content);
            if (!empty($htmlRows)) {
                return $htmlRows;
            }
        }

        // Parse as CSV / Delimited text (Pipe, Semicolon, Comma, Tab)
        return self::parseCsvString($content);
    }

    public static function parseXlsxFile(string $filePath): array {
        $zip = new ZipArchive();
        $openResult = $zip->open($filePath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            $openResult = $zip->open($filePath);
        }
        if ($openResult !== true) {
            throw new RuntimeException("Gagal membuka file Excel (.xlsx).");
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
        $candidates = [
            'xl/worksheets/sheet1.xml',
            'xl/worksheets/Sheet1.xml',
            'xl/worksheets/sheet.xml',
            'xl/worksheets/Sheet.xml',
        ];
        foreach ($candidates as $cand) {
            $sheetXml = $zip->getFromName($cand);
            if ($sheetXml !== false) break;
        }
        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/.*\.xml$#i', $entryName)) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException("Worksheet 1 tidak ditemukan dalam file Excel.");
        }

        $sheet = simplexml_load_string($sheetXml);
        $rows = [];

        if ($sheet && isset($sheet->sheetData->row)) {
            foreach ($sheet->sheetData->row as $rowNode) {
                $rowCells = [];
                $colIndex = 0;

                foreach ($rowNode->c as $cell) {
                    $cellCoord = (string)$cell['r'];
                    preg_match('/([A-Z]+)(\d+)/', $cellCoord, $matches);
                    $colLetters = $matches[1] ?? 'A';
                    $targetCol = self::columnLetterToIndex($colLetters);

                    while ($colIndex < $targetCol) {
                        $rowCells[] = '';
                        $colIndex++;
                    }

                    $type = (string)$cell['t'];
                    $val = (string)$cell->v;

                    if ($type === 's' && isset($sharedStrings[(int)$val])) {
                        $val = $sharedStrings[(int)$val];
                    } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                        $val = (string)$cell->is->t;
                    }

                    $rowCells[] = trim($val);
                    $colIndex++;
                }

                if (!empty(array_filter($rowCells, fn($c) => $c !== ''))) {
                    $rows[] = $rowCells;
                }
            }
        }

        $zip->close();
        return $rows;
    }

    private static function columnLetterToIndex(string $letters): int {
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    public static function processImportRows(array $rows): array {
        if (count($rows) < 2) {
            return ['success' => false, 'error' => 'Data tidak berisi baris header dan data. Minimal 2 baris diperlukan.'];
        }

        $rawHeaders = array_shift($rows);
        $headerMap = [];

        foreach ($rawHeaders as $idx => $headerName) {
            $clean = strtoupper(trim(str_replace(['"', "'"], '', $headerName)));
            $headerMap[$clean] = $idx;
        }

        $colMapping = [
            'posisi' => ['POSISI', 'TANGGAL', 'DATE'],
            'nama_nasabah' => ['NAMA', 'NAMA NASABAH', 'CUSTOMER NAME'],
            'cif' => ['CIF', 'NO CIF'],
            'no_rekening' => ['NO REK/ KARTU KREDIT', 'NO REK', 'NO REKENING', 'ACCOUNT NUMBER'],
            'resiko' => ['RESIKO', 'RISK', 'TINGKAT RESIKO'],
            'brilink' => ['BRILINK', 'AGEN BRILINK'],
            'status_pekerja' => ['STATUS PEKERJA', 'PEKERJA'],
            'digital_saving' => ['DIGITAL SAVING'],
            'skenario' => ['SKENARIO', 'SCENARIO', 'ALERT SKENARIO'],
            'scoring' => ['SCORING', 'SCORE'],
            'info_param' => ['INFO PARAM', 'PARAMETER'],
            'kategori' => ['KATEGORI', 'CATEGORY'],
            'unit_kerja' => ['UNIT KERJA', 'UKER', 'BRANCH'],
            'kantor_cabang' => ['KANTOR CABANG', 'KC', 'MAIN BRANCH'],
            'kantor_kanwil' => ['KANTOR KANWIL', 'KANWIL', 'REGIONAL OFFICE'],
            'rekomendasi_uker' => ['REKOMENDASI UKER', 'REK UKER'],
            'rekomendasi_ukk' => ['REKOMENDASI UKK', 'REK UKK'],
            'status_uker' => ['STATUS UKER'],
            'status_ukk' => ['STATUS UKK', 'STATUS', 'STATUS TINDAK LANJUT'],
            'disposisi' => ['DISPOSISI', 'DISPOSISI RAC', 'PN PIC'],
            'info_lainnya' => ['INFO LAINNYA', 'INFO AINNYA', 'CATATAN', 'KETERANGAN'],
        ];

        $resolvedIndexes = [];
        foreach ($colMapping as $field => $candidates) {
            $resolvedIndexes[$field] = null;
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $headerMap)) {
                    $resolvedIndexes[$field] = $headerMap[$candidate];
                    break;
                }
            }
        }

        $pdo = getDbConnection();
        $pdo->beginTransaction();

        $findExistingStmt = $pdo->prepare("SELECT id FROM str_alerts WHERE
            posisi = :posisi
            AND UPPER(TRIM(nama_nasabah)) = :nama_nasabah
            AND cif = :cif
            AND no_rekening = :no_rekening
            AND COALESCE(resiko, '') = :resiko
            AND COALESCE(brilink, '') = :brilink
            AND COALESCE(status_pekerja, '') = :status_pekerja
            AND COALESCE(digital_saving, '') = :digital_saving
            AND skenario = :skenario
            AND COALESCE(scoring, '') = :scoring
            AND COALESCE(info_param, '') = :info_param
            AND kategori = :kategori
            AND COALESCE(unit_kerja, '') = :unit_kerja
            AND COALESCE(kantor_cabang, '') = :kantor_cabang
            AND COALESCE(kantor_kanwil, '') = :kantor_kanwil
            LIMIT 1");

        $updateSql = "UPDATE str_alerts SET
            nama_nasabah = :nama_nasabah,
            resiko = :resiko,
            brilink = :brilink,
            status_pekerja = :status_pekerja,
            digital_saving = :digital_saving,
            skenario = :skenario,
            scoring = :scoring,
            info_param = :info_param,
            kategori = :kategori,
            unit_kerja = :unit_kerja,
            kantor_cabang = :kantor_cabang,
            kantor_kanwil = :kantor_kanwil,
            branch = :branch,
            main_branch = :main_branch,
            regional_office = :regional_office,
            status_uker = :status_uker,
            rekomendasi_uker = :rekomendasi_uker,
            status_ukk = :status_ukk,
            rekomendasi_ukk = :rekomendasi_ukk,
            status = :status,
            disposisi = :disposisi,
            info_lainnya = :info_lainnya,
            catatan = :catatan,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);

        $insertSql = "INSERT INTO str_alerts (
            posisi, nama_nasabah, cif, no_rekening, resiko, brilink, status_pekerja,
            digital_saving, skenario, scoring, info_param, kategori, unit_kerja,
            kantor_cabang, kantor_kanwil, branch, main_branch, regional_office,
            rekomendasi_uker, rekomendasi_ukk, status_uker, status_ukk, status, status_tl, disposisi,
            disposisi_rac, tgl_tindak_lanjut, info_lainnya, catatan
        ) VALUES (
            :posisi, :nama_nasabah, :cif, :no_rekening, :resiko, :brilink, :status_pekerja,
            :digital_saving, :skenario, :scoring, :info_param, :kategori, :unit_kerja,
            :kantor_cabang, :kantor_kanwil, :branch, :main_branch, :regional_office,
            :rekomendasi_uker, :rekomendasi_ukk, :status_uker, :status_ukk, :status, :status_tl, :disposisi,
            :disposisi_rac, :tgl_tindak_lanjut, :info_lainnya, :catatan
        )";
        $insertStmt = $pdo->prepare($insertSql);

        $insertedCount = 0;
        $updatedCount = 0;

        try {
            foreach ($rows as $row) {
                if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
                    continue;
                }

                $getVal = fn($field, $default = '') => 
                    isset($resolvedIndexes[$field], $row[$resolvedIndexes[$field]]) 
                        ? trim((string)$row[$resolvedIndexes[$field]]) 
                        : $default;

                $nama = $getVal('nama_nasabah');
                $cif = $getVal('cif');
                $rekening = $getVal('no_rekening');
                $skenario = $getVal('skenario');

                if ($nama === '' && $rekening === '') {
                    continue;
                }

                $posisi = $getVal('posisi', date('Y-m-d'));
                $unitKerja = $getVal('unit_kerja', 'Kas Kampus');
                $kantorCabang = $getVal('kantor_cabang', $unitKerja);
                $kantorKanwil = $getVal('kantor_kanwil', 'RO 01 - Padang');
                $disposisi = $getVal('disposisi', '-');
                $statusUkk = $getVal('status_ukk', 'Belum TL') ?: 'Belum TL';
                $infoLainnya = $getVal('info_lainnya', '-');
                $resiko = $getVal('resiko');
                $brilink = $getVal('brilink', 'Tidak');
                $statusPekerja = $getVal('status_pekerja', 'Tidak');
                $digitalSaving = $getVal('digital_saving', 'Tidak');
                $skenarioVal = $skenario ?: 'Money Mules';
                $scoring = $getVal('scoring');
                $infoParam = $getVal('info_param');
                $kategori = $getVal('kategori', 'Narkotika/Judi Online');
                $rekomendasiUker = $getVal('rekomendasi_uker');
                $rekomendasiUkk = $getVal('rekomendasi_ukk');
                $statusUker = $getVal('status_uker', 'Belum TL') ?: 'Belum TL';

                // Cari apakah alert sudah ada berdasarkan identitas nasabah, alert, dan unit kerja
                $cifVal = ($cif !== '' && $cif !== null) ? $cif : '0';
                $findExistingStmt->execute([
                    'posisi' => $posisi,
                    'nama_nasabah' => strtoupper(trim($nama)),
                    'cif' => $cifVal,
                    'no_rekening' => $rekening,
                    'resiko' => $resiko,
                    'brilink' => $brilink,
                    'status_pekerja' => $statusPekerja,
                    'digital_saving' => $digitalSaving,
                    'skenario' => $skenarioVal,
                    'scoring' => $scoring,
                    'info_param' => $infoParam,
                    'kategori' => $kategori,
                    'unit_kerja' => $unitKerja,
                    'kantor_cabang' => $kantorCabang,
                    'kantor_kanwil' => $kantorKanwil,
                ]);
                $existing = $findExistingStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing && !empty($existing['id'])) {
                    // Update data yang ada, PERTAHANKAN disposisi_rac, status_tl, dan tgl_tindak_lanjut
                    $updateStmt->execute([
                        'id' => $existing['id'],
                        'nama_nasabah' => strtoupper($nama),
                        'resiko' => $resiko,
                        'brilink' => $brilink,
                        'status_pekerja' => $statusPekerja,
                        'digital_saving' => $digitalSaving,
                        'skenario' => $skenarioVal,
                        'scoring' => $scoring,
                        'info_param' => $infoParam,
                        'kategori' => $kategori,
                        'unit_kerja' => $unitKerja,
                        'kantor_cabang' => $kantorCabang,
                        'kantor_kanwil' => $kantorKanwil,
                        'branch' => $unitKerja,
                        'main_branch' => $kantorCabang,
                        'regional_office' => $kantorKanwil,
                        'status_uker' => $statusUker,
                        'rekomendasi_uker' => $rekomendasiUker,
                        'status_ukk' => $statusUkk,
                        'rekomendasi_ukk' => $rekomendasiUkk,
                        'status' => $statusUkk,
                        'disposisi' => $disposisi,
                        'info_lainnya' => $infoLainnya,
                        'catatan' => $infoLainnya,
                    ]);
                    $updatedCount++;
                } else {
                    // Insert data baru
                    $insertStmt->execute([
                        'posisi' => $posisi,
                        'nama_nasabah' => strtoupper($nama),
                        'cif' => $cif ?: '0',
                        'no_rekening' => $rekening,
                        'resiko' => $resiko,
                        'brilink' => $brilink,
                        'status_pekerja' => $statusPekerja,
                        'digital_saving' => $digitalSaving,
                        'skenario' => $skenarioVal,
                        'scoring' => $scoring,
                        'info_param' => $infoParam,
                        'kategori' => $kategori,
                        'unit_kerja' => $unitKerja,
                        'kantor_cabang' => $kantorCabang,
                        'kantor_kanwil' => $kantorKanwil,
                        'branch' => $unitKerja,
                        'main_branch' => $kantorCabang,
                        'regional_office' => $kantorKanwil,
                        'status_uker' => $statusUker,
                        'rekomendasi_uker' => $rekomendasiUker,
                        'status_ukk' => $statusUkk,
                        'rekomendasi_ukk' => $rekomendasiUkk,
                        'status' => $statusUkk,
                        'status_tl' => (strtolower(trim($statusUkk)) === 'done' ? 'Done' : 'Not Done'),
                        'disposisi' => $disposisi,
                        'disposisi_rac' => null,
                        'tgl_tindak_lanjut' => null,
                        'info_lainnya' => $infoLainnya,
                        'catatan' => $infoLainnya,
                    ]);
                    $insertedCount++;
                }
            }

            $pdo->commit();

            $totalProcessed = $insertedCount + $updatedCount;
            $msgParts = [];
            if ($updatedCount > 0) {
                $msgParts[] = "{$updatedCount} data diperbarui";
            }
            if ($insertedCount > 0) {
                $msgParts[] = "{$insertedCount} data baru ditambahkan";
            }
            $detailMsg = !empty($msgParts) ? implode(', ', $msgParts) : "0 data diproses";

            return [
                'success' => true,
                'count' => $totalProcessed,
                'inserted' => $insertedCount,
                'updated' => $updatedCount,
                'message' => "Berhasil memproses import: {$detailMsg}."
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error' => "Gagal mengimpor data: " . $e->getMessage()
            ];
        }
    }
}
