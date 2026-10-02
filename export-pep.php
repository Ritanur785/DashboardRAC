<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/regions.php';

date_default_timezone_set('Asia/Jakarta');

$pdo = getDbConnection();

$filterBulan = trim((string)($_GET['bulan'] ?? ($_GET['pep_bulan'] ?? '')));

$monthsIndo = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April',   '05' => 'Mei',      '06' => 'Juni',
    '07' => 'Juli',    '08' => 'Agustus',  '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$namaBulan = 'Semua Periode';
if ($filterBulan !== '') {
    $parts = explode('-', $filterBulan);
    if (count($parts) === 2 && isset($monthsIndo[$parts[1]])) {
        $namaBulan = $monthsIndo[$parts[1]] . ' ' . $parts[0];
    } else {
        $namaBulan = $filterBulan;
    }
}

$where = [];
$params = [];

if ($filterBulan !== '') {
    $where[] = "posisi LIKE :bulan";
    $params['bulan'] = $filterBulan . '%';
}

$whereClause = !empty($where) ? (' WHERE ' . implode(' AND ', $where)) : '';

$regionCase = getRegionSqlCaseExpression("region");
$regionSql = "SELECT 
    $regionCase AS region,
    COUNT(*) as total,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND flag_pep_bri_updated = 'YA' THEN 1 ELSE 0 END) as done_ya,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' AND (flag_pep_bri_updated = 'TIDAK' OR flag_pep_bri_updated IS NULL OR flag_pep_bri_updated = '') THEN 1 ELSE 0 END) as done_tidak,
    SUM(CASE WHEN LOWER(TRIM(status_tl)) = 'done' THEN 1 ELSE 0 END) as total_done,
    SUM(CASE WHEN status_tl IS NULL OR LOWER(TRIM(status_tl)) != 'done' THEN 1 ELSE 0 END) as belum_tl
FROM pep_alerts" . $whereClause . "
GROUP BY region";

$stmt = $pdo->prepare($regionSql);
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$regionMap = [];
foreach (MASTER_REGIONS as $num => $name) {
    $regionMap[$name] = [
        'no'         => $num,
        'region'     => $name,
        'total'      => 0,
        'done_ya'    => 0,
        'done_tidak' => 0,
        'total_done' => 0,
        'belum_tl'   => 0,
    ];
}

$lainnyaRow = null;
foreach ($raw as $r) {
    $name = (string)$r['region'];
    if (isset($regionMap[$name])) {
        $regionMap[$name]['total']      = (int)$r['total'];
        $regionMap[$name]['done_ya']    = (int)$r['done_ya'];
        $regionMap[$name]['done_tidak'] = (int)$r['done_tidak'];
        $regionMap[$name]['total_done'] = (int)$r['total_done'];
        $regionMap[$name]['belum_tl']   = (int)$r['belum_tl'];
    } elseif ((int)$r['total'] > 0) {
        $lainnyaRow = [
            'no'         => 99,
            'region'     => $name,
            'total'      => (int)$r['total'],
            'done_ya'    => (int)$r['done_ya'],
            'done_tidak' => (int)$r['done_tidak'],
            'total_done' => (int)$r['total_done'],
            'belum_tl'   => (int)$r['belum_tl'],
        ];
    }
}

$data = array_values($regionMap);
if ($lainnyaRow !== null) {
    $data[] = $lainnyaRow;
}

// Generate XLSX
$tempFile = tempnam(sys_get_temp_dir(), 'pep_export_');
$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo "Gagal membuat file export Excel.";
    exit;
}

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
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
    <sheet name="Progress PEP" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2">
    <numFmt numFmtId="164" formatCode="#,##0"/>
    <numFmt numFmtId="165" formatCode="0.0%"/>
  </numFmts>
  <fonts count="4">
    <font><name val="Calibri"/><sz val="11"/><color rgb="FF000000"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FF000000"/></font>
    <font><b/><name val="Calibri"/><sz val="14"/><color rgb="FF1E293B"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0284C7"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/></border>
    <border>
      <left style="thin"><color rgb="FFD1D5DB"/></left>
      <right style="thin"><color rgb="FFD1D5DB"/></right>
      <top style="thin"><color rgb="FFD1D5DB"/></top>
      <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="10">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="164" fontId="2" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="165" fontId="2" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="right"/></xf>
  </cellXfs>
</styleSheet>';

$rowsXml = '';
$merges = [];

$rowsXml .= '<row r="1" ht="26" customHeight="1">';
$rowsXml .= '<c r="A1" s="1" t="inlineStr"><is><t>PROGRESS TINDAK LANJUT MONITORING PEP PER REGION</t></is></c>';
$rowsXml .= '</row>';
$merges[] = 'A1:H1';

$rowsXml .= '<row r="2" ht="18" customHeight="1">';
$rowsXml .= '<c r="A2" s="0" t="inlineStr"><is><t>Periode Posisi: ' . htmlspecialchars($namaBulan) . ' | Tanggal Unduh: ' . date('d-m-Y H:i:s') . ' WIB</t></is></c>';
$rowsXml .= '</row>';
$merges[] = 'A2:H2';

$rowsXml .= '<row r="3" ht="6" customHeight="1"></row>';

$headers = [
    'A' => 'NO',
    'B' => 'REGIONAL OFFICE',
    'C' => 'TOTAL DATA TARGET',
    'D' => 'SUDAH TL (FLAG YA)',
    'E' => 'SUDAH TL (FLAG TIDAK)',
    'F' => 'TOTAL SUDAH TL',
    'G' => 'BELUM TL',
    'H' => '% PROGRESS'
];

$rowsXml .= '<row r="4" ht="28" customHeight="1">';
foreach ($headers as $col => $title) {
    $rowsXml .= '<c r="' . $col . '4" s="2" t="inlineStr"><is><t>' . $title . '</t></is></c>';
}
$rowsXml .= '</row>';

$rowIdx = 5;
$sumTotal = 0;
$sumDoneYa = 0;
$sumDoneTidak = 0;
$sumTotalDone = 0;
$sumBelum = 0;

foreach ($data as $r) {
    $total = (int)$r['total'];
    $doneYa = (int)$r['done_ya'];
    $doneTidak = (int)$r['done_tidak'];
    $totalDone = (int)$r['total_done'];
    $belum = (int)$r['belum_tl'];
    $pct = $total > 0 ? ($totalDone / $total) : 0.0;

    $sumTotal += $total;
    $sumDoneYa += $doneYa;
    $sumDoneTidak += $doneTidak;
    $sumTotalDone += $totalDone;
    $sumBelum += $belum;

    $noVal = ($r['no'] === 99) ? '-' : (string)$r['no'];

    $rowsXml .= '<row r="' . $rowIdx . '" ht="20">';
    $rowsXml .= '<c r="A' . $rowIdx . '" s="3" t="inlineStr"><is><t>' . $noVal . '</t></is></c>';
    $rowsXml .= '<c r="B' . $rowIdx . '" s="4" t="inlineStr"><is><t>' . htmlspecialchars((string)$r['region']) . '</t></is></c>';
    $rowsXml .= '<c r="C' . $rowIdx . '" s="5"><v>' . $total . '</v></c>';
    $rowsXml .= '<c r="D' . $rowIdx . '" s="5"><v>' . $doneYa . '</v></c>';
    $rowsXml .= '<c r="E' . $rowIdx . '" s="5"><v>' . $doneTidak . '</v></c>';
    $rowsXml .= '<c r="F' . $rowIdx . '" s="5"><v>' . $totalDone . '</v></c>';
    $rowsXml .= '<c r="G' . $rowIdx . '" s="5"><v>' . $belum . '</v></c>';
    $rowsXml .= '<c r="H' . $rowIdx . '" s="6"><v>' . number_format($pct, 4, '.', '') . '</v></c>';
    $rowsXml .= '</row>';

    $rowIdx++;
}

$totalPct = $sumTotal > 0 ? ($sumTotalDone / $sumTotal) : 0.0;
$gtRow = $rowIdx;

$rowsXml .= '<row r="' . $gtRow . '" ht="22" customHeight="1">';
$rowsXml .= '<c r="A' . $gtRow . '" s="7" t="inlineStr"><is><t>TOTAL KESELURUHAN</t></is></c>';
$rowsXml .= '<c r="B' . $gtRow . '" s="7"/>';
$rowsXml .= '<c r="C' . $gtRow . '" s="8"><v>' . $sumTotal . '</v></c>';
$rowsXml .= '<c r="D' . $gtRow . '" s="8"><v>' . $sumDoneYa . '</v></c>';
$rowsXml .= '<c r="E' . $gtRow . '" s="8"><v>' . $sumDoneTidak . '</v></c>';
$rowsXml .= '<c r="F' . $gtRow . '" s="8"><v>' . $sumTotalDone . '</v></c>';
$rowsXml .= '<c r="G' . $gtRow . '" s="8"><v>' . $sumBelum . '</v></c>';
$rowsXml .= '<c r="H' . $gtRow . '" s="9"><v>' . number_format($totalPct, 4, '.', '') . '</v></c>';
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
    <col min="2" max="2" width="34" customWidth="1"/>
    <col min="3" max="3" width="16" customWidth="1"/>
    <col min="4" max="4" width="16" customWidth="1"/>
    <col min="5" max="5" width="16" customWidth="1"/>
    <col min="6" max="6" width="16" customWidth="1"/>
    <col min="7" max="7" width="14" customWidth="1"/>
    <col min="8" max="8" width="14" customWidth="1"/>
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

$waktuStr = date('H.i');
$filename = "PEP posisi {$namaBulan} pk {$waktuStr} WIB.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: public');

readfile($tempFile);
@unlink($tempFile);
exit;
