/**
 * Mail Blast — TinyMCE setup extensions.
 * GLPI's per-editor configuration is loaded first from the authenticated
 * EditorConfig JavaScript route; this file only modifies that config and starts it.
 */
(function () {
  'use strict';

  function initializeMailBlastEditor() {
    const root = document.getElementById('mb-send-page');
    if (!root) return;

    const id = root.dataset.mbBodyId || '';
    if (!/^mb_body_[a-f0-9]{12}$/.test(id)) {
      console.error('Mail Blast: invalid editor identifier');
      return;
    }

    const configs = window.tinymce_editor_configs || {};
    const conf = configs[id];
    if (!conf) {
      console.error('Mail Blast: GLPI TinyMCE configuration was not loaded');
      return;
    }

    if (typeof conf.toolbar === 'string') {
      conf.toolbar += ' | alignleft aligncenter alignright alignjustify';
    }

    const imageLimitMessage = root.dataset.mbImageLimitMessage || '';
    const originalSetup = conf.setup;
    conf.setup = function (editor) {
      if (typeof originalSetup === 'function') {
        originalSetup(editor);
      }

      editor.on('init', function () {
        window._mbEmbeddedBytes = window._mbEmbeddedBytes || 0;
        const originalUpload = editor.options.get('images_upload_handler');
        if (typeof originalUpload === 'function') {
          editor.options.set('images_upload_handler', function (blobInfo, progress) {
            const imageBytes = blobInfo.blob().size;
            const attachmentBytes = window._mbSelectedFiles
              ? Array.from(window._mbSelectedFiles.files).reduce((sum, file) => sum + file.size, 0)
              : 0;
            const maxBytes = (window._mbMaxAttMb || 15) * 1024 * 1024;
            if (window._mbEmbeddedBytes + attachmentBytes + imageBytes > maxBytes) {
              const message = imageLimitMessage.replace('%s', window._mbMaxAttMb || 15);
              return Promise.reject({ message, remove: true });
            }
            return originalUpload(blobInfo, progress).then(function (location) {
              window._mbEmbeddedBytes += imageBytes;
              return location;
            });
          });
        }
      });

      editor.on('PastePostProcess', function (event) {
        Array.from(event.node.querySelectorAll('img')).forEach(function (img) {
          if (!img.getAttribute('width') && !img.getAttribute('height')) {
            const applyDimensions = function () {
              if (img.naturalWidth) {
                img.setAttribute('width', img.naturalWidth);
                img.setAttribute('height', img.naturalHeight);
              }
            };
            if (img.complete) {
              applyDimensions();
            } else {
              img.addEventListener('load', applyDimensions, { once: true });
            }
          }
        });
      });
    };

    if (typeof window.tinyMCE === 'undefined' || typeof window.tinyMCE.init !== 'function') {
      console.error('Mail Blast: TinyMCE is not available');
      return;
    }
    window.tinyMCE.init(conf);
  }

  // GLPI's bootstrap registers its editor config through jQuery ready. Register
  // this callback afterwards so the config exists before Mail Blast modifies it.
  if (window.jQuery) {
    window.jQuery(initializeMailBlastEditor);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeMailBlastEditor, { once: true });
  } else {
    initializeMailBlastEditor();
  }
}());
