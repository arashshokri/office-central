const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../public/js/office-system-update.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const reply = (status, body = {}, retryAfter = null) => ({
    status, ok: status >= 200 && status < 300,
    headers: { get: name => name === 'Retry-After' ? retryAfter : null },
    json: async () => body,
});
const available = { confirmation_supported: true, installed_version: '3.8.24',
    update: { available: true, version: '3.8.25', release_id: 'test-release' } };

function ui(responses, initial = {}) {
    const elements = new Map(), calls = [], timers = new Map();
    let timerId = 0, now = 0, loaded;
    const element = id => {
        if (!elements.has(id)) elements.set(id, { hidden: true, disabled: false, textContent: '', style: {},
            dataset: {}, events: {}, classList: { add() {}, remove() {} }, setAttribute() {},
            addEventListener(name, fn) { this.events[name] = fn; } });
        return elements.get(id);
    };
    element('officeSystemUpdate').dataset = { installedVersion: '3.8.24', checkUrl: '/check', installUrl: '/install', statusUrl: '/status' };
    const schedule = (fn, delay, interval = false) => {
        const id = ++timerId;
        timers.set(id, { fn, at: now + delay, delay, interval });
        return id;
    };
    vm.runInNewContext(script, {
        document: { getElementById: element, querySelector: () => ({ content: 'csrf' }),
            addEventListener: (name, fn) => { loaded = fn; } },
        window: { officeUpdateInitial: initial }, navigator: {}, AbortController,
        bootstrap: { Modal: class { constructor(node) { this.node = node; } show() { this.node.open = true; } hide() { this.node.open = false; } } }, location: { reload() {} },
        setTimeout: (fn, delay) => schedule(fn, delay), clearTimeout: id => timers.delete(id),
        setInterval: (fn, delay) => schedule(fn, delay, true), clearInterval: id => timers.delete(id),
        fetch: async (url, options) => {
            calls.push({ url, method: options.method });
            assert.ok(responses.length, 'Unexpected network request');
            return responses.shift();
        },
    });
    loaded();
    return { element, calls, click: id => element(id).events.click(),
        async advance(ms) {
            const end = now + ms;
            while (true) {
                const next = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                const [id, timer] = next;
                now = timer.at;
                if (timer.interval) timer.at += timer.delay;
                else timers.delete(id);
                timer.fn();
                await flush();
            }
            now = end;
            await flush();
        } };
}

test('a check waits for Retry-After, disables repeated clicks and retries once', async () => {
    const page = ui([reply(429, { message: 'Too Many Attempts.' }, '3'), reply(200, available)]);
    const checking = page.click('checkUpdate');
    await flush();
    assert.equal(page.element('checkUpdate').disabled, true);
    assert.match(page.element('checkMessage').textContent, /3/);
    await page.click('checkUpdate');
    await page.advance(2000);
    assert.equal(page.calls.length, 1);
    assert.notEqual(page.element('checkPercent').textContent, '100%');
    await page.advance(1000);
    await checking;
    assert.equal(page.calls.length, 2);
    assert.equal(page.element('checkPercent').textContent, '100%');
    assert.equal(page.element('offeredVersion').textContent, 'v3.8.25');
    assert.equal(page.element('checkUpdate').disabled, false);
});

test('repeated throttling stops after one retry and displays a useful Persian error', async () => {
    const page = ui([reply(429, {}, '1'), reply(429, { message: 'Too Many Attempts.' }, '60')]);
    const checking = page.click('checkUpdate');
    await flush();
    await page.advance(1000);
    await checking;
    assert.equal(page.calls.length, 2);
    assert.match(page.element('updateError').textContent, /60/);
    assert.doesNotMatch(page.element('updateError').textContent, /Too Many Attempts/);
    assert.notEqual(page.element('checkPercent').textContent, '100%');
    assert.equal(page.element('checkUpdate').disabled, true);
    await page.click('checkUpdate');
    assert.equal(page.calls.length, 2);
    await page.advance(60000);
    assert.equal(page.calls.length, 2);
    assert.equal(page.element('checkUpdate').disabled, false);
});

test('status polling respects Retry-After and resumes at the server deadline', async () => {
    const page = ui([reply(429, {}, '12'), reply(200, { status: 'success' })], { job: { status: 'running' } });
    await page.advance(2500);
    assert.equal(page.calls.length, 1);
    await page.advance(11999);
    assert.equal(page.calls.length, 1);
    await page.advance(1);
    assert.equal(page.calls.length, 2);
    assert.equal(page.element('updateSpinner').hidden, true);
});

test('a throttled install is never automatically replayed', async () => {
    const page = ui([reply(429, {}, '10'), reply(200, {})], { offer: available.update });
    page.click('installUpdate');
    await page.click('confirmInstall');
    assert.match(page.element('updateError').textContent, /10/);
    assert.equal(page.element('installUpdate').disabled, true);
    assert.equal(page.element('confirmInstall').disabled, true);
    await page.click('installUpdate');
    await page.click('confirmInstall');
    assert.equal(page.calls.length, 1);
    await page.advance(9999);
    assert.equal(page.element('installUpdate').disabled, true);
    assert.match(page.element('updateMessage').textContent, /1 ثانیه/);
    await page.advance(1);
    assert.equal(page.element('installUpdate').disabled, false);
    await page.advance(60000);
    assert.deepEqual(page.calls, [{ url: '/install', method: 'POST' }, { url: '/status', method: 'GET' }]);
});

test('a throttled start still discovers an existing operation without replaying installation', async () => {
    const page = ui([reply(429, {}, '10'), reply(200, { id: 'job-a', status: 'running', stage: 'build', progress: 30 })],
        { offer: available.update });
    page.click('installUpdate');
    await page.click('confirmInstall');
    await page.advance(2500);
    assert.deepEqual(page.calls.map(call => call.method), ['POST', 'GET']);
    assert.equal(page.element('installPercent').textContent, '30%');
    assert.equal(page.element('installUpdate').disabled, true);
    await page.advance(1000);
    assert.match(page.element('updateMessage').textContent, /بروزرسانی در حال انجام/);
});

test('installation progress uses milestones, survives reload and reaches 100 only on success', async () => {
    const page = ui([reply(200, { id: 'job-a', status: 'running', stage: 'health', progress: 94 }),
        reply(200, { id: 'job-a', status: 'running', stage: 'confirmation', progress: 98 }),
        reply(200, { id: 'job-a', status: 'success', stage: 'complete', progress: 100 })],
        { job: { id: 'job-a', status: 'running', stage: 'build', progress: 30 } });
    assert.equal(page.element('installPercent').textContent, '30%');
    await page.advance(2500);
    assert.equal(page.element('installPercent').textContent, '94%');
    await page.advance(2500);
    assert.equal(page.element('installPercent').textContent, '98%');
    await page.advance(2500);
    assert.equal(page.element('installPercent').textContent, '100%');
    assert.equal(page.element('installProgressBar').style.width, '100%');
    assert.equal(page.element('installUpdate').disabled, true);
});

test('old helper milestones and failed installations never claim completion', async () => {
    const page = ui([reply(200, { id: 'job-a', status: 'error', stage: 'migration', progress: 100, error: 'SQLSTATE' })],
        { job: { id: 'job-a', status: 'running', stage: 'build' } });
    assert.equal(page.element('installPercent').textContent, '30%');
    await page.advance(2500);
    assert.equal(page.element('installPercent').textContent, '99%');
    assert.equal(page.element('updateError').textContent, 'SQLSTATE');
});

test('an old failed operation does not replace a newly authorized version', () => {
    const page = ui([], { offer: available.update,
        job: { id: 'old-job', status: 'error', version: '3.8.23', stage: 'build', error: 'previous build failed' } });
    assert.equal(page.element('offeredVersion').textContent, 'v3.8.25');
    page.click('installUpdate');
    assert.equal(page.element('confirmNewVersion').textContent, 'v3.8.25');
    assert.equal(page.element('updateError').textContent, 'previous build failed');
});

test('a lost install response is followed by status reads, never a second install POST', async () => {
    const page = ui([reply(502, { message: 'Restarting' }), reply(200, { id: 'job-a', status: 'running', stage: 'build', progress: 30 })],
        { offer: available.update });
    page.click('installUpdate');
    await page.click('confirmInstall');
    assert.equal(page.element('installPercent').textContent, '0%');
    await page.advance(2500);
    assert.deepEqual(page.calls.map(c => c.method), ['POST', 'GET']);
    assert.equal(page.element('installPercent').textContent, '30%');
    assert.equal(page.element('checkUpdate').disabled, true);
});

test('confirmation remains open and displays authoritative progress through success', async () => {
    const page = ui([reply(202, {id:'modal-job', status:'running', stage:'build', progress:34, version:'3.8.25'}),
        reply(200, {id:'modal-job', status:'success', stage:'complete', progress:100, version:'3.8.25'})], {offer:available.update});
    page.click('installUpdate');
    assert.equal(page.element('officeUpdateConfirm').open, true);
    assert.equal(page.calls.length, 0, 'opening confirmation must not start work');
    await page.click('confirmInstall');
    assert.equal(page.element('officeUpdateConfirm').open, true);
    assert.equal(page.element('installPercent').textContent, '34%');
    assert.equal(page.element('confirmInstall').hidden, true);
    assert.equal(page.element('cancelUpdate').textContent, 'بستن پنجره');
    await page.advance(2500);
    assert.equal(page.element('installPercent').textContent, '100%');
    assert.match(page.element('officeUpdateConfirmTitle').textContent, /با موفقیت/);
});

test('reloading an active operation restores its progress window without a second POST', () => {
    const page = ui([], {job:{id:'running-job',status:'running',stage:'services',progress:85,version:'3.8.25'}});
    assert.equal(page.element('officeUpdateConfirm').open, true);
    assert.equal(page.element('installPercent').textContent, '85%');
    assert.equal(page.calls.length, 0);
    assert.equal(page.element('showUpdateProgress').hidden, false);
});

test('a failed new start never inherits the success title of an older job', async () => {
    const page = ui([reply(200, available), reply(429, {}, '10')], {job:{id:'old',status:'success',version:'3.8.24',progress:100}});
    await page.click('checkUpdate');
    page.click('installUpdate');
    await page.click('confirmInstall');
    assert.doesNotMatch(page.element('officeUpdateConfirmTitle').textContent, /با موفقیت/);
    assert.match(page.element('officeUpdateConfirmTitle').textContent, /نیاز به ادامه/);
    assert.equal(page.element('installPercent').textContent, '0%');
});
