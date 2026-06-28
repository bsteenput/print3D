<?php
// Route : GET /api/dashboard
require_admin();

$pdo = db();

$jobs_by_status = $pdo->query(
    'SELECT status, COUNT(*) AS n FROM jobs GROUP BY status'
)->fetchAll();
$status_map = [];
foreach ($jobs_by_status as $r) $status_map[$r['status']] = (int)$r['n'];

$revenue = $pdo->query(
    "SELECT COALESCE(SUM(price_final),0) AS total FROM jobs WHERE status IN ('done','picked_up')"
)->fetchColumn();

$active_jobs = $pdo->query(
    "SELECT j.id, j.ref, j.title, j.status, j.eta, j.layer_current, j.layer_total,
            u.name AS client_name, p.name AS printer_name
     FROM jobs j
     LEFT JOIN users u ON u.id = j.client_id
     LEFT JOIN printers p ON p.id = j.printer_id
     WHERE j.status IN ('queued','printing')
     ORDER BY j.queue_order, j.created_at
     LIMIT 10"
)->fetchAll();

$recent_jobs = $pdo->query(
    "SELECT j.id, j.ref, j.title, j.status, j.price_final, j.created_at, u.name AS client_name
     FROM jobs j LEFT JOIN users u ON u.id = j.client_id
     ORDER BY j.created_at DESC LIMIT 8"
)->fetchAll();

$low_stock = $pdo->query(
    "SELECT id, material, print_type, color, color_hex, stock_grams FROM filaments
     WHERE stock_grams < 200 AND active = 1 ORDER BY stock_grams"
)->fetchAll();

json_ok([
    'counts' => [
        'queued'    => $status_map['queued']    ?? 0,
        'printing'  => $status_map['printing']  ?? 0,
        'done'      => $status_map['done']       ?? 0,
        'total'     => array_sum($status_map),
    ],
    'revenue'      => (float)$revenue,
    'active_jobs'  => $active_jobs,
    'recent_jobs'  => $recent_jobs,
    'low_stock'    => $low_stock,
]);
