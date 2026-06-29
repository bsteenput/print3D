/* ============================================================
   Print3D — Single-Page Application
   ============================================================ */

const API = '/api';
let _user = null;

// ── Auth helpers ──────────────────────────────────────────────
const token = () => localStorage.getItem('p3d_token');
const setToken = t => localStorage.setItem('p3d_token', t);
const clearAuth = () => { localStorage.removeItem('p3d_token'); _user = null; };

async function apiFetch(path, opts = {}) {
  const headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
  if (token()) headers['Authorization'] = 'Bearer ' + token();
  const res = await fetch(API + path, { ...opts, headers });
  const json = await res.json().catch(() => ({ ok: false, error: 'Erreur réseau' }));
  if (!json.ok) throw Object.assign(new Error(json.error || 'Erreur'), { status: res.status });
  return json.data;
}

const get  = (p)    => apiFetch(p);
const post = (p, b) => apiFetch(p, { method: 'POST',   body: JSON.stringify(b) });
const put  = (p, b) => apiFetch(p, { method: 'PUT',    body: JSON.stringify(b) });
const patch= (p, b) => apiFetch(p, { method: 'PATCH',  body: JSON.stringify(b) });
const del  = (p)    => apiFetch(p, { method: 'DELETE' });

// ── DOM helpers ───────────────────────────────────────────────
const el    = id  => document.getElementById(id);
const html  = (id, h) => { el(id).innerHTML = h; };
const show  = id  => { el(id).style.display = ''; };
const hide  = id  => { el(id).style.display = 'none'; };
const esc   = s   => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const money = n   => Number(n ?? 0).toFixed(2) + ' €';
const fmt   = d   => d ? new Date(d).toLocaleString('fr-BE', { dateStyle:'short', timeStyle:'short' }) : '—';
const fmtD  = d   => d ? new Date(d).toLocaleDateString('fr-BE') : '—';

const STATUS_LABELS = {
  draft:'Brouillon', queued:'En attente', printing:'En cours',
  done:'Terminé', picked_up:'Récupéré', cancelled:'Annulé'
};
const badge = s => `<span class="badge badge-${s}">${STATUS_LABELS[s] ?? s}</span>`;
const colorDot = hex => hex ? `<span class="color-dot" style="background:${esc(hex)}"></span>` : '';

const ITEM_STATUS_LABELS = { pending:'En attente', printing:'En cours', done:'Terminé', failed:'Échoué' };
const ITEM_STATUS_BADGE  = { pending:'queued', printing:'printing', done:'done', failed:'cancelled' };
const itemBadge = s => `<span class="badge badge-${ITEM_STATUS_BADGE[s]??'draft'}">${ITEM_STATUS_LABELS[s]??s}</span>`;

// ── Thème ─────────────────────────────────────────────────────
function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  const btn = el('theme-toggle');
  if (btn) btn.querySelector('.icon').textContent = theme === 'dark' ? '☀️' : '🌙';
}

// ── Login ─────────────────────────────────────────────────────
async function initLogin() {
  el('login-screen').style.display = 'flex';
  hide('app');
  const btn = el('l-btn');
  btn.onclick = async () => {
    btn.disabled = true;
    hide('login-err');
    try {
      const r = await post('/auth/login', { email: el('l-email').value, password: el('l-pass').value });
      setToken(r.token);
      _user = r.user;
      startApp();
    } catch(e) {
      el('login-err').textContent = e.message;
      show('login-err');
    } finally { btn.disabled = false; }
  };
  el('l-pass').onkeydown = e => { if (e.key === 'Enter') btn.click(); };
}

// ── App shell ─────────────────────────────────────────────────
async function startApp() {
  hide('login-screen');
  el('app').style.display = 'flex';
  el('sb-name').textContent = _user.name;

  // show/hide admin-only nav items
  document.querySelectorAll('[data-admin]').forEach(a => {
    a.style.display = _user.role === 'admin' ? '' : 'none';
  });

  el('logout-btn').onclick = e => { e.preventDefault(); clearAuth(); initLogin(); };
  window.onhashchange = route;
  if (!location.hash || location.hash === '#') location.hash = _user.role === 'admin' ? '#dashboard' : '#jobs';
  else route();
}

// ── Router ────────────────────────────────────────────────────
function route() {
  stopMonitor();
  const hash = location.hash.replace('#', '') || 'dashboard';
  const [page, param] = hash.split('/');

  document.querySelectorAll('#nav a').forEach(a => {
    a.classList.toggle('active', a.getAttribute('href') === '#' + page);
  });

  const views = {
    dashboard: viewDashboard,
    jobs:      () => param ? viewJob(param) : viewJobs(),
    clients:   () => param ? viewClient(param) : viewClients(),
    printers:  viewPrinters,
    filaments: viewFilaments,
    settings:  viewSettings,
    stats:     viewStats,
  };
  (views[page] || (() => html('view', '<div class="empty">Page introuvable</div>')))();
}

// ── DASHBOARD ─────────────────────────────────────────────────
async function viewDashboard() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const d = await get('/dashboard');
    html('view', `
      <div class="page-title">Dashboard</div>
      <div class="stat-grid">
        <div class="stat-card"><div class="val">${d.counts.queued}</div><div class="lbl">En attente</div></div>
        <div class="stat-card"><div class="val">${d.counts.printing}</div><div class="lbl">En cours</div></div>
        <div class="stat-card"><div class="val">${d.counts.done}</div><div class="lbl">Terminés</div></div>
        <div class="stat-card"><div class="val">${money(d.revenue)}</div><div class="lbl">CA total</div></div>
      </div>
      ${d.low_stock.length ? `
      <div class="card">
        <h2>⚠️ Stock bas</h2>
        <div class="table-wrap"><table>
          <tr><th>Matière</th><th>Type</th><th>Couleur</th><th>Stock</th></tr>
          ${d.low_stock.map(f=>`<tr>
            <td>${esc(f.material)}</td>
            <td>${f.print_type === 'resin' ? 'Résine' : 'FDM'}</td>
            <td>${colorDot(f.color_hex)}${esc(f.color)}</td>
            <td><strong style="color:var(--warning)">${f.stock_grams}${f.print_type === 'resin' ? 'ml' : 'g'}</strong></td>
          </tr>`).join('')}
        </table></div>
      </div>` : ''}
      <div class="card">
        <h2>Jobs actifs</h2>
        ${d.active_jobs.length ? `<div class="table-wrap"><table>
          <tr><th>Réf</th><th>Titre</th><th>Client</th><th>Statut</th><th>Progression</th></tr>
          ${d.active_jobs.map(j => `<tr>
            <td><a href="#jobs/${j.id}">${esc(j.ref)}</a></td>
            <td>${esc(j.title)}</td>
            <td>${esc(j.client_name)}</td>
            <td>${badge(j.status)}</td>
            <td>${j.layer_total ? progressBar(j.layer_current, j.layer_total) : '—'}</td>
          </tr>`).join('')}
        </table></div>` : '<div class="empty">Aucun job actif</div>'}
      </div>
      <div class="card">
        <h2>Jobs récents</h2>
        <div class="table-wrap"><table>
          <tr><th>Réf</th><th>Titre</th><th>Client</th><th>Statut</th><th>Prix</th><th>Date</th></tr>
          ${d.recent_jobs.map(j=>`<tr>
            <td><a href="#jobs/${j.id}">${esc(j.ref)}</a></td>
            <td>${esc(j.title)}</td>
            <td>${esc(j.client_name)}</td>
            <td>${badge(j.status)}</td>
            <td>${j.price_final ? money(j.price_final) : '—'}</td>
            <td>${fmtD(j.created_at)}</td>
          </tr>`).join('')}
        </table></div>
      </div>
    `);
  } catch(e) { html('view', errBox(e)); }
}

function progressBar(cur, tot) {
  const pct = tot ? Math.round((cur/tot)*100) : 0;
  return `<div class="progress-wrap" title="${cur}/${tot} couches">
    <div class="progress-bar" style="width:${pct}%"></div>
  </div><small style="color:var(--muted)">${pct}%</small>`;
}

// ── JOBS LIST ─────────────────────────────────────────────────
async function viewJobs() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const jobs = await get('/jobs');
    const isAdmin = _user.role === 'admin';
    html('view', `
      <div class="page-title">
        Jobs d'impression
        <button class="btn btn-primary" id="new-job-btn">+ Nouveau job</button>
      </div>
      <div class="table-wrap"><table>
        <tr>
          <th>Réf</th><th>Titre</th>
          ${isAdmin ? '<th>Client</th>' : ''}
          <th>Statut</th><th>Prix</th>${isAdmin ? '<th>Payé</th>' : ''}<th>ETA</th><th>Créé</th>
        </tr>
        ${jobs.length ? jobs.map(j => `<tr style="cursor:pointer" onclick="location.hash='#jobs/${j.id}'">
          <td><strong>${esc(j.ref)}</strong></td>
          <td>${esc(j.title)}</td>
          ${isAdmin ? `<td>${esc(j.client_name)}</td>` : ''}
          <td>${badge(j.status)}</td>
          <td>${j.price_final ? money(j.price_final) : '—'}</td>
          ${isAdmin ? `<td>${j.paid ? '<span style="color:#22c55e;font-weight:700">✓</span>' : '<span style="color:#f59e0b">✗</span>'}</td>` : ''}
          <td>${fmt(j.eta)}</td>
          <td>${fmtD(j.created_at)}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="empty">Aucun job</td></tr>'}
      </table></div>
    `);
    el('new-job-btn')?.addEventListener('click', () => isAdmin ? modalNewJob() : modalNewJobClient());
  } catch(e) { html('view', errBox(e)); }
}

// ── JOB DETAIL ────────────────────────────────────────────────
async function viewJob(id) {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const [j, clients, printers, filaments] = await Promise.all([
      get('/jobs/' + id),
      _user.role === 'admin' ? get('/clients') : Promise.resolve([]),
      _user.role === 'admin' ? get('/printers') : Promise.resolve([]),
      get('/filaments'),
    ]);
    const isAdmin = _user.role === 'admin';
    const pct = j.layer_total ? Math.round((j.layer_current/j.layer_total)*100) : null;

    html('view', `
      <div class="page-title">
        <div>
          <a href="#jobs" style="font-size:14px;color:var(--muted)">← Jobs</a><br>
          ${esc(j.ref)} — ${esc(j.title)}
        </div>
        <div style="display:flex;gap:8px">
          ${isAdmin ? `<button class="btn btn-primary btn-sm" id="edit-job-btn">Modifier</button>
          <button class="btn btn-ghost btn-sm" id="status-btn">Changer statut</button>
          <button class="btn btn-danger btn-sm" id="del-job-btn">Supprimer</button>` : ''}
        </div>
      </div>
      <div class="detail-grid">
        <div>
          <div class="card">
            <h2>Informations</h2>
            <table style="width:100%">
              <tr><td style="color:var(--muted);width:140px">Statut</td><td>${badge(j.status)}</td></tr>
              <tr><td style="color:var(--muted)">Client</td><td>${esc(j.client_name)}</td></tr>
              <tr><td style="color:var(--muted)">Quantité</td><td>${j.quantity}</td></tr>
              <tr><td style="color:var(--muted)">Imprimante</td><td>${esc(j.printer_name ?? '—')}</td></tr>
              <tr><td style="color:var(--muted)">${j.print_type === 'resin' ? 'Résine' : 'Filament'}</td><td>${j.filament_material ? colorDot(j.color_hex)+esc(j.filament_material)+' '+esc(j.filament_color) : '—'}</td></tr>
              ${j.print_type === 'resin'
                ? `<tr><td style="color:var(--muted)">Volume</td><td>${j.ml_used ? j.ml_used+'ml' : '—'}</td></tr>`
                : `<tr><td style="color:var(--muted)">Grammes</td><td>${j.grams_used ? j.grams_used+'g' : '—'}</td></tr>`}
              <tr><td style="color:var(--muted)">Durée</td><td>${j.print_hours ? j.print_hours+'h' : '—'}</td></tr>
              <tr><td style="color:var(--muted)">ETA</td><td>${fmt(j.eta)}</td></tr>
              <tr><td style="color:var(--muted)">Prix auto</td><td>${j.price_auto ? money(j.price_auto) : '—'}</td></tr>
              <tr><td style="color:var(--muted)">Prix final</td><td><strong>${j.price_final ? money(j.price_final) : '—'}</strong></td></tr>
              ${isAdmin ? `<tr><td style="color:var(--muted)">Paiement</td><td>
                <button id="pay-toggle-btn" class="btn btn-sm ${j.paid ? 'btn-primary' : 'btn-ghost'}" style="font-size:12px">
                  ${j.paid ? '✓ Payé' : '✗ Non payé'}
                </button>
              </td></tr>` : ''}
            </table>
            ${j.description ? `<hr style="border-color:var(--border);margin:16px 0"><p style="color:var(--muted)">${esc(j.description)}</p>` : ''}
            ${isAdmin && j.notes_admin ? `<hr style="border-color:var(--border);margin:16px 0"><p style="font-size:12px;color:var(--warning)">🔒 ${esc(j.notes_admin)}</p>` : ''}
            ${isAdmin ? `<hr style="border-color:var(--border);margin:16px 0">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              ${j.tracking_token
                ? `<span style="font-size:12px;color:var(--muted)">Lien de suivi :</span>
                   <button class="btn btn-sm btn-ghost" onclick="copyTrackingLink('${esc(j.tracking_token)}')">📋 Copier le lien</button>`
                : `<button class="btn btn-sm btn-ghost" id="gen-token-btn">🔗 Générer lien de suivi</button>`}
            </div>` : ''}
          </div>
          ${j.status === 'printing' ? `
          <div class="card">
            <h2>🔴 Live — Elegoo Saturn 4 Ultra</h2>
            <div id="monitor-box"><div style="color:var(--muted);font-size:12px">Connexion à l'imprimante…</div></div>
          </div>` : pct !== null ? `
          <div class="card">
            <h2>Progression</h2>
            ${progressBar(j.layer_current, j.layer_total)}
            <p style="margin-top:8px;font-size:13px;color:var(--muted)">${j.layer_current}/${j.layer_total} couches</p>
          </div>` : ''}
        </div>
        <div>
          <div class="card">
            <h2>Fichiers STL</h2>
            ${j.files.length ? `<ul class="file-list" id="file-list">
              ${j.files.map(f => `<li id="fi-${f.id}">
                <button class="btn btn-sm btn-ghost" onclick="openStl('${esc(f.url)}','${esc(f.filename)}')">👁 Voir</button>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(f.filename)}</span>
                <span style="color:var(--muted)">${formatBytes(f.size_bytes)}</span>
                ${isAdmin ? `<button class="btn btn-sm btn-danger" onclick="deleteFile(${j.id},${f.id})">✕</button>` : ''}
              </li>`).join('')}
            </ul>` : '<div class="empty" style="padding:16px">Aucun fichier</div>'}
            <div style="margin-top:12px">
              <input type="file" id="stl-input" accept=".stl,.3mf,.obj" multiple style="display:none">
              <button class="btn btn-ghost btn-sm" onclick="el('stl-input').click()">+ Ajouter STL</button>
              <span id="upload-status" style="font-size:12px;color:var(--muted);margin-left:8px"></span>
            </div>
          </div>
          <div class="card">
            <h2>Photos du résultat
              ${isAdmin ? `<label class="btn btn-sm btn-ghost" style="cursor:pointer;font-weight:400">
                + Ajouter<input type="file" id="photo-input" accept="image/*" multiple style="display:none">
              </label>` : ''}
            </h2>
            ${j.photos && j.photos.length ? `
            <div id="photo-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;margin-bottom:8px">
              ${j.photos.map(p => `
              <div style="position:relative" id="ph-${p.id}">
                <img src="${esc(p.url)}" alt="${esc(p.filename)}" loading="lazy"
                     style="width:100%;aspect-ratio:1;object-fit:cover;border:var(--bw) solid var(--border);cursor:pointer;border-radius:2px"
                     onclick="openPhotoViewer('${esc(p.url)}','${esc(p.filename)}')">
                ${isAdmin ? `<button onclick="deletePhoto(${j.id},${p.id})" style="position:absolute;top:2px;right:2px;background:#ef4444;color:#fff;border:none;border-radius:2px;width:18px;height:18px;cursor:pointer;font-size:10px;line-height:1;padding:0">✕</button>` : ''}
              </div>`).join('')}
            </div>` : `<div class="empty" style="padding:16px">${isAdmin ? 'Aucune photo — ajoutez des photos du résultat' : 'Aucune photo disponible'}</div>`}
            <span id="photo-status" style="font-size:12px;color:var(--muted)"></span>
          </div>
          <div class="card">
            <h2>Timeline</h2>
            <ul class="timeline">
              ${j.events.map(ev=>`<li>
                <div class="tl-dot"></div>
                <div>
                  <div class="tl-time">${fmt(ev.created_at)}</div>
                  <div class="tl-label">${esc(STATUS_LABELS[ev.status] ?? ev.status)}</div>
                  ${ev.message ? `<div class="tl-msg">${esc(ev.message)}</div>` : ''}
                </div>
              </li>`).join('')}
            </ul>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <h2 style="display:flex;justify-content:space-between;align-items:center">
          Objets à imprimer
          ${isAdmin ? `<button class="btn btn-sm btn-ghost" id="add-item-btn">+ Ajouter</button>` : ''}
        </h2>
        ${j.items.length ? `
        <div class="table-wrap"><table>
          <tr><th>Nom</th><th>Fichier STL</th><th style="text-align:center">Qté</th><th>Notes</th><th>Statut</th>${isAdmin?'<th></th>':''}</tr>
          ${j.items.map(it => `<tr id="item-row-${it.id}">
            <td>${esc(it.name)}</td>
            <td style="color:var(--muted)">${it.filename ? esc(it.filename) : '—'}</td>
            <td style="text-align:center">${it.quantity}</td>
            <td style="color:var(--muted);font-size:12px">${it.notes ? esc(it.notes) : ''}</td>
            <td>${isAdmin
              ? `<select onchange="changeItemStatus(${j.id},${it.id},this.value)" style="font-size:12px;padding:2px 4px">
                  ${Object.keys(ITEM_STATUS_LABELS).map(s=>`<option value="${s}"${s===it.status?' selected':''}>${ITEM_STATUS_LABELS[s]}</option>`).join('')}
                 </select>`
              : itemBadge(it.status)}</td>
            ${isAdmin ? `<td style="white-space:nowrap">
              <button class="btn btn-sm btn-ghost" onclick="modalEditItem(${j.id},${it.id})">✏</button>
              <button class="btn btn-sm btn-danger" onclick="deleteItem(${j.id},${it.id})">✕</button>
            </td>` : ''}
          </tr>`).join('')}
        </table></div>` : `<div class="empty" style="padding:16px">Aucun objet — ${isAdmin ? 'ajoutez les modèles à imprimer' : 'aucun objet renseigné pour ce job'}</div>`}
      </div>
    `);

    // index des items pour les modals
    window._jobItems = {};
    j.items.forEach(it => { window._jobItems[it.id] = it; });
    window._jobFiles = j.files;

    // STL upload
    el('stl-input')?.addEventListener('change', async () => {
      const f = el('stl-input').files;
      if (!f.length) return;
      el('upload-status').textContent = 'Upload…';
      const fd = new FormData();
      for (let i=0; i<f.length; i++) fd.append('stl[]', f[i]);
      const headers = {};
      if (token()) headers['Authorization'] = 'Bearer ' + token();
      try {
        const res = await fetch(`${API}/jobs/${id}/files`, { method:'POST', headers, body: fd });
        const json = await res.json();
        el('upload-status').textContent = json.ok ? `${json.data.length} fichier(s) uploadé(s)` : json.error;
        if (json.ok) setTimeout(() => viewJob(id), 800);
      } catch(e) { el('upload-status').textContent = 'Erreur upload'; }
    });

    // Démarre le monitor temps réel si le job est en cours d'impression
    if (j.status === 'printing') {
      startMonitor(id);
    }

    if (isAdmin) {
      el('edit-job-btn')?.addEventListener('click', () => modalEditJob(j, clients, printers, filaments));
      el('status-btn')?.addEventListener('click', () => modalStatus(j));
      el('del-job-btn')?.addEventListener('click', async () => {
        if (!confirm('Supprimer ce job ?')) return;
        await del('/jobs/' + id);
        location.hash = '#jobs';
      });
      el('add-item-btn')?.addEventListener('click', () => modalAddItem(id));

      // Toggle paiement
      el('pay-toggle-btn')?.addEventListener('click', async () => {
        await patch(`/jobs/${id}/payment`, { paid: j.paid ? 0 : 1 });
        viewJob(id);
      });

      // Générer token de suivi
      el('gen-token-btn')?.addEventListener('click', async () => {
        const r = await post(`/jobs/${id}/token`, {});
        copyTrackingLink(r.tracking_token);
        viewJob(id);
      });

      // Upload photos
      el('photo-input')?.addEventListener('change', async () => {
        const files = el('photo-input').files;
        if (!files.length) return;
        el('photo-status').textContent = 'Upload…';
        const fd = new FormData();
        for (let i = 0; i < files.length; i++) fd.append('photo[]', files[i]);
        const headers = {};
        if (token()) headers['Authorization'] = 'Bearer ' + token();
        try {
          const res = await fetch(`${API}/jobs/${id}/photos`, { method: 'POST', headers, body: fd });
          const json = await res.json();
          el('photo-status').textContent = json.ok ? `${json.data.length} photo(s) ajoutée(s)` : json.error;
          if (json.ok) setTimeout(() => viewJob(id), 600);
        } catch(e) { el('photo-status').textContent = 'Erreur upload'; }
      });
    }
  } catch(e) { html('view', errBox(e)); }
}

window.deleteFile = async (jobId, fileId) => {
  if (!confirm('Supprimer ce fichier ?')) return;
  await del(`/jobs/${jobId}/files?file_id=${fileId}`);
  el('fi-' + fileId)?.remove();
};

window.deletePhoto = async (jobId, photoId) => {
  if (!confirm('Supprimer cette photo ?')) return;
  await del(`/jobs/${jobId}/photos?photo_id=${photoId}`);
  el('ph-' + photoId)?.remove();
};

window.openPhotoViewer = (url, name) => {
  openModal(name, `<div style="text-align:center"><img src="${esc(url)}" alt="${esc(name)}" style="max-width:100%;max-height:60vh;object-fit:contain"></div>`, [{label:'Fermer',cls:'btn-ghost',click:closeModal}]);
};

function itemFileOptions(selectedId) {
  const files = window._jobFiles ?? [];
  const opts = files.map(f => `<option value="${f.id}"${f.id===selectedId?' selected':''}>${esc(f.filename)}</option>`).join('');
  return `<option value="">— Aucun fichier —</option>${opts}`;
}

function modalAddItem(jobId) {
  openModal('Ajouter un objet', `
    <div class="form-group"><label>Nom de l'objet</label><input id="m-iname" placeholder="Ex: Vase nervuré"></div>
    <div class="form-row">
      <div class="form-group"><label>Quantité</label><input type="number" id="m-iqty" value="1" min="1"></div>
      <div class="form-group"><label>Fichier STL</label>
        <select id="m-ifile">${itemFileOptions(null)}</select>
      </div>
    </div>
    <div class="form-group"><label>Notes <span style="font-weight:400;font-size:11px;color:var(--muted)">(paramètres, orientation…)</span></label>
      <textarea id="m-inotes" rows="2" placeholder="Ex: 0.1mm, 20% infill, orienté à plat"></textarea>
    </div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Ajouter',cls:'btn-primary',click:async()=>{
    const name = el('m-iname').value.trim();
    if (!name) { alert('Nom requis'); return; }
    await post(`/jobs/${jobId}/items`, {
      name, quantity: +el('m-iqty').value,
      file_id: +el('m-ifile').value || null,
      notes: el('m-inotes').value || null,
    });
    closeModal(); viewJob(jobId);
  }}]);
}

window.modalEditItem = (jobId, itemId) => {
  const it = window._jobItems[itemId];
  if (!it) return;
  openModal('Modifier l\'objet', `
    <div class="form-group"><label>Nom de l'objet</label><input id="m-iname" value="${esc(it.name)}"></div>
    <div class="form-row">
      <div class="form-group"><label>Quantité</label><input type="number" id="m-iqty" value="${it.quantity}" min="1"></div>
      <div class="form-group"><label>Fichier STL</label>
        <select id="m-ifile">${itemFileOptions(it.file_id)}</select>
      </div>
    </div>
    <div class="form-group"><label>Statut</label>
      <select id="m-istatus">
        ${Object.keys(ITEM_STATUS_LABELS).map(s=>`<option value="${s}"${s===it.status?' selected':''}>${ITEM_STATUS_LABELS[s]}</option>`).join('')}
      </select>
    </div>
    <div class="form-group"><label>Notes</label>
      <textarea id="m-inotes" rows="2">${esc(it.notes??'')}</textarea>
    </div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Enregistrer',cls:'btn-primary',click:async()=>{
    const name = el('m-iname').value.trim();
    if (!name) { alert('Nom requis'); return; }
    await put(`/jobs/${jobId}/items/${itemId}`, {
      name, quantity: +el('m-iqty').value,
      status: el('m-istatus').value,
      file_id: +el('m-ifile').value || null,
      notes: el('m-inotes').value || null,
    });
    closeModal(); viewJob(jobId);
  }}]);
};

window.deleteItem = async (jobId, itemId) => {
  if (!confirm('Supprimer cet objet ?')) return;
  await del(`/jobs/${jobId}/items/${itemId}`);
  el('item-row-' + itemId)?.remove();
};

window.changeItemStatus = async (jobId, itemId, status) => {
  const it = window._jobItems[itemId];
  if (!it) return;
  await put(`/jobs/${jobId}/items/${itemId}`, { ...it, status, file_id: it.file_id || null });
  window._jobItems[itemId] = { ...it, status };
};

function formatBytes(b) {
  if (!b) return '';
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b/1024).toFixed(0) + ' KB';
  return (b/1048576).toFixed(1) + ' MB';
}

// ── MODAL: new job ────────────────────────────────────────────
async function modalNewJob() {
  const [clients, printers, filaments] = await Promise.all([
    get('/clients'), get('/printers'), get('/filaments')
  ]);
  window._newJobFilaments = filaments.filter(f => f.active);
  const fdmOpts = () => window._newJobFilaments
    .filter(f => (f.print_type||'fdm') === 'fdm')
    .map(f => `<option value="${f.id}">${esc(f.material)} ${esc(f.color)}</option>`).join('');
  openModal('Nouveau job', `
    <div class="form-row">
      <div class="form-group"><label>Client</label>
        <select id="m-client">${clients.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label>Quantité</label><input type="number" id="m-qty" value="1" min="1"></div>
    </div>
    <div class="form-group"><label>Titre</label><input id="m-title" placeholder="Ex: Pied de lampe"></div>
    <div class="form-group"><label>Description</label><textarea id="m-desc" rows="3"></textarea></div>
    <div class="form-group"><label>Type d'impression</label>
      <select id="m-ptype" onchange="toggleNewJobType()">
        <option value="fdm">FDM (filament)</option>
        <option value="resin">Résine</option>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Imprimante</label>
        <select id="m-printer"><option value="">—</option>${printers.filter(p=>p.active).map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label id="m-mat-label">Filament</label>
        <select id="m-filament"><option value="">—</option>${fdmOpts()}</select>
      </div>
    </div>
    <div class="form-group"><label>Notes internes</label><textarea id="m-notes" rows="2"></textarea></div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Créer',cls:'btn-primary',click:async()=>{
    const b = {
      title: el('m-title').value, description: el('m-desc').value,
      client_id: +el('m-client').value, quantity: +el('m-qty').value,
      print_type: el('m-ptype').value,
      printer_id: +el('m-printer').value || null,
      filament_id: +el('m-filament').value || null,
      notes_admin: el('m-notes').value,
    };
    const r = await post('/jobs', b);
    closeModal(); location.hash = '#jobs/' + r.id;
  }}]);
}

window.toggleNewJobType = () => {
  const isResin = el('m-ptype').value === 'resin';
  el('m-mat-label').textContent = isResin ? 'Résine' : 'Filament';
  const filtered = (window._newJobFilaments || []).filter(f => (f.print_type||'fdm') === (isResin ? 'resin' : 'fdm'));
  el('m-filament').innerHTML = `<option value="">—</option>` +
    filtered.map(f => `<option value="${f.id}">${esc(f.material)} ${esc(f.color)}</option>`).join('');
};

// ── MODAL: new job (client) ───────────────────────────────────
async function modalNewJobClient() {
  const filaments = await get('/filaments');
  window._clientJobFilaments = filaments.filter(f => f.active);
  const buildClientFilOpts = type => {
    const list = window._clientJobFilaments.filter(f => (f.print_type||'fdm') === type);
    const byMat = list.reduce((acc, f) => { (acc[f.material]=acc[f.material]||[]).push(f); return acc; }, {});
    return `<option value="">— Sans préférence —</option>` +
      Object.entries(byMat).map(([mat, fs]) =>
        `<optgroup label="${esc(mat)}">${fs.map(f=>`<option value="${f.id}">${esc(f.color)}</option>`).join('')}</optgroup>`
      ).join('');
  };
  openModal('Nouvelle demande d\'impression', `
    <div class="form-group">
      <label>Titre <span style="color:var(--danger)">*</span></label>
      <input id="m-title" placeholder="Ex: Support de téléphone">
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea id="m-desc" rows="3" placeholder="Informations complémentaires, dimensions, contraintes…"></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Quantité</label>
        <input type="number" id="m-qty" value="1" min="1" max="10">
      </div>
      <div class="form-group">
        <label>Technologie</label>
        <select id="m-ptype" onchange="toggleClientJobType()">
          <option value="fdm">FDM (plastique)</option>
          <option value="resin">Résine</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label id="m-mat-label">Filament souhaité</label>
      <select id="m-filament">${buildClientFilOpts('fdm')}</select>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-top:8px">Vous pourrez uploader vos fichiers STL/3MF après création.</p>
  `, [
    { label: 'Annuler', cls: 'btn-ghost', click: closeModal },
    { label: 'Créer la demande', cls: 'btn-primary', click: async () => {
      const title = el('m-title').value.trim();
      if (!title) { alert('Le titre est obligatoire.'); return; }
      const qty = +el('m-qty').value;
      if (qty < 1 || qty > 10) { alert('La quantité doit être entre 1 et 10.'); return; }
      const r = await post('/jobs', {
        title,
        description: el('m-desc').value,
        quantity: qty,
        print_type: el('m-ptype').value,
        filament_id: +el('m-filament').value || null,
      });
      closeModal();
      location.hash = '#jobs/' + r.id;
    }}
  ]);
}

window.toggleClientJobType = () => {
  const isResin = el('m-ptype').value === 'resin';
  el('m-mat-label').textContent = isResin ? 'Résine souhaitée' : 'Filament souhaité';
  const list = (window._clientJobFilaments || []).filter(f => (f.print_type||'fdm') === (isResin ? 'resin' : 'fdm'));
  const byMat = list.reduce((acc, f) => { (acc[f.material]=acc[f.material]||[]).push(f); return acc; }, {});
  el('m-filament').innerHTML = `<option value="">— Sans préférence —</option>` +
    Object.entries(byMat).map(([mat, fs]) =>
      `<optgroup label="${esc(mat)}">${fs.map(f=>`<option value="${f.id}">${esc(f.color)}</option>`).join('')}</optgroup>`
    ).join('');
};

// ── MODAL: edit job ───────────────────────────────────────────
function modalEditJob(j, clients, printers, filaments) {
  const isResin = j.print_type === 'resin';
  const filByType = t => filaments.filter(f => (f.print_type||'fdm') === t);
  const filOpts = (list, selId) => `<option value="">—</option>` +
    list.map(f=>`<option value="${f.id}"${+f.id===+selId?' selected':''}>${esc(f.material)} ${esc(f.color)}</option>`).join('');
  window._editJobFilaments = filaments;
  openModal('Modifier ' + j.ref, `
    <div class="form-group"><label>Titre</label><input id="m-title" value="${esc(j.title)}"></div>
    <div class="form-row">
      <div class="form-group"><label>Client</label>
        <select id="m-client">${clients.map(c=>`<option value="${c.id}"${+c.id===+j.client_id?' selected':''}>${esc(c.name)}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label>Quantité</label><input type="number" id="m-qty" value="${j.quantity}" min="1"></div>
    </div>
    <div class="form-group"><label>Type d'impression</label>
      <select id="m-ptype" onchange="toggleEditJobType()">
        <option value="fdm"${!isResin?' selected':''}>FDM (filament)</option>
        <option value="resin"${isResin?' selected':''}>Résine</option>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Imprimante</label>
        <select id="m-printer"><option value="">—</option>${printers.map(p=>`<option value="${p.id}"${+p.id===+j.printer_id?' selected':''}>${esc(p.name)}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label id="m-mat-label">${isResin ? 'Résine' : 'Filament'}</label>
        <select id="m-filament">${filOpts(filByType(isResin?'resin':'fdm'), j.filament_id)}</select>
      </div>
    </div>
    <div class="form-row-3">
      <div class="form-group" id="fg-grams"${isResin?' style="display:none"':''}>
        <label>Grammes utilisés</label><input type="number" step="0.1" id="m-grams" value="${j.grams_used??''}">
      </div>
      <div class="form-group" id="fg-ml"${!isResin?' style="display:none"':''}>
        <label>Volume résine (ml)</label><input type="number" step="0.1" id="m-ml" value="${j.ml_used??''}">
      </div>
      <div class="form-group"><label>Heures</label><input type="number" step="0.1" id="m-hours" value="${j.print_hours??''}"></div>
      <div class="form-group"><label>Prix final (€)</label><input type="number" step="0.01" id="m-price" value="${j.price_final??''}"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Couche actuelle</label><input type="number" id="m-lc" value="${j.layer_current??''}"></div>
      <div class="form-group"><label>Couches total</label><input type="number" id="m-lt" value="${j.layer_total??''}"></div>
    </div>
    <div class="form-group"><label>ETA</label><input type="datetime-local" id="m-eta" value="${j.eta?j.eta.replace(' ','T').substring(0,16):''}"></div>
    <div class="form-group"><label>Description</label><textarea id="m-desc">${esc(j.description??'')}</textarea></div>
    <div class="form-group"><label>Notes internes</label><textarea id="m-notes">${esc(j.notes_admin??'')}</textarea></div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Enregistrer',cls:'btn-primary',click:async()=>{
    const r = el('m-ptype').value === 'resin';
    await put('/jobs/' + j.id, {
      title: el('m-title').value,
      description: el('m-desc').value,
      client_id: +el('m-client').value,
      quantity: +el('m-qty').value,
      print_type: el('m-ptype').value,
      printer_id: +el('m-printer').value || null,
      filament_id: +el('m-filament').value || null,
      grams_used: r ? null : (el('m-grams').value || null),
      ml_used:    r ? (el('m-ml').value || null) : null,
      print_hours: el('m-hours').value || null,
      price_final: el('m-price').value || null,
      layer_current: el('m-lc').value || null,
      layer_total: el('m-lt').value || null,
      eta: el('m-eta').value || null,
      notes_admin: el('m-notes').value,
    });
    closeModal(); viewJob(j.id);
  }}]);
}

window.toggleEditJobType = () => {
  const isResin = el('m-ptype').value === 'resin';
  el('fg-grams').style.display = isResin ? 'none' : '';
  el('fg-ml').style.display    = isResin ? '' : 'none';
  el('m-mat-label').textContent = isResin ? 'Résine' : 'Filament';
  const filtered = (window._editJobFilaments || []).filter(f => (f.print_type||'fdm') === (isResin ? 'resin' : 'fdm'));
  el('m-filament').innerHTML = `<option value="">—</option>` +
    filtered.map(f => `<option value="${f.id}">${esc(f.material)} ${esc(f.color)}</option>`).join('');
};

// ── MODAL: change status ──────────────────────────────────────
function modalStatus(j) {
  const statuses = ['draft','queued','printing','done','picked_up','cancelled'];
  openModal('Changer le statut', `
    <div class="form-group"><label>Nouveau statut</label>
      <select id="m-status">
        ${statuses.map(s=>`<option value="${s}"${s===j.status?' selected':''}>${STATUS_LABELS[s]}</option>`).join('')}
      </select>
    </div>
    <div class="form-group"><label>Message (optionnel)</label>
      <input id="m-msg" placeholder="Message visible par le client">
    </div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Appliquer',cls:'btn-warning',click:async()=>{
    await patch('/jobs/' + j.id + '/status', { status: el('m-status').value, message: el('m-msg').value || null });
    closeModal(); viewJob(j.id);
  }}]);
}

// ── CLIENTS ───────────────────────────────────────────────────
async function viewClients() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const clients = await get('/clients');
    html('view', `
      <div class="page-title">
        Clients
        <button class="btn btn-primary" id="new-client-btn">+ Nouveau client</button>
      </div>
      <div class="table-wrap"><table>
        <tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Dernier login</th><th></th></tr>
        ${clients.map(c=>`<tr>
          <td>${esc(c.name)}</td>
          <td>${c.email ? esc(c.email) : '<span class="badge badge-draft" style="font-size:9px">Pas de compte</span>'}</td>
          <td>${c.role}</td>
          <td>${fmt(c.last_login)}</td>
          <td>
            <button class="btn btn-sm btn-ghost" onclick="editClient(${c.id},'${esc(c.name)}','${esc(c.email??'')}')">Modifier</button>
            ${c.role==='client'?`<button class="btn btn-sm btn-danger" onclick="deleteClient(${c.id})">Supprimer</button>`:''}
          </td>
        </tr>`).join('')}
      </table></div>
    `);
    el('new-client-btn').addEventListener('click', () => modalNewClient());
  } catch(e) { html('view', errBox(e)); }
}

async function viewClient(id) { location.hash = '#clients'; }

function modalNewClient() {
  openModal('Nouveau compte', `
    <div class="form-group"><label>Nom</label><input id="m-name"></div>
    <div class="form-group"><label>Email <span style="font-weight:400;font-size:11px;color:var(--muted)">(optionnel)</span></label><input type="email" id="m-email" placeholder="Laisser vide pour créer sans accès"></div>
    <div class="form-group"><label>Mot de passe <span style="font-weight:400;font-size:11px;color:var(--muted)">(optionnel)</span></label><input type="password" id="m-pass" placeholder="Min. 8 caractères — laisser vide"></div>
    <div class="form-group"><label>Rôle</label>
      <select id="m-role"><option value="client">Client</option><option value="admin">Admin</option></select>
    </div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Créer',cls:'btn-primary',click:async()=>{
    await post('/auth/register', { name:el('m-name').value, email:el('m-email').value, password:el('m-pass').value, role:el('m-role').value });
    closeModal(); viewClients();
  }}]);
}

window.editClient = (id, name, email) => {
  const noEmail = !email;
  openModal('Modifier client', `
    ${noEmail ? '<div style="background:#bfdbfe;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px">Ce client n\'a pas encore d\'accès à la plateforme. Définissez un email et un mot de passe pour l\'activer.</div>' : ''}
    <div class="form-group"><label>Nom</label><input id="m-name" value="${esc(name)}"></div>
    <div class="form-group"><label>Email${noEmail ? ' <span style="font-weight:400;font-size:11px;color:var(--muted)">(optionnel)</span>' : ''}</label><input type="email" id="m-email" value="${esc(email)}" placeholder="${noEmail ? 'Définir un email pour activer le compte' : ''}"></div>
    <div class="form-group"><label>${noEmail ? 'Mot de passe (optionnel)' : 'Nouveau mot de passe (vide = inchangé)'}</label><input type="password" id="m-pass" placeholder="Min. 8 caractères"></div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Enregistrer',cls:'btn-primary',click:async()=>{
    const b = { name:el('m-name').value, email:el('m-email').value };
    if (el('m-pass').value) b.password = el('m-pass').value;
    await put('/clients/' + id, b);
    closeModal(); viewClients();
  }}]);
};

window.deleteClient = async id => {
  if (!confirm('Supprimer ce client ?')) return;
  await del('/clients/' + id);
  viewClients();
};

// ── PRINTERS ──────────────────────────────────────────────────
async function viewPrinters() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const printers = await get('/printers');
    html('view', `
      <div class="page-title">
        Imprimantes
        <button class="btn btn-primary" id="new-printer-btn">+ Ajouter</button>
      </div>
      <div class="table-wrap"><table>
        <tr><th>Nom</th><th>Active</th><th>Notes</th><th></th></tr>
        ${printers.map(p=>`<tr>
          <td>${esc(p.name)}</td>
          <td>${p.active ? '✅' : '❌'}</td>
          <td>${esc(p.notes??'')}</td>
          <td>
            <button class="btn btn-sm btn-ghost" onclick="editPrinter(${p.id},'${esc(p.name)}',${p.active},'${esc(p.notes??'')}')">Modifier</button>
            <button class="btn btn-sm btn-danger" onclick="deletePrinter(${p.id})">Supprimer</button>
          </td>
        </tr>`).join('')}
      </table></div>
    `);
    el('new-printer-btn').addEventListener('click', () => printerForm(null));
  } catch(e) { html('view', errBox(e)); }
}

function printerForm(p) {
  openModal(p ? 'Modifier imprimante' : 'Nouvelle imprimante', `
    <div class="form-group"><label>Nom</label><input id="m-name" value="${esc(p?.name??'')}"></div>
    <div class="form-group"><label>Notes</label><textarea id="m-notes">${esc(p?.notes??'')}</textarea></div>
    <div class="form-group"><label><input type="checkbox" id="m-active" ${!p||p.active?'checked':''}> Active</label></div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Enregistrer',cls:'btn-primary',click:async()=>{
    const b = { name:el('m-name').value, notes:el('m-notes').value, active:el('m-active').checked?1:0 };
    p ? await put('/printers/'+p.id, b) : await post('/printers', b);
    closeModal(); viewPrinters();
  }}]);
}

window.editPrinter = (id, name, active, notes) => printerForm({ id, name, active, notes });
window.deletePrinter = async id => {
  if (!confirm('Supprimer cette imprimante ?')) return;
  await del('/printers/' + id); viewPrinters();
};

// ── FILAMENTS ─────────────────────────────────────────────────
async function viewFilaments() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const filaments = await get('/filaments');
    html('view', `
      <div class="page-title">
        Matériaux
        <button class="btn btn-primary" id="new-fil-btn">+ Ajouter</button>
      </div>
      <div class="table-wrap"><table>
        <tr><th>Type</th><th>Matière</th><th>Couleur</th><th>Marque</th><th>Prix</th><th>Stock</th><th></th></tr>
        ${filaments.map(f=>`<tr>
          <td>${f.print_type === 'resin'
            ? '<span class="badge badge-printing" style="font-size:10px">Résine</span>'
            : '<span style="font-size:11px;color:var(--muted)">FDM</span>'}</td>
          <td>${esc(f.material)}</td>
          <td>${colorDot(f.color_hex)}${esc(f.color)}</td>
          <td>${esc(f.brand??'')}</td>
          <td>${f.print_type === 'resin' ? money(f.price_per_litre)+'/L' : money(f.price_per_kg)+'/kg'}</td>
          <td style="color:${f.stock_grams<200?'var(--warning)':'inherit'}">${f.stock_grams}${f.print_type === 'resin' ? 'ml' : 'g'}</td>
          <td>
            <button class="btn btn-sm btn-ghost" onclick='editFilament(${JSON.stringify(f)})'>Modifier</button>
            <button class="btn btn-sm btn-danger" onclick="deleteFilament(${f.id})">✕</button>
          </td>
        </tr>`).join('')}
      </table></div>
    `);
    el('new-fil-btn').addEventListener('click', () => filamentForm(null));
  } catch(e) { html('view', errBox(e)); }
}

const FDM_MATS   = ['PLA','PETG','ABS','ASA','TPU','Nylon','PC','HIPS','PVA'];
const RESIN_MATS = ['Standard','ABS-Like','Flexible','Water-Washable','Plant-Based','Engineering'];

function filamentForm(f) {
  const isResin = f?.print_type === 'resin';
  const matOpts = list => list.map(m => `<option${f?.material===m?' selected':''}>${m}</option>`).join('');
  openModal(f ? 'Modifier matériau' : 'Nouveau matériau', `
    <div class="form-group"><label>Type d'impression</label>
      <select id="m-ptype" onchange="toggleFilamentType()">
        <option value="fdm"${!isResin?' selected':''}>FDM (filament)</option>
        <option value="resin"${isResin?' selected':''}>Résine</option>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Matière</label>
        <select id="m-mat">${isResin ? matOpts(RESIN_MATS) : matOpts(FDM_MATS)}</select>
      </div>
      <div class="form-group"><label>Couleur</label><input id="m-color" value="${esc(f?.color??'')}"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>Couleur hex</label><input type="color" id="m-hex" value="${f?.color_hex??'#888888'}"></div>
      <div class="form-group"><label>Marque</label><input id="m-brand" value="${esc(f?.brand??'')}"></div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label id="fg-price-label">${isResin ? 'Prix/L (€)' : 'Prix/kg (€)'}</label>
        <input type="number" step="0.01" id="m-price" value="${isResin ? (f?.price_per_litre??'') : (f?.price_per_kg??'')}">
      </div>
      <div class="form-group">
        <label id="fg-stock-label">${isResin ? 'Stock (ml)' : 'Stock (g)'}</label>
        <input type="number" id="m-stock" value="${f?.stock_grams??'0'}">
      </div>
    </div>
    <div class="form-group"><label><input type="checkbox" id="m-active" ${!f||f.active?'checked':''}> Actif</label></div>
  `, [{label:'Annuler',cls:'btn-ghost',click:closeModal},{label:'Enregistrer',cls:'btn-primary',click:async()=>{
    const r = el('m-ptype').value === 'resin';
    const b = {
      print_type: el('m-ptype').value,
      material: el('m-mat').value, color: el('m-color').value,
      color_hex: el('m-hex').value, brand: el('m-brand').value,
      price_per_kg:     r ? null : +el('m-price').value,
      price_per_litre:  r ? +el('m-price').value : null,
      stock_grams: +el('m-stock').value,
      active: el('m-active').checked ? 1 : 0,
    };
    f ? await put('/filaments/'+f.id, b) : await post('/filaments', b);
    closeModal(); viewFilaments();
  }}]);
}

window.toggleFilamentType = () => {
  const isResin = el('m-ptype').value === 'resin';
  el('m-mat').innerHTML = (isResin ? RESIN_MATS : FDM_MATS).map(m => `<option>${m}</option>`).join('');
  el('fg-price-label').textContent = isResin ? 'Prix/L (€)' : 'Prix/kg (€)';
  el('fg-stock-label').textContent = isResin ? 'Stock (ml)' : 'Stock (g)';
  el('m-price').value = '';
};

window.editFilament = f => filamentForm(f);
window.deleteFilament = async id => {
  if (!confirm('Supprimer ce filament ?')) return;
  await del('/filaments/' + id); viewFilaments();
};

// ── SETTINGS ──────────────────────────────────────────────────
async function viewSettings() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const s = await get('/settings');
    html('view', `
      <div class="page-title">Paramètres</div>
      <div class="card" style="max-width:540px">
        <h2>Général</h2>
        <div class="form-group"><label>Nom de l'application</label><input id="s-name" value="${esc(s.app_name??'Print3D')}"></div>
        <div class="form-group"><label>Taux horaire (€/h)</label><input type="number" step="0.01" id="s-rate" value="${esc(s.hourly_rate??'0.80')}"></div>
        <div class="form-group"><label>Email de contact</label><input type="email" id="s-email" value="${esc(s.contact_email??'')}"></div>
        <div class="form-group"><label><input type="checkbox" id="s-notify" ${s.notify_on_status==='1'?'checked':''}> Notifier le client par email à chaque changement de statut</label></div>
        <button class="btn btn-primary" id="s-save">Enregistrer</button>
      </div>
      <div class="card" style="max-width:540px">
        <h2>🖨 Imprimante résine (Elegoo Saturn 4 Ultra)</h2>
        <div class="form-group">
          <label>IP de l'imprimante</label>
          <div style="display:flex;gap:8px">
            <input id="s-printer-ip" value="${esc(s.printer_ip??'')}" placeholder="192.168.0.124" style="flex:1">
            <button class="btn btn-ghost btn-sm" id="s-probe-btn">Tester</button>
          </div>
        </div>
        <div id="probe-result"></div>
        <button class="btn btn-primary" id="s-save-printer">Enregistrer l'IP</button>
      </div>
    `);

    el('s-save').addEventListener('click', async () => {
      await post('/settings', {
        app_name: el('s-name').value,
        hourly_rate: el('s-rate').value,
        contact_email: el('s-email').value,
        notify_on_status: el('s-notify').checked ? '1' : '0',
      });
      el('s-save').textContent = 'Enregistré ✓';
      setTimeout(() => { el('s-save').textContent = 'Enregistrer'; }, 1500);
    });

    el('s-save-printer').addEventListener('click', async () => {
      await post('/settings', { printer_ip: el('s-printer-ip').value.trim() });
      el('s-save-printer').textContent = 'Enregistré ✓';
      setTimeout(() => { el('s-save-printer').textContent = "Enregistrer l'IP"; }, 1500);
    });

    el('s-probe-btn').addEventListener('click', async () => {
      const ip = el('s-printer-ip').value.trim();
      if (ip) await post('/settings', { printer_ip: ip });
      el('probe-result').innerHTML = '<p style="color:var(--muted);font-size:12px">Test en cours…</p>';
      try {
        const r = await get('/monitor/probe');
        const working = Object.entries(r.candidates).find(([,v]) => v.ok && v.json);
        if (working) {
          el('probe-result').innerHTML = `
            <div class="alert" style="background:#86efac;border:2px solid #000;margin-top:8px;font-size:12px">
              ✅ Connecté — endpoint : <code>${working[0]}</code><br>
              <details style="margin-top:6px"><summary>Réponse brute</summary>
              <pre style="font-size:11px;overflow:auto;max-height:150px">${esc(JSON.stringify(working[1].json, null, 2))}</pre></details>
            </div>`;
        } else {
          el('probe-result').innerHTML = `
            <div class="alert alert-err" style="margin-top:8px;font-size:12px">
              ❌ Aucun endpoint Chitu V3 n'a répondu. Vérifie que l'imprimante est allumée et sur le même réseau.
              <details style="margin-top:6px"><summary>Détails</summary>
              <pre style="font-size:11px;overflow:auto;max-height:120px">${esc(JSON.stringify(r, null, 2))}</pre></details>
            </div>`;
        }
      } catch(e) { el('probe-result').innerHTML = `<div class="alert alert-err" style="margin-top:8px">${esc(e.message)}</div>`; }
    });
  } catch(e) { html('view', errBox(e)); }
}

// ── STATS ─────────────────────────────────────────────────────
async function viewStats() {
  html('view', '<div class="empty">Chargement…</div>');
  try {
    const s = await get('/stats');

    const maxCA = Math.max(...s.monthly.map(m => m.ca), 1);
    const barChart = (data, key, color, fmt_) => data.map(m => `
      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:0">
        <div style="width:100%;background:#e5e7eb;border:1px solid #000;border-radius:2px 2px 0 0;height:80px;display:flex;align-items:flex-end">
          <div style="width:100%;background:${color};height:${Math.round((m[key]/maxCA)*80)}px;min-height:${m[key]?2:0}px"></div>
        </div>
        <div style="font-size:10px;font-weight:700">${fmt_(m[key])}</div>
        <div style="font-size:9px;color:var(--muted);text-align:center">${esc(m.month)}</div>
      </div>`).join('');

    html('view', `
      <div class="page-title">Statistiques</div>
      <div class="card">
        <h2>CA mensuel (12 derniers mois)</h2>
        <div style="display:flex;gap:4px;align-items:flex-end;overflow-x:auto;padding-bottom:4px">
          ${s.monthly.map(m => `
          <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:40px">
            <div style="width:100%;background:#e5e7eb;border:1px solid #000;border-radius:2px 2px 0 0;height:80px;display:flex;align-items:flex-end">
              <div style="width:100%;background:var(--primary);height:${Math.round((m.ca/maxCA)*80)}px;min-height:${m.ca?2:0}px"></div>
            </div>
            <div style="font-size:10px;font-weight:700">${money(m.ca)}</div>
            <div style="font-size:9px;color:var(--muted);text-align:center">${esc(m.month)}</div>
          </div>`).join('')}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
          <h2>Paiements</h2>
          <div style="display:flex;gap:16px;align-items:center">
            <div style="font-size:28px;font-weight:700;color:#22c55e">${money(s.payments.total_paid_amount)}</div>
            <div style="font-size:13px;color:var(--muted)">${s.payments.paid_count} jobs payés</div>
          </div>
          <div style="margin-top:8px;font-size:13px;color:#ef4444">Non payé : ${money(s.payments.unpaid_amount)} (${s.payments.unpaid_count} jobs)</div>
        </div>
        <div class="card">
          <h2>Jobs par statut</h2>
          ${s.by_status.map(r=>`
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
            <span>${badge(r.status)}</span><strong>${r.n}</strong>
          </div>`).join('')}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
          <h2>Matériaux utilisés</h2>
          <div class="table-wrap"><table>
            <tr><th>Matière</th><th>Type</th><th>Couleur</th><th>Qté</th><th>Jobs</th></tr>
            ${s.materials.map(m=>`<tr>
              <td>${esc(m.material)}</td>
              <td><span class="badge" style="font-size:9px">${m.print_type}</span></td>
              <td>${colorDot(m.color_hex)}${esc(m.color)}</td>
              <td>${m.print_type==='resin' ? m.total_qty+'ml' : m.total_qty+'g'}</td>
              <td>${m.job_count}</td>
            </tr>`).join('')}
          </table></div>
        </div>
        <div class="card">
          <h2>Top 5 clients</h2>
          <div class="table-wrap"><table>
            <tr><th>Client</th><th>Jobs</th><th>CA</th></tr>
            ${s.top_clients.map(c=>`<tr>
              <td>${esc(c.name)}</td>
              <td>${c.job_count}</td>
              <td>${money(c.revenue)}</td>
            </tr>`).join('')}
          </table></div>
        </div>
      </div>
    `);
  } catch(e) { html('view', errBox(e)); }
}

// ── Modal helper ──────────────────────────────────────────────
function openModal(title, bodyHtml, actions) {
  el('modal-title').textContent = title;
  html('modal-body', bodyHtml);
  html('modal-actions', actions.map(a =>
    `<button class="btn ${a.cls}" data-action="${a.label}">${a.label}</button>`
  ).join(''));
  actions.forEach(a => {
    el('modal-actions').querySelector(`[data-action="${a.label}"]`).addEventListener('click', async () => {
      try { await a.click(); }
      catch(e) { alert(e.message); }
    });
  });
  el('modal-overlay').classList.add('open');
}

function closeModal() { el('modal-overlay').classList.remove('open'); }
el('modal-overlay').addEventListener('click', e => { if (e.target === el('modal-overlay')) closeModal(); });

function errBox(e) {
  return `<div class="alert alert-err">Erreur : ${esc(e.message)}</div>`;
}

// ── Monitor temps réel (Chitu V3) ────────────────────────────
let _monitorInterval = null;

function stopMonitor() {
  if (_monitorInterval) { clearInterval(_monitorInterval); _monitorInterval = null; }
}

function fmtTime(sec) {
  if (!sec) return '—';
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
  return h > 0 ? `${h}h${String(m).padStart(2,'0')}` : `${m}m`;
}

const CHITU_STATUS = {
  idle:'Idle', homing:'Homing', printing:'Impression',
  paused:'En pause', stopping:'Arrêt', complete:'Terminé', error:'Erreur', unknown:'—'
};

async function startMonitor(jobId) {
  stopMonitor();
  const box = el('monitor-box');
  if (!box) return;

  async function tick() {
    try {
      // sync vers DB
      const d = await post(`/monitor/${jobId}`, {});
      if (!d.synced) { box.innerHTML = monitorIdleHtml(d.reason); return; }
      const m = d.data;
      box.innerHTML = monitorLiveHtml(m);
    } catch(e) {
      box.innerHTML = `<div style="color:var(--muted);font-size:12px">⚠️ ${esc(e.message)}</div>`;
    }
  }

  await tick();
  _monitorInterval = setInterval(tick, 5000);
}

function monitorLiveHtml(m) {
  const pct = m.progress_pct || 0;
  const statusColor = m.status === 'printing' ? '#facc15' : m.status === 'error' ? '#fca5a5' : '#e5e5e5';
  return `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
      <span style="background:${statusColor};border:var(--bw) solid var(--border);padding:3px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">
        ${CHITU_STATUS[m.status] ?? m.status}
      </span>
      ${m.filename ? `<span style="font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(m.filename)}</span>` : ''}
    </div>
    <div class="progress-wrap" style="height:20px;margin-bottom:10px">
      <div class="progress-bar" style="width:${pct}%"></div>
    </div>
    <div class="monitor-stats">
      <div style="text-align:center;border:var(--bw) solid var(--border);padding:10px">
        <div style="font-size:22px;font-weight:700">${pct}%</div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Progression</div>
      </div>
      <div style="text-align:center;border:var(--bw) solid var(--border);padding:10px">
        <div style="font-size:22px;font-weight:700">${m.layer_current||'—'}<span style="font-size:12px;color:var(--muted)">/${m.layer_total||'?'}</span></div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Couches</div>
      </div>
      <div style="text-align:center;border:var(--bw) solid var(--border);padding:10px">
        <div style="font-size:22px;font-weight:700">${fmtTime(m.remain_sec)}</div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)">Restant</div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--muted);text-align:right">
      Temps écoulé : ${fmtTime(m.elapsed_sec)} · Sync auto toutes les 5s
    </div>
  `;
}

function monitorIdleHtml(reason) {
  return `<div style="color:var(--muted);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">⏸ ${esc(reason||'Imprimante idle')}</div>`;
}

// ── STL Viewer (Three.js) ─────────────────────────────────────
let stlRenderer = null, stlAnimId = null;

window.openStl = (url, name) => {
  el('stl-modal-title').textContent = name;
  el('stl-modal').classList.add('open');

  if (stlAnimId) cancelAnimationFrame(stlAnimId);
  if (stlRenderer) { stlRenderer.dispose(); stlRenderer = null; }

  const wrap    = el('stl-viewer-wrap');
  const canvas  = el('stl-canvas');
  const W = wrap.clientWidth, H = wrap.clientHeight;

  const scene    = new THREE.Scene();
  scene.background = new THREE.Color(0x12141e);

  const camera = new THREE.PerspectiveCamera(45, W/H, 0.1, 10000);
  camera.position.set(0, 80, 200);

  stlRenderer = new THREE.WebGLRenderer({ canvas, antialias: true });
  stlRenderer.setPixelRatio(window.devicePixelRatio);
  stlRenderer.setSize(W, H);

  const controls = new THREE.OrbitControls(camera, canvas);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;

  // Lights
  scene.add(new THREE.AmbientLight(0xffffff, 0.5));
  const dir1 = new THREE.DirectionalLight(0xffffff, 1.2);
  dir1.position.set(1, 2, 3);
  scene.add(dir1);
  const dir2 = new THREE.DirectionalLight(0x8888ff, 0.4);
  dir2.position.set(-2, -1, -1);
  scene.add(dir2);

  // Grid
  const grid = new THREE.GridHelper(400, 20, 0x2e3348, 0x2e3348);
  scene.add(grid);

  const loader = new THREE.STLLoader();

  // Chargement via fetch + parse direct (évite les problèmes de type MIME sur les blob URLs)
  const hdrs = {};
  if (token()) hdrs['Authorization'] = 'Bearer ' + token();
  fetch(url, { headers: hdrs })
    .then(r => r.ok ? r.arrayBuffer() : Promise.reject(new Error('HTTP ' + r.status)))
    .then(buf => {
      const geo = loader.parse(buf);
      geo.computeVertexNormals();
      geo.center();
      const mat  = new THREE.MeshPhongMaterial({ color: 0x4ecca3, specular: 0x222244, shininess: 60 });
      const mesh = new THREE.Mesh(geo, mat);

      // Auto-scale to fit
      const box = new THREE.Box3().setFromObject(mesh);
      const sz  = box.getSize(new THREE.Vector3());
      const max = Math.max(sz.x, sz.y, sz.z);
      const scale = 150 / max;
      mesh.scale.setScalar(scale);
      mesh.position.y = (sz.y * scale) / 2;

      scene.add(mesh);
      camera.lookAt(0, (sz.y * scale)/2, 0);
      controls.target.set(0, (sz.y * scale)/2, 0);
      controls.update();
    })
    .catch(e => { el('stl-modal-title').textContent = name + ' (' + (e.message || 'erreur') + ')'; });

  const animate = () => {
    stlAnimId = requestAnimationFrame(animate);
    controls.update();
    stlRenderer.render(scene, camera);
  };
  animate();

  const onResize = () => {
    const W2 = wrap.clientWidth, H2 = wrap.clientHeight;
    camera.aspect = W2/H2;
    camera.updateProjectionMatrix();
    stlRenderer.setSize(W2, H2);
  };
  window.addEventListener('resize', onResize);
  el('stl-close')._cleanup = () => window.removeEventListener('resize', onResize);
};

el('stl-close').addEventListener('click', () => {
  el('stl-modal').classList.remove('open');
  if (stlAnimId) cancelAnimationFrame(stlAnimId);
  if (stlRenderer) { stlRenderer.dispose(); stlRenderer = null; }
  if (el('stl-close')._cleanup) el('stl-close')._cleanup();
});
el('stl-modal').addEventListener('click', e => { if (e.target === el('stl-modal')) el('stl-close').click(); });

// ── Page de suivi public (/track/{token}) ────────────────────
async function showTrackingPage(trackingToken) {
  const STATUS_LABELS = {
    queued:'En file d\'attente', printing:'En cours d\'impression',
    done:'Prêt à récupérer', picked_up:'Récupéré', cancelled:'Annulé'
  };
  const STATUS_COLORS = {
    queued:'var(--muted)', printing:'var(--primary)', done:'#22c55e',
    picked_up:'var(--primary)', cancelled:'#ef4444'
  };
  const STATUS_PCT = { queued:10, printing:60, done:100, picked_up:100, cancelled:0 };

  document.body.innerHTML = `<div style="max-width:560px;margin:40px auto;padding:20px;font-family:Inter,system-ui,sans-serif">
    <div style="font-size:22px;font-weight:700;margin-bottom:24px">🖨 Print3D — Suivi de commande</div>
    <div id="track-content" style="color:var(--muted)">Chargement…</div>
  </div>`;

  try {
    const res = await fetch(`/api/track/${encodeURIComponent(trackingToken)}`);
    const json = await res.json();
    if (!json.ok || !json.data) {
      document.getElementById('track-content').innerHTML =
        '<div style="color:#ef4444;font-weight:600">Lien de suivi invalide ou expiré.</div>';
      return;
    }
    const j = json.data;
    const pct = STATUS_PCT[j.status] || 0;
    const label = STATUS_LABELS[j.status] || j.status;
    const color = STATUS_COLORS[j.status] || 'var(--muted)';

    document.getElementById('track-content').innerHTML = `
      <div style="border:2px solid #000;border-radius:4px;padding:20px;margin-bottom:16px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:4px">${esc(j.ref)}</div>
        <div style="font-size:20px;font-weight:700;margin-bottom:16px">${esc(j.title)}</div>
        <div style="font-size:16px;font-weight:600;color:${color};margin-bottom:12px">● ${esc(label)}</div>
        ${pct > 0 ? `
        <div style="height:8px;background:#e5e7eb;border:1px solid #000;border-radius:99px;overflow:hidden;margin-bottom:16px">
          <div style="height:100%;width:${pct}%;background:${color};transition:width .5s"></div>
        </div>` : ''}
        ${j.price_final ? `<div style="font-size:14px;color:var(--muted)">Prix : <strong>${esc(String(j.price_final))} €</strong></div>` : ''}
      </div>
      ${j.photos && j.photos.length ? `
      <div style="margin-bottom:16px">
        <div style="font-weight:600;margin-bottom:8px">Photos</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px">
          ${j.photos.map(p=>`<img src="${esc(p.url)}" alt="${esc(p.filename)}" loading="lazy"
            style="width:100%;aspect-ratio:1;object-fit:cover;border:2px solid #000;border-radius:4px">`).join('')}
        </div>
      </div>` : ''}
      ${j.events && j.events.length ? `
      <div>
        <div style="font-weight:600;margin-bottom:8px">Historique</div>
        ${j.events.map(ev=>`
        <div style="display:flex;gap:12px;margin-bottom:8px;font-size:13px">
          <div style="color:var(--muted);white-space:nowrap">${esc(ev.created_at ? new Date(ev.created_at).toLocaleString('fr-BE') : '')}</div>
          <div><strong>${esc(STATUS_LABELS[ev.status]||ev.status)}</strong>${ev.message?` — ${esc(ev.message)}`:''}</div>
        </div>`).join('')}
      </div>` : ''}
    `;
  } catch(e) {
    document.getElementById('track-content').innerHTML = `<div style="color:#ef4444">Erreur : ${esc(e.message)}</div>`;
  }
}

window.copyTrackingLink = (token) => {
  const url = `${location.origin}/track/${token}`;
  navigator.clipboard.writeText(url).then(
    () => { alert('Lien copié : ' + url); },
    () => { prompt('Copier ce lien :', url); }
  );
};

// ── Boot ──────────────────────────────────────────────────────

// Thème : appliquer avant le premier rendu pour éviter le flash
applyTheme(localStorage.getItem('p3d_theme') || 'light');
el('theme-toggle').addEventListener('click', () => {
  const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  applyTheme(next);
  localStorage.setItem('p3d_theme', next);
});

(async () => {
  // Détecter une URL /track/{token} avant la vérification du login
  const trackMatch = location.pathname.match(/^\/track\/([a-f0-9]{32})$/i);
  if (trackMatch) { showTrackingPage(trackMatch[1]); return; }

  if (token()) {
    try {
      _user = await get('/auth/me');
      startApp();
      return;
    } catch(e) { clearAuth(); }
  }
  initLogin();
})();
