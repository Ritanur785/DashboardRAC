CREATE TABLE IF NOT EXISTS `pep_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `posisi` VARCHAR(100) NOT NULL,
    `nama_lengkap` VARCHAR(150) NOT NULL,
    `cif` VARCHAR(50) NOT NULL,
    `open_date` VARCHAR(50) DEFAULT NULL,
    `nik` VARCHAR(50) DEFAULT NULL,
    `tempat_lahir` VARCHAR(100) DEFAULT NULL,
    `tanggal_lahir` VARCHAR(50) DEFAULT NULL,
    `jabatan_bri` VARCHAR(150) DEFAULT NULL,
    `instansi_bri` VARCHAR(150) DEFAULT NULL,
    `kode_uker` VARCHAR(50) DEFAULT NULL,
    `unit_kerja` VARCHAR(150) DEFAULT NULL,
    `branch` VARCHAR(150) DEFAULT NULL,
    `region` VARCHAR(150) DEFAULT NULL,
    `flag_pep_bri_initial` VARCHAR(100) DEFAULT NULL,
    `flag_pep_bri_updated` VARCHAR(100) DEFAULT NULL,
    `jabatan_ppatk` VARCHAR(150) DEFAULT NULL,
    `instansi_ppatk` VARCHAR(150) DEFAULT NULL,
    `analisa` TEXT DEFAULT NULL,
    `status` VARCHAR(100) DEFAULT NULL,
    `disposisi_rac` VARCHAR(150) DEFAULT NULL,
    `tgl_tindak_lanjut` DATE DEFAULT NULL,
    `status_tl` VARCHAR(50) NOT NULL DEFAULT 'Not Done',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pep_cif` (`cif`),
    INDEX `idx_pep_nik` (`nik`),
    INDEX `idx_pep_posisi` (`posisi`),
    INDEX `idx_pep_status_tl` (`status_tl`),
    INDEX `idx_pep_region` (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pep_alerts` 
(`posisi`, `nama_lengkap`, `cif`, `open_date`, `nik`, `tempat_lahir`, `tanggal_lahir`, `jabatan_bri`, `instansi_bri`, `kode_uker`, `unit_kerja`, `branch`, `region`, `flag_pep_bri_initial`, `flag_pep_bri_updated`, `jabatan_ppatk`, `instansi_ppatk`, `analisa`, `status`, `disposisi_rac`, `tgl_tindak_lanjut`, `status_tl`)
VALUES
('2026-07-31', 'H. BAMBANG SOESATYO, S.E., M.B.A.', '80192831', '2018-04-12', '3171012309620001', 'Jakarta', '1962-09-10', 'Nasabah Prioritas', 'BRI KC Sudirman', '0021', 'KC Sudirman Sentral', 'KC Sudirman', 'RO 01 - Jakarta 1', 'PEP', 'PEP Terkonfirmasi', 'Ketua MPR RI', 'MPR RI', 'Terdaftar dalam basis data PEP PPATK aktif', 'Selesai Analisa', '00212345 - Fikri Kipli', '2026-08-05', 'Done'),
('2026-07-31', 'DR. AHMAD FAUZI LUKMAN', '80192832', '2020-01-15', '3273011405750002', 'Bandung', '1975-05-14', 'Nasabah Giro Badan', 'BRI KC Bandung Asia Afrika', '0105', 'KC Asia Afrika', 'KC Bandung', 'RO 02 - Bandung', 'Non PEP', 'PEP Terindikasi', 'Kepala Dinas ESDM', 'Pemerintah Provinsi Jabar', 'Terdapat kesesuaian data identitas dengan data PPATK', 'Perlu Verifikasi', NULL, NULL, 'Not Done'),
('2026-08-31', 'HJ. SITI AMINAH WIDJAJA', '80192833', '2019-11-20', '3578016008800003', 'Surabaya', '1980-08-20', 'Nasabah BritAma', 'BRI KC Surabaya Kaliasin', '0230', 'KC Surabaya Kaliasin', 'KC Surabaya', 'RO 03 - Surabaya', 'PEP', 'PEP Terkonfirmasi', 'Anggota Komisi XI DPR RI', 'DPR RI', 'Data PEP telah diupdate pada sistem core banking', 'Dalam Pemantauan', '00284711 - Ahmad Fauzi', NULL, 'Not Done');
