-- SkillsPassAurora database installer and non-destructive upgrade.
-- Safe to import repeatedly: existing marketplace data is preserved.
CREATE DATABASE IF NOT EXISTS aurora_group3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE aurora_group3;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('candidate', 'employer') NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    profile_picture MEDIUMBLOB NULL,
    profile_picture_mime VARCHAR(32) NULL,
    failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    otp_hash VARCHAR(255) NULL,
    otp_expires_at DATETIME NULL,
    otp_last_sent_at DATETIME NULL,
    otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_premium TINYINT UNSIGNED NOT NULL DEFAULT 0,
    premium_expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_users_role_created (role, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

SET @aurora_add_profile_picture = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'profile_picture'
    ) = 0,
    'ALTER TABLE users ADD COLUMN profile_picture MEDIUMBLOB NULL AFTER display_name',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_profile_picture;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_profile_picture_mime = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'profile_picture_mime'
    ) = 0,
    'ALTER TABLE users ADD COLUMN profile_picture_mime VARCHAR(32) NULL AFTER profile_picture',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_profile_picture_mime;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_failed_login_attempts = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'failed_login_attempts'
    ) = 0,
    'ALTER TABLE users ADD COLUMN failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER display_name',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_failed_login_attempts;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_otp_hash = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'otp_hash'
    ) = 0,
    'ALTER TABLE users ADD COLUMN otp_hash VARCHAR(255) NULL AFTER failed_login_attempts',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_otp_hash;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_otp_expires_at = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'otp_expires_at'
    ) = 0,
    'ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_hash',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_otp_expires_at;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_otp_last_sent_at = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'otp_last_sent_at'
    ) = 0,
    'ALTER TABLE users ADD COLUMN otp_last_sent_at DATETIME NULL AFTER otp_expires_at',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_otp_last_sent_at;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_otp_attempts = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'otp_attempts'
    ) = 0,
    'ALTER TABLE users ADD COLUMN otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_last_sent_at',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_otp_attempts;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_is_premium = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'is_premium'
    ) = 0,
    'ALTER TABLE users ADD COLUMN is_premium TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_attempts',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_is_premium;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_premium_expires_at = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'premium_expires_at'
    ) = 0,
    'ALTER TABLE users ADD COLUMN premium_expires_at DATETIME NULL AFTER is_premium',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_premium_expires_at;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employer_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    location VARCHAR(150) NOT NULL,
    employment_type ENUM('full-time', 'part-time', 'contract', 'temporary') NOT NULL,
    description TEXT NOT NULL,
    skills TEXT NULL,
    status ENUM('available', 'taken') NOT NULL DEFAULT 'available',
    selected_candidate_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_jobs_status_created (status, created_at),
    INDEX idx_jobs_employer (employer_id),
    CONSTRAINT fk_jobs_employer FOREIGN KEY (employer_id) REFERENCES users (id) ON DELETE CASCADE,
    KEY idx_jobs_selected_candidate (selected_candidate_id),
    CONSTRAINT fk_jobs_selected_candidate FOREIGN KEY (selected_candidate_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    cover_letter TEXT NULL,
    relevant_experience TEXT NULL,
    portfolio_url VARCHAR(2048) NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_applications_job_candidate (job_id, candidate_id),
    INDEX idx_applications_candidate (candidate_id),
    INDEX idx_applications_job_status_created (job_id, status, created_at),
    CONSTRAINT fk_applications_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_candidate FOREIGN KEY (candidate_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS job_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NOT NULL,
    view_month VARCHAR(7) NOT NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_views_user_job_month (user_id, job_id, view_month),
    INDEX idx_job_views_month_user (view_month, user_id),
    INDEX idx_job_views_job (job_id),
    CONSTRAINT fk_job_views_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_views_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS premium_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_reference_id CHAR(36) NOT NULL UNIQUE,
    external_id VARCHAR(64) NOT NULL UNIQUE,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    premium_days SMALLINT UNSIGNED NOT NULL,
    status ENUM('pending', 'successful', 'failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_premium_payments_user_status_created (user_id, status, created_at),
    CONSTRAINT fk_premium_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS wallets (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    balance_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS wallet_topups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_reference_id CHAR(36) NOT NULL UNIQUE,
    external_id VARCHAR(64) NOT NULL UNIQUE,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('pending', 'successful', 'failed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_wallet_topups_user_status_created (user_id, status, created_at),
    CONSTRAINT fk_wallet_topups_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    transaction_type ENUM('top_up', 'premium_purchase') NOT NULL,
    amount_xaf BIGINT UNSIGNED NOT NULL,
    balance_after_xaf BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(80) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wallet_transactions_user_created (user_id, created_at),
    CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

INSERT INTO wallets (user_id)
SELECT id FROM users
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

SET @aurora_add_cover_letter = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'cover_letter'
    ) = 0,
    'ALTER TABLE applications ADD COLUMN cover_letter TEXT NULL AFTER candidate_id',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_cover_letter;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_relevant_experience = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'relevant_experience'
    ) = 0,
    'ALTER TABLE applications ADD COLUMN relevant_experience TEXT NULL AFTER cover_letter',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_relevant_experience;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_portfolio_url = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'portfolio_url'
    ) = 0,
    'ALTER TABLE applications ADD COLUMN portfolio_url VARCHAR(2048) NULL AFTER relevant_experience',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_portfolio_url;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;
