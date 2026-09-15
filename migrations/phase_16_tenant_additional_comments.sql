USE ofw_dormitory_system;

ALTER TABLE tenants
  ADD COLUMN additional_comments TEXT NULL AFTER emergency_contact_no;
