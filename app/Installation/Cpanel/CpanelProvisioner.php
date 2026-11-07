<?php

declare(strict_types=1);

namespace App\Installation\Cpanel;

use Illuminate\Support\Str;

/**
 * Prepares a cPanel account for Perysee through UAPI: a hostname whose document root is the application's public/
 * directory, a dedicated database and user, a recent web PHP, and the scheduler cron. It creates, never overwrites:
 * a hostname that already exists elsewhere is refused, and a failed run removes only what it created itself.
 */
final class CpanelProvisioner
{
    private const MINIMUM_PHP = 83;

    public function __construct(private readonly UapiClient $uapi) {}

    public function provision(string $domain, string $target): Provisioned
    {
        $domain = strtolower(trim($domain));
        $target = rtrim($target, '/');
        $information = (array) $this->uapi->call('Variables', 'get_user_information');
        $home = rtrim((string) ($information['home'] ?? ''), '/');
        if ($home === '' || ! str_starts_with($target, $home.'/')) {
            throw new ProvisioningException("The application must be installed inside your home directory ({$home}) so that cPanel can serve its public/ folder.");
        }
        $documentRoot = $target.'/public';
        $subdomain = $this->planSubdomain($domain, (array) $this->uapi->call('DomainInfo', 'domains_data'), $documentRoot);
        [$prefix, $maximumDatabase, $maximumUser] = $this->restrictions((string) ($information['user'] ?? 'cpanel'));
        $remaining = $maximumUser - strlen($prefix);
        if ($remaining < 4) {
            throw new ProvisioningException('This account\'s database user names are too short to add a Perysee user. Create the database by hand and use --mode manual.');
        }
        [$phpVersion, $warning] = $this->newestPhp();
        $resources = new Provisioned(
            domain: $domain,
            databaseName: substr($prefix.'perysee_'.bin2hex(random_bytes(2)), 0, $maximumDatabase),
            databaseUser: $prefix.substr('p'.bin2hex(random_bytes(4)), 0, $remaining),
            databasePassword: Str::random(32),
            phpCli: $phpVersion !== null && preg_match('/^ea-php\d+$/', $phpVersion) === 1 ? "/opt/cpanel/{$phpVersion}/root/usr/bin/php" : PHP_BINARY,
        );
        if ($warning !== null) {
            $resources->warnings[] = $warning;
        }
        try {
            if ($subdomain !== null) {
                $this->uapi->call('SubDomain', 'addsubdomain', ['domain' => $subdomain[0], 'rootdomain' => $subdomain[1], 'dir' => substr($documentRoot, strlen($home) + 1), 'disallowdot' => '1']);
                $resources->subdomain = $domain;
            }
            if ($phpVersion !== null) {
                $this->selectWebPhp($resources, $phpVersion);
            }
            $this->uapi->call('Mysql', 'create_database', ['name' => $resources->databaseName]);
            $resources->databaseCreated = true;
            $this->uapi->call('Mysql', 'create_user', ['name' => $resources->databaseUser, 'password' => $resources->databasePassword], secret: true);
            $resources->userCreated = true;
            $this->uapi->call('Mysql', 'set_privileges_on_database', ['user' => $resources->databaseUser, 'database' => $resources->databaseName, 'privileges' => 'ALL PRIVILEGES']);
        } catch (ProvisioningException $exception) {
            $this->rollback($resources);
            throw $exception;
        }

        return $resources;
    }

    /**
     * Run the scheduler every minute. A refusal is not fatal (the cron can be added by hand) so it is returned.
     *
     * @return list<string> warnings
     */
    public function schedule(Provisioned $resources, string $target): array
    {
        $command = 'cd '.escapeshellarg(rtrim($target, '/')).' && '.escapeshellarg($resources->phpCli).' artisan schedule:run >> storage/logs/cron.log 2>&1';
        try {
            $this->uapi->call('Cron', 'add_line', ['command' => $command, 'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*']);
        } catch (ProvisioningException $exception) {
            return ["The cron job could not be added ({$exception->getMessage()}). Add this line in cPanel > Cron Jobs yourself: * * * * * {$command}"];
        }

        return [];
    }

    /** Remove only the resources this run created. Best effort: a cleanup step that fails does not hide the others. */
    public function rollback(Provisioned $resources): void
    {
        $steps = [];
        if ($resources->subdomain !== null) {
            $steps[] = ['SubDomain', 'delsubdomain', ['domain' => $resources->subdomain]];
        }
        if ($resources->userCreated) {
            $steps[] = ['Mysql', 'delete_user', ['name' => $resources->databaseUser]];
        }
        if ($resources->databaseCreated) {
            $steps[] = ['Mysql', 'delete_database', ['name' => $resources->databaseName]];
        }
        foreach ($steps as [$module, $function, $arguments]) {
            try {
                $this->uapi->call($module, $function, $arguments);
            } catch (ProvisioningException) {
                // Reported by the caller as the original failure; a leftover empty resource is removable in cPanel.
            }
        }
    }

    /** @return array{0: string, 1: string}|null the label and root domain to create, or null when the hostname is already served from the application */
    private function planSubdomain(string $domain, array $data, string $documentRoot): ?array
    {
        $existing = [];
        $roots = [];
        foreach (['main_domain', 'addon_domains', 'parked_domains', 'sub_domains'] as $kind) {
            foreach ($this->entries($data[$kind] ?? []) as $entry) {
                $existing[$entry['domain']] = $entry['documentroot'];
                if ($kind !== 'sub_domains') {
                    $roots[] = $entry['domain'];
                }
            }
        }
        if (array_key_exists($domain, $existing)) {
            if (rtrim((string) $existing[$domain], '/') === $documentRoot) {
                return null;
            }
            throw new ProvisioningException("{$domain} already exists in this account (document root: ".($existing[$domain] ?? 'unknown').'). Perysee never takes over an existing site: choose a free subdomain.');
        }
        usort($roots, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($roots as $root) {
            $label = substr($domain, 0, -strlen($root) - 1);
            if (str_ends_with($domain, '.'.$root) && $label !== '' && ! str_contains($label, '.')) {
                return [$label, $root];
            }
        }
        throw new ProvisioningException("{$domain} is not a one-level subdomain of a domain in this account. Use a subdomain of one of: ".implode(', ', array_unique($roots)).'. Other domains must be added in cPanel first.');
    }

    /** @return list<array{domain: string, documentroot: ?string}> */
    private function entries(mixed $value): array
    {
        if (is_string($value)) {
            return [['domain' => strtolower($value), 'documentroot' => null]];
        }
        if (! is_array($value)) {
            return [];
        }
        if (isset($value['domain'])) {
            return [['domain' => strtolower((string) $value['domain']), 'documentroot' => isset($value['documentroot']) ? (string) $value['documentroot'] : null]];
        }

        return array_merge(...array_map(fn (mixed $item): array => $this->entries($item), array_values($value)) ?: [[]]);
    }

    /** @return array{0: string, 1: int, 2: int} the account's database name prefix and its two length limits */
    private function restrictions(string $user): array
    {
        try {
            $data = (array) $this->uapi->call('Mysql', 'get_restrictions');
        } catch (ProvisioningException) {
            $data = [];
        }

        return [(string) ($data['prefix'] ?? $user.'_'), (int) ($data['max_database_name_length'] ?? 63), (int) ($data['max_username_length'] ?? 16)];
    }

    /** @return array{0: ?string, 1: ?string} the newest MultiPHP version that qualifies, or why none was chosen */
    private function newestPhp(): array
    {
        try {
            $versions = (array) $this->uapi->call('LangPHP', 'php_get_installed_versions');
        } catch (ProvisioningException $exception) {
            return [null, "The installed PHP versions could not be read ({$exception->getMessage()}). Select PHP 8.3 or later for the domain in cPanel."];
        }
        $best = null;
        $bestNumber = 0;
        foreach ($versions as $version) {
            if (is_string($version) && preg_match('/^ea-php(\d)(\d+)$/', $version, $match) === 1 && (int) ($match[1].$match[2]) > $bestNumber) {
                $best = $version;
                $bestNumber = (int) ($match[1].$match[2]);
            }
        }
        if ($bestNumber < self::MINIMUM_PHP) {
            return [null, 'No PHP 8.3 or later is installed for this account. Enable one in cPanel (MultiPHP Manager or Select PHP Version) before opening the site.'];
        }

        return [$best, null];
    }

    private function selectWebPhp(Provisioned $resources, string $version): void
    {
        try {
            $this->uapi->call('LangPHP', 'php_set_vhost_versions', ['version' => $version, 'vhost' => $resources->domain]);
        } catch (ProvisioningException $exception) {
            $resources->warnings[] = "The web PHP of {$resources->domain} could not be set to {$version} ({$exception->getMessage()}). Select PHP 8.3 or later for it in cPanel.";
        }
    }
}
