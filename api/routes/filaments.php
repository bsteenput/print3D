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
    foreach (['material', 'color', 'price_per_kg'] as $f) {
        if (empty($b[$f])) json_err("Champ requis : $f");
    }
    $stmt = db()->prepare(
        'INSERT INTO filaments (material, color, color_hex, brand, price_per_kg, stock_grams, active)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $b['material'], $b['color'], $b['color_hex'] ?? null, $b['brand'] ?? null,
        (float)$b['price_per_kg'], (int)($b['stock_grams'] ?? 0), (int)($b['active'] ?? 1),
    ]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

// ── PUT /api/filaments/{id} ───────────────────────────────────
if ($method === 'PUT' && $id !== null) {
    $b = body();
    $stmt = db()->prepare(
        'UPDATE filaments SET material=?, color=?, color_hex=?, brand=?,
         price_per_kg=?, stock_grams=?, active=? WHERE id=?'
    );
    $stmt->execute([
        $b['material'] ?? '', $b['color'] ?? '', $b['color_hex'] ?? null, $b['brand'] ?? null,
        (float)($b['price_per_kg'] ?? 0), (int)($b['stock_grams'] ?? 0),
        (int)($b['active'] ?? 1), $id,
    ]);
    json_ok(['updated' => true]);
}

// ── DELETE /api/filaments/{id} ────────────────────────────────
if ($method === 'DELETE' && $id !== null) {
    db()->prepare('DELETE FROM filaments WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
