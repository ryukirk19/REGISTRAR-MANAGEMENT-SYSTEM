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
        el.addEventListener('change', () => el.form && el.form.submit());
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

    // Print certificate pages open the print dialog automatically
    if (document.body.dataset.autoprint === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 350));
    }
})();
