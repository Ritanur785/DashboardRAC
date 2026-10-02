CREATE DATABASE IF NOT EXISTS `DashboardRAC` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `DashboardRAC`;

CREATE TABLE IF NOT EXISTS `str_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `posisi` VARCHAR(100) NOT NULL,
    `nama_nasabah` VARCHAR(150) NOT NULL,
    `cif` VARCHAR(50) NOT NULL,
    `no_rekening` VARCHAR(60) NOT NULL,
    `resiko` VARCHAR(50) DEFAULT NULL,
    `brilink` VARCHAR(30) DEFAULT NULL,
    `status_pekerja` VARCHAR(50) DEFAULT NULL,
    `digital_saving` VARCHAR(50) DEFAULT NULL,
    `skenario` VARCHAR(200) NOT NULL,
    `scoring` VARCHAR(30) DEFAULT NULL,
    `info_param` VARCHAR(100) DEFAULT NULL,
    `kategori` VARCHAR(100) NOT NULL,
    `unit_kerja` VARCHAR(120) DEFAULT NULL,
    `kantor_cabang` VARCHAR(120) DEFAULT NULL,
    `kantor_kanwil` VARCHAR(120) DEFAULT NULL,
    `branch` VARCHAR(120) NOT NULL,
    `main_branch` VARCHAR(120) NOT NULL,
    `regional_office` VARCHAR(120) NOT NULL,
    `status_uker` VARCHAR(50) NOT NULL DEFAULT 'Belum TL',
    `rekomendasi_uker` VARCHAR(150) DEFAULT NULL,
    `status_ukk` VARCHAR(50) NOT NULL DEFAULT 'Belum TL',
    `rekomendasi_ukk` VARCHAR(150) DEFAULT NULL,
    `disposisi_rac` VARCHAR(120) DEFAULT NULL,
    `tgl_tindak_lanjut` DATE DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Belum TL',
    `disposisi` VARCHAR(150) DEFAULT NULL,
    `info_lainnya` TEXT DEFAULT NULL,
    `catatan` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cif` (`cif`),
    INDEX `idx_rekening` (`no_rekening`),
    INDEX `idx_status` (`status`),
    INDEX `idx_posisi` (`posisi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `str_alerts` 
(`posisi`, `nama_nasabah`, `cif`, `no_rekening`, `resiko`, `brilink`, `status_pekerja`, `digital_saving`, `skenario`, `scoring`, `info_param`, `kategori`, `unit_kerja`, `kantor_cabang`, `kantor_kanwil`, `branch`, `main_branch`, `regional_office`, `status_uker`, `status_ukk`, `status`, `disposisi`, `info_lainnya`)
VALUES
('2026-03-31', 'PT SUKA SUKA SAYA P', '0', '10301005146301', '', 'Tidak', 'Tidak', 'Tidak', 'Money Mules', '27', '77', 'Narkotika/Judi Online', 'Kas Kampus', 'Kas Kampus', 'KAS KAMPUS', 'Kas Kampus', 'Kas Kampus', 'KAS KAMPUS', 'Belum TL', 'Belum TL', 'Belum TL', '00212345 - Fikri Kipli', '-'),
('SKR C2 - Teller', 'JERRY SUCHAI', '10082873', '206010012838505', 'High', 'Tidak', 'Tidak', 'Tidak', 'STR - Transaksi Tunai Signifikan Tanpa Profil Usaha', '45', '80', 'STR Nasabah', 'KC Sudirman Sentral', 'KC Sudirman Sentral', 'RO 01 - Jakarta 1', 'KC Sudirman Sentral', 'KC Sudirman Sentral', 'RO 01 - Jakarta 1', 'Belum TL', 'Belum TL', 'Belum TL', '00284711 - Ahmad Fauzi', 'Setoran tunai beruntun 3 hari berturut-turut'),
('SKR C2 - CS', 'ALFIN ALFAN', '10082874', '206010012838506', 'High', 'Tidak', 'Tidak', 'Ya', 'STR - Transaksi Pass-Through Cepat', '80', '95', 'STR Korporasi', 'KC Sudirman Sentral', 'KC Sudirman Sentral', 'RO 01 - Jakarta 1', 'KC Sudirman Sentral', 'KC Sudirman Sentral', 'RO 01 - Jakarta 1', 'Done', 'Done', 'Done', '00291033 - Budi Santoso', 'Perpindahan dana instan antar rekening non-afiliasi');
