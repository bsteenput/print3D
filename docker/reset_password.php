<?php
// Usage : php docker/reset_password.php <email> <nouveau_mot_de_passe>
if (PHP_SAPI !== 'cli') { echo "CLI only\n"; exit(1); }

$email = $argv[1] ?? null;
$pass  = $argv[2] ?? null;

if (!$email || !$pass) {
    echo "Usage : php docker/reset_password.php <email> <nouveau_mot_de_passe>\n";
    exit(1);
}

if (strlen($pass) < 6) {
    echo "Erreur : mot de passe trop court (minimum 6 caractères)\n";
    exit(1);
}

if (file_exists(__DIR__ . '/../config/config.local.php')) {
    require_once __DIR__ . '/../config/config.local.php';
} else {
    require_once __DIR__ . '/../config/config.php';
}
require_once __DIR__ . '/../config/db.php';

$stmt = db()->prepare('SELECT id, name, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo "Erreur : aucun utilisateur trouvé avec l'email « {$email} »\n";
    exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);
db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user['id']]);

echo "✓ Mot de passe mis à jour pour {$user['name']} ({$user['role']})\n";
