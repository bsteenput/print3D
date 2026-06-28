<?php
// Routes : /api/filaments[/{id}]
$user = require_auth();

// ── GET /api/filaments ────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    $rows = db()->query('SELECT * FROM filaments ORDER BY material, color')->fetchAll();
    json_ok($rows);
}

// ── GET /api/filaments/{id} ───────────────────────────────────
if ($method === 'GET' && $id !== null) {
    $stmt = db()->prepare('SELECT * FROM filaments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_err('Filament introuvable', 404);
    json_ok($row);
}

// Admin only below
if ($user['role'] !== 'admin') json_err('Accès refusé', 403);

// ── POST /api/filaments ───────────────────────────────────────
if ($method === 'POST' && $id === null) {
    $b = body();
    $print_type = ($b['print_type'] ?? '') === 'resin' ? 'resin' : 'fdm';
    if (empty($b['material']) || empty($b['color'])) json_err('Champs requis : material, color');
    if ($print_type === 'resin' && empty($b['price_per_litre'])) json_err('Champ requis : price_per_litre');
    if ($print_type === 'fdm'   && empty($b['price_per_kg']))    json_err('Champ requis : price_per_kg');
    $stmt = db()->prepare(
        'INSERT INTO filaments (material, print_type, color, color_hex, brand, price_per_kg, price_per_litre, stock_grams, active)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $b['material'], $print_type, $b['color'], $b['color_hex'] ?? null, $b['brand'] ?? null,
        $print_type === 'fdm'   ? (float)$b['price_per_kg']    : null,
        $print_type === 'resin' ? (float)$b['price_per_litre'] : null,
        (int)($b['stock_grams'] ?? 0), (int)($b['active'] ?? 1),
    ]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

// ── PUT /api/filaments/{id} ───────────────────────────────────
if ($method === 'PUT' && $id !== null) {
    $b = body();
    $print_type = ($b['print_type'] ?? '') === 'resin' ? 'resin' : 'fdm';
    $stmt = db()->prepare(
        'UPDATE filaments SET material=?, print_type=?, color=?, color_hex=?, brand=?,
         price_per_kg=?, price_per_litre=?, stock_grams=?, active=? WHERE id=?'
    );
    $stmt->execute([
        $b['material'] ?? '', $print_type, $b['color'] ?? '', $b['color_hex'] ?? null, $b['brand'] ?? null,
        $print_type === 'fdm'   ? (float)($b['price_per_kg']    ?? 0) : null,
        $print_type === 'resin' ? (float)($b['price_per_litre'] ?? 0) : null,
        (int)($b['stock_grams'] ?? 0), (int)($b['active'] ?? 1), $id,
    ]);
    json_ok(['updated' => true]);
}

// ── DELETE /api/filaments/{id} ────────────────────────────────
if ($method === 'DELETE' && $id !== null) {
    db()->prepare('DELETE FROM filaments WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
