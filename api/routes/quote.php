<?php
// Routes : /api/quote/materials (GET) + /api/quote (POST)
// Accès public — portail de demande de devis (/devis)

$action = $parts[1] ?? null;
$pdo = db();

// ── GET /api/quote/materials ─────────────────────────────────
//  Liste des matériaux actifs, champs limités (pas de prix ni stock).
if ($method === 'GET' && $action === 'materials') {
    $rows = $pdo->query(
        'SELECT id, material, print_type, color, color_hex
         FROM filaments WHERE active = 1
         ORDER BY print_type, material, color'
    )->fetchAll();
    json_ok($rows);
}

// ── POST /api/quote ──────────────────────────────────────────
//  Formulaire multipart : name, email, title, description, quantity,
//  filament_id (optionnel), website (honeypot), fichiers stl[].
if ($method === 'POST' && $action === null) {

    // Honeypot : les bots remplissent le champ caché — on répond
    // "succès" sans rien créer pour ne pas leur donner d'indice.
    if (!empty($_POST['website'])) {
        json_ok(['message' => 'Demande reçue']);
    }

    // IP réelle même derrière Traefik/proxy
    $ip = trim(explode(',', $_SERVER['HTTP_X_REAL_IP']
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0')[0]);

    // Rate limiting : max 5 demandes par IP par heure
    try {
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM quote_attempts
             WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $chk->execute([$ip]);
        if ((int)$chk->fetchColumn() >= 5) {
            json_err('Trop de demandes envoyées. Réessaie dans une heure.', 429);
        }
    } catch (PDOException $e) { /* table absente — continue sans rate limit */ }

    // Multipart = octets bruts : on force de l'UTF-8 valide (les séquences
    // invalides sont remplacées) pour ne pas faire échouer l'INSERT utf8mb4.
    $scrub = fn(string $s): string => mb_convert_encoding($s, 'UTF-8', 'UTF-8');

    $name  = mb_substr(trim($scrub($_POST['name'] ?? '')), 0, 100);
    $email = mb_substr(trim($scrub($_POST['email'] ?? '')), 0, 180);
    $title = mb_substr(trim($scrub($_POST['title'] ?? '')), 0, 200);
    $desc  = trim($scrub($_POST['description'] ?? '')) ?: null;
    $qty   = min(999, max(1, (int)($_POST['quantity'] ?? 1)));

    if (!$name)  json_err('Nom requis');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Email invalide');
    if (!$title) json_err('Décris ce que tu veux faire imprimer');

    if (!empty($_FILES['stl']['name']) && is_array($_FILES['stl']['name'])
        && count($_FILES['stl']['name']) > 20) {
        json_err('Maximum 20 fichiers par demande');
    }

    // Matériau souhaité (optionnel) — snapshot du prix comme pour un job normal
    $fil_id = !empty($_POST['filament_id']) ? (int)$_POST['filament_id'] : null;
    $print_type = 'fdm';
    $material_price = null;
    if ($fil_id) {
        $stmt = $pdo->prepare('SELECT print_type, price_per_kg, price_per_litre FROM filaments WHERE id = ? AND active = 1');
        $stmt->execute([$fil_id]);
        $fil = $stmt->fetch();
        if (!$fil) { $fil_id = null; }
        else {
            $print_type = $fil['print_type'] === 'resin' ? 'resin' : 'fdm';
            $price = $print_type === 'resin' ? $fil['price_per_litre'] : $fil['price_per_kg'];
            $material_price = $price !== null ? (float)$price : null;
        }
    }

    // Client : réutilisé si l'email existe déjà, créé sinon (sans mot de passe)
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $client_id = $stmt->fetchColumn();
    if (!$client_id) {
        $pdo->prepare("INSERT INTO users (name, email, role) VALUES (?, ?, 'client')")
            ->execute([$name, $email]);
        $client_id = (int)$pdo->lastInsertId();
    }

    $hourly = (float)($pdo->query("SELECT value FROM settings WHERE key_name='hourly_rate'")->fetchColumn() ?? 0.80);
    $ref    = next_job_ref();
    $token  = generate_tracking_token();

    $stmt = $pdo->prepare(
        'INSERT INTO jobs (ref, client_id, filament_id, title, description, quantity,
                           print_type, status, hourly_rate, material_price, tracking_token, queue_order)
         VALUES (?,?,?,?,?,?,?,\'quote\',?,?,?,
                 (SELECT COALESCE(MAX(queue_order),0)+1 FROM jobs j2))'
    );
    $stmt->execute([$ref, $client_id, $fil_id, $title, $desc, $qty, $print_type, $hourly, $material_price, $token]);
    $job_id = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO job_events (job_id, status, message) VALUES (?,?,?)')
        ->execute([$job_id, 'quote', 'Demande de devis reçue via le portail']);

    $saved = handle_stl_upload($job_id);

    try {
        $pdo->prepare('INSERT INTO quote_attempts (ip) VALUES (?)')->execute([$ip]);
        // Nettoyage périodique des vieilles entrées (~5% des requêtes)
        if (rand(1, 20) === 1) {
            $pdo->exec("DELETE FROM quote_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        }
    } catch (PDOException $e) { /* table absente */ }

    $track_url = base_url() . '/track/' . $token;
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";

    // Email à l'admin
    $admin_email = $pdo->query("SELECT value FROM settings WHERE key_name='contact_email'")->fetchColumn();
    if ($admin_email) {
        $admin_body = "Nouvelle demande de devis via le portail :\n\n"
                    . "  Réf      : {$ref}\n"
                    . "  De       : {$name} <{$email}>\n"
                    . "  Titre    : {$title}\n"
                    . "  Quantité : {$qty}\n"
                    . "  Fichiers : " . count($saved) . "\n\n"
                    . ($desc ? "Message :\n{$desc}\n\n" : '')
                    . "Ouvrir : " . base_url() . "/#jobs/{$job_id}\n";
        @mail($admin_email, "[Print3D] Nouvelle demande de devis — {$ref}", $admin_body, $headers);
    }

    // Email de confirmation au demandeur (avec lien de suivi)
    $client_body = "Bonjour {$name},\n\n"
                 . "Ta demande de devis « {$title} » ({$ref}) a bien été reçue.\n"
                 . "Je reviens vers toi rapidement avec un prix.\n\n"
                 . "Suis l'avancement ici : {$track_url}\n\n"
                 . "— " . MAIL_FROM_NAME;
    @mail($email, "[Print3D] Demande de devis reçue — {$ref}", $client_body, $headers);

    json_ok([
        'ref'            => $ref,
        'tracking_token' => $token,
        'tracking_url'   => $track_url,
        'files'          => count($saved),
    ], 201);
}

json_err('Méthode non supportée', 405);
