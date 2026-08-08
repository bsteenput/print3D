<?php
// Routes : GET /api/track/{token}, POST /api/track/{token}/messages
// Accès public — le token fait office d'authentification

$token = preg_replace('/[^a-fA-F0-9]/', '', $parts[1] ?? '');
$sub2  = $parts[2] ?? null;

if (!$token) json_err('Token invalide', 400);

$pdo  = db();
$stmt = $pdo->prepare('SELECT id, ref, title FROM jobs WHERE tracking_token = ?');
$stmt->execute([$token]);
$job_row = $stmt->fetch();
if (!$job_row) json_err('Job introuvable', 404);
$job_id = (int)$job_row['id'];

// ── POST /api/track/{token}/messages — le client écrit à l'admin ──
if ($method === 'POST' && $sub2 === 'messages') {
    $b       = body();
    $message = trim($b['message'] ?? '');
    if ($message === '') json_err('Message requis');
    if (mb_strlen($message) > 2000) json_err('Message trop long (2000 caractères max)');

    $pdo->prepare('INSERT INTO job_messages (job_id, sender_role, message) VALUES (?, ?, ?)')
        ->execute([$job_id, 'client', $message]);

    notify_admin_whatsapp(
        "💬 Nouveau message de {$job_row['ref']} ({$job_row['title']}) :\n" . mb_substr($message, 0, 300)
    );

    json_ok(['sent' => true], 201);
}

if ($method !== 'GET') json_err('Méthode non supportée', 405);

$stmt = $pdo->prepare(
    "SELECT j.id, j.ref, j.title, j.status, j.print_type,
            j.layer_current, j.layer_total, j.eta, j.print_hours,
            j.price_final, j.created_at, j.finished_at,
            u.name AS client_name,
            p.name AS printer_name,
            f.material AS filament_material, f.color AS filament_color, f.color_hex
     FROM jobs j
     LEFT JOIN users u     ON u.id = j.client_id
     LEFT JOIN printers p  ON p.id = j.printer_id
     LEFT JOIN filaments f ON f.id = j.filament_id
     WHERE j.id = ?"
);
$stmt->execute([$job_id]);
$job = $stmt->fetch();
if (!$job) json_err('Job introuvable', 404);

$events = $pdo->prepare(
    'SELECT status, message, created_at FROM job_events WHERE job_id = ? ORDER BY created_at'
);
$events->execute([$job_id]);
$job['events'] = $events->fetchAll();

// Photos publiques
$photos = $pdo->prepare(
    'SELECT id, filename, path, uploaded_at FROM job_photos WHERE job_id = ? ORDER BY uploaded_at'
);
$photos->execute([$job_id]);
$job['photos'] = array_map(function ($p) use ($job_id) {
    $p['url'] = '/api/photos/' . $job_id . '/' . urlencode(basename($p['path']));
    return $p;
}, $photos->fetchAll());

// Messages (discussion avec l'admin)
$messages = $pdo->prepare(
    'SELECT sender_role, message, created_at FROM job_messages WHERE job_id = ? ORDER BY created_at'
);
$messages->execute([$job_id]);
$job['messages'] = $messages->fetchAll();

json_ok($job);
