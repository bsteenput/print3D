<?php
// Route : GET /api/gallery
// Accès public — liste les jobs marqués "in_gallery" avec leurs photos

if ($method !== 'GET') json_err('Méthode non supportée', 405);

$pdo  = db();
$jobs = $pdo->query(
    "SELECT j.id, j.ref, j.title, j.description, j.print_type,
            j.grams_used, j.ml_used, j.print_hours,
            j.finished_at, j.created_at,
            u.name AS client_name,
            f.material AS filament_material, f.color AS filament_color, f.color_hex
     FROM jobs j
     LEFT JOIN users u     ON u.id = j.client_id
     LEFT JOIN filaments f ON f.id = j.filament_id
     WHERE j.in_gallery = 1 AND j.status IN ('done','picked_up')
     ORDER BY j.finished_at DESC, j.created_at DESC"
)->fetchAll();

$photos_stmt = $pdo->prepare(
    'SELECT id, filename, path, uploaded_at FROM job_photos WHERE job_id = ? ORDER BY uploaded_at LIMIT 6'
);

foreach ($jobs as &$job) {
    $photos_stmt->execute([$job['id']]);
    $job['photos'] = array_map(function ($p) use ($job) {
        $p['url'] = '/api/photos/' . $job['id'] . '/' . urlencode(basename($p['path']));
        return $p;
    }, $photos_stmt->fetchAll());
}
unset($job);

json_ok($jobs);
