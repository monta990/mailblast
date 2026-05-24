/**
 * Mail Blast — send page JS.
 * Runtime config injected by PHP as window.mbConfig before this file loads.
 */
(function () {
  'use strict';

  const cfg  = window.mbConfig || {};
  const i18n = cfg.i18n || {};

  // expose for TinyMCE scriptBlock scope
  window._mbMaxAttMb = cfg.maxAttMb || 15;
  window._mbI18n     = i18n;

  // ── File management ──────────────────────────────────────────────────────────

  const input    = document.getElementById('mb_fileInput');
  const dropZone = document.getElementById('mb_dropZone');
  const fileList = document.getElementById('mb_fileList');

  if (!input || !dropZone || !fileList) {
    console.error('Mail Blast: required DOM elements not found');
  } else {

  dropZone.addEventListener('click', (e) => {
    if (e.target === input) return;
    input.click();
  });

  let _dragCounter = 0;
  dropZone.addEventListener('dragenter', e => { e.preventDefault(); _dragCounter++; dropZone.classList.add('dragover'); });
  dropZone.addEventListener('dragleave', () => { _dragCounter--; if (_dragCounter <= 0) { _dragCounter = 0; dropZone.classList.remove('dragover'); } });
  dropZone.addEventListener('dragover',  e => { e.preventDefault(); });
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    e.stopPropagation();
    _dragCounter = 0;
    dropZone.classList.remove('dragover');
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      mergeFiles(e.dataTransfer.files);
    }
  });

  let selectedFiles = window._mbSelectedFiles = new DataTransfer();

  input.addEventListener('change', () => {
    mergeFiles(input.files);
    input.value = '';
  });

  function totalAttachmentSize() {
    return [...selectedFiles.files].reduce((sum, f) => sum + f.size, 0);
  }

  function mergeFiles(newFiles) {
    const limitBytes = (cfg.maxAttMb || 15) * 1024 * 1024;
    for (const f of newFiles) {
      const dup = [...selectedFiles.files].some(x => x.name === f.name && x.size === f.size);
      if (dup) continue;
      if (totalAttachmentSize() + f.size > limitBytes) {
        const msg = (i18n.attSizeLimit || 'Attachment size limit exceeded (%s MB max). File not added: %s')
          .replace('%s', cfg.maxAttMb || 15).replace('%s', f.name);
        const fa = document.getElementById('mb_formAlert');
        if (fa) { fa.textContent = msg; fa.style.display = ''; fa.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        else { console.warn('Mail Blast: ' + msg); }
        continue;
      }
      selectedFiles.items.add(f);
    }
    syncInput();
    renderList();
  }

  function removeFile(index) {
    const updated = new DataTransfer();
    [...selectedFiles.files].forEach((f, i) => { if (i !== index) updated.items.add(f); });
    selectedFiles = updated;
    window._mbSelectedFiles = selectedFiles;
    syncInput();
    renderList();
  }

  function syncInput() {
    const dt = new DataTransfer();
    for (const f of selectedFiles.files) dt.items.add(f);
    input.files = dt.files;
  }

  function humanSize(bytes) {
    if (bytes < 1024)    return bytes + ' ' + (i18n.bytes || 'B');
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' ' + (i18n.kilobytes || 'KB');
    return (bytes / 1048576).toFixed(1) + ' ' + (i18n.megabytes || 'MB');
  }

  function iconForMime(mime) {
    if (mime.startsWith('image/'))       return 'ti-photo';
    if (mime === 'application/pdf')      return 'ti-file-type-pdf';
    if (mime.includes('word') || mime.includes('document')) return 'ti-file-type-doc';
    if (mime.includes('sheet') || mime.includes('excel'))   return 'ti-file-type-xls';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return 'ti-file-type-ppt';
    if (mime.startsWith('text/'))        return 'ti-file-type-txt';
    if (mime.includes('zip') || mime.includes('compressed')) return 'ti-file-zip';
    return 'ti-file';
  }

  function renderList() {
    fileList.innerHTML = '';
    const files = [...selectedFiles.files];
    if (files.length === 0) { fileList.style.setProperty('display', 'none', 'important'); return; }
    fileList.style.removeProperty('display');
    files.forEach((f, idx) => {
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex align-items-center gap-2 py-2';
      li.innerHTML = `
        <i class="ti ${iconForMime(f.type)} text-muted fs-4 flex-shrink-0"></i>
        <span class="flex-grow-1 text-truncate" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
        <small class="text-muted flex-shrink-0">${humanSize(f.size)}</small>
        <button type="button" class="btn btn-sm btn-ghost-danger ms-1 flex-shrink-0" data-idx="${idx}" title="${i18n.remove || 'Remove'}">
          <i class="ti ti-x"></i>
        </button>
      `;
      li.querySelector('button').addEventListener('click', () => removeFile(idx));
      fileList.appendChild(li);
    });
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  } // end null-guard

  // ── Footer contenteditable toolbar ──────────────────────────────────────────

  (function() {
    const edit    = document.getElementById('mb_footerEdit');
    const hidden  = document.getElementById('mb_footer');
    const toolbar = document.getElementById('mb_footerToolbar');
    if (!edit || !hidden || !toolbar) return;

    function syncFooter() { hidden.value = edit.innerHTML; }
    edit.addEventListener('input', syncFooter);
    syncFooter();

    edit.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.execCommand('insertHTML', false, '<br>');
      }
    });

    toolbar.querySelectorAll('[data-cmd]').forEach(function(btn) {
      btn.addEventListener('mousedown', function(e) {
        e.preventDefault();
        document.execCommand(btn.dataset.cmd, false, null);
        syncFooter();
        edit.focus();
      });
    });
  }());

  // ── Clear form ───────────────────────────────────────────────────────────────

  (function() {
    var btn = document.getElementById('mb_clearForm');
    if (!btn) return;
    btn.addEventListener('click', function() {
      var subj = document.getElementById('mb_subject');
      if (subj) { subj.value = ''; subj.dispatchEvent(new Event('input')); }
      if (typeof tinymce !== 'undefined') {
        tinymce.editors.forEach(function(ed) {
          if (ed.id && ed.id.indexOf('mb_body_') === 0) ed.setContent('');
        });
      }
      var footerEdit   = document.getElementById('mb_footerEdit');
      var footerHidden = document.getElementById('mb_footer');
      if (footerEdit)   footerEdit.innerHTML = '';
      if (footerHidden) footerHidden.value   = '';
      var banner = document.getElementById('mb_summaryBanner');
      if (banner) banner.style.display = 'none';
      try { sessionStorage.removeItem('mb_subject'); } catch(_) {}
    });
  }());

  // ── Test address toggle ──────────────────────────────────────────────────────

  const testMe       = document.getElementById('mb_testMe');
  const testSpecific = document.getElementById('mb_testSpecific');
  const testField    = document.getElementById('mb_testEmailField');

  testSpecific?.addEventListener('change', () => { testField.style.display = testSpecific.checked ? 'block' : 'none'; });
  testMe?.addEventListener('change',       () => { testField.style.display = 'none'; });

  // ── Mass-send: validate → confirm modal → progress modal ────────────────────

  (function () {
    'use strict';

    let _confirmModal  = null;
    let _progressModal = null;
    let _cancelBound   = false;
    let _cancelled     = false;
    let _ticker        = null;
    let _cancelStep    = 0;

    let _sendId = '', _qHtml = '', _qPlain = '', _qAttB64 = [], _qInlineB64 = [];
    let _totalSent = 0, _totalErrors = 0, _total = 0, _startTime = 0;
    let _reportRows = [];

    // ── Recipient filter ─────────────────────────────────────────────────────

    function getFilterParams() {
      var typeEl = document.querySelector('input[name="filter_type"]:checked');
      var type   = typeEl ? typeEl.value : 'all';
      var ids    = [];
      var selId  = { entities: 'mb_entitySelect', profiles: 'mb_profileSelect', users: 'mb_userSelect' }[type];
      if (selId) {
        var sel = document.getElementById(selId);
        if (sel) ids = Array.from(sel.selectedOptions).map(function(o) { return +o.value; });
      }
      return { type: type, ids: ids };
    }

    function updateRecipientCount() {
      var f = getFilterParams();
      if (f.type !== 'all' && f.ids.length === 0) {
        var badge = document.getElementById('mb_recipientCount');
        if (badge) badge.textContent = '0';
        var cc = document.getElementById('mb_confirmCount');
        if (cc) cc.textContent = '0';
        var sb = document.getElementById('mb_sendAll');
        if (sb) sb.disabled = true;
        var ri = document.getElementById('mb_recipientIcon');
        var rw = document.getElementById('mb_recipientWarnIcon');
        if (ri) ri.style.display = 'none';
        if (rw) rw.style.display = '';
        return;
      }
      var url = cfg.formAction
        + '?action=count_recipients&filter_type=' + encodeURIComponent(f.type)
        + '&filter_ids=' + encodeURIComponent(JSON.stringify(f.ids));
      fetch(url, { method: 'GET' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.ok) return;
          var n = data.count || 0;
          var badge = document.getElementById('mb_recipientCount');
          if (badge) badge.textContent = n;
          var cc = document.getElementById('mb_confirmCount');
          if (cc) cc.textContent = n;
          var sb = document.getElementById('mb_sendAll');
          if (sb) sb.disabled = (n === 0);
          var ri = document.getElementById('mb_recipientIcon');
          var rw = document.getElementById('mb_recipientWarnIcon');
          if (ri) ri.style.display = n > 0 ? '' : 'none';
          if (rw) rw.style.display = n > 0 ? 'none' : '';
        }).catch(function() {});
    }

    (function wireFilter() {
      var boxes = { entities: 'mb_filterEntitiesBox', profiles: 'mb_filterProfilesBox', users: 'mb_filterUsersBox' };
      document.querySelectorAll('input[name="filter_type"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
          Object.keys(boxes).forEach(function(k) {
            var el = document.getElementById(boxes[k]);
            if (el) el.style.display = (radio.value === k) ? '' : 'none';
          });
          updateRecipientCount();
        });
      });
      ['mb_entitySelect', 'mb_profileSelect', 'mb_userSelect'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', updateRecipientCount);
      });
    }());

    function $$(id) { return document.getElementById(id); }
    function csrfToken() { var el = document.querySelector('input[name="_glpi_csrf_token"]'); return el ? el.value : ''; }
    function updateCsrf(token) {
      if (!token) return;
      var el = document.querySelector('input[name="_glpi_csrf_token"]');
      if (el) el.value = token;
    }

    function showFormAlert(msg) {
      var el = $$('mb_formAlert');
      if (!el) return;
      el.textContent = msg;
      el.style.display = '';
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hideFormAlert() { var el = $$('mb_formAlert'); if (el) el.style.display = 'none'; }

    function setStatus(msg, type) {
      var sl = $$('mb_statusLine');
      if (!sl) return;
      if (!msg) { sl.style.display = 'none'; return; }
      sl.className   = 'alert py-2 small mb-3 alert-' + (type || 'info');
      sl.textContent = msg;
      sl.style.display = '';
    }

    function setCounters(sent, errors, pending) {
      var eS = $$('mb_countSent');    if (eS) eS.textContent = sent;
      var eE = $$('mb_countError');   if (eE) eE.textContent = errors;
      var eP = $$('mb_countPending'); if (eP) eP.textContent = pending;
    }

    function setBar(pct) {
      var b = $$('mb_progressBar');
      if (!b) return;
      b.style.width = pct + '%';
      b.textContent = pct + '%';
      b.setAttribute('aria-valuenow', pct);
    }

    function addErrorItem(msg) {
      var es = $$('mb_errorSection');
      var el = $$('mb_errorList');
      if (es) es.style.display = '';
      if (el) { var li = document.createElement('li'); li.textContent = msg; el.appendChild(li); }
    }

    function finish(errMsg) {
      if (_ticker) { clearInterval(_ticker); _ticker = null; }

      var cb = $$('mb_cancelSend');
      var cl = $$('mb_closeProgress');
      var dl = $$('mb_downloadReport');
      if (cb) cb.classList.add('d-none');
      if (cl) cl.classList.remove('d-none');
      if (dl) dl.classList.remove('d-none');

      var b = $$('mb_progressBar');
      if (b) {
        b.classList.remove('progress-bar-animated', 'progress-bar-striped');
        if (_cancelled)  b.classList.add('bg-secondary');
        else if (errMsg) b.classList.add('bg-danger');
        else             b.classList.add('bg-success');
      }

      if (errMsg) addErrorItem(errMsg);
      if (_cancelled) {
        setStatus(i18n.sendingCancelled || 'Sending cancelled.', 'warning');
      } else if (_totalErrors > 0 && _totalSent === 0) {
        setStatus(i18n.sendingFailed || 'Sending failed — no emails were delivered.', 'danger');
      } else if (_totalErrors > 0) {
        setStatus(_totalSent + ' ' + (i18n.sent || 'sent') + ', ' + _totalErrors + ' ' + (i18n.failed || 'failed'), 'warning');
      } else {
        setStatus(i18n.allSent || 'All emails sent successfully.', 'success');
      }
    }

    // ── Report download (XLSX) ───────────────────────────────────────────────

    (function() {
      var dlBtn = $$('mb_downloadReport');
      if (!dlBtn) return;
      dlBtn.addEventListener('click', function() {
        if (!_reportRows.length) return;
        dlBtn.disabled = true;
        var origHTML = dlBtn.innerHTML;
        dlBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>'
                        + (i18n.generating || 'Generating…');

        var fd = new FormData();
        fd.append('_glpi_csrf_token', csrfToken());
        fd.append('action',  'generate_report');
        fd.append('rows',    JSON.stringify(_reportRows));
        fd.append('subject', ($$('mb_subject') || {}).value || '');

        fetch(cfg.formAction, { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (!data.ok || !data.data) throw new Error(data.error || 'Failed');
            updateCsrf(data.csrf);
            var binary = atob(data.data);
            var bytes  = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            var blob = new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href   = url;
            a.download = data.filename || 'mailblast_report.xlsx';
            document.body.appendChild(a);
            a.click();
            setTimeout(function() { document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
          })
          .catch(function(e) { alert(e.message || 'Error generating report'); })
          .then(function() { dlBtn.disabled = false; dlBtn.innerHTML = origHTML; });
      });
    }());

    // ── Fetch wrapper ────────────────────────────────────────────────────────

    function doFetch(fd, onOk, onFail) {
      fetch(cfg.formAction, { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(text) {
          var data;
          try { data = JSON.parse(text); } catch(e) {
            onFail((i18n.badResponse || 'Bad server response') + ': ' + text.substring(0, 150));
            return;
          }
          if (!data.ok) { onFail(data.error || i18n.serverError || 'Server error'); return; }
          onOk(data);
        })
        .catch(function(e) { onFail((i18n.networkError || 'Network error') + ': ' + (e.message || e)); });
    }

    // ── Batch loop ───────────────────────────────────────────────────────────

    function processNext(offset) {
      if (_cancelled) { finish(); return; }

      var fd = new FormData();
      fd.append('_glpi_csrf_token', csrfToken());
      fd.append('action',          'queue_process');
      fd.append('send_id',         _sendId);
      fd.append('offset',          offset);
      fd.append('html',            _qHtml);
      fd.append('plain',           _qPlain);
      fd.append('attachments_b64',   JSON.stringify(_qAttB64));
      fd.append('inline_images_b64', JSON.stringify(_qInlineB64));

      doFetch(fd,
        function(data) {
          if (_cancelled) { finish(); return; }
          updateCsrf(data.csrf);
          _totalSent   += data.sent   || 0;
          _totalErrors += data.errors || 0;

          if (data.sent_list && data.sent_list.length) {
            data.sent_list.forEach(function(addr) {
              _reportRows.push({ email: addr, status: 'sent', reason: '' });
            });
          }
          var processed = Math.min(data.next_offset || offset, _total);
          var pct = _total > 0 ? Math.round((processed / _total) * 100) : 0;
          setBar(pct);
          setCounters(_totalSent, _totalErrors, Math.max(0, _total - processed));
          var lbl = $$('mb_progressLabel2');
          if (lbl) lbl.textContent = processed + ' / ' + _total;

          if (data.error_list && data.error_list.length) {
            data.error_list.forEach(function(msg) {
              addErrorItem(msg);
              var sep = msg.indexOf(': ');
              _reportRows.push({
                email:  sep > -1 ? msg.substring(0, sep) : msg,
                status: 'failed',
                reason: sep > -1 ? msg.substring(sep + 2) : ''
              });
            });
          }

          if (data.done || _cancelled) {
            finish();
          } else {
            var delay = cfg.batchDelay || 0;
            if (delay > 0) {
              setTimeout(function() { processNext(data.next_offset); }, delay);
            } else {
              processNext(data.next_offset);
            }
          }
        },
        function(err) { finish(err); }
      );
    }

    // ── Start send ───────────────────────────────────────────────────────────

    function startSend() {
      var form = $$('mb_sendForm');
      if (!form) return;

      _cancelled   = false;
      _cancelStep  = 0;
      _totalSent   = 0;
      _totalErrors = 0;
      _total       = 0;
      _reportRows  = [];
      window._mbEmbeddedBytes = 0;
      _sendId  = '';
      _qHtml   = '';
      _qPlain  = '';
      _qAttB64    = [];
      _qInlineB64 = [];
      _startTime = Date.now();

      setStatus('');
      setBar(0);
      setCounters(0, 0, 0);
      var lbl = $$('mb_progressLabel2'); if (lbl) lbl.textContent = '0 / 0';
      var el  = $$('mb_elapsed');        if (el)  el.textContent  = '0s';
      var es  = $$('mb_errorSection');   if (es)  es.style.display = 'none';
      var eli = $$('mb_errorList');      if (eli) eli.innerHTML   = '';
      var b   = $$('mb_progressBar');
      if (b) {
        b.className = 'progress-bar progress-bar-striped progress-bar-animated fw-semibold';
        b.style.width = '0%'; b.textContent = '0%';
        b.setAttribute('aria-valuenow', 0);
      }

      var cb = $$('mb_cancelSend');
      var cl = $$('mb_closeProgress');
      var dl = $$('mb_downloadReport');
      if (cb) { cb.classList.remove('d-none', 'btn-danger'); cb.classList.add('btn-outline-danger'); cb.disabled = false; cb.innerHTML = '<i class="ti ti-x me-1"></i>' + (i18n.cancel || 'Cancel'); }
      if (cl) cl.classList.add('d-none');
      if (dl) dl.classList.add('d-none');

      if (_ticker) clearInterval(_ticker);
      _ticker = setInterval(function() {
        var el2 = $$('mb_elapsed');
        if (el2) el2.textContent = Math.floor((Date.now() - _startTime) / 1000) + 's';
      }, 1000);

      if (!_progressModal) {
        _progressModal = new bootstrap.Modal($$('mb_progressModal'));
        $$('mb_progressModal').addEventListener('hidden.bs.modal', function() {
          var banner = $$('mb_summaryBanner');
          if (!banner || (!_totalSent && !_totalErrors)) return;
          var type    = _totalErrors > 0 ? (_totalSent === 0 ? 'danger' : 'warning') : 'success';
          var icon    = _totalErrors > 0 ? 'ti-alert-triangle' : 'ti-circle-check';
          var closeBtn = document.createElement('button');
          closeBtn.type      = 'button';
          closeBtn.className = 'btn-close ms-auto';
          closeBtn.addEventListener('click', function() { banner.style.display = 'none'; });
          banner.className = 'alert alert-' + type + ' mb-3 d-flex align-items-center gap-2';
          banner.innerHTML = '<i class="ti ' + icon + ' fs-5"></i><span>'
            + _totalSent + ' ' + (i18n.sent || 'sent')
            + (_totalErrors > 0 ? ', ' + _totalErrors + ' ' + (i18n.failed || 'failed') : '')
            + '</span>';
          banner.appendChild(closeBtn);
          banner.style.display = '';
          banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
      }
      _progressModal.show();

      var fd = new FormData();
      fd.append('_glpi_csrf_token', csrfToken());
      fd.append('action',  'queue_init');
      fd.append('subject', ($$('mb_subject') || {}).value || '');

      if (typeof tinymce !== 'undefined') { try { tinymce.triggerSave(); } catch(e) {} }
      var bodyEl   = document.querySelector('textarea[name="body"]');
      var footerEl = document.querySelector('textarea[name="footer"]');
      fd.append('body',   bodyEl   ? bodyEl.value   : '');
      fd.append('footer', footerEl ? footerEl.value : '');

      var attFiles      = window._mbSelectedFiles ? Array.from(window._mbSelectedFiles.files) : [];
      var attB64Pending = attFiles.length;
      var attB64List    = [];

      function _doQueueInit() {
        fd.append('attachments_b64', JSON.stringify(attB64List));
        setStatus((i18n.sendingEmails || 'Sending emails') + '…', 'info');
        doFetch(fd,
          function(data) {
            updateCsrf(data.csrf);
            _total   = data.total   || 0;
            _sendId  = data.send_id || '';
            _qHtml   = data.html    || '';
            _qPlain  = data.plain   || '';
            _qAttB64    = data.attachments_b64   || [];
            _qInlineB64 = data.inline_images_b64 || [];
            setCounters(0, 0, _total);
            var lbl2 = $$('mb_progressLabel2'); if (lbl2) lbl2.textContent = '0 / ' + _total;
            if (_total === 0) {
              finish();
              setStatus(i18n.noActiveUsers || 'No active users with registered email found', 'warning');
              return;
            }
            processNext(0);
          },
          function(err) { finish(err); }
        );
      }

      var _filter = getFilterParams();
      fd.append('filter_type',    _filter.type);
      fd.append('filter_ids',     JSON.stringify(_filter.ids));
      var fromEntityEl = document.getElementById('mb_fromEntity');
      fd.append('from_entity_id', fromEntityEl ? (fromEntityEl.value || '0') : '0');

      if (attB64Pending === 0) {
        _doQueueInit();
      } else {
        attFiles.forEach(function(file) {
          var reader = new FileReader();
          reader.onload = function(ev) {
            var parts = ev.target.result.split(',');
            attB64List.push({ name: file.name, mime: file.type || 'application/octet-stream', data: parts[1] || '' });
            attB64Pending--;
            if (attB64Pending === 0) _doQueueInit();
          };
          reader.onerror = function() { attB64Pending--; if (attB64Pending === 0) _doQueueInit(); };
          reader.readAsDataURL(file);
        });
      }
    }

    // ── Wire: Send All ───────────────────────────────────────────────────────

    const sendAllBtn = $$('mb_sendAll');
    if (sendAllBtn) {
      sendAllBtn.addEventListener('click', function() {
        var subjectEl = $$('mb_subject');
        if (!subjectEl || !subjectEl.value.trim()) { showFormAlert(i18n.subjectRequired || 'Subject is required'); return; }
        if (typeof tinymce !== 'undefined') { try { tinymce.triggerSave(); } catch(e) {} }
        var bodyEl   = document.querySelector('textarea[name="body"]');
        var bodyText = bodyEl ? bodyEl.value.replace(/<[^>]*>/g, '').trim() : '';
        if (!bodyText) { hideFormAlert(); showFormAlert(i18n.bodyRequired || 'Body is required'); return; }
        hideFormAlert();
        if (!_confirmModal) _confirmModal = new bootstrap.Modal($$('mb_confirmModal'));
        _confirmModal.show();
      });
    }

    // ── Wire: Confirm Send ───────────────────────────────────────────────────

    const confirmBtn = $$('mb_confirmSend');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', function() {
        if (_confirmModal) _confirmModal.hide();
        var modalEl = $$('mb_confirmModal');
        if (modalEl) {
          modalEl.addEventListener('hidden.bs.modal', function() { startSend(); }, { once: true });
        } else {
          startSend();
        }
      });
    }

    // ── Wire: Cancel ─────────────────────────────────────────────────────────

    const cancelBtn = $$('mb_cancelSend');
    if (cancelBtn && !_cancelBound) {
      _cancelBound = true;
      cancelBtn.addEventListener('click', function() {
        _cancelStep++;
        if (_cancelStep === 1) {
          cancelBtn.classList.replace('btn-outline-danger', 'btn-danger');
          cancelBtn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>'
                              + (i18n.cancelConfirm || 'Cancel sending? Emails already sent will not be recalled.');
          setTimeout(function() {
            if (!_cancelled) {
              _cancelStep = 0;
              cancelBtn.classList.replace('btn-danger', 'btn-outline-danger');
              cancelBtn.innerHTML = '<i class="ti ti-x me-1"></i>' + (i18n.cancel || 'Cancel');
            }
          }, 4000);
        } else {
          _cancelled  = true;
          _cancelStep = 0;
          cancelBtn.disabled = true;
          cancelBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>'
                              + (i18n.cancelling || 'Cancelling…');
          finish();
        }
      });
    }

  }()); // end mass-send IIFE

  // ── sessionStorage: survive theme switches ───────────────────────────────────

  const SS_SUBJECT = 'mb_subject';
  const subjectEl  = document.getElementById('mb_subject');
  if (subjectEl) {
    const subjectCounter = document.getElementById('mb_subjectCounter');
    function updateSubjectCounter() {
      if (subjectCounter) {
        const len = Array.from(subjectEl.value).length;
        subjectCounter.textContent = len + '/250';
        subjectCounter.className   = 'small fw-normal '
          + (len >= 240 ? 'text-danger' : len >= 200 ? 'text-warning' : 'text-muted');
      }
    }
    subjectEl.addEventListener('input', () => {
      try { sessionStorage.setItem(SS_SUBJECT, subjectEl.value); } catch(_) {}
      updateSubjectCounter();
    });
    try {
      const saved = sessionStorage.getItem(SS_SUBJECT);
      if (saved !== null && saved !== '') subjectEl.value = saved;
    } catch(_) {}
    updateSubjectCounter();
  }

  // ── Test send ────────────────────────────────────────────────────────────────

  (function() {
    'use strict';

    try {
      var _testBtn = document.getElementById('mb_sendTest');
      if (!_testBtn) return;

      var _csrfToken = (function() {
        var el = document.querySelector('input[name="_glpi_csrf_token"]');
        return el ? el.value : '';
      }());

      function _updateCsrf(newToken) {
        if (!newToken) return;
        _csrfToken = newToken;
        var el = document.querySelector('input[name="_glpi_csrf_token"]');
        if (el) el.value = newToken;
      }

      _testBtn.addEventListener('click', function() {
        var subjectEl  = document.getElementById('mb_subject');
        var subjectVal = subjectEl ? subjectEl.value.trim() : '';
        if (!subjectVal) {
          var fa = document.getElementById('mb_formAlert');
          if (fa) { fa.textContent = i18n.subjectRequired || 'Subject is required'; fa.style.display = ''; }
          return;
        }

        var btn      = this;
        btn.disabled = true;
        var origHTML = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>'
                      + (i18n.sending || 'Sending…');

        var dt    = window._mbSelectedFiles;
        var files = dt ? Array.from(dt.files) : [];
        var attB64  = [];
        var pending = files.length;

        function doSend() {
          var fd = new FormData();
          fd.append('_glpi_csrf_token', _csrfToken);
          fd.append('action',           'test_send');
          fd.append('subject',          subjectVal);
          fd.append('attachments_b64',  JSON.stringify(attB64));

          if (typeof tinymce !== 'undefined') { try { tinymce.triggerSave(); } catch(e) {} }

          var bodyEl   = document.querySelector('textarea[name="body"]');
          var footerEl = document.querySelector('textarea[name="footer"]');
          var bodyVal  = bodyEl ? bodyEl.value.replace(/<[^>]*>/g, '').trim() : '';

          if (!bodyVal) {
            btn.disabled  = false;
            btn.innerHTML = origHTML;
            mbShowResult(false, i18n.bodyRequired || 'Body is required');
            return;
          }

          fd.append('body',   bodyEl   ? bodyEl.value   : '');
          fd.append('footer', footerEl ? footerEl.value : '');

          var modeEl = document.querySelector('input[name="test_mode"]:checked');
          fd.append('test_mode', modeEl ? modeEl.value : 'my_address');
          var specEl = document.getElementById('mb_testEmail');
          if (specEl && specEl.value.trim()) fd.append('test_email', specEl.value.trim());

          fetch(cfg.formAction, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              _updateCsrf(data.csrf || '');
              mbShowResult(data.ok,
                data.ok ? (i18n.testSent || 'Test sent successfully')
                        : (data.error || i18n.testFailed || 'Test failed')
              );
            })
            .catch(function(err) {
              mbShowResult(false, (i18n.networkError || 'Network error') + ': ' + (err.message || err));
            })
            .then(function() { btn.disabled = false; btn.innerHTML = origHTML; });
        }

        if (pending === 0) { doSend(); return; }

        files.forEach(function(file) {
          var reader = new FileReader();
          reader.onload = function(ev) {
            var parts = ev.target.result.split(',');
            attB64.push({ name: file.name, mime: file.type || 'application/octet-stream', data: parts[1] || '' });
            pending--;
            if (pending === 0) doSend();
          };
          reader.onerror = function() { pending--; if (pending === 0) doSend(); };
          reader.readAsDataURL(file);
        });
      });

    } catch(e) {
      var errBtn = document.getElementById('mb_sendTest');
      if (errBtn) {
        errBtn.insertAdjacentHTML('afterend',
          '<div class="alert alert-danger mt-2">' + (i18n.jsInitError || 'Initialization error') + ': ' + e.message + '</div>');
      }
    }

    function mbShowResult(ok, msg) {
      document.querySelectorAll('.mb-test-result').forEach(function(el) { el.remove(); });
      var div = document.createElement('div');
      div.className = 'alert mb-test-result mt-2 ' + (ok ? 'alert-success' : 'alert-danger');
      div.textContent = msg;
      var btn = document.getElementById('mb_sendTest');
      if (btn) btn.insertAdjacentElement('afterend', div);
      setTimeout(function() { if (div.parentNode) div.parentNode.removeChild(div); }, 8000);
    }

  }());

}());
