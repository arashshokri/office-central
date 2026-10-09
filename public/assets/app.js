document.addEventListener('DOMContentLoaded', () => {
    const menuButton = document.querySelector('[data-menu-toggle]');
    const closeMenu = () => {
        document.body.classList.remove('open');
        menuButton?.setAttribute('aria-expanded', 'false');
    };
    menuButton?.addEventListener('click', () => {
        if (window.matchMedia('(min-width: 761px)').matches) {
            const collapsed = document.body.classList.toggle('sidebar-collapsed');
            menuButton.setAttribute('aria-expanded', String(!collapsed));
            try { localStorage.setItem('central-sidebar-collapsed', String(collapsed)); } catch {}
            return;
        }
        const open = document.body.classList.toggle('open');
        menuButton.setAttribute('aria-expanded', String(open));
    });
    document.querySelector('[data-menu-close]')?.addEventListener('click', closeMenu);
    document.querySelectorAll('aside nav a').forEach(link => link.addEventListener('click', closeMenu));

    let storedTheme;
    try {
        storedTheme = localStorage.getItem('central-theme');
        document.body.classList.toggle('sidebar-collapsed', localStorage.getItem('central-sidebar-collapsed') === 'true');
    } catch {}
    if (storedTheme) document.documentElement.dataset.theme = storedTheme;
    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('central-theme', next); } catch {}
    });

    document.querySelectorAll('[data-copy-target]').forEach(button => button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget);
        if (!target) return;
        try {
            await navigator.clipboard.writeText(target.textContent.trim());
        } catch {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(target);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        const original = button.textContent;
        button.textContent = '✓';
        window.setTimeout(() => button.textContent = original, 1400);
    }));

    const dialog = document.querySelector('[data-confirm-dialog]');
    let pendingForm = null;
    document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
        if (!dialog?.showModal) return;
        event.preventDefault();
        pendingForm = button.form;
        dialog.querySelector('[data-confirm-message]').textContent = button.dataset.confirm;
        dialog.showModal();
    }));
    dialog?.addEventListener('close', () => {
        if (dialog.returnValue === 'confirm' && pendingForm) pendingForm.requestSubmit();
        pendingForm = null;
    });

    document.querySelectorAll('[data-feature-plan]').forEach(plan => {
        const form = plan.closest('form');
        const policy = plan.querySelector('[data-feature-policy]');
        const product = form.querySelector('[name="product_id"], [data-plan-product]');
        const update = () => {
            const selected = policy.value === 'selected';
            plan.querySelector('[data-feature-choices]').hidden = !selected;
            plan.querySelectorAll('[data-feature-product]').forEach(choice => {
                const relevant = choice.dataset.featureProduct === product?.value;
                choice.hidden = !relevant;
                const input = choice.querySelector('[name="features[]"]');
                if (input) input.disabled = !selected || !relevant;
            });
        };
        policy.addEventListener('change', update);
        product?.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-package-file]').forEach(input => input.addEventListener('change', () => {
        const label = input.closest('label').querySelector('[data-package-name]');
        label.textContent = input.files?.length ? input.files[0].name : '';
    }));
});
