<?php
// Routes : /api/settings
require_admin();

// ── GET /api/settings ─────────────────────────────────────────
if ($method === 'GET') {
    $rows = db()->query('SELECT key_name, value FROM settings')->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['key_name']] = $r['value'];
    json_ok($out);
}

// ── POST /api/settings  (body: {"key":"value", ...}) ──────────
if ($method === 'POST') {
    $b = body();
    $allowed = ['hourly_rate', 'app_name', 'contact_email', 'notify_on_status'];
    $stmt    = db()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    foreach ($b as $k => $v) {
        if (!in_array($k, $allowed)) continue;
        $stmt->execute([$k, (string)$v]);
    }
    json_ok(['saved' => true]);
}

json_err('Méthode non supportée', 405);
