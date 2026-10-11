(function (Drupal, drupalSettings, once) {
  
  Drupal.behaviors.simpleNavBlock = {
    attach: function (context) {
      once('simpleNavInit', '#svContainer', context).forEach(function (container) {

        function getFileIcon(ext) {
          const icons = {
            pdf:  { label: 'PDF',  color: '#e74c3c' },
            doc:  { label: 'DOC',  color: '#2980b9' },
            docx: { label: 'DOC',  color: '#2980b9' },
            xls:  { label: 'XLS',  color: '#27ae60' },
            xlsx: { label: 'XLS',  color: '#27ae60' },
            ppt:  { label: 'PPT',  color: '#e67e22' },
            pptx: { label: 'PPT',  color: '#e67e22' },
            zip:  { label: 'ZIP',  color: '#8e44ad' },
            mp4:  { label: 'MP4',  color: '#1abc9c' },
            mp3:  { label: 'MP3',  color: '#1abc9c' },
            jpg:  { label: 'IMG',  color: '#f39c12' },
            jpeg: { label: 'IMG',  color: '#f39c12' },
            png:  { label: 'IMG',  color: '#f39c12' },
          };
          const def = icons[ext?.toLowerCase()] ?? { label: ext?.toUpperCase() || '?', color: '#95a5a6' };
          return `<span class="gd-file-ext" style="background:${def.color}">${def.label}</span>`;
        }

        function formatFolderName(name) {
          if (!name) return '';
          const cleaned = name.replace(/_/g, ' ');
          return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
        }

        const settings   = drupalSettings.gestion_documentaire.simple_nav;
        const searchUrl  = settings.search_url;
        const langPrefix = drupalSettings.path?.currentLanguage
  ? drupalSettings.path.currentLanguage + '/'
  : '';
const basePath = (drupalSettings.path?.baseUrl || '/') + langPrefix;

        
        const rootIdChar = settings.id_char_version;
        const rootLabel  = settings.root_label || rootIdChar;
        
        const perPage     = 20;
        let currentPage   = 0;
        let currentIdChar = rootIdChar;
        let lastData      = [];
        
        let selectedLangs = new Set();
        let selectedExts  = new Set();
        
        let breadcrumbStack = [{ id_char: rootIdChar, label: formatFolderName(rootLabel) }];
        let breadcrumbStackAction = [{ id_char: rootIdChar, label: rootLabel }];
        
        const list       = container.querySelector('#svList');
        const pageInfo   = container.querySelector('#svPageInfo');
        const prevBtn    = container.querySelector('#svPrev');
        const nextBtn    = container.querySelector('#svNext');
        const searchInp  = container.querySelector('#svSearch');
        const breadcrumb = container.querySelector('#svBreadcrumb');
        const extBar     = container.querySelector('#svExtBar');
        const langBtn    = container.querySelector('#svLangBtn');
        
        // ── URL helpers ──────────────────────────────────────────────────
        function urlUpdate(idChar) {
          const type = idChar.startsWith('taxonomy_') ? 'taxonomy' : 'media';
          return `${basePath}gestion_documentaire/update/${type}/${encodeURIComponent(idChar)}`;
        }
        
        function urlDelete(idChar) {
          const type = idChar.startsWith('taxonomy_') ? 'taxonomy' : 'media';
          return `${basePath}gestion_documentaire/delete/${type}/${encodeURIComponent(idChar)}`;
        }
        
        function urlAddMedia(idChar) {
          return `${basePath}gestion_documentaire/add/media/${encodeURIComponent(idChar)}`;
        }
        
        function urlAddTaxonomy(idChar) {
          return `${basePath}gestion_documentaire/add/taxonomy/${encodeURIComponent(idChar)}`;
        }
        
        // ── Fermer popups au clic extérieur ─────────────────────────────
        document.addEventListener('click', function (e) {
          if (!e.target.closest('.sv-info-btn') && !e.target.closest('.sv-popup')) {
            document.querySelectorAll('.sv-popup').forEach(p => p.remove());
          }
          if (!e.target.closest('#svLangBtn') && !e.target.closest('#svLangPopup')) {
            document.getElementById('svLangPopup')?.remove();
          }
        });
        
        // ── Bouton Add toolbar ───────────────────────────────────────────
        // ── Bouton Add toolbar ───────────────────────────────────────────
function renderAddBtn() {
  const toolbar = container.querySelector('.sv-toolbar');
  if (!toolbar) return;

  toolbar.querySelectorAll('.sv-add-dropdown-wrap').forEach(e => e.remove());

  // Vérif permission héritée du parent (calculée côté PHP)
  const settings = (drupalSettings.gestion_documentaire || {}).simple_nav || {};
  const canAdd = parseInt(settings.is_admin) === 1 || parseInt(settings.can_edit) === 1;
  if (!canAdd) return;

  const currentCrumb = breadcrumbStackAction[breadcrumbStackAction.length - 1];
  const currentName  = encodeURIComponent(currentCrumb.label);
  const addUrl       = `${basePath}gestion_documentaire/dossier/${currentName}/ajouter-document`;

  const wrap = document.createElement('div');
  wrap.className = 'sv-add-dropdown-wrap';
  wrap.style.cssText = 'flex-shrink:0;';

  const btn = document.createElement('a');
  btn.href      = addUrl;
  btn.textContent = Drupal.t('Add document');
  btn.style.cssText = `
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 14px;border:1px solid #1a73e8;border-radius:4px;
    background:#1a73e8;color:#fff;cursor:pointer;
    font-size:14px;font-weight:500;text-decoration:none;white-space:nowrap;
  `;

  wrap.appendChild(btn);
  toolbar.appendChild(wrap);
}
        
        // ── Breadcrumb ───────────────────────────────────────────────────
        function renderBreadcrumb() {
          breadcrumb.innerHTML = '';
          breadcrumbStack.forEach(function (crumb, idx) {
            const isLast = idx === breadcrumbStack.length - 1;
            if (isLast) {
              const span = document.createElement('span');
              span.className = 'sv-crumb sv-crumb--current';
              span.textContent = crumb.label;
              breadcrumb.appendChild(span);
            } else {
              const a = document.createElement('a');
              a.className = 'sv-crumb sv-crumb--link';
              a.href = '#';
              a.textContent = crumb.label;
              a.dataset.idx = idx;
              a.addEventListener('click', function (e) {
                e.preventDefault();
                const targetIdx = parseInt(this.dataset.idx);
                breadcrumbStack = breadcrumbStack.slice(0, targetIdx + 1);
                currentIdChar   = breadcrumbStack[targetIdx].id_char;
                currentPage     = 0;
                searchInp.value = '';
                fetchResults();
              });
              breadcrumb.appendChild(a);
              const sep = document.createElement('span');
              sep.className = 'sv-crumb-sep';
              sep.textContent = '/';
              breadcrumb.appendChild(sep);
            }
          });
        }
        
        // ── Barre extensions ─────────────────────────────────────────────
        function renderExtBar(extList) {
          if (!extBar) return;
          extBar.innerHTML = '';
          if (!extList || !extList.length) {
            extBar.style.display = 'none';
            return;
          }
          extBar.style.display = 'flex';
          
          const allBtn = document.createElement('button');
          allBtn.className = 'sv-ext-btn' + (selectedExts.size === 0 ? ' sv-ext-btn--active' : '');
          allBtn.textContent = Drupal.t('All');
          allBtn.addEventListener('click', function () {
            selectedExts.clear();
            currentPage = 0;
            fetchResults();
          });
          extBar.appendChild(allBtn);
          
          extList.forEach(function (ext) {
            const btn = document.createElement('button');
            btn.className = 'sv-ext-btn' + (selectedExts.has(ext) ? ' sv-ext-btn--active' : '');
            btn.textContent = ext.toUpperCase();
            btn.dataset.ext = ext;
            btn.addEventListener('click', function () {
              if (selectedExts.has(ext)) selectedExts.delete(ext);
              else                       selectedExts.add(ext);
              currentPage = 0;
              fetchResults();
            });
            extBar.appendChild(btn);
          });
        }
        
        // ── Popup langues ────────────────────────────────────────────────
        function openLangPopup(langList) {
          document.getElementById('svLangPopup')?.remove();
          
          const popup = document.createElement('div');
          popup.id        = 'svLangPopup';
          popup.className = 'sv-lang-popup';
          
          const title = document.createElement('div');
          title.className   = 'sv-lang-popup-title';
          title.textContent = Drupal.t('Filter by language');
          popup.appendChild(title);
          
          const grid = document.createElement('div');
          grid.className = 'sv-lang-grid';
          
          langList.forEach(function (lang) {
            const label = document.createElement('label');
            label.className = 'sv-lang-item';
            
            const cb = document.createElement('input');
            cb.type    = 'checkbox';
            cb.value   = lang;
            cb.checked = selectedLangs.has(lang);
            cb.addEventListener('change', function () {
              if (this.checked) selectedLangs.add(lang);
              else              selectedLangs.delete(lang);
              currentPage = 0;
              fetchResults();
              updateLangBtn();
            });
            
            const flag = document.createElement('img');
            flag.className = 'sv-flag-img sv-flag-lg';
            flag.src       = `/img/common/flags/flag_${lang}.png`;
            flag.alt       = lang;
            flag.title     = lang.toUpperCase();
            
            const lbl = document.createElement('span');
            lbl.textContent = lang.toUpperCase();
            
            label.appendChild(cb);
            label.appendChild(flag);
            label.appendChild(lbl);
            grid.appendChild(label);
          });
          
          popup.appendChild(grid);
          
          const clearBtn = document.createElement('button');
          clearBtn.className   = 'sv-lang-clear';
          clearBtn.textContent = Drupal.t('Clear selection');
          clearBtn.addEventListener('click', function () {
            selectedLangs.clear();
            popup.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
            currentPage = 0;
            fetchResults();
            updateLangBtn();
          });
          popup.appendChild(clearBtn);
          
          document.body.appendChild(popup);
          
          const btnRect = langBtn.getBoundingClientRect();
          popup.style.position = 'absolute';
          popup.style.top      = (btnRect.bottom + window.scrollY + 6) + 'px';
          popup.style.left     = Math.max(8, btnRect.right - 280) + 'px';
        }
        
        function updateLangBtn() {
          if (!langBtn) return;
          if (selectedLangs.size > 0) {
            langBtn.classList.add('sv-lang-btn--active');
            langBtn.innerHTML = '🌐 <span class="sv-lang-count">' + selectedLangs.size + '</span>';
          } else {
            langBtn.classList.remove('sv-lang-btn--active');
            langBtn.innerHTML = '🌐';
          }
        }
        
        // ── Fetch ────────────────────────────────────────────────────────
        function fetchResults() {
          list.innerHTML = `<div class="sv-loading">${Drupal.t('Loading...')}</div>`;
          
          const params = new URLSearchParams({
            search:   searchInp.value,
            page:     currentPage,
            per_page: perPage,
          });
          
          if (selectedExts.size > 0)  params.set('ext',  Array.from(selectedExts).join(','));
          if (selectedLangs.size > 0) params.set('lang', Array.from(selectedLangs).join(','));
          
          fetch(`${searchUrl}?id_char_version=${encodeURIComponent(currentIdChar)}&${params}`)
          .then(r => r.json())
          .then(function (result) {
            const data     = result[0] ?? [];
            const total    = result[1] ?? 0;
            const offset   = result[2] ?? 0;
            const limit    = result[3] ?? perPage;
            const extList  = result[8] ?? [];
            const langList = result[9] ?? [];
            
            lastData = data;
            renderExtBar(extList);
            
            if (langBtn) {
              langBtn.style.display = langList.length ? 'inline-flex' : 'none';
              langBtn._langList = langList;
            }
            
            render(data);
            
            prevBtn.disabled = offset === 0;
            nextBtn.disabled = offset + limit >= total;
            pageInfo.textContent = `${currentPage + 1} / ${Math.max(1, Math.ceil(total / limit))}`;
            
            renderBreadcrumb();
            renderAddBtn();
          })
          .catch(function () {
            list.innerHTML = `<p class="sv-error">${Drupal.t('Error loading data')}</p>`;
          });
        }

        // ── Render ───────────────────────────────────────────────────────
        function render(data) {
          list.innerHTML = '';
          
          if (!data.length) {
            list.innerHTML = `<p class="sv-empty">${Drupal.t('No results found')}</p>`;
            return;
          }
          
          data.forEach(function (row) {
            const isFolder  = row.entity_type === 'taxonomy';
            const icon = row.entity_type !== 'media'
              ? '📁'
              : getFileIcon(row.file_ext);

            const idChar    = row.id_char_version || row.id_char;
            const canEdit   = parseInt(row.can_edit)   === 1;
            const canDelete = parseInt(row.can_delete) === 1;
            const canAdd    = parseInt(row.can_edit)   === 1;
            
            const item = document.createElement('div');
            item.className = 'sv-item';
            
            if (isFolder) {
              
              const btn = document.createElement('button');
              btn.className = 'sv-folder-btn';
              btn.innerHTML = `
                <span class="sv-icon">${icon}</span>
                <span class="sv-name">${escHtml(formatFolderName(row.name))}</span>
                <span class="sv-folder-arrow">›</span>
              `;
              btn.addEventListener('click', function () {
                breadcrumbStack.push({ id_char: idChar, label: formatFolderName(row.name) });
                currentIdChar   = idChar;
                currentPage     = 0;
                searchInp.value = '';
                fetchResults();
              });
              item.appendChild(btn);
              
            } else {
              
              const created  = row.created ? formatDate(row.created) : '—';
              const changed  = row.changed ? formatDate(row.changed) : '—';
              const lang     = row.lang    || '';
              const ext      = row.file_ext;
              
              const flagHtml = lang ? `<span class="sv-flags">${langFlags(lang)}</span>` : '';
              
              const infoData = {
                [Drupal.t('Filename')]:          row.display_name || '—',
                [Drupal.t('Language')]:          lang ? langFlagsHtml(lang) : '—',
                [Drupal.t('Size')]:              row.filesize ? formatSize(row.filesize) : '—',
                [Drupal.t('Created')]:           created,
                [Drupal.t('Last modification')]: changed,
              };

              const dlHtml = row.url_download
                ? `<a href="${row.url_download}" class="sv-dl-btn" title="${Drupal.t('Download')}" target="_blank" rel="noopener">↓</a>`
                : '';
              
              item.innerHTML = `
                <span class="sv-icon">${icon}</span>
                <a href="${row.url_view}" class="sv-name" title="${escHtml(row.name)}" target="_blank" rel="noopener">${escHtml(row.display_name)}</a>
                ${flagHtml}
                ${dlHtml}
                <div class="sv-actions sv-actions--inline"></div>
                <button class="sv-info-btn" aria-label="${Drupal.t('Info')}">?</button>
              `;
              
              const actions = item.querySelector('.sv-actions--inline');
              
              if (canEdit) {
                const editBtn = document.createElement('a');
                editBtn.className = 'sv-action-btn sv-action-btn--edit';
                editBtn.title     = Drupal.t('Edit');
                editBtn.href      = row.url_edit;
                editBtn.innerHTML = '✏️';
                actions.appendChild(editBtn);
              }
              
              if (canDelete) {
                const delBtn = document.createElement('a');
                delBtn.className = 'sv-action-btn sv-action-btn--delete';
                delBtn.title     = Drupal.t('Delete');
                delBtn.href      = row.url_delete;
                delBtn.innerHTML = '🗑️';
                delBtn.addEventListener('click', function (e) {
                  e.preventDefault();
                  openDeleteConfirm(row, row.url_delete);
                });
                actions.appendChild(delBtn);
              }
              
              // Info popup (toggle si déjà ouvert par CE bouton)
              item.querySelector('.sv-info-btn').addEventListener('click', function (e) {
                e.stopPropagation();

                const existing = document.querySelector('.sv-popup');
                if (existing && existing._owner === this) {
                  existing.remove();
                  return;
                }

                document.querySelectorAll('.sv-popup').forEach(p => p.remove());

                const popup = document.createElement('div');
                popup.className = 'sv-popup';
                popup._owner    = this;

                popup.innerHTML = Object.entries(infoData).map(([label, val]) => {
                  const plainVal = String(val).replace(/<[^>]*>/g, '');
                  return `<div class="sv-popup-row">
                    <span class="sv-popup-label">${label}</span>
                    <strong class="sv-popup-val" title="${escHtml(plainVal)}">${val}</strong>
                  </div>`;
                }).join('');

                document.body.appendChild(popup);
                const btnRect = this.getBoundingClientRect();
                const popW    = 420;
                let left = btnRect.left - popW - 8;
                if (left < 8) left = btnRect.right + 8;
                popup.style.cssText = `position:absolute;left:${left}px;top:${btnRect.top + window.scrollY - 10}px;width:${popW}px`;
              });
            }
            
            list.appendChild(item);
          });
        }
        
        function formatSize(bytes) {
          bytes = parseInt(bytes);
          if (!bytes || isNaN(bytes)) return '—';
          if (bytes < 1024)        return bytes + ' ' + Drupal.t('B');
          if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' ' + Drupal.t('KB');
          return (bytes / (1024 * 1024)).toFixed(1) + ' ' + Drupal.t('MB');
        }

        // ── Popup confirmation suppression ───────────────────────────────
        function openDeleteConfirm(row, deleteUrl) {
          document.querySelectorAll('.sv-popup-overlay').forEach(p => p.remove());
        
          const overlay = document.createElement('div');
          overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9998;display:flex;align-items:center;justify-content:center;';
        
          const popup = document.createElement('div');
          popup.style.cssText = 'background:#fff;border-radius:8px;padding:24px;min-width:min(320px, calc(100vw - 32px));max-width:420px;width:calc(100vw - 32px);box-shadow:0 8px 32px rgba(0,0,0,.25);z-index:9999;position:relative;';
          popup.innerHTML = `
            <div style="font-weight:600;font-size:15px;margin-bottom:12px;">${Drupal.t('Confirm deletion')}</div>
            <p style="font-size:14px;color:#444;margin-bottom:20px;line-height:1.5;">
              ${Drupal.t('Delete')} <strong>${escHtml(row.name)}</strong> ?<br>
              <small style="color:#888;">${Drupal.t('This action is irreversible.')}</small>
            </p>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
              <button class="sv-btn-cancel" style="padding:7px 18px;border-radius:4px;border:1px solid #ddd;background:#f5f5f5;cursor:pointer;font-size:14px;">${Drupal.t('Cancel')}</button>
              <a href="${deleteUrl}" style="padding:7px 18px;border-radius:4px;background:#d32f2f;color:#fff;text-decoration:none;font-size:14px;font-weight:500;display:inline-flex;align-items:center;">${Drupal.t('Delete')}</a>
            </div>
          `;
        
          overlay.appendChild(popup);
          document.body.appendChild(overlay);
        
          overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
          popup.querySelector('.sv-btn-cancel').addEventListener('click', () => overlay.remove());
        }
        
        // ── Helpers ──────────────────────────────────────────────────────
        function formatDate(str) {
          if (!str || str === '1970-01-01 01:00:00') return '—';
          const d = new Date(str.replace(' ', 'T'));
          if (isNaN(d)) return str;
          return d.toLocaleDateString() + ' '
            + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        
        function langFlags(langStr) {
          if (!langStr) return '';
          const priority = ['fr','uk','de','it','es','ru','zh'];
          return langStr.split(/[,\s]+/)
            .map(l => l.trim().toLowerCase())
            .filter(Boolean)
            .sort(function(a, b) {
              const pa = priority.indexOf(a) !== -1 ? priority.indexOf(a) : 999;
              const pb = priority.indexOf(b) !== -1 ? priority.indexOf(b) : 999;
              if (pa !== pb) return pa - pb;
              return a.localeCompare(b);
            })
            .map(l => `<img class="sv-flag-img" src="/img/common/flags/flag_${l}.png" alt="${l}" title="${l.toUpperCase()}">`)
            .join('');
        }
        
        function langFlagsHtml(langStr) {
          return langFlags(langStr) || langStr;
        }
        
        function escHtml(str) {
          return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        }
        
        function debounce(fn, delay) {
          let t;
          return function () { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), delay); };
        }
        
        // ── Events ───────────────────────────────────────────────────────
        prevBtn.addEventListener('click', function () {
          if (currentPage > 0) { currentPage--; fetchResults(); }
        });
        nextBtn.addEventListener('click', function () {
          currentPage++; fetchResults();
        });
        searchInp.addEventListener('input', debounce(function () {
          currentPage = 0; fetchResults();
        }, 300));
        
        if (langBtn) {
          langBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const existing = document.getElementById('svLangPopup');
            if (existing) { existing.remove(); return; }
            openLangPopup(langBtn._langList || []);
          });
        }
        
        // ── Init ─────────────────────────────────────────────────────────
        fetchResults();
      });
    }
  };
  
})(Drupal, drupalSettings, once);