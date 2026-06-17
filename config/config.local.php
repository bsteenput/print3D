<?php
// Config locale Docker — ne pas committer
define('DB_HOST', 'db');              // nom du service docker-compose
define('DB_NAME', 'print3d');
define('DB_USER', 'print3d_user');
define('DB_PASS', 'print3d_pass');
define('DB_CHARSET', 'utf8mb4');

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['stl', 'STL']);

define('JWT_SECRET', 'local_dev_secret_32_chars_xxxxx!');
define('JWT_EXPIRY', 60 * 60 * 24 * 7);

define('APP_URL', 'http://localhost:8080');
define('APP_ENV', 'dev');

define('MAIL_FROM', 'noreply@localhost');
define('MAIL_FROM_NAME', 'Print3D Local');
