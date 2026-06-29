ALTER TABLE jobs ADD COLUMN tracking_token VARCHAR(64) NULL;
CREATE UNIQUE INDEX idx_jobs_tracking_token ON jobs (tracking_token);
