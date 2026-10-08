document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('officeSystemUpdate');
    if (!root) return;
    const copyLicense = document.getElementById('copyLicense');
    copyLicense?.addEventListener('click', async () => {
        const input = document.getElementById('licenseKey');
        try { await navigator.clipboard.writeText(input.value); copyLicense.querySelector('span').textContent = 'کپی شد'; }
        catch { input.focus(); input.select(); }
    });
    const check = document.getElementById('checkUpdate'), install = document.getElementById('installUpdate');
    if (!check) return;
    const byId = id => document.getElementById(id);
    const initial = window.officeUpdateInitial || {};
    const error = byId('updateError'), progress = byId('checkProgress');
    let currentVersion = root.dataset.installedVersion, offer = null, confirmation = null;
    let running = false, checking = false, starting = false, reloadScheduled = false, pollTimer, progressTimer, percent = 0;
    const modal = new bootstrap.Modal(byId('officeUpdateConfirm'));
    const buttons = () => {
        check.disabled = running || checking || starting;
        install.disabled = running || checking || starting || !offer?.version;
        byId('confirmInstall').disabled = running || starting;
    };
    const api = async (url, method = 'GET', body = null) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), method === 'GET' ? 20000 : (url === root.dataset.installUrl ? 135000 : 75000));
        try {
            const response = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                ...(body ? { body: JSON.stringify(body) } : {}) });
            if ([401, 419].includes(response.status)) throw new Error('نشست شما منقضی شده است؛ دوباره وارد سامانه شوید.');
            let data;
            try { data = await response.json(); }
            catch { throw new Error('پاسخ سامانه قابل خواندن نیست؛ ممکن است نسخهٔ جدید در حال راه‌اندازی باشد.'); }
            if (!response.ok) throw new Error(data.message || 'درخواست انجام نشد: HTTP ' + response.status);
            return data;
        } catch (e) {
            if (e.name === 'AbortError') throw new Error('زمان انتظار پایان یافت. اتصال سرویس را بررسی و دوباره تلاش کنید.');
            throw e;
        } finally { clearTimeout(timeout); }
    };
    const fail = e => { error.hidden = false; error.textContent = e.message; };
    const setPercent = value => {
        percent = value;
        byId('checkPercent').textContent = value + '%';
        byId('checkProgressBar').style.width = value + '%';
        byId('checkProgressTrack').setAttribute('aria-valuenow', String(value));
    };
    const renderOffer = candidate => {
        offer = candidate?.available && candidate.version ? candidate : null;
        byId('updateOffer').hidden = !offer;
        byId('versionComparison').hidden = !offer;
        byId('currentVersion').textContent = 'v' + currentVersion;
        byId('offeredVersion').textContent = offer ? 'v' + offer.version : '';
        byId('releaseNotes').textContent = offer?.notes || '';
        byId('securityUpdate').hidden = !offer?.security;
        buttons();
    };
    const renderJob = job => {
        if (!job.status) return;
        const wasRunning = running;
        running = job.status === 'running';
        byId('updateProgress').hidden = false;
        byId('updateMessage').textContent = job.message || (running ? 'بروزرسانی در حال انجام است…' : 'وضعیت بروزرسانی');
        byId('updateStage').textContent = 'مرحله: ' + (job.stage || '—') + (job.version ? ' • نسخهٔ ' + job.version : '');
        byId('updateSpinner').hidden = !running;
        error.hidden = !job.error;
        if (job.error) error.textContent = job.error;
        if (job.status === 'error' && job.version) {
            renderOffer({ available: true, version: job.version, notes: 'عملیات قبلی کامل نشده است. پس از رفع خطای نمایش‌داده‌شده، برای ادامه تأیید کنید.' });
        }
        if (job.status === 'success') {
            renderOffer(null);
            if (wasRunning && !reloadScheduled) { reloadScheduled = true; setTimeout(() => location.reload(), 2500); }
        }
        buttons();
        clearTimeout(pollTimer);
        if (running) pollTimer = setTimeout(poll, 2500);
    };
    const poll = async () => {
        try { renderJob(await api(root.dataset.statusUrl)); }
        catch (e) { byId('updateMessage').textContent = 'در انتظار راه‌اندازی سرویس…'; fail(e); pollTimer = setTimeout(poll, 5000); }
    };
    check.addEventListener('click', async () => {
        if (checking || running || starting) return;
        checking = true; buttons(); error.hidden = true; progress.hidden = false;
        byId('checkMessage').textContent = 'در حال بررسی نسخه‌های مجاز…';
        byId('checkProgressBar').classList.remove('bg-danger');
        renderOffer(null); setPercent(0);
        // The request has no byte count. Keep the activity indicator below
        // completion until the helper actually returns an authoritative result.
        progressTimer = setInterval(() => setPercent(Math.min(90, percent + Math.max(1, Math.ceil((90 - percent) / 8)))), 300);
        try {
            const data = await api(root.dataset.checkUrl, 'POST');
            if (!data.status && data.confirmation_supported !== true) throw new Error('HELPER_UPGRADE_REQUIRED: سرویس بروزرسانی باید توسط مدیر سرور به‌روز شود. با نماینده فنی خود در ارتباط باشید.');
            clearInterval(progressTimer); setPercent(100);
            if (data.status) { byId('checkMessage').textContent = 'وضعیت عملیات دریافت شد.'; renderJob(data); }
            else {
                if (data.installed_version) currentVersion = data.installed_version;
                renderOffer(data.update);
                byId('checkMessage').textContent = offer ? 'بررسی کامل شد؛ نسخهٔ جدید آمادهٔ بروزرسانی است.' : 'بررسی کامل شد؛ نسخهٔ جدیدتری برای لایسنس شما در دسترس نیست.';
            }
        } catch (e) {
            clearInterval(progressTimer);
            byId('checkProgressBar').classList.add('bg-danger');
            byId('checkMessage').textContent = 'بررسی بروزرسانی انجام نشد.';
            fail(e);
        } finally { checking = false; buttons(); }
    });
    install.addEventListener('click', () => {
        if (!offer?.version || running || checking || starting) return;
        confirmation = { expected_version: offer.version, expected_release_id: offer.release_id || null };
        byId('confirmCurrentVersion').textContent = 'v' + currentVersion;
        byId('confirmNewVersion').textContent = 'v' + offer.version;
        modal.show();
    });
    byId('confirmInstall').addEventListener('click', async () => {
        if (!confirmation || starting || running) return;
        starting = true; buttons(); error.hidden = true; modal.hide();
        byId('updateProgress').hidden = false;
        byId('updateSpinner').hidden = false;
        byId('updateMessage').textContent = 'در حال تأیید نسخه و آغاز بروزرسانی…';
        byId('updateStage').textContent = 'نسخهٔ تأییدشده: ' + confirmation.expected_version;
        try { renderJob(await api(root.dataset.installUrl, 'POST', confirmation)); }
        catch (e) { byId('updateSpinner').hidden = true; byId('updateMessage').textContent = 'آغاز بروزرسانی انجام نشد؛ وضعیت عملیات را دوباره بررسی کنید.'; fail(e); }
        finally { starting = false; buttons(); }
    });
    byId('officeUpdateConfirm').addEventListener('hidden.bs.modal', () => { if (!starting) confirmation = null; });
    renderOffer(initial.offer);
    renderJob(initial.job || {});
});
