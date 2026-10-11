(function ($) {
  'use strict';

  Drupal.behaviors.simpleUploadValidator = {
    attach: function (context) {
      console.log('[UPLOAD] attach() called | already init:', !!window._uploadValidatorInit);

      if (window._uploadValidatorInit) return;
      const $form = $('form.upload-form-container');
      console.log('[UPLOAD] form found:', $form.length);
      if (!$form.length) return;
      window._uploadValidatorInit = true;

      const knownLangs  = ['fr','uk','en','de','it','es','ru','nl','pl','cs','cz','zh','cn','ja','jp','pt','tr','ar','us','no','vn'];
      const langAliases = { jp:'ja', cz:'cs', cn:'zh', en:'uk', uk:'uk' };

      function getKnownTypes() {
        return (window.drupalSettings && drupalSettings.uploadValidator && drupalSettings.uploadValidator.types) || [];
      }

      function getTypeTidMap() {
        return (window.drupalSettings && drupalSettings.uploadValidator && drupalSettings.uploadValidator.typeTidMap) || {};
      }

      function normalizeLang(l) {
        l = String(l).toLowerCase().trim();
        return langAliases[l] || l;
      }

      let fileData     = [];
      let pendingNames = [];
      let lastKey      = '';

      function baseName(filename) {
        const ext      = (filename.match(/\.[^.]+$/) || [''])[0];
        const stem     = filename.slice(0, filename.length - ext.length);
        const stripped = stem.replace(/_\d{1,2}$/, '').replace(/_+$/, '');
        const structured = /(__[a-z]{2}|__\d+|_[a-z]{2}$|_\d{2,6}$)/i.test(stripped);
        return (structured ? stripped : stem) + ext;
      }

      function cleanName(name) {
        return String(name).replace(/__+/g, '_').replace(/_+$/, '').trim();
      }

      function parseFilename(fn) {
        const knownTypes = getKnownTypes();
        const ext  = (fn.match(/\.[^.]+$/) || [''])[0];
        let stem   = fn.slice(0, fn.length - ext.length);

        const stripped = stem.replace(/_\d{1,2}$/, '').replace(/_+$/, '');
        if (/(__[a-z]{2}|__\d+|_[a-z]{2}$|_\d{2,6}$)/i.test(stripped)) stem = stripped;

        const knownTypesLower = knownTypes.map(t => t.toLowerCase().trim());

        function classifySeg(seg) {
          const segLower = seg.toLowerCase().trim();
          const sub = seg.split(/[,+]/).map(s => s.trim()).filter(Boolean);
          const typeIdx = knownTypesLower.indexOf(segLower);
          if (typeIdx !== -1) return ['type', knownTypes[typeIdx]];
          if (sub.length && sub.every(s => /^\d{2,6}$/.test(s))) return ['refs', sub.map(s => s.padStart(6,'0'))];
          if (sub.length && sub.every(s => knownLangs.includes(s.toLowerCase()) || s.toLowerCase() in langAliases)) return ['langs', sub.map(normalizeLang)];
          return ['name', seg];
        }

        const refs = [], langs = [], names = [];
        let type = null;

        for (const dp of stem.split(/_{2,}/)) {
          if (!dp.replace(/_/g,'')) continue;
          const [kind, val] = classifySeg(dp);
          if      (kind === 'type'  && !type) { type = val; }
          else if (kind === 'refs')  { val.forEach(r => { if (!refs.includes(r))  refs.push(r);  }); }
          else if (kind === 'langs') { val.forEach(l => { if (!langs.includes(l)) langs.push(l); }); }
          else {
            const rem = [];
            const spParts = dp.split(/(?<![_])_(?![_])/);
            
            let matchedType = false;
            if (!type) {
              for (let len = spParts.length; len >= 1; len--) {
                const candidate = spParts.slice(0, len).join('_');
                const candidateLower = candidate.toLowerCase().trim();
                const typeIdx = knownTypesLower.indexOf(candidateLower);
                if (typeIdx !== -1) {
                  type = knownTypes[typeIdx];
                  spParts.slice(len).forEach(sp => {
                    if (!sp) return;
                    const [k2, v2] = classifySeg(sp);
                    if      (k2 === 'refs')  { v2.forEach(r => { if (!refs.includes(r))  refs.push(r);  }); }
                    else if (k2 === 'langs') { v2.forEach(l => { if (!langs.includes(l)) langs.push(l); }); }
                    else                     { rem.push(sp); }
                  });
                  matchedType = true;
                  break;
                }
              }
            }
          
            if (!matchedType) {
              for (const sp of spParts) {
                if (!sp) continue;
                const [k2, v2] = classifySeg(sp);
                if      (k2 === 'type'  && !type) { type = v2; }
                else if (k2 === 'refs')  { v2.forEach(r => { if (!refs.includes(r))  refs.push(r);  }); }
                else if (k2 === 'langs') { v2.forEach(l => { if (!langs.includes(l)) langs.push(l); }); }
                else                     { rem.push(sp); }
              }
            }
          
            if (rem.length) names.push(rem.join('_'));
          }
        }

        return { type, refs, langs, name: names.filter(Boolean).join('_') || null, partial: !(type && langs.length) };
      }

      function resolveFile(idx) {
        const f = fileData[idx];
        const u = f.unit || {};
        const p = f.parsed || {};
        const type     = u.type  || p.type  || getFallback('type');
        const refs     = (u.refs  && u.refs.length)  ? u.refs  : (p.refs  && p.refs.length)  ? p.refs  : getFallback('refs');
        const langs    = (u.langs && u.langs.length) ? u.langs : (p.langs && p.langs.length) ? p.langs : getFallback('langs');
        const name     = u.customName || p.name || null;
        const keepName = u.keepName || false;
        const source   = u.type ? 'unitaire' : (p.type ? (p.partial ? 'partiel' : 'nom') : 'champs');
        return { type, refs, langs, name, keepName, source };
      }

      function getFallback(field) {
        if (field === 'type')  return $('#edit-manual-type').val()?.trim() || '';
        if (field === 'langs') return ($('#edit-free-name-langs').val() || '').split(/[,+]/).map(l => normalizeLang(l)).filter(Boolean);
        if (field === 'refs')  return ($('#edit-manual-refs').val() || '').split(/[,+]/).map(s => s.replace(/\D/g,'').trim()).filter(s => s).map(s => s.padStart(6,'0'));
        return [];
      }

      function findParentTidFromType() {
        const typeTidMap = getTypeTidMap();
        for (let i = 0; i < fileData.length; i++) {
          const res = resolveFile(i);
          if (res.type) {
            const typeLower = res.type.toLowerCase().trim();
            const termData = typeTidMap[typeLower];
            if (termData && termData.tid) {
              console.log('[UPLOAD] parent_taxonomy trouvé par type →', termData.name, 'tid:', termData.tid);
              return termData.tid;
            }
          }
        }
        return '';
      }

      function updateParentTaxonomy() {
        if (fileData.length === 0) return;
        const parentTid = findParentTidFromType();
        $('#parent-taxonomy-raw').val(parentTid);
        console.log('[UPLOAD] parent_taxonomy updated → tid:', parentTid);
      }

      function allReady() {
        return fileData.length > 0 && fileData.every((_, i) => {
          const r = resolveFile(i);
          return r.type && r.langs && r.langs.length;
        });
      }

      function updateHiddenMetaFields() {
        const $form = $('form.upload-form-container');
        $form.find('input.file-meta-hidden').remove();
        updateParentTaxonomy();

        fileData.forEach(function (fd, i) {
          const res = resolveFile(i);
          const displayName = res.keepName
            ? cleanName(baseName(fd.name))
            : (res.name ? cleanName(res.name) : '');

          $('<input>').attr({ type: 'hidden', class: 'file-meta-hidden', name: 'file_meta[' + i + '][original_name]', value: fd.name }).appendTo($form);
          $('<input>').attr({ type: 'hidden', class: 'file-meta-hidden', name: 'file_meta[' + i + '][type]',          value: res.type }).appendTo($form);
          $('<input>').attr({ type: 'hidden', class: 'file-meta-hidden', name: 'file_meta[' + i + '][refs]',          value: res.refs.join(',') }).appendTo($form);
          $('<input>').attr({ type: 'hidden', class: 'file-meta-hidden', name: 'file_meta[' + i + '][langs]',         value: res.langs.join(',') }).appendTo($form);
          $('<input>').attr({ type: 'hidden', class: 'file-meta-hidden', name: 'file_meta[' + i + '][custom_name]',   value: displayName }).appendTo($form);
        });

        console.log('[UPLOAD] Hidden fields count:', $form.find('input.file-meta-hidden').length);
      }

      function renderTable() {
        const container = $('#validation-results');

        if (!fileData.length) {
          container.html('<div class="vt-box vt-empty"><span class="vt-empty-msg">Aucun fichier sélectionné</span></div>').addClass('show');
          $('#free-name-fields').slideUp(150);
          $('#submit-upload-btn').prop('disabled', true);
          return;
        }

        const hasInvalid = fileData.some((_, i) => { const r = resolveFile(i); return !r.type || !r.langs || !r.langs.length; });
        let rows = '';

        for (let i = 0; i < fileData.length; i++) {
          const f   = fileData[i];
          const res = resolveFile(i);
          const ok  = !!(res.type && res.langs && res.langs.length);
          const u   = f.unit || {};
          const p   = f.parsed || {};

          rows += `<tr class="${ok ? 'row-ok' : 'row-err'}" data-index="${i}">`;
          rows += `<td class="col-file" title="${esc(f.name)}">${esc(trunc(f.name, 34))}</td>`;
          rows += `<td>${res.type ? `<span class="chip type">${esc(res.type)}</span>` : `<span class="missing">—</span>`}</td>`;
          rows += `<td>${res.refs && res.refs.length ? res.refs.map(r => `<span class="chip ref">${esc(r)}</span>`).join('') : `<span class="opt-empty">—</span>`}</td>`;
          rows += `<td>${res.langs && res.langs.length ? res.langs.map(l => `<span class="chip lang">${esc(l)}</span>`).join('') : `<span class="missing">—</span>`}</td>`;
          const displayName = res.keepName ? cleanName(baseName(f.name)) : (res.name ? cleanName(res.name) : null);
          rows += `<td>${displayName ? `<span class="chip name-chip" title="${esc(displayName)}"> ${esc(trunc(displayName, 18))}</span>` : `<span class="opt-empty">auto</span>`}</td>`;
          rows += `<td>${ok ? `<span class="badge ok">✓</span>` : `<span class="badge err">✗</span>`}</td>`;
          rows += `<td><button type="button" class="btn-unit-edit${f._unitOpen ? ' active' : ''}" data-index="${i}">✎</button></td>`;
          rows += `</tr>`;

          const uType  = u.type       !== undefined ? u.type       : (p.type  || '');
          const uRefs  = u.refs       !== undefined ? u.refs       : (p.refs  || []);
          const uLangs = u.langs      !== undefined ? u.langs      : (p.langs || []);
          const uKeep  = u.keepName   !== undefined ? u.keepName   : false;
          const uCName = u.customName !== undefined ? u.customName : (p.name  || '');

          rows += `<tr class="unit-row" id="unit-row-${i}" style="${f._unitOpen ? '' : 'display:none'}">
            <td colspan="7"><div class="unit-form">
              <span class="unit-label">Unitaire</span>
              <input type="text" class="unit-type  vt-mini-input"       placeholder="type"          value="${esc(uType)}"            data-index="${i}">
              <input type="text" class="unit-refs  vt-mini-input mono"  placeholder="055123,047846" value="${esc(uRefs.join(','))}"   data-index="${i}">
              <input type="text" class="unit-langs vt-mini-input"       placeholder="fr, en"        value="${esc(uLangs.join(', '))}" data-index="${i}">
              <input type="text" class="unit-cname vt-mini-input"       placeholder="nom perso"     value="${esc(uCName)}"           data-index="${i}" style="width:130px">
              <label class="unit-keep-label">
                <input type="checkbox" class="unit-keep" data-index="${i}"${uKeep ? ' checked' : ''}> Conserver le nom
              </label>
              ${uKeep ? `<span class="unit-filename-preview">${esc(cleanName(baseName(f.name)))}</span>` : ''}
              <button type="button" class="btn-unit-save"  data-index="${i}">Appliquer</button>
              <button type="button" class="btn-unit-clear" data-index="${i}">↺</button>
            </div></td>
          </tr>`;
        }

        container.html(`
          <div class="vt-box">
            <div class="vt-head">
              <span>${fileData.length} fichier${fileData.length > 1 ? 's' : ''}</span>
              <span class="vt-state ${allReady() ? 'state-ok' : 'state-warn'}">
                ${allReady() ? '✓ Tout prêt' : '⚠ Type ou langue manquant'}
              </span>
            </div>
            <table class="vt-table">
              <thead>
                <tr>
                  <th>Fichier</th><th>Type</th><th>Réf.</th><th>Langue(s)</th><th>Nom</th><th></th><th></th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        `).addClass('show');

        if (fileData.length) { $('#free-name-fields').slideDown(150); } else { $('#free-name-fields').slideUp(150); }
        $('#edit-is-structured').val(hasInvalid ? 0 : 1);

        setTimeout(updateHiddenMetaFields, 100);

        // Le bouton est toujours actif — la validation se fait au clic
        $('#submit-upload-btn').prop('disabled', false);
        bindRowEvents();
      }

      function bindRowEvents() {
        const $c = $('#validation-results');

        $c.off('click','.btn-unit-edit').on('click','.btn-unit-edit', function () {
          const idx = +$(this).data('index');
          fileData[idx]._unitOpen = !fileData[idx]._unitOpen;
          renderTable();
        });

        $c.off('change','.unit-keep').on('change','.unit-keep', function () {
          const idx  = +$(this).data('index');
          const $uf  = $(`#unit-row-${idx}`).find('.unit-form');
          $uf.find('.unit-filename-preview').remove();
          if ($(this).is(':checked')) {
            $uf.append(`<span class="unit-filename-preview">${esc(cleanName(baseName(fileData[idx].name)))}</span>`);
          }
        });

        $c.off('click','.btn-unit-save').on('click','.btn-unit-save', function () {
          const idx  = +$(this).data('index');
          const $row = $(`#unit-row-${idx}`);
          fileData[idx].unit = {
            type:       $row.find('.unit-type').val().trim(),
            refs:       $row.find('.unit-refs').val().split(/[,+]/).map(s => s.replace(/\D/g,'').trim()).filter(s => s).map(s => s.padStart(6,'0')),
            langs:      $row.find('.unit-langs').val().split(/[,+]/).map(l => normalizeLang(l)).filter(Boolean),
            keepName:   $row.find('.unit-keep').is(':checked'),
            customName: $row.find('.unit-cname').val().trim(),
          };
          fileData[idx]._unitOpen = false;
          renderTable();
        });

        $c.off('click','.btn-unit-clear').on('click','.btn-unit-clear', function () {
          const idx = +$(this).data('index');
          fileData[idx].unit      = {};
          fileData[idx]._unitOpen = false;
          renderTable();
        });
      }

      function syncFromDrupalFull() {
        const names = [...pendingNames];
        $('span.file a, .file--title, .file-info a, .js-form-managed-file a').each(function () {
          const n = $(this).text().trim();
          if (n && !names.includes(n)) names.push(n);
        });
        const domNames = [];
        $('span.file a, .file--title, .file-info a, .js-form-managed-file a').each(function () { domNames.push($(this).text().trim()); });
        pendingNames = pendingNames.filter(n => domNames.includes(n));

        const seen = {}, unique = [];
        names.forEach(n => { const b = baseName(n); if (!seen[b]) { seen[b] = true; unique.push(n); } });

        const key = unique.join('|');
        if (key === lastKey) return;
        lastKey = key;

        const prev = {};
        fileData.forEach(f => { prev[baseName(f.name)] = f; });
        fileData = unique.map(name => {
          const p = prev[baseName(name)];
          return { name, parsed: parseFilename(name), unit: p ? p.unit : {}, _unitOpen: p ? p._unitOpen : false };
        });
        renderTable();
      }

      $(document).on('change', 'input[type="file"][id*="documents"]', function () {
        Array.from(this.files || []).forEach(f => { if (!pendingNames.includes(f.name)) pendingNames.push(f.name); });
        syncFromDrupalFull();
      });

      $(document).on('click', '[class*="remove"],[data-drupal-selector*="remove"]', function () {
        const removed = $(this).closest('.js-form-managed-file,[class*="file"]').find('a,.file--title').first().text().trim();
        if (removed) pendingNames = pendingNames.filter(n => n !== removed);
        setTimeout(syncFromDrupalFull, 600);
      });

      new MutationObserver(() => setTimeout(syncFromDrupalFull, 300)).observe(document.body, { childList: true, subtree: true });
      setInterval(syncFromDrupalFull, 800);

      $(document).on('input change', '#edit-manual-type,#edit-free-name-langs,#edit-manual-refs', function () {
        if (fileData.length) renderTable();
      });

      renderTable();

      // ── Popup loader ──────────────────────────────────────────────────────
      $('body').append(`
        <div id="upload-loader-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:10px;padding:32px 40px;min-width:320px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.2)">
            <div class="loader-spinner" style="width:48px;height:48px;border:5px solid #e2e8f0;border-top-color:#4299e1;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 20px"></div>
            <div id="upload-loader-steps" style="text-align:left;margin-bottom:8px"></div>
          </div>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
      `);

      const loaderSteps = [
        'Validation des fichiers…',
        'Envoi au serveur…',
        'Traitement des médias…',
        'Mise à jour de la base…',
        'Finalisation…',
      ];

      // ── Submit : validation puis loader ───────────────────────────────────
      $form.on('submit', function (e) {

        // Vérification des champs manquants
        const missing = [];
        fileData.forEach(function (fd, i) {
          const res = resolveFile(i);
          const errors = [];
          if (!res.type)                       errors.push('type');
          if (!res.langs || !res.langs.length) errors.push('language');
          if (errors.length) missing.push({ name: fd.name, errors });
        });

        if (missing.length) {
          e.preventDefault();
          e.stopImmediatePropagation();
          const rows = missing.map(m =>
            `<tr>
              <td style="padding:6px 10px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(m.name)}">${esc(trunc(m.name, 36))}</td>
              <td style="padding:6px 10px">${m.errors.map(e =>
                `<span style="background:#fed7d7;color:#c53030;padding:2px 8px;border-radius:4px;font-size:12px;margin-right:4px;font-weight:600">${e}</span>`
              ).join('')}</td>
            </tr>`
          ).join('');

          $('#upload-missing-overlay').remove();
          $('body').append(`
            <div id="upload-missing-overlay" style="display:flex;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
              <div style="background:#fff;border-radius:10px;padding:28px 32px;min-width:380px;max-width:540px;box-shadow:0 8px 32px rgba(0,0,0,.2)">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                  <span style="font-size:22px">⚠️</span>
                  <strong style="font-size:16px">Missing required fields</strong>
                </div>
                <p style="color:#718096;font-size:13px;margin:0 0 14px">
                  The following files are missing <strong>type</strong> or <strong>language</strong>.<br>
                  Please fill them in before importing.
                </p>
                <div style="max-height:260px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px">
                  <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                      <tr style="background:#f7fafc;border-bottom:1px solid #e2e8f0">
                        <th style="padding:6px 10px;text-align:left;color:#4a5568;font-weight:600">File</th>
                        <th style="padding:6px 10px;text-align:left;color:#4a5568;font-weight:600">Missing</th>
                      </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                  </table>
                </div>
                <div style="margin-top:18px;text-align:right">
                  <button id="upload-missing-close" style="background:#4299e1;color:#fff;border:none;padding:8px 22px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600">OK</button>
                </div>
              </div>
            </div>
          `);

          $('#upload-missing-close').on('click', function () {
            $('#upload-missing-overlay').remove();
          });

          return;
        }

        // Tout OK → loader
        const $overlay = $('#upload-loader-overlay').css('display', 'flex');
        const $steps   = $('#upload-loader-steps').empty();

        loaderSteps.forEach((step, i) => {
          setTimeout(() => {
            $steps.find('.ul-pending').last()
              .removeClass('ul-pending')
              .html(s => s.replace('⏳', '✅'));
            $steps.append(
              `<div class="ul-pending" style="padding:3px 0;color:#3182ce;font-weight:600">⏳ ${step}</div>`
            );
          }, i * 900);
        });
      });

      function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
      function trunc(s, n) { return s.length > n ? s.slice(0, n-1) + '…' : s; }
    }
  };

})(jQuery, Drupal);