<?php
// Route : GET /api/files/{job_id}/{filename}
// Sert les fichiers STL avec vérification d'authentification et d'appartenance
$user     = require_auth();
$is_admin = $user['role'] === 'admin';

if ($method !== 'GET') json_err('Méthode non supportée', 405);

$job_id    = (int)($parts[1] ?? 0);
// Le fichier peut être dans un sous-dossier (upload de dossier complet) : on rejoint tous les segments restants,
// chacun passé par basename() pour neutraliser toute traversée de chemin (../).
// Décoder chaque segment (%20 → espace, etc.) puis basename() contre les traversées de chemin
$segments = array_map(fn($s) => basename(rawurldecode($s)), array_slice($parts, 2));
$filename = end($segments) ?: '';
$relpath  = implode('/', $segments);

if (!$job_id || !$filename) json_err('Paramètres manquants', 400);

$pdo  = db();

// Vérifier que le job existe et appartient au client (ou que c'est un admin)
$stmt = $pdo->prepare('SELECT client_id FROM jobs WHERE id = ?');
$stmt->execute([$job_id]);
$job  = $stmt->fetch();
if (!$job) json_err('Job introuvable', 404);
if (!$is_admin && (int)$job['client_id'] !== (int)$user['id']) json_err('Accès refusé', 403);

// Vérifier que le fichier appartient bien à ce job en base (empêche de deviner les noms)
$path = "job_{$job_id}/{$relpath}";
$stmt = $pdo->prepare('SELECT path FROM job_files WHERE path = ? AND job_id = ?');
$stmt->execute([$path, $job_id]);
$file = $stmt->fetch();
if (!$file) json_err('Fichier introuvable', 404);

$abs = UPLOAD_DIR . $file['path'];
if (!is_file($abs)) json_err('Fichier introuvable sur le disque', 404);

// Servir le fichier avec les en-têtes de sécurité appropriés
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($abs);
exit;
