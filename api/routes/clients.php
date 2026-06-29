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
    $b     = body();
    $name  = trim($b['name'] ?? '');
    $email = array_key_exists('email', $b) ? (trim($b['email']) ?: null) : false;
    if (!$name) json_err('Nom requis');

    $fields = ['name = ?'];
    $params = [$name];

    if ($email !== false) {
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Email invalide');
        $fields[] = 'email = ?';
        $params[]  = $email;
    }

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
if ($method === 'DELETE' && $id !== null && $sub === null) {
    db()->prepare('DELETE FROM users WHERE id = ? AND role = \'client\'')->execute([$id]);
    json_ok(['deleted' => true]);
}

// ── POST /api/clients/{id}/reset-token ───────────────────────
if ($method === 'POST' && $id !== null && $sub === 'reset-token') {
    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) json_err('Utilisateur introuvable', 404);

    $token   = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    db()->prepare('UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?')
        ->execute([$token, $expires, $id]);

    json_ok([
        'token' => $token,
        'link'  => APP_URL . '/reset/' . $token,
        'name'  => $user['name'],
        'email' => $user['email'],
    ]);
}

json_err('Méthode non supportée', 405);
