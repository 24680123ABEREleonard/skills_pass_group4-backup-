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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
    CONSTRAINT fk_jobs_selected_candidate FOREIGN KEY (selected_candidate_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

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
    CONSTRAINT fk_applications_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_candidate FOREIGN KEY (candidate_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS application_unlock_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    employer_id BIGINT UNSIGNED NOT NULL,
    amount_xaf INT UNSIGNED NOT NULL,
    transaction_reference VARCHAR(100) NOT NULL UNIQUE,
    payment_method ENUM('manual', 'mtn_momo') NOT NULL DEFAULT 'manual',
    payer_phone VARCHAR(20) NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_unlock_payments_pending (status, created_at),
    INDEX idx_unlock_payments_application (application_id, id),
    CONSTRAINT fk_unlock_payments_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_unlock_payments_employer FOREIGN KEY (employer_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_unlock_payments_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @aurora_add_unlock_payment_method = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'application_unlock_payments'
          AND COLUMN_NAME = 'payment_method'
    ) = 0,
    'ALTER TABLE application_unlock_payments ADD COLUMN payment_method ENUM(''manual'', ''mtn_momo'') NOT NULL DEFAULT ''manual'' AFTER transaction_reference',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_unlock_payment_method;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

SET @aurora_add_unlock_payer_phone = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'application_unlock_payments'
          AND COLUMN_NAME = 'payer_phone'
    ) = 0,
    'ALTER TABLE application_unlock_payments ADD COLUMN payer_phone VARCHAR(20) NULL AFTER payment_method',
    'SELECT 1'
);
PREPARE aurora_schema_statement FROM @aurora_add_unlock_payer_phone;
EXECUTE aurora_schema_statement;
DEALLOCATE PREPARE aurora_schema_statement;

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
