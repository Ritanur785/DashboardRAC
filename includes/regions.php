<?php
declare(strict_types=1);

const MASTER_REGIONS = [
    1  => 'Region 1 - RO Medan',
    2  => 'Region 2 - RO Pekanbaru',
    3  => 'Region 3 - RO Padang',
    4  => 'Region 4 - RO Palembang',
    5  => 'Region 5 - RO Bandar Lampung',
    6  => 'Region 6 - RO Jakarta 1',
    7  => 'Region 7 - RO Jakarta 2',
    8  => 'Region 8 - RO Jakarta 3',
    9  => 'Region 9 - RO Bandung',
    10 => 'Region 10 - RO Semarang',
    11 => 'Region 11 - RO Yogyakarta',
    12 => 'Region 12 - RO Surabaya',
    13 => 'Region 13 - RO Malang',
    14 => 'Region 14 - RO Banjarmasin',
    15 => 'Region 15 - RO Makassar',
    16 => 'Region 16 - RO Manado',
    17 => 'Region 17 - RO Denpasar',
    18 => 'Region 18 - RO Jayapura',
];

function formatRegionName(?string $raw): string {
    $u = strtoupper(trim((string)$raw));
    if ($u === '' || $u === '-') return '-';
    if (strpos($u, 'MEDAN') !== false) return 'Region 1 - RO Medan';
    if (strpos($u, 'PEKANBARU') !== false) return 'Region 2 - RO Pekanbaru';
    if (strpos($u, 'PADANG') !== false) return 'Region 3 - RO Padang';
    if (strpos($u, 'PALEMBANG') !== false) return 'Region 4 - RO Palembang';
    if (strpos($u, 'LAMPUNG') !== false) return 'Region 5 - RO Bandar Lampung';
    if (strpos($u, 'JAKARTA 3') !== false) return 'Region 8 - RO Jakarta 3';
    if (strpos($u, 'DKI2') !== false || strpos($u, 'JAKARTA 2') !== false) return 'Region 7 - RO Jakarta 2';
    if ($u === 'DKI' || strpos($u, 'DKI 1') !== false || strpos($u, 'JAKARTA 1') !== false) return 'Region 6 - RO Jakarta 1';
    if (strpos($u, 'BANDUNG') !== false) return 'Region 9 - RO Bandung';
    if (strpos($u, 'SEMARANG') !== false) return 'Region 10 - RO Semarang';
    if (strpos($u, 'YOGYA') !== false) return 'Region 11 - RO Yogyakarta';
    if (strpos($u, 'SURABAYA') !== false) return 'Region 12 - RO Surabaya';
    if (strpos($u, 'MALANG') !== false) return 'Region 13 - RO Malang';
    if (strpos($u, 'BANJARMASIN') !== false) return 'Region 14 - RO Banjarmasin';
    if (strpos($u, 'MAKASSAR') !== false) return 'Region 15 - RO Makassar';
    if (strpos($u, 'MANADO') !== false) return 'Region 16 - RO Manado';
    if (strpos($u, 'DENPASAR') !== false) return 'Region 17 - RO Denpasar';
    if (strpos($u, 'JAYAPURA') !== false) return 'Region 18 - RO Jayapura';
    return (string)$raw;
}

function getRegionSqlCaseExpression(string $col = "COALESCE(kantor_kanwil, regional_office, '')"): string {
    return "CASE
        WHEN UPPER($col) LIKE '%MEDAN%' THEN 'Region 1 - RO Medan'
        WHEN UPPER($col) LIKE '%PEKANBARU%' THEN 'Region 2 - RO Pekanbaru'
        WHEN UPPER($col) LIKE '%PADANG%' THEN 'Region 3 - RO Padang'
        WHEN UPPER($col) LIKE '%PALEMBANG%' THEN 'Region 4 - RO Palembang'
        WHEN UPPER($col) LIKE '%LAMPUNG%' THEN 'Region 5 - RO Bandar Lampung'
        WHEN UPPER($col) LIKE '%JAKARTA 3%' THEN 'Region 8 - RO Jakarta 3'
        WHEN UPPER($col) LIKE '%DKI2%' OR UPPER($col) LIKE '%JAKARTA 2%' THEN 'Region 7 - RO Jakarta 2'
        WHEN UPPER($col) = 'DKI' OR UPPER($col) LIKE '%JAKARTA 1%' THEN 'Region 6 - RO Jakarta 1'
        WHEN UPPER($col) LIKE '%BANDUNG%' THEN 'Region 9 - RO Bandung'
        WHEN UPPER($col) LIKE '%SEMARANG%' THEN 'Region 10 - RO Semarang'
        WHEN UPPER($col) LIKE '%YOGYA%' THEN 'Region 11 - RO Yogyakarta'
        WHEN UPPER($col) LIKE '%SURABAYA%' THEN 'Region 12 - RO Surabaya'
        WHEN UPPER($col) LIKE '%MALANG%' THEN 'Region 13 - RO Malang'
        WHEN UPPER($col) LIKE '%BANJARMASIN%' THEN 'Region 14 - RO Banjarmasin'
        WHEN UPPER($col) LIKE '%MAKASSAR%' THEN 'Region 15 - RO Makassar'
        WHEN UPPER($col) LIKE '%MANADO%' THEN 'Region 16 - RO Manado'
        WHEN UPPER($col) LIKE '%DENPASAR%' THEN 'Region 17 - RO Denpasar'
        WHEN UPPER($col) LIKE '%JAYAPURA%' THEN 'Region 18 - RO Jayapura'
        ELSE 'Lainnya'
    END";
}

function getRegionSqlCondition(string|int $region, string $col = "COALESCE(kantor_kanwil, regional_office, '')"): ?string {
    $str = trim((string)$region);
    if ($str === '') return null;
    
    $num = is_numeric($str) ? (int)$str : 0;
    if ($num === 0 && preg_match('/^Region\s+(\d+)/i', $str, $m)) {
        $num = (int)$m[1];
    }
    
    return match($num) {
        1  => "UPPER($col) LIKE '%MEDAN%'",
        2  => "UPPER($col) LIKE '%PEKANBARU%'",
        3  => "UPPER($col) LIKE '%PADANG%'",
        4  => "UPPER($col) LIKE '%PALEMBANG%'",
        5  => "UPPER($col) LIKE '%LAMPUNG%'",
        6  => "(UPPER($col) = 'DKI' OR UPPER($col) LIKE '%JAKARTA 1%')",
        7  => "(UPPER($col) LIKE '%DKI2%' OR UPPER($col) LIKE '%JAKARTA 2%')",
        8  => "UPPER($col) LIKE '%JAKARTA 3%'",
        9  => "UPPER($col) LIKE '%BANDUNG%'",
        10 => "UPPER($col) LIKE '%SEMARANG%'",
        11 => "UPPER($col) LIKE '%YOGYA%'",
        12 => "UPPER($col) LIKE '%SURABAYA%'",
        13 => "UPPER($col) LIKE '%MALANG%'",
        14 => "UPPER($col) LIKE '%BANJARMASIN%'",
        15 => "UPPER($col) LIKE '%MAKASSAR%'",
        16 => "UPPER($col) LIKE '%MANADO%'",
        17 => "UPPER($col) LIKE '%DENPASAR%'",
        18 => "UPPER($col) LIKE '%JAYAPURA%'",
        default => null,
    };
}
