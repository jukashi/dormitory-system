-- Phase 9: run once on existing installations.
-- Adds distinct Vacation Leave (VL) and Sick Leave (SL) event types.

USE ofw_dormitory_system;

ALTER TABLE schedules
  MODIFY event_type ENUM(
    'work_shift',
    'day_off',
    'vacation_leave',
    'sick_leave',
    'leave',
    'flight',
    'appointment',
    'other'
  ) NOT NULL;
