(function () {
    'use strict';

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    // Sidebar (mobile)
    const sidebar = $('#sidebar');
    $$('[data-sidebar-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            sidebar && sidebar.classList.toggle('is-open');
        });
    });
    document.addEventListener('click', (e) => {
        if (!sidebar || !sidebar.classList.contains('is-open')) return;
        if (sidebar.contains(e.target) || e.target.closest('[data-sidebar-toggle]')) return;
        sidebar.classList.remove('is-open');
    });

    // Dialogs
    const openDialog = (dialog) => {
        if (!dialog) return;
        if (!dialog.open) dialog.showModal();
        const first = dialog.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
        if (first) setTimeout(() => first.focus(), 40);
    };
    $$('[data-dialog-open]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            openDialog($(btn.getAttribute('data-dialog-open')));
        });
    });
    $$('[data-dialog-close]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const d = btn.closest('dialog');
            d && d.close();
        });
    });
    $$('dialog.modal').forEach((dialog) => {
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) dialog.close();
        });
        if (dialog.dataset.autopen === '1') {
            setTimeout(() => openDialog(dialog), 60);
        }
    });

    // Confirm destructive submissions
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-confirm]');
        if (!el) return;
        if (!window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
        }
    });

    // Live table filter
    $$('input[data-filter-table]').forEach((input) => {
        const table = $(input.getAttribute('data-filter-table'));
        if (!table) return;
        const rows = $$('tbody tr', table);
        const empty = table.closest('.card') && $('.filter-empty', table.closest('.card'));
        const apply = () => {
            const q = input.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach((row) => {
                const hit = !q || row.textContent.toLowerCase().includes(q);
                row.style.display = hit ? '' : 'none';
                if (hit) shown++;
            });
            if (empty) empty.style.display = shown ? 'none' : '';
        };
        input.addEventListener('input', apply);
        const clear = input.parentElement && $('[data-filter-clear]', input.parentElement);
        clear && clear.addEventListener('click', () => {
            input.value = '';
            apply();
            input.focus();
        });
    });

    // Copy buttons
    $$('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const text = btn.getAttribute('data-copy');
            try {
                await navigator.clipboard.writeText(text);
                const original = btn.innerHTML;
                btn.innerHTML = btn.getAttribute('data-copy-done') || 'Copied';
                setTimeout(() => { btn.innerHTML = original; }, 1400);
            } catch (err) {
                window.alert('Copy failed. Please select the text manually.');
            }
        });
    });

    // Auto-submit selects
    $$('[data-autosubmit]').forEach((el) => {
        el.addEventListener('change', () => {
            if (!el.form) return;
            if (el.form.requestSubmit) el.form.requestSubmit();
            else el.form.submit();
        });
    });

    // Print
    $$('[data-print]').forEach((btn) => btn.addEventListener('click', () => window.print()));

    // Toasts
    const toastBox = $('#toasts');
    if (toastBox) {
        const hide = (toast) => {
            toast.style.transition = 'opacity .2s ease, transform .2s ease';
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(6px)';
            setTimeout(() => toast.remove(), 220);
        };
        $$('.toast', toastBox).forEach((toast) => {
            const close = $('button', toast);
            close && close.addEventListener('click', () => hide(toast));
            setTimeout(() => hide(toast), 6500);
        });
    }

    // Global search shortcut
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
            const search = $('.topbar-search input');
            if (search) {
                e.preventDefault();
                search.focus();
            }
        }
        if (e.key === 'Escape') {
            const open = $('dialog.modal[open]');
            open && open.close();
        }
    });

    // Prevent double-submit
    $$('form').forEach((form) => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type=submit]:not([data-allow-multi])');
            if (btn && !btn.disabled) {
                setTimeout(() => { btn.disabled = true; }, 10);
            }
        });
    });

    // Dependent storage selects: grade -> cabinet -> section shelf -> student folder
    $$('[data-storage-cabinet]').forEach((cabinet) => {
        const form = cabinet.closest('form');
        if (!form) return;
        const shelf = $('[data-storage-shelf]', form);
        const folder = $('[data-storage-folder]', form);
        if (!shelf || !folder) return;
        const gradeField = form.querySelector('select[name="grade_level"]');

        const filter = (select, predicate) => {
            let valid = false;
            $$('option', select).forEach((opt) => {
                if (!opt.value) {
                    opt.hidden = false;
                    opt.disabled = false;
                    return;
                }
                const match = predicate(opt);
                opt.hidden = !match;
                const taken = opt.dataset.full === '1' || opt.dataset.taken === '1';
                opt.disabled = !match || (taken && !opt.selected);
                if (opt.selected && match) valid = true;
            });
            if (!valid && select.value) select.value = '';
        };

        const gradeMatches = (opt) => !gradeField || !opt.dataset.grade || opt.dataset.grade === gradeField.value;

        const sync = () => {
            filter(cabinet, gradeMatches);
            filter(shelf, (opt) => opt.dataset.cabinet === cabinet.value);
            filter(folder, (opt) => opt.dataset.cabinet === cabinet.value && opt.dataset.shelf === shelf.value);
        };

        cabinet.addEventListener('change', sync);
        if (gradeField) gradeField.addEventListener('change', sync);
        shelf.addEventListener('change', () => {
            sync();
            const opt = shelf.selectedOptions[0];
            if (!opt || !opt.value) return;
            const section = opt.getAttribute('data-section') || '';
            const strand = opt.getAttribute('data-strand') || '';
            const sectionField = form.querySelector('[name="section"]');
            const strandField = form.querySelector('select[name="strand"]');
            if (section && sectionField) sectionField.value = section;
            if (strand && strandField) {
                if (!Array.from(strandField.options).some((o) => o.value === strand)) {
                    const option = document.createElement('option');
                    option.value = strand;
                    option.textContent = strand;
                    strandField.appendChild(option);
                }
                strandField.value = strand;
            }
        });
        sync();
    });

    // Print certificate pages open the print dialog automatically
    if (document.body.dataset.autoprint === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 350));
    }

    // Restore scroll position after form submits and pager clicks
    const SCROLL_KEY = 'regis:scroll';
    const saveScroll = () => {
        try {
            sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ path: location.pathname, y: window.scrollY || 0 }));
        } catch (err) { /* storage may be unavailable */ }
    };
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form && String(form.method).toLowerCase() === 'post') saveScroll();
    }, true);
    document.addEventListener('click', (e) => {
        if (e.target.closest('.pager a')) saveScroll();
    }, true);
    try {
        const saved = sessionStorage.getItem(SCROLL_KEY);
        if (saved) {
            sessionStorage.removeItem(SCROLL_KEY);
            const state = JSON.parse(saved);
            if (state.path === location.pathname && state.y > 0) {
                window.addEventListener('load', () => {
                    document.documentElement.style.scrollBehavior = 'auto';
                    window.scrollTo(0, state.y);
                });
            }
        }
    } catch (err) { /* ignore malformed state */ }

    // Release the account the moment the tab or window is closed
    const csrfMeta = document.querySelector('meta[name="regis-csrf"]');
    if (csrfMeta) {
        const post = (fields) => {
            const body = new FormData();
            body.append('csrf', csrfMeta.getAttribute('content'));
            Object.keys(fields).forEach((key) => body.append(key, fields[key]));
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/logout.php', body);
            } else {
                fetch('/logout.php', { method: 'POST', body, credentials: 'same-origin', keepalive: true });
            }
        };
        const keepSession = () => post({ keep: '1' });

        let internalNav = false;
        let navTimer = 0;
        const markInternal = () => {
            internalNav = true;
            clearTimeout(navTimer);
            navTimer = setTimeout(() => { internalNav = false; }, 8000);
        };
        const sameOrigin = (href) => {
            try { return new URL(href, location.href).origin === location.origin; }
            catch (err) { return false; }
        };
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (link.target && link.target !== '_self') return;
            if (link.hasAttribute('download')) return;
            if (sameOrigin(link.href)) markInternal();
        }, true);
        document.addEventListener('submit', () => { markInternal(); }, true);
        window.addEventListener('pageshow', (e) => {
            internalNav = false;
            clearTimeout(navTimer);
            if (e.persisted) keepSession();
        });
        const nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        if (nav && nav.type === 'reload') {
            setTimeout(keepSession, 1200);
        }
        window.addEventListener('pagehide', (e) => {
            if (e.persisted || internalNav) return;
            post({ auto: '1' });
        });
    }
})();
