(function ($, Drupal, drupalSettings, once) {
  
  Drupal.behaviors.homeBlock = {
    attach: function (context, settings) {
      $(once('homeBlockInit', '#resultsTable', document)).each(function () {
        
        let currentPage       = 0;
        const perPage         = 10;
        const tbody           = document.querySelector('#resultsTable tbody');
        const id_char_version = drupalSettings.gestion_documentaire.home.id_char_version;
        const searchUrl       = drupalSettings.gestion_documentaire.home.search_url;
        
        const searchModeIndicator = document.getElementById('searchModeIndicator');
        const authorSelect        = document.getElementById('authorSelect');
        const revisionSelect      = document.getElementById('revisionSelect');
        const gdExtBar  = document.getElementById('gdExtBar');
        const gdLangBtn = document.getElementById('gdLangBtn');
        
        let selectedExts  = new Set();
        let selectedLangs = new Set();
        let lastIsAdmin   = false;
        
        // ── Fermer popup langues au clic extérieur ─────────────────────
        document.addEventListener('click', function (e) {
          if (!e.target.closest('#gdLangBtn') && !e.target.closest('#gdLangPopup')) {
            document.getElementById('gdLangPopup')?.remove();
          }
        });
        
        // ── Selects auteurs/révisionneurs ──────────────────────────────
        function updateSelects(authorsList, revisionUsersList) {
          const currentAuthor   = authorSelect.value;
          const currentRevision = revisionSelect.value;
          
          authorSelect.innerHTML   = '<option value="">' + Drupal.t('All author') + '</option>';
          revisionSelect.innerHTML = '<option value="">' + Drupal.t('All modification') + '</option>';
          
          (authorsList || []).forEach(author => {
            if (!author || !author.trim()) return;
            const opt = document.createElement('option');
            opt.value = author; opt.textContent = author;
            if (author === currentAuthor) opt.selected = true;
            authorSelect.appendChild(opt);
          });
          
          (revisionUsersList || []).forEach(user => {
            if (!user || !user.trim()) return;
            const opt = document.createElement('option');
            opt.value = user; opt.textContent = user;
            if (user === currentRevision) opt.selected = true;
            revisionSelect.appendChild(opt);
          });
        }
        
        
        function formatSize(bytes) {
          bytes = Number.parseInt(bytes);
          if (!bytes || Number.isNaN(bytes)) return '—';
          if (bytes < 1024)        return bytes + ' o';
          if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
          return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
        }
        
        
        // ── Barre extensions ───────────────────────────────────────────
        function renderExtBar(extList) {
          if (!gdExtBar) return;
          gdExtBar.innerHTML = '';
          if (!extList || !extList.length) return;
          
          const allBtn = document.createElement('button');
          allBtn.className   = 'gd-ext-btn' + (selectedExts.size === 0 ? ' gd-ext-btn--active' : '');
          allBtn.textContent = Drupal.t('All');
          allBtn.addEventListener('click', function () {
            selectedExts.clear();
            currentPage = 0;
            fetchResults();
          });
          gdExtBar.appendChild(allBtn);
          
          extList.forEach(function (ext) {
            const btn = document.createElement('button');
            btn.className   = 'gd-ext-btn' + (selectedExts.has(ext) ? ' gd-ext-btn--active' : '');
            btn.textContent = ext.toUpperCase();
            btn.addEventListener('click', function () {
              if (selectedExts.has(ext)) selectedExts.delete(ext);
              else                       selectedExts.add(ext);
              currentPage = 0;
              fetchResults();
            });
            gdExtBar.appendChild(btn);
          });
        }
        
        // ── Popup langues ──────────────────────────────────────────────
        function openLangPopup(langList) {
          document.getElementById('gdLangPopup')?.remove();
          
          const popup = document.createElement('div');
          popup.id        = 'gdLangPopup';
          popup.className = 'gd-lang-popup';
          
          const title = document.createElement('div');
          title.className   = 'gd-lang-popup-title';
          title.textContent = Drupal.t('Filter by language');
          popup.appendChild(title);
          
          const grid = document.createElement('div');
          grid.className = 'gd-lang-grid';
          
          langList.forEach(function (lang) {
            const label = document.createElement('label');
            label.className = 'gd-lang-item';
            
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
            flag.className = 'gd-flag-img';
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
          clearBtn.className   = 'gd-lang-clear';
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
          const btnRect = gdLangBtn.getBoundingClientRect();
          popup.style.position = 'absolute';
          popup.style.top      = (btnRect.bottom + window.scrollY + 6) + 'px';
          popup.style.left     = Math.max(8, btnRect.right - 300) + 'px';
        }
        
        function updateLangBtn() {
          if (!gdLangBtn) return;
          if (selectedLangs.size > 0) {
            gdLangBtn.classList.add('gd-lang-btn--active');
            gdLangBtn.innerHTML = '🌐 <span class="gd-lang-count">' + selectedLangs.size + '</span> ' + Drupal.t('Languages');
          } else {
            gdLangBtn.classList.remove('gd-lang-btn--active');
            gdLangBtn.innerHTML = '🌐 ' + Drupal.t('Languages');
          }
        }
        
        // ── Format helpers ─────────────────────────────────────────────
        function formatDate(str) {
          if (!str || str === '1970-01-01 01:00:00') return '—';
          const d = new Date(str.replace(' ', 'T'));
          if (Number.isNaN(d)) return str;
          return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        
        function langFlagsHtml(langStr) {
          if (!langStr) return '—';
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
          .map(l => `<img style="width:20px;height:14px;object-fit:cover;border:0.5px solid #e0e0e0;border-radius:2px;vertical-align:middle;" src="/img/common/flags/flag_${l}.png" alt="${l}" title="${l.toUpperCase()}">`)
          .join(' ');
        }
        
        function hasActiveFilters() {
          return !!(
            document.getElementById('searchInput').value ||
            authorSelect.value ||
            revisionSelect.value ||
            document.getElementById('createdInput').value ||
            document.getElementById('changedInput').value ||
            selectedExts.size > 0 ||
            selectedLangs.size > 0
            );
          }
          
          function debounce(func, delay) {
            let t;
            return function () {
              clearTimeout(t);
              t = setTimeout(() => func.apply(this, arguments), delay);
            };
          }
          
          // ── Fetch ──────────────────────────────────────────────────────
          function fetchResults() {
            if (!id_char_version || !searchUrl) return;
            
            searchModeIndicator.classList.toggle('active', hasActiveFilters());
            
            const params = new URLSearchParams({
              search:             document.getElementById('searchInput').value,
              author_name:        authorSelect.value,
              revision_user_name: revisionSelect.value,
              created:            document.getElementById('createdInput').value,
              changed:            document.getElementById('changedInput').value,
              page:               currentPage,
              per_page:           perPage,
            });
            
            if (selectedExts.size > 0)  params.set('ext',  Array.from(selectedExts).join(','));
            if (selectedLangs.size > 0) params.set('lang', Array.from(selectedLangs).join(','));
            
            fetch(`${searchUrl}?id_char_version=${id_char_version}&${params.toString()}`)
            .then(res => res.json())
            .then(result => {
              const data         = result[0] || [];
              const total        = result[1] || 0;
              const offset       = result[2] || 0;
              const limit        = result[3] || perPage;
              const is_admin     = result[5];
              const authorsList  = result[6] || [];
              const revisionList = result[7] || [];
              const extList      = result[8] || [];
              const langList     = result[9] || [];
              
              lastIsAdmin = is_admin;
              
              updateSelects(authorsList, revisionList);
              renderExtBar(extList);
              
              if (gdLangBtn) {
                gdLangBtn.style.display = langList.length ? 'inline-flex' : 'none';
                gdLangBtn._langList = langList;
              }
              
              document.querySelector('#nextPage').disabled = (offset + limit >= total);
              document.querySelector('#prevPage').disabled = (offset === 0);
              
              renderRows(data, is_admin);
              
              const nbPages = Math.max(1, Math.ceil(total / perPage));
              document.getElementById('pageInfo').textContent = `Page ${currentPage + 1}/${nbPages}`;
            })
            .catch(error => {
              console.error('Erreur:', error);
              tbody.innerHTML = `
              <tr>
              <td colspan="10" class="gd-empty-state">
              <div class="gd-empty-state-icon">⚠️</div>
              <p class="gd-empty-state-text">${Drupal.t('Error loading data')}</p>
              </td>
              </tr>
              `;
            });
          }
          
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
          
          // ── Render lignes ──────────────────────────────────────────────
          function renderRows(data, is_admin) {
            tbody.innerHTML = '';
            
            if (!data.length) {
              tbody.innerHTML = `
              <tr>
              <td colspan="10" class="gd-empty-state">
              <div class="gd-empty-state-icon">📭</div>
              <p class="gd-empty-state-text">${Drupal.t('No results found')}</p>
              </td>
              </tr>
              `;
              return;
            }
            
            data.forEach(row => {
              const tr        = document.createElement('tr');
              const icon = row.entity_type !== 'media'
              ? '📁'
              : getFileIcon(row.file_ext);
              const typeLabel = row.file_ext;
              const flagsHtml = row.lang ? langFlagsHtml(row.lang) : '—';
              
              const categoryCell = row.url_category
              ? `<a href="${row.url_category}" class="gd-name-link">${row.type || '—'}</a>`
              : (row.type || '—');
              
              let actions = '<div class="gd-actions">';
              if (row.can_edit == 1) {
                actions += `<a href="${row.url_edit}" class="gd-action-link gd-action-edit">✏️ ${Drupal.t('Edit')}</a>`;
                if (row.entity_type === 'media') {
                  actions += `<a href="${row.url_duplicate}" class="gd-action-link gd-action-duplicate">🗐 ${Drupal.t('Duplicate')}</a>`;
                }
              }
              if ((row.can_delete == 1 && row.entity_type === 'taxonomy' && is_admin) ||
              (row.can_delete == 1 && row.entity_type === 'media')) {
                actions += `<a href="${row.url_delete}" class="gd-action-link gd-action-delete"
                onclick="return confirm(Drupal.t('Delete @type \u00AB @name \u00BB ?', {'@type': '${typeLabel}', '@name': '${row.name}'}));">🗑 ${Drupal.t('Delete')}</a>`;
              }
              actions += '</div>';
              var target='';
              if(row.entity_type=='media'){
                target = 'target="_blank';
              }
              tr.innerHTML = `
              <td><span class="gd-icon" title="${typeLabel}">${icon}</span></td>
              <td>
              ${
                row.entity_type === 'media'
                ? `<span class="gd-version-badge ${row.publish == 1 ? 'gd-version-badge--active' : 'gd-version-badge--archived'}" title="v${row.version}">v${row.version}</span>`
                : ''
              }
              </td>
              <td><a href="${row.url_view}" class="gd-name-link" ${target} title="${row.name}">${row.display_name}</a></td>
              <td>${categoryCell}</td>
              <td>${flagsHtml}</td>
              <td class="gd-reference" title="${row.reference || ''}">${row.reference || '—'}</td>
              <td>${formatSize(row.filesize)}</td>
              <td>${row.revision_user_name || '—'}</td>
              <td>${row.author_name || '—'}</td>
              <td>${formatDate(row.created)}</td>
              <td>${formatDate(row.changed)}</td>
              <td>${actions}</td>
              `;
              tbody.appendChild(tr);
            });
          }
          
          // ── Events ─────────────────────────────────────────────────────
          document.getElementById('prevPage').addEventListener('click', () => {
            if (currentPage > 0) { currentPage--; fetchResults(); }
          });
          document.getElementById('nextPage').addEventListener('click', () => {
            currentPage++; fetchResults();
          });
          document.getElementById('searchInput').addEventListener('input', debounce(() => {
            currentPage = 0; fetchResults();
          }, 250));
          
          ['createdInput', 'changedInput'].forEach(id => {
            document.getElementById(id).addEventListener('input', () => {
              currentPage = 0; fetchResults();
            });
          });
          
          ['authorSelect', 'revisionSelect'].forEach(id => {
            document.getElementById(id).addEventListener('change', () => {
              currentPage = 0; fetchResults();
            });
          });
          
          if (gdLangBtn) {
            gdLangBtn.addEventListener('click', function (e) {
              e.stopPropagation();
              const existing = document.getElementById('gdLangPopup');
              if (existing) { existing.remove(); return; }
              openLangPopup(gdLangBtn._langList || []);
            });
          }
          
          // ── Init ───────────────────────────────────────────────────────
          fetchResults();
        });
      }
    };
    
  })(jQuery, Drupal, drupalSettings, once);