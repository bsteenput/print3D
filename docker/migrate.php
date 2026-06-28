<?php
// Runs pending SQL migrations from /var/www/html/migrations/*.sql
// Tracks applied files in the `schema_migrations` table.

$host = getenv('DB_HOST') ?: 'db';
$name = getenv('DB_NAME') ?: 'print3d';
$user = getenv('DB_USER') ?: 'print3d_user';
$pass = getenv('DB_PASS') ?: '';

// Retry loop — MySQL may not be ready immediately even with depends_on healthy
$pdo = null;
for ($i = 0; $i < 10; $i++) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        echo "Waiting for DB... ($i)\n";
        sleep(2);
    }
}
if (!$pdo) { echo "ERROR: Could not connect to database.\n"; exit(1); }

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename    VARCHAR(255) PRIMARY KEY,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dir   = __DIR__ . '/../migrations';
$files = glob($dir . '/*.sql');
sort($files);

foreach ($files as $path) {
    $filename = basename($path);
    $row = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $row->execute([$filename]);
    if ($row->fetchColumn()) {
        echo "  skip  $filename\n";
        continue;
    }
    $sql = file_get_contents($path);
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$filename]);
    echo "  apply $filename\n";
}

echo "Migrations done.\n";
