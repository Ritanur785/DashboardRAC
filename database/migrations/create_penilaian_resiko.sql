CREATE TABLE IF NOT EXISTS `penilaian_resiko` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `no_urut` VARCHAR(50) DEFAULT NULL,
    `branch` VARCHAR(150) NOT NULL,
    `pic_rac` VARCHAR(150) DEFAULT NULL,
    `nilai_resiko` VARCHAR(50) DEFAULT NULL,
    `nilai_risiko_pic` VARCHAR(50) DEFAULT NULL,
    `level_risiko` VARCHAR(50) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Small',
    `month` VARCHAR(50) DEFAULT NULL,
    `region` VARCHAR(150) DEFAULT NULL,
    `keterangan` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_penilaian_resiko_branch` (`branch`),
    INDEX `idx_penilaian_resiko_month` (`month`),
    INDEX `idx_penilaian_resiko_region` (`region`),
    INDEX `idx_penilaian_resiko_level` (`level_risiko`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
