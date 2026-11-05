/*!
 * Grocery Restable JavaScript SDK (ES module, no dependencies).
 * Works in browsers and in Node.js 18+ (global fetch, FormData, Blob).
 *
 *   import GroceryRestable from '@kctofficial/grocery-restable';      // npm
 *   import GroceryRestable from 'https://your-host/api/sdk.js';       // served by the API itself
 *
 *   const gr = new GroceryRestable('https://your-host/api', { apiKey: 'gr_...' });
 *   const { items, totalItems } = await gr.collection('employees').getList(1, 20, {
 *       filter: { officeCode: '1', salary: { gte: 5000 } },
 *       sort: '-lastName,firstName',
 *       select: '*,offices(city)',
 *   });
 */

// =====================================================================
// Errors
// =====================================================================

export class GroceryRestableError extends Error {
    /**
     * @param {string} message
     * @param {{status?: number, code?: string, details?: object, url?: string, isAbort?: boolean, cause?: unknown}} [info]
     */
    constructor(message, info = {}) {
        super(message);
        this.name = 'GroceryRestableError';
        this.status = info.status ?? 0;
        this.code = info.code ?? (info.isAbort ? 'aborted' : 'network_error');
        this.details = info.details ?? {};
        this.url = info.url ?? '';
        this.isAbort = Boolean(info.isAbort);
        if (info.cause !== undefined) this.cause = info.cause;
    }
}

// =====================================================================
// Query helpers (PostgREST syntax of the API)
// =====================================================================

const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'in', 'is', 'not'];

/** Quote a value inside in.(...) or or=(...) when it contains reserved characters. */
function listValue(value) {
    const text = value === null ? 'null' : String(value);
    return /[,()"\s]/.test(text) ? `"${text.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"` : text;
}

/** One operation: ('eq', 5) → "eq.5", ('in', [1, 2]) → "in.(1,2)", ('not', {in: [1]}) → "not.in.(1)". */
function operation(operator, value) {
    if (!OPERATORS.includes(operator)) throw new GroceryRestableError(`Unknown filter operator "${operator}".`, { code: 'invalid_filter' });
    if (operator === 'not') {
        const [[inner, innerValue]] = Object.entries(value);
        return 'not.' + operation(inner, innerValue);
    }
    if (operator === 'in') return `in.(${[].concat(value).map(listValue).join(',')})`;
    if (operator === 'is') return `is.${value === null ? 'null' : value}`;
    return `${operator}.${value}`;
}

/** Filter value → list of operations ("eq.Paris", "gte.10", ...). */
function operations(value) {
    if (value === null || value === undefined) return ['is.null'];
    if (typeof value === 'boolean') return [`is.${value}`];
    if (Array.isArray(value)) return [operation('in', value)];
    if (value instanceof Date) return [`eq.${value.toISOString()}`];
    if (typeof value === 'object') return Object.entries(value).map(([op, v]) => operation(op, v));
    return [`eq.${value}`];
}

/** Items of an or / and group: {a: 1, b: {gt: 2}} → ["a.eq.1", "b.gt.2"]. */
function groupItems(conditions) {
    return [].concat(conditions).flatMap((condition) => Object.entries(condition).flatMap(([key, value]) => {
        if (key === 'or' || key === 'and') return [`${key}(${groupItems(value).join(',')})`];
        return operations(value).map((op) => `${key}.${op.replace(/^(eq|neq|gt|gte|lt|lte|like|ilike)\.(.*)$/s, (m, o, v) => `${o}.${listValue(v)}`)}`);
    }));
}

/**
 * Turn a filter into query pairs.
 *   { city: 'Paris' }                      city=eq.Paris
 *   { salary: { gte: 5000, lt: 9000 } }    salary=gte.5000&salary=lt.9000
 *   { officeCode: ['1', '4'] }             officeCode=in.(1,4)
 *   { reportsTo: null, active: true }      reportsTo=is.null&active=is.true
 *   { status: { not: { in: ['a', 'b'] } } } status=not.in.(a,b)
 *   { or: [{ age: { lt: 18 } }, { age: { gt: 65 } }] }   or=(age.lt.18,age.gt.65)
 *   'city=eq.Paris&country=eq.France'      used as is
 *
 * @param {object|string|undefined} filter
 * @returns {Array<[string, string]>}
 */
export function filterPairs(filter) {
    if (!filter) return [];
    if (typeof filter === 'string') return [...new URLSearchParams(filter)];
    return Object.entries(filter).flatMap(([key, value]) => {
        if (key === 'or' || key === 'and') return [[key, `(${groupItems(value).join(',')})`]];
        return operations(value).map((op) => [key, op]);
    });
}

/** PocketBase style sort "-created,title" → "created.desc,title.asc" (PostgREST "a.desc" is kept). */
export function orderValue(sort) {
    if (!sort) return '';
    return String(sort).split(',').map((part) => part.trim()).filter(Boolean).map((part) => {
        if (/\.(asc|desc)/.test(part)) return part;
        return part.startsWith('-') ? `${part.slice(1)}.desc` : `${part.replace(/^\+/, '')}.asc`;
    }).join(',');
}

/** Common list options → query pairs. */
function listPairs(options = {}) {
    const pairs = filterPairs(options.filter);
    if (options.select) pairs.push(['select', options.select]);
    if (options.sort) pairs.push(['order', orderValue(options.sort)]);
    if (options.search) pairs.push(['q', options.search]);
    for (const [key, value] of Object.entries(options.query || {})) pairs.push([key, String(value)]);
    return pairs;
}

const encodePath = (path) => String(path).split('/').map(encodeURIComponent).join('/');
const isFile = (v) => (typeof Blob !== 'undefined' && v instanceof Blob);
const isFormData = (v) => (typeof FormData !== 'undefined' && v instanceof FormData);

/** Objects that contain files are sent as multipart/form-data automatically. */
function toBody(data) {
    if (data === undefined || isFormData(data) || !data || typeof data !== 'object' || Array.isArray(data)) return data;
    if (!Object.values(data).some((v) => isFile(v) || (Array.isArray(v) && v.some(isFile)))) return data;
    const form = new FormData();
    for (const [key, value] of Object.entries(data)) {
        if (value === undefined) continue;
        if (Array.isArray(value)) value.forEach((item) => form.append(key, item));
        else form.append(key, isFile(value) || typeof value === 'string' ? value : JSON.stringify(value));
    }
    return form;
}

// =====================================================================
// Auth store: the user's token (from gr.auth, your own login or an identity provider)
// =====================================================================

export class AuthStore {
    constructor() {
        this.token = '';
        /** Refresh token of the "jwt" strategy (gr.auth renews the access token with it). */
        this.refreshToken = '';
        /** The signed in user returned by /auth. */
        this.user = null;
        this.listeners = [];
    }

    /** True when a token is set and not expired (based on its "exp" claim; session tokens have none). */
    get isValid() {
        if (!this.token) return false;
        const payload = this.payload;
        return !payload || !payload.exp || payload.exp * 1000 > Date.now();
    }

    /** Decoded JWT payload (not verified, the server verifies it). Null for session tokens. */
    get payload() {
        try {
            const part = this.token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
            return JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(part), (c) => c.charCodeAt(0))));
        } catch (e) {
            return null;
        }
    }

    /**
     * @param {string} token
     * @param {{refreshToken?: string, user?: object|null}} [extra]
     */
    save(token, extra = {}) {
        this.token = token || '';
        this.refreshToken = this.token ? extra.refreshToken || '' : '';
        this.user = this.token ? extra.user ?? null : null;
        this.listeners.forEach((listener) => listener(this.token, this.user));
    }

    clear() {
        this.save('');
    }

    /** Subscribe to changes: listener(token, user). Returns an unsubscribe function. */
    onChange(listener) {
        this.listeners.push(listener);
        return () => { this.listeners = this.listeners.filter((l) => l !== listener); };
    }
}

/**
 * Auth store persisted in localStorage (browsers), shared by the tabs of the same origin.
 * Tokens in localStorage are readable by any script of the page: keep a strict Content-Security-Policy.
 */
export class LocalAuthStore extends AuthStore {
    constructor(key = 'gr_auth') {
        super();
        this.storageKey = key;
        this.load();
        if (typeof window !== 'undefined' && window.addEventListener) {
            window.addEventListener('storage', (e) => { if (e.key === this.storageKey) this.load(true); });
        }
    }

    load(notify = false) {
        let data = {};
        try { data = JSON.parse(globalThis.localStorage.getItem(this.storageKey) || '{}') || {}; } catch (e) { data = {}; }
        this.token = data.token || '';
        this.refreshToken = data.refreshToken || '';
        this.user = data.user ?? null;
        if (notify) this.listeners.forEach((listener) => listener(this.token, this.user));
    }

    save(token, extra = {}) {
        super.save(token, extra);
        try {
            if (this.token) globalThis.localStorage.setItem(this.storageKey, JSON.stringify({ token: this.token, refreshToken: this.refreshToken, user: this.user }));
            else globalThis.localStorage.removeItem(this.storageKey);
        } catch (e) { /* storage unavailable (private mode): keep the tokens in memory */ }
    }
}

// =====================================================================
// User login (/auth)
// =====================================================================

export class AuthService {
    /** @param {GroceryRestable} client */
    constructor(client) {
        this.client = client;
        this.refreshing = null;
    }

    /** The signed in user (from the last login / refresh), or null. */
    get user() {
        return this.client.authStore.user;
    }

    get isSignedIn() {
        return Boolean(this.client.authStore.token);
    }

    /** Enabled sign-in methods: { password, google: { client_id } | false, strategy }. */
    methods(options = {}) {
        return this.client.send('/auth', { ...options, noToken: true });
    }

    /** Sign in with an identity (email, username...) and a password. Stores the tokens. */
    async login(identity, password, options = {}) {
        return this.store(await this.client.send('/auth/login', { ...options, method: 'POST', body: { identity, password }, noToken: true }));
    }

    /**
     * Sign in with Google: { idToken } (the "credential" of Sign in with Google / One Tap / mobile SDKs)
     * or { code, redirectUri?, codeVerifier? } (authorization code flow).
     */
    async loginWithGoogle({ idToken, credential, code, redirectUri, codeVerifier } = {}, options = {}) {
        const body = (idToken || credential)
            ? { id_token: idToken || credential }
            : { code, redirect_uri: redirectUri, code_verifier: codeVerifier };
        return this.store(await this.client.send('/auth/google', { ...options, method: 'POST', body, noToken: true }));
    }

    /** New access token (and rotated refresh token). Parallel calls share one request. */
    refresh(options = {}) {
        const refreshToken = this.client.authStore.refreshToken;
        if (!refreshToken) return Promise.reject(new GroceryRestableError('Not signed in.', { status: 401, code: 'unauthorized' }));
        if (!this.refreshing) {
            this.refreshing = this.client.send('/auth/refresh', { ...options, method: 'POST', body: { refresh_token: refreshToken }, noToken: true })
                .then((data) => this.store(data))
                .catch((error) => {
                    if (error.status === 401 || error.status === 403) this.client.authStore.clear();
                    throw error;
                })
                .finally(() => { this.refreshing = null; });
        }
        return this.refreshing;
    }

    /** Sign out (all: on every device). The local tokens are cleared even when the request fails. */
    async logout({ all = false, ...options } = {}) {
        const { token, refreshToken } = this.client.authStore;
        try {
            if (token || refreshToken) {
                // The refresh token identifies the session even when the access token has expired.
                const body = refreshToken ? { refresh_token: refreshToken, all } : { all };
                await this.client.send('/auth/logout', { ...options, method: 'POST', body, noRefresh: true, noToken: Boolean(refreshToken) });
            }
        } catch (error) {
            if (error.status !== 401) throw error;
        } finally {
            this.client.authStore.clear();
        }
        return true;
    }

    /** The signed in user, read again from the server. */
    async me(options = {}) {
        const user = await this.client.send('/auth/me', options);
        const store = this.client.authStore;
        store.save(store.token, { refreshToken: store.refreshToken, user });
        return user;
    }

    store(data) {
        this.client.authStore.save(data.access_token, { refreshToken: data.refresh_token, user: data.user });
        return data;
    }
}

// =====================================================================
// Collections (resources)
// =====================================================================

export class CollectionService {
    /**
     * @param {GroceryRestable} client
     * @param {string} name
     */
    constructor(client, name) {
        this.client = client;
        this.name = name;
        this.path = '/' + encodeURIComponent(name);
    }

    /**
     * One page of records with the total count.
     * @returns {Promise<{page: number, perPage: number, totalItems: number, totalPages: number, items: object[]}>}
     */
    async getList(page = 1, perPage = 30, options = {}) {
        const pairs = listPairs(options);
        pairs.push(['limit', String(perPage)], ['offset', String((page - 1) * perPage)], ['count', 'exact']);
        const { data, response } = await this.client.send(this.path, { ...options, query: pairs, raw: true });
        const items = Array.isArray(data) ? data : (data && data.data) || [];
        const total = data && data.meta && data.meta.total != null ? data.meta.total : totalFromRange(response.headers.get('content-range'), items.length);
        return { page, perPage, totalItems: total, totalPages: Math.max(1, Math.ceil(total / perPage)), items };
    }

    /** Every record matching the options, fetched in batches (default 500). */
    async getFullList(options = {}) {
        const batch = options.batch || 500;
        const items = [];
        for (let page = 1; ; page++) {
            const result = await this.getList(page, batch, options);
            items.push(...result.items);
            if (result.items.length < batch || items.length >= result.totalItems) return items;
        }
    }

    /** The first record matching a filter; rejects with a 404 error when there is none. */
    async getFirstListItem(filter, options = {}) {
        const { items } = await this.getList(1, 1, { ...options, filter });
        if (!items.length) throw new GroceryRestableError('The requested record was not found.', { status: 404, code: 'not_found' });
        return items[0];
    }

    /** One record by primary key. Options: select, query, headers, signal. */
    getOne(id, options = {}) {
        return this.client.send(`${this.path}/${encodeURIComponent(id)}`, { ...options, query: listPairs(options) });
    }

    /** Create a record (object, FormData, or an object containing File / Blob values). */
    create(data, options = {}) {
        return this.client.send(this.path, { ...options, method: 'POST', body: toBody(data), query: listPairs(options), prefer: 'return=representation' });
    }

    /** Create many records in one request (JSON array). */
    createMany(rows, options = {}) {
        return this.client.send(this.path, { ...options, method: 'POST', body: rows, query: listPairs(options), prefer: 'return=representation' });
    }

    /** Insert or update by primary key. */
    upsert(data, options = {}) {
        return this.client.send(this.path, { ...options, method: 'POST', body: data, query: listPairs(options), prefer: 'return=representation, resolution=merge-duplicates' });
    }

    /** Update some fields of a record. With files the request is sent as multipart. */
    update(id, data, options = {}) {
        const body = toBody(data);
        // Browsers and PHP only parse multipart bodies on POST: the API honors the override for forms.
        const method = isFormData(body) ? 'POST' : 'PATCH';
        const headers = isFormData(body) ? { 'X-HTTP-Method-Override': 'PATCH', ...(options.headers || {}) } : options.headers;
        return this.client.send(`${this.path}/${encodeURIComponent(id)}`, { ...options, method, headers, body, query: listPairs(options), prefer: 'return=representation' });
    }

    /** Update every record matching a filter (the filter is required). */
    updateMany(filter, data, options = {}) {
        return this.client.send(this.path, { ...options, method: 'PATCH', body: data, query: [...filterPairs(filter), ...listPairs({ ...options, filter: undefined })], prefer: 'return=representation' });
    }

    /** Delete a record. Resolves true. */
    async delete(id, options = {}) {
        await this.client.send(`${this.path}/${encodeURIComponent(id)}`, { ...options, method: 'DELETE' });
        return true;
    }

    /** Delete every record matching a filter (the filter is required). Resolves the deleted ids. */
    async deleteMany(filter, options = {}) {
        const result = await this.client.send(this.path, { ...options, method: 'DELETE', query: filterPairs(filter) });
        return (result && result.deleted) || [];
    }

    /** Copy a record (the clone operation must be enabled on the resource). */
    clone(id, overrides = {}, options = {}) {
        return this.client.send(`${this.path}/${encodeURIComponent(id)}/clone`, { ...options, method: 'POST', body: overrides });
    }

    /** Upload a file into an upload field. */
    uploadFile(id, field, file, options = {}) {
        const form = new FormData();
        form.append('file', file);
        return this.client.send(`${this.path}/${encodeURIComponent(id)}/upload/${encodeURIComponent(field)}`, { ...options, method: 'POST', body: form });
    }

    /** Remove the file of an upload field. */
    async deleteFile(id, field, options = {}) {
        await this.client.send(`${this.path}/${encodeURIComponent(id)}/upload/${encodeURIComponent(field)}`, { ...options, method: 'DELETE' });
        return true;
    }

    /** URL of the file of an upload field (for links; images need a public resource or ?apikey=). */
    getFileUrl(id, field, query = {}) {
        return this.client.buildUrl(`${this.path}/${encodeURIComponent(id)}/file/${encodeURIComponent(field)}`, query);
    }

    /** Download the file of an upload field as a Blob (sends the credentials). */
    getFile(id, field, options = {}) {
        return this.client.send(`${this.path}/${encodeURIComponent(id)}/file/${encodeURIComponent(field)}`, { ...options, as: 'blob' });
    }

    /** Value / label pairs of a relation field (for dropdowns). */
    options(field, { search, limit } = {}, options = {}) {
        const query = [];
        if (search) query.push(['q', search]);
        if (limit) query.push(['limit', String(limit)]);
        return this.client.send(`${this.path}/_options/${encodeURIComponent(field)}`, { ...options, query });
    }

    /** Run a custom action defined in code: $api->resource(...)->addAction(name, ...). */
    action(id, name, body = {}, options = {}) {
        return this.client.send(`${this.path}/${encodeURIComponent(id)}/actions/${encodeURIComponent(name)}`, { ...options, method: 'POST', body });
    }

    /** Field metadata (labels, types, required, relations) to build forms. */
    schema(options = {}) {
        return this.client.send(`${this.path}/_schema`, options);
    }
}

function totalFromRange(range, fallback) {
    const match = /\/(\d+)$/.exec(range || '');
    return match ? Number(match[1]) : fallback;
}

// =====================================================================
// Storage buckets
// =====================================================================

export class StorageService {
    constructor(client, bucket) {
        this.client = client;
        this.bucket = bucket;
        this.path = '/storage/' + encodeURIComponent(bucket);
    }

    /**
     * One page of a listing, like S3 ListObjectsV2.
     *   prefix     key prefix: "users/42/" lists that folder
     *   delimiter  "/" (default) groups deeper keys into folders, "" lists every key below the prefix
     *   limit      1 - 1000 keys + folders, cursor = next_cursor of the previous page
     * Resolves { prefix, prefixes, folders, files, truncated, next_cursor }.
     */
    list(prefix = '', { delimiter = '/', limit, cursor, ...options } = {}) {
        const query = [['prefix', prefix], ['delimiter', delimiter]];
        if (limit) query.push(['limit', String(limit)]);
        if (cursor) query.push(['cursor', cursor]);
        return this.client.send(this.path, { ...options, query });
    }

    /** Every object below a prefix (recursive), following the pages. */
    async listAll(prefix = '', options = {}) {
        const files = [];
        let cursor;
        do {
            const page = await this.list(prefix, { ...options, delimiter: '', limit: 1000, cursor });
            files.push(...page.files);
            cursor = page.truncated ? page.next_cursor : null;
        } while (cursor);
        return files;
    }

    /** Metadata of an object: size, mime, etag, cache_control, metadata, original_name, dates, url. */
    info(path, options = {}) {
        return this.client.send(`${this.path}/${encodePath(path)}`, { ...options, query: [['info', '1']] });
    }

    /**
     * Upload a file. path = folder inside the bucket ("users/42"); options.name keeps a fixed file name,
     * options.upsert overwrites an existing file, options.cacheControl is sent back with the file,
     * options.metadata holds your own key / value pairs (like x-amz-meta-*).
     */
    upload(path, file, { name, upsert = false, cacheControl, metadata, ...options } = {}) {
        const form = new FormData();
        form.append('file', file);
        if (path) form.append('path', path);
        if (name) form.append('name', name);
        if (upsert) form.append('upsert', '1');
        if (cacheControl) form.append('cache_control', cacheControl);
        if (metadata) form.append('metadata', JSON.stringify(metadata));
        return this.client.send(this.path, { ...options, method: 'POST', body: form });
    }

    /** Download a file as a Blob. */
    download(path, options = {}) {
        return this.client.send(`${this.path}/${encodePath(path)}`, { ...options, as: 'blob' });
    }

    /** Public URL of a file (works without credentials for public buckets). */
    getUrl(path, query = {}) {
        return this.client.buildUrl(`${this.path}/${encodePath(path)}`, query);
    }

    async remove(path, options = {}) {
        await this.client.send(`${this.path}/${encodePath(path)}`, { ...options, method: 'DELETE' });
        return true;
    }
}

// =====================================================================
// Client
// =====================================================================

export default class GroceryRestable {
    /**
     * @param {string} baseUrl API base URL, e.g. "https://example.com/api"
     * @param {{apiKey?: string, token?: string, headers?: object, fetch?: Function, authStore?: AuthStore}} [options]
     */
    constructor(baseUrl = '/api', options = {}) {
        this.baseUrl = String(baseUrl).replace(/\/+$/, '');
        this.apiKey = options.apiKey || '';
        this.headers = { ...(options.headers || {}) };
        this.authStore = options.authStore || new AuthStore();
        if (options.token) this.authStore.save(options.token);
        this.fetch = options.fetch || ((...args) => globalThis.fetch(...args));
        /** Hook: (url, init) => void | {url, init}, called before every request. */
        this.beforeSend = null;
        /** Hook: (response, data) => data, called after every successful request. */
        this.afterSend = null;
        this.collections = new Map();
        /** User login: gr.auth.login(), loginWithGoogle(), refresh(), logout(), me(). */
        this.auth = new AuthService(this);
    }

    /** A resource of the API (PocketBase calls them collections). */
    collection(name) {
        if (!this.collections.has(name)) this.collections.set(name, new CollectionService(this, name));
        return this.collections.get(name);
    }

    /** Alias of collection() for Supabase users. */
    from(name) {
        return this.collection(name);
    }

    /** A storage bucket. */
    storage(bucket) {
        return new StorageService(this, bucket);
    }

    /** Buckets the caller can read. */
    buckets(options = {}) {
        return this.send('/storage', options);
    }

    /** Call a remote procedure registered with $api->rpc(). */
    rpc(name, params = {}, { method = 'POST', ...options } = {}) {
        const path = '/rpc/' + encodeURIComponent(name);
        return method === 'GET'
            ? this.send(path, { ...options, query: Object.entries(params).map(([k, v]) => [k, String(v)]) })
            : this.send(path, { ...options, method, body: params });
    }

    /** The OpenAPI document of what the caller can use. */
    openApi(options = {}) {
        return this.send('/openapi.json', options);
    }

    /** Use a user JWT (Authorization: Bearer) for the next requests. */
    setToken(token) {
        this.authStore.save(token);
        return this;
    }

    clearToken() {
        this.authStore.clear();
        return this;
    }

    /** Whether the stored access token is usable for at least 10 more seconds (session tokens always are). */
    tokenFresh() {
        const payload = this.authStore.payload;
        return !payload || !payload.exp || payload.exp * 1000 > Date.now() + 10000;
    }

    /**
     * @param {string} path
     * @param {object|Array<[string,string]>} [query]
     */
    buildUrl(path, query = {}) {
        const params = new URLSearchParams(Array.isArray(query) ? query : Object.entries(query).map(([k, v]) => [k, String(v)]));
        const search = params.toString();
        return this.baseUrl + (path.startsWith('/') ? path : '/' + path) + (search ? '?' + search : '');
    }

    /**
     * Send any request to the API. Resolves with the parsed JSON (or Blob / text / null).
     * Options: method, query, body, headers, prefer, signal, as ('json' | 'blob' | 'text'), raw,
     * noToken (do not send the user token), noRefresh (do not renew an expired token first).
     * With a refresh token (gr.auth), an expired access token is renewed before the request,
     * and a 401 answer is retried once after a refresh.
     */
    async send(path, options = {}) {
        const store = this.authStore;
        const canRefresh = !options.noToken && !options.noRefresh && store.refreshToken;
        if (canRefresh && !this.tokenFresh()) {
            try { await this.auth.refresh(); } catch (error) { /* continue without a valid token */ }
        }

        let url = this.buildUrl(path, options.query || []);
        const headers = { ...this.headers, ...(options.headers || {}) };
        if (this.apiKey) headers.apikey = this.apiKey;
        if (store.token && !options.noToken) headers.Authorization = 'Bearer ' + store.token;
        if (options.prefer) headers.Prefer = options.prefer;

        let body = options.body;
        if (body !== undefined && body !== null && !isFormData(body) && !isFile(body) && typeof body !== 'string') {
            body = JSON.stringify(body);
            headers['Content-Type'] = 'application/json';
        }
        let init = { method: options.method || 'GET', headers, body, signal: options.signal };
        if (this.beforeSend) {
            const changed = await this.beforeSend(url, init);
            if (changed) ({ url = url, init = init } = changed);
        }

        let response;
        try {
            response = await this.fetch(url, init);
        } catch (error) {
            const isAbort = error && error.name === 'AbortError';
            throw new GroceryRestableError(isAbort ? 'The request was aborted.' : 'The server could not be reached.', { url, isAbort, cause: error });
        }

        const type = response.headers.get('content-type') || '';
        let data = null;
        if (response.status !== 204) {
            if (options.as === 'blob' && response.ok) data = await response.blob();
            else if (options.as === 'text' || !type.includes('json')) data = await response.text();
            else data = await response.json().catch(() => null);
        }
        if (response.status === 401 && canRefresh && !options.retried && !isFormData(options.body)) {
            try {
                await this.auth.refresh();
                return this.send(path, { ...options, retried: true });
            } catch (error) { /* report the original 401 */ }
        }
        if (!response.ok) {
            const error = (data && data.error) || {};
            throw new GroceryRestableError(error.message || response.statusText || 'Request failed.', {
                status: response.status, code: error.code || 'http_' + response.status, details: error.details || {}, url,
            });
        }
        if (this.afterSend) data = await this.afterSend(response, data);
        return options.raw ? { data, response } : data;
    }
}

export { GroceryRestable };
