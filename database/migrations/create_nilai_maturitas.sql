CREATE TABLE IF NOT EXISTS `nilai_maturitas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `no_urut` VARCHAR(50) DEFAULT NULL,
    `branch` VARCHAR(150) NOT NULL,
    `pic_rac` VARCHAR(150) DEFAULT NULL,
    `nilai_maturitas` VARCHAR(50) DEFAULT NULL,
    `average` VARCHAR(50) DEFAULT NULL,
    `rating_maturitas` VARCHAR(100) DEFAULT NULL,
    `month` VARCHAR(50) DEFAULT NULL,
    `region` VARCHAR(150) DEFAULT NULL,
    `keterangan` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_maturitas_branch` (`branch`),
    INDEX `idx_maturitas_month` (`month`),
    INDEX `idx_maturitas_region` (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
