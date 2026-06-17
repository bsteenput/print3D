<?php
// Routes : /api/clients[/{id}]
require_admin();

// ── GET /api/clients ──────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    $rows = db()->query(
        'SELECT id, name, email, role, created_at, last_login FROM users ORDER BY name'
    )->fetchAll();
    json_ok($rows);
}

// ── GET /api/clients/{id} ─────────────────────────────────────
if ($method === 'GET' && $id !== null) {
    $stmt = db()->prepare(
        'SELECT id, name, email, role, created_at, last_login FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_err('Client introuvable', 404);

    $jobs = db()->prepare(
        'SELECT id, ref, title, status, created_at FROM jobs WHERE client_id = ? ORDER BY created_at DESC'
    );
    $jobs->execute([$id]);
    $row['jobs'] = $jobs->fetchAll();
    json_ok($row);
}

// ── PUT /api/clients/{id} ─────────────────────────────────────
if ($method === 'PUT' && $id !== null) {
    $b    = body();
    $name = trim($b['name'] ?? '');
    $email = trim($b['email'] ?? '');
    if (!$name || !$email) json_err('Nom et email requis');

    $fields = ['name = ?', 'email = ?'];
    $params = [$name, $email];

    if (!empty($b['password'])) {
        if (strlen($b['password']) < 8) json_err('Mot de passe trop court');
        $fields[] = 'password = ?';
        $params[]  = password_hash($b['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    }
    $params[] = $id;

    try {
        db()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_err('Email déjà utilisé', 409);
        throw $e;
    }
    json_ok(['updated' => true]);
}

// ── DELETE /api/clients/{id} ──────────────────────────────────
if ($method === 'DELETE' && $id !== null) {
    db()->prepare('DELETE FROM users WHERE id = ? AND role = \'client\'')->execute([$id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
