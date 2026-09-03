-- Phase 17: run once on existing installations.
-- Adds composite indexes for the application's most frequent filters and joins.

USE ofw_dormitory_system;

ALTER TABLE tenants
  ADD INDEX idx_tenants_status_room (status, room_id),
  ADD INDEX idx_tenants_shift_status (shift_code, status);

ALTER TABLE payments
  ADD INDEX idx_payments_tenant_month (tenant_id, payment_month);

ALTER TABLE maintenance_requests
  ADD INDEX idx_maintenance_status_date (status, date_reported);

ALTER TABLE schedules
  ADD INDEX idx_schedules_tenant_dates (tenant_id, start_date, end_date);
