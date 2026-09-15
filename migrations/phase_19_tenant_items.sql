-- Phase 19: configurable tenant items with issue and return history.
-- Run once on existing installations after taking a database backup.

USE ofw_dormitory_system;

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
