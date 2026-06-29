<?php
// ============================================================
//  Print3D — API Router
//  Toutes les requêtes /api/* arrivent ici via .htaccess
// ============================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^/api#', '', $uri);   // strip /api prefix
$parts  = array_values(array_filter(explode('/', $uri)));

// Route : /api/{resource}/{id?}/{sub?}/{sub_id?}
$resource = $parts[0] ?? '';
$id       = isset($parts[1]) ? (int)$parts[1] : null;
$sub      = $parts[2] ?? null;
$sub_id   = isset($parts[3]) ? (int)$parts[3] : null;

match(true) {

    // ── Auth ─────────────────────────────────────────────────
    $resource === 'auth' && $parts[1] === 'login'    => require __DIR__ . '/routes/auth.php',
    $resource === 'auth' && $parts[1] === 'me'       => require __DIR__ . '/routes/auth.php',
    $resource === 'auth' && $parts[1] === 'register' => require __DIR__ . '/routes/auth.php',

    // ── Jobs ─────────────────────────────────────────────────
    $resource === 'jobs' && $id === null              => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $id !== null && $sub === null
                                                      => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'status'         => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'files'          => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'items'          => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'photos'         => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'token'          => require __DIR__ . '/routes/jobs.php',
    $resource === 'jobs' && $sub === 'payment'        => require __DIR__ . '/routes/jobs.php',

    // ── Photos (accès public) ─────────────────────────────────
    $resource === 'photos'                            => require __DIR__ . '/routes/photos.php',

    // ── Suivi public par token ────────────────────────────────
    $resource === 'track'                             => require __DIR__ . '/routes/track.php',

    // ── Clients ──────────────────────────────────────────────
    $resource === 'clients'                           => require __DIR__ . '/routes/clients.php',

    // ── Printers ─────────────────────────────────────────────
    $resource === 'printers'                          => require __DIR__ . '/routes/printers.php',

    // ── Filaments ────────────────────────────────────────────
    $resource === 'filaments'                         => require __DIR__ . '/routes/filaments.php',

    // ── Settings ─────────────────────────────────────────────
    $resource === 'settings'                          => require __DIR__ . '/routes/settings.php',

    // ── Dashboard stats ──────────────────────────────────────
    $resource === 'dashboard'                         => require __DIR__ . '/routes/dashboard.php',

    // ── Fichiers (proxy authentifié) ─────────────────────────
    $resource === 'files'                             => require __DIR__ . '/routes/files.php',

    // ── Monitoring imprimante physique ────────────────────────
    $resource === 'monitor'                           => require __DIR__ . '/routes/monitor.php',

    default => json_err('Route introuvable', 404),
};
