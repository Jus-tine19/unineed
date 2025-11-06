-- Migration: add course and year_level to users
ALTER TABLE users
  ADD COLUMN course VARCHAR(255) NULL AFTER student_id,
  ADD COLUMN year_level VARCHAR(50) NULL AFTER course;

-- Example: update existing users to have empty values
UPDATE users SET course = NULL WHERE course = '';
UPDATE users SET year_level = NULL WHERE year_level = '';
