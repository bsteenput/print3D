<?php
// Routes : /api/jobs[/{id}[/status|files]]
$user = require_auth();
$is_admin = $user['role'] === 'admin';
$pdo = db();

$STATUSES = ['draft','queued','printing','done','picked_up','cancelled'];

// ── GET /api/jobs ─────────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    $where  = $is_admin ? '' : 'WHERE j.client_id = ' . (int)$user['id'];
    $status = $_GET['status'] ?? '';
    if ($status && in_array($status, $STATUSES)) {
        $where = $where ? "$where AND j.status = " . $pdo->quote($status)
                        : "WHERE j.status = " . $pdo->quote($status);
    }
    $rows = $pdo->query(
        "SELECT j.id, j.ref, j.title, j.status, j.quantity, j.grams_used, j.print_hours,
                j.price_final, j.eta, j.created_at, j.updated_at, j.queue_order,
                u.name AS client_name, p.name AS printer_name,
                f.material AS filament_material, f.color AS filament_color, f.color_hex
         FROM jobs j
         LEFT JOIN users u     ON u.id = j.client_id
         LEFT JOIN printers p  ON p.id = j.printer_id
         LEFT JOIN filaments f ON f.id = j.filament_id
         $where
         ORDER BY j.queue_order, j.created_at DESC"
    )->fetchAll();
    json_ok($rows);
}

// ── GET /api/jobs/{id} ────────────────────────────────────────
if ($method === 'GET' && $id !== null && $sub === null) {
    $stmt = $pdo->prepare(
        "SELECT j.*, u.name AS client_name, u.email AS client_email,
                p.name AS printer_name,
                f.material AS filament_material, f.color AS filament_color,
                f.color_hex, f.price_per_kg
         FROM jobs j
         LEFT JOIN users u     ON u.id = j.client_id
         LEFT JOIN printers p  ON p.id = j.printer_id
         LEFT JOIN filaments f ON f.id = j.filament_id
         WHERE j.id = ?"
    );
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) json_err('Job introuvable', 404);
    if (!$is_admin && (int)$job['client_id'] !== (int)$user['id']) json_err('Accès refusé', 403);

    // hide admin notes from clients
    if (!$is_admin) unset($job['notes_admin']);

    $files = $pdo->prepare('SELECT id, filename, path, size_bytes, uploaded_at FROM job_files WHERE job_id = ?');
    $files->execute([$id]);
    $job['files'] = array_map(function ($f) {
        $f['url'] = UPLOAD_URL . $f['path'];
        return $f;
    }, $files->fetchAll());

    $events = $pdo->prepare('SELECT status, message, created_at FROM job_events WHERE job_id = ? ORDER BY created_at');
    $events->execute([$id]);
    $job['events'] = $events->fetchAll();

    json_ok($job);
}

// ── POST /api/jobs ────────────────────────────────────────────
if ($method === 'POST' && $id === null && $sub === null) {
    $b = body();
    if (empty($b['title'])) json_err('Titre requis');

    $client_id = $is_admin ? (int)($b['client_id'] ?? $user['id']) : (int)$user['id'];
    $hourly    = (float)($pdo->query("SELECT value FROM settings WHERE key_name='hourly_rate'")->fetchColumn() ?? 0.80);
    $ref       = next_job_ref();

    $stmt = $pdo->prepare(
        'INSERT INTO jobs (ref, client_id, printer_id, filament_id, title, description,
                           quantity, status, hourly_rate, notes_admin, queue_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,
                 (SELECT COALESCE(MAX(queue_order),0)+1 FROM jobs j2))'
    );
    $stmt->execute([
        $ref, $client_id,
        !empty($b['printer_id'])  ? (int)$b['printer_id']  : null,
        !empty($b['filament_id']) ? (int)$b['filament_id'] : null,
        $b['title'],
        $b['description'] ?? null,
        (int)($b['quantity'] ?? 1),
        $is_admin ? ($b['status'] ?? 'queued') : 'queued',
        $hourly,
        $is_admin ? ($b['notes_admin'] ?? null) : null,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO job_events (job_id, status, message) VALUES (?,?,?)')
        ->execute([$new_id, 'queued', 'Job créé']);

    json_ok(['id' => $new_id, 'ref' => $ref], 201);
}

// ── PUT /api/jobs/{id} ────────────────────────────────────────
if ($method === 'PUT' && $id !== null && $sub === null) {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b = body();

    $stmt = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) json_err('Job introuvable', 404);

    // Recalcul prix auto si filament/heures fournis
    $price_auto = $job['price_auto'];
    $grams  = isset($b['grams_used'])  ? (float)$b['grams_used']  : (float)$job['grams_used'];
    $hours  = isset($b['print_hours']) ? (float)$b['print_hours'] : (float)$job['print_hours'];
    if ($grams > 0 && $hours > 0) {
        $fil_id = (int)($b['filament_id'] ?? $job['filament_id']);
        if ($fil_id) {
            $ppkg = (float)$pdo->prepare('SELECT price_per_kg FROM filaments WHERE id=?')
                        ->execute([$fil_id]) ? $pdo->query("SELECT price_per_kg FROM filaments WHERE id=$fil_id")->fetchColumn() : 0;
            $price_auto = calc_price_auto($grams, $hours, (float)$ppkg, (float)$job['hourly_rate']);
        }
    }

    $price_adjusted = isset($b['price_final']) && (float)$b['price_final'] !== $price_auto ? 1 : $job['price_adjusted'];
    $price_final    = isset($b['price_final']) ? (float)$b['price_final'] : ($price_auto ?? $job['price_final']);

    $stmt = $pdo->prepare(
        'UPDATE jobs SET
            title=?, description=?, quantity=?, printer_id=?, filament_id=?,
            grams_used=?, print_hours=?, layer_current=?, layer_total=?,
            eta=?, started_at=?, finished_at=?, picked_up_at=?,
            price_auto=?, price_final=?, price_adjusted=?,
            notes_admin=?, queue_order=?
         WHERE id=?'
    );
    $stmt->execute([
        $b['title']         ?? $job['title'],
        $b['description']   ?? $job['description'],
        (int)($b['quantity']      ?? $job['quantity']),
        !empty($b['printer_id'])  ? (int)$b['printer_id']  : $job['printer_id'],
        !empty($b['filament_id']) ? (int)$b['filament_id'] : $job['filament_id'],
        $b['grams_used']    ?? $job['grams_used'],
        $b['print_hours']   ?? $job['print_hours'],
        $b['layer_current'] ?? $job['layer_current'],
        $b['layer_total']   ?? $job['layer_total'],
        $b['eta']           ?? $job['eta'],
        $b['started_at']    ?? $job['started_at'],
        $b['finished_at']   ?? $job['finished_at'],
        $b['picked_up_at']  ?? $job['picked_up_at'],
        $price_auto,
        $price_final,
        $price_adjusted,
        $b['notes_admin']   ?? $job['notes_admin'],
        (int)($b['queue_order'] ?? $job['queue_order']),
        $id,
    ]);
    json_ok(['updated' => true]);
}

// ── PATCH /api/jobs/{id}/status ───────────────────────────────
if ($method === 'PATCH' && $id !== null && $sub === 'status') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b      = body();
    $status = $b['status'] ?? '';
    if (!in_array($status, $STATUSES)) json_err('Statut invalide');

    $timestamps = [];
    if ($status === 'printing')  $timestamps = ['started_at = NOW()'];
    if ($status === 'done')      $timestamps = ['finished_at = NOW()'];
    if ($status === 'picked_up') $timestamps = ['picked_up_at = NOW()'];

    $set = array_merge(['status = ?'], $timestamps);
    $pdo->prepare('UPDATE jobs SET ' . implode(', ', $set) . ' WHERE id = ?')
        ->execute([$status, $id]);

    $msg = $b['message'] ?? null;
    $pdo->prepare('INSERT INTO job_events (job_id, status, message) VALUES (?,?,?)')
        ->execute([$id, $status, $msg]);

    notify_client_status($id, $status);
    json_ok(['status' => $status]);
}

// ── POST /api/jobs/{id}/files ─────────────────────────────────
if ($method === 'POST' && $id !== null && $sub === 'files') {
    $stmt = $pdo->prepare('SELECT client_id FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) json_err('Job introuvable', 404);
    if (!$is_admin && (int)$job['client_id'] !== (int)$user['id']) json_err('Accès refusé', 403);

    $saved = handle_stl_upload($id);
    json_ok($saved, 201);
}

// ── DELETE /api/jobs/{id}/files  (query ?file_id=N) ──────────
if ($method === 'DELETE' && $id !== null && $sub === 'files') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $file_id = (int)($_GET['file_id'] ?? 0);
    if (!$file_id) json_err('file_id requis');

    $row = $pdo->prepare('SELECT path FROM job_files WHERE id = ? AND job_id = ?');
    $row->execute([$file_id, $id]);
    $f = $row->fetch();
    if (!$f) json_err('Fichier introuvable', 404);

    @unlink(UPLOAD_DIR . $f['path']);
    $pdo->prepare('DELETE FROM job_files WHERE id = ?')->execute([$file_id]);
    json_ok(['deleted' => true]);
}

// ── DELETE /api/jobs/{id} ─────────────────────────────────────
if ($method === 'DELETE' && $id !== null && $sub === null) {
    if (!$is_admin) json_err('Accès refusé', 403);
    $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
