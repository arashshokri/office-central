document.addEventListener('DOMContentLoaded', () => {
    const menuButton = document.querySelector('[data-menu-toggle]');
    const closeMenu = () => {
        document.body.classList.remove('open');
        menuButton?.setAttribute('aria-expanded', 'false');
    };
    menuButton?.addEventListener('click', () => {
        const open = document.body.classList.toggle('open');
        menuButton.setAttribute('aria-expanded', String(open));
    });
    document.querySelector('[data-menu-close]')?.addEventListener('click', closeMenu);
    document.querySelectorAll('aside nav a').forEach(link => link.addEventListener('click', closeMenu));

    const storedTheme = localStorage.getItem('central-theme');
    if (storedTheme) document.documentElement.dataset.theme = storedTheme;
    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('central-theme', next);
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
});
