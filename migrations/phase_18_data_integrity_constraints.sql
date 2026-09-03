-- Phase 18: run once on existing installations after confirming there are no invalid historical rows.
-- Prevents impossible date ranges even when data is written outside the web interface.

USE ofw_dormitory_system;

ALTER TABLE tenants
  ADD CONSTRAINT chk_tenant_move_dates CHECK (date_moved_out IS NULL OR date_moved_out >= date_moved_in);

ALTER TABLE visitors
  ADD CONSTRAINT chk_visitor_times CHECK (time_out IS NULL OR time_out >= time_in);

ALTER TABLE schedules
  ADD CONSTRAINT chk_schedule_dates CHECK (end_date IS NULL OR end_date >= start_date);

ALTER TABLE staff_events
  ADD CONSTRAINT chk_staff_event_dates CHECK (end_date IS NULL OR end_date >= start_date);
