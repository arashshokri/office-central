const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const exported = {exports: {}};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../../public/assets/release-upload.js'), 'utf8'), {module: exported, URL});
const {attach} = exported.exports;

function fixture(size = 1024, chunked = false) {
    const nodes = Object.fromEntries(['panel', 'progress', 'percent', 'status', 'error'].map(key => [key, {
        hidden: true, value: 0, textContent: '', removeAttribute() { this.value = undefined; }
    }]));
    const controls = [{disabled: false}, {disabled: true}];
    const texts = Object.fromEntries(['uploading','processing','saved','failed','tooLarge','sessionExpired','rateLimit','proxyError','networkError','retrying'].map(key => [key,key]));
    const file = {size, name: 'office.zip'};
    if (chunked) file.slice = (start, end) => ({start, end});
    let submit;
    const form = {action: 'https://central.test/releases', dataset: {maxUploadBytes: '1073741824', uploadText: JSON.stringify(texts)},
        addEventListener(name, callback) { submit = callback; }, setAttribute() {},
        querySelector(selector) { return selector === '[data-package-file]' ? {files: [file]} : nodes[selector.replace('[data-upload-', '').replace(']', '')]; },
        querySelectorAll() { return controls; }};
    if (chunked) { form.dataset.uploadUrl = '/release-uploads'; form.dataset.releaseId = '5'; }
    const requests = [];
    const redirects = [];
    const env = {addEventListener() {}, setTimeout(fn) { fn(); }, FormData: class {
        constructor(form) { this.fields = new Map(form ? [['_token', 'csrf'], ['package', file], ['_method', 'PUT']] : []); }
        get(key) { return this.fields.get(key); } set(key, value) { this.fields.set(key, value); }
        append(key, value) { this.set(key, value); } delete(key) { this.fields.delete(key); }
    }, location: {origin: 'https://central.test', href: 'https://central.test/releases/create', assign(url) { redirects.push(url); }},
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

const tick = () => new Promise(resolve => setImmediate(resolve));
const respond = (xhr, body, status = 200) => { xhr.status = status; xhr.responseText = JSON.stringify(body); xhr.onload(); };
const uploadId = '11111111-1111-4111-8111-111111111111';

test('chunk transport keeps requests small and shows actual total progress, then submits metadata only', async () => {
    const f = fixture(600000, true); f.submit();
    const init = f.requests[0];
    assert.equal(init.url, 'https://central.test/release-uploads');
    assert.equal(init.body.get('release_id'), '5');
    assert.equal(init.body.get('_token'), 'csrf');
    respond(init, {id: uploadId, offset: 0, chunk_bytes: 262144}); await tick();
    const first = f.requests[1];
    assert.equal(first.body.get('offset'), 0);
    assert.equal(first.body.get('chunk').end, 262144);
    first.upload.onprogress({lengthComputable: true, loaded: 1, total: 2});
    assert.equal(f.nodes.progress.value, 21);
    respond(first, {offset: 262144}); await tick();
    respond(f.requests[2], {offset: 524288}); await tick();
    assert.equal(f.requests[3].body.get('chunk').end, 600000);
    respond(f.requests[3], {offset: 600000}); await tick();
    const finish = f.requests[4];
    assert.equal(f.nodes.percent.textContent, '100%');
    assert.equal(f.nodes.status.textContent, 'processing');
    assert.equal(finish.body.get('package'), undefined);
    assert.equal(finish.body.get('package_upload'), uploadId);
    assert.equal(finish.body.get('_method'), 'PUT');
    assert.deepEqual(f.redirects, []);
    respond(finish, {redirect: '/releases'}); await tick();
    assert.deepEqual(f.redirects, ['https://central.test/releases']);
});

test('lost chunk acknowledgement is retried at the same offset and final save can be retried safely', async () => {
    const f = fixture(1000, true); f.submit();
    respond(f.requests[0], {id: uploadId, offset: 0, chunk_bytes: 262144}); await tick();
    f.requests[1].onerror(); await tick();
    assert.equal(f.requests[2].body.get('offset'), 0);
    assert.equal(f.requests[2].body.get('chunk').end, 1000);
    respond(f.requests[2], {offset: 1000}); await tick();
    f.requests[3].ontimeout(); await tick();
    assert.equal(f.requests[4].body.get('package_upload'), uploadId);
    respond(f.requests[4], {redirect: '/releases'}); await tick();
    assert.deepEqual(f.redirects, ['https://central.test/releases']);
});

test('validation preserves uploaded file for correction and save without another transfer', async () => {
    const f = fixture(1000, true); f.submit();
    respond(f.requests[0], {id: uploadId, offset: 0, chunk_bytes: 262144}); await tick();
    respond(f.requests[1], {offset: 1000}); await tick();
    respond(f.requests[2], {errors: {version: ['VERSION mismatch']}}, 422); await tick();
    assert.equal(f.nodes.error.textContent, 'VERSION mismatch');
    assert.equal(f.controls[0].disabled, false);
    f.submit(); await tick();
    assert.equal(f.requests[3].url, 'https://central.test/releases');
    assert.equal(f.requests[3].body.get('package_upload'), uploadId);
    respond(f.requests[3], {redirect: '/releases'}); await tick();
});

test('bounded network retries stop and another Save resumes the same upload session', async () => {
    const f = fixture(1000, true); f.submit();
    respond(f.requests[0], {id: uploadId, offset: 0, chunk_bytes: 262144}); await tick();
    for (const index of [1, 2, 3]) { f.requests[index].onerror(); await tick(); }
    assert.equal(f.nodes.error.textContent, 'networkError');
    assert.equal(f.controls[0].disabled, false);
    f.submit(); await tick();
    assert.equal(f.requests[4].url, 'https://central.test/release-uploads/' + uploadId + '/chunks');
    assert.equal(f.requests[4].body.get('offset'), 0);
    respond(f.requests[4], {offset: 1000}); await tick();
    respond(f.requests[5], {redirect: '/releases'}); await tick();
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
