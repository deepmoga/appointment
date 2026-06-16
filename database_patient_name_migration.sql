-- Add patient_name column to appointments to store per-booking name independently
-- This prevents same-phone customers from sharing/overwriting each other's names

ALTER TABLE appointments
ADD COLUMN patient_name VARCHAR(150) NULL DEFAULT NULL
AFTER customer_id;
