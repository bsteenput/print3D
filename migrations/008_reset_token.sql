ALTER TABLE users
    ADD COLUMN reset_token            VARCHAR(64)  NULL,
    ADD COLUMN reset_token_expires_at DATETIME     NULL;
