<?php
// Route : GET /api/track/{token}
// Accès public — retourne les infos de suivi d'un job via son token

if ($method !== 'GET') json_err('Méthode non supportée', 405);

$uri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri   = preg_replace('#^/api/track/#', '', $uri);
$token = preg_replace('/[^a-fA-F0-9]/', '', $uri);

if (!$token) json_err('Token invalide', 400);

$pdo  = db();
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
     WHERE j.tracking_token = ?"
);
$stmt->execute([$token]);
$job = $stmt->fetch();
if (!$job) json_err('Job introuvable', 404);

$events = $pdo->prepare(
    'SELECT status, message, created_at FROM job_events WHERE job_id = ? ORDER BY created_at'
);
$events->execute([$job['id']]);
$job['events'] = $events->fetchAll();

// Photos publiques
$photos = $pdo->prepare(
    'SELECT id, filename, path, uploaded_at FROM job_photos WHERE job_id = ? ORDER BY uploaded_at'
);
$photos->execute([$job['id']]);
$job['photos'] = array_map(function ($p) use ($job) {
    $p['url'] = '/api/photos/' . $job['id'] . '/' . urlencode(basename($p['path']));
    return $p;
}, $photos->fetchAll());

json_ok($job);
