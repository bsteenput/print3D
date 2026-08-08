-- migrations/014_remove_email_settings.sql
-- Suppression de l'envoi d'email : ces réglages ne pilotent plus rien.
DELETE FROM settings WHERE key_name IN ('contact_email', 'notify_on_status');
