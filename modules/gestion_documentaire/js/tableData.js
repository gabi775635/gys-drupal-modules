(function ($, Drupal, once) {
  Drupal.behaviors.tableData = {
    attach: function (context, settings) {
      var tab_value_ref = [];
      
      // ===== INITIALISATION =====
      $(once("tableDataInit", "body", context)).each(function () {
        setTimeout(function () {
          initializeForm();
        }, 100);
      });
      
      // ===== LOADER GLOBAL SUBMIT =====
      function gdInitLoader() {
        if (document.getElementById('gd-submit-loader')) return;
        document.body.insertAdjacentHTML('beforeend',
        '<div id="gd-submit-loader" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:99999;align-items:center;justify-content:center;">'
        + '<div class="gd-loader-box">'
        + '<div id="gd-spinner"></div>'
        + '<p id="gd-step-label"></p>'
        + '<p id="gd-step-sub"></p>'
        + '<div class="gd-prog-track"><div id="gd-prog-bar"></div></div>'
        + '<div id="gd-steps-list"></div>'
        + '</div>'
        + '</div>'
        );
      }
      
      function gdShowLoader(step) {
        var loader = document.getElementById('gd-submit-loader');
        loader.style.display = 'flex';
        var totalSteps = 5;
        var pct = Math.round((step / totalSteps) * 100) + '%';
        var stepLabels = [
          Drupal.t('Language validation'),
          Drupal.t('Checking for existing versions to delete'),
          Drupal.t('Checking file is not already present in the system'),
          Drupal.t('Checking ref/lang combination does not already exist'),
          Drupal.t('Saving'),
        ];
        var currentLabel = stepLabels[step - 1] || '';
        document.getElementById('gd-step-label').textContent = currentLabel + '...';
        document.getElementById('gd-step-sub').textContent   = Drupal.t('Step') + ' ' + step + ' / ' + totalSteps;
        document.getElementById('gd-prog-bar').style.width   = pct;
        document.getElementById('gd-steps-list').innerHTML = stepLabels.map(function(txt, i) {
          var done = i < step - 1;
          var cur  = i === step - 1;
          var iconClass = done ? 'gd-step-icon done' : (cur ? 'gd-step-icon active' : 'gd-step-icon pending');
          var fw = cur ? 'font-weight:500;color:#111827;' : (done ? 'color:#374151;' : 'color:#9ca3af;');
          return '<div style="display:flex;align-items:center;gap:8px;font-size:13px;' + fw + '">'
          + '<span class="' + iconClass + '"></span>'
          + txt
          + '</div>';
        }).join('');
      }
      
      function gdHideLoader() {
        var loader = document.getElementById('gd-submit-loader');
        if (loader) loader.style.display = 'none';
      }
      
      // ===== SHARED INLINE STEP RENDERER =====
      function showInlineStep(labelEl, subEl, barEl, listEl, step, totalSteps, stepLabels) {
        var pct = Math.round((step / totalSteps) * 100) + '%';
        labelEl.textContent = stepLabels[step - 1] + '...';
        subEl.textContent = Drupal.t('Step') + ' ' + step + ' / ' + totalSteps;
        barEl.style.width = pct;
        listEl.innerHTML = stepLabels.map(function(txt, i) {
          var done = i < step - 1;
          var cur  = i === step - 1;
          var iconClass = done ? 'gd-step-icon done' : (cur ? 'gd-step-icon active' : 'gd-step-icon pending');
          var fw = cur ? 'font-weight:500;color:#111827;' : (done ? 'color:#374151;' : 'color:#9ca3af;');
          return '<div style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 10px;border-radius:8px;background:#f9fafb;border:1px solid #f3f4f6;' + fw + '">'
          + '<span class="' + iconClass + '"></span>'
          + txt
          + '</div>';
        }).join('');
      }
      
      // ===== POP-UP CONFIRMATION PUBLICATION =====
      function showPublishConfirmModal(onConfirm) {
        var existingModal = document.getElementById('publishConfirmModal');
        if (existingModal) existingModal.remove();
        
        var modal = document.createElement('div');
        modal.id = 'publishConfirmModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = '<div style="background:#fff;border-radius:6px;padding:24px;max-width:520px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
        + '<h3 style="margin-top:0;color:#e67e22;">📢 ' + Drupal.t('Publication confirmation') + '</h3>'
        + '<p>' + Drupal.t('You are about to publish this media.') + '</p>'
        + '<p>' + Drupal.t('It will be publicly visible. Do you confirm?') + '</p>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">'
        + '<button id="publishCancelBtn" style="padding:8px 16px;">' + Drupal.t('Cancel') + '</button>'
        + '<button id="publishConfirmBtn" style="padding:8px 16px;background:#e67e22;color:#fff;border:none;border-radius:4px;cursor:pointer;">' + Drupal.t('Confirm publication') + '</button>'
        + '</div></div>';
        document.body.appendChild(modal);
        
        document.getElementById('publishCancelBtn').addEventListener('click', function() {
          modal.remove();
          var publishedCb = document.querySelector('[name*="published"]');
          if (publishedCb) publishedCb.checked = false;
        });
        document.getElementById('publishConfirmBtn').addEventListener('click', function() {
          modal.remove();
          onConfirm();
        });
      }
      
      // ===== POP-UP DOUBLON HASH FICHIER =====
      function showHashDuplicateModal(mediaInfo, onConfirm) {
        var existingModal = document.getElementById('hashDuplicateModal');
        if (existingModal) existingModal.remove();
        
        var baseUrl = drupalSettings.path.baseUrl;
        var url = baseUrl + 'gestion_documentaire/document/modifier/' + mediaInfo.name;
        
        var modal = document.createElement('div');
        modal.id = 'hashDuplicateModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = '<div style="background:#fff;border-radius:6px;padding:24px;max-width:520px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
        + '<h3 style="margin-top:0;color:#c0392b;">⚠️ ' + Drupal.t('File already exists') + '</h3>'
        + '<p>' + Drupal.t('This file already exists in the system:') + '</p>'
        + '<ul style="margin:10px 0;padding-left:20px;">'
        + '<li style="margin-bottom:6px;"><strong>' + mediaInfo.name + '</strong>'
        + (mediaInfo.parent_taxonomy ? ' — ' + mediaInfo.parent_taxonomy : '')
        + (mediaInfo.type      ? ' | ' + mediaInfo.type      : '')
        + (mediaInfo.lang      ? ' | ' + mediaInfo.lang      : '')
        + (mediaInfo.reference ? ' | ' + mediaInfo.reference : '')
        + ' — <a href="' + url + '" target="_blank">' + Drupal.t('Edit this media') + '</a>'
        + '</li></ul>'
        + '<p>' + Drupal.t('Do you still want to continue?') + '</p>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">'
        + '<button id="hashCancelBtn" style="padding:8px 16px;">' + Drupal.t('Cancel') + '</button>'
        + '<button id="hashConfirmBtn" style="padding:8px 16px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;">' + Drupal.t('Continue anyway') + '</button>'
        + '</div></div>';
        document.body.appendChild(modal);
        
        document.getElementById('hashCancelBtn').addEventListener('click', function() {
          modal.remove();
          gdHideLoader();
        });
        document.getElementById('hashConfirmBtn').addEventListener('click', function() {
          modal.remove();
          onConfirm();
        });
      }
      
      // ===== POP-UP SUPPRESSION VERSIONS =====
      function showVersionDeletionModal(versions, onConfirm) {
        var existingModal = document.getElementById('versionDeletionModal');
        if (existingModal) existingModal.remove();
        
        var listHtml = '<ul style="margin:10px 0;padding-left:20px;">';
        versions.forEach(function(v) {
          listHtml += '<li style="margin-bottom:4px;"><strong>' + v.name + '</strong> — ' + Drupal.t('version') + ' ' + v.version + '</li>';
        });
        listHtml += '</ul>';
        
        var modal = document.createElement('div');
        modal.id = 'versionDeletionModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = '<div style="background:#fff;border-radius:6px;padding:24px;max-width:520px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
        + '<h3 style="margin-top:0;color:#c0392b;">⚠️ ' + Drupal.t('Deletion of old versions') + '</h3>'
        + '<p>' + Drupal.t('By publishing this new version, the following versions will be permanently deleted:') + '</p>'
        + listHtml
        + '<p>' + Drupal.t('This action is irreversible. Do you confirm?') + '</p>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">'
        + '<button id="versionCancelBtn" style="padding:8px 16px;">' + Drupal.t('Cancel') + '</button>'
        + '<button id="versionConfirmBtn" style="padding:8px 16px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;">' + Drupal.t('Confirm deletion') + '</button>'
        + '</div></div>';
        document.body.appendChild(modal);
        
        document.getElementById('versionCancelBtn').addEventListener('click', function() {
          modal.remove();
          gdHideLoader();
          var publishedCb = document.querySelector('[name*="published"]');
          if (publishedCb) publishedCb.checked = false;
        });
        document.getElementById('versionConfirmBtn').addEventListener('click', function() {
          modal.remove();
          onConfirm();
        });
      }
      
      // ===== MODALE CONFLIT DOUBLON =====
      function showConflictModal(message, conflictingMedia, onConfirm) {
        var existingModal = document.getElementById('conflictModal');
        if (existingModal) existingModal.remove();
        
        var baseUrl = drupalSettings.path.baseUrl;
        var linksHtml = '';
        if (conflictingMedia && conflictingMedia.length > 0) {
          linksHtml = '<ul style="margin:10px 0;padding-left:20px;">';
          conflictingMedia.forEach(function(m) {
            var url = baseUrl + 'gestion_documentaire/document/modifier/' + m.name;
            linksHtml += '<li style="margin-bottom:6px;"><strong>' + (m.name || m.id_char) + '</strong>'
            + ' (' + (m.refs || []).join(', ') + ' — ' + (m.langs || []).join(', ') + ')'
            + ' — <a href="' + url + '" target="_blank">' + Drupal.t('Edit this media') + '</a></li>';
          });
          linksHtml += '</ul>';
        }
        
        var modal = document.createElement('div');
        modal.id = 'conflictModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = '<div style="background:#fff;border-radius:6px;padding:24px;max-width:520px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">'
        + '<h3 style="margin-top:0;color:#c0392b;">⚠️ ' + Drupal.t('Duplicate detected') + '</h3>'
        + '<p style="white-space:pre-line;">' + message + '</p>'
        + linksHtml
        + '<p>' + Drupal.t('Do you still want to continue?') + '</p>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">'
        + '<button id="conflictCancelBtn" style="padding:8px 16px;">' + Drupal.t('Cancel') + '</button>'
        + '<button id="conflictConfirmBtn" style="padding:8px 16px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;">' + Drupal.t('Continue anyway') + '</button>'
        + '</div></div>';
        document.body.appendChild(modal);
        
        document.getElementById('conflictCancelBtn').addEventListener('click', function() {
          modal.remove();
          gdHideLoader();
        });
        document.getElementById('conflictConfirmBtn').addEventListener('click', function() {
          modal.remove();
          onConfirm();
        });
      }
      
      // ===== MODAL RECHERCHE CATÉGORIE =====
      function openCategorySearchModal() {
        var existingModal = document.getElementById('categorySearchModal');
        if (existingModal) existingModal.remove();
        
        var modal = document.createElement('div');
        modal.id = 'categorySearchModal';
        modal.innerHTML = '<div class="modal-header">'
        + '<h3>' + Drupal.t('Search a category') + '</h3>'
        + '<button id="closeModalBtn">&times;</button>'
        + '</div>'
        + '<div class="modal-body">'
        + '<div class="search-wrapper">'
        + '<input type="text" id="searchTextInput" placeholder="' + Drupal.t('Keyword') + '...">'
        + '<button id="doSearchBtn">' + Drupal.t('Search') + '</button>'
        + '</div>'
        + '<div id="searchResults"></div>'
        + '<div id="categorySearchLoader" style="display:none;text-align:center;padding:20px;">'
        + '<div class="spinner" style="border:4px solid #f3f3f3;border-top:4px solid #3498db;border-radius:50%;width:40px;height:40px;animation:spin 1s linear infinite;margin:0 auto;"></div>'
        + '<p style="margin-top:10px;color:#666;">' + Drupal.t('Searching...') + '</p>'
        + '</div>'
        + '</div>';
        document.body.appendChild(modal);
        
        document.getElementById('closeModalBtn').addEventListener('click', function() { modal.remove(); });
        document.getElementById('doSearchBtn').addEventListener('click', function() { performCategorySearch(); });
        document.getElementById('searchTextInput').addEventListener('keypress', function(e) { if (e.key === 'Enter') { performCategorySearch(); } });
        document.getElementById('searchTextInput').addEventListener('input', function(e) { performCategorySearch(); });
      }
      
      function performCategorySearch() {
        var keyword = document.getElementById('searchTextInput').value.trim();
        if (!keyword) return;
        var resultsDiv = document.getElementById('searchResults');
        var loaderDiv  = document.getElementById('categorySearchLoader');
        resultsDiv.style.display = 'none';
        loaderDiv.style.display  = 'block';
        fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/searchCategories?text=' + encodeURIComponent(keyword))
        .then(resp => resp.json())
        .then(data => { loaderDiv.style.display = 'none'; resultsDiv.style.display = 'block'; displaySearchResults(data); })
        .catch(err  => { loaderDiv.style.display = 'none'; resultsDiv.style.display = 'block'; resultsDiv.innerHTML = '<p style="color:red;">' + Drupal.t('Error during search') + '</p>'; });
      }
      
      function displaySearchResults(data) {
        var resultsDiv = document.getElementById('searchResults');
        if (data.length === 0) { resultsDiv.innerHTML = '<p>' + Drupal.t('No results') + '</p>'; return; }
        var html = '<ul>';
        data.forEach(item => {
          html += '<li class="result-item"><strong>' + item.famSSFam + '</strong> - ' + item.texte_fr
          + '<button class="selectCategoryBtn" data-code="' + item.famSSFam + '">' + Drupal.t('Choose') + '</button></li>';
        });
        html += '</ul>';
        resultsDiv.innerHTML = html;
        resultsDiv.querySelectorAll('.selectCategoryBtn').forEach(btn => {
          btn.addEventListener('click', function() { void addCategoryRefs(this.getAttribute('data-code')); });
        });
      }
      
      // ===== AJOUT REFS PAR CATÉGORIE (avec showInlineStep partagé) =====
      async function addCategoryRefs(code) {
        var modal = document.getElementById('categorySearchModal');
        document.getElementById('searchResults').style.display = 'none';
        
        var progressHtml = '<div id="addRefProgress" style="background:#ffffff;padding:24px;border-radius:8px;border:1px solid #e5e7eb;box-shadow:0 4px 12px rgba(0,0,0,0.1);">'
        + '<div style="width:48px;height:48px;border:3px solid #e5e7eb;border-top:3px solid #378ADD;border-radius:50%;margin:0 auto 16px;animation:gdSpin 0.8s linear infinite;"></div>'
        + '<p id="addRefStepLabel" style="font-size:15px;font-weight:500;color:#111827;margin:0 0 8px;text-align:center;"></p>'
        + '<p id="addRefStepSub" style="font-size:13px;color:#6b7280;margin:0 0 16px;text-align:center;"></p>'
        + '<div style="background:#f3f4f6;border-radius:999px;height:6px;overflow:hidden;margin-bottom:16px;">'
        + '<div id="addRefProgBar" style="height:100%;width:0%;background:#378ADD;border-radius:999px;transition:width 0.4s ease;"></div>'
        + '</div>'
        + '<div id="addRefStepsList" style="display:flex;flex-direction:column;gap:6px;"></div>'
        + '</div>';
        document.querySelector('#categorySearchModal .modal-body').insertAdjacentHTML('beforeend', progressHtml);
        
        var labelEl = document.getElementById('addRefStepLabel');
        var subEl   = document.getElementById('addRefStepSub');
        var barEl   = document.getElementById('addRefProgBar');
        var listEl  = document.getElementById('addRefStepsList');
        
        var stepLabels = [
          Drupal.t('Validating reference numbers'),
          Drupal.t('Fetching product information'),
          Drupal.t('Adding to table'),
        ];
        var totalSteps = stepLabels.length;
        function showStep(n) { showInlineStep(labelEl, subEl, barEl, listEl, n, totalSteps, stepLabels); }
        
        try {
          showStep(1);
          await new Promise(resolve => setTimeout(resolve, 300));
          var response = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/validateReferenceNumbers?ref=' + encodeURIComponent(code));
          var data = await response.json();
          var validRefs = data.references || [];
          if (validRefs.length === 0) {
            labelEl.textContent = Drupal.t('No valid reference found');
            labelEl.style.color = '#dc2626';
            setTimeout(function() { modal.remove(); }, 2000);
            return;
          }
          
          showStep(2);
          await new Promise(resolve => setTimeout(resolve, 300));
          var infoResponse = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/getInformationProduct', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ data: validRefs.join(',') }), credentials: 'same-origin'
          });
          var infoData = await infoResponse.json();
          
          showStep(3);
          await new Promise(resolve => setTimeout(resolve, 300));
          var existingRefs = new Set(tab_value_ref.map(i => i.reference));
          tab_value_ref = tab_value_ref.concat(infoData.filter(item => !existingRefs.has(item.reference)));
          renderTableRef();
          updateRefJsData();
          
          labelEl.textContent = Drupal.t('References added successfully!');
          labelEl.style.color = '#059669';
          barEl.style.width = '100%';
          setTimeout(function() { modal.remove(); }, 1500);
          
        } catch(error) {
          labelEl.textContent = Drupal.t('Error: @msg', {'@msg': error.message});
          labelEl.style.color = '#dc2626';
          console.error('addCategoryRefs error:', error);
          setTimeout(function() { modal.remove(); }, 3000);
        }
      }
      
      // ===== VÉRIFICATION HASH AU MOMENT DE L'UPLOAD =====
      function bindFileUploadCheck() {
        $(document).on('ajaxComplete', function(event, xhr, settings) {
          if (settings && settings.url && settings.url.indexOf('element_parents=file') !== -1) {
            setTimeout(function() {
              var fileField = document.querySelector('.js-form-type-managed-file');
              if (!fileField) return;
              fileField.querySelectorAll('input[type="hidden"]').forEach(function(input) {
                var val = Number.parseInt(input.value);
                if (val && val > 0 && input.name && input.name.indexOf('file') !== -1) { void checkFileHashOnUpload(val); }
              });
            }, 500);
          }
        });
      }
      
      async function checkFileHashOnUpload(fid) {
        var entityId = document.querySelector('#entity_id_js_data')?.value || '0';
        var formData = new FormData();
        formData.append('fid', fid);
        formData.append('entity_id', entityId);
        try {
          var resp  = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkFileHashDuplicate', { method: 'POST', body: formData, credentials: 'same-origin' });
          var check = await resp.json();
          if (check.status == 1 && check.media) { showHashDuplicateModal(check.media, function() {}); }
        } catch (e) { console.warn('checkFileHashDuplicate échoué :', e); }
      }
      
      // ===== AJOUT DE RÉFÉRENCES MANUELLES (avec showInlineStep partagé) =====
      async function addEntryRef() {
        var input = document.getElementById('inputDataRef');
        var valuesString = input.value.trim();
        if (!valuesString) return;
        input.value = '';
        
        // Injecter le mini-loader inline sous le champ
        var existing = document.getElementById('addRefInlineProgress');
        if (existing) existing.remove();
        var progressHtml = '<div id="addRefInlineProgress" style="margin-top:8px;background:#f9fafb;padding:16px;border-radius:8px;border:1px solid #e5e7eb;">'
        + '<p id="addRefIStepLabel" style="font-size:14px;font-weight:500;color:#111827;margin:0 0 4px;"></p>'
        + '<p id="addRefIStepSub" style="font-size:12px;color:#6b7280;margin:0 0 10px;"></p>'
        + '<div style="background:#f3f4f6;border-radius:999px;height:4px;overflow:hidden;margin-bottom:10px;">'
        + '<div id="addRefIProgBar" style="height:100%;width:0%;background:#378ADD;border-radius:999px;transition:width 0.3s ease;"></div>'
        + '</div>'
        + '<div id="addRefIStepsList" style="display:flex;flex-direction:column;gap:4px;"></div>'
        + '</div>';
        input.insertAdjacentHTML('afterend', progressHtml);
        
        var labelEl = document.getElementById('addRefIStepLabel');
        var subEl   = document.getElementById('addRefIStepSub');
        var barEl   = document.getElementById('addRefIProgBar');
        var listEl  = document.getElementById('addRefIStepsList');
        
        var stepLabels = [
          Drupal.t('Validating references'),
          Drupal.t('Checking for duplicates'),
          Drupal.t('Fetching product information'),
        ];
        var totalSteps = stepLabels.length;
        function showStep(n) { showInlineStep(labelEl, subEl, barEl, listEl, n, totalSteps, stepLabels); }
        
        function finishInline(success) {
          labelEl.textContent = success ? Drupal.t('Done!') : Drupal.t('Cancelled');
          labelEl.style.color = success ? '#059669' : '#6b7280';
          barEl.style.width = '100%';
          setTimeout(function() {
            var el = document.getElementById('addRefInlineProgress');
            if (el) el.remove();
          }, 1500);
        }
        
        var values         = valuesString.split(',').map(v => v.trim()).filter(v => v);
        var langString     = document.querySelector('#lang_js_data')?.value || '';
        var type           = document.querySelector('#type_js_data')?.value || '';
        var entity_id      = document.querySelector('#entity_id_js_data')?.value || '0';
        var isAlphanumericFree = values.some(v => /[A-Za-z]/.test(v));
        
        if (isAlphanumericFree) {
          if (type && langString) {
            showStep(2);
            var formData = new FormData();
            formData.append('ref', values.join(',')); formData.append('lang', langString);
            formData.append('type', type); formData.append('entity_id', entity_id);
            try {
              var resp  = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkDocumentExistence', { method: 'POST', body: formData, credentials: 'same-origin' });
              var check = await resp.json();
              if (check.status == 1) {
                finishInline(false);
                showConflictModal(Drupal.t('Warning, this reference already exists:') + '\n' + check.message, check.conflicting_media || [], function() {
                  var existingRefs = new Set(tab_value_ref.map(i => i.reference));
                  tab_value_ref = tab_value_ref.concat(values.filter(v => !existingRefs.has(v)).map(v => ({ reference: v, fam: null, ssfam: null, title: v, sav: 0, site_gys: 0 })));
                  renderTableRef(); updateRefJsData();
                });
                return;
              }
            } catch (e) { console.warn('checkDocumentExistence échoué :', e); }
          }
          var existingRefs = new Set(tab_value_ref.map(i => i.reference));
          tab_value_ref = tab_value_ref.concat(values.filter(v => !existingRefs.has(v)).map(v => ({ reference: v, fam: null, ssfam: null, title: v, sav: 0, site_gys: 0 })));
          renderTableRef(); updateRefJsData();
          finishInline(true);
          return;
        }
        
        showStep(1);
        var response = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/validateReferenceNumbers?ref=' + encodeURIComponent(valuesString.toUpperCase()));
        var data = await response.json();
        var invalidTerms = values.map(v => v.toUpperCase()).filter(v => data.terms_status[v] !== 1);
        if (invalidTerms.length > 0) { alert(Drupal.t('Invalid:') + ' ' + invalidTerms.join(', ')); }
        var validRefs = data.references || [];
        if (validRefs.length === 0) { finishInline(false); return; }
        
        if (type && langString) {
          showStep(2);
          var formData = new FormData();
          formData.append('ref', validRefs.join(',')); formData.append('lang', langString);
          formData.append('type', type); formData.append('entity_id', entity_id);
          try {
            var resp  = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkDocumentExistence', { method: 'POST', body: formData, credentials: 'same-origin' });
            var check = await resp.json();
            if (check.status == 1) {
              finishInline(false);
              showConflictModal(Drupal.t('Warning, this reference already exists:') + '\n' + check.message, check.conflicting_media || [], async function() {
                var infoResponse = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/getInformationProduct', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: validRefs.join(',') }), credentials: 'same-origin' });
                var infoData = await infoResponse.json();
                var existingRefs = new Set(tab_value_ref.map(i => i.reference));
                tab_value_ref = tab_value_ref.concat(infoData.filter(item => !existingRefs.has(item.reference)));
                renderTableRef(); updateRefJsData();
              });
              return;
            }
          } catch (e) { console.warn('checkDocumentExistence échoué :', e); }
        }
        
        showStep(3);
        var infoResponse = await fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/getInformationProduct', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ data: validRefs.join(',') }), credentials: 'same-origin' });
        var infoData = await infoResponse.json();
        var existingRefs = new Set(tab_value_ref.map(i => i.reference));
        tab_value_ref = tab_value_ref.concat(infoData.filter(item => !existingRefs.has(item.reference)));
        renderTableRef(); updateRefJsData();
        finishInline(true);
      }
      
      // ===== RENDU DU TABLEAU =====
      function renderTableRef() {
        var tableBody = document.querySelector('#tableDataRef');
        tableBody.innerHTML = '';
        var freeRefs    = tab_value_ref.filter(item => !item.fam);
        var productRefs = tab_value_ref.filter(item =>  item.fam);
        var hasProductRefs = productRefs.length > 0;
        
        var headerTr = document.createElement('tr');
        (hasProductRefs
          ? [Drupal.t('Family'), Drupal.t('Sub-family'), Drupal.t('Reference'), Drupal.t('Title'), Drupal.t('SAV site'), Drupal.t('GYS site'), Drupal.t('Action')]
          : [Drupal.t('Reference'), Drupal.t('Action')]
          ).forEach(text => { var th = document.createElement('th'); th.textContent = text; headerTr.appendChild(th); });
          tableBody.appendChild(headerTr);
          
          freeRefs.forEach(function(item) {
            var tr = document.createElement('tr');
            var refTd = document.createElement('td');
            refTd.textContent = item.reference;
            if (hasProductRefs) { refTd.colSpan = 6; refTd.style.fontStyle = 'italic'; }
            tr.appendChild(refTd);
            var deleteTd = document.createElement('td');
            var deleteBtn = document.createElement('button');
            deleteBtn.textContent = Drupal.t('Delete');
            deleteBtn.addEventListener('click', (function(ref) { return function() { tab_value_ref = tab_value_ref.filter(i => i.reference !== ref); renderTableRef(); updateRefJsData(); }; })(item.reference));
            deleteTd.appendChild(deleteBtn); tr.appendChild(deleteTd); tableBody.appendChild(tr);
          });
          
          var grouped = {};
          productRefs.forEach(item => {
            if (!grouped[item.fam]) grouped[item.fam] = {};
            if (!grouped[item.fam][item.ssfam]) grouped[item.fam][item.ssfam] = [];
            grouped[item.fam][item.ssfam].push(item);
          });
          
          Object.keys(grouped).forEach(fam => {
            var ssfams = grouped[fam];
            var famRowSpan = Object.values(ssfams).reduce((sum, arr) => sum + arr.length, 0);
            let famInserted = false;
            Object.keys(ssfams).forEach(ssfam => {
              var products = ssfams[ssfam];
              let ssfamInserted = false;
              products.forEach(product => {
                var tr = document.createElement('tr');
                if (!famInserted) {
                  var famTd = document.createElement('td'); famTd.rowSpan = famRowSpan;
                  var wrapper = document.createElement('div'); wrapper.style.cssText = 'display:flex;justify-content:space-between;align-items:center;width:100%';
                  var famDiv = document.createElement('div'); famDiv.textContent = fam;
                  var delBtn = document.createElement('button'); delBtn.textContent = '-';
                  delBtn.addEventListener('click', (function(f) { return function() { tab_value_ref = tab_value_ref.filter(item => item.fam !== f); renderTableRef(); updateRefJsData(); }; })(fam));
                  wrapper.appendChild(famDiv); wrapper.appendChild(delBtn); famTd.appendChild(wrapper); tr.appendChild(famTd); famInserted = true;
                }
                if (!ssfamInserted) {
                  var ssfamTd = document.createElement('td'); ssfamTd.rowSpan = products.length;
                  var wrapper2 = document.createElement('div'); wrapper2.style.cssText = 'display:flex;justify-content:space-between;align-items:center;width:100%';
                  var ssfamDiv = document.createElement('div'); ssfamDiv.textContent = ssfam;
                  var delBtn2 = document.createElement('button'); delBtn2.textContent = '-';
                  delBtn2.addEventListener('click', (function(sf) { return function() { tab_value_ref = tab_value_ref.filter(item => item.ssfam !== sf); renderTableRef(); updateRefJsData(); }; })(ssfam));
                  wrapper2.appendChild(ssfamDiv); wrapper2.appendChild(delBtn2); ssfamTd.appendChild(wrapper2); tr.appendChild(ssfamTd); ssfamInserted = true;
                }
                var refTd = document.createElement('td'); refTd.textContent = product.reference; tr.appendChild(refTd);
                var titleTd = document.createElement('td'); titleTd.textContent = product.title; titleTd.title = product.title; titleTd.style.cssText = 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px'; tr.appendChild(titleTd);
                var savTd = document.createElement('td'); var savCb = document.createElement('input'); savCb.type = 'checkbox'; savCb.disabled = true; savCb.checked = (product.sav == -1); savTd.appendChild(savCb); tr.appendChild(savTd);
                var gysTd = document.createElement('td'); var gysCb = document.createElement('input'); gysCb.type = 'checkbox'; gysCb.disabled = true; gysCb.checked = (product.site_gys == -1); gysTd.appendChild(gysCb); tr.appendChild(gysTd);
                var deleteTd2 = document.createElement('td'); var deleteBtn2 = document.createElement('button'); deleteBtn2.textContent = Drupal.t('Delete');
                deleteBtn2.addEventListener('click', (function(ref) { return function() { tab_value_ref = tab_value_ref.filter(item => item.reference !== ref); renderTableRef(); updateRefJsData(); }; })(product.reference));
                deleteTd2.appendChild(deleteBtn2); tr.appendChild(deleteTd2); tableBody.appendChild(tr);
              });
            });
          });
        }
        
        function updateRefJsData() {
          document.querySelector('#ref_js_data').value = tab_value_ref.map(item => item.reference).join(',');
        }
        
        // ===== LANGUES =====
        function updateLangJsData() {
          var checked = [];
          document.querySelectorAll('input[type="checkbox"][name*="languages"]').forEach(function (cb) { if (cb.checked) checked.push(cb.value); });
          var langField = document.querySelector('#lang_js_data');
          if (langField) langField.value = checked.join(',');
          var langString = checked.join(',');
          var type      = document.querySelector('#type_js_data')?.value || '';
          var entity_id = document.querySelector('#entity_id_js_data')?.value || '0';
          if (!langString || !type || tab_value_ref.length === 0) return;
          var formData = new FormData();
          formData.append('ref', tab_value_ref.map(i => i.reference).join(','));
          formData.append('lang', langString); formData.append('type', type); formData.append('entity_id', entity_id);
          fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkDocumentExistence', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(resp => resp.json())
          .then(function(check) { if (check.status == 1) { showConflictModal(Drupal.t('Warning, a reference/language combination already exists:') + '\n' + check.message, check.conflicting_media || [], function() {}); } })
          .catch(function(e) { console.warn('checkDocumentExistence (lang change) échoué :', e); });
        }
        
        function bindLanguageCheckboxes() {
          document.querySelectorAll('input[type="checkbox"][name*="languages"]').forEach(function (cb) { cb.addEventListener('change', updateLangJsData); });
        }
        
        // ===== CHARGEMENT INITIAL =====
        function loadInitialData() {
          if (!settings.gestion_documentaire) return;
          const data = settings.gestion_documentaire.ressource;
          var tableDataRef = data.ref ? data.ref.split(',') : [];
          var type = data.type;
          if (!tableDataRef.length) return;
          $.ajax({
            url: settings.gestion_documentaire.getDataUrl, method: 'POST',
            data: { reference: tableDataRef.join(','), type: type }, dataType: 'json',
            success: function (response) {
              var foundRefs = new Set((response || []).map(i => i.reference));
              tab_value_ref = response || [];
              tableDataRef.forEach(function(ref) {
                ref = ref.trim();
                if (ref && !foundRefs.has(ref)) { tab_value_ref.push({ reference: ref, fam: null, ssfam: null, title: ref, sav: 0, site_gys: 0 }); }
              });
              renderTableRef(); updateRefJsData();
            },
            error: function (xhr, status, error) { console.error('Erreur AJAX :', error); }
          });
        }
        
        // ===== FLUX SUBMIT =====
        
        // Étape 2 — vérification des versions à supprimer
        function checkVersionsAndSubmit(langString, published, versionning, doSubmit) {
          var groupId    = document.querySelector('[name*="media_group_id"]')?.value || '';
          var currentMid = document.querySelector('#entity_id_js_data')?.value || '0';
          if (published && groupId) {
            gdShowLoader(2);
            var midForQuery = versionning ? '0' : currentMid;
            fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/getMediaVersions?group_id=' + encodeURIComponent(groupId) + '&current_mid=' + encodeURIComponent(midForQuery))
            .then(resp => resp.json())
            .then(function(versions) {
              if (versions && versions.length > 0) {
                gdHideLoader();
                showVersionDeletionModal(versions, function() { checkHashAndProceed(langString, doSubmit); });
              } else {
                checkHashAndProceed(langString, doSubmit);
              }
            })
            .catch(function(err) { console.warn('getMediaVersions échoué :', err); checkHashAndProceed(langString, doSubmit); });
          } else {
            checkHashAndProceed(langString, doSubmit);
          }
        }
        
        // Étape 3 — vérification hash fichier
        function checkHashAndProceed(langString, doSubmit) {
          gdShowLoader(3);
          var entityId = document.querySelector('#entity_id_js_data')?.value || '0';
          var fid = 0;
          var fileField = document.querySelector('.js-form-type-managed-file');
          if (fileField) {
            fileField.querySelectorAll('input[type="hidden"]').forEach(function(input) {
              var val = Number.parseInt(input.value);
              if (val && val > 0 && input.name && input.name.indexOf('file') !== -1) { fid = val; }
            });
          }
          if (!fid) { proceedWithSubmit(langString, doSubmit); return; }
          var formData = new FormData();
          formData.append('fid', fid);
          formData.append('entity_id', entityId);
          fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkFileHashDuplicate', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(resp => resp.json())
          .then(function(check) {
            if (check.status == 1 && check.media) {
              gdHideLoader();
              showHashDuplicateModal(check.media, function() { proceedWithSubmit(langString, doSubmit); });
            } else {
              proceedWithSubmit(langString, doSubmit);
            }
          })
          .catch(function(err) { console.warn('checkFileHashDuplicate échoué :', err); proceedWithSubmit(langString, doSubmit); });
        }
        
        // Étape 4 — vérification couple ref/lang
        function proceedWithSubmit(langString, doSubmit) {
          gdShowLoader(4);
          var formData = new FormData();
          formData.append('ref',       tab_value_ref.map(i => i.reference).join(','));
          formData.append('lang',      langString);
          formData.append('type',      document.querySelector('#type_js_data').value);
          formData.append('entity_id', document.querySelector('#entity_id_js_data').value);
          fetch(drupalSettings.path.baseUrl + 'gestion_documentaire/checkDocumentExistence', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(resp => resp.json())
          .then(data => {
            if (data.status == 1) {
              console.log('[GD] intercept clic');
              gdHideLoader();
              showConflictModal(data.message, data.conflicting_media || [], function() { gdShowLoader(5); doSubmit(); });
            } else {
              gdShowLoader(5); doSubmit();
            }
          })
          .catch(error => { console.error('Erreur checkDocumentExistence :', error); gdShowLoader(5); doSubmit(); });
        }
        
        // ===== INITIALISATION FORMULAIRE =====
        function initializeForm() {
          var inputDataRef = document.querySelector('#inputDataRef');
          var addValueRef  = document.querySelector('#addValueRef');
          var searchBtn    = document.querySelector('#searchCategoriesBtn');
          
          var nameField = document.querySelector('[name="name"]');
          if (nameField) {
            nameField.addEventListener('keydown', function(e) {
              if (e.key === 'Enter') {
                e.preventDefault();
                e.stopImmediatePropagation();
                // déclenche le vrai bouton Update, qui repassera par ton intercepteur de click
                var updateBtn = document.querySelector('#edit-submit, form input[type="submit"][value], form button[type="submit"]');
                if (updateBtn) updateBtn.click();
              }
            });
          }
          
          
          if (!inputDataRef || !addValueRef) { console.warn(Drupal.t('Form elements not found')); return; }
          
          gdInitLoader();
          loadInitialData();
          
          $(once('addValueRefEvent', addValueRef, context)).on('click', function(e) { e.preventDefault(); void addEntryRef(); });
          $(once('searchCategoryEvent', searchBtn, context)).on('click', function(e) { e.preventDefault(); openCategorySearchModal(); });
          $(once('searchCategoryEvent', searchBtn, context)).on('keypress', function(e) { e.preventDefault(); openCategorySearchModal(); });
          inputDataRef.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              e.stopImmediatePropagation();
              addEntryRef();
            }
          });
          
          $(once('submitIntercept', 'form input[type="submit"], form button[type="submit"]', context)).on('click', function(e) {
            var $btn = $(this);
            
            if ($btn.attr('id') === 'searchCategoriesBtn' || $btn.closest('#searchCategoriesBtn').length) {
              return true;
            }
            
            if ($btn.attr('id') === 'addValueRef') {
              return true;
            }
            
            if ($btn.data('validated')) {
              $btn.removeData('validated');
              return true;
            }
            
            e.preventDefault();
            e.stopImmediatePropagation();
            
            try {
              updateLangJsData();
              var langString = document.querySelector('#lang_js_data')?.value || '';
              
              if (!langString.trim()) {
                alert(Drupal.t('At least one language'));
                return false;
              }
              
              var published   = document.querySelector('[name*="published"]')?.checked || false;
              var versionning = document.querySelector('[name*="create_new_version"]')?.checked || false;
              
              function doSubmit() {
                gdShowLoader(5);
                $btn.data('validated', true);
                var form = $btn[0].form;
                console.log('[GD] tentative submit, action=', form && form.action);
                if (!form) { console.error('[GD] pas de form !'); return; }
                // contourne la validation HTML5
                form.setAttribute('novalidate', 'novalidate');
                if (form.requestSubmit) {
                  form.requestSubmit($btn[0]);
                } else {
                  form.submit();
                }
              }
              
              gdShowLoader(1);
              checkVersionsAndSubmit(langString, published, versionning, doSubmit);
              
            } catch (error) {
              console.error('Erreur dans l\'intercepteur :', error);
              gdHideLoader();
            }
            
            return false;
          });
          
          bindLanguageCheckboxes();
          bindFileUploadCheck();
        }
      }
    };
  })(jQuery, Drupal, once);