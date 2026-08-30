-- Phase 11: run once on existing installations.
-- Adds the four shift codes used by DATE / IS WORK DAY CSV imports.

USE ofw_dormitory_system;

ALTER TABLE schedules
  MODIFY event_type ENUM(
    'da', 'db', 'na', 'nb',
    'work_shift', 'day_off', 'vacation_leave', 'sick_leave',
    'leave', 'flight', 'appointment', 'other'
  ) NOT NULL;
