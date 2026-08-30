-- Phase 10: run once on existing installations.
-- Adds a job role for staff and module-level access assignments.

USE ofw_dormitory_system;

ALTER TABLE users
  ADD COLUMN job_role VARCHAR(100) NULL AFTER role;

CREATE TABLE IF NOT EXISTS staff_permissions (
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (user_id, permission_key),
  CONSTRAINT fk_staff_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
