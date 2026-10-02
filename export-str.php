<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

date_default_timezone_set('Asia/Jakarta');

$pdo = getDbConnection();

$filterBulan = trim((string)($_GET['bulan'] ?? ''));

$daftarBulan = [
    1  => 'Januari',
    2  => 'Februari',
    3  => 'Maret',
    4  => 'April',
    5  => 'Mei',
    6  => 'Juni',
    7  => 'Juli',
    8  => 'Agustus',
    9  => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember'
];

$namaBulan = 'Semua Bulan';
$filterBulanNum = null;

if ($filterBulan !== '') {
    if (is_numeric($filterBulan)) {
        $n = (int)$filterBulan;
        if ($n >= 1 && $n <= 12) {
            $filterBulanNum = $n;
            $namaBulan = $daftarBulan[$n];
        }
    } else {
        foreach ($daftarBulan as $mNum => $mName) {
            if (strcasecmp($mName, $filterBulan) === 0) {
                $filterBulanNum = $mNum;
                $namaBulan = $mName;
                break;
            }
        }
        if ($filterBulanNum === null && preg_match('/-(\d{1,2})$/', $filterBulan, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 12) {
                $filterBulanNum = $n;
                $namaBulan = $daftarBulan[$n];
            }
        }
    }
}

$strMonthExpr = "MONTH(CASE 
    WHEN posisi REGEXP '^[0-9]+$' AND CAST(posisi AS UNSIGNED) BETWEEN 30000 AND 60000 
    THEN DATE_ADD('1899-12-30', INTERVAL CAST(posisi AS UNSIGNED) DAY)
    WHEN posisi REGEXP '^[0-9]{4}-[0-9]{2}' 
    THEN STR_TO_DATE(SUBSTRING(posisi, 1, 10), '%Y-%m-%d')
    ELSE NULL 
END)";

$where = [
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KANPUS%'",
    "UPPER(COALESCE(kantor_kanwil, regional_office, '')) NOT LIKE '%KAMPUS%'"
];
$params = [];

if ($filterBulanNum !== null) {
    $where[] = "$strMonthExpr = :bulan_num";
    $params['bulan_num'] = $filterBulanNum;
}

$whereClause = ' WHERE ' . implode(' AND ', $where);

$regionCase = getRegionSqlCaseExpression("COALESCE(kantor_kanwil, regional_office, '')");
$regionSql = "SELECT 
    $regionCase AS region,
    COUNT(*) AS total,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Ya' THEN 1 ELSE 0 END) AS tl_ya,
    SUM(CASE WHEN TRIM(COALESCE(rekomendasi_ukk, '')) = 'Tidak' THEN 1 ELSE 0 END) AS tl_tidak,
    SUM(CASE WHEN (rekomendasi_ukk IS NULL OR TRIM(rekomendasi_ukk) = '' OR TRIM(rekomendasi_ukk) = '-') THEN 1 ELSE 0 END) AS belum_tl
FROM str_alerts" . $whereClause . "
GROUP BY region";

$stmt = $pdo->prepare($regionSql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$regionMap = [];
foreach (MASTER_REGIONS as $num => $name) {
    $regionMap[$name] = [
        'no' => $num,
        'region' => $name,
        'tl_ya' => 0,
        'tl_tidak' => 0,
        'belum_tl' => 0,
    ];
}

$lainnyaRow = null;
foreach ($raw as $r) {
    $name = (string)$r['region'];
    if (isset($regionMap[$name])) {
        $regionMap[$name]['tl_ya'] = (int)$r['tl_ya'];
        $regionMap[$name]['tl_tidak'] = (int)$r['tl_tidak'];
        $regionMap[$name]['belum_tl'] = (int)$r['belum_tl'];
    } elseif ((int)$r['total'] > 0) {
        $lainnyaRow = [
            'no' => 99,
            'region' => $name,
            'tl_ya' => (int)$r['tl_ya'],
            'tl_tidak' => (int)$r['tl_tidak'],
            'belum_tl' => (int)$r['belum_tl'],
        ];
    }
}

$data = array_values($regionMap);
if ($lainnyaRow !== null) {
    $data[] = $lainnyaRow;
}

// Generate XLSX
$tempFile = tempnam(sys_get_temp_dir(), 'str_export_');
$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo "Gagal membuat file export Excel.";
    exit;
}

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Progress STR" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2">
    <numFmt numFmtId="164" formatCode="#,##0"/>
    <numFmt numFmtId="165" formatCode="0.00%"/>
  </numFmts>
  <fonts count="3">
    <font><name val="Calibri"/><sz val="11"/><color rgb="FF000000"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FF000000"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF4F81BD"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/></border>
    <border>
      <left style="thin"><color rgb="FF000000"/></left>
      <right style="thin"><color rgb="FF000000"/></right>
      <top style="thin"><color rgb="FF000000"/></top>
      <bottom style="thin"><color rgb="FF000000"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf/></cellStyleXfs>
  <cellXfs count="10">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>
    <xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="165" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
  </cellXfs>
</styleSheet>';

$rowsXml = '';

// Row 1: Main Headers
$rowsXml .= '<row r="1" ht="24" customHeight="1">';
$rowsXml .= '<c r="A1" s="1" t="inlineStr"><is><t>No</t></is></c>';
$rowsXml .= '<c r="B1" s="1" t="inlineStr"><is><t>Region</t></is></c>';
$rowsXml .= '<c r="C1" s="1" t="inlineStr"><is><t>Sudah TL</t></is></c>';
$rowsXml .= '<c r="D1" s="1"/>';
$rowsXml .= '<c r="E1" s="1" t="inlineStr"><is><t>Belum TL</t></is></c>';
$rowsXml .= '<c r="F1" s="1" t="inlineStr"><is><t>Persentase</t></is></c>';
$rowsXml .= '</row>';

// Row 2: Sub-headers
$rowsXml .= '<row r="2" ht="20" customHeight="1">';
$rowsXml .= '<c r="A2" s="1"/>';
$rowsXml .= '<c r="B2" s="1"/>';
$rowsXml .= '<c r="C2" s="2" t="inlineStr"><is><t>Ya</t></is></c>';
$rowsXml .= '<c r="D2" s="2" t="inlineStr"><is><t>Tidak</t></is></c>';
$rowsXml .= '<c r="E2" s="1"/>';
$rowsXml .= '<c r="F2" s="1"/>';
$rowsXml .= '</row>';

$merges = [
    'A1:A2',
    'B1:B2',
    'C1:D1',
    'E1:E2',
    'F1:F2'
];

$curRow = 3;
$sumYa = 0;
$sumTidak = 0;
$sumBelum = 0;

foreach ($data as $idx => $item) {
    $no = $idx + 1;
    $regionText = (string)$item['region'];
    $ya = (int)$item['tl_ya'];
    $tidak = (int)$item['tl_tidak'];
    $belum = (int)$item['belum_tl'];
    $totalAlert = $ya + $tidak + $belum;
    $sudah = $ya + $tidak;
    $pct = $totalAlert > 0 ? ($sudah / $totalAlert) : 0.0;

    $sumYa += $ya;
    $sumTidak += $tidak;
    $sumBelum += $belum;

    $rowsXml .= '<row r="' . $curRow . '" ht="20" customHeight="1">';
    $rowsXml .= '<c r="A' . $curRow . '" s="3"><v>' . $no . '</v></c>';
    $rowsXml .= '<c r="B' . $curRow . '" s="4" t="inlineStr"><is><t>' . htmlspecialchars($regionText) . '</t></is></c>';
    
    // Ya (kosong jika 0 agar sesuai tampilan Excel referensi pengguna)
    if ($ya > 0) {
        $rowsXml .= '<c r="C' . $curRow . '" s="5"><v>' . $ya . '</v></c>';
    } else {
        $rowsXml .= '<c r="C' . $curRow . '" s="5"/>';
    }

    // Tidak (kosong jika 0)
    if ($tidak > 0) {
        $rowsXml .= '<c r="D' . $curRow . '" s="5"><v>' . $tidak . '</v></c>';
    } else {
        $rowsXml .= '<c r="D' . $curRow . '" s="5"/>';
    }

    // Belum TL (kosong jika 0)
    if ($belum > 0) {
        $rowsXml .= '<c r="E' . $curRow . '" s="5"><v>' . $belum . '</v></c>';
    } else {
        $rowsXml .= '<c r="E' . $curRow . '" s="5"/>';
    }

    // Persentase (0.00%)
    $rowsXml .= '<c r="F' . $curRow . '" s="6"><v>' . number_format($pct, 4, '.', '') . '</v></c>';
    $rowsXml .= '</row>';

    $curRow++;
}

// Grand Total Row
$sumSudah = $sumYa + $sumTidak;
$sumAll = $sumSudah + $sumBelum;
$totalPct = $sumAll > 0 ? ($sumSudah / $sumAll) : 0.0;

$gtRow = $curRow;
$rowsXml .= '<row r="' . $gtRow . '" ht="22" customHeight="1">';
$rowsXml .= '<c r="A' . $gtRow . '" s="7" t="inlineStr"><is><t>Grand Total</t></is></c>';
$rowsXml .= '<c r="B' . $gtRow . '" s="7"/>';
$rowsXml .= '<c r="C' . $gtRow . '" s="8"><v>' . $sumYa . '</v></c>';
$rowsXml .= '<c r="D' . $gtRow . '" s="8"><v>' . $sumTidak . '</v></c>';
$rowsXml .= '<c r="E' . $gtRow . '" s="8"><v>' . $sumBelum . '</v></c>';
$rowsXml .= '<c r="F' . $gtRow . '" s="9"><v>' . number_format($totalPct, 4, '.', '') . '</v></c>';
$rowsXml .= '</row>';

$merges[] = 'A' . $gtRow . ':B' . $gtRow;

$mergeXml = '<mergeCells count="' . count($merges) . '">';
foreach ($merges as $m) {
    $mergeXml .= '<mergeCell ref="' . $m . '"/>';
}
$mergeXml .= '</mergeCells>';

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="1" width="6" customWidth="1"/>
    <col min="2" max="2" width="32" customWidth="1"/>
    <col min="3" max="3" width="12" customWidth="1"/>
    <col min="4" max="4" width="12" customWidth="1"/>
    <col min="5" max="5" width="14" customWidth="1"/>
    <col min="6" max="6" width="16" customWidth="1"/>
  </cols>
  <sheetData>' . $rowsXml . '</sheetData>
  ' . $mergeXml . '
</worksheet>';

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

// Penamaan file sesuai instruksi pengguna:
// "STR posisi Juli pk 10.31 WIB. Bulan menyesuaikan dengan bulan yang saya ambil, lalu untuk waktunya adalah posisi waktu saya mendownload (exportnya)"
$waktuStr = date('H.i');
$filename = "STR posisi {$namaBulan} pk {$waktuStr} WIB.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');

readfile($tempFile);
@unlink($tempFile);
exit;
