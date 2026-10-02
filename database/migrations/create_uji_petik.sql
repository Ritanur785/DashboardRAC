CREATE TABLE IF NOT EXISTS `uji_petik` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `no_urut` VARCHAR(50) DEFAULT NULL,
    `branch` VARCHAR(150) NOT NULL,
    `pic_rac` VARCHAR(150) DEFAULT NULL,
    `rencana_pelaksanaan` VARCHAR(150) DEFAULT NULL,
    `tanggal_uji_petik` VARCHAR(100) DEFAULT NULL,
    `dokumen` VARCHAR(255) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'Not Done',
    `month` VARCHAR(50) DEFAULT NULL,
    `region` VARCHAR(150) DEFAULT NULL,
    `keterangan` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_uji_petik_branch` (`branch`),
    INDEX `idx_uji_petik_month` (`month`),
    INDEX `idx_uji_petik_region` (`region`),
    INDEX `idx_uji_petik_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
