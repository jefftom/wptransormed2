/**
 * WPTransformed Admin Dashboard
 * assets/admin/js/admin.js
 *
 * Dashboard page-specific JS. Global admin chrome (dark mode toggle,
 * sidebar injections, topbar) handled by admin-global.js.
 *
 * Handles:
 * 1. Sub-module toggles (AJAX via wpt_toggle_module)
 * 2. Parent toggle → cascade activate/deactivate of all sub-modules
 *    (Stage 3 cascades via sub-toggle events; Stage 4 will swap to a
 *    batch wpt_toggle_parent endpoint)
 * 3. Expand/collapse of parent cards (Session 3)
 * 4. Pill-tab category filtering across parent cards
 * 5. Command palette (Ctrl+K) with search and keyboard nav
 * 6. Animated bento counters
 *
 * No jQuery dependency — vanilla JS.
 */
(function() {
    'use strict';

    var dashboard, cmdOverlay, cmdInput, cmdResults, selectedIdx;

    document.addEventListener('DOMContentLoaded', function() {
        dashboard = document.querySelector('.wpt-dashboard');
        if (!dashboard) return;

        initModuleToggles();
        initParentToggles();
        initParentCardInteractions();
        initPillTabs();
        initCommandPalette();
        initDbCleanupActions();
        initLoginDesigner();
        initMenuEditor();
        setTimeout(animateCounters, 350);
    });

    /* ──────────────────────────────────────
       SUB-MODULE TOGGLES (AJAX via wpt_toggle_module)
       Parent toggles are handled separately in initParentToggles.
    ────────────────────────────────────── */
    function initModuleToggles() {
        document.querySelectorAll('.wpt-module-toggle').forEach(function(toggle) {
            /* Skip parent toggles — initParentToggles() handles those. */
            if (toggle.classList.contains('wpt-parent-toggle')) return;

            toggle.addEventListener('change', function(e) {
                e.stopPropagation();

                var moduleId = this.dataset.moduleId;
                var active   = this.checked ? '1' : '0';
                var inp      = this;
                var parentCard = this.closest('.parent-card');
                var subItem = this.closest('.submodule-item');

                /* Optimistic UI: reflect the new state immediately. */
                if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                if (parentCard) syncParentCard(parentCard);

                var formData = new FormData();
                formData.append('action', 'wpt_toggle_module');
                formData.append('module_id', moduleId);
                formData.append('active', active);
                formData.append('nonce', wptAdmin.nonce);

                fetch(wptAdmin.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        /* Revert optimistic UI on server error. */
                        inp.checked = !inp.checked;
                        if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                        if (parentCard) syncParentCard(parentCard);
                    } else {
                        updateBentoCount();
                    }
                })
                .catch(function() {
                    inp.checked = !inp.checked;
                    if (subItem) subItem.classList.toggle('disabled', !inp.checked);
                    if (parentCard) syncParentCard(parentCard);
                });
            });

            /* Prevent label click from bubbling to the card. */
            var label = toggle.closest('.toggle, .sub-toggle');
            if (label) {
                label.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    }

    /* ──────────────────────────────────────
       PARENT TOGGLES
       Single batch round-trip via wpt_toggle_parent AJAX endpoint.
       The handler in class-admin.php (ajax_toggle_parent) walks the
       parent's sub-modules via Module_Hierarchy and toggles each atomically.
       Per-sub results are returned so we can revert individual failures.
    ────────────────────────────────────── */
    function initParentToggles() {
        document.querySelectorAll('.wpt-parent-toggle').forEach(function(parentToggle) {
            parentToggle.addEventListener('change', function(e) {
                e.stopPropagation();

                var card = this.closest('.parent-card');
                if (!card) return;

                /* Disabled Pro toggle — shouldn't fire, but revert if it does. */
                if (this.disabled) {
                    this.checked = !this.checked;
                    return;
                }

                var parentId       = this.dataset.parentId;
                var shouldActivate = this.checked;
                var inp            = this;
                var subToggles     = card.querySelectorAll('.wpt-sub-module-toggle');

                /* Optimistic UI: flip all sub-toggles immediately. */
                var priorState = [];
                subToggles.forEach(function(sub) {
                    priorState.push({ el: sub, was: sub.checked });
                    sub.checked = shouldActivate;
                    var row = sub.closest('.submodule-item');
                    if (row) row.classList.toggle('disabled', !shouldActivate);
                });
                syncParentCard(card);

                var formData = new FormData();
                formData.append('action', 'wpt_toggle_parent');
                formData.append('parent_id', parentId);
                formData.append('active', shouldActivate ? '1' : '0');
                formData.append('nonce', wptAdmin.nonce);

                fetch(wptAdmin.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        /* Whole batch rejected — revert everything. */
                        priorState.forEach(function(s) {
                            s.el.checked = s.was;
                            var row = s.el.closest('.submodule-item');
                            if (row) row.classList.toggle('disabled', !s.was);
                        });
                        inp.checked = priorState.some(function(s) { return s.was; });
                        syncParentCard(card);
                        return;
                    }

                    /* Per-sub failures: revert just those specific rows.
                       data.data.sub_modules = [{id, active, error?}, ...] */
                    var payload = data.data || {};
                    var subResults = payload.sub_modules || [];
                    subResults.forEach(function(sr) {
                        if (!sr.error) return;
                        var sub = card.querySelector('.wpt-sub-module-toggle[data-module-id="' + sr.id + '"]');
                        if (!sub) return;
                        /* Revert this sub to its original pre-batch state. */
                        var prior = priorState.find(function(p) { return p.el === sub; });
                        if (prior) {
                            sub.checked = prior.was;
                            var row = sub.closest('.submodule-item');
                            if (row) row.classList.toggle('disabled', !prior.was);
                        }
                    });
                    syncParentCard(card);
                    updateBentoCount();
                })
                .catch(function() {
                    /* Network error — full revert. */
                    priorState.forEach(function(s) {
                        s.el.checked = s.was;
                        var row = s.el.closest('.submodule-item');
                        if (row) row.classList.toggle('disabled', !s.was);
                    });
                    inp.checked = priorState.some(function(s) { return s.was; });
                    syncParentCard(card);
                });
            });

            var label = parentToggle.closest('.toggle');
            if (label) {
                label.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    }

    /* ──────────────────────────────────────
       PARENT CARD INTERACTIONS
       - Expand/collapse the sub-modules panel on expand-button click
       - Don't hijack mod-main clicks on parent cards (no card-level URL)
    ────────────────────────────────────── */
    function initParentCardInteractions() {
        document.querySelectorAll('.parent-card .mod-expand-btn').forEach(function(btn) {
            /* APP parents use an <a class="mod-app-link">; let native
               navigation handle them. */
            if (btn.classList.contains('mod-app-link')) return;

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var card = this.closest('.parent-card');
                if (!card) return;

                var isExpanded = card.classList.toggle('expanded');
                this.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            });
        });
    }

    /* ──────────────────────────────────────
       SYNC PARENT CARD STATE
       Re-compute a parent card's UI state based on its sub-toggles.
       Called after any sub-toggle change or AJAX success/failure.
    ────────────────────────────────────── */
    function syncParentCard(card) {
        if (!card || !card.classList.contains('parent-card')) return;

        var subToggles = card.querySelectorAll('.wpt-sub-module-toggle');
        var totalSubs  = subToggles.length;
        var activeSubs = card.querySelectorAll('.wpt-sub-module-toggle:checked').length;

        /* Parent toggle reflects "any sub active", not a server write —
           update .checked directly without firing change events. */
        var parentToggle = card.querySelector('.wpt-parent-toggle');
        if (parentToggle) {
            parentToggle.checked = activeSubs > 0;
        }

        /* Card-level disabled: greys out the card when no subs are active. */
        card.classList.toggle('disabled', activeSubs === 0);

        /* Update the "X/Y sub-modules" text in the footer. */
        var footerCount = card.querySelector('.wpt-sub-count-text');
        if (footerCount) {
            footerCount.textContent = activeSubs + '/' + totalSubs + ' sub-modules';
        }

        /* Update the "X of Y enabled" text inside the expanded panel. */
        var panelCount = card.querySelector('.wpt-sub-count-text-expanded');
        if (panelCount) {
            panelCount.textContent = activeSubs + ' of ' + totalSubs + ' enabled';
        }

        /* Update data attrs so downstream code reading them stays in sync. */
        card.dataset.activeSubCount = String(activeSubs);

        /* Keep the category-section header count live as parents toggle. */
        syncCategoryCount(card.closest('.category-section'));
    }

    /* Recompute "X of Y active" for a .category-section header by counting
       parent cards whose data-active-sub-count is > 0. Called from
       syncParentCard() so section counts stay live as users edit. */
    function syncCategoryCount(section) {
        if (!section) return;
        var countEl = section.querySelector('.category-count');
        if (!countEl) return;
        var cards = section.querySelectorAll('.parent-card');
        var total = cards.length;
        var active = 0;
        cards.forEach(function(c) {
            if (parseInt(c.dataset.activeSubCount || '0', 10) > 0) active++;
        });
        countEl.textContent = active + ' of ' + total + ' active';
    }

    function updateBentoCount() {
        /* Bento "Active Modules" — counts only real sub-module toggles,
           excludes .wpt-parent-toggle which are presentation-layer only. */
        var activeCount = 0;
        document.querySelectorAll('.wpt-module-toggle:checked').forEach(function(t) {
            if (!t.classList.contains('wpt-parent-toggle')) activeCount++;
        });
        var el = document.getElementById('wptActiveCount');
        if (el) el.textContent = activeCount;
    }

    /* ──────────────────────────────────────
       PILL TAB FILTERING — category sections
       Filters whole .category-section wrappers on pill click. When
       "All" is active every section shows; picking a specific category
       shows only that section (header + grid + cards together).
    ────────────────────────────────────── */
    function initPillTabs() {
        var tabs     = document.querySelectorAll('.pill-tab');
        var sections = document.querySelectorAll('#wptModulesContainer .category-section');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var cat = this.dataset.category;

                tabs.forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

                sections.forEach(function(section) {
                    if (cat === 'all' || section.dataset.category === cat) {
                        section.style.display = '';
                    } else {
                        section.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ──────────────────────────────────────
       DATABASE OPTIMIZER — Cleanup task runners
       Calls the existing wpt_db_cleanup_run AJAX endpoint registered
       by the Database_Cleanup module. Each Clean button targets one
       cleanup category; the "Clean All" header button iterates over
       enabled (non-zero count) rows.
    ────────────────────────────────────── */
    function initDbCleanupActions() {
        var container = document.getElementById('wptDbOptimizer');
        if (!container) return;

        var config = typeof wptDbOptimizer !== 'undefined' ? wptDbOptimizer : null;
        if (!config || !config.ajaxUrl || !config.nonce) return;

        /* Per-row Clean buttons */
        container.querySelectorAll('.wpt-cleanup-action').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                if (btn.disabled) return;

                var row = btn.closest('.wpt-cleanup-item');
                var category = btn.dataset.category;
                if (!category || !row) return;

                runCleanup(category, btn, row, config);
            });
        });

        /* Clean All header button — iterate rows with count > 0 */
        var cleanAllBtn = document.getElementById('wptDbCleanAll');
        if (cleanAllBtn && !cleanAllBtn.disabled) {
            cleanAllBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('Run all cleanup tasks? This removes rows permanently.')) return;

                cleanAllBtn.disabled = true;
                cleanAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning…';

                runCleanup('all', cleanAllBtn, null, config)
                    .then(function() {
                        cleanAllBtn.innerHTML = '<i class="fas fa-check"></i> Done';
                        setTimeout(function() { window.location.reload(); }, 800);
                    })
                    .catch(function() {
                        cleanAllBtn.disabled = false;
                        cleanAllBtn.innerHTML = '<i class="fas fa-broom"></i> Clean All';
                    });
            });
        }
    }

    function runCleanup(category, btn, row, config) {
        var originalLabel = btn ? btn.textContent : '';
        if (btn && row) {
            btn.disabled = true;
            btn.textContent = '…';
        }

        var formData = new FormData();
        formData.append('action', 'wpt_db_cleanup_run');
        formData.append('category', category);
        formData.append('nonce', config.nonce);

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                throw new Error((data.data && data.data.message) || 'Cleanup failed');
            }

            /* Per-row: update count + size + disable button */
            if (btn && row) {
                var countEl = row.querySelector('.wpt-cleanup-count');
                var sizeEl  = row.querySelector('.wpt-cleanup-size');
                var remaining = (data.data && data.data.remaining) || 0;
                if (countEl) countEl.textContent = String(remaining);
                if (sizeEl)  sizeEl.textContent = remaining === 0 ? '0.0 B' : countEl.textContent;

                if (remaining === 0) {
                    row.classList.add('is-clean');
                    btn.disabled = true;
                    btn.textContent = 'Clean';
                } else {
                    /* More rows exist — restore button so user can click again */
                    btn.disabled = false;
                    btn.textContent = originalLabel || 'Clean';
                }
            }

            return data;
        })
        .catch(function(err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalLabel || 'Clean';
            }
            /* eslint-disable-next-line no-console */
            console.error('[WPT DB cleanup]', err);
            alert('Cleanup failed: ' + err.message);
            throw err;
        });
    }

    /* ──────────────────────────────────────
       LOGIN DESIGNER — Session 5 Part 1
       Tab switching, live preview updates, template picker,
       color swatch ↔ hex input sync, device preview toggle.
    ────────────────────────────────────── */
    function initLoginDesigner() {
        var root = document.getElementById('wptLoginDesigner');
        if (!root) return;

        initLdTabs(root);
        initLdColorPickers(root);
        initLdLivePreview(root);
        initLdTemplates(root);
        initLdDeviceButtons(root);
        initLdResetButton(root);
    }

    function initLdTabs(root) {
        var tabs = root.querySelectorAll('.wpt-ld-tab');
        var panels = root.querySelectorAll('.wpt-ld-tab-panel');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                var target = this.dataset.tab;

                tabs.forEach(function(t) {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');

                panels.forEach(function(p) {
                    p.classList.toggle('active', p.dataset.tabPanel === target);
                });
            });
        });
    }

    /* Sync the native <input type="color"> (inside the swatch) with the
       hex text input next to it. Both directions: picking a color updates
       the text, typing a hex updates the swatch + color picker. */
    function initLdColorPickers(root) {
        var swatches = root.querySelectorAll('.wpt-ld-color-swatch input[type="color"]');

        swatches.forEach(function(picker) {
            var textId = picker.dataset.colorTarget;
            var textInput = textId ? document.querySelector(textId) : null;
            if (!textInput) return;

            /* Picker → text input */
            picker.addEventListener('input', function() {
                var val = this.value;
                textInput.value = val;
                var swatch = picker.closest('.wpt-ld-color-swatch');
                if (swatch) swatch.style.backgroundColor = val;
                /* Dispatch input event on the text input so the preview
                   binding below picks up the change. */
                textInput.dispatchEvent(new Event('input', { bubbles: true }));
            });

            /* Text input → picker + swatch */
            textInput.addEventListener('input', function() {
                var val = this.value.trim();
                if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                    picker.value = val;
                    var swatch = picker.closest('.wpt-ld-color-swatch');
                    if (swatch) swatch.style.backgroundColor = val;
                }
            });
        });
    }

    /* Wire every input with a data-preview-target to update the preview
       DOM on input (for text/color/range) or change (for checkboxes).
       Keeps the operation declarative: the PHP markup decides what the
       input controls via data-preview-action. */
    function initLdLivePreview(root) {
        var inputs = root.querySelectorAll('[data-preview-target]');

        inputs.forEach(function(input) {
            var target = input.dataset.previewTarget;
            var action = input.dataset.previewAction;
            if (!target || !action) return;

            var evtName = input.type === 'checkbox' ? 'change' : 'input';

            input.addEventListener(evtName, function() {
                applyLdPreview(root, input, target, action);
            });
        });

        /* Border-radius slider has a separate value display element. */
        var displayInputs = root.querySelectorAll('[data-display-target]');
        displayInputs.forEach(function(input) {
            var display = document.querySelector(input.dataset.displayTarget);
            if (!display) return;
            input.addEventListener('input', function() {
                display.textContent = this.value + 'px';
            });
        });
    }

    function applyLdPreview(root, input, target, action) {
        var els = root.querySelectorAll(target);
        if (!els.length) return;

        var value = input.type === 'checkbox' ? input.checked : input.value;

        els.forEach(function(el) {
            switch (action) {
                case 'bg-color':
                    if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
                        el.style.backgroundColor = value;
                    }
                    break;
                case 'color':
                    if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
                        el.style.color = value;
                    }
                    break;
                case 'text-color':
                    if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
                        el.style.color = value;
                        /* Cascade to nested labels inside the form */
                        el.querySelectorAll('label, h3').forEach(function(child) {
                            child.style.color = value;
                        });
                    }
                    break;
                case 'border-radius':
                    el.style.borderRadius = parseInt(value, 10) + 'px';
                    break;
                case 'bg-image':
                    if (typeof value === 'string' && value.length > 0) {
                        el.style.backgroundImage = "url('" + value.replace(/'/g, "\\'") + "')";
                        el.style.backgroundSize = 'cover';
                        el.style.backgroundPosition = 'center';
                    } else {
                        el.style.backgroundImage = 'none';
                    }
                    break;
                case 'logo-url':
                    if (el.tagName === 'IMG') {
                        if (typeof value === 'string' && value.length > 0) {
                            el.src = value;
                            el.hidden = false;
                            /* Hide the placeholder icon when we have a real logo */
                            var placeholder = root.querySelector('.wpt-ld-login-logo-placeholder');
                            if (placeholder) placeholder.style.display = 'none';
                        } else {
                            el.hidden = true;
                            el.src = '';
                            var placeholder2 = root.querySelector('.wpt-ld-login-logo-placeholder');
                            if (placeholder2) placeholder2.style.display = '';
                        }
                    }
                    break;
                case 'hide-back':
                    el.hidden = !!value;
                    break;
            }
        });
    }

    /* Template picker: clicking a preset applies its JSON settings to
       every matching form input and fires their input events so the
       live preview + color swatches update. */
    function initLdTemplates(root) {
        var presets = root.querySelectorAll('.wpt-ld-preset');

        presets.forEach(function(preset) {
            preset.addEventListener('click', function(e) {
                e.preventDefault();

                presets.forEach(function(p) { p.classList.remove('active'); });
                this.classList.add('active');

                var json = this.dataset.templateJson;
                if (!json) return;

                var settings;
                try {
                    settings = JSON.parse(json);
                } catch (err) {
                    /* eslint-disable-next-line no-console */
                    console.error('[WPT Login Designer] invalid template JSON', err);
                    return;
                }

                Object.keys(settings).forEach(function(key) {
                    /* Form fields use the wpt_ prefix because Login_Customizer::
                       sanitize_settings() reads $raw['wpt_*']. Template JSON
                       keys are the settings-array keys (unprefixed), so we
                       add the prefix when looking up the input. */
                    var input = root.querySelector('[name="wpt_' + key + '"]');
                    if (!input) return;

                    var value = settings[key];
                    if (input.type === 'checkbox') {
                        input.checked = !!value;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    } else {
                        input.value = String(value);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            });
        });
    }

    /* Device toggle: swap max-width on the preview wrap. No real
       browser emulation — just a visual indicator of how the form
       will scale. */
    function initLdDeviceButtons(root) {
        var btns = root.querySelectorAll('.wpt-ld-device-btn');
        var wrap = root.querySelector('.wpt-ld-preview-wrap');
        if (!wrap) return;

        btns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                btns.forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                wrap.setAttribute('data-device', this.dataset.device);
            });
        });
    }

    /* Reset button: clears form via confirm + plain form.reset(),
       then fires input events to sync the preview. Server-side
       defaults are applied on the next save, so the user can still
       abandon the reset by leaving the page without clicking Save. */
    function initLdResetButton(root) {
        var btn = document.getElementById('wptLoginDesignerReset');
        if (!btn) return;

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Reset all fields to their current saved values? Unsaved changes will be lost.')) return;

            var form = document.getElementById('wptLoginDesignerForm');
            if (!form) return;
            form.reset();

            /* Fire input events so the preview re-syncs */
            form.querySelectorAll('input, textarea, select').forEach(function(el) {
                var evtName = el.type === 'checkbox' ? 'change' : 'input';
                el.dispatchEvent(new Event(evtName, { bubbles: true }));
            });
        });
    }

    /* ──────────────────────────────────────
       MENU EDITOR — Session 5 Part 2
       Tree hydration, HTML5 drag-drop reorder, selection,
       property editing, live preview sync, and form
       serialization on save.
    ────────────────────────────────────── */
    function initMenuEditor() {
        var root = document.getElementById('wptMenuEditor');
        if (!root) return;

        var dataEl = document.getElementById('wptMenuEditorData');
        if (!dataEl) return;

        var data;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (e) {
            /* eslint-disable-next-line no-console */
            console.error('[WPT Menu Editor] invalid init data', e);
            return;
        }

        var state = {
            items: (data.items || []).map(function(it) {
                return {
                    slug: it.slug,
                    label: it.label,
                    originalLabel: it.original_label,
                    icon: it.icon,
                    originalIcon: it.original_icon,
                    hidden: !!it.hidden,
                    separator: !!it.separator,
                    renamed: !!it.renamed,
                    iconOverridden: !!it.icon_overridden
                };
            }),
            selectedSlug: null,
            iconPalette: data.iconPalette || []
        };

        var els = {
            tree: document.getElementById('wptMeTree'),
            preview: document.getElementById('wptMePreviewMenu'),
            treeCount: document.getElementById('wptMeTreeCount'),
            props: document.getElementById('wptMeProps'),
            propsBody: document.getElementById('wptMePropsBody'),
            propsEmpty: document.getElementById('wptMePropsEmpty'),
            propsTitle: document.getElementById('wptMePropsTitle'),
            propsSubtitle: document.getElementById('wptMePropsSubtitle'),
            inputLabel: document.getElementById('wptMeEditLabel'),
            inputSlug: document.getElementById('wptMeEditSlug'),
            inputIconManual: document.getElementById('wptMeEditIcon'),
            inputHidden: document.getElementById('wptMeEditHidden'),
            inputSeparator: document.getElementById('wptMeEditSeparator'),
            iconPicker: document.getElementById('wptMeIconPicker'),
            revertBtn: document.getElementById('wptMeRevertItem'),
            resetBtn: document.getElementById('wptMeReset'),
            form: document.getElementById('wptMenuEditorForm'),
            hiddenMenuOrder: document.getElementById('wptMeMenuOrder'),
            hiddenHiddenItems: document.getElementById('wptMeHiddenItems'),
            hiddenRenamedJson: document.getElementById('wptMeRenamedJson'),
            hiddenIconsJson: document.getElementById('wptMeIconsJson'),
            hiddenSeparators: document.getElementById('wptMeSeparators')
        };

        renderTree(state, els);
        renderPreview(state, els);
        bindTreeInteractions(state, els);
        bindPropsInteractions(state, els);
        bindResetButton(state, els);
        bindFormSubmit(state, els);
    }

    function findItem(state, slug) {
        for (var i = 0; i < state.items.length; i++) {
            if (state.items[i].slug === slug) return state.items[i];
        }
        return null;
    }

    /* Render the center-panel tree of menu item cards. */
    function renderTree(state, els) {
        var tree = els.tree;
        tree.innerHTML = '';

        state.items.forEach(function(item) {
            var row = document.createElement('div');
            row.className = 'wpt-me-item';
            if (state.selectedSlug === item.slug) row.classList.add('is-selected');
            if (item.hidden) row.classList.add('is-hidden');
            row.setAttribute('data-slug', item.slug);
            row.setAttribute('draggable', 'true');
            row.setAttribute('role', 'option');

            row.innerHTML =
                '<i class="fas fa-grip-vertical wpt-me-item-drag" aria-hidden="true"></i>' +
                '<div class="wpt-me-item-icon"><span class="dashicons ' + escAttr(item.icon || 'dashicons-admin-generic') + '" aria-hidden="true"></span></div>' +
                '<div class="wpt-me-item-info">' +
                    '<div class="wpt-me-item-label">' +
                        esc(item.label) +
                        (item.renamed ? ' <span class="wpt-me-item-badge">renamed</span>' : '') +
                    '</div>' +
                    '<div class="wpt-me-item-slug">' + esc(item.slug) + '</div>' +
                '</div>' +
                '<label class="wpt-me-item-toggle" aria-label="Hide ' + escAttr(item.label) + '">' +
                    '<span class="toggle">' +
                        '<input type="checkbox" class="wpt-me-item-visible" ' + (item.hidden ? '' : 'checked') + '>' +
                        '<span class="toggle-track"></span>' +
                    '</span>' +
                '</label>';

            tree.appendChild(row);

            if (item.separator) {
                var sep = document.createElement('div');
                sep.className = 'wpt-me-separator-marker';
                tree.appendChild(sep);
            }
        });

        if (els.treeCount) {
            var n = state.items.length;
            els.treeCount.textContent = n + ' ' + (n === 1 ? 'item' : 'items');
        }
    }

    /* Render the left-panel live sidebar preview. */
    function renderPreview(state, els) {
        var nav = els.preview;
        nav.innerHTML = '';

        state.items.forEach(function(item) {
            if (item.hidden) return; // hidden items don't render in preview

            var row = document.createElement('div');
            row.className = 'wpt-me-preview-item';
            if (state.selectedSlug === item.slug) row.classList.add('is-selected');
            row.setAttribute('data-slug', item.slug);
            row.innerHTML =
                '<span class="dashicons ' + escAttr(item.icon || 'dashicons-admin-generic') + '" aria-hidden="true"></span>' +
                '<span>' + esc(item.label) + '</span>';
            nav.appendChild(row);

            if (item.separator) {
                var sep = document.createElement('div');
                sep.className = 'wpt-me-preview-separator';
                nav.appendChild(sep);
            }
        });
    }

    /* Wire tree-level interactions: selection + hide toggle + drag-drop. */
    function bindTreeInteractions(state, els) {
        var tree = els.tree;

        /* Click → select */
        tree.addEventListener('click', function(e) {
            if (e.target.closest('.toggle')) return; // let the hide toggle handle itself
            if (e.target.closest('.wpt-me-item-drag')) return;
            var row = e.target.closest('.wpt-me-item');
            if (!row) return;
            selectItem(state, els, row.getAttribute('data-slug'));
        });

        /* Hide/show toggle */
        tree.addEventListener('change', function(e) {
            if (!e.target.classList.contains('wpt-me-item-visible')) return;
            var row = e.target.closest('.wpt-me-item');
            if (!row) return;
            var slug = row.getAttribute('data-slug');
            var item = findItem(state, slug);
            if (!item) return;
            item.hidden = !e.target.checked; // checkbox is "visible"
            renderTree(state, els);
            renderPreview(state, els);
            if (state.selectedSlug === slug) {
                els.inputHidden.checked = item.hidden;
            }
        });

        /* HTML5 drag-drop reorder */
        var draggingSlug = null;

        tree.addEventListener('dragstart', function(e) {
            var row = e.target.closest('.wpt-me-item');
            if (!row) return;
            draggingSlug = row.getAttribute('data-slug');
            row.classList.add('is-dragging');
            try {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', draggingSlug);
            } catch (_e) {}
        });

        tree.addEventListener('dragend', function(e) {
            var row = e.target.closest('.wpt-me-item');
            if (row) row.classList.remove('is-dragging');
            tree.querySelectorAll('.is-drop-target').forEach(function(el) {
                el.classList.remove('is-drop-target');
            });
            draggingSlug = null;
        });

        tree.addEventListener('dragover', function(e) {
            if (!draggingSlug) return;
            e.preventDefault();
            var row = e.target.closest('.wpt-me-item');
            tree.querySelectorAll('.is-drop-target').forEach(function(el) {
                el.classList.remove('is-drop-target');
            });
            if (row && row.getAttribute('data-slug') !== draggingSlug) {
                row.classList.add('is-drop-target');
            }
        });

        tree.addEventListener('drop', function(e) {
            if (!draggingSlug) return;
            e.preventDefault();
            var targetRow = e.target.closest('.wpt-me-item');
            if (!targetRow) return;
            var targetSlug = targetRow.getAttribute('data-slug');
            if (targetSlug === draggingSlug) return;

            var fromIdx = state.items.findIndex(function(it) { return it.slug === draggingSlug; });
            var toIdx   = state.items.findIndex(function(it) { return it.slug === targetSlug; });
            if (fromIdx < 0 || toIdx < 0) return;

            var moved = state.items.splice(fromIdx, 1)[0];
            state.items.splice(toIdx, 0, moved);

            renderTree(state, els);
            renderPreview(state, els);
        });
    }

    /* Load a tree item into the right-panel form + highlight it. */
    function selectItem(state, els, slug) {
        var item = findItem(state, slug);
        if (!item) return;

        state.selectedSlug = slug;

        // Update tree + preview selection styling
        els.tree.querySelectorAll('.wpt-me-item').forEach(function(row) {
            row.classList.toggle('is-selected', row.getAttribute('data-slug') === slug);
        });
        els.preview.querySelectorAll('.wpt-me-preview-item').forEach(function(row) {
            row.classList.toggle('is-selected', row.getAttribute('data-slug') === slug);
        });

        // Fill form
        els.propsBody.hidden = false;
        els.propsEmpty.hidden = true;
        els.propsTitle.textContent = 'Edit: ' + item.label;
        els.propsSubtitle.textContent = 'Slug: ' + item.slug;
        els.inputLabel.value = item.renamed ? item.label : '';
        els.inputLabel.placeholder = item.originalLabel || item.label;
        els.inputSlug.value = item.slug;
        els.inputIconManual.value = item.iconOverridden ? item.icon : '';
        els.inputIconManual.placeholder = item.originalIcon || 'dashicons-admin-generic';
        els.inputHidden.checked = item.hidden;
        els.inputSeparator.checked = item.separator;

        // Highlight the chosen icon in the palette
        els.iconPicker.querySelectorAll('.wpt-me-icon-option').forEach(function(btn) {
            btn.classList.toggle('is-selected', btn.getAttribute('data-icon') === item.icon);
        });
    }

    /* Wire the right-panel property inputs to update state live. */
    function bindPropsInteractions(state, els) {
        function currentItem() {
            return state.selectedSlug ? findItem(state, state.selectedSlug) : null;
        }

        els.inputLabel.addEventListener('input', function() {
            var item = currentItem();
            if (!item) return;
            var val = this.value.trim();
            if (val === '' || val === item.originalLabel) {
                item.label = item.originalLabel;
                item.renamed = false;
            } else {
                item.label = val;
                item.renamed = true;
            }
            renderTree(state, els);
            renderPreview(state, els);
            // Restore selection state after re-render
            selectItem(state, els, item.slug);
        });

        els.inputIconManual.addEventListener('input', function() {
            var item = currentItem();
            if (!item) return;
            var val = this.value.trim();
            if (val === '' || val === item.originalIcon) {
                item.icon = item.originalIcon;
                item.iconOverridden = false;
            } else if (/^dashicons-[a-z0-9-]+$/.test(val)) {
                item.icon = val;
                item.iconOverridden = true;
            } else {
                return; // invalid pattern — don't touch state
            }
            renderTree(state, els);
            renderPreview(state, els);
            selectItem(state, els, item.slug);
        });

        els.inputHidden.addEventListener('change', function() {
            var item = currentItem();
            if (!item) return;
            item.hidden = this.checked;
            renderTree(state, els);
            renderPreview(state, els);
            selectItem(state, els, item.slug);
        });

        els.inputSeparator.addEventListener('change', function() {
            var item = currentItem();
            if (!item) return;
            item.separator = this.checked;
            renderTree(state, els);
            renderPreview(state, els);
            selectItem(state, els, item.slug);
        });

        /* Icon palette clicks */
        els.iconPicker.addEventListener('click', function(e) {
            var btn = e.target.closest('.wpt-me-icon-option');
            if (!btn) return;
            e.preventDefault();
            var item = currentItem();
            if (!item) return;

            var icon = btn.getAttribute('data-icon');
            item.icon = icon;
            item.iconOverridden = icon !== item.originalIcon;

            // Fire input event on the manual text input so it syncs
            els.inputIconManual.value = item.iconOverridden ? icon : '';
            renderTree(state, els);
            renderPreview(state, els);
            selectItem(state, els, item.slug);
        });

        /* Revert: clear all customizations for the selected item */
        els.revertBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var item = currentItem();
            if (!item) return;
            if (!confirm('Revert all changes to this menu item?')) return;

            item.label = item.originalLabel;
            item.icon = item.originalIcon;
            item.hidden = false;
            item.separator = false;
            item.renamed = false;
            item.iconOverridden = false;

            renderTree(state, els);
            renderPreview(state, els);
            selectItem(state, els, item.slug);
        });
    }

    /* Reset everything — confirm, clear all customizations, keep natural order. */
    function bindResetButton(state, els) {
        if (!els.resetBtn) return;
        els.resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Reset all menu customizations? Unsaved changes will be lost.')) return;

            state.items.forEach(function(item) {
                item.label = item.originalLabel;
                item.icon = item.originalIcon;
                item.hidden = false;
                item.separator = false;
                item.renamed = false;
                item.iconOverridden = false;
            });
            /* Note: we don't reset order here because we can't recover the
               original server-side order from client state. The user can
               drag items back or refresh the page to re-pull server state. */

            renderTree(state, els);
            renderPreview(state, els);
            if (state.selectedSlug) {
                selectItem(state, els, state.selectedSlug);
            }
        });
    }

    /* Before submit: serialize state into the hidden inputs. */
    function bindFormSubmit(state, els) {
        if (!els.form) return;
        els.form.addEventListener('submit', function() {
            var order = [];
            var hiddenItems = [];
            var renamed = {};
            var icons = {};
            var separators = [];

            state.items.forEach(function(item) {
                order.push(item.slug);
                if (item.hidden) hiddenItems.push(item.slug);
                if (item.renamed) renamed[item.slug] = item.label;
                if (item.iconOverridden) icons[item.slug] = item.icon;
                if (item.separator) separators.push(item.slug);
            });

            els.hiddenMenuOrder.value    = order.join(',');
            els.hiddenHiddenItems.value  = hiddenItems.join(',');
            els.hiddenRenamedJson.value  = JSON.stringify(renamed);
            els.hiddenIconsJson.value    = JSON.stringify(icons);
            els.hiddenSeparators.value   = separators.join(',');
        });
    }

    function escAttr(s) {
        if (s == null) return '';
        return String(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ──────────────────────────────────────
       SIDEBAR SEARCH → COMMAND PALETTE
    ────────────────────────────────────── */
    /* ──────────────────────────────────────
       COMMAND PALETTE
    ────────────────────────────────────── */
    function initCommandPalette() {
        cmdOverlay = document.getElementById('wptCmdOverlay');
        cmdInput = document.getElementById('wptCmdInput');
        cmdResults = document.getElementById('wptCmdResults');
        if (!cmdOverlay || !cmdInput) return;

        selectedIdx = -1;

        document.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                toggleCmdPalette();
            }
            if (e.key === 'Escape' && cmdOverlay.classList.contains('open')) {
                closeCmdPalette();
            }
        });

        cmdOverlay.addEventListener('click', function(e) {
            if (e.target === cmdOverlay) closeCmdPalette();
        });

        cmdInput.addEventListener('input', function() {
            renderCmdResults(this.value.toLowerCase().trim());
        });

        cmdInput.addEventListener('keydown', function(e) {
            var items = cmdResults.querySelectorAll('.wpt-cmd-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIdx = Math.min(selectedIdx + 1, items.length - 1);
                updateCmdSelection(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIdx = Math.max(selectedIdx - 1, 0);
                updateCmdSelection(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedIdx >= 0 && items[selectedIdx]) {
                    var url = items[selectedIdx].dataset.url;
                    if (url) window.location.href = url;
                }
            }
        });

        renderCmdResults('');
    }

    function toggleCmdPalette() {
        if (cmdOverlay.classList.contains('open')) closeCmdPalette();
        else openCmdPalette();
    }

    function openCmdPalette() {
        if (!cmdOverlay) return;
        cmdOverlay.classList.add('open');
        if (cmdInput) {
            cmdInput.value = '';
            renderCmdResults('');
            setTimeout(function() { cmdInput.focus(); }, 100);
        }
        selectedIdx = -1;
    }

    function closeCmdPalette() {
        if (!cmdOverlay) return;
        cmdOverlay.classList.remove('open');
        if (cmdInput) cmdInput.value = '';
        selectedIdx = -1;
    }

    function cmdItemHtml(url, iconClass, colorClass, title, desc) {
        return '<div class="wpt-cmd-item" data-url="' + esc(url) + '">' +
            '<div class="wpt-cmd-icon ' + esc(colorClass) + '"><i class="fas ' + esc(iconClass) + '"></i></div>' +
            '<div class="wpt-cmd-text"><span>' + esc(title) + '</span><small>' + esc(desc) + '</small></div>' +
            '</div>';
    }

    function renderCmdResults(query) {
        if (!cmdResults || typeof wptAdmin === 'undefined' || !wptAdmin.modules) return;

        var html = '';
        var modules = wptAdmin.modules;

        var quickActions = [
            { title: 'New Post', desc: 'Create a new blog post', icon: 'fa-plus', color: 'blue', url: wptAdmin.adminUrl + 'post-new.php' },
            { title: 'New Page', desc: 'Create a new page', icon: 'fa-plus', color: 'green', url: wptAdmin.adminUrl + 'post-new.php?post_type=page' },
            { title: 'Upload Media', desc: 'Upload images and files', icon: 'fa-cloud-upload-alt', color: 'violet', url: wptAdmin.adminUrl + 'media-new.php' }
        ];

        var navPages = [
            { title: 'All Posts', desc: 'View and manage posts', icon: 'fa-file-alt', color: 'blue', url: wptAdmin.adminUrl + 'edit.php' },
            { title: 'All Pages', desc: 'View and manage pages', icon: 'fa-copy', color: 'green', url: wptAdmin.adminUrl + 'edit.php?post_type=page' },
            { title: 'Media Library', desc: 'Browse media files', icon: 'fa-images', color: 'violet', url: wptAdmin.adminUrl + 'upload.php' },
            { title: 'Plugins', desc: 'Manage installed plugins', icon: 'fa-plug', color: 'amber', url: wptAdmin.adminUrl + 'plugins.php' },
            { title: 'Settings', desc: 'General WordPress settings', icon: 'fa-cog', color: 'rose', url: wptAdmin.adminUrl + 'options-general.php' },
            { title: 'Users', desc: 'Manage user accounts', icon: 'fa-users', color: 'blue', url: wptAdmin.adminUrl + 'users.php' }
        ];

        var matchedActions = quickActions;
        var matchedModules = modules;
        var matchedNav = navPages;

        if (query) {
            matchedActions = quickActions.filter(function(a) {
                return a.title.toLowerCase().indexOf(query) !== -1 ||
                       a.desc.toLowerCase().indexOf(query) !== -1;
            });
            matchedModules = modules.filter(function(m) {
                return m.title.toLowerCase().indexOf(query) !== -1 ||
                       m.category.toLowerCase().indexOf(query) !== -1;
            });
            matchedNav = navPages.filter(function(p) {
                return p.title.toLowerCase().indexOf(query) !== -1 ||
                       p.desc.toLowerCase().indexOf(query) !== -1;
            });
        }

        if (matchedActions.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Quick Actions</div>';
            matchedActions.forEach(function(a) {
                html += cmdItemHtml(a.url, a.icon, a.color, a.title, a.desc);
            });
            html += '</div>';
        }

        if (matchedModules.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Modules</div>';
            matchedModules.slice(0, 8).forEach(function(m) {
                var statusDesc = m.active ? 'Currently enabled' : 'Currently disabled';
                var colorClass = m.active ? 'blue' : '';
                html += cmdItemHtml(m.settingsUrl, m.icon, colorClass, m.title, statusDesc);
            });
            html += '</div>';
        }

        if (matchedNav.length) {
            html += '<div class="wpt-cmd-group">';
            html += '<div class="wpt-cmd-group-label">Navigate</div>';
            matchedNav.forEach(function(p) {
                html += cmdItemHtml(p.url, p.icon, p.color, p.title, p.desc);
            });
            html += '</div>';
        }

        if (!html) {
            html = '<div class="wpt-cmd-group"><div class="wpt-cmd-group-label">No results found</div></div>';
        }

        cmdResults.innerHTML = html;
        selectedIdx = -1;

        cmdResults.querySelectorAll('.wpt-cmd-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var url = this.dataset.url;
                if (url) window.location.href = url;
            });
        });
    }

    function updateCmdSelection(items) {
        items.forEach(function(item, i) {
            item.classList.toggle('selected', i === selectedIdx);
        });
        if (items[selectedIdx]) {
            items[selectedIdx].scrollIntoView({ block: 'nearest' });
        }
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* ──────────────────────────────────────
       ANIMATED BENTO COUNTERS
       Source: wp-transformation-final.html animateCounters()
    ────────────────────────────────────── */
    function animateCounters() {
        document.querySelectorAll('.bento-value[data-count]').forEach(function(el) {
            var target = parseInt(el.dataset.count, 10);
            var suffix = el.dataset.suffix || '';
            if (isNaN(target) || target === 0) {
                el.textContent = '0' + suffix;
                return;
            }
            var current = 0;
            var step = Math.max(1, Math.floor(target / 35));
            var interval = setInterval(function() {
                current += step;
                if (current >= target) {
                    current = target;
                    clearInterval(interval);
                }
                el.textContent = current + suffix;
            }, 28);
        });
    }

})();
