<?php
// Routes : /api/auth/login  /api/auth/me  /api/auth/register
$action = $parts[1] ?? '';

// ── POST /api/auth/login ──────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $b = body();
    $email    = trim($b['email'] ?? '');
    $password = $b['password'] ?? '';
    if (!$email || !$password) json_err('Email et mot de passe requis');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        json_err('Identifiants incorrects', 401);
    }

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

    $token = jwt_encode([
        'sub'  => $user['id'],
        'role' => $user['role'],
        'exp'  => time() + JWT_EXPIRY,
    ]);

    json_ok([
        'token' => $token,
        'user'  => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']],
    ]);
}

// ── GET /api/auth/me ─────────────────────────────────────────
if ($action === 'me' && $method === 'GET') {
    json_ok(require_auth());
}

// ── POST /api/auth/register ───────────────────────────────────
if ($action === 'register' && $method === 'POST') {
    require_admin();
    $b    = body();
    $name  = trim($b['name'] ?? '');
    $email = trim($b['email'] ?? '');
    $pass  = $b['password'] ?? '';
    $role  = in_array($b['role'] ?? '', ['admin', 'client']) ? $b['role'] : 'client';
    if (!$name || !$email || !$pass) json_err('Nom, email et mot de passe requis');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Email invalide');
    if (strlen($pass) < 8) json_err('Mot de passe trop court (8 car. min.)');

    try {
        $stmt = db()->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
        $stmt->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $role]);
        json_ok(['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_err('Email déjà utilisé', 409);
        throw $e;
    }
}

json_err('Route auth introuvable', 404);
