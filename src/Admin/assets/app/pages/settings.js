/*!
 * Grocery Restable admin - Settings area: one page per topic, each with its own Save.
 *
 *   GET /settings/:page    application · api · auth · cors · uploads · connections · admins · io
 */
(function (GR, $) {
    'use strict';

    const { esc, icon, badge, sourceBadge, panel, codeBlock } = GR.html;
    const { absoluteApiUrl, formValues, formatBytes } = GR.util;
    const { api } = GR.http;
    const { toast, fail, drawer, confirmDialog, download } = GR.ui;
    const C = GR.components;
    const shell = GR.shell;
    const store = GR.store;
    const $content = shell.$content;

    const MENU = [
        { group: 'Application', items: [['account', 'My account', 'circle-user'], ['application', 'General', 'circle-info'], ['connections', 'Database connections', 'database'], ['admins', 'Admin users', 'users']] },
        { group: 'API', items: [['api', 'API behaviour', 'table-list'], ['auth', 'Authentication', 'shield-halved'], ['login', 'User login', 'right-to-bracket'], ['ratelimit', 'Rate limiting', 'gauge-high'], ['cors', 'CORS', 'globe'], ['uploads', 'Uploads', 'upload']] },
        { group: 'Maintenance', items: [['logging', 'Logging', 'list-ul'], ['cleanup', 'Storage cleanup', 'broom'], ['io', 'Export / Import', 'right-left']] },
    ];
    const titleOf = (page) => MENU.flatMap((g) => g.items).find(([key]) => key === page);

    shell.defineNav('settings', {
        head: () => shell.navHead('Settings', 'gear'),
        body: () => MENU.map((g) => `<div class="gr-nav-caption">${esc(g.group)}</div><ul class="gr-menu">
            ${g.items.map(([key, label, iconName]) => shell.navItem({ href: '#/settings/' + key, iconName, label })).join('')}</ul>`).join(''),
    });

    // ---------------------------------------------------------------- form helpers

    const switchField = (s, name, label, help = '') => `<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="s-${name}" name="${name}" ${s[name] ? 'checked' : ''}>
        <label class="form-check-label" for="s-${name}">${label}</label>${help ? `<div class="gr-help">${help}</div>` : ''}</div>`;

    const inputField = (s, name, label, help = '', type = 'text') => {
        const value = s[name];
        return `<div class="mb-2"><label class="form-label small mb-0" for="s-${name}">${label}</label>
            <input type="${type}" class="form-control form-control-sm" id="s-${name}" name="${name}" value="${esc(Array.isArray(value) ? value.join(', ') : value ?? '')}">${help ? `<div class="gr-help">${help}</div>` : ''}</div>`;
    };

    /** Render a settings form; only the fields on this page are saved. */
    function settingsForm(panels, { extraButtons = '', beforeSave = (v) => v, bind = () => {} } = {}) {
        $content().html(`<form id="settings-form" class="gr-narrow">${panels}
            <div class="gr-sticky-footer"><button class="btn btn-sm btn-primary" type="submit" data-save>${icon('floppy-disk')} Save changes</button>${shell.dirtyBadge()}${extraButtons}</div></form>`);
        const $form = $('#settings-form');
        shell.trackDirty($form);
        bind($form);
        $form.on('submit', async (e) => {
            e.preventDefault();
            try {
                await api('settings.save', { settings: beforeSave(formValues($form), $form) });
                shell.clearDirty();
                toast('Settings saved');
                await shell.refreshAll();
                GR.reload();
            } catch (err) { fail(err); }
        });
    }

    // ---------------------------------------------------------------- pages

    const PAGES = {
        application(s) {
            const b = store.boot;
            settingsForm(panel(`${icon('circle-info')} General`, `${inputField(s, 'app_name', 'Application name', 'Shown in the browser title, the login page and the breadcrumb.')}
                ${inputField(s, 'api_description', 'API description', 'Used as description of the OpenAPI document.')}`)
                + panel(`${icon('server')} System`, `<table class="gr-table gr-kv w-100 mb-0">
                    <tr><th>Version</th><td>Grocery Restable ${esc(GR.CFG.version)}</td></tr>
                    <tr><th>Framework</th><td>${esc((b.frameworks[b.framework] || {}).label || b.framework)}</td></tr>
                    <tr><th>API base URL</th><td><code>${esc(absoluteApiUrl())}</code> <a href="#" data-copy="${esc(absoluteApiUrl())}" title="Copy">${icon('copy')}</a></td></tr>
                    <tr><th>Admin URL</th><td><code>${esc(GR.CFG.adminUrl)}</code></td></tr>
                    <tr><th>Logged in as</th><td>${icon('user')} ${esc(b.user.username)}</td></tr></table>`));
        },

        api(s) {
            settingsForm(panel(`${icon('table-list')} API behaviour`, `${switchField(s, 'auto_expose', 'Auto expose every table of the default connection', 'Tables configured in code or in the API area keep their own definition.')}
                ${inputField(s, 'auto_exclude_tables', 'Tables excluded from auto expose', 'Comma separated.')}
                <div class="row g-2"><div class="col">${inputField(s, 'default_per_page', 'Default page size', '', 'number')}</div><div class="col">${inputField(s, 'max_limit', 'Maximum page size', '', 'number')}</div>
                <div class="col">${inputField(s, 'schema_cache_ttl', 'Schema cache TTL (s)', '0 disables the cache.', 'number')}</div></div>
                ${switchField(s, 'response_envelope', 'Wrap list responses in {"data": [...], "meta": {...}}', 'Off = PostgREST compatible bare arrays with Content-Range headers.')}
                ${switchField(s, 'expose_openapi', 'Publish /openapi.json')}`), {
                extraButtons: `<button class="btn btn-sm btn-outline-secondary ms-auto" type="button" id="clear-cache">${icon('rotate')} Clear schema cache</button>`,
                bind: ($form) => $form.find('#clear-cache').on('click', async () => {
                    try { await api('schema.refresh'); toast('Schema cache cleared'); await shell.refreshAll(); } catch (err) { fail(err); }
                }),
            });
        },

        auth(s) {
            const ops = store.boot.permissions;
            const modes = [['api_key', 'API keys'], ['any', 'API keys + user tokens (JWT, sessions)'], ['jwt', 'User tokens only (JWT, sessions)'], ['none', 'None (open API - development only)']];
            settingsForm(panel(`${icon('shield-halved')} Authentication`, `<div class="mb-2"><label class="form-label small mb-0" for="s-auth_mode">Mode</label><select class="form-select form-select-sm" id="s-auth_mode" name="auth_mode">
                    ${modes.map(([v, l]) => `<option value="${v}" ${s.auth_mode === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
                ${switchField(s, 'allow_query_apikey', 'Accept ?apikey= in the query string', 'Convenient for &lt;img src&gt; but keys end up in server logs.')}
                ${switchField(s, 'jwt_require_exp', 'Reject JWTs without an expiry (exp claim)', 'A token without "exp" would be valid forever.')}
                ${switchField(s, 'jwt_allow_service_role', 'Allow JWTs to grant the service_role role', 'Off by default: only the service_role API key bypasses permissions.')}
                <p class="gr-help mb-0">Manage the keys themselves in <a href="#/api/keys">API › API keys</a>.</p>`)
                + panel(`${icon('user-lock')} Default permissions`, `<p class="gr-help mb-1">Used by endpoints and buckets that do not define their own. <code>service_role</code> always has full access.</p>
                    <div id="s-perms">${C.permissionGrid(s.default_permissions || {}, ops, 'default')}</div>`)
                + panel(`${icon('id-badge')} JWT`, `${s.jwt_secret_from_config ? '<div class="alert alert-info py-1 small">The JWT secret is set in the framework configuration (jwtSecret) and overrides this one.</div>' : ''}
                    <div class="mb-2"><label class="form-label small mb-0" for="s-jwt_secret">Secret (HMAC) ${s.jwt_secret_set ? badge('set', 'success') : badge('not set', 'secondary')}</label>
                    <div class="input-group input-group-sm"><input type="password" class="form-control" id="s-jwt_secret" name="jwt_secret" placeholder="${s.jwt_secret_set ? 'leave empty to keep the current secret' : 'at least 32 characters'}" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" id="gen-secret">${icon('dice')} Generate</button></div></div>
                    <div class="row g-2"><div class="col"><label class="form-label small mb-0" for="s-jwt_algorithm">Algorithm</label><select class="form-select form-select-sm" id="s-jwt_algorithm" name="jwt_algorithm">${['HS256', 'HS384', 'HS512'].map((a) => `<option ${s.jwt_algorithm === a ? 'selected' : ''}>${a}</option>`).join('')}</select></div>
                    <div class="col">${inputField(s, 'jwt_role_claim', 'Role claim', 'Dot notation allowed, e.g. app_metadata.role')}</div><div class="col">${inputField(s, 'jwt_default_role', 'Default role')}</div></div>
                    <div class="row g-2"><div class="col">${inputField(s, 'jwt_issuer', 'Required issuer (iss)')}</div><div class="col">${inputField(s, 'jwt_audience', 'Required audience (aud)')}</div></div>`), {
                beforeSave: (v, $form) => ({ ...v, default_permissions: C.readPermissionGrid($form.find('#s-perms .gr-perm-grid')) }),
                bind: ($form) => {
                    C.bindPermissionGrid($form, ops);
                    $form.find('#gen-secret').on('click', () => {
                        const bytes = new Uint8Array(32);
                        crypto.getRandomValues(bytes);
                        $form.find('[name=jwt_secret]').attr('type', 'text').val(Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')).trigger('input');
                    });
                },
            });
        },

        login(s) {
            const b = store.boot;
            const option = (value, label, current) => `<option value="${esc(value)}" ${current === value ? 'selected' : ''}>${esc(label)}</option>`;
            const select = (name, label, options, help = '') => `<div class="mb-2"><label class="form-label small mb-0" for="s-${name}">${label}</label>
                <select class="form-select form-select-sm" id="s-${name}" name="${name}">${options.map(([v, l]) => option(v, l, s[name])).join('')}</select>${help ? `<div class="gr-help">${help}</div>` : ''}</div>`;
            const signupFields = Object.entries(s.auth_google_signup_fields || {}).map(([c, v]) => `${c}=${v}`).join(', ');
            const tokensOk = ['jwt', 'any'].includes(s.auth_mode);
            const base = absoluteApiUrl();
            const sample = `import GroceryRestable from '${base}/sdk.js';

const gr = new GroceryRestable('${base}', { apiKey: 'YOUR_ANON_KEY' });

// password login: tokens are stored and refreshed by the SDK
const { user } = await gr.auth.login('jane@example.com', 'secret');

// Google: the "credential" of Sign in with Google (or { code } of the popup code flow)
await gr.auth.loginWithGoogle({ idToken: response.credential });

await gr.collection('notes').getList();   // runs as the signed in user
await gr.auth.logout();`;

            settingsForm(panel(`${icon('right-to-bracket')} User login`, `<p class="gr-help">Sign users of <b>your own users table</b> in through the API and get tokens that work with permissions and
                    <code>scope()</code> like any other JWT. Endpoints: <code>POST /auth/login</code>, <code>POST /auth/google</code>, <code>POST /auth/refresh</code>,
                    <code>POST /auth/logout</code>, <code>GET /auth/me</code>, <code>GET /auth</code>.</p>
                ${tokensOk ? '' : `<div class="alert alert-warning py-1 small">The authentication mode does not accept user tokens. Set it to "API keys + user tokens" in <a href="#/settings/auth">Authentication</a>.</div>`}
                ${switchField(s, 'auth_password_enabled', 'Password login', 'Identity (email, username...) and password, checked against the users table.')}
                ${switchField(s, 'auth_google_enabled', 'Sign in with Google', 'Google ID tokens are verified on the server (signature, audience, expiry).')}`)
                + panel(`${icon('table')} Users table`, `<div class="row g-2"><div class="col">${select('auth_users_connection', 'Connection', [['', 'Default connection'], ...b.connections.map((c) => [c.name, c.name])])}</div>
                    <div class="col">${inputField(s, 'auth_users_table', 'Table')}</div></div>
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_users_id_column', 'Id column', 'Becomes the "sub" claim / $auth->userId().')}</div><div class="col">${inputField(s, 'auth_users_password_column', 'Password hash column')}</div></div>
                    ${inputField(s, 'auth_users_identity_columns', 'Identity columns', 'Comma separated, tried in order, e.g. email, username. Text columns are compared case-insensitively.')}
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_users_role_column', 'Role column', `Optional. Empty = the default JWT role (${esc(s.jwt_default_role || 'authenticated')}). service_role is refused.`)}</div>
                    <div class="col">${inputField(s, 'auth_users_active_column', 'Active column', 'Optional. 0 / false / empty = login refused.')}</div></div>
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_users_claim_columns', 'Columns copied into the token', 'e.g. tenant_id, read with $auth->claim(\'tenant_id\').')}</div>
                    <div class="col">${inputField(s, 'auth_users_hidden_columns', 'Columns never returned', 'The password column never is.')}</div></div>`)
                + panel(`${icon('key')} Tokens`, `${select('auth_token_strategy', 'Strategy', [['jwt', 'JWT + rotating refresh token (stateless, scales horizontally)'], ['session', 'Session token (server side, revoked instantly)']],
                    'JWT: short lived access token, refresh tokens are rotated and a reused one revokes the session. Session: one opaque token, checked on every request.')}
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_access_ttl', 'Access token lifetime (s)', 'JWT strategy, 60 to 86400. Default 900 (15 min).', 'number')}</div>
                    <div class="col">${inputField(s, 'auth_session_ttl', 'Session / refresh token lifetime (s)', 'Absolute, from the login. Default 30 days.', 'number')}</div></div>`)
                + panel(`${icon('fingerprint')} Password hashing`, `${select('auth_password_algorithm', 'Algorithm for new hashes', b.passwordAlgorithms.map((a) => [a, a + (a === 'argon2id' ? ' (recommended)' : '')]),
                    'Standard password_hash() formats: compatible with CodeIgniter Shield, Laravel and password_verify(). Also used by "password" fields of endpoints.')}
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_password_cost', 'bcrypt cost', '10 to 15', 'number')}</div>
                    <div class="col">${inputField(s, 'auth_password_memory', 'argon2 memory (KiB)', 'min 19456', 'number')}</div><div class="col">${inputField(s, 'auth_password_time', 'argon2 iterations', '2 to 10', 'number')}</div></div>
                    ${switchField(s, 'auth_password_rehash', 'Rehash on login', 'Upgrades older algorithms, weaker parameters and legacy digests to the settings above.')}
                    <label class="form-label small mb-0">Legacy digests (verify only, for migrating old tables)</label>
                    <div class="mb-1">${b.legacyDigests.map((d) => `<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="s-legacy-${d}" data-legacy="${d}" ${(s.auth_password_legacy || []).includes(d) ? 'checked' : ''}>
                        <label class="form-check-label small" for="s-legacy-${d}">${d}</label></div>`).join('')}</div>
                    <div class="gr-help">Unsalted hex digests are weak: enable them only while migrating, users are rehashed at their next login.</div>`)
                + panel(`${icon('user-shield')} Brute force protection`, `<div class="row g-2"><div class="col">${inputField(s, 'auth_max_attempts', 'Failures per account', '0 = no limit', 'number')}</div>
                    <div class="col">${inputField(s, 'auth_max_attempts_ip', 'Failures per IP', '0 = no limit', 'number')}</div><div class="col">${inputField(s, 'auth_attempts_window', 'Window (s)', '', 'number')}</div></div>
                    <div class="gr-help">Over the limit, logins answer 429 with Retry-After, even with the right password.</div>`)
                + panel('<i class="fa-brands fa-google"></i> Google', `${inputField(s, 'auth_google_client_ids', 'OAuth client ids', 'Comma separated (web, Android, iOS). The first one is the web client.')}
                    <div class="mb-2"><label class="form-label small mb-0" for="s-auth_google_client_secret">Client secret ${s.auth_google_client_secret_set ? badge('set', 'success') : badge('not set', 'secondary')}</label>
                    <input type="password" class="form-control form-control-sm" id="s-auth_google_client_secret" name="auth_google_client_secret" autocomplete="new-password" placeholder="${s.auth_google_client_secret_set ? 'leave empty to keep the current secret' : 'only needed for authorization codes'}">
                    <div class="gr-help">Not needed for ID tokens (Sign in with Google button, One Tap, mobile SDKs).</div></div>
                    <div class="row g-2"><div class="col">${inputField(s, 'auth_google_id_column', 'Google id column', 'Stores the Google "sub". Recommended.')}</div><div class="col">${inputField(s, 'auth_google_email_column', 'Email column')}</div></div>
                    ${switchField(s, 'auth_google_link_by_email', 'Link to an existing user with the same verified email')}
                    ${switchField(s, 'auth_google_signup', 'Create a user on the first Google sign-in')}
                    ${inputField({ auth_google_signup_fields: signupFields }, 'auth_google_signup_fields', 'Columns of new users', 'column=claim pairs, e.g. name=name, avatar=picture (claims: name, given_name, family_name, picture, locale).')}
                    ${inputField(s, 'auth_google_hosted_domains', 'Allowed Google Workspace domains', 'Comma separated, empty = any Google account.')}`)
                + panel(`${icon('code')} Usage`, codeBlock(sample, 'javascript', 'mb-0')), {
                beforeSave: (v, $form) => ({ ...v, auth_password_legacy: $form.find('[data-legacy]:checked').map(function () { return this.dataset.legacy; }).get() }),
            });
        },

        cors(s) {
            settingsForm(panel(`${icon('globe')} CORS`, `${switchField(s, 'cors_enabled', 'Send CORS headers')}
                ${inputField(s, 'cors_origins', 'Allowed origins', 'Comma separated, * for any origin.')}${inputField(s, 'cors_max_age', 'Preflight max age (s)', '', 'number')}`));
        },

        uploads(s) {
            settingsForm(panel(`${icon('upload')} Uploads`, `${inputField(s, 'upload_allowed_types', 'Allowed file types (default)', 'Separated with |. Server side scripts are always rejected; html/svg/js only when listed explicitly on a field or bucket.')}
                ${inputField(s, 'upload_max_size', 'Maximum file size (default)', 'e.g. 20MB, 512KB')}${switchField(s, 'delete_replaced_files', 'Delete files from disk when they are replaced or their record is deleted')}`));
        },

        account() {
            const user = store.boot.user;
            $content().html(`<form id="account-form" class="gr-narrow" autocomplete="off">${panel(`${icon('circle-user')} My account`, `
                <div class="mb-2"><label class="form-label small mb-0" for="a-username">Username (used to log in)</label>
                    <input class="form-control form-control-sm" id="a-username" name="username" value="${esc(user.username)}" autocomplete="username"></div>
                <div class="row g-2"><div class="col-md-6"><label class="form-label small mb-0" for="a-password">New password</label>
                    <input type="password" class="form-control form-control-sm" id="a-password" name="password" placeholder="leave empty to keep" autocomplete="new-password"></div>
                <div class="col-md-6"><label class="form-label small mb-0" for="a-confirm">Confirm new password</label>
                    <input type="password" class="form-control form-control-sm" id="a-confirm" name="password_confirm" autocomplete="new-password"></div></div>
                <hr><div class="mb-1" style="max-width:360px"><label class="form-label small mb-0" for="a-current">Current password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control form-control-sm" id="a-current" name="current_password" required autocomplete="current-password"></div>
                <p class="gr-help mb-0">Your current password is required to change the username or the password. Other admins are managed in <a href="#/settings/admins">Admin users</a>.</p>`)}
                <div class="gr-sticky-footer"><button class="btn btn-sm btn-primary" type="submit" data-save>${icon('floppy-disk')} Update account</button>${shell.dirtyBadge()}</div></form>`);
            const $form = $('#account-form');
            shell.trackDirty($form);
            $form.on('submit', async (e) => {
                e.preventDefault();
                try {
                    const r = await api('account.update', formValues($form));
                    shell.clearDirty();
                    store.boot.user.username = r.username;
                    toast('Account updated');
                    GR.reload();
                } catch (err) { fail(err); }
            });
        },

        ratelimit(s) {
            settingsForm(panel(`${icon('gauge-high')} Rate limiting`, `${switchField(s, 'rate_limit_enabled', 'Limit the number of API requests per client', 'Clients that exceed the limit receive <code>429 Too Many Requests</code> with a <code>Retry-After</code> header.')}
                <div class="row g-2"><div class="col-md-4">${inputField(s, 'rate_limit_requests', 'Requests per window', 'API keys and signed-in users (JWT), counted per key / user.', 'number')}</div>
                <div class="col-md-4">${inputField(s, 'rate_limit_window', 'Window (seconds)', 'e.g. 60 = per minute.', 'number')}</div>
                <div class="col-md-4">${inputField(s, 'rate_limit_anon_requests', 'Anonymous requests per window', 'Counted per IP address (the anon key is public). 0 = same as above.', 'number')}</div></div>
                ${switchField(s, 'rate_limit_exempt_service', 'Exempt the service_role (server side jobs)')}
                <p class="gr-help mb-0">Failed authentications count against the client IP, which slows down key and token guessing.
                An endpoint can have its own limit: <b>API</b> &rsaquo; endpoint &rsaquo; <b>General</b>. Every response carries <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code>.</p>`));
        },

        cleanup(s) {
            const TASKS = {
                orphan_files: ['Orphan upload files', 'Files of upload fields that no row references any more.', 'moved to the trash', true],
                orphan_buckets: ['Orphan bucket folders', 'Folders of buckets that were deleted.', 'moved to the trash', true],
                stale_index: ['Stale metadata', 'Index rows whose file is gone.', 'removed', true],
                untracked_objects: ['Untracked bucket files', 'Files copied into buckets by hand, without metadata.', 'indexed', true],
                tmp_files: ['Interrupted uploads', 'Temporary files older than one hour.', 'deleted', true],
                empty_folders: ['Empty folders', 'Folders without files inside buckets (S3 has no folders).', 'removed', true],
                trash: ['Expired trash', `Trash days older than ${s.storage_trash_days} days.`, 'deleted', true],
                trash_all: ['Whole trash', 'Everything in the trash, including recent days.', 'deleted', false],
            };
            const items = (task, report) => {
                const list = report.tasks[task].items;
                if (!list.length) return '';
                const cols = Object.keys(list[0]);
                return `<details class="small mt-1"><summary>${esc(list.length)} of ${esc(report.tasks[task].count)} shown</summary><table class="gr-table w-100 mt-1"><thead><tr>${cols.map((c) => `<th>${esc(c)}</th>`).join('')}</tr></thead>
                    <tbody>${list.map((it) => `<tr>${cols.map((c) => `<td class="text-break">${esc(c === 'size' ? formatBytes(it[c]) : it[c])}</td>`).join('')}</tr>`).join('')}</tbody></table></details>`;
            };
            const reportHtml = (report) => `${report.skipped.map((k) => `<div class="alert alert-warning py-1 small mb-2">${icon('triangle-exclamation', 'me-1')}Skipped <code>${esc(k.directory)}</code>: ${esc(k.reason)}. Fix the resource, otherwise files there are never treated as orphans.</div>`).join('')}
                <table class="gr-table w-100"><thead><tr><th></th><th>Task</th><th class="text-end">Items</th><th class="text-end">Size</th><th>Result</th></tr></thead><tbody>
                ${Object.entries(TASKS).map(([task, [title, text, result, preset]]) => {
                    const t = report.tasks[task];
                    return `<tr><td class="text-center"><input type="checkbox" class="form-check-input" data-task="${task}" data-no-dirty ${preset && t.count ? 'checked' : ''} ${t.count ? '' : 'disabled'} aria-label="${esc(title)}"></td>
                        <td><b>${esc(title)}</b><div class="small text-muted">${text}</div>${items(task, report)}</td>
                        <td class="text-end">${esc(t.count)}</td><td class="text-end">${esc(formatBytes(t.bytes))}</td><td class="small">${esc(result)}</td></tr>`;
                }).join('')}</tbody></table>
                <p class="gr-help mb-2">Files younger than ${esc(report.min_age_hours)} hours are never orphans. Trash: <code>${esc(report.trash_path)}</code>. Scanned ${esc(report.scanned_at)}.</p>`;

            settingsForm(panel(`${icon('magnifying-glass')} Scan &amp; clean`, `<p class="small mb-2">A scan only reports (dry run). Nothing is changed until you clean the selected tasks.
                    Orphans are moved to the trash first, so they can be restored by hand until the trash expires.</p>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><button type="button" class="btn btn-sm btn-primary" id="scan">${icon('magnifying-glass')} Scan</button>
                    <div class="input-group input-group-sm" style="max-width:260px"><span class="input-group-text">Min. age</span><input type="number" min="0" class="form-control" id="scan-age" value="${esc(s.orphan_min_age_hours)}" data-no-dirty aria-label="Minimum age in hours"><span class="input-group-text">hours</span></div>
                    <button type="button" class="btn btn-sm btn-danger ms-auto d-none" id="clean">${icon('broom')} Clean selected</button></div>
                <div id="scan-report"><p class="text-muted small mb-0">Click <b>Scan</b> to look for orphan files and leftovers.</p></div>`)
                + panel(`${icon('sliders')} Settings`, `${switchField(s, 'storage_auto_cleanup', 'Daily housekeeping', 'Once a day, on the first storage write: stale metadata, untracked files, interrupted uploads, empty folders and expired trash. Orphans are never removed automatically.')}
                    <div class="row g-2"><div class="col-md-6">${inputField(s, 'orphan_min_age_hours', 'Minimum age of an orphan (hours)', 'Protects uploads that are still in progress.', 'number')}</div>
                    <div class="col-md-6">${inputField(s, 'storage_trash_days', 'Keep the trash for (days)', '0 deletes cleaned files immediately.', 'number')}</div></div>
                    <p class="gr-help mb-0">Schedule a full cleanup with cron: <code>php spark grocery-restable:cleanup --run</code> (CodeIgniter 4), or <code>$api-&gt;janitor()-&gt;clean([...])</code> without a framework.</p>`), {
                bind: ($form) => {
                    const scan = async () => {
                        $form.find('#scan-report').html(GR.html.loading('Scanning…'));
                        try {
                            const report = await api('storage.scan', { minAgeHours: Number($form.find('#scan-age').val()) });
                            $form.find('#scan-report').html(reportHtml(report));
                            $form.find('#clean').toggleClass('d-none', !Object.values(report.tasks).some((t) => t.count));
                        } catch (err) { fail(err); }
                    };
                    $form.find('#scan').on('click', scan);
                    $form.find('#clean').on('click', async () => {
                        const tasks = $form.find('[data-task]:checked').map(function () { return this.dataset.task; }).get();
                        if (!tasks.length) { toast('Select at least one task', 'warning'); return; }
                        if (!await confirmDialog(`Clean: <b>${tasks.map((t) => esc(TASKS[t][0])).join(', ')}</b>?`, 'Clean', 'btn-danger')) return;
                        try {
                            const done = await api('storage.clean', { tasks, minAgeHours: Number($form.find('#scan-age').val()) });
                            toast(Object.entries(done).map(([t, n]) => `${TASKS[t][0]}: ${n}`).join(', '));
                            scan();
                        } catch (err) { fail(err); }
                    });
                },
            });
        },

        logging(s) {
            const modes = [['all', 'Every request'], ['errors', 'Only errors (status 400 and above)'], ['none', 'Nothing']];
            settingsForm(panel(`${icon('list-ul')} Logging`, `<div class="mb-2"><label class="form-label small mb-0" for="s-log_requests">API requests to log</label>
                    <select class="form-select form-select-sm" id="s-log_requests" name="log_requests">${modes.map(([v, l]) => `<option value="${v}" ${s.log_requests === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
                ${switchField(s, 'log_admin', 'Log admin actions (logins, configuration changes)')}
                ${inputField(s, 'log_retention_days', 'Keep log files for (days)', '0 keeps them forever. Older files are deleted when the first entry of a new day is written.', 'number')}
                <p class="gr-help mb-0">One JSON lines file per day in <code>${esc(store.boot.logPath)}</code>. Request bodies are never logged and API keys in query strings are masked.
                Browse them in <a href="#/logs">Logs</a>.</p>`));
        },

        async connections() {
            const list = await api('connections.list');
            const engines = store.boot.engines;
            const driverLabel = (c) => (c.source === 'framework' ? c.client || '' : ({ pdo: 'PDO', mysqli: 'MySQLi' }[c.client || 'pdo']));
            const row = (c) => `<tr><td><b>${esc(c.name)}</b></td><td>${sourceBadge(c.source)}</td><td>${esc((engines[c.driver] || {}).label || c.driver || '')}</td><td>${esc(driverLabel(c))}</td><td>${esc(c.host || '')}${c.port ? ':' + esc(c.port) : ''}</td>
                <td>${esc(c.database || '')}</td><td class="text-center">${c.default ? icon('star', 'text-warning') : `<a href="#" data-default="${esc(c.name)}" title="Make default">${icon('star', 'text-muted')}</a>`}</td>
                <td class="gr-actions-cell"><a href="#" data-test="${esc(c.name)}">${icon('plug')} Test</a>
                ${c.source === 'gui' ? `<a href="#" data-edit="${esc(c.name)}">${icon('pen')} Edit</a>${!c.default ? `<a href="#" class="text-danger" data-del="${esc(c.name)}">${icon('trash')} Delete</a>` : ''}` : '<span class="text-muted small">configured in the framework</span>'}</td></tr>`;

            $content().html(panel(`${icon('database')} Database connections`, `<table class="gr-table w-100"><thead><tr><th>Name</th><th>Source</th><th>Engine</th><th>PHP driver</th><th>Host</th><th>Database</th><th>Default</th><th></th></tr></thead>
                <tbody>${list.map(row).join('')}</tbody></table>
                <p class="gr-help mb-0">The default connection is used by auto expose. Passwords are stored encrypted and never sent back to the browser.</p>`,
                `<button class="btn btn-sm btn-primary" data-add>${icon('plus')} Add connection</button>`));

            const editor = (existing) => {
                const d = existing || { driver: C.defaultEngine(engines), client: 'pdo' };
                const $d = drawer({
                    title: existing ? `${icon('pen')} Edit connection <b>${esc(existing.name)}</b>` : `${icon('plus')} Add connection`,
                    size: 'lg',
                    body: `<div class="mb-2"><label class="form-label small mb-0" for="c-name">Connection name</label><input class="form-control form-control-sm" id="c-name" name="name" value="${esc(d.name || '')}" ${existing ? 'readonly' : ''}></div>
                        ${C.connectionFields(d, engines)}<div class="mt-2" id="conn-result"></div>`,
                    buttons: [
                        { label: `${icon('plug')} Test`, cls: 'btn-outline-primary', action: async ($el) => {
                            const r = await api('connection.test', { name: $el.find('[name=name]').val(), config: C.connectionFieldValues($el) });
                            $el.find('#conn-result').html(`<div class="alert alert-${r.ok ? 'success' : 'danger'} py-1 small mb-0">${esc(r.message)}</div>`);
                            return false;
                        } },
                        { label: `${icon('floppy-disk')} Save`, save: true, action: async ($el) => {
                            await api('connection.save', { name: $el.find('[name=name]').val(), config: C.connectionFieldValues($el) });
                            toast('Connection saved');
                            await shell.refreshAll();
                            GR.reload();
                        } },
                    ],
                });
                C.bindConnectionFields($d);
            };

            const $c = $content();
            $c.find('[data-add]').on('click', () => editor(null));
            $c.find('[data-edit]').on('click', function (e) { e.preventDefault(); editor(list.find((c) => c.name === this.dataset.edit)); });
            $c.find('[data-test]').on('click', async function (e) {
                e.preventDefault();
                try { const r = await api('connection.test', { name: this.dataset.test }); toast(r.message, r.ok ? 'success' : 'danger'); } catch (err) { fail(err); }
            });
            $c.find('[data-default]').on('click', async function (e) {
                e.preventDefault();
                try { await api('connection.default', { name: this.dataset.default }); toast('Default connection changed'); await shell.refreshAll(); GR.reload(); } catch (err) { fail(err); }
            });
            $c.find('[data-del]').on('click', async function (e) {
                e.preventDefault();
                if (!await confirmDialog(`Delete connection <b>${esc(this.dataset.del)}</b>?`, 'Delete')) return;
                try { await api('connection.delete', { name: this.dataset.del }); toast('Connection deleted'); await shell.refreshAll(); GR.reload(); } catch (err) { fail(err); }
            });
        },

        async admins() {
            const users = await api('users.list');
            const me = store.boot.user.id;
            $content().html(panel(`${icon('users')} Admin users`, `<table class="gr-table w-100"><thead><tr><th>#</th><th>Username</th><th>Created</th><th>Last login</th><th></th></tr></thead><tbody>
                ${users.map((u) => `<tr><td>${esc(u.id)}</td><td><b>${esc(u.username)}</b>${u.id === me ? ' ' + badge('you', 'primary') : ''}</td><td>${esc(u.created_at || '')}</td><td>${esc(u.last_login_at || '')}</td>
                    <td class="gr-actions-cell">${u.id === me
                        ? `<a href="#/settings/account">${icon('circle-user')} My account</a>`
                        : `<a href="#" data-edit-user="${esc(u.id)}">${icon('pen')} Edit</a><a href="#" class="text-danger" data-del="${esc(u.id)}">${icon('trash')} Delete</a>`}</td></tr>`).join('')}
                </tbody></table><p class="gr-help mb-0">Admin users can manage everything in this GUI. API consumers authenticate with API keys or JWTs instead.</p>`,
                `<button class="btn btn-sm btn-primary" data-add>${icon('user-plus')} Add user</button>`));

            const $c = $content();
            $c.find('[data-add]').on('click', () => drawer({
                title: `${icon('user-plus')} Add admin user`,
                body: `<div class="mb-2"><label class="form-label small mb-0" for="u-name">Username</label><input class="form-control form-control-sm" id="u-name" name="username" autocomplete="off"></div>
                    <div><label class="form-label small mb-0" for="u-pass">Password (min. 8)</label><input type="password" class="form-control form-control-sm" id="u-pass" name="password" autocomplete="new-password"></div>`,
                buttons: [{ label: `${icon('user-plus')} Create`, save: true, action: async ($el) => { await api('user.create', formValues($el.find('.gr-overlay-body'))); toast('User created'); GR.reload(); } }],
            }));
            $c.find('[data-edit-user]').on('click', function (e) {
                e.preventDefault();
                const user = users.find((u) => String(u.id) === this.dataset.editUser);
                drawer({
                    title: `${icon('pen')} Edit admin <b>${esc(user.username)}</b>`,
                    body: `<div class="mb-2"><label class="form-label small mb-0" for="u-name">Username</label><input class="form-control form-control-sm" id="u-name" name="username" value="${esc(user.username)}" autocomplete="off"></div>
                        <div><label class="form-label small mb-0" for="u-new">New password</label><input type="password" class="form-control form-control-sm" id="u-new" name="password" autocomplete="new-password" placeholder="leave empty to keep"></div>
                        <p class="gr-help mt-1 mb-0">Minimum 8 characters. The user is not logged out of open sessions.</p>`,
                    buttons: [{ label: `${icon('floppy-disk')} Save`, save: true, action: async ($el) => {
                        await api('user.update', { id: user.id, ...formValues($el.find('.gr-overlay-body')) });
                        toast('Admin user updated');
                        GR.reload();
                    } }],
                });
            });
            $c.find('[data-del]').on('click', async function (e) {
                e.preventDefault();
                if (!await confirmDialog('Delete this admin user?', 'Delete')) return;
                try { await api('user.delete', { id: Number(this.dataset.del) }); toast('User deleted'); GR.reload(); } catch (err) { fail(err); }
            });
        },

        io() {
            $content().html(`<div class="row g-3"><div class="col-lg-6">${panel(`${icon('file-export')} Export configuration`, `<p class="small">Downloads settings, connections (without passwords), GUI endpoints and buckets as JSON. Users, API keys and secrets are never exported.</p>
                <button class="btn btn-sm btn-primary" id="do-export">${icon('download')} Download JSON</button>`)}</div>
                <div class="col-lg-6">${panel(`${icon('file-import')} Import configuration`, `<p class="small">Imports endpoints, buckets and settings from an export file. Existing items with the same name are replaced.</p>
                <input type="file" class="form-control form-control-sm mb-2" id="import-file" accept="application/json,.json" aria-label="Configuration file"><button class="btn btn-sm btn-warning" id="do-import">${icon('upload')} Import</button>`)}</div></div>`);
            $('#do-export').on('click', async () => {
                try { download('grocery-restable-config.json', JSON.stringify(await api('config.export'), null, 2)); } catch (err) { fail(err); }
            });
            $('#do-import').on('click', async () => {
                const file = $('#import-file')[0].files[0];
                if (!file) { toast('Choose a file', 'warning'); return; }
                let data;
                try { data = JSON.parse(await file.text()); } catch (err) { fail({ message: 'Invalid JSON file' }); return; }
                if (!await confirmDialog('Import this configuration? Items with the same name are replaced.', 'Import', 'btn-warning')) return;
                try {
                    const r = await api('config.import', { data });
                    toast(`Imported ${r.resources} endpoints, ${r.buckets} buckets, ${r.settings} settings`);
                    await shell.refreshAll();
                } catch (err) { fail(err); }
            });
        },
    };

    // ---------------------------------------------------------------- GET /settings/:page?

    async function page(req, res) {
        const key = req.params.page;
        if (!key) return res.redirect('/settings/application');
        const entry = titleOf(key);
        if (!entry) return shell.middleware.notFound();
        shell.setBreadcrumb([{ label: 'Settings', icon: 'gear', href: '#/settings' }, { label: entry[1], icon: entry[2] }]);
        const settings = ['account', 'connections', 'admins', 'io'].includes(key) ? null : await api('settings.get');
        await PAGES[key](settings);
    }

    GR.pages = GR.pages || {};
    GR.pages.settings = { page };
})(window.GR = window.GR || {}, jQuery);
