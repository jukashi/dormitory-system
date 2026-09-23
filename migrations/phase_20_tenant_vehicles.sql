-- Phase 20: tenant vehicle registry
-- Apply once after phase_19_tenant_items.sql when upgrading an existing installation.

CREATE TABLE tenant_vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  vehicle_type VARCHAR(50) NOT NULL,
  license_plate_no VARCHAR(30) NOT NULL,
  sticker_no VARCHAR(50) NULL,
  registration_date DATE NULL,
  notes VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tenant_vehicles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
  CONSTRAINT uq_tenant_vehicles_plate UNIQUE (license_plate_no),
  INDEX idx_tenant_vehicles_tenant (tenant_id),
  INDEX idx_tenant_vehicles_type (vehicle_type)
) ENGINE=InnoDB;
