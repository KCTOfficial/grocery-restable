import { test } from 'node:test';
import assert from 'node:assert/strict';
import GroceryRestable, { filterPairs, orderValue, GroceryRestableError } from '../src/grocery-restable.js';

/** A fetch stub that records requests and answers with the queued responses. */
function stub(...responses) {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url: new URL(url), init });
        const r = responses.shift() || {};
        const headers = new Headers({ 'content-type': 'application/json', ...(r.headers || {}) });
        return new Response(r.status === 204 ? null : JSON.stringify(r.body ?? null), { status: r.status || 200, headers });
    };
    return { calls, fetch };
}

test('filters become PostgREST query pairs', () => {
    assert.deepEqual(filterPairs({ city: 'Paris', salary: { gte: 5000, lt: 9000 } }), [['city', 'eq.Paris'], ['salary', 'gte.5000'], ['salary', 'lt.9000']]);
    assert.deepEqual(filterPairs({ officeCode: ['1', 'a,b'], reportsTo: null, active: true }), [['officeCode', 'in.(1,"a,b")'], ['reportsTo', 'is.null'], ['active', 'is.true']]);
    assert.deepEqual(filterPairs({ status: { not: { in: ['a', 'b'] } } }), [['status', 'not.in.(a,b)']]);
    assert.deepEqual(filterPairs({ or: [{ age: { lt: 18 } }, { name: 'Doe, John' }] }), [['or', '(age.lt.18,name.eq."Doe, John")']]);
    assert.deepEqual(filterPairs('city=eq.Paris'), [['city', 'eq.Paris']]);
    assert.throws(() => filterPairs({ a: { nope: 1 } }), GroceryRestableError);
});

test('sort accepts PocketBase and PostgREST styles', () => {
    assert.equal(orderValue('-lastName,firstName'), 'lastName.desc,firstName.asc');
    assert.equal(orderValue('lastName.desc.nullslast'), 'lastName.desc.nullslast');
});

test('getList sends paging, filter, sort and select and reads the total', async () => {
    const { calls, fetch } = stub({ body: [{ id: 1 }, { id: 2 }], headers: { 'content-range': '20-21/42' } });
    const gr = new GroceryRestable('http://x/api/', { apiKey: 'gr_anon', fetch });
    const result = await gr.collection('employees').getList(2, 20, { filter: { officeCode: '1' }, sort: '-lastName', select: '*,offices(city)' });

    assert.deepEqual(result, { page: 2, perPage: 20, totalItems: 42, totalPages: 3, items: [{ id: 1 }, { id: 2 }] });
    const { url, init } = calls[0];
    assert.equal(url.pathname, '/api/employees');
    assert.equal(url.searchParams.get('officeCode'), 'eq.1');
    assert.equal(url.searchParams.get('order'), 'lastName.desc');
    assert.equal(url.searchParams.get('select'), '*,offices(city)');
    assert.equal(url.searchParams.get('limit'), '20');
    assert.equal(url.searchParams.get('offset'), '20');
    assert.equal(url.searchParams.get('count'), 'exact');
    assert.equal(init.headers.apikey, 'gr_anon');
});

test('getList understands the {data, meta} envelope', async () => {
    const { fetch } = stub({ body: { data: [{ id: 1 }], meta: { total: 1 } } });
    const result = await new GroceryRestable('http://x/api', { fetch }).collection('t').getList();
    assert.equal(result.totalItems, 1);
    assert.equal(result.items.length, 1);
});

test('getFullList pages until everything is loaded', async () => {
    const { calls, fetch } = stub(
        { body: [{ id: 1 }, { id: 2 }], headers: { 'content-range': '0-1/3' } },
        { body: [{ id: 3 }], headers: { 'content-range': '2-2/3' } },
    );
    const items = await new GroceryRestable('http://x/api', { fetch }).collection('t').getFullList({ batch: 2 });
    assert.deepEqual(items.map((i) => i.id), [1, 2, 3]);
    assert.equal(calls.length, 2);
});

test('create, update and delete', async () => {
    const { calls, fetch } = stub({ status: 201, body: { id: 'a' } }, { body: { id: 'a', title: 'B' } }, { status: 204 });
    const notes = new GroceryRestable('http://x/api', { fetch, token: 'jwt.token.sig' }).collection('notes');

    assert.deepEqual(await notes.create({ title: 'A' }), { id: 'a' });
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.body, '{"title":"A"}');
    assert.equal(calls[0].init.headers.Prefer, 'return=representation');
    assert.equal(calls[0].init.headers.Authorization, 'Bearer jwt.token.sig');

    await notes.update('a', { title: 'B' });
    assert.equal(calls[1].init.method, 'PATCH');
    assert.equal(calls[1].url.pathname, '/api/notes/a');

    assert.equal(await notes.delete('a'), true);
    assert.equal(calls[2].init.method, 'DELETE');
});

test('objects with files are sent as multipart, updates with a method override', async () => {
    const { calls, fetch } = stub({ body: { id: 1 } });
    await new GroceryRestable('http://x/api', { fetch }).collection('employees').update(1, { photo: new Blob(['x']), lastName: 'Doe' });
    const { init } = calls[0];
    assert.equal(init.method, 'POST');
    assert.equal(init.headers['X-HTTP-Method-Override'], 'PATCH');
    assert.ok(init.body instanceof FormData);
    assert.equal(init.body.get('lastName'), 'Doe');
});

test('errors carry status, code and details', async () => {
    const { fetch } = stub({ status: 422, body: { error: { code: 'validation_failed', message: 'Invalid', details: { fields: { email: 'required' } } } } });
    await assert.rejects(
        new GroceryRestable('http://x/api', { fetch }).collection('employees').create({}),
        (e) => e instanceof GroceryRestableError && e.status === 422 && e.code === 'validation_failed' && e.details.fields.email === 'required',
    );
});

test('storage, rpc and file urls', async () => {
    const { calls, fetch } = stub({ status: 201, body: { path: 'users/1/a.png' } }, { body: { total: 3 } });
    const gr = new GroceryRestable('http://x/api', { fetch });

    await gr.storage('avatars').upload('users/1', new Blob(['png']), { upsert: true });
    assert.equal(calls[0].url.pathname, '/api/storage/avatars');
    assert.equal(calls[0].init.body.get('path'), 'users/1');
    assert.equal(calls[0].init.body.get('upsert'), '1');

    assert.deepEqual(await gr.rpc('stats', { year: 2026 }), { total: 3 });
    assert.equal(calls[1].url.pathname, '/api/rpc/stats');

    assert.equal(gr.storage('avatars').getUrl('users/1/a b.png'), 'http://x/api/storage/avatars/users/1/a%20b.png');
    assert.equal(gr.collection('employees').getFileUrl(7, 'photo'), 'http://x/api/employees/7/file/photo');
});

test('storage listing follows ListObjectsV2 and listAll pages', async () => {
    const { calls, fetch } = stub(
        { body: { files: [{ path: 'a/1.txt' }], truncated: true, next_cursor: 'a/1.txt' } },
        { body: { files: [{ path: 'a/2.txt' }], truncated: false, next_cursor: null } },
        { body: { path: 'a/1.txt', etag: 'abc' } },
        { status: 201, body: { path: 'a/x.png' } },
    );
    const docs = new GroceryRestable('http://x/api', { fetch }).storage('docs');
    const files = await docs.listAll('a/');
    assert.deepEqual(files.map((f) => f.path), ['a/1.txt', 'a/2.txt']);
    assert.equal(calls[0].url.searchParams.get('delimiter'), '');
    assert.equal(calls[1].url.searchParams.get('cursor'), 'a/1.txt');

    assert.equal((await docs.info('a/1.txt')).etag, 'abc');
    assert.equal(calls[2].url.searchParams.get('info'), '1');

    await docs.upload('a', new Blob(['x']), { cacheControl: 'max-age=60', metadata: { author: 'jane' } });
    assert.equal(calls[3].init.body.get('cache_control'), 'max-age=60');
    assert.equal(calls[3].init.body.get('metadata'), '{"author":"jane"}');
});

test('auth store reads the token payload and expiry', () => {
    const payload = Buffer.from(JSON.stringify({ sub: 'u1', exp: Math.floor(Date.now() / 1000) + 60 })).toString('base64url');
    const gr = new GroceryRestable('http://x/api');
    let seen = null;
    gr.authStore.onChange((token) => { seen = token; });
    gr.setToken(`h.${payload}.s`);
    assert.equal(gr.authStore.payload.sub, 'u1');
    assert.equal(gr.authStore.isValid, true);
    assert.equal(seen, `h.${payload}.s`);
    gr.clearToken();
    assert.equal(gr.authStore.isValid, false);
});

const jwt = (claims) => 'h.' + Buffer.from(JSON.stringify(claims)).toString('base64url') + '.s';

test('auth.login stores the tokens and sends no stale token', async () => {
    const tokens = { access_token: jwt({ sub: '1', exp: Math.floor(Date.now() / 1000) + 900 }), refresh_token: 'grr_1', user: { id: 1 } };
    const { calls, fetch } = stub({ body: tokens }, { body: [] });
    const gr = new GroceryRestable('http://x/api', { apiKey: 'gr_anon', token: 'stale', fetch });

    assert.deepEqual(await gr.auth.login('jane@example.com', 'secret'), tokens);
    assert.equal(calls[0].url.pathname, '/api/auth/login');
    assert.deepEqual(JSON.parse(calls[0].init.body), { identity: 'jane@example.com', password: 'secret' });
    assert.equal(calls[0].init.headers.Authorization, undefined);
    assert.equal(gr.authStore.refreshToken, 'grr_1');
    assert.deepEqual(gr.auth.user, { id: 1 });

    await gr.collection('notes').getList();
    assert.equal(calls[1].init.headers.Authorization, 'Bearer ' + tokens.access_token);
});

test('expired access tokens are refreshed once, before the request', async () => {
    const fresh = { access_token: jwt({ exp: Math.floor(Date.now() / 1000) + 900 }), refresh_token: 'grr_2', user: { id: 1 } };
    const { calls, fetch } = stub({ body: fresh }, { body: [] }, { body: [] });
    const gr = new GroceryRestable('http://x/api', { fetch });
    gr.authStore.save(jwt({ exp: 1 }), { refreshToken: 'grr_1' });

    await Promise.all([gr.collection('a').getList(), gr.collection('b').getList()]);
    assert.equal(calls.filter((c) => c.url.pathname === '/api/auth/refresh').length, 1, 'parallel requests share one refresh');
    assert.deepEqual(JSON.parse(calls[0].init.body), { refresh_token: 'grr_1' });
    assert.equal(gr.authStore.refreshToken, 'grr_2');
    assert.equal(calls[1].init.headers.Authorization, 'Bearer ' + fresh.access_token);
});

test('a 401 is retried after a refresh, a failed refresh signs out', async () => {
    const fresh = { access_token: jwt({ exp: Math.floor(Date.now() / 1000) + 900 }), refresh_token: 'grr_2', user: {} };
    const { calls, fetch } = stub({ status: 401, body: { error: { code: 'unauthorized' } } }, { body: fresh }, { body: [{ id: 1 }] });
    const gr = new GroceryRestable('http://x/api', { fetch });
    gr.authStore.save(jwt({ exp: Math.floor(Date.now() / 1000) + 900 }), { refreshToken: 'grr_1' });
    assert.deepEqual((await gr.collection('a').getList()).items, [{ id: 1 }]);
    assert.equal(calls.length, 3);

    const failing = stub({ status: 401, body: {} }, { status: 401, body: { error: { code: 'invalid_refresh_token' } } });
    const gr2 = new GroceryRestable('http://x/api', { fetch: failing.fetch });
    gr2.authStore.save(jwt({ exp: Math.floor(Date.now() / 1000) + 900 }), { refreshToken: 'grr_1' });
    await assert.rejects(gr2.collection('a').getList(), (e) => e.status === 401);
    assert.equal(gr2.authStore.token, '');
});

test('loginWithGoogle and logout', async () => {
    const { calls, fetch } = stub({ body: { access_token: 'grs_abc', user: { id: 2 } } }, { status: 204 });
    const gr = new GroceryRestable('http://x/api', { fetch });
    await gr.auth.loginWithGoogle({ idToken: 'google.id.token' });
    assert.deepEqual(JSON.parse(calls[0].init.body), { id_token: 'google.id.token' });
    assert.equal(gr.authStore.token, 'grs_abc');
    assert.ok(gr.authStore.isValid, 'session tokens have no exp');

    await gr.auth.logout({ all: true });
    assert.equal(calls[1].url.pathname, '/api/auth/logout');
    assert.equal(calls[1].init.headers.Authorization, 'Bearer grs_abc');
    assert.deepEqual(JSON.parse(calls[1].init.body), { all: true });
    assert.equal(gr.authStore.token, '');
});

test('LocalAuthStore persists tokens', async () => {
    const data = new Map();
    globalThis.localStorage = { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v), removeItem: (k) => data.delete(k) };
    try {
        const { LocalAuthStore } = await import('../src/grocery-restable.js');
        new LocalAuthStore().save('t1', { refreshToken: 'grr_1', user: { id: 1 } });
        const again = new LocalAuthStore();
        assert.deepEqual([again.token, again.refreshToken, again.user], ['t1', 'grr_1', { id: 1 }]);
        again.clear();
        assert.equal(data.size, 0);
    } finally {
        delete globalThis.localStorage;
    }
});
