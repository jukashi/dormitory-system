-- Phase 12: run once on existing installations.
-- Imported shift dates are shared by shift code, not copied to individual tenants.

USE ofw_dormitory_system;

CREATE TABLE IF NOT EXISTS shift_schedule_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_code ENUM('DA', 'DB', 'NA', 'NB') NOT NULL,
  schedule_date DATE NOT NULL,
  is_work_day TINYINT(1) NOT NULL DEFAULT 1,
  source_value VARCHAR(50) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_shift_schedule_entries_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT uq_shift_schedule_date UNIQUE (shift_code, schedule_date),
  INDEX idx_shift_schedule_date (schedule_date)
) ENGINE=InnoDB;
