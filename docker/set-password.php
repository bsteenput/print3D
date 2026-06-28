<?php
// Script one-shot : définit le mot de passe de l'admin
// Usage : docker compose exec php php /var/www/html/docker/set-password.php
$cfg = file_exists('/var/www/html/config/config.local.php')
    ? '/var/www/html/config/config.local.php'
    : '/var/www/html/config/config.php';
require_once $cfg;
require_once '/var/www/html/config/db.php';

$password = $argv[1] ?? 'print3d123';
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = db()->prepare("UPDATE users SET password = ? WHERE email = 'bertrand@example.com'");
$stmt->execute([$hash]);

echo "✓ Mot de passe admin défini : $password\n";
echo "  Email : bertrand@example.com\n";
