<?php

declare(strict_types=1);

namespace GroceryRestable\Tests\Feature;

use GroceryRestable\Admin\SessionInterface;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\Response;
use GroceryRestable\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase
{
    private string $dir;
    private GroceryRestable $api;
    private SessionInterface $session;

    protected function setUp(): void
    {
        $this->dir = Fixture::directory();
        $this->api = GroceryRestable::create([
            'storagePath' => $this->dir . '/storage',
            'encryptionKey' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'adminUrl' => '/admin',
            'logger' => static fn (): null => null,
        ]);
        $this->session = new class () implements SessionInterface {
            /** @var array<string, mixed> */
            public array $data = [];

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }

            public function regenerate(): void
            {
            }
        };
    }

    protected function tearDown(): void
    {
        unset($this->api);
        gc_collect_cycles();
        Fixture::remove($this->dir);
    }

    private function request(string $method, string $path, array $body = [], array $headers = [], string $ip = '127.0.0.1', bool $form = false): Response
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        $raw = $form ? '' : json_encode($body);
        $headers += ['Content-Type' => $form ? 'application/x-www-form-urlencoded' : 'application/json'];

        return $this->api->handleAdmin(new Request($method, $path, $query, $headers, $raw, $form ? $body : [], [], $ip, 'http://localhost/admin'), $this->session);
    }

    private function runSetup(): array
    {
        $this->request('GET', 'setup');

        return $this->request('POST', 'setup', [
            'action' => 'finish',
            'framework' => 'standalone',
            'database' => ['driver' => 'sqlite', 'database' => Fixture::database($this->dir)],
            'account' => ['username' => 'admin', 'password' => 'Secret123!', 'password_confirm' => 'Secret123!'],
            'options' => ['auth_mode' => 'api_key', 'auto_expose' => true],
        ], ['X-CSRF-Token' => (string) $this->session->get('csrf')])->decodedJson();
    }

    private function login(): void
    {
        $this->request('GET', 'login');
        $response = $this->request('POST', 'login', ['_csrf' => $this->session->get('csrf'), 'username' => 'admin', 'password' => 'Secret123!'], [], '127.0.0.1', true);
        self::assertSame(302, $response->getStatus());
    }

    private function xhr(string $action, array $data = []): array
    {
        $response = $this->request('POST', 'xhr', ['action' => $action] + $data, ['X-CSRF-Token' => (string) $this->session->get('csrf')]);

        return [$response->getStatus(), $response->decodedJson()];
    }

    public function testSetupIsOnlyReachableFromTrustedIpsOrWithToken(): void
    {
        self::assertSame(403, $this->request('GET', 'setup', [], [], '203.0.113.9')->getStatus());
        self::assertSame(302, $this->request('GET', '')->getStatus(), 'Admin home redirects to setup');
        self::assertSame(200, $this->request('GET', 'setup')->getStatus());
    }

    public function testSetupOnlyOffersDetectedFrameworks(): void
    {
        $this->request('GET', 'setup');
        $response = $this->request('POST', 'setup', [
            'action' => 'finish',
            'framework' => 'codeigniter4',
            'database' => ['driver' => 'sqlite', 'database' => Fixture::database($this->dir)],
            'account' => ['username' => 'admin', 'password' => 'Secret123!', 'password_confirm' => 'Secret123!'],
        ], ['X-CSRF-Token' => (string) $this->session->get('csrf')]);

        self::assertSame(422, $response->getStatus());
        self::assertArrayHasKey('framework', $response->decodedJson()['error']['details']['fields']);
        self::assertSame(['standalone', 'codeigniter4'], array_keys($this->api->config()->frameworkChoices()));
        self::assertFalse($this->api->store()->isSetupCompleted());
    }

    public function testSetupCreatesKeysAndClosesItself(): void
    {
        $result = $this->runSetup();

        self::assertTrue($result['ok']);
        self::assertStringStartsWith('gr_', $result['keys']['anon']);
        self::assertTrue($this->api->store()->isSetupCompleted());
        self::assertSame(404, $this->request('GET', 'setup')->getStatus());

        $api = $this->api->handle(new Request('GET', 'employees', '', ['apikey' => $result['keys']['service_role']], '', [], [], '127.0.0.1', 'http://localhost/api'));
        self::assertSame(200, $api->getStatus());
    }

    public function testLoginCsrfAndThrottling(): void
    {
        $this->runSetup();
        $this->request('GET', 'login');
        self::assertSame(419, $this->request('POST', 'login', ['_csrf' => 'wrong', 'username' => 'admin', 'password' => 'Secret123!'], [], '127.0.0.1', true)->getStatus());

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->request('POST', 'login', ['_csrf' => $this->session->get('csrf'), 'username' => 'admin', 'password' => 'bad'], [], '10.0.0.1', true)->getStatus());
        }
        self::assertSame(429, $this->request('POST', 'login', ['_csrf' => $this->session->get('csrf'), 'username' => 'admin', 'password' => 'Secret123!'], [], '10.0.0.1', true)->getStatus());

        $this->login();
        self::assertSame(200, $this->request('GET', '')->getStatus());
    }

    public function testXhrRequiresSessionAndCsrf(): void
    {
        $this->runSetup();
        self::assertSame(401, $this->xhr('bootstrap')[0]);

        $this->login();
        self::assertSame(419, $this->request('POST', 'xhr', ['action' => 'bootstrap'], ['X-CSRF-Token' => 'nope'])->getStatus());
        [$status, $body] = $this->xhr('bootstrap');
        self::assertSame(200, $status);
        self::assertSame('admin', $body['data']['user']['username']);
        self::assertArrayNotHasKey('jwt_secret', $body['data']['settings'], 'Secrets are never sent to the GUI');
    }

    public function testResourceLifecycleFromTheGui(): void
    {
        $this->runSetup();
        $this->login();

        [, $generated] = $this->xhr('resource.generate', ['table' => 'employees']);
        $definition = $generated['data'];
        self::assertContains('offices', array_column($definition['relations'], 'name'));

        $definition['name'] = 'staff';
        $definition['columns'] = ['lastName', 'offices'];
        $definition['permissions'] = ['anon' => ['select']];
        [$status] = $this->xhr('resource.save', ['definition' => $definition, 'enabled' => true]);
        self::assertSame(200, $status);

        // anon may read "staff" but not the auto exposed "offices": the default embed is skipped.
        $response = $this->api->handle(new Request('GET', 'staff', 'limit=1', [], '', [], [], '127.0.0.1', 'http://localhost/api'));
        self::assertSame([['lastName' => 'Murphy']], $response->decodedJson());
        $key = $this->api->store()->createApiKey('service', 'service_role');
        $response = $this->api->handle(new Request('GET', 'staff', 'limit=1', ['apikey' => $key], '', [], [], '127.0.0.1', 'http://localhost/api'));
        self::assertSame('San Francisco', $response->decodedJson()[0]['offices']['city']);

        $definition['columns'] = ['nope'];
        [$status, $body] = $this->xhr('resource.save', ['definition' => $definition]);
        self::assertSame(422, $status);
        self::assertNotEmpty($body['error']['details']['problems']);

        [, $code] = $this->xhr('resource.code', ['definition' => $definition]);
        self::assertStringContainsString("\$api->resource('staff', 'employees')", $code['data']['code']);

        [$status, $console] = $this->xhr('console', ['method' => 'GET', 'path' => 'staff?select=lastName&limit=1', 'role' => 'anon']);
        self::assertSame(200, $console['data']['status']);
    }

    public function testBrowseAndRowEditing(): void
    {
        $this->runSetup();
        $this->login();

        [, $browse] = $this->xhr('table.browse', ['table' => 'offices', 'search' => 'paris']);
        self::assertSame(1, $browse['data']['total']);

        [$status] = $this->xhr('table.row.save', ['table' => 'offices', 'values' => ['officeCode' => '9', 'city' => 'Jakarta', 'country' => 'Indonesia']]);
        self::assertSame(200, $status);
        [, $saved] = $this->xhr('table.row.save', ['table' => 'offices', 'key' => ['officeCode' => '9'], 'values' => ['city' => 'Bandung']]);
        self::assertSame(1, $saved['data']['affected']);
        [$status] = $this->xhr('table.row.save', ['table' => 'offices', 'values' => ['bad column' => 1]]);
        self::assertSame(422, $status);
        [, $deleted] = $this->xhr('table.row.delete', ['table' => 'offices', 'key' => ['officeCode' => '9']]);
        self::assertSame(1, $deleted['data']['affected']);
    }

    public function testUserLoginSettingsAreValidated(): void
    {
        $this->runSetup();
        $this->login();

        [$status, $error] = $this->xhr('settings.save', ['settings' => ['auth_password_enabled' => true, 'auth_users_table' => 'nope']]);
        self::assertSame(422, $status);
        self::assertArrayHasKey('auth_users_table', $error['error']['details']['fields']);
        self::assertSame(422, $this->xhr('settings.save', ['settings' => ['auth_password_enabled' => true, 'auth_users_table' => 'employees', 'auth_users_id_column' => 'employeeNumber', 'auth_users_identity_columns' => 'email, login']])[0]);
        self::assertSame(422, $this->xhr('settings.save', ['settings' => ['auth_password_algorithm' => 'md5']])[0]);
        self::assertSame(422, $this->xhr('settings.save', ['settings' => ['auth_google_enabled' => true, 'auth_google_client_ids' => '']])[0]);

        [$status, $saved] = $this->xhr('settings.save', ['settings' => [
            'auth_password_enabled' => 'true', 'auth_users_table' => 'employees', 'auth_users_id_column' => 'employeeNumber',
            'auth_users_identity_columns' => 'email', 'auth_password_legacy' => ['md5', 'rot13'], 'auth_access_ttl' => '5',
            'auth_google_client_secret' => 'g-secret', 'auth_google_signup_fields' => 'lastName=family_name, firstName = given_name',
        ]]);
        self::assertSame(200, $status, json_encode($saved));
        $s = $saved['data'];
        self::assertSame([true, ['email'], ['md5'], 60], [$s['auth_password_enabled'], $s['auth_users_identity_columns'], $s['auth_password_legacy'], $s['auth_access_ttl']]);
        self::assertSame(['lastName' => 'family_name', 'firstName' => 'given_name'], $s['auth_google_signup_fields']);
        self::assertTrue($s['auth_google_client_secret_set']);
        self::assertArrayNotHasKey('auth_google_client_secret', $s);
        self::assertTrue($s['jwt_secret_set'], 'A JWT secret is generated for the jwt strategy');
        self::assertSame('g-secret', $this->api->store()->setting('auth_google_client_secret'));
        self::assertStringStartsWith('"enc:v1:', (string) $this->api->store()->pdo()->query("SELECT value FROM settings WHERE key = 'auth_google_client_secret'")->fetchColumn(), 'Encrypted at rest');

        // empty secret keeps the stored one; other settings pages are not blocked by the users table
        $this->xhr('settings.save', ['settings' => ['auth_google_client_secret' => '']]);
        self::assertSame('g-secret', $this->api->store()->setting('auth_google_client_secret'));
        self::assertSame(200, $this->xhr('settings.save', ['settings' => ['max_limit' => '40']])[0]);
        self::assertNotEmpty(array_filter($this->xhr('bootstrap')[1]['data']['warnings'] ?? [], static fn (string $w): bool => str_contains($w, 'User login')));
    }

    public function testSettingsKeysUsersAndExport(): void
    {
        $this->runSetup();
        $this->login();

        [, $settings] = $this->xhr('settings.save', ['settings' => ['jwt_secret' => str_repeat('x', 40), 'cors_origins' => 'https://a.test, https://b.test', 'max_limit' => '50']]);
        self::assertTrue($settings['data']['jwt_secret_set']);
        self::assertSame(['https://a.test', 'https://b.test'], $settings['data']['cors_origins']);
        self::assertSame(422, $this->xhr('settings.save', ['settings' => ['jwt_secret' => 'short']])[0]);

        [, $key] = $this->xhr('apikey.create', ['name' => 'CI', 'role' => 'editor']);
        self::assertStringStartsWith('gr_', $key['data']['key']);
        [, $token] = $this->xhr('jwt.generate', ['role' => 'editor', 'sub' => 'u1']);
        self::assertCount(3, explode('.', $token['data']['token']));

        self::assertSame(200, $this->xhr('user.create', ['username' => 'second', 'password' => 'Secret123!'])[0]);
        self::assertSame(422, $this->xhr('user.create', ['username' => 'x', 'password' => 'short'])[0]);

        [, $export] = $this->xhr('config.export');
        self::assertArrayNotHasKey('password', $export['data']['connections']['default']);
        self::assertArrayNotHasKey('jwt_secret', $export['data']['settings']);
    }

    public function testAccountUsersAndLogs(): void
    {
        $this->runSetup();
        $this->login();

        // My account: the current password is required.
        self::assertSame(422, $this->xhr('account.update', ['current_password' => 'wrong', 'username' => 'boss'])[0]);
        self::assertSame(422, $this->xhr('account.update', ['current_password' => 'Secret123!', 'password' => 'NewSecret1!', 'password_confirm' => 'typo'])[0]);
        [$status, $account] = $this->xhr('account.update', ['current_password' => 'Secret123!', 'username' => 'boss', 'password' => 'NewSecret1!', 'password_confirm' => 'NewSecret1!']);
        self::assertSame([200, 'boss'], [$status, $account['data']['username']]);
        self::assertNotNull($this->api->store()->verifyUser('boss', 'NewSecret1!'));

        // Other admins: rename / reset password, but not yourself through this action.
        [, $created] = $this->xhr('user.create', ['username' => 'second', 'password' => 'Secret123!']);
        self::assertSame(200, $this->xhr('user.update', ['id' => $created['data']['id'], 'username' => 'third', 'password' => 'Another12!'])[0]);
        self::assertNotNull($this->api->store()->verifyUser('third', 'Another12!'));
        self::assertSame(400, $this->xhr('user.update', ['id' => $this->api->store()->verifyUser('boss', 'NewSecret1!')['id'], 'password' => 'x12345678'])[0]);
        self::assertSame(422, $this->xhr('user.update', ['id' => $created['data']['id'], 'username' => 'boss'])[0], 'usernames are unique');

        // Admin actions are logged; secrets are not.
        $day = date('Y-m-d');
        [, $logs] = $this->xhr('logs.read', ['day' => $day, 'filters' => ['type' => 'admin']]);
        $actions = array_column($logs['data']['entries'], 'action');
        self::assertContains('login', $actions);
        self::assertContains('account.update', $actions);
        self::assertStringNotContainsString('NewSecret1!', (string) file_get_contents($this->api->logs()->path($day)));
        self::assertSame($day, $this->xhr('logs.days')[1]['data'][0]['day']);
        self::assertSame(200, $this->request('GET', 'log?day=' . $day)->getStatus());
        self::assertSame(400, $this->request('GET', 'log?day=../x')->getStatus());
        self::assertSame(1, $this->xhr('logs.delete', ['day' => $day])[1]['data']['deleted']);
    }

    public function testStorageCleanupActions(): void
    {
        $this->runSetup();
        $this->login();

        self::assertSame(200, $this->xhr('bucket.save', ['name' => 'old', 'definition' => ['allowedTypes' => 'txt']])[0]);
        $dir = $this->dir . '/storage/buckets/old/a';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/file.txt', 'x');

        [, $list] = $this->xhr('bucket.objects', ['bucket' => 'old', 'delimiter' => '']);
        self::assertSame(['a/file.txt'], array_column($list['data']['files'], 'path'));
        self::assertSame('a/file.txt', $this->xhr('bucket.object.info', ['bucket' => 'old', 'path' => 'a/file.txt'])[1]['data']['path']);

        // Deleting the bucket without purge leaves an orphan folder, which the scan reports.
        $this->xhr('bucket.delete', ['name' => 'old']);
        [, $scan] = $this->xhr('storage.scan');
        self::assertSame('old', $scan['data']['tasks']['orphan_buckets']['items'][0]['bucket']);
        [, $clean] = $this->xhr('storage.clean', ['tasks' => ['orphan_buckets', 'unknown']]);
        self::assertSame(['orphan_buckets' => 1], $clean['data']);
        self::assertDirectoryDoesNotExist($this->dir . '/storage/buckets/old');

        // With purge the files go straight to the trash.
        $this->xhr('bucket.save', ['name' => 'tmp', 'definition' => []]);
        file_put_contents($this->dir . '/storage/buckets/tmp/f.txt', 'x');
        self::assertTrue($this->xhr('bucket.delete', ['name' => 'tmp', 'purge' => true])[1]['data']['trashed']);
        self::assertDirectoryExists($this->dir . '/storage/trash/' . date('Y-m-d') . '/buckets/tmp');
    }

    public function testStaticAssetsAndOfflineVendorCopies(): void
    {
        $ok = [
            'assets/admin.js' => 'application/javascript',
            'assets/loader.js' => 'application/javascript',
            'assets/app/core.js' => 'application/javascript',
            'assets/app/pages/resource.js' => 'application/javascript',
            'assets/favicon.svg' => 'image/svg+xml',
            'assets/vendor/jquery/jquery.min.js' => 'application/javascript',
            'assets/vendor/bootstrap/bootstrap.min.css' => 'text/css',
            'assets/vendor/fontawesome/webfonts/fa-solid-900.woff2' => 'font/woff2',
            'assets/vendor/highlightjs/highlight.min.js' => 'application/javascript',
        ];
        foreach ($ok as $path => $type) {
            $response = $this->request('GET', $path);
            self::assertSame(200, $response->getStatus(), $path);
            self::assertStringStartsWith($type, (string) $response->getHeader('Content-Type'), $path);
        }
        self::assertStringContainsString('immutable', (string) $this->request('GET', 'assets/vendor/jquery/jquery.min.js')->getHeader('Cache-Control'));

        foreach (['assets/../AdminKernel.php', 'assets/views/app.php', 'assets/vendor/../../View.php', 'assets/.hidden.js', 'assets/nope.js'] as $path) {
            self::assertSame(404, $this->request('GET', $path)->getStatus(), $path);
        }
    }

    public function testVendorCopiesMatchTheCdnIntegrityHashes(): void
    {
        $local = [
            'bootstrap_css' => 'bootstrap/bootstrap.min.css',
            'bootstrap_js' => 'bootstrap/bootstrap.bundle.min.js',
            'jquery' => 'jquery/jquery.min.js',
            'fontawesome' => 'fontawesome/css/all.min.css',
            'highlightjs' => 'highlightjs/highlight.min.js',
            'highlightjs_css' => 'highlightjs/github-dark.min.css',
        ];
        foreach ($local as $key => $file) {
            $hash = 'sha384-' . base64_encode(hash_file('sha384', dirname(__DIR__, 2) . '/src/Admin/assets/vendor/' . $file, true));
            self::assertSame(\GroceryRestable\Admin\View::CDN[$key]['sri'], $hash, $file . ' differs from the CDN version');
        }
    }

    public function testSecretsAreEncryptedAtRest(): void
    {
        $this->runSetup();
        $this->api->store()->saveConnection('other', ['driver' => 'mysql', 'database' => 'x', 'password' => 'super-secret']);
        $raw = (string) file_get_contents($this->dir . '/storage/grocery-restable.sqlite');

        self::assertStringNotContainsString('super-secret', $raw);
        self::assertSame('super-secret', $this->api->store()->connection('other')['password']);
    }
}
