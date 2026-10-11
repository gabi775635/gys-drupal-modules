(function ($, Drupal, once) {

  const BASE = '/extranet/fr/gestion_documentaire/stats';

  // ── État global ──────────────────────────────────────────────────────────

  const state = {
    period:         'current_month',
    date_from:      '',
    date_to:        '',
    type_filter:    '',
    // recherches
    modifiedSearch: '',
    insertedSearch: '',
    errorsSearch:   '',
    // pages courantes
    modifiedPage:   1,
    insertedPage:   1,
    topDocsPage:    1,
    topUsersPage:   1,
    errorsPage:     1,
    // per page
    modifiedPerPage: 20,
    insertedPerPage: 20,
    topDocsPerPage:  20,
    topUsersPerPage: 20,
    errorsPerPage:   20,
    // filtres tableau erreurs
    errorsStatus: '',
    errorsAction: '',
    // filtres type par tableau
    modifiedTypeFilter: '',
    insertedTypeFilter: '',
    // tri par tableau
    sorts: {
      topDocs:  { key: 'total_accesses', dir: -1 },
      topUsers: { key: 'total_accesses', dir: -1 },
      errors:   { key: 'timestamp',      dir: -1 },
      modified: { key: 'revision_count', dir: -1 },
      inserted: { key: 'created_date',   dir: -1 },
    },
  };

  // ── Helpers ──────────────────────────────────────────────────────────────

  function getFilterParams(extra = {}) {
    return new URLSearchParams({
      period:      state.period,
      date_from:   state.date_from,
      date_to:     state.date_to,
      type_filter: state.type_filter,
      ...extra,
    }).toString();
  }

  function escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }

  function tdTruncated(text, maxLength = 40, extraClass = '') {
    const safe      = escapeHtml(text || '-');
    const classAttr = extraClass ? ` class="${extraClass}"` : '';
    if (!text || text.length <= maxLength) return `<td${classAttr}>${safe}</td>`;
    return `<td${classAttr}><span class="gd-truncated" title="${safe}">${escapeHtml(text.substring(0, maxLength))}…</span></td>`;
  }

  function setLoading(sel, colspan) {
    const el = document.querySelector(sel);
    if (el) el.innerHTML = `<tr><td colspan="${colspan}" class="gd-stats-loading">Chargement…</td></tr>`;
  }

  function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function syncStateFromDOM() {
    state.date_from   = document.getElementById('date_from')?.value   || '';
    state.date_to     = document.getElementById('date_to')?.value     || '';
    state.type_filter = document.getElementById('type_filter')?.value || '';
  }

  // ── Quick picks de période ────────────────────────────────────────────────

  function initQuickPicks() {
    document.querySelectorAll('.gd-qp').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.gd-qp').forEach(b => b.classList.remove('gd-qp--active'));
        btn.classList.add('gd-qp--active');
        state.period = btn.dataset.v;

        const customGroup = document.getElementById('dateRangeGroup');
        if (state.period === 'custom') {
          if (customGroup) customGroup.style.display = 'flex';
        } else {
          if (customGroup) customGroup.style.display = 'none';
          // Calculer les dates côté JS pour l'affichage immédiat
          const [f, t] = computeDateRange(state.period);
          state.date_from = f;
          state.date_to   = t;
          const dfEl = document.getElementById('date_from');
          const dtEl = document.getElementById('date_to');
          if (dfEl) dfEl.value = f;
          if (dtEl) dtEl.value = t;
        }

        updatePeriodLabel();
      });
    });
  }

  function computeDateRange(period) {
    const now = new Date();
    const fmt  = d => d.toISOString().slice(0, 10);
    const ymd  = (y, m, d) => new Date(y, m, d);

    switch (period) {
      case 'today': {
        const s = fmt(now); return [s, s];
      }
      case 'yesterday': {
        const y = new Date(now); y.setDate(now.getDate() - 1);
        const s = fmt(y); return [s, s];
      }
      case 'last_7_days': {
        const f = new Date(now); f.setDate(now.getDate() - 6);
        return [fmt(f), fmt(now)];
      }
      case 'current_week': {
        const day = now.getDay() || 7;
        const mon = new Date(now); mon.setDate(now.getDate() - day + 1);
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        return [fmt(mon), fmt(sun)];
      }
      case 'last_week': {
        const day = now.getDay() || 7;
        const mon = new Date(now); mon.setDate(now.getDate() - day - 6);
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        return [fmt(mon), fmt(sun)];
      }
      case 'last_month': {
        const first = ymd(now.getFullYear(), now.getMonth() - 1, 1);
        const last  = ymd(now.getFullYear(), now.getMonth(), 0);
        return [fmt(first), fmt(last)];
      }
      case 'current_quarter': {
        const q     = Math.floor(now.getMonth() / 3);
        const first = ymd(now.getFullYear(), q * 3, 1);
        return [fmt(first), fmt(now)];
      }
      case 'current_year': {
        return [`${now.getFullYear()}-01-01`, `${now.getFullYear()}-12-31`];
      }
      case 'current_month':
      default: {
        const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        return [`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`, fmt(last)];
      }
    }
  }

  function updatePeriodLabel() {
    const labels = {
      today:           "aujourd'hui",
      yesterday:       'hier',
      last_7_days:     '7 derniers jours',
      current_week:    'cette semaine',
      last_week:       'semaine précédente',
      current_month:   'ce mois',
      last_month:      'mois précédent',
      current_quarter: 'ce trimestre',
      current_year:    'cette année',
      custom:          `du ${state.date_from} au ${state.date_to}`,
    };
    setText('periodLabel', labels[state.period] || 'la période');
  }

  // ── Period data ───────────────────────────────────────────────────────────

  function loadPeriodData() {
    fetch(`${BASE}/period-data?${getFilterParams()}`)
      .then(r => r.json())
      .then(data => {
        setText('totalModifiedCount',    data.modified_total     ?? 0);
        setText('totalInsertedCount',    data.inserted_total     ?? 0);
        setText('totalLangAddedCount',   data.lang_added_total   ?? 0);
        setText('totalLangRemovedCount', data.lang_removed_total ?? 0);
        setText('totalViewsCount',       data.total_views        ?? 0);
        setText('totalDownloadsCount',   data.total_downloads    ?? 0);
        setText('totalSuccessCount',     data.total_success      ?? 0);
        setText('totalErrorCount',       data.total_errors       ?? 0);
        setText('totalSuccessRate',      (data.success_rate ?? 100) + ' %');

        renderTypeStatsTable('#modifiedStatsTable tbody', data.modified_stats, 3);
        renderTypeStatsTable('#insertedStatsTable tbody', data.inserted_stats, 3);
      })
      .catch(err => console.error('[gdStats] periodData error:', err));
  }

  function renderTypeStatsTable(sel, rows, colspan) {
    const tbody = document.querySelector(sel);
    if (!tbody) return;
    if (!rows?.length) {
      tbody.innerHTML = `<tr><td colspan="${colspan}" class="gd-stats-empty">Aucune donnée sur cette période</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td>${escapeHtml(r.type || 'Non défini')}</td>
        <td><strong>${r.count}</strong></td>
        <td>${r.percent ?? 0}%</td>
      </tr>`).join('');
  }

  // ── Access data (top docs + top users + évolution) ───────────────────────

  let accessChart = null;

  function loadAccessData(docsPage = 1, usersPage = 1) {
    state.topDocsPage  = docsPage;
    state.topUsersPage = usersPage;
    setLoading('#topDocsTable tbody',  7);
    setLoading('#topUsersTable tbody', 6);

    const s = state.sorts.topDocs;
    const su = state.sorts.topUsers;

    fetch(`${BASE}/access-data?${getFilterParams({
      page:           docsPage,
      per_page:       state.topDocsPerPage,
      users_page:     usersPage,
      users_per_page: state.topUsersPerPage,
    })}`)
      .then(r => r.json())
      .then(data => {
        setText('totalUniqueUsers', data.top_users_total ?? 0);

        renderTopDocsTable(sortRows(data.top_docs, s.key, s.dir));
        renderTopUsersTable(sortRows(data.top_users, su.key, su.dir));
        renderAccessEvolution(data.evolution);

        renderPagination('topDocsPagination', data.current_page, data.total_pages, data.total_docs,
          p => loadAccessData(p, state.topUsersPage),
          pp => { state.topDocsPerPage = pp; loadAccessData(1, state.topUsersPage); }
        );
        renderPagination('topUsersPagination', data.users_current_page, data.top_users_pages, data.top_users_total,
          p => loadAccessData(state.topDocsPage, p),
          pp => { state.topUsersPerPage = pp; loadAccessData(state.topDocsPage, 1); }
        );
        setText('topDocsInfo', `${data.total_docs} résultat${data.total_docs > 1 ? 's' : ''}`);
        setText('topUsersInfo', `${data.top_users_total} résultat${data.top_users_total > 1 ? 's' : ''}`);
      })
      .catch(err => console.error('[gdStats] accessData error:', err));
  }

  function renderTopDocsTable(rows) {
    const tbody = document.querySelector('#topDocsTable tbody');
    if (!tbody) return;
    if (!rows?.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="gd-stats-empty">Aucune consultation sur cette période</td></tr>';
      return;
    }
    const search = state._topDocsSearch?.toLowerCase() || '';
    const filtered = search
      ? rows.filter(r => (r.doc_name || '').toLowerCase().includes(search) || (r.doc_type || '').toLowerCase().includes(search))
      : rows;
    tbody.innerHTML = filtered.map(r => `
      <tr>
        ${tdTruncated(r.doc_name, 50)}
        ${tdTruncated(r.doc_type, 25)}
        <td class="gd-text-center">${r.views}</td>
        <td class="gd-text-center">${r.downloads}</td>
        <td class="gd-text-center"><strong>${r.total_accesses}</strong></td>
        <td class="gd-text-center">${r.unique_users}</td>
        <td>${escapeHtml(r.last_access)}</td>
      </tr>`).join('');
  }

  function renderTopUsersTable(rows) {
    const tbody = document.querySelector('#topUsersTable tbody');
    if (!tbody) return;
    if (!rows?.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="gd-stats-empty">Aucune donnée utilisateur sur cette période</td></tr>';
      return;
    }
    const search = state._topUsersSearch?.toLowerCase() || '';
    const filtered = search
      ? rows.filter(r => (r.user_label || '').toLowerCase().includes(search))
      : rows;
    tbody.innerHTML = filtered.map(r => {
      const isAnon   = r.user_label === 'Anonyme / Non connecté';
      const rowClass = isAnon ? ' class="gd-row-anon"' : '';
      return `
        <tr${rowClass}>
          <td>${escapeHtml(r.user_label)}</td>
          <td class="gd-text-center">${r.views}</td>
          <td class="gd-text-center">${r.downloads}</td>
          <td class="gd-text-center"><strong>${r.total_accesses}</strong></td>
          <td class="gd-text-center">${r.unique_docs}</td>
          <td>${escapeHtml(r.last_access)}</td>
        </tr>`;
    }).join('');
  }

  function renderAccessEvolution(rows) {
    const canvas = document.getElementById('chartAccessEvolution');
    if (!canvas || typeof Chart === 'undefined') return;
    if (accessChart) { accessChart.destroy(); accessChart = null; }

    if (!rows?.length) return;

    accessChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: rows.map(r => r.day),
        datasets: [
          { label: 'Consultations',   data: rows.map(r => r.views),     backgroundColor: '#4e79a7' },
          { label: 'Téléchargements', data: rows.map(r => r.downloads), backgroundColor: '#f28e2b' },
        ],
      },
      options: {
        responsive: true,
        scales: { x: { stacked: false }, y: { beginAtZero: true } },
        plugins: { legend: { display: true } },
      },
    });
  }

  // ── Journal d'accès (statuts & erreurs) ──────────────────────────────────

  // Cache local pour le tri/search côté client sur la page courante
  let _errorsCache = [];

  function loadAccessLog(page = 1) {
    state.errorsPage = page;
    setLoading('#errorsTable tbody', 11);

    fetch(`${BASE}/access-log?${getFilterParams({
      page:       state.errorsPage,
      per_page:   state.errorsPerPage,
      status:     state.errorsStatus,
      action:     state.errorsAction,
      search:     state.errorsSearch,
    })}`)
      .then(r => r.json())
      .then(data => {
        setText('totalSuccessCount', data.total_success ?? 0);
        setText('totalErrorCount',   data.total_errors  ?? 0);
        setText('totalSuccessRate',  (data.success_rate ?? 100) + ' %');

        _errorsCache = data.rows || [];
        renderErrorsTable(_errorsCache);

        setText('errorsInfo', `${data.total} résultat${data.total > 1 ? 's' : ''}`);

        renderPagination('errorsPagination', data.current_page, data.total_pages, data.total,
          p => loadAccessLog(p),
          pp => { state.errorsPerPage = pp; loadAccessLog(1); }
        );
      })
      .catch(err => console.error('[gdStats] accessLog error:', err));
  }

  function renderErrorsTable(rows) {
    const tbody = document.querySelector('#errorsTable tbody');
    if (!tbody) return;
    if (!rows?.length) {
      tbody.innerHTML = '<tr><td colspan="11" class="gd-stats-empty">Aucun résultat</td></tr>';
      return;
    }

    const s = state.sorts.errors;
    const sorted = sortRows(rows, s.key, s.dir);

    tbody.innerHTML = sorted.map(r => {
      const statusBadge = r.status === 'success'
        ? `<span class="gd-badge gd-badge--success"><span class="gd-badge-dot gd-badge-dot--ok"></span>success</span>`
        : `<span class="gd-badge gd-badge--error"><span class="gd-badge-dot gd-badge-dot--err"></span>error</span>`;

      const actionBadge = r.action === 'download'
        ? `<span class="gd-badge gd-badge--download">download</span>`
        : r.action === 'error'
        ? `<span class="gd-badge gd-badge--error">error</span>`
        : `<span class="gd-badge gd-badge--view">view</span>`;

      return `<tr>
        <td style="white-space:nowrap;">${escapeHtml(r.date_formatted)}</td>
        <td>${statusBadge}</td>
        <td>${actionBadge}</td>
        <td>${r.media_id || 0}</td>
        ${tdTruncated(r.media_name, 35)}
        <td>${escapeHtml(r.doc_type)}</td>
        <td>${escapeHtml(r.lang)}</td>
        <td>${escapeHtml(r.reference)}</td>
        <td>${escapeHtml(r.tiers_label)}</td>
        ${tdTruncated(r.file_uri, 50)}
        ${tdTruncated(r.message, 55)}
      </tr>`;
    }).join('');

    updateSortIcons('errorsTable', s.key, s.dir);
  }

  // ── Références modifiées ─────────────────────────────────────────────────

  function loadModifiedReferences(page = 1) {
    state.modifiedPage = page;
    setLoading('#modifiedReferencesTable tbody', 9);

    const s = state.sorts.modified;

    fetch(`${BASE}/search-modified?${getFilterParams({
      search:      state.modifiedSearch,
      type_filter: state.modifiedTypeFilter || state.type_filter,
      page:        state.modifiedPage,
      per_page:    state.modifiedPerPage,
    })}`)
      .then(r => r.json())
      .then(data => {
        const rows = sortRows(data.references, s.key, s.dir);
        renderModifiedTable(rows);
        setText('modifiedInfo', `${data.total} résultat${data.total > 1 ? 's' : ''}`);
        renderPagination('modifiedPagination', data.current_page, data.total_pages, data.total,
          p => loadModifiedReferences(p),
          pp => { state.modifiedPerPage = pp; loadModifiedReferences(1); }
        );
        updateSortIcons('modifiedReferencesTable', s.key, s.dir);
      })
      .catch(err => console.error('[gdStats] searchModified error:', err));
  }

  function renderModifiedTable(references) {
    const tbody = document.querySelector('#modifiedReferencesTable tbody');
    if (!tbody) return;
    if (!references?.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="gd-stats-empty">Aucune référence modifiée trouvée</td></tr>';
      return;
    }
    tbody.innerHTML = references.map(ref => {
      const langsHtml = buildLangsHtml(ref.langs_added || [], ref.langs_removed || [], ref.langs_unchanged || []);
      return `
        <tr>
          ${tdTruncated(ref.reference)}
          ${tdTruncated(ref.type || '-', 30)}
          <td class="gd-langs-cell">${langsHtml}</td>
          ${tdTruncated(ref.document_name, 50)}
          ${tdTruncated(ref.author_name || 'Anonyme', 25)}
          ${tdTruncated(ref.last_editor_name || 'Anonyme', 25)}
          <td class="gd-text-center">${ref.revision_count}</td>
          <td>${escapeHtml(ref.last_modified)}</td>
          ${tdTruncated(ref.modification_dates || '-', 40, 'gd-dates-cell')}
        </tr>`;
    }).join('');
  }

  function buildLangsHtml(added, removed, unchanged) {
    if (!added.length && !removed.length && !unchanged.length) return '<span class="gd-lang-neutral">-</span>';
    const parts = [];
    unchanged.forEach(l => parts.push(`<span class="gd-lang-unchanged">${escapeHtml(l)}</span>`));
    added.forEach(l    => parts.push(`<span class="gd-lang-added">+${escapeHtml(l)}</span>`));
    removed.forEach(l  => parts.push(`<span class="gd-lang-removed">-${escapeHtml(l)}</span>`));
    return parts.join(' ');
  }

  // ── Nouvelles références ─────────────────────────────────────────────────

  function loadInsertedReferences(page = 1) {
    state.insertedPage = page;
    setLoading('#insertedReferencesTable tbody', 6);

    const s = state.sorts.inserted;

    fetch(`${BASE}/search-inserted?${getFilterParams({
      search:      state.insertedSearch,
      type_filter: state.insertedTypeFilter || state.type_filter,
      page:        state.insertedPage,
      per_page:    state.insertedPerPage,
    })}`)
      .then(r => r.json())
      .then(data => {
        const rows = sortRows(data.references, s.key, s.dir);
        renderInsertedTable(rows);
        setText('insertedInfo', `${data.total} résultat${data.total > 1 ? 's' : ''}`);
        renderPagination('insertedPagination', data.current_page, data.total_pages, data.total,
          p => loadInsertedReferences(p),
          pp => { state.insertedPerPage = pp; loadInsertedReferences(1); }
        );
        updateSortIcons('insertedReferencesTable', s.key, s.dir);
      })
      .catch(err => console.error('[gdStats] searchInserted error:', err));
  }

  function renderInsertedTable(references) {
    const tbody = document.querySelector('#insertedReferencesTable tbody');
    if (!tbody) return;
    if (!references?.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="gd-stats-empty">Aucune nouvelle référence trouvée</td></tr>';
      return;
    }
    tbody.innerHTML = references.map(ref => `
      <tr>
        ${tdTruncated(ref.reference)}
        ${tdTruncated(ref.type || '-', 30)}
        <td>${escapeHtml(ref.lang || '-')}</td>
        ${tdTruncated(ref.document_name, 50)}
        ${tdTruncated(ref.author_name || 'Anonyme', 25)}
        <td>${escapeHtml(ref.created_date)}</td>
      </tr>`).join('');
  }

  // ── Tri générique ─────────────────────────────────────────────────────────

  function sortRows(rows, key, dir) {
    if (!key || !rows?.length) return rows;
    return [...rows].sort((a, b) => {
      const av = String(a[key] ?? '');
      const bv = String(b[key] ?? '');
      return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
    });
  }

  function updateSortIcons(tableId, activeKey, dir) {
    document.querySelectorAll(`#${tableId} th[data-sort]`).forEach(th => {
      th.classList.remove('gd-sorted');
      const icon = th.querySelector('.gd-sort-icon');
      if (icon) icon.textContent = '↕';
      if (th.dataset.sort === activeKey) {
        th.classList.add('gd-sorted');
        if (icon) icon.textContent = dir > 0 ? '↑' : '↓';
      }
    });
  }

  function initSortableTable(tableId, sortKey, loadFn) {
    document.querySelectorAll(`#${tableId} th[data-sort]`).forEach(th => {
      th.addEventListener('click', () => {
        const key = th.dataset.sort;
        const s   = state.sorts[sortKey];
        if (s.key === key) s.dir *= -1;
        else { s.key = key; s.dir = -1; }
        loadFn(1);
      });
    });
  }

  // ── Pagination générique ──────────────────────────────────────────────────

  function renderPagination(containerId, currentPage, totalPages, total, onPage, onPerPage) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!totalPages || totalPages <= 1) {
      container.innerHTML = '';
      return;
    }

    // Pages à afficher
    const pages = [];
    const w = 2;
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || Math.abs(i - currentPage) <= w) {
        pages.push(i);
      } else if (pages[pages.length - 1] !== '…') {
        pages.push('…');
      }
    }

    const pageLinks = pages.map(p =>
      p === '…'
        ? `<span class="gd-pagination-dots">…</span>`
        : `<button type="button" class="gd-pagination-link${p === currentPage ? ' active' : ''}"
             data-page="${p}">${p}</button>`
    ).join('');

    container.innerHTML = `
      <div class="gd-pagination-info">${total} résultat${total > 1 ? 's' : ''} — page ${currentPage}/${totalPages}</div>
      <div class="gd-pagination-right">
        <div class="gd-pagination-controls">
          <button type="button" class="gd-pagination-link" data-page="1" ${currentPage === 1 ? 'disabled' : ''}>«</button>
          <button type="button" class="gd-pagination-link" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''}>‹</button>
          ${pageLinks}
          <button type="button" class="gd-pagination-link" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''}>›</button>
          <button type="button" class="gd-pagination-link" data-page="${totalPages}" ${currentPage === totalPages ? 'disabled' : ''}>»</button>
        </div>
        <div class="gd-pagination-jump">
          Aller à <input type="number" min="1" max="${totalPages}" value="${currentPage}">
        </div>
      </div>`;

    container.querySelectorAll('.gd-pagination-link[data-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.disabled) return;
        const p = Number.parseInt(btn.dataset.page, 10);
        if (p >= 1 && p <= totalPages) onPage(p);
      });
    });

    const jumpInput = container.querySelector('.gd-pagination-jump input');
    if (jumpInput) {
      jumpInput.addEventListener('change', () => {
        const p = Math.min(totalPages, Math.max(1, Number.parseInt(jumpInput.value, 10) || 1));
        onPage(p);
      });
    }
  }

  // ── Exports CSV (endpoint PHP) ────────────────────────────────────────────

  function exportData(action) {
    syncStateFromDOM();
    window.location.href = `${BASE}/export?${getFilterParams({ action })}`;
  }

  // ── Graphiques globaux ────────────────────────────────────────────────────

  function initCharts(data) {
    const palette = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'];
    const colors  = n => Array.from({ length: n }, (_, i) => palette[i % palette.length]);

    const configs = [
      { id: 'chartMediaType',       dataset: data.media_by_type,       type: 'doughnut', label: 'Documents' },
      { id: 'chartMediaLang',       dataset: data.media_by_lang,       type: 'doughnut', label: 'Documents' },
      { id: 'chartEvolution',       dataset: data.evolution,           type: 'bar',      label: 'Créations' },
      { id: 'chartTopReferences',   dataset: data.top_references,      type: 'bar',      label: 'Documents',  horizontal: true },
      { id: 'chartDocsByFolder',    dataset: data.documents_by_folder, type: 'bar',      label: 'Documents',  horizontal: true },
      { id: 'chartTopContributors', dataset: data.top_contributors,    type: 'bar',      label: 'Révisions',  horizontal: true },
      { id: 'chartVersions',        dataset: data.versions_stats,      type: 'bar',      label: 'Documents' },
    ];

    configs.forEach(({ id, dataset, type, label, horizontal }) => {
      const canvas = document.getElementById(id);
      if (!canvas || !dataset) return;
      new Chart(canvas, {
        type,
        data: {
          labels: dataset.labels,
          datasets: [{ label, data: dataset.data, backgroundColor: colors(dataset.data.length), borderWidth: 1 }],
        },
        options: {
          indexAxis: horizontal ? 'y' : 'x',
          responsive: true,
          plugins: { legend: { display: type === 'doughnut' } },
        },
      });
    });

    const topCanvas = document.getElementById('chartTopAccessed');
    if (topCanvas && data.top_accessed?.labels?.length) {
      new Chart(topCanvas, {
        type: 'bar',
        data: {
          labels: data.top_accessed.labels,
          datasets: [
            { label: 'Consultations',   data: data.top_accessed.views,     backgroundColor: '#4e79a7' },
            { label: 'Téléchargements', data: data.top_accessed.downloads, backgroundColor: '#f28e2b' },
          ],
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          plugins: { legend: { display: true } },
        },
      });
    }
  }

  // ── Chargement complet ────────────────────────────────────────────────────

  function loadAll() {
    state.modifiedPage = 1;
    state.insertedPage = 1;
    state.topDocsPage  = 1;
    state.topUsersPage = 1;
    state.errorsPage   = 1;

    updatePeriodLabel();
    loadPeriodData();
    loadAccessData(1, 1);
    loadAccessLog(1);
    loadModifiedReferences(1);
    loadInsertedReferences(1);
  }

  // ── Behavior Drupal ───────────────────────────────────────────────────────

  Drupal.behaviors.gdStats = {
    attach(context, settings) {

      // Graphiques globaux (une seule fois)
      once('gdStatsCharts', 'body', context).forEach(() => {
        if (typeof Chart !== 'undefined' && settings.gdStatsData) {
          initCharts(settings.gdStatsData);
        }
      });

      // Quick picks
      once('gdStatsQuickPicks', '#quickPicks', context).forEach(() => {
        initQuickPicks();
        // Init dates par défaut
        const [f, t] = computeDateRange('current_month');
        state.date_from = f; state.date_to = t;
        const dfEl = document.getElementById('date_from');
        const dtEl = document.getElementById('date_to');
        if (dfEl) dfEl.value = f;
        if (dtEl) dtEl.value = t;
      });

      // Bouton Appliquer
      once('gdStatsApply', '#applyFiltersBtn', context).forEach(btn => {
        btn.addEventListener('click', () => { syncStateFromDOM(); loadAll(); });
      });

      // Bouton Reset
      once('gdStatsReset', '#resetFiltersBtn', context).forEach(btn => {
        btn.addEventListener('click', () => {
          document.querySelectorAll('.gd-qp').forEach(b => b.classList.remove('gd-qp--active'));
          document.querySelector('.gd-qp[data-v="current_month"]')?.classList.add('gd-qp--active');
          state.period = 'current_month';
          const [f, t] = computeDateRange('current_month');
          state.date_from = f; state.date_to = t;
          const dfEl = document.getElementById('date_from');
          const dtEl = document.getElementById('date_to');
          if (dfEl) dfEl.value = f;
          if (dtEl) dtEl.value = t;
          document.getElementById('dateRangeGroup').style.display = 'none';
          const tf = document.getElementById('type_filter');
          if (tf) tf.value = '';
          state.type_filter = '';
          loadAll();
        });
      });

      // Per page — top docs
      once('gdTopDocsPerPage', '#topDocsPerPage', context).forEach(sel => {
        sel.addEventListener('change', () => {
          state.topDocsPerPage = +sel.value;
          loadAccessData(1, state.topUsersPage);
        });
      });

      // Per page — top users
      once('gdTopUsersPerPage', '#topUsersPerPage', context).forEach(sel => {
        sel.addEventListener('change', () => {
          state.topUsersPerPage = +sel.value;
          loadAccessData(state.topDocsPage, 1);
        });
      });

      // Recherche top docs
      once('gdTopDocsSearch', '#topDocsSearch', context).forEach(input => {
        input.addEventListener('input', () => {
          state._topDocsSearch = input.value;
          renderTopDocsTable([]); // re-render avec filtre
          loadAccessData(1, state.topUsersPage);
        });
      });

      // Recherche top users
      once('gdTopUsersSearch', '#topUsersSearch', context).forEach(input => {
        input.addEventListener('input', () => {
          state._topUsersSearch = input.value;
          loadAccessData(state.topDocsPage, 1);
        });
      });

      // Tri top docs
      once('gdTopDocsSort', '#topDocsTable', context).forEach(() => {
        initSortableTable('topDocsTable', 'topDocs', p => loadAccessData(p, state.topUsersPage));
      });

      // Tri top users
      once('gdTopUsersSort', '#topUsersTable', context).forEach(() => {
        initSortableTable('topUsersTable', 'topUsers', p => loadAccessData(state.topDocsPage, p));
      });

      // ── Tableau erreurs ──

      once('gdErrorsPerPage', '#errorsPerPage', context).forEach(sel => {
        sel.addEventListener('change', () => { state.errorsPerPage = +sel.value; loadAccessLog(1); });
      });

      once('gdErrorsSearch', '#errorsSearch', context).forEach(input => {
        let t;
        input.addEventListener('input', () => {
          clearTimeout(t);
          t = setTimeout(() => { state.errorsSearch = input.value; loadAccessLog(1); }, 300);
        });
      });

      once('gdErrorsStatus', '#errorsStatusFilter', context).forEach(sel => {
        sel.addEventListener('change', () => { state.errorsStatus = sel.value; loadAccessLog(1); });
      });

      once('gdErrorsAction', '#errorsActionFilter', context).forEach(sel => {
        sel.addEventListener('change', () => { state.errorsAction = sel.value; loadAccessLog(1); });
      });

      once('gdErrorsSort', '#errorsTable', context).forEach(() => {
        initSortableTable('errorsTable', 'errors', p => loadAccessLog(p));
      });

      // ── Tableau modifiées ──

      once('gdModPerPage', '#modifiedPerPage', context).forEach(sel => {
        sel.addEventListener('change', () => { state.modifiedPerPage = +sel.value; loadModifiedReferences(1); });
      });

      once('gdModSearch', '#modifiedSearchInput', context).forEach(input => {
        let t;
        input.addEventListener('input', () => {
          clearTimeout(t);
          t = setTimeout(() => { state.modifiedSearch = input.value; loadModifiedReferences(1); }, 300);
        });
        input.addEventListener('keypress', e => {
          if (e.key === 'Enter') { state.modifiedSearch = input.value; loadModifiedReferences(1); }
        });
      });

      once('gdModTypeFilter', '#modifiedTypeFilter', context).forEach(sel => {
        sel.addEventListener('change', () => { state.modifiedTypeFilter = sel.value; loadModifiedReferences(1); });
      });

      once('gdModReset', '#modifiedResetBtn', context).forEach(btn => {
        btn.addEventListener('click', () => {
          const inp = document.getElementById('modifiedSearchInput');
          const sel = document.getElementById('modifiedTypeFilter');
          if (inp) inp.value = '';
          if (sel) sel.value = '';
          state.modifiedSearch = '';
          state.modifiedTypeFilter = '';
          loadModifiedReferences(1);
        });
      });

      once('gdModSort', '#modifiedReferencesTable', context).forEach(() => {
        initSortableTable('modifiedReferencesTable', 'modified', p => loadModifiedReferences(p));
      });

      // ── Tableau insérées ──

      once('gdInsPerPage', '#insertedPerPage', context).forEach(sel => {
        sel.addEventListener('change', () => { state.insertedPerPage = +sel.value; loadInsertedReferences(1); });
      });

      once('gdInsSearch', '#insertedSearchInput', context).forEach(input => {
        let t;
        input.addEventListener('input', () => {
          clearTimeout(t);
          t = setTimeout(() => { state.insertedSearch = input.value; loadInsertedReferences(1); }, 300);
        });
        input.addEventListener('keypress', e => {
          if (e.key === 'Enter') { state.insertedSearch = input.value; loadInsertedReferences(1); }
        });
      });

      once('gdInsTypeFilter', '#insertedTypeFilter', context).forEach(sel => {
        sel.addEventListener('change', () => { state.insertedTypeFilter = sel.value; loadInsertedReferences(1); });
      });

      once('gdInsReset', '#insertedResetBtn', context).forEach(btn => {
        btn.addEventListener('click', () => {
          const inp = document.getElementById('insertedSearchInput');
          const sel = document.getElementById('insertedTypeFilter');
          if (inp) inp.value = '';
          if (sel) sel.value = '';
          state.insertedSearch = '';
          state.insertedTypeFilter = '';
          loadInsertedReferences(1);
        });
      });

      once('gdInsSort', '#insertedReferencesTable', context).forEach(() => {
        initSortableTable('insertedReferencesTable', 'inserted', p => loadInsertedReferences(p));
      });

      // ── Exports ──

      once('gdExportMod', '#exportModifiedBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('modified'));
      });
      once('gdExportIns', '#exportInsertedBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('inserted'));
      });
      once('gdExportAcc', '#exportAccessBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('access'));
      });
      once('gdExportErr', '#exportErrorsBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('errors'));
      });
      once('gdExportTopDocs', '#exportTopDocsBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('top_docs'));
      });
      once('gdExportTopUsers', '#exportTopUsersBtn', context).forEach(btn => {
        btn.addEventListener('click', () => exportData('top_users'));
      });

      // ── Init au chargement ──
      once('gdStatsInit', '#periodCards', context).forEach(() => loadAll());
    },
  };

})(jQuery, Drupal, once);