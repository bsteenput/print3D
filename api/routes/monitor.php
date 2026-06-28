<?php
// ============================================================
//  Monitor — Connecteur Chitu V3 (Elegoo Saturn 4 Ultra)
//
//  GET  /api/monitor          → statut temps réel imprimante
//  GET  /api/monitor/probe    → réponse brute (debug)
//  POST /api/monitor/sync/{job_id} → sync données vers un job
// ============================================================
require_admin();

$action = $parts[1] ?? null;   // 'probe' | null | numeric job_id

// ── Récupère l'IP depuis les settings ────────────────────────
function printer_ip(): string {
    $ip = db()->query("SELECT value FROM settings WHERE key_name='printer_ip'")->fetchColumn();
    return $ip ?: '';
}

// ── Requête HTTP vers l'imprimante (timeout court) ───────────
function chitu_get(string $ip, string $path, int $timeout = 4): array {
    $url = "http://{$ip}{$path}";
    $ctx = stream_context_create(['http' => [
        'timeout'        => $timeout,
        'ignore_errors'  => true,
        'method'         => 'GET',
        'header'         => "Accept: application/json\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => "Imprimante injoignable ($url)"];
    $data = json_decode($raw, true);
    return ['ok' => true, 'raw' => $raw, 'json' => $data];
}

// ── Normalise la réponse Chitu V3 ────────────────────────────
//  Le protocole V3 renvoie des champs en CamelCase ou snake_case
//  selon la version firmware — on couvre les deux.
function normalize_chitu(array $r): array {
    // Chitu V3 peut encapsuler dans "Attributes" ou mettre à plat
    $d = $r['Attributes'] ?? $r['data'] ?? $r;

    // Status numérique → label
    $status_map = [
        0 => 'idle', 1 => 'homing', 2 => 'printing',
        3 => 'paused', 4 => 'stopping', 5 => 'complete', 6 => 'error',
    ];

    $raw_status = $d['CurrentStatus'] ?? $d['PrintStatus'] ?? $d['status'] ?? null;
    $status_int = is_numeric($raw_status) ? (int)$raw_status : null;
    $status_str = is_string($raw_status) ? strtolower($raw_status) : ($status_map[$status_int] ?? 'unknown');

    $layer_cur = (int)($d['CurrentLayer']  ?? $d['currentLayer']  ?? $d['current_layer'] ?? 0);
    $layer_tot = (int)($d['TotalLayer']    ?? $d['totalLayer']    ?? $d['total_layer']   ?? 0);
    $progress  = $layer_tot > 0
        ? round(($layer_cur / $layer_tot) * 100, 1)
        : (float)($d['PrintProgress'] ?? $d['progress'] ?? 0);

    $remain_s  = (int)($d['RemainTime']    ?? $d['remainTime']    ?? $d['remaining_time'] ?? 0);
    $elapsed_s = (int)($d['PrintDuration'] ?? $d['printDuration'] ?? $d['elapsed_time']   ?? 0);

    $filename  = $d['FileName'] ?? $d['fileName'] ?? $d['filename'] ?? $d['file'] ?? null;

    return [
        'status'        => $status_str,
        'is_printing'   => $status_str === 'printing',
        'layer_current' => $layer_cur,
        'layer_total'   => $layer_tot,
        'progress_pct'  => $progress,
        'elapsed_sec'   => $elapsed_s,
        'remain_sec'    => $remain_s,
        'filename'      => $filename,
        'raw_status'    => $raw_status,
    ];
}

// ── GET /api/monitor/probe  (debug : réponse brute) ──────────
if ($method === 'GET' && $action === 'probe') {
    $ip = printer_ip();
    if (!$ip) json_err('IP imprimante non configurée (paramètre printer_ip)', 400);

    // Teste plusieurs endpoints candidats Chitu V3
    $candidates = [
        '/api/print/currentsession',
        '/api/print/status',
        '/api/mainboard/status',
        '/status',
        '/',
    ];
    $results = [];
    foreach ($candidates as $path) {
        $r = chitu_get($ip, $path, 3);
        $results[$path] = $r;
        if ($r['ok'] && $r['json'] !== null) break;
    }
    json_ok(['ip' => $ip, 'candidates' => $results]);
}

// ── GET /api/monitor  (statut normalisé) ─────────────────────
if ($method === 'GET' && ($action === null || $action === '')) {
    $ip = printer_ip();
    if (!$ip) json_err('IP imprimante non configurée', 400);

    // Endpoint Chitu V3 — si le premier échoue on tente le suivant
    $endpoints = [
        '/api/print/currentsession',
        '/api/print/status',
        '/api/mainboard/status',
    ];
    $result = null;
    $last_err = '';
    foreach ($endpoints as $ep) {
        $r = chitu_get($ip, $ep);
        if ($r['ok'] && is_array($r['json'])) {
            $result = normalize_chitu($r['json']);
            $result['endpoint_used'] = $ep;
            break;
        }
        $last_err = $r['error'] ?? "Réponse non-JSON sur $ep";
    }
    if (!$result) json_err($last_err ?: 'Impossible de joindre l\'imprimante', 503);
    json_ok($result);
}

// ── POST /api/monitor/sync/{job_id}  (sync → job en DB) ──────
if ($method === 'POST' && is_numeric($action)) {
    $job_id = (int)$action;

    $ip = printer_ip();
    if (!$ip) json_err('IP imprimante non configurée', 400);

    $endpoints = ['/api/print/currentsession', '/api/print/status', '/api/mainboard/status'];
    $data = null;
    foreach ($endpoints as $ep) {
        $r = chitu_get($ip, $ep);
        if ($r['ok'] && is_array($r['json'])) { $data = normalize_chitu($r['json']); break; }
    }
    if (!$data) json_err('Imprimante injoignable', 503);
    if (!$data['is_printing']) json_ok(['synced' => false, 'reason' => 'Imprimante idle']);

    // Récupère le taux horaire du job
    $job = db()->prepare('SELECT hourly_rate, filament_id, grams_used FROM jobs WHERE id=?');
    $job->execute([$job_id]);
    $job = $job->fetch();
    if (!$job) json_err('Job introuvable', 404);

    $hours = $data['elapsed_sec'] > 0 ? round($data['elapsed_sec'] / 3600, 2) : null;

    // Recalcul prix si on a les grammes et les heures
    $price_auto = null;
    if ($hours && $job['filament_id'] && $job['grams_used']) {
        $ppkg = (float)db()->prepare('SELECT price_per_kg FROM filaments WHERE id=?')
                    ->execute([$job['filament_id']]) ?
                db()->query("SELECT price_per_kg FROM filaments WHERE id={$job['filament_id']}")->fetchColumn() : 0;
        $price_auto = calc_price_auto((float)$job['grams_used'], $hours, $ppkg, (float)$job['hourly_rate']);
    }

    $stmt = db()->prepare(
        'UPDATE jobs SET
            layer_current = ?,
            layer_total   = ?,
            print_hours   = COALESCE(?, print_hours),
            eta           = CASE WHEN ? > 0 THEN DATE_ADD(NOW(), INTERVAL ? SECOND) ELSE eta END,
            price_auto    = COALESCE(?, price_auto),
            price_final   = CASE WHEN price_adjusted = 0 THEN COALESCE(?, price_final) ELSE price_final END
         WHERE id = ?'
    );
    $stmt->execute([
        $data['layer_current'] ?: null,
        $data['layer_total']   ?: null,
        $hours,
        $data['remain_sec'], $data['remain_sec'],
        $price_auto,
        $price_auto,
        $job_id,
    ]);

    json_ok(['synced' => true, 'data' => $data]);
}

json_err('Route monitor introuvable', 404);
