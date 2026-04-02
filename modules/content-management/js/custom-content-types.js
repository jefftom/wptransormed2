/**
 * Custom Content Types — Admin UI interactions.
 *
 * Handles tabs, modals, and AJAX CRUD for post types and taxonomies.
 *
 * @package WPTransformed
 */
(function() {
    'use strict';

    var initDataEl = document.getElementById('wpt-cct-init-data');
    if (!initDataEl) return;

    var data;
    try {
        data = JSON.parse(initDataEl.textContent);
    } catch (e) {
        return;
    }

    var postTypes  = data.postTypes || [];
    var taxonomies = data.taxonomies || [];

    // ── Tab Switching ────────────────────────────────────────

    var tabs = document.querySelectorAll('.wpt-cct-tab');
    var panels = document.querySelectorAll('.wpt-cct-panel');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');

            tabs.forEach(function(t) { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });

            this.classList.add('active');
            var panel = document.querySelector('[data-panel="' + target + '"]');
            if (panel) panel.classList.add('active');
        });
    });

    // ── Modal Helpers ────────────────────────────────────────

    function showModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.style.display = 'flex';
    }

    function hideModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
    }

    function resetPTForm() {
        document.getElementById('wpt-cct-pt-editing-slug').value = '';
        document.getElementById('wpt-cct-pt-slug').value = '';
        document.getElementById('wpt-cct-pt-slug').removeAttribute('readonly');
        document.getElementById('wpt-cct-pt-singular').value = '';
        document.getElementById('wpt-cct-pt-plural').value = '';
        document.getElementById('wpt-cct-pt-public').checked = true;
        document.getElementById('wpt-cct-pt-has-archive').checked = true;
        document.getElementById('wpt-cct-pt-show-in-rest').checked = true;
        document.getElementById('wpt-cct-pt-icon').value = 'dashicons-admin-post';
        document.getElementById('wpt-cct-pt-rewrite').value = '';

        var supports = document.querySelectorAll('.wpt-cct-pt-support');
        supports.forEach(function(cb) {
            cb.checked = (cb.value === 'title' || cb.value === 'editor' || cb.value === 'thumbnail');
        });
    }

    function resetTaxForm() {
        document.getElementById('wpt-cct-tax-editing-slug').value = '';
        document.getElementById('wpt-cct-tax-slug').value = '';
        document.getElementById('wpt-cct-tax-slug').removeAttribute('readonly');
        document.getElementById('wpt-cct-tax-singular').value = '';
        document.getElementById('wpt-cct-tax-plural').value = '';
        document.getElementById('wpt-cct-tax-hierarchical').checked = false;
        document.getElementById('wpt-cct-tax-show-in-rest').checked = true;
        document.getElementById('wpt-cct-tax-rewrite').value = '';

        var ptCheckboxes = document.querySelectorAll('.wpt-cct-tax-pt');
        ptCheckboxes.forEach(function(cb) { cb.checked = false; });
    }

    function populatePTForm(pt) {
        document.getElementById('wpt-cct-pt-editing-slug').value = pt.slug || '';
        document.getElementById('wpt-cct-pt-slug').value = pt.slug || '';
        document.getElementById('wpt-cct-pt-singular').value = pt.singular || '';
        document.getElementById('wpt-cct-pt-plural').value = pt.plural || '';
        document.getElementById('wpt-cct-pt-public').checked = !!pt.public;
        document.getElementById('wpt-cct-pt-has-archive').checked = !!pt.has_archive;
        document.getElementById('wpt-cct-pt-show-in-rest').checked = !!pt.show_in_rest;
        document.getElementById('wpt-cct-pt-icon').value = pt.menu_icon || 'dashicons-admin-post';
        document.getElementById('wpt-cct-pt-rewrite').value = (pt.rewrite && pt.rewrite !== pt.slug) ? pt.rewrite : '';

        var supports = pt.supports || ['title', 'editor', 'thumbnail'];
        document.querySelectorAll('.wpt-cct-pt-support').forEach(function(cb) {
            cb.checked = supports.indexOf(cb.value) !== -1;
        });
    }

    function populateTaxForm(tax) {
        document.getElementById('wpt-cct-tax-editing-slug').value = tax.slug || '';
        document.getElementById('wpt-cct-tax-slug').value = tax.slug || '';
        document.getElementById('wpt-cct-tax-singular').value = tax.singular || '';
        document.getElementById('wpt-cct-tax-plural').value = tax.plural || '';
        document.getElementById('wpt-cct-tax-hierarchical').checked = !!tax.hierarchical;
        document.getElementById('wpt-cct-tax-show-in-rest').checked = !!tax.show_in_rest;
        document.getElementById('wpt-cct-tax-rewrite').value = (tax.rewrite && tax.rewrite !== tax.slug) ? tax.rewrite : '';

        var associatedPTs = tax.post_types || [];
        document.querySelectorAll('.wpt-cct-tax-pt').forEach(function(cb) {
            cb.checked = associatedPTs.indexOf(cb.value) !== -1;
        });
    }

    // ── AJAX Helper ──────────────────────────────────────────

    function ajaxPost(action, data, callback) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', wptCCT.nonce);

        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                var val = data[key];
                if (Array.isArray(val)) {
                    val.forEach(function(v) {
                        formData.append(key + '[]', v);
                    });
                } else {
                    formData.append(key, val);
                }
            }
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', wptCCT.ajaxUrl, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    callback(response);
                } catch (e) {
                    callback({ success: false, data: { message: wptCCT.i18n.error } });
                }
            } else {
                callback({ success: false, data: { message: wptCCT.i18n.error } });
            }
        };
        xhr.onerror = function() {
            callback({ success: false, data: { message: wptCCT.i18n.error } });
        };
        xhr.send(formData);
    }

    // ── Notice Helper ────────────────────────────────────────

    function showNotice(message, type) {
        var existing = document.querySelector('.wpt-cct-notice');
        if (existing) existing.remove();

        var notice = document.createElement('div');
        notice.className = 'wpt-cct-notice wpt-cct-notice-' + (type || 'success');
        notice.textContent = message;

        var wrap = document.querySelector('.wpt-cct-wrap');
        if (wrap) {
            wrap.insertBefore(notice, wrap.firstChild);
            setTimeout(function() { notice.remove(); }, 4000);
        }
    }

    // ── Post Type: Add ───────────────────────────────────────

    var addPTBtn = document.getElementById('wpt-cct-add-pt');
    if (addPTBtn) {
        addPTBtn.addEventListener('click', function() {
            resetPTForm();
            document.getElementById('wpt-cct-pt-modal-title').textContent = 'Add Post Type';
            showModal('wpt-cct-pt-modal');
        });
    }

    // ── Post Type: Edit ──────────────────────────────────────

    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.wpt-cct-edit-pt');
        if (!editBtn) return;

        var slug = editBtn.getAttribute('data-slug');
        var pt = null;
        for (var i = 0; i < postTypes.length; i++) {
            if (postTypes[i].slug === slug) {
                pt = postTypes[i];
                break;
            }
        }
        if (!pt) return;

        resetPTForm();
        populatePTForm(pt);
        document.getElementById('wpt-cct-pt-modal-title').textContent = 'Edit Post Type';
        showModal('wpt-cct-pt-modal');
    });

    // ── Post Type: Save ──────────────────────────────────────

    var savePTBtn = document.getElementById('wpt-cct-pt-save');
    if (savePTBtn) {
        savePTBtn.addEventListener('click', function() {
            var btn = this;
            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = wptCCT.i18n.saving;

            var supports = [];
            document.querySelectorAll('.wpt-cct-pt-support:checked').forEach(function(cb) {
                supports.push(cb.value);
            });

            var payload = {
                slug:         document.getElementById('wpt-cct-pt-slug').value.toLowerCase().replace(/[^a-z0-9_-]/g, ''),
                editing_slug: document.getElementById('wpt-cct-pt-editing-slug').value,
                singular:     document.getElementById('wpt-cct-pt-singular').value,
                plural:       document.getElementById('wpt-cct-pt-plural').value,
                public:       document.getElementById('wpt-cct-pt-public').checked ? '1' : '',
                has_archive:  document.getElementById('wpt-cct-pt-has-archive').checked ? '1' : '',
                show_in_rest: document.getElementById('wpt-cct-pt-show-in-rest').checked ? '1' : '',
                menu_icon:    document.getElementById('wpt-cct-pt-icon').value,
                supports:     supports,
                rewrite:      document.getElementById('wpt-cct-pt-rewrite').value.toLowerCase().replace(/[^a-z0-9_-]/g, '')
            };

            ajaxPost('wpt_cct_save_post_type', payload, function(response) {
                btn.disabled = false;
                btn.textContent = originalText;

                if (response.success) {
                    postTypes = response.data.post_types || postTypes;
                    hideModal('wpt-cct-pt-modal');
                    showNotice(response.data.message, 'success');
                    reloadPage();
                } else {
                    showNotice(response.data.message || wptCCT.i18n.error, 'error');
                }
            });
        });
    }

    // ── Post Type: Delete ────────────────────────────────────

    document.addEventListener('click', function(e) {
        var deleteBtn = e.target.closest('.wpt-cct-delete-pt');
        if (!deleteBtn) return;

        if (!confirm(wptCCT.i18n.confirmDeletePT)) return;

        var slug = deleteBtn.getAttribute('data-slug');
        var originalText = deleteBtn.textContent;
        deleteBtn.disabled = true;
        deleteBtn.textContent = wptCCT.i18n.deleting;

        ajaxPost('wpt_cct_delete_post_type', { slug: slug }, function(response) {
            deleteBtn.disabled = false;
            deleteBtn.textContent = originalText;

            if (response.success) {
                postTypes = response.data.post_types || [];
                taxonomies = response.data.taxonomies || taxonomies;
                showNotice(response.data.message, 'success');
                reloadPage();
            } else {
                showNotice(response.data.message || wptCCT.i18n.error, 'error');
            }
        });
    });

    // ── Taxonomy: Add ────────────────────────────────────────

    var addTaxBtn = document.getElementById('wpt-cct-add-tax');
    if (addTaxBtn) {
        addTaxBtn.addEventListener('click', function() {
            resetTaxForm();
            document.getElementById('wpt-cct-tax-modal-title').textContent = 'Add Taxonomy';
            showModal('wpt-cct-tax-modal');
        });
    }

    // ── Taxonomy: Edit ───────────────────────────────────────

    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.wpt-cct-edit-tax');
        if (!editBtn) return;

        var slug = editBtn.getAttribute('data-slug');
        var tax = null;
        for (var i = 0; i < taxonomies.length; i++) {
            if (taxonomies[i].slug === slug) {
                tax = taxonomies[i];
                break;
            }
        }
        if (!tax) return;

        resetTaxForm();
        populateTaxForm(tax);
        document.getElementById('wpt-cct-tax-modal-title').textContent = 'Edit Taxonomy';
        showModal('wpt-cct-tax-modal');
    });

    // ── Taxonomy: Save ───────────────────────────────────────

    var saveTaxBtn = document.getElementById('wpt-cct-tax-save');
    if (saveTaxBtn) {
        saveTaxBtn.addEventListener('click', function() {
            var btn = this;
            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = wptCCT.i18n.saving;

            var selectedPTs = [];
            document.querySelectorAll('.wpt-cct-tax-pt:checked').forEach(function(cb) {
                selectedPTs.push(cb.value);
            });

            var payload = {
                slug:         document.getElementById('wpt-cct-tax-slug').value.toLowerCase().replace(/[^a-z0-9_-]/g, ''),
                editing_slug: document.getElementById('wpt-cct-tax-editing-slug').value,
                singular:     document.getElementById('wpt-cct-tax-singular').value,
                plural:       document.getElementById('wpt-cct-tax-plural').value,
                post_types:   selectedPTs,
                hierarchical: document.getElementById('wpt-cct-tax-hierarchical').checked ? '1' : '',
                show_in_rest: document.getElementById('wpt-cct-tax-show-in-rest').checked ? '1' : '',
                rewrite:      document.getElementById('wpt-cct-tax-rewrite').value.toLowerCase().replace(/[^a-z0-9_-]/g, '')
            };

            ajaxPost('wpt_cct_save_taxonomy', payload, function(response) {
                btn.disabled = false;
                btn.textContent = originalText;

                if (response.success) {
                    taxonomies = response.data.taxonomies || taxonomies;
                    hideModal('wpt-cct-tax-modal');
                    showNotice(response.data.message, 'success');
                    reloadPage();
                } else {
                    showNotice(response.data.message || wptCCT.i18n.error, 'error');
                }
            });
        });
    }

    // ── Taxonomy: Delete ─────────────────────────────────────

    document.addEventListener('click', function(e) {
        var deleteBtn = e.target.closest('.wpt-cct-delete-tax');
        if (!deleteBtn) return;

        if (!confirm(wptCCT.i18n.confirmDeleteTax)) return;

        var slug = deleteBtn.getAttribute('data-slug');
        var originalText = deleteBtn.textContent;
        deleteBtn.disabled = true;
        deleteBtn.textContent = wptCCT.i18n.deleting;

        ajaxPost('wpt_cct_delete_taxonomy', { slug: slug }, function(response) {
            deleteBtn.disabled = false;
            deleteBtn.textContent = originalText;

            if (response.success) {
                taxonomies = response.data.taxonomies || [];
                showNotice(response.data.message, 'success');
                reloadPage();
            } else {
                showNotice(response.data.message || wptCCT.i18n.error, 'error');
            }
        });
    });

    // ── Modal: Cancel / Close ────────────────────────────────

    var cancelPTBtn = document.getElementById('wpt-cct-pt-cancel');
    if (cancelPTBtn) {
        cancelPTBtn.addEventListener('click', function() {
            hideModal('wpt-cct-pt-modal');
        });
    }

    var cancelTaxBtn = document.getElementById('wpt-cct-tax-cancel');
    if (cancelTaxBtn) {
        cancelTaxBtn.addEventListener('click', function() {
            hideModal('wpt-cct-tax-modal');
        });
    }

    // Close modal on X button.
    document.querySelectorAll('.wpt-cct-modal-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = this.closest('.wpt-cct-modal');
            if (modal) modal.style.display = 'none';
        });
    });

    // Close modal on backdrop click.
    document.querySelectorAll('.wpt-cct-modal').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // ── Auto-generate Slug ───────────────────────────────────

    var ptSingular = document.getElementById('wpt-cct-pt-singular');
    var ptSlug     = document.getElementById('wpt-cct-pt-slug');
    if (ptSingular && ptSlug) {
        ptSingular.addEventListener('input', function() {
            // Only auto-generate if not editing an existing CPT.
            if (document.getElementById('wpt-cct-pt-editing-slug').value !== '') return;
            ptSlug.value = this.value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_-]/g, '').substring(0, 20);
        });
    }

    var taxSingular = document.getElementById('wpt-cct-tax-singular');
    var taxSlug     = document.getElementById('wpt-cct-tax-slug');
    if (taxSingular && taxSlug) {
        taxSingular.addEventListener('input', function() {
            if (document.getElementById('wpt-cct-tax-editing-slug').value !== '') return;
            taxSlug.value = this.value.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_-]/g, '').substring(0, 20);
        });
    }

    // ── Page Reload ──────────────────────────────────────────

    function reloadPage() {
        // Reload after a short delay so the notice is visible.
        setTimeout(function() {
            window.location.reload();
        }, 800);
    }

})();
