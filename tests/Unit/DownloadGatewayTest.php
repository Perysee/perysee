<?php

namespace Tests\Unit;

use PeryseeDownloads\Gateway;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../infra/downloads/Gateway.php';

class DownloadGatewayTest extends TestCase
{
    private string $directory;

    private array $calls = [];

    private array $files = [];

    private bool $offline = false;

    private string $redirect = 'https://release-assets.githubusercontent.com/signed-file';

    private string $version = 'v1.2.3';

    private bool $draft = false;

    private bool $prerelease = false;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/perysee-gateway-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->files = ['install.sh' => "#!/usr/bin/env bash\necho installer\n", 'install.php' => '<?php echo "download only";', 'perysee.zip' => 'ZIP_CONTENT'];
        $this->files['SHA256SUMS'] = implode('', array_map(fn ($name) => hash('sha256', $this->files[$name]).'  '.$name."\n", array_keys($this->files)));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    private function gateway(array $overrides = []): Gateway
    {
        return new Gateway(array_replace(['origin' => 'https://get.perysee.com', 'repository' => 'Perysee/perysee', 'token' => 'private-test-token', 'cache_path' => $this->directory.'/cache'], $overrides), function (string $url, array $headers, string $destination, int $limit): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers];
            if ($this->offline) {
                throw new \RuntimeException('private-test-token: upstream failed');
            }
            if (str_contains($url, '/releases/latest') || str_contains($url, '/releases/tags/')) {
                $body = json_encode(['tag_name' => $this->version, 'draft' => $this->draft, 'prerelease' => $this->prerelease, 'assets' => array_map(fn ($name, $id) => ['name' => $name, 'id' => $id + 1], array_keys($this->files), array_keys(array_keys($this->files)))]);
            } elseif (str_ends_with($url, '/assets/3')) {
                file_put_contents($destination, '');

                return ['status' => 302, 'location' => $this->redirect];
            } elseif ($url === $this->redirect) {
                $body = $this->files['perysee.zip'];
            } else {
                $id = (int) basename($url);
                $body = $this->files[array_keys($this->files)[$id - 1]];
            }
            file_put_contents($destination, $body);

            return ['status' => 200, 'location' => null];
        });
    }

    private function body(array $response): string
    {
        return $response['file'] ? file_get_contents($response['file']) : $response['body'];
    }

    public function test_bootstrap_pins_the_release_and_keeps_credentials_private(): void
    {
        $response = $this->gateway()->handle('GET', '/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString("PERYSEE_DOWNLOAD_URL='https://get.perysee.com/releases/v1.2.3'", $this->body($response));
        $this->assertStringEndsWith($this->files['install.sh'], $this->body($response));
        $this->assertStringNotContainsString('private-test-token', $this->body($response));
        $this->assertSame('Bearer private-test-token', $this->calls[0]['headers']['Authorization']);
    }

    public function test_versioned_assets_are_files_and_redirects_never_receive_the_token(): void
    {
        $response = $this->gateway()->handle('GET', '/releases/v1.2.3/perysee.zip');
        $this->assertSame(200, $response['status']);
        $this->assertSame('ZIP_CONTENT', $this->body($response));
        $this->assertSame('', $response['body']);
        $this->assertStringContainsString('immutable', $response['headers']['Cache-Control']);
        $this->assertSame('11', $response['headers']['Content-Length']);
        $redirected = array_values(array_filter($this->calls, fn ($call) => $call['url'] === $this->redirect));
        $this->assertArrayNotHasKey('Authorization', $redirected[0]['headers']);
        $php = $this->gateway()->handle('GET', '/releases/v1.2.3/install.php');
        $this->assertSame('application/octet-stream', $php['headers']['Content-Type']);
        $this->assertSame($this->files['install.php'], $this->body($php));
    }

    public function test_aliases_pin_a_stable_version_and_head_does_not_send_a_body(): void
    {
        $gateway = $this->gateway();
        $response = $gateway->handle('HEAD', '/perysee.zip');
        $this->assertSame(302, $response['status']);
        $this->assertSame('https://get.perysee.com/releases/v1.2.3/perysee.zip', $response['headers']['Location']);
        $this->assertSame('', $response['body']);
        $gateway->handle('GET', '/');
        $count = count($this->calls);
        foreach (['/', '/releases/v1.2.3/perysee.zip'] as $path) {
            $response = $gateway->handle('HEAD', $path);
            $this->assertSame(200, $response['status']);
            $this->assertSame('', $response['body']);
            $this->assertNull($response['file']);
            $this->assertArrayHasKey('Content-Length', $response['headers']);
        }
        $this->assertCount($count, $this->calls);
    }

    public function test_only_allowlisted_routes_and_methods_can_contact_github(): void
    {
        $gateway = $this->gateway();
        foreach (['/.env', '/config.php', '/Gateway.php', '/releases/v1.2.3/source.zip', '/releases/v1.2.3/../README.md', '/releases/v1.2.3%2f../perysee.zip', '/?token=secret', '/install.sh?x=1', '/releases/v1.2.3/perysee.zip/extra'] as $path) {
            $this->assertSame(404, $gateway->handle('GET', $path)['status'], $path);
        }
        $response = $gateway->handle('POST', '/');
        $this->assertSame(405, $response['status']);
        $this->assertSame('GET, HEAD', $response['headers']['Allow']);
        $this->assertSame([], $this->calls);
    }

    public function test_failed_checksums_never_publish_a_partial_cache_and_can_be_retried(): void
    {
        $good = $this->files['perysee.zip'];
        $this->files['perysee.zip'] = 'corrupted ZIP';
        $gateway = $this->gateway();
        $this->assertSame(503, $gateway->handle('GET', '/')['status']);
        $this->assertDirectoryDoesNotExist($this->directory.'/cache/releases/v1.2.3');
        $this->assertSame([], glob($this->directory.'/cache/.release-*'));
        $this->files['perysee.zip'] = $good;
        $this->assertSame(200, $gateway->handle('GET', '/')['status']);
    }

    public function test_unexpected_redirects_are_rejected_before_contacting_the_host(): void
    {
        $this->redirect = 'https://untrusted.example/secret';
        $response = $this->gateway()->handle('GET', '/');
        $this->assertSame(503, $response['status']);
        $this->assertSame([], array_filter($this->calls, fn ($call) => str_contains($call['url'], 'untrusted.example')));
    }

    public function test_upstream_errors_are_generic_and_cached_releases_remain_available(): void
    {
        $gateway = $this->gateway();
        $this->offline = true;
        $response = $gateway->handle('GET', '/');
        $this->assertSame(503, $response['status']);
        $this->assertSame('60', $response['headers']['Retry-After']);
        $this->assertStringNotContainsString('private-test-token', $response['body']);
        $this->offline = false;
        $this->assertSame(200, $gateway->handle('GET', '/')['status']);
        file_put_contents($this->directory.'/cache/latest.json', json_encode(['version' => 'v1.2.3', 'at' => time() - 120]));
        $this->offline = true;
        $this->assertSame(200, $gateway->handle('GET', '/')['status']);
        $this->assertSame('ZIP_CONTENT', $this->body($gateway->handle('GET', '/releases/v1.2.3/perysee.zip')));
    }

    public function test_drafts_and_prereleases_are_never_selected_as_latest(): void
    {
        $gateway = $this->gateway();
        $this->draft = true;
        $this->assertSame(503, $gateway->handle('GET', '/')['status']);
        $this->draft = false;
        $this->prerelease = true;
        $this->version = 'v1.2.3-beta.1';
        $this->assertSame(503, $gateway->handle('GET', '/')['status']);
        $this->assertSame(200, $gateway->handle('GET', '/releases/v1.2.3-beta.1/perysee.zip')['status']);
    }

    public function test_an_inflight_release_returns_retry_instead_of_starting_a_second_download(): void
    {
        $gateway = $this->gateway();
        $lock = fopen($this->directory.'/cache/releases.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $response = $gateway->handle('GET', '/releases/v1.2.3/perysee.zip');
            $this->assertSame(503, $response['status']);
            $this->assertSame([], $this->calls);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame(200, $gateway->handle('GET', '/releases/v1.2.3/perysee.zip')['status']);
    }

    public function test_a_new_stable_does_not_change_an_installation_already_pinned(): void
    {
        $gateway = $this->gateway();
        $first = $gateway->handle('GET', '/');
        $this->version = 'v1.2.4';
        file_put_contents($this->directory.'/cache/latest.json', json_encode(['version' => 'v1.2.3', 'at' => time() - 120]));
        $second = $gateway->handle('GET', '/');
        $this->assertStringContainsString('/releases/v1.2.3', $this->body($first));
        $this->assertStringContainsString('/releases/v1.2.4', $this->body($second));
        $this->assertSame(200, $gateway->handle('GET', '/releases/v1.2.3/perysee.zip')['status']);
    }

    public function test_cache_inside_the_public_root_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->gateway(['public_root' => $this->directory]);
    }

    public function test_a_broken_new_release_keeps_the_previous_verified_stable_available(): void
    {
        $gateway = $this->gateway();
        $this->assertSame(200, $gateway->handle('GET', '/')['status']);
        file_put_contents($this->directory.'/cache/latest.json', json_encode(['version' => 'v1.2.3', 'at' => time() - 120]));
        $this->version = 'v1.2.4';
        $this->files['perysee.zip'] = 'invalid';
        $response = $gateway->handle('GET', '/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('/releases/v1.2.3', $this->body($response));
        $this->assertDirectoryDoesNotExist($this->directory.'/cache/releases/v1.2.4');
    }

    public function test_a_killed_download_is_cleaned_before_retrying_the_same_version(): void
    {
        $gateway = $this->gateway();
        mkdir($this->directory.'/cache/.release-v1.2.3');
        file_put_contents($this->directory.'/cache/.release-v1.2.3/perysee.zip', 'unfinished');
        $this->assertSame(200, $gateway->handle('GET', '/releases/v1.2.3/perysee.zip')['status']);
        $this->assertDirectoryDoesNotExist($this->directory.'/cache/.release-v1.2.3');
    }
}
