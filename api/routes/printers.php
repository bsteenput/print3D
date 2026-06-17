<?php
// Routes : /api/printers[/{id}]
require_admin();

// ── GET /api/printers ─────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    json_ok(db()->query('SELECT * FROM printers ORDER BY name')->fetchAll());
}

// ── GET /api/printers/{id} ────────────────────────────────────
if ($method === 'GET' && $id !== null) {
    $stmt = db()->prepare('SELECT * FROM printers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_err('Imprimante introuvable', 404);
    json_ok($row);
}

// ── POST /api/printers ────────────────────────────────────────
if ($method === 'POST' && $id === null) {
    $b    = body();
    $name = trim($b['name'] ?? '');
    if (!$name) json_err('Nom requis');
    $stmt = db()->prepare('INSERT INTO printers (name, active, notes) VALUES (?,?,?)');
    $stmt->execute([$name, (int)($b['active'] ?? 1), $b['notes'] ?? null]);
    json_ok(['id' => (int)db()->lastInsertId()], 201);
}

// ── PUT /api/printers/{id} ────────────────────────────────────
if ($method === 'PUT' && $id !== null) {
    $b    = body();
    $name = trim($b['name'] ?? '');
    if (!$name) json_err('Nom requis');
    $stmt = db()->prepare('UPDATE printers SET name=?, active=?, notes=? WHERE id=?');
    $stmt->execute([$name, (int)($b['active'] ?? 1), $b['notes'] ?? null, $id]);
    json_ok(['updated' => true]);
}

// ── DELETE /api/printers/{id} ─────────────────────────────────
if ($method === 'DELETE' && $id !== null) {
    db()->prepare('DELETE FROM printers WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
