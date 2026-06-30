<?php
// Production — les valeurs sont injectées via variables d'environnement (Coolify)
// En local, config.local.php est chargé à la place (voir helpers.php)

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME')    ?: 'print3d');
define('DB_USER',    getenv('DB_USER')    ?: 'print3d_user');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_FILE_SIZE', (int)(getenv('MAX_FILE_SIZE_MB') ?: 500) * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['stl', 'STL']);

define('JWT_SECRET', getenv('JWT_SECRET') ?: 'CHANGE_ME_RANDOM_32_CHARS');
define('JWT_EXPIRY', 60 * 60 * 24 * 7);

define('APP_URL',  getenv('APP_URL')  ?: 'https://print3d.example.com');
define('APP_ENV',  getenv('APP_ENV')  ?: 'production');

define('MAIL_FROM',      getenv('MAIL_FROM')      ?: 'noreply@example.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Print3D');
