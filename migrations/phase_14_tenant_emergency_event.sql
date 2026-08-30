-- Phase 14: run once on existing installations.
-- Adds Emergency as an available manual tenant-calendar event type.

USE ofw_dormitory_system;

ALTER TABLE schedules
  MODIFY event_type ENUM(
    'da', 'db', 'na', 'nb',
    'work_shift', 'day_off', 'vacation_leave', 'sick_leave',
    'leave', 'flight', 'appointment', 'emergency', 'other'
  ) NOT NULL;
