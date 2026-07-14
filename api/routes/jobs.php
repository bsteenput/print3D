<?php
// Routes : /api/jobs[/{id}[/status|files]]
$user = require_auth();
$is_admin = $user['role'] === 'admin';
$pdo = db();

$STATUSES = ['draft','queued','printing','done','picked_up','cancelled'];

// ── GET /api/jobs/queue ──────────────────────────────────────
//  File d'attente groupée par imprimante, avec estimation de
//  début/fin cumulée à partir de la durée (print_hours) des jobs.
if ($method === 'GET' && ($parts[1] ?? null) === 'queue') {
    require_admin();

    $printers = $pdo->query('SELECT id, name FROM printers WHERE active = 1 ORDER BY name')->fetchAll();

    $jobs = $pdo->query(
        "SELECT id, ref, title, status, printer_id, print_hours, started_at, queue_order
         FROM jobs
         WHERE status IN ('queued','printing')
         ORDER BY printer_id IS NULL, printer_id, queue_order, created_at"
    )->fetchAll();

    $by_printer = [];
    $unassigned = [];
    foreach ($jobs as $j) {
        if ($j['printer_id']) $by_printer[$j['printer_id']][] = $j;
        else $unassigned[] = $j;
    }

    $now = new DateTime();
    $result = [];
    foreach ($printers as $p) {
        $cursor = clone $now;
        $out = [];
        foreach (($by_printer[$p['id']] ?? []) as $j) {
            $hours = $j['print_hours'] !== null ? (float)$j['print_hours'] : null;

            if ($j['status'] === 'printing' && $j['started_at'] && $hours !== null) {
                $started        = new DateTime($j['started_at']);
                $elapsed_hours  = ($now->getTimestamp() - $started->getTimestamp()) / 3600;
                $remaining      = max(0, $hours - $elapsed_hours);
                $start          = clone $now;
                $end            = (clone $now)->modify('+' . round($remaining * 3600) . ' seconds');
                $cursor         = clone $end;
            } elseif ($hours !== null) {
                $start  = clone $cursor;
                $end    = (clone $cursor)->modify('+' . round($hours * 3600) . ' seconds');
                $cursor = clone $end;
            } else {
                $start = clone $cursor;
                $end   = null; // durée inconnue, impossible d'estimer plus loin
            }

            $out[] = [
                'id' => (int)$j['id'], 'ref' => $j['ref'], 'title' => $j['title'],
                'status' => $j['status'], 'print_hours' => $j['print_hours'],
                'queue_order' => (int)$j['queue_order'],
                'estimated_start'      => $start->format('Y-m-d H:i:s'),
                'estimated_completion' => $end ? $end->format('Y-m-d H:i:s') : null,
            ];
        }
        $result[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'jobs' => $out];
    }

    json_ok([
        'printers'   => $result,
        'unassigned' => array_map(fn($j) => [
            'id' => (int)$j['id'], 'ref' => $j['ref'], 'title' => $j['title'],
            'status' => $j['status'], 'print_hours' => $j['print_hours'],
            'queue_order' => (int)$j['queue_order'],
        ], $unassigned),
    ]);
}

// ── PATCH /api/jobs/reorder ──────────────────────────────────
//  body: { order: [jobId, jobId, ...] } → renumérote queue_order
//  selon la position dans la liste fournie (typiquement les jobs
//  d'une même imprimante, réordonnés par glisser-déposer).
if ($method === 'PATCH' && ($parts[1] ?? null) === 'reorder') {
    require_admin();
    $b = body();
    $order = $b['order'] ?? null;
    if (!is_array($order) || !$order) json_err('Liste order requise');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE jobs SET queue_order = ? WHERE id = ?');
    foreach (array_values($order) as $i => $job_id) {
        $stmt->execute([$i + 1, (int)$job_id]);
    }
    $pdo->commit();

    json_ok(['reordered' => true]);
}

// ── GET /api/jobs ─────────────────────────────────────────────
if ($method === 'GET' && $id === null) {
    $where  = $is_admin ? '' : 'WHERE j.client_id = ' . (int)$user['id'];
    $status = $_GET['status'] ?? '';
    if ($status && in_array($status, $STATUSES)) {
        $where = $where ? "$where AND j.status = " . $pdo->quote($status)
                        : "WHERE j.status = " . $pdo->quote($status);
    }
    $rows = $pdo->query(
        "SELECT j.id, j.ref, j.title, j.status, j.quantity, j.print_type,
                j.grams_used, j.ml_used, j.print_hours,
                j.price_final, j.paid, j.gifted, j.eta, j.created_at, j.updated_at, j.queue_order,
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
                f.color_hex, f.price_per_kg, f.price_per_litre, f.print_type AS filament_type
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

    $files = $pdo->prepare('SELECT id, filename, relative_path, path, size_bytes, uploaded_at FROM job_files WHERE job_id = ?');
    $files->execute([$id]);
    $job['files'] = array_map(function ($f) use ($id) {
        $served = preg_replace('#^job_' . $id . '/#', '', $f['path']);
        // Encoder chaque segment pour que les espaces/caractères spéciaux soient valides dans l'URL
        $f['url'] = '/api/files/' . $id . '/' . implode('/', array_map('rawurlencode', explode('/', $served)));
        return $f;
    }, $files->fetchAll());

    $items_stmt = $pdo->prepare(
        'SELECT i.id, i.file_id, i.name, i.quantity, i.status, i.notes, i.sort_order,
                f.filename, f.relative_path
         FROM job_items i
         LEFT JOIN job_files f ON f.id = i.file_id
         WHERE i.job_id = ?
         ORDER BY i.sort_order, i.id'
    );
    $items_stmt->execute([$id]);
    $job['items'] = $items_stmt->fetchAll();

    $events = $pdo->prepare('SELECT status, message, created_at FROM job_events WHERE job_id = ? ORDER BY created_at');
    $events->execute([$id]);
    $job['events'] = $events->fetchAll();

    $photos_stmt = $pdo->prepare('SELECT id, filename, path, size_bytes, uploaded_at FROM job_photos WHERE job_id = ? ORDER BY uploaded_at');
    $photos_stmt->execute([$id]);
    $job['photos'] = array_map(function ($p) use ($id) {
        $p['url'] = '/api/photos/' . $id . '/' . urlencode(basename($p['path']));
        return $p;
    }, $photos_stmt->fetchAll());

    // Masquer le token de suivi aux clients
    if (!$is_admin) unset($job['tracking_token']);

    json_ok($job);
}

// ── POST /api/jobs ────────────────────────────────────────────
if ($method === 'POST' && $id === null && $sub === null) {
    $b = body();
    if (empty($b['title'])) json_err('Titre requis');

    $client_id  = $is_admin ? (int)($b['client_id'] ?? $user['id']) : (int)$user['id'];
    $hourly     = (float)($pdo->query("SELECT value FROM settings WHERE key_name='hourly_rate'")->fetchColumn() ?? 0.80);
    $ref        = next_job_ref();
    $print_type = ($b['print_type'] ?? '') === 'resin' ? 'resin' : 'fdm';

    // Snapshot du prix matière au moment de la création : le prix reste figé
    // sur le job même si le prix de la bobine/résine change plus tard.
    $material_price = null;
    $fil_id = !empty($b['filament_id']) ? (int)$b['filament_id'] : null;
    if ($fil_id) {
        $col = $print_type === 'resin' ? 'price_per_litre' : 'price_per_kg';
        $stmt = $pdo->prepare("SELECT $col FROM filaments WHERE id = ?");
        $stmt->execute([$fil_id]);
        $price = $stmt->fetchColumn();
        $material_price = $price !== false ? (float)$price : null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO jobs (ref, client_id, printer_id, filament_id, title, description,
                           quantity, print_type, status, hourly_rate, material_price, notes_admin, tracking_token, queue_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,
                 (SELECT COALESCE(MAX(queue_order),0)+1 FROM jobs j2))'
    );
    $stmt->execute([
        $ref, $client_id,
        !empty($b['printer_id'])  ? (int)$b['printer_id']  : null,
        $fil_id,
        $b['title'],
        $b['description'] ?? null,
        (int)($b['quantity'] ?? 1),
        $print_type,
        $is_admin ? ($b['status'] ?? 'queued') : 'queued',
        $hourly,
        $material_price,
        $is_admin ? ($b['notes_admin'] ?? null) : null,
        generate_tracking_token(),
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

    // Recalcul prix auto selon le type d'impression
    $price_auto = $job['price_auto'];
    $print_type = ($b['print_type'] ?? $job['print_type'] ?? 'fdm') === 'resin' ? 'resin' : 'fdm';
    $hours      = isset($b['print_hours']) ? (float)$b['print_hours'] : (float)$job['print_hours'];

    // Le prix matière est figé au moment de la création (snapshot). On ne va
    // rechercher le prix courant de la bobine/résine que si le matériau change
    // sur ce job, ou si aucun snapshot n'existe encore (anciens jobs pré-migration).
    $fil_id          = (int)($b['filament_id'] ?? $job['filament_id']);
    $filament_changed = isset($b['filament_id']) && (int)$b['filament_id'] !== (int)$job['filament_id'];
    $material_price  = $job['material_price'];
    if ($fil_id && ($filament_changed || $material_price === null)) {
        $col = $print_type === 'resin' ? 'price_per_litre' : 'price_per_kg';
        $price = $pdo->query("SELECT $col FROM filaments WHERE id=$fil_id")->fetchColumn();
        $material_price = $price !== false ? (float)$price : null;
    }

    if ($print_type === 'resin') {
        $ml = isset($b['ml_used']) ? (float)$b['ml_used'] : (float)$job['ml_used'];
        if ($ml > 0 && $hours > 0 && $material_price !== null) {
            $price_auto = calc_price_auto($ml, $hours, $material_price, (float)$job['hourly_rate']);
        }
    } else {
        $grams = isset($b['grams_used']) ? (float)$b['grams_used'] : (float)$job['grams_used'];
        if ($grams > 0 && $hours > 0 && $material_price !== null) {
            $price_auto = calc_price_auto($grams, $hours, $material_price, (float)$job['hourly_rate']);
        }
    }

    $price_adjusted = isset($b['price_final']) && (float)$b['price_final'] !== $price_auto ? 1 : $job['price_adjusted'];
    $price_final    = isset($b['price_final']) ? (float)$b['price_final'] : ($price_auto ?? $job['price_final']);

    $stmt = $pdo->prepare(
        'UPDATE jobs SET
            title=?, description=?, quantity=?, print_type=?, printer_id=?, filament_id=?,
            grams_used=?, ml_used=?, print_hours=?, layer_current=?, layer_total=?,
            eta=?, started_at=?, finished_at=?, picked_up_at=?,
            price_auto=?, price_final=?, price_adjusted=?, material_price=?,
            notes_admin=?, queue_order=?
         WHERE id=?'
    );
    $stmt->execute([
        $b['title']         ?? $job['title'],
        $b['description']   ?? $job['description'],
        (int)($b['quantity']      ?? $job['quantity']),
        $print_type,
        !empty($b['printer_id'])  ? (int)$b['printer_id']  : $job['printer_id'],
        !empty($b['filament_id']) ? (int)$b['filament_id'] : $job['filament_id'],
        array_key_exists('grams_used', $b) ? $b['grams_used'] : $job['grams_used'],
        array_key_exists('ml_used',    $b) ? $b['ml_used']    : $job['ml_used'],
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
        $material_price,
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

    // Déduction de stock filament/résine quand le job passe en "done"
    if ($status === 'done') {
        $jd = $pdo->prepare('SELECT filament_id, print_type, grams_used, ml_used FROM jobs WHERE id = ?');
        $jd->execute([$id]);
        $jd = $jd->fetch();
        if ($jd && $jd['filament_id']) {
            if ($jd['print_type'] === 'resin' && (float)$jd['ml_used'] > 0) {
                $pdo->prepare('UPDATE filaments SET stock_grams = GREATEST(0, CAST(stock_grams AS SIGNED) - ?) WHERE id = ?')
                    ->execute([(float)$jd['ml_used'], $jd['filament_id']]);
            } elseif ($jd['print_type'] === 'fdm' && (float)$jd['grams_used'] > 0) {
                $pdo->prepare('UPDATE filaments SET stock_grams = GREATEST(0, CAST(stock_grams AS SIGNED) - ?) WHERE id = ?')
                    ->execute([(float)$jd['grams_used'], $jd['filament_id']]);
            }
        }
    }

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

// ── POST /api/jobs/{id}/items ─────────────────────────────────
if ($method === 'POST' && $id !== null && $sub === 'items') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b = body();

    // Création en masse : { file_ids: [1,2,3] } — un objet par fichier sélectionné,
    // pratique après l'upload d'un dossier complet pour choisir quoi imprimer.
    if (!empty($b['file_ids']) && is_array($b['file_ids'])) {
        $file_ids = array_map('intval', $b['file_ids']);
        $stmt = $pdo->prepare('SELECT id, filename FROM job_files WHERE id = ? AND job_id = ?');
        $insert = $pdo->prepare(
            'INSERT INTO job_items (job_id, file_id, name, quantity, sort_order)
             VALUES (?, ?, ?, 1, (SELECT COALESCE(MAX(sort_order),0)+1 FROM job_items ji2 WHERE ji2.job_id = ?))'
        );
        $created = [];
        foreach ($file_ids as $fid) {
            $stmt->execute([$fid, $id]);
            $f = $stmt->fetch();
            if (!$f) continue;
            $name = pathinfo($f['filename'], PATHINFO_FILENAME);
            $insert->execute([$id, $fid, $name, $id]);
            $created[] = (int)$pdo->lastInsertId();
        }
        json_ok(['created' => $created], 201);
    }

    $name = trim($b['name'] ?? '');
    if (!$name) json_err('Nom requis');
    $file_id = !empty($b['file_id']) ? (int)$b['file_id'] : null;
    $pdo->prepare(
        'INSERT INTO job_items (job_id, file_id, name, quantity, notes, sort_order)
         VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM job_items ji2 WHERE ji2.job_id = ?))'
    )->execute([$id, $file_id, $name, (int)($b['quantity'] ?? 1), $b['notes'] ?? null, $id]);
    json_ok(['id' => (int)$pdo->lastInsertId()], 201);
}

// ── PUT /api/jobs/{id}/items/{sub_id} ─────────────────────────
if ($method === 'PUT' && $id !== null && $sub === 'items' && $sub_id !== null) {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b = body();
    $name = trim($b['name'] ?? '');
    if (!$name) json_err('Nom requis');
    $ITEM_STATUSES = ['pending', 'printing', 'done', 'failed'];
    $status  = in_array($b['status'] ?? '', $ITEM_STATUSES) ? $b['status'] : 'pending';
    $file_id = array_key_exists('file_id', $b) ? (!empty($b['file_id']) ? (int)$b['file_id'] : null) : false;

    $fields = ['name = ?', 'quantity = ?', 'status = ?', 'notes = ?'];
    $params = [$name, (int)($b['quantity'] ?? 1), $status, $b['notes'] ?? null];
    if ($file_id !== false) { $fields[] = 'file_id = ?'; $params[] = $file_id; }
    $params[] = $sub_id;

    $pdo->prepare('UPDATE job_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND job_id = ' . $id)
        ->execute($params);
    json_ok(['updated' => true]);
}

// ── DELETE /api/jobs/{id}/items/{sub_id} ──────────────────────
if ($method === 'DELETE' && $id !== null && $sub === 'items' && $sub_id !== null) {
    if (!$is_admin) json_err('Accès refusé', 403);
    $pdo->prepare('DELETE FROM job_items WHERE id = ? AND job_id = ?')->execute([$sub_id, $id]);
    json_ok(['deleted' => true]);
}

// ── PATCH /api/jobs/{id}/gift ────────────────────────────────
if ($method === 'PATCH' && $id !== null && $sub === 'gift') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b      = body();
    $gifted = (int)(!empty($b['gifted']));
    $pdo->prepare('UPDATE jobs SET gifted = ?, price_final = IF(? = 1, 0, price_final), price_adjusted = IF(? = 1, 1, price_adjusted) WHERE id = ?')
        ->execute([$gifted, $gifted, $gifted, $id]);
    json_ok(['gifted' => $gifted]);
}

// ── PATCH /api/jobs/{id}/gallery ─────────────────────────────
if ($method === 'PATCH' && $id !== null && $sub === 'gallery') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b          = body();
    $in_gallery = (int)(!empty($b['in_gallery']));
    $pdo->prepare('UPDATE jobs SET in_gallery = ? WHERE id = ?')->execute([$in_gallery, $id]);
    json_ok(['in_gallery' => $in_gallery]);
}

// ── PATCH /api/jobs/{id}/payment ─────────────────────────────
if ($method === 'PATCH' && $id !== null && $sub === 'payment') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $b    = body();
    $paid = (int)(!empty($b['paid']));
    $pdo->prepare('UPDATE jobs SET paid = ?, paid_at = ? WHERE id = ?')
        ->execute([$paid, $paid ? date('Y-m-d H:i:s') : null, $id]);
    json_ok(['paid' => $paid]);
}

// ── POST /api/jobs/{id}/photos ────────────────────────────────
if ($method === 'POST' && $id !== null && $sub === 'photos') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $stmt = $pdo->prepare('SELECT id FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_err('Job introuvable', 404);

    $saved = handle_photo_upload($id);
    json_ok($saved, 201);
}

// ── POST /api/jobs/{id}/token  (génère ou régénère le token) ──
if ($method === 'POST' && $id !== null && $sub === 'token') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $token = generate_tracking_token();
    $pdo->prepare('UPDATE jobs SET tracking_token = ? WHERE id = ?')->execute([$token, $id]);
    json_ok(['tracking_token' => $token]);
}

// ── DELETE /api/jobs/{id}/photos  (query ?photo_id=N) ────────
if ($method === 'DELETE' && $id !== null && $sub === 'photos') {
    if (!$is_admin) json_err('Accès refusé', 403);
    $photo_id = (int)($_GET['photo_id'] ?? 0);
    if (!$photo_id) json_err('photo_id requis');

    $row = $pdo->prepare('SELECT path FROM job_photos WHERE id = ? AND job_id = ?');
    $row->execute([$photo_id, $id]);
    $f = $row->fetch();
    if (!$f) json_err('Photo introuvable', 404);

    @unlink(UPLOAD_DIR . $f['path']);
    $pdo->prepare('DELETE FROM job_photos WHERE id = ?')->execute([$photo_id]);
    json_ok(['deleted' => true]);
}

json_err('Méthode non supportée', 405);
