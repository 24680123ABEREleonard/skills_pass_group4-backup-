USE skillpass;

ALTER TABLE users
  MODIFY role ENUM('jobseeker', 'employer', 'admin') NOT NULL,
  ADD COLUMN status ENUM('pending', 'active', 'rejected') NOT NULL DEFAULT 'active' AFTER role;

ALTER TABLE jobs
  MODIFY status ENUM('pending', 'open', 'closed', 'rejected') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id BIGINT UNSIGNED NOT NULL,
  recipient_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  read_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_recipient_created (recipient_id, created_at),
  KEY idx_messages_sender_created (sender_id, created_at),
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
