USE ofw_dormitory_system;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_key CHAR(64) PRIMARY KEY,
  attempted_username VARCHAR(50) NOT NULL,
  remote_address VARCHAR(45) NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_updated (updated_at),
  INDEX idx_login_attempts_blocked (blocked_until)
) ENGINE=InnoDB;

ALTER TABLE users
  ADD INDEX idx_users_role_status (role, status);

ALTER TABLE tenants
  DROP FOREIGN KEY fk_tenants_room;

ALTER TABLE tenants
  ADD CONSTRAINT fk_tenants_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT;

ALTER TABLE tenants
  ADD COLUMN active_room_id INT UNSIGNED AS (CASE WHEN status = 'active' THEN room_id ELSE NULL END) STORED,
  ADD COLUMN active_bed_number VARCHAR(30) AS (CASE WHEN status = 'active' THEN LOWER(TRIM(bed_number)) ELSE NULL END) STORED,
  ADD UNIQUE INDEX uq_active_room_bed (active_room_id, active_bed_number);
