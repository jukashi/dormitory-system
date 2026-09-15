-- OFW Dormitory Management System
-- Clean installation schema for MySQL 8.0.16 or newer.
-- Import this file with a database account that can create and use
-- the ofw_dormitory_system database.

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS ofw_dormitory_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ofw_dormitory_system;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
  job_role VARCHAR(100) NULL,
  status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
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

CREATE TABLE staff_permissions (
  user_id INT UNSIGNED NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  PRIMARY KEY (user_id, permission_key),
  CONSTRAINT fk_staff_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dormitories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dormitory_id INT UNSIGNED NOT NULL,
  room_number VARCHAR(30) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 4,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_rooms_dormitory FOREIGN KEY (dormitory_id) REFERENCES dormitories(id) ON DELETE RESTRICT,
  CONSTRAINT uq_room_number_per_dormitory UNIQUE (dormitory_id, room_number),
  CONSTRAINT chk_room_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE employers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE agencies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  nationality VARCHAR(80) NULL,
  contact_no VARCHAR(50) NULL,
  passport_no VARCHAR(80) NULL UNIQUE,
  passport_expiry DATE NULL,
  arc_no VARCHAR(80) NULL UNIQUE,
  arc_expiry DATE NULL,
  employee_id VARCHAR(80) NULL,
  employer_id INT UNSIGNED NULL,
  agency_id INT UNSIGNED NULL,
  designation VARCHAR(150) NULL,
  emergency_contact_name VARCHAR(150) NULL,
  emergency_contact_no VARCHAR(50) NULL,
  additional_comments TEXT NULL,
  room_id INT UNSIGNED NULL,
  bed_number VARCHAR(30) NULL,
  shift_code ENUM('DA', 'DB', 'NA', 'NB') NULL,
  monthly_rent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  date_moved_in DATE NOT NULL,
  date_moved_out DATE NULL,
  photo_path VARCHAR(255) NULL,
  status ENUM('active', 'moved_out') NOT NULL DEFAULT 'active',
  active_room_id INT UNSIGNED AS (CASE WHEN status = 'active' THEN room_id ELSE NULL END) STORED,
  active_bed_number VARCHAR(30) AS (CASE WHEN status = 'active' THEN LOWER(TRIM(bed_number)) ELSE NULL END) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenants_employer FOREIGN KEY (employer_id) REFERENCES employers(id) ON DELETE SET NULL,
  CONSTRAINT fk_tenants_agency FOREIGN KEY (agency_id) REFERENCES agencies(id) ON DELETE SET NULL,
  CONSTRAINT fk_tenants_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
  CONSTRAINT chk_tenant_move_dates CHECK (date_moved_out IS NULL OR date_moved_out >= date_moved_in),
  CONSTRAINT uq_active_room_bed UNIQUE (active_room_id, active_bed_number),
  INDEX idx_tenants_status_room (status, room_id),
  INDEX idx_tenants_shift_status (shift_code, status)
) ENGINE=InnoDB;

CREATE TABLE tenant_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_tenant_items_name UNIQUE (name),
  INDEX idx_tenant_items_active_name (is_active, name)
) ENGINE=InnoDB;

CREATE TABLE tenant_item_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  reference_no VARCHAR(100) NULL,
  issue_notes VARCHAR(1000) NULL,
  issued_on DATE NOT NULL,
  issued_by INT UNSIGNED NULL,
  returned_on DATE NULL,
  return_notes VARCHAR(1000) NULL,
  returned_by INT UNSIGNED NULL,
  active_item_id INT UNSIGNED AS (CASE WHEN returned_on IS NULL THEN item_id ELSE NULL END) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_item_assignments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tenant_item_assignments_item FOREIGN KEY (item_id) REFERENCES tenant_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tenant_item_assignments_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tenant_item_assignments_returned_by FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_tenant_item_return_date CHECK (returned_on IS NULL OR returned_on >= issued_on),
  CONSTRAINT uq_tenant_active_item UNIQUE (tenant_id, active_item_id),
  INDEX idx_tenant_item_report (item_id, returned_on, issued_on),
  INDEX idx_tenant_item_tenant_history (tenant_id, issued_on, id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_month DATE NOT NULL,
  payment_date DATE NOT NULL,
  payment_method ENUM('cash', 'bank_transfer', 'payroll_deduction', 'other') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(100) NULL,
  notes TEXT NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payment_amount CHECK (amount > 0),
  INDEX idx_payments_month (payment_month),
  INDEX idx_payments_tenant (tenant_id),
  INDEX idx_payments_tenant_month (tenant_id, payment_month)
) ENGINE=InnoDB;

CREATE TABLE visitors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  visitor_name VARCHAR(150) NOT NULL,
  visitor_id_no VARCHAR(100) NULL,
  purpose VARCHAR(255) NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_visitors_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_visitors_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_visitor_times CHECK (time_out IS NULL OR time_out >= time_in),
  INDEX idx_visitors_time_in (time_in)
) ENGINE=InnoDB;

CREATE TABLE maintenance_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NULL,
  description TEXT NOT NULL,
  date_reported DATE NOT NULL,
  status ENUM('pending', 'in_progress', 'resolved') NOT NULL DEFAULT 'pending',
  assigned_to INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_maintenance_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  CONSTRAINT fk_maintenance_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_maintenance_status (status),
  INDEX idx_maintenance_status_date (status, date_reported)
) ENGINE=InnoDB;

CREATE TABLE schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  event_type ENUM('da', 'db', 'na', 'nb', 'work_shift', 'day_off', 'vacation_leave', 'sick_leave', 'leave', 'flight', 'appointment', 'emergency', 'other') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_schedules_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_schedule_dates CHECK (end_date IS NULL OR end_date >= start_date),
  INDEX idx_schedules_dates (start_date, end_date),
  INDEX idx_schedules_tenant_dates (tenant_id, start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE schedule_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  comment_text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_schedule_comments_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_schedule_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE shift_schedule_entries (
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

CREATE TABLE staff_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_staff_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_staff_event_dates CHECK (end_date IS NULL OR end_date >= start_date),
  INDEX idx_staff_events_dates (start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('company_name', 'OFW Dormitory System'),
  ('company_logo_path', NULL);
