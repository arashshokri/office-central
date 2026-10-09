const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const exported = {exports: {}};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../public/assets/release-upload.js'), 'utf8'), {module: exported, URL});
const {attach} = exported.exports;

function fixture(size = 1024) {
    const nodes = Object.fromEntries(['panel', 'progress', 'percent', 'status', 'error'].map(key => [key, {
        hidden: true, value: 0, textContent: '', removeAttribute() { this.value = undefined; }
    }]));
    const controls = [{disabled: false}, {disabled: true}];
    const texts = Object.fromEntries(['uploading','processing','saved','failed','tooLarge','sessionExpired','rateLimit','proxyError','networkError'].map(key => [key,key]));
    let submit;
    const form = {action: 'https://central.test/releases', dataset: {maxUploadBytes: '1073741824', uploadText: JSON.stringify(texts)},
        addEventListener(name, callback) { submit = callback; }, setAttribute() {},
        querySelector(selector) { return selector === '[data-package-file]' ? {files: [{size}]} : nodes[selector.replace('[data-upload-', '').replace(']', '')]; },
        querySelectorAll() { return controls; }};
    const requests = [];
    const redirects = [];
    const env = {addEventListener() {}, FormData: class {}, location: {origin: 'https://central.test', href: 'https://central.test/releases/create', assign(url) { redirects.push(url); }},
        XMLHttpRequest: class {
            constructor() { this.upload = {}; this.headers = {}; requests.push(this); }
            open(method, url) { this.method = method; this.url = url; }
            setRequestHeader(key, value) { this.headers[key] = value; }
            send(body) { this.body = body; }
        }};
    attach(form, env);
    return {nodes, controls, requests, redirects, submit() { let prevented = false; submit({preventDefault() { prevented = true; }}); return prevented; }};
}

test('real upload events drive percent; transfer completion does not claim server success', () => {
    const f = fixture(); f.submit();
    const xhr = f.requests[0];
    assert.equal(f.nodes.percent.textContent, '0%');
    xhr.upload.onprogress({lengthComputable: true, loaded: 23, total: 100});
    assert.equal(f.nodes.progress.value, 23);
    assert.equal(f.nodes.percent.textContent, '23%');
    xhr.upload.onload();
    assert.equal(f.nodes.progress.value, 100);
    assert.equal(f.nodes.status.textContent, 'processing');
    assert.deepEqual(f.redirects, []);
    assert.equal(f.controls[0].disabled, true);
    f.submit(); assert.equal(f.requests.length, 1);
    xhr.status = 200; xhr.responseText = JSON.stringify({redirect: '/releases'}); xhr.onload();
    assert.deepEqual(f.redirects, ['https://central.test/releases']);
    assert.equal(f.controls[0].disabled, false);
    assert.equal(f.controls[1].disabled, true);
});

test('validation failure preserves form and permits a corrected retry', () => {
    const f = fixture(); f.submit();
    const xhr = f.requests[0];
    xhr.status = 422; xhr.responseText = JSON.stringify({errors: {package: ['Invalid Office ZIP'], version: ['VERSION mismatch']}}); xhr.onload();
    assert.equal(f.nodes.error.textContent, 'Invalid Office ZIP\nVERSION mismatch');
    assert.equal(f.nodes.error.hidden, false);
    assert.equal(f.controls[0].disabled, false);
    f.submit(); assert.equal(f.requests.length, 2);
});

test('proxy, session, HTML, and network failures show actionable messages', () => {
    for (const [status, expected] of [[413,'tooLarge'],[419,'sessionExpired'],[429,'rateLimit'],[502,'proxyError'],[504,'proxyError'],[200,'failed']]) {
        const f = fixture(); f.submit(); const xhr = f.requests[0];
        xhr.status = status; xhr.responseText = '<html>server error</html>'; xhr.onload();
        assert.equal(f.nodes.error.textContent, expected);
        assert.deepEqual(f.redirects, []);
        assert.equal(f.controls[0].disabled, false);
    }
    const f = fixture(); f.submit(); f.requests[0].onerror();
    assert.equal(f.nodes.error.textContent, 'networkError');
});

test('oversized file is rejected before transmitting its contents', () => {
    const f = fixture(1073741825); assert.equal(f.submit(), true);
    assert.equal(f.nodes.error.textContent, 'tooLarge');
    assert.equal(f.requests.length, 0);
});

test('unknown transfer length remains indeterminate and unsafe redirects are rejected', () => {
    const f = fixture(); f.submit(); const xhr = f.requests[0];
    xhr.upload.onprogress({lengthComputable: false});
    assert.equal(f.nodes.progress.value, undefined);
    assert.equal(f.nodes.percent.textContent, '…');
    xhr.status = 200; xhr.responseText = JSON.stringify({redirect: 'https://other.test/'}); xhr.onload();
    assert.deepEqual(f.redirects, []);
    assert.equal(f.nodes.error.textContent, 'failed');
});
