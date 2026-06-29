<?php
// Routes : /api/auth/login  /api/auth/me  /api/auth/register
$action = $parts[1] ?? '';

// ── POST /api/auth/login ──────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $b = body();
    $email    = trim($b['email'] ?? '');
    $password = $b['password'] ?? '';
    if (!$email || !$password) json_err('Email et mot de passe requis');

    // IP réelle même derrière Traefik/proxy
    $ip = trim(explode(',', $_SERVER['HTTP_X_REAL_IP']
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0')[0]);

    $pdo = db();

    // Rate limiting : max 10 tentatives par IP sur 15 minutes
    try {
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $chk->execute([$ip]);
        if ((int)$chk->fetchColumn() >= 10) {
            json_err('Trop de tentatives de connexion. Réessayez dans 15 minutes.', 429);
        }
    } catch (PDOException $e) { /* table absente — continue sans rate limit */ }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Enregistrer la tentative échouée
        try {
            $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
            // Nettoyage périodique des vieilles tentatives (~5% des requêtes)
            if (rand(1, 20) === 1) {
                $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
            }
        } catch (PDOException $e) { /* table absente */ }
        json_err('Identifiants incorrects', 401);
    }

    // Succès : effacer les tentatives de cette IP
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    } catch (PDOException $e) { /* table absente */ }

    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

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
    $b     = body();
    $name  = trim($b['name'] ?? '');
    $email = trim($b['email'] ?? '') ?: null;
    $pass  = ($b['password'] ?? '') !== '' ? $b['password'] : null;
    $role  = in_array($b['role'] ?? '', ['admin', 'client']) ? $b['role'] : 'client';
    if (!$name) json_err('Nom requis');
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Email invalide');
    if ($pass  !== null && strlen($pass) < 8) json_err('Mot de passe trop court (8 car. min.)');

    $hash = $pass !== null ? password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]) : null;
    try {
        $stmt = db()->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
        $stmt->execute([$name, $email, $hash, $role]);
        json_ok(['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_err('Email déjà utilisé', 409);
        throw $e;
    }
}

// ── GET /api/auth/reset?token=xxx — valider le token ─────────
if ($action === 'reset' && $method === 'GET') {
    $token = preg_replace('/[^a-fA-F0-9]/', '', $_GET['token'] ?? '');
    if (!$token) json_err('Token manquant');

    $stmt = db()->prepare(
        'SELECT id, name, email FROM users
         WHERE reset_token = ? AND reset_token_expires_at > NOW()'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) json_err('Lien invalide ou expiré', 404);

    json_ok(['name' => $user['name'], 'email' => $user['email']]);
}

// ── POST /api/auth/reset — appliquer le nouveau mot de passe ─
if ($action === 'reset' && $method === 'POST') {
    $b      = body();
    $token  = preg_replace('/[^a-fA-F0-9]/', '', $b['token'] ?? '');
    $pass   = $b['password'] ?? '';
    if (!$token) json_err('Token manquant');
    if (strlen($pass) < 6) json_err('Mot de passe trop court (6 car. min.)');

    $stmt = db()->prepare(
        'SELECT id, name FROM users
         WHERE reset_token = ? AND reset_token_expires_at > NOW()'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) json_err('Lien invalide ou expiré', 404);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare(
        'UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?'
    )->execute([$hash, $user['id']]);

    json_ok(['message' => 'Mot de passe mis à jour']);
}

json_err('Route auth introuvable', 404);
