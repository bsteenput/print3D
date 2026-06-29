<?php
// Route : GET /api/stats
require_admin();
$pdo = db();

// CA et heures par mois (12 derniers mois)
$monthly = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
            COUNT(*) AS jobs_count,
            COALESCE(SUM(CASE WHEN status IN ('done','picked_up') THEN price_final ELSE 0 END), 0) AS ca,
            COALESCE(SUM(print_hours), 0) AS hours
     FROM jobs
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY month ASC"
)->fetchAll();

// Consommation par matériau (top 10)
$materials_raw = $pdo->query(
    "SELECT f.material, f.print_type, f.color, f.color_hex,
            COUNT(j.id) AS job_count,
            COALESCE(SUM(CASE WHEN j.print_type='fdm'   THEN j.grams_used ELSE 0 END), 0) AS total_grams,
            COALESCE(SUM(CASE WHEN j.print_type='resin' THEN j.ml_used    ELSE 0 END), 0) AS total_ml
     FROM filaments f
     LEFT JOIN jobs j ON j.filament_id = f.id AND j.status IN ('done','picked_up')
     GROUP BY f.id
     ORDER BY job_count DESC
     LIMIT 10"
)->fetchAll();

// Ajouter total_qty (grammes ou ml selon le type)
$materials = array_map(function ($m) {
    $m['total_qty'] = $m['print_type'] === 'resin' ? (float)$m['total_ml'] : (float)$m['total_grams'];
    return $m;
}, $materials_raw);

// Répartition par statut global
$by_status = $pdo->query(
    'SELECT status, COUNT(*) AS n FROM jobs GROUP BY status'
)->fetchAll();

// Résumé paiements
$pay = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN paid=1 THEN 1 ELSE 0 END), 0) AS paid_count,
        COALESCE(SUM(CASE WHEN paid=0 THEN 1 ELSE 0 END), 0) AS unpaid_count,
        COALESCE(SUM(CASE WHEN paid=1 THEN price_final ELSE 0 END), 0) AS total_paid_amount,
        COALESCE(SUM(CASE WHEN paid=0 THEN price_final ELSE 0 END), 0) AS unpaid_amount
     FROM jobs
     WHERE status IN ('done','picked_up') AND price_final > 0"
)->fetch();

// Top clients (par CA)
$top_clients = $pdo->query(
    "SELECT u.name,
            COUNT(j.id) AS job_count,
            COALESCE(SUM(j.price_final), 0) AS revenue
     FROM users u
     LEFT JOIN jobs j ON j.client_id = u.id AND j.status IN ('done','picked_up')
     GROUP BY u.id
     ORDER BY revenue DESC
     LIMIT 5"
)->fetchAll();

json_ok([
    'monthly'     => $monthly,
    'materials'   => $materials,
    'by_status'   => $by_status,
    'payments'    => $pay,
    'top_clients' => $top_clients,
]);
