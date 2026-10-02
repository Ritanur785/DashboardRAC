<?php
/**
 * Helper untuk pengelompokan PIC RAC dan perhitungan rata-rata (Average) otomatis
 * dengan penggabungan sel (rowspan) sesuai tampilan spreadsheet.
 */

if (!function_exists('attachPicRacGroupAverages')) {
    /**
     * Memproses baris data untuk menggabungkan sel Average per kelompok PIC RAC yang sama.
     *
     * @param array  $rows      Daftar baris data dari database
     * @param string $valueCol  Nama kolom nilai utama ('persentase' atau 'nilai_maturitas')
     * @param string $avgCol    Nama kolom average yang tersimpan di DB ('average')
     * @param string $picCol    Nama kolom PIC RAC ('pic_rac')
     * @return array Baris data yang diperkaya atribut:
     *               - is_first_in_pic_group (bool)
     *               - pic_group_rowspan (int)
     *               - pic_group_average (string)
     */
    function attachPicRacGroupAverages(array $rows, string $valueCol, string $avgCol = 'average', string $picCol = 'pic_rac'): array {
        $n = count($rows);
        if ($n === 0) {
            return [];
        }

        $result = $rows;
        $i = 0;

        while ($i < $n) {
            $rawPic = trim((string)($result[$i][$picCol] ?? ''));
            $picKey = strtolower($rawPic);

            // Jika PIC RAC kosong atau strip, buat baris tunggal (rowspan = 1)
            if ($picKey === '' || $picKey === '-') {
                $rawVal = (string)($result[$i][$avgCol] ?? $result[$i][$valueCol] ?? '-');
                $avgDisp = formatAvgValue($rawVal, $valueCol);
                $result[$i]['is_first_in_pic_group'] = true;
                $result[$i]['pic_group_rowspan'] = 1;
                $result[$i]['pic_group_average'] = $avgDisp;
                $singleRating = '-';
                if ($valueCol === 'nilai_maturitas') {
                    $cleanAvg = str_replace(['%', ' '], '', $avgDisp);
                    $cleanAvg = str_replace(',', '.', $cleanAvg);
                    $avgNum = is_numeric($cleanAvg) ? (float)$cleanAvg : null;
                    if ($avgNum !== null && $avgNum > 0) {
                        if ($avgNum >= 8.0) $singleRating = 'Sangat Baik';
                        elseif ($avgNum >= 6.5) $singleRating = 'Baik';
                        elseif ($avgNum >= 4.0) $singleRating = 'Cukup';
                        else $singleRating = 'Kurang';
                    } else {
                        $singleRating = trim((string)($result[$i]['rating_maturitas'] ?? '-'));
                    }
                }
                $result[$i]['pic_group_rating'] = $singleRating;
                $i++;
                continue;
            }

            // Cari kelompok baris berturutan dengan PIC RAC yang sama
            $j = $i;
            while ($j < $n && strtolower(trim((string)($result[$j][$picCol] ?? ''))) === $picKey) {
                $j++;
            }

            $groupCount = $j - $i;

            // Kumpulkan nilai-nilai numerik dalam grup
            $numericValues = [];
            $hasPercentSign = false;
            $isDecimalPercent = false;

            for ($k = $i; $k < $j; $k++) {
                $rawVal = trim((string)($result[$k][$valueCol] ?? ''));
                if ($rawVal !== '' && $rawVal !== '-') {
                    if (str_contains($rawVal, '%')) {
                        $hasPercentSign = true;
                    }
                    $cleaned = str_replace(['%', ' '], '', $rawVal);
                    $cleaned = str_replace(',', '.', $cleaned);
                    if (is_numeric($cleaned)) {
                        $f = (float)$cleaned;
                        if ($f <= 1.0 && !str_contains($rawVal, '%')) {
                            $isDecimalPercent = true;
                        }
                        $numericValues[] = $f;
                    }
                }
            }

            // Hitung rata-rata
            $displayAvg = '-';
            if (!empty($numericValues)) {
                $avg = array_sum($numericValues) / count($numericValues);

                if ($valueCol === 'nilai_maturitas') {
                    // Nilai maturitas (skala 1 - 5): format 2 desimal
                    $displayAvg = number_format($avg, 2, '.', '');
                } elseif ($hasPercentSign) {
                    // Format persentase: misal 95.83%
                    $displayAvg = number_format($avg, 2, '.', '') . '%';
                } elseif ($isDecimalPercent) {
                    // Format desimal Excel: misal 0.9583 (4 digit desimal)
                    $displayAvg = number_format($avg, 4, '.', '');
                } else {
                    $displayAvg = number_format($avg, 2, '.', '');
                }
            } else {
                // Fallback ke kolom average bawaan baris pertama jika ada
                $storedAvg = trim((string)($result[$i][$avgCol] ?? ''));
                if ($storedAvg !== '' && $storedAvg !== '-') {
                    $displayAvg = $storedAvg;
                }
            }

            // Hitung rating berdasarkan nilai rata-rata jika modul nilai_maturitas
            $displayRating = '-';
            if ($valueCol === 'nilai_maturitas') {
                $cleanAvg = str_replace(['%', ' '], '', (string)$displayAvg);
                $cleanAvg = str_replace(',', '.', $cleanAvg);
                $avgNum = is_numeric($cleanAvg) ? (float)$cleanAvg : null;
                if ($avgNum !== null && $avgNum > 0) {
                    if ($avgNum >= 8.0 || ($avgNum >= 4.5 && $avgNum <= 5.0)) {
                        $displayRating = 'Sangat Baik';
                    } elseif ($avgNum >= 6.5 || ($avgNum >= 3.5 && $avgNum < 4.5)) {
                        $displayRating = 'Baik';
                    } elseif ($avgNum >= 4.0 || ($avgNum >= 2.5 && $avgNum < 3.5)) {
                        $displayRating = 'Cukup';
                    } else {
                        $displayRating = 'Kurang';
                    }
                } else {
                    $rawR = trim((string)($result[$i]['rating_maturitas'] ?? ''));
                    if ($rawR !== '') {
                        $displayRating = $rawR;
                    }
                }
            }

            // Baris pertama kelompok ini menampung rowspan dan nilai rata-rata
            $result[$i]['is_first_in_pic_group'] = true;
            $result[$i]['pic_group_rowspan'] = $groupCount;
            $result[$i]['pic_group_average'] = $displayAvg;
            $result[$i]['pic_group_rating'] = $displayRating;

            // Baris ke-2 hingga ke-N dalam kelompok ditandai agar tidak merender sel terpisah
            for ($k = $i + 1; $k < $j; $k++) {
                $result[$k]['is_first_in_pic_group'] = false;
                $result[$k]['pic_group_rowspan'] = 0;
                $result[$k]['pic_group_average'] = $displayAvg;
                $result[$k]['pic_group_rating'] = $displayRating;
            }

            $i = $j;
        }

        return $result;
    }
}

if (!function_exists('formatAvgValue')) {
    function formatAvgValue(string $val, string $valueCol): string {
        $trimmed = trim($val);
        if ($trimmed === '' || $trimmed === '-') {
            return '-';
        }
        return $trimmed;
    }
}
