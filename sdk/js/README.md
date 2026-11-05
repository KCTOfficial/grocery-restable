# Grocery Restable JavaScript SDK

One small ES module (no dependencies) to use a Grocery Restable API from browsers and Node.js 18+.

```js
import GroceryRestable from '@kctofficial/grocery-restable';

const gr = new GroceryRestable('https://example.com/api', { apiKey: 'gr_your_anon_key' });

// list and search
const { items, totalItems } = await gr.collection('employees').getList(1, 20, {
    filter: { officeCode: '1', salary: { gte: 5000 } },
    sort: '-lastName,firstName',
    select: '*,offices(city)',
});

// one record, create, update, delete
const one = await gr.collection('employees').getOne(1002);
const created = await gr.collection('employees').create({ lastName: 'Doe', firstName: 'Jane' });
await gr.collection('employees').update(created.employeeNumber, { jobTitle: 'VP Sales' });
await gr.collection('employees').delete(created.employeeNumber);
```

## Install

| Where | How |
|---|---|
| Bundler / Node.js | Copy `sdk/js` into your project or install it from the repository: `npm install ./vendor/kctofficial/grocery-restable/sdk/js` |
| Browser, no build step | `import GroceryRestable from 'https://your-host/api/sdk.js'` (the API serves the SDK itself) |

## Client

```js
const gr = new GroceryRestable(baseUrl, {
    apiKey: 'gr_...',       // sent as "apikey" header. In browsers use the anon key, never service_role
    token: 'eyJ...',        // optional user JWT, sent as "Authorization: Bearer"
    headers: {},            // extra headers for every request
    fetch: customFetch,     // optional fetch implementation
});

gr.setToken(jwt);           // after your own login
gr.authStore.isValid;       // token set and not expired
gr.authStore.onChange((token) => localStorage.setItem('token', token));
gr.clearToken();

gr.beforeSend = (url, init) => { /* change url / init, or return { url, init } */ };
gr.afterSend = (response, data) => data;
```

## User login

When user login is enabled on the server (Settings > User login):

```js
import GroceryRestable, { LocalAuthStore } from '@kctofficial/grocery-restable';

const gr = new GroceryRestable(baseUrl, { apiKey: 'gr_anon', authStore: new LocalAuthStore() });

await gr.auth.login('jane@example.com', 'secret');               // POST /auth/login
await gr.auth.loginWithGoogle({ idToken: response.credential });  // POST /auth/google (or { code })
gr.auth.user;                                                     // the signed in user
await gr.auth.logout();                                           // { all: true } = every device
```

Expired access tokens are renewed automatically with the refresh token (one request for parallel calls).

## Collections (resources)

| Method | Request |
|---|---|
| `getList(page, perPage, options)` | `GET /{name}?limit&offset&count=exact` returns `{ page, perPage, totalItems, totalPages, items }` |
| `getFullList(options)` | pages through every record (`options.batch`, default 500) |
| `getFirstListItem(filter, options)` | first match, rejects with a 404 error when there is none |
| `getOne(id, options)` | `GET /{name}/{id}` |
| `create(data, options)` | `POST /{name}`; objects containing `File` / `Blob` values are sent as multipart |
| `createMany(rows)` / `upsert(data)` | bulk insert / insert or update by primary key |
| `update(id, data, options)` | `PATCH /{name}/{id}` (multipart with files) |
| `updateMany(filter, data)` / `deleteMany(filter)` | bulk operations, a filter is required |
| `delete(id)` | `DELETE /{name}/{id}` |
| `clone(id, overrides)` | `POST /{name}/{id}/clone` |
| `uploadFile(id, field, file)` / `deleteFile(id, field)` | upload fields |
| `getFile(id, field)` / `getFileUrl(id, field)` | download as `Blob` / URL |
| `options(field, { search, limit })` | value / label pairs of a relation |
| `action(id, name, body)` | custom actions defined in PHP |
| `schema()` | field metadata to build forms |

`gr.from(name)` is an alias of `gr.collection(name)`.

### Options

| Option | Example | Query |
|---|---|---|
| `filter` | `{ city: 'Paris' }` | `city=eq.Paris` |
| | `{ salary: { gte: 5000, lt: 9000 } }` | `salary=gte.5000&salary=lt.9000` |
| | `{ officeCode: ['1', '4'] }` | `officeCode=in.(1,4)` |
| | `{ reportsTo: null, active: true }` | `reportsTo=is.null&active=is.true` |
| | `{ email: { ilike: '*@example.com' } }` | `email=ilike.*@example.com` |
| | `{ status: { not: { in: ['a', 'b'] } } }` | `status=not.in.(a,b)` |
| | `{ or: [{ age: { lt: 18 } }, { age: { gt: 65 } }] }` | `or=(age.lt.18,age.gt.65)` |
| | `{ 'offices.city': 'Paris' }` | filter on a relation |
| | `'city=eq.Paris'` | a raw query string is used as is |
| `sort` | `'-lastName,firstName'` | `order=lastName.desc,firstName.asc` |
| `select` | `'*,offices(city),projects(name)'` | columns and embedded relations |
| `search` | `'smith'` | `q=smith` |
| `query` | `{ format: 'csv' }` | extra parameters |
| `signal` | `controller.signal` | abort the request |

## Storage

```js
const avatars = gr.storage('avatars');
await avatars.upload('users/42', file, { name: 'me.png', upsert: true, cacheControl: 'public, max-age=86400', metadata: { author: 'jane' } });
const { prefixes, files, next_cursor } = await avatars.list('users/42/', { limit: 100 });   // like S3 ListObjectsV2
const everything = await avatars.listAll('users/');                                        // recursive, all pages
const info = await avatars.info('users/42/me.png');                                        // size, etag, mime, metadata
const url = avatars.getUrl('users/42/me.png');     // public buckets
const blob = await avatars.download('users/42/me.png');
await avatars.remove('users/42/me.png');
const buckets = await gr.buckets();
```

## RPC and anything else

```js
const stats = await gr.rpc('stats', { year: 2026 });              // POST /rpc/stats
const report = await gr.rpc('report', { id: 1 }, { method: 'GET' });
const raw = await gr.send('/employees', { query: { limit: 1 } });  // any request
```

## Errors

Every failure rejects with a `GroceryRestableError`:

```js
try {
    await gr.collection('employees').create({});
} catch (e) {
    e.status;   // 422
    e.code;     // "validation_failed", "rate_limited", "not_found", ...
    e.details;  // { fields: { lastName: "The Last name field is required." } }
}
```

When the API rate limit is reached the status is `429`; the response carries `Retry-After` and
`X-RateLimit-*` headers.

## Tests

```bash
npm test        # node --test, no dependencies
```
