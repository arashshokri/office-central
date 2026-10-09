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
        bootstrap: { Modal: class { show() {} hide() {} } }, location: { reload() {} },
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
    await page.advance(60000);
    assert.equal(page.calls.length, 2);
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
    const page = ui([reply(429, {}, '10')], { offer: available.update });
    page.click('installUpdate');
    await page.click('confirmInstall');
    assert.match(page.element('updateError').textContent, /10/);
    await page.advance(60000);
    assert.deepEqual(page.calls, [{ url: '/install', method: 'POST' }]);
});
