<?php
if (file_exists(__DIR__ . '/../config/config.local.php')) {
    require_once __DIR__ . '/../config/config.local.php';
} else {
    require_once __DIR__ . '/../config/config.php';
}
require_once __DIR__ . '/../config/db.php';

// ── Réponses JSON ────────────────────────────────────────────
function json_ok(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── JWT minimal (sans librairie externe) ────────────────────
function jwt_encode(array $payload): string {
    $header  = base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url(json_encode($payload));
    $sig     = base64url(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64url(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Auth ─────────────────────────────────────────────────────
function current_user(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
    if (!str_starts_with($header, 'Bearer ')) return null;
    $token = substr($header, 7);
    $payload = jwt_decode($token);
    if (!$payload) return null;
    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $stmt->execute([$payload['sub']]);
    return $stmt->fetch() ?: null;
}

function require_auth(): array {
    $user = current_user();
    if (!$user) json_err('Non authentifié', 401);
    return $user;
}

function require_admin(): array {
    $user = require_auth();
    if ($user['role'] !== 'admin') json_err('Accès refusé', 403);
    return $user;
}

// ── Calcul prix automatique ───────────────────────────────────
function calc_price_auto(float $grams, float $hours, float $price_per_kg, float $hourly_rate): float {
    $filament_cost = ($grams / 1000) * $price_per_kg;
    $machine_cost  = $hours * $hourly_rate;
    return round($filament_cost + $machine_cost, 2);
}

// ── URL de base (host réel de la requête) ────────────────────
function base_url(): string {
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // Derrière Traefik/Coolify le proto réel est dans X-Forwarded-Proto
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    return APP_URL;
}

// ── Génération ref job ────────────────────────────────────────
function next_job_ref(): string {
    $stmt = db()->query('SELECT MAX(CAST(SUBSTRING(ref, 5) AS UNSIGNED)) AS max_n FROM jobs');
    $row  = $stmt->fetch();
    $n    = ($row['max_n'] ?? 0) + 1;
    return 'JOB-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Upload fichiers 3D ────────────────────────────────────────
function handle_stl_upload(int $job_id): array {
    $saved = [];
    $files = $_FILES['stl'] ?? null;
    if (!$files) return $saved;

    // Normaliser en tableau (un ou plusieurs fichiers)
    if (!is_array($files['name'])) {
        foreach ($files as $k => $v) $files[$k] = [$v];
    }

    // Extensions autorisées pour l'impression 3D
    $allowed_exts = ['stl', '3mf', 'obj'];

    // Types MIME dangereux à bloquer explicitement
    $blocked_mimes = [
        'text/html', 'application/xhtml+xml',
        'application/x-php', 'application/x-httpd-php',
        'application/javascript', 'text/javascript',
        'application/x-executable', 'application/x-sharedlib',
        'application/x-sh', 'application/x-csh',
    ];

    // Marqueurs de code à rejeter dans les premiers octets du fichier
    $code_markers = ['<?php', '<?=', '<script', '<!DOCTYPE', '<html', '<%', '#!'];

    $dir = UPLOAD_DIR . "job_{$job_id}/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Chemins relatifs envoyés en parallèle (upload de dossier complet) : un par fichier, même ordre
    $rel_paths = $_POST['stl_paths'] ?? [];

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) continue;

        $tmp = $files['tmp_name'][$i];

        // 1. Vérification MIME type (si finfo disponible)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (in_array($mime, $blocked_mimes)) continue;
        }

        // 2. Vérification du contenu — rejeter si marqueurs de code présents
        $head = (string) file_get_contents($tmp, false, null, 0, 512);
        $safe_content = true;
        foreach ($code_markers as $marker) {
            if (stripos($head, $marker) !== false) { $safe_content = false; break; }
        }
        if (!$safe_content) continue;

        // Chemin relatif (dossier d'origine), nettoyé segment par segment — évite ../ et caractères dangereux
        $rel_path = null;
        if (!empty($rel_paths[$i]) && is_string($rel_paths[$i])) {
            $segments = array_filter(explode('/', str_replace('\\', '/', $rel_paths[$i])), fn($s) => $s !== '' && $s !== '.' && $s !== '..');
            $segments = array_map(fn($s) => preg_replace('/[^a-zA-Z0-9_\-. ]/', '_', $s), $segments);
            if ($segments) $rel_path = implode('/', $segments);
        }
        $rel_path ??= $name;

        $sub_dir = trim(dirname($rel_path), '.');
        $dest_dir = $sub_dir !== '' ? $dir . $sub_dir . '/' : $dir;
        if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);

        $safe     = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
        $filename = uniqid() . '_' . $safe;
        $dest     = $dest_dir . $filename;

        if (move_uploaded_file($tmp, $dest)) {
            $pdo  = db();
            $stmt = $pdo->prepare(
                'INSERT INTO job_files (job_id, filename, relative_path, path, size_bytes) VALUES (?,?,?,?,?)'
            );
            $rel = $sub_dir !== '' ? "job_{$job_id}/{$sub_dir}/{$filename}" : "job_{$job_id}/{$filename}";
            $stmt->execute([$job_id, $name, $rel_path, $rel, $files['size'][$i]]);
            $raw_url_path = ltrim(($sub_dir !== '' ? $sub_dir . '/' : '') . $filename, '/');
            $encoded_url  = implode('/', array_map('rawurlencode', explode('/', $raw_url_path)));
            $saved[] = [
                'id'            => (int)$pdo->lastInsertId(),
                'filename'      => $name,
                'relative_path' => $rel_path,
                'url'           => '/api/files/' . $job_id . '/' . $encoded_url,
            ];
        }
    }
    return $saved;
}

// ── Upload photos ─────────────────────────────────────────────
function handle_photo_upload(int $job_id): array {
    $saved = [];
    $files = $_FILES['photo'] ?? null;
    if (!$files) return $saved;

    if (!is_array($files['name'])) {
        foreach ($files as $k => $v) $files[$k] = [$v];
    }

    $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    $dir = UPLOAD_DIR . "job_{$job_id}/photos/";
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts)) continue;

        $tmp = $files['tmp_name'][$i];

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if (!in_array($mime, $allowed_mimes)) continue;
        }

        $safe     = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
        $filename = uniqid() . '_' . $safe;
        $dest     = $dir . $filename;

        if (move_uploaded_file($tmp, $dest)) {
            $pdo  = db();
            $stmt = $pdo->prepare(
                'INSERT INTO job_photos (job_id, filename, path, size_bytes) VALUES (?,?,?,?)'
            );
            $rel = "job_{$job_id}/photos/{$filename}";
            $stmt->execute([$job_id, $name, $rel, $files['size'][$i]]);
            $saved[] = [
                'id'       => (int)$pdo->lastInsertId(),
                'filename' => $name,
                'url'      => '/api/photos/' . $job_id . '/' . urlencode($filename),
            ];
        }
    }
    return $saved;
}

// ── Génération token de suivi ─────────────────────────────────
function generate_tracking_token(): string {
    return bin2hex(random_bytes(16));
}

// ── Email notification ────────────────────────────────────────
function notify_client_status(int $job_id, string $status): void {
    $setting = db()->query("SELECT value FROM settings WHERE key_name='notify_on_status'")->fetchColumn();
    if (!$setting) return;

    $stmt = db()->prepare('
        SELECT j.ref, j.title, j.price_final, j.tracking_token, u.name, u.email
        FROM jobs j JOIN users u ON u.id = j.client_id
        WHERE j.id = ?
    ');
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['email']) return;

    $labels = [
        'quote'     => 'Devis en cours d\'étude',
        'queued'    => 'En file d\'attente',
        'printing'  => 'En cours d\'impression',
        'done'      => 'Prête à récupérer !',
        'picked_up' => 'Récupérée — merci !',
        'cancelled' => 'Annulée',
    ];
    $label = $labels[$status] ?? $status;

    $price_line = '';
    if ($status === 'done' && $row['price_final']) {
        $price_line = "\nPrix : " . number_format($row['price_final'], 2) . " €";
    }

    $subject = "[Print3D] {$row['ref']} — {$label}";
    $track_line = $row['tracking_token']
        ? "\nSuis l'avancement en direct : " . base_url() . "/track/" . $row['tracking_token']
        : '';

    $body    = "Bonjour {$row['name']},\n\n"
             . "Ton impression « {$row['title']} » ({$row['ref']}) a changé de statut :\n\n"
             . "  ➜  {$label}\n"
             . $price_line . $track_line . "\n\n"
             . "— Bertrand";

    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";

    mail($row['email'], $subject, $body, $headers);
}
