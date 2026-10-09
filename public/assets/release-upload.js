(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => {
        root.document.querySelectorAll('[data-release-upload]').forEach(form => api.attach(form, root));
    });
})(typeof window === 'undefined' ? globalThis : window, function () {
    function errorMessage(status, response, texts) {
        if (status === 422 && response?.errors) return Object.values(response.errors).flat().join('\n');
        const key = {413: 'tooLarge', 419: 'sessionExpired', 429: 'rateLimit', 502: 'proxyError', 504: 'proxyError'}[status];
        return (key && texts[key]) || texts.failed;
    }
    function attach(form, env) {
        const texts = JSON.parse(form.dataset.uploadText);
        const panel = form.querySelector('[data-upload-panel]');
        const progress = form.querySelector('[data-upload-progress]');
        const percent = form.querySelector('[data-upload-percent]');
        const status = form.querySelector('[data-upload-status]');
        const errors = form.querySelector('[data-upload-error]');
        let busy = false;
        env.addEventListener('beforeunload', event => {
            if (busy) { event.preventDefault(); event.returnValue = ''; }
        });
        form.addEventListener('submit', event => {
            if (busy) { event.preventDefault(); return; }
            const file = form.querySelector('[data-package-file]')?.files?.[0];
            if (!file || !env.XMLHttpRequest || !env.FormData) return;
            event.preventDefault();
            panel.hidden = false;
            errors.hidden = true;
            errors.textContent = '';
            progress.value = 0;
            percent.textContent = '0%';
            if (file.size > Number(form.dataset.maxUploadBytes)) {
                errors.textContent = texts.tooLarge;
                errors.hidden = false;
                status.textContent = texts.failed;
                return;
            }
            const body = new env.FormData(form);
            const controls = Array.from(form.querySelectorAll('input, select, textarea, button'))
                .map(control => [control, control.disabled]);
            controls.forEach(([control]) => control.disabled = true);
            busy = true;
            form.setAttribute('aria-busy', 'true');
            status.textContent = texts.uploading;
            const xhr = new env.XMLHttpRequest();
            const restore = () => {
                busy = false;
                form.setAttribute('aria-busy', 'false');
                controls.forEach(([control, disabled]) => control.disabled = disabled);
            };
            const fail = message => {
                restore();
                status.textContent = texts.failed;
                errors.textContent = message;
                errors.hidden = false;
            };
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 2100000;
            xhr.upload.onprogress = event => {
                if (!event.lengthComputable) {
                    progress.removeAttribute('value');
                    percent.textContent = '…';
                    return;
                }
                const value = Math.min(100, Math.floor(event.loaded / event.total * 100));
                progress.value = value;
                percent.textContent = value + '%';
                if (value === 100) status.textContent = texts.processing;
            };
            xhr.upload.onload = () => {
                progress.value = 100;
                percent.textContent = '100%';
                status.textContent = texts.processing;
            };
            xhr.onload = () => {
                let response;
                try { response = JSON.parse(xhr.responseText); } catch {}
                if (xhr.status >= 200 && xhr.status < 300 && typeof response?.redirect === 'string') {
                    let redirect;
                    try { redirect = new URL(response.redirect, env.location.href); } catch { fail(texts.failed); return; }
                    if (redirect.origin !== env.location.origin) { fail(texts.failed); return; }
                    restore();
                    status.textContent = texts.saved;
                    env.location.assign(redirect.href);
                } else fail(errorMessage(xhr.status, response, texts));
            };
            xhr.onerror = () => fail(texts.networkError);
            xhr.ontimeout = () => fail(texts.proxyError);
            xhr.onabort = () => fail(texts.networkError);
            try { xhr.send(body); } catch { fail(texts.networkError); }
        });
    }
    return {attach, errorMessage};
});
