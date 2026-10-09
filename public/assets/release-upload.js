(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => {
        root.document.querySelectorAll('[data-release-upload]').forEach(form => api.attach(form, root));
    });
})(typeof window === 'undefined' ? globalThis : window, function () {
    function errorMessage(status, response, texts) {
        if (status === 422 && response?.errors) return Object.values(response.errors).flat().join('\n');
        const key = {401: 'sessionExpired', 409: 'conflict', 413: 'tooLarge', 419: 'sessionExpired', 429: 'rateLimit', 502: 'proxyError', 504: 'proxyError'}[status];
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
        const transfer = {file: null, id: null, offset: 0};
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
            if (form.dataset.uploadUrl && typeof file.slice === 'function') {
                uploadChunks(form, env, file, body, transfer, texts, value => {
                    progress.value = value;
                    percent.textContent = value + '%';
                }, message => status.textContent = message).then(response => {
                    let redirect;
                    try { redirect = new URL(response.redirect, env.location.href); } catch { fail(texts.failed); return; }
                    if (redirect.origin !== env.location.origin) { fail(texts.failed); return; }
                    restore();
                    status.textContent = texts.saved;
                    env.location.assign(redirect.href);
                }).catch(error => fail(error.message || texts.failed));
                return;
            }
            const xhr = new env.XMLHttpRequest();
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

    async function uploadChunks(form, env, file, body, state, texts, progress, status) {
        const uploadUrl = new URL(form.dataset.uploadUrl, env.location.href);
        const saveUrl = new URL(form.action, env.location.href);
        if (uploadUrl.origin !== env.location.origin || saveUrl.origin !== env.location.origin) throw new Error(texts.failed);
        const request = (url, data, onProgress, final = false) => new Promise((resolve, reject) => {
            const xhr = new env.XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = final ? 2100000 : 90000;
            if (onProgress) xhr.upload.onprogress = event => {
                if (event.lengthComputable) onProgress(Math.min(1, event.loaded / event.total));
            };
            const failure = (message, retryable = false) => {
                const error = new Error(message);
                error.retryable = retryable;
                error.delay = Math.min(60, Math.max(1, Number(xhr.getResponseHeader?.('Retry-After')) || 1)) * 1000;
                reject(error);
            };
            xhr.onload = () => {
                let response;
                try { response = JSON.parse(xhr.responseText); } catch {}
                if (xhr.status >= 200 && xhr.status < 300 && response && typeof response === 'object') resolve(response);
                else failure(errorMessage(xhr.status, response, texts), [408, 429, 502, 503, 504].includes(xhr.status));
            };
            xhr.onerror = () => failure(texts.networkError, true);
            xhr.ontimeout = () => failure(texts.proxyError, true);
            xhr.onabort = () => failure(texts.networkError);
            try { xhr.send(data); } catch { failure(texts.networkError, true); }
        });
        const retry = async operation => {
            for (let attempt = 0; ; attempt++) {
                try { return await operation(); }
                catch (error) {
                    if (!error.retryable || attempt >= 2) throw error;
                    status(texts.retrying || texts.uploading);
                    await new Promise(resolve => env.setTimeout(resolve, Math.max(error.delay, 1000 * 2 ** attempt)));
                    status(state.offset === file.size ? texts.processing : texts.uploading);
                }
            }
        };
        if (state.file !== file) {
            if (state.id) {
                const cancel = new env.FormData();
                cancel.append('_token', body.get('_token') || '');
                cancel.append('_method', 'DELETE');
                await request(uploadUrl.href + '/' + state.id, cancel).catch(() => {});
            }
            state.file = file;
            state.id = null;
            state.offset = 0;
        }
        if (!state.id) {
            const init = new env.FormData();
            init.append('_token', body.get('_token') || '');
            init.append('filename', file.name);
            init.append('size', file.size);
            if (form.dataset.releaseId) init.append('release_id', form.dataset.releaseId);
            const response = await request(uploadUrl.href, init);
            if (!/^[a-f0-9-]{36}$/i.test(response.id || '') || response.chunk_bytes !== 262144 || response.offset !== 0) throw new Error(texts.failed);
            state.id = response.id;
        }
        progress(Math.floor(state.offset / file.size * 100));
        while (state.offset < file.size) {
            const start = state.offset;
            const end = Math.min(start + 262144, file.size);
            const chunk = new env.FormData();
            chunk.append('_token', body.get('_token') || '');
            chunk.append('offset', start);
            chunk.append('chunk', file.slice(start, end), 'chunk.bin');
            const response = await retry(() => request(uploadUrl.href + '/' + state.id + '/chunks', chunk,
                fraction => progress(Math.floor((start + fraction * (end - start)) / file.size * 100))));
            if (response.offset !== end) throw new Error(texts.failed);
            state.offset = end;
            progress(Math.floor(end / file.size * 100));
        }
        status(texts.processing);
        body.delete('package');
        body.set('package_upload', state.id);
        const response = await retry(() => request(saveUrl.href, body, null, true));
        if (typeof response.redirect !== 'string') throw new Error(texts.failed);
        return response;
    }
    return {attach, errorMessage};
});
