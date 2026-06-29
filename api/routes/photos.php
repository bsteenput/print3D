<?php
// Route : GET /api/photos/{job_id}/{filename}
// Sert les photos sans authentification (URLs non-devinables grâce au uniqid)

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri  = preg_replace('#^/api/photos/#', '', $uri);
$segs = explode('/', $uri, 2);

$job_id   = (int)($segs[0] ?? 0);
$filename = urldecode($segs[1] ?? '');

if (!$job_id || !$filename) json_err('Introuvable', 404);

// Sécurité : éviter les path traversal
$filename = basename($filename);
$path     = UPLOAD_DIR . "job_{$job_id}/photos/" . $filename;

if (!file_exists($path) || !is_file($path)) json_err('Photo introuvable', 404);

$ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = match($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'webp'        => 'image/webp',
    'gif'         => 'image/gif',
    default       => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000');
readfile($path);
exit;
