document.addEventListener('DOMContentLoaded', () => {
 const el = id => document.getElementById(id);
 let token = location.hash.slice(1), saved;
 try { saved = sessionStorage.getItem('office-setup-token'); if (token) sessionStorage.setItem('office-setup-token', token); } catch {}
 token ||= saved || '';
 history.replaceState(null, '', location.pathname);
 let status = null, submitting = false, draft = null, timer, failures = 0, percent = 0, jobId, modalOpened = false;
 const dialog = el('installDialog');
 const showDialog = () => { if (!dialog.open) dialog.showModal(); };
 const fail = (message, modal = false) => { const node = el(modal ? 'installError' : 'pageError'); node.textContent = message; node.hidden = false; };
 const api = async (path, data) => {
  const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), path === 'license' ? 75000 : 20000);
  try {
   const response = await fetch('/api/' + path, { method: data ? 'POST' : 'GET', cache: 'no-store', credentials: 'omit', signal: controller.signal,
    headers: { Authorization: 'Bearer ' + token, Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}) }, ...(data ? { body: JSON.stringify(data) } : {}) });
   let body; try { body = await response.json(); } catch { throw new Error('پاسخ راه‌انداز قابل خواندن نیست؛ اتصال سرور را بررسی کنید.'); }
   if (!response.ok) { const error = new Error(body.message || 'درخواست انجام نشد.'); error.status = response.status; error.retryAfter = Number(response.headers.get('Retry-After')) || 60; throw error; }
   return body;
  } catch (error) { if (error.name === 'AbortError') throw new Error('پاسخ در زمان مقرر دریافت نشد؛ وضعیت نصب دوباره بررسی می‌شود.'); throw error; }
  finally { clearTimeout(timeout); }
 };
 const stages = { authorization: 'بررسی لایسنس', download: 'دریافت بسته', build: 'ساخت و بررسی نسخه', configuration: 'تنظیمات', database: 'دیتابیس و کش', migration: 'آماده‌سازی دیتابیس', administrator: 'حساب مدیر', services: 'سرویس‌ها', health: 'بررسی سلامت', confirmation: 'ثبت نتیجه', complete: 'تکمیل' };
 const render = value => {
  status = value;
  el('connecting').hidden = true;
  const job = value.job || {}, hasJob = ['running', 'error', 'success'].includes(job.status);
  el('licensePage').hidden = value.authorized || hasJob;
  el('settingsPage').hidden = !value.authorized || hasJob;
  el('operationPage').hidden = !hasJob;
  ['stepLicense','stepSettings','stepInstall'].forEach((id, i) => el(id).classList.toggle('active', i === (hasJob ? 2 : value.authorized ? 1 : 0)));
  el('confirmVersion').textContent = el('version').textContent = el('operationVersion').textContent = 'v' + (value.version || '');
  if (value.authorized && !hasJob && !draft) {
   const d = value.deployment || {};
   [['appUrl', 'app_url'], ['adminName', 'admin_name'], ['adminEmail', 'admin_email'], ['bindIp', 'bind_ip'], ['port', 'port'], ['proxyNetwork', 'proxy_network']].forEach(([id, key]) => el(id).value = d[key] || '');
  }
  if (!hasJob) return;
  if (job.id !== jobId) { jobId = job.id; percent = 0; }
  percent = job.status === 'success' ? 100 : Math.max(percent, Math.min(99, Number(job.progress) || 0));
  el('installProgress').hidden = false; el('confirmDescription').hidden = true;
  el('installPercent').textContent = percent + '%'; el('installBar').style.width = percent + '%'; el('installTrack').setAttribute('aria-valuenow', String(percent));
  el('installTrack').classList.toggle('failed', job.status === 'error');
  el('installMessage').textContent = job.message || 'در حال نصب…'; el('installStage').textContent = 'مرحله: ' + (stages[job.stage] || job.stage || '—');
  el('installError').hidden = !job.error; if (job.error) el('installError').textContent = job.error;
  el('confirmInstall').hidden = job.status !== 'error'; el('confirmInstall').textContent = 'تأیید و ادامهٔ نصب'; el('confirmInstall').disabled = submitting;
  el('cancelInstall').textContent = 'بستن پنجره'; el('dialogTitle').textContent = el('operationTitle').textContent = job.status === 'success' ? 'Office آمادهٔ استفاده است' : job.status === 'error' ? 'نصب نیاز به ادامه دارد' : 'در حال نصب Office';
  el('successDetails').hidden = job.status !== 'success';
  if (job.status === 'success') {
   const d = value.deployment || {}; el('panelLink').textContent = d.app_url; el('panelLink').href = d.app_url + '/login'; el('loginEmail').textContent = d.admin_email;
   el('operationDescription').textContent = 'نصب، بررسی سلامت و فعال‌سازی لایسنس با موفقیت تکمیل شد.';
   el('showProgress').textContent = 'مشاهدهٔ نتیجهٔ نصب';
  }
  if (!modalOpened) { modalOpened = true; showDialog(); }
  clearTimeout(timer);
  if (job.status === 'running') timer = setTimeout(poll, 2500);
 };
 const poll = async () => {
  try { render(await api('status')); failures = 0; }
  catch (error) {
   fail(error.message, dialog.open); failures++;
   if (error.status !== 401) timer = setTimeout(poll, error.status === 429 ? error.retryAfter * 1000 : Math.min(30000, failures * 5000));
  }
 };
 el('licenseForm').addEventListener('submit', async event => {
  event.preventDefault(); if (submitting) return;
  submitting = true; el('verifyLicense').disabled = true; el('pageError').hidden = true;
  el('verifyLicense').textContent = 'در حال بررسی لایسنس…';
  try { render(await api('license', { license: el('license').value.trim() })); el('license').value = ''; }
  catch (error) { fail(error.message); }
  finally { submitting = false; el('verifyLicense').disabled = false; el('verifyLicense').textContent = 'تأیید و ادامه ←'; }
 });
 el('settingsForm').addEventListener('submit', event => {
  event.preventDefault(); if (submitting) return;
  if (el('password').value !== el('passwordConfirmation').value) { fail('رمز ورود و تکرار رمز یکسان نیستند.'); return; }
  el('pageError').hidden = true; el('installError').hidden = true;
  draft = { deployment: { app_url: el('appUrl').value, admin_name: el('adminName').value.trim(), admin_email: el('adminEmail').value.trim(), bind_ip: el('bindIp').value.trim(), port: Number(el('port').value), proxy_network: el('proxyNetwork').value.trim() }, password: el('password').value, password_confirmation: el('passwordConfirmation').value, confirm: true, expected_version: status.version };
  showDialog();
 });
 el('confirmInstall').addEventListener('click', async () => {
  if (submitting || status?.job?.status === 'running') return;
  const request = status?.configured ? { confirm: true, expected_version: status.version } : draft;
  if (!request) return;
  submitting = true; el('confirmInstall').disabled = true; el('cancelInstall').textContent = 'بستن پنجره';
  el('installProgress').hidden = false; el('installError').hidden = true; el('installMessage').textContent = 'در حال ثبت تنظیمات و آغاز نصب…';
  try { render(await api('install', request)); draft = null; el('password').value = el('passwordConfirmation').value = ''; }
  catch (error) {
   fail(error.message, true);
   // Discover a lost response. Never repeat an install POST automatically.
   timer = setTimeout(poll, error.status === 429 ? error.retryAfter * 1000 : 2500);
  }
  finally { submitting = false; el('confirmInstall').disabled = false; }
 });
 const close = () => dialog.close();
 el('closeDialog').addEventListener('click', close); el('cancelInstall').addEventListener('click', close); el('showProgress').addEventListener('click', showDialog);
 el('theme').addEventListener('click', () => { const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try { localStorage.setItem('office-setup-theme', theme); } catch {} });
 try { document.documentElement.dataset.theme = localStorage.getItem('office-setup-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch {}
 if (!/^[a-f0-9]{64}$/.test(token)) { el('connecting').hidden = true; fail('صفحه را با لینک کامل و خصوصی که دستور نصب اعلام کرده است باز کنید.'); return; }
 poll();
});
