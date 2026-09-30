<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class PepImporter {
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

            // Handle whole-line wrapped in quotes with doubled inner quotes (common Excel pipe-delimited export)
            if (str_starts_with($cleanLine, '"') && str_ends_with($cleanLine, '"') && strpos($cleanLine, $delimiter) !== false) {
                $cleanLine = substr($cleanLine, 1, -1);
                $cleanLine = str_replace('""', '"', $cleanLine);
            }

            $row = str_getcsv($cleanLine, $delimiter, '"', '\\');
            $rows[] = array_map(function($v) {
                $val = trim((string)$v);
                // Remove redundant quotes if any remain
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

    public static function cleanDate(?string $val): ?string {
        if ($val === null || trim($val) === '' || trim($val) === '-') {
            return null;
        }
        $val = trim($val);
        // If string matches YYYY-MM-DD ...
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $val, $m)) {
            return $m[1];
        }
        // If Excel numeric timestamp
        if (is_numeric($val) && (float)$val > 10000 && (float)$val < 60000) {
            $unix = ((float)$val - 25569) * 86400;
            return date('Y-m-d', (int)$unix);
        }
        return $val;
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
            'posisi'               => ['POSISI', 'TANGGAL', 'DATE', 'POSISI PERIODE'],
            'nama_lengkap'         => ['NAMA LENGKAP', 'NAMA', 'CUSTOMER NAME', 'NAMA NASABAH'],
            'cif'                  => ['CIF', 'NO CIF', 'NOMOR CIF'],
            'open_date'            => ['OPEN DATE', 'TANGGAL BUKA', 'TGL BUKA', 'TANGGAL PEMBUKAAN'],
            'nik'                  => ['NIK', 'NO KTP', 'NOMOR IDENTITAS', 'IDENTITAS'],
            'tempat_lahir'         => ['TEMPAT LAHIR', 'TEMPAT_LAHIR', 'KOTA LAHIR'],
            'tanggal_lahir'        => ['TANGGAL LAHIR', 'TGL LAHIR', 'TANGGAL_LAHIR'],
            'jabatan_bri'          => ['JABATAN BRI', 'JABATAN_BRI', 'JABATAN'],
            'instansi_bri'         => ['INSTANSI BRI', 'INSTANSI_BRI', 'INSTANSI'],
            'kode_uker'            => ['KODE UKER', 'KODE_UKER', 'KODE UNIT KERJA'],
            'unit_kerja'           => ['UNIT KERJA', 'UKER', 'UNIT_KERJA', 'NAMA UKER'],
            'branch'               => ['BRANCH', 'KANTOR CABANG', 'KC', 'MAIN BRANCH'],
            'region'               => ['REGION', 'KANWIL', 'REGIONAL OFFICE', 'KANTOR WILAYAH'],
            'flag_pep_bri_initial' => ['FLAG PEP BRI (INITIAL)', 'FLAG PEP BRI (INITIAL) ', 'FLAG PEP INITIAL', 'PEP INITIAL'],
            'flag_pep_bri_updated' => ['FLAG PEP BRI (UPDATED)', 'FLAG PEP BRI (UPDATED) ', 'FLAG PEP UPDATED', 'PEP UPDATED'],
            'jabatan_ppatk'        => ['JABATAN PPATK', 'JABATAN_PPATK'],
            'instansi_ppatk'       => ['INSTANSI PPATK', 'INSTANSI_PPATK'],
            'analisa'              => ['ANALISA', 'ANALISIS', 'ANALISA PPATK', 'HASIL ANALISA'],
            'status'               => ['STATUS', 'STATUS PEP', 'STATUS TINDAK LANJUT']
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

        // Validate critical columns
        if ($resolvedIndexes['cif'] === null && $resolvedIndexes['nama_lengkap'] === null) {
            return [
                'success' => false,
                'error' => 'Header file tidak sesuai. Kolom CIF atau NAMA LENGKAP tidak ditemukan dalam file.'
            ];
        }

        $pdo = getDbConnection();
        $pdo->beginTransaction();

        $findStmt = $pdo->prepare("SELECT id, disposisi_rac, tgl_tindak_lanjut, status_tl FROM pep_alerts WHERE posisi = :posisi AND cif = :cif LIMIT 1");

        $updateSql = "UPDATE pep_alerts SET
            nama_lengkap = :nama_lengkap,
            open_date = :open_date,
            nik = :nik,
            tempat_lahir = :tempat_lahir,
            tanggal_lahir = :tanggal_lahir,
            jabatan_bri = :jabatan_bri,
            instansi_bri = :instansi_bri,
            kode_uker = :kode_uker,
            unit_kerja = :unit_kerja,
            branch = :branch,
            region = :region,
            flag_pep_bri_initial = :flag_pep_bri_initial,
            flag_pep_bri_updated = :flag_pep_bri_updated,
            jabatan_ppatk = :jabatan_ppatk,
            instansi_ppatk = :instansi_ppatk,
            analisa = :analisa,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);

        $insertSql = "INSERT INTO pep_alerts (
            posisi, nama_lengkap, cif, open_date, nik, tempat_lahir, tanggal_lahir,
            jabatan_bri, instansi_bri, kode_uker, unit_kerja, branch, region,
            flag_pep_bri_initial, flag_pep_bri_updated, jabatan_ppatk, instansi_ppatk,
            analisa, status, disposisi_rac, tgl_tindak_lanjut, status_tl
        ) VALUES (
            :posisi, :nama_lengkap, :cif, :open_date, :nik, :tempat_lahir, :tanggal_lahir,
            :jabatan_bri, :instansi_bri, :kode_uker, :unit_kerja, :branch, :region,
            :flag_pep_bri_initial, :flag_pep_bri_updated, :jabatan_ppatk, :instansi_ppatk,
            :analisa, :status, :disposisi_rac, :tgl_tindak_lanjut, :status_tl
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

                $nama = $getVal('nama_lengkap');
                $cif = $getVal('cif');

                if ($nama === '' && $cif === '') {
                    continue;
                }

                $posisiRaw = $getVal('posisi', date('Y-m-d'));
                $posisi = self::cleanDate($posisiRaw) ?: date('Y-m-d');
                $openDate = self::cleanDate($getVal('open_date'));
                $nik = $getVal('nik');
                $tempatLahir = $getVal('tempat_lahir');
                $tglLahir = self::cleanDate($getVal('tanggal_lahir'));
                $jabatanBri = $getVal('jabatan_bri');
                $instansiBri = $getVal('instansi_bri');
                $kodeUker = $getVal('kode_uker');
                $unitKerja = $getVal('unit_kerja');
                $branch = $getVal('branch');
                $region = $getVal('region');
                $flagInitial = $getVal('flag_pep_bri_initial');
                $flagUpdated = $getVal('flag_pep_bri_updated');
                $jabatanPpatk = $getVal('jabatan_ppatk');
                $instansiPpatk = $getVal('instansi_ppatk');
                $analisa = $getVal('analisa');
                $status = $getVal('status', 'BELUM TL');

                // Check existing record by posisi & cif
                $findStmt->execute([
                    'posisi' => $posisi,
                    'cif'    => $cif,
                ]);
                $existing = $findStmt->fetch();

                if ($existing) {
                    // Update master data, preserve user follow-up (disposisi_rac, tgl_tindak_lanjut, status_tl)
                    $updateStmt->execute([
                        'nama_lengkap'         => $nama,
                        'open_date'            => $openDate,
                        'nik'                  => $nik ?: null,
                        'tempat_lahir'         => $tempatLahir ?: null,
                        'tanggal_lahir'        => $tglLahir ?: null,
                        'jabatan_bri'          => $jabatanBri ?: null,
                        'instansi_bri'         => $instansiBri ?: null,
                        'kode_uker'            => $kodeUker ?: null,
                        'unit_kerja'           => $unitKerja ?: null,
                        'branch'               => $branch ?: null,
                        'region'               => $region ?: null,
                        'flag_pep_bri_initial' => $flagInitial ?: null,
                        'flag_pep_bri_updated' => $flagUpdated ?: null,
                        'jabatan_ppatk'        => $jabatanPpatk ?: null,
                        'instansi_ppatk'       => $instansiPpatk ?: null,
                        'analisa'              => $analisa ?: null,
                        'status'               => $status,
                        'id'                   => $existing['id'],
                    ]);
                    $updatedCount++;
                } else {
                    // Insert new PEP alert
                    $isDone = in_array(strtoupper(trim($status)), ['DONE', 'SELESAI'], true);
                    $statusTl = $isDone ? 'Done' : 'Not Done';

                    $insertStmt->execute([
                        'posisi'               => $posisi,
                        'nama_lengkap'         => $nama,
                        'cif'                  => $cif,
                        'open_date'            => $openDate,
                        'nik'                  => $nik ?: null,
                        'tempat_lahir'         => $tempatLahir ?: null,
                        'tanggal_lahir'        => $tglLahir ?: null,
                        'jabatan_bri'          => $jabatanBri ?: null,
                        'instansi_bri'         => $instansiBri ?: null,
                        'kode_uker'            => $kodeUker ?: null,
                        'unit_kerja'           => $unitKerja ?: null,
                        'branch'               => $branch ?: null,
                        'region'               => $region ?: null,
                        'flag_pep_bri_initial' => $flagInitial ?: null,
                        'flag_pep_bri_updated' => $flagUpdated ?: null,
                        'jabatan_ppatk'        => $jabatanPpatk ?: null,
                        'instansi_ppatk'       => $instansiPpatk ?: null,
                        'analisa'              => $analisa ?: null,
                        'status'               => $status,
                        'disposisi_rac'        => null,
                        'tgl_tindak_lanjut'    => null,
                        'status_tl'            => $statusTl,
                    ]);
                    $insertedCount++;
                }
            }

            $pdo->commit();

            return [
                'success'  => true,
                'inserted' => $insertedCount,
                'updated'  => $updatedCount,
                'total'    => $insertedCount + $updatedCount,
                'message'  => "Berhasil memproses import: {$insertedCount} data baru ditambahkan, {$updatedCount} data diperbarui (disposisi dan tindak lanjut tetap dipertahankan)."
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error'   => 'Gagal menyimpan data ke database: ' . $e->getMessage()
            ];
        }
    }
}
