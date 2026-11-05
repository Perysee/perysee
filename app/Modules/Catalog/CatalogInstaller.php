<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\InvalidModuleBundle;
use App\Modules\Manifest;
use App\Modules\ModuleApi;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRegistry;
use App\Updates\PackageVerifier;
use App\Updates\UpdateException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Installs or updates a free official module from the catalog. Nothing is written to the modules folder until the
 * entry's signature, the archive's SHA-256 and the archive's own manifest all agree with each other; the module is
 * then installed exactly as an uploaded archive is, disabled, with the same checks on the archive's content.
 */
final class CatalogInstaller
{
    public function __construct(
        private readonly ModuleCatalog $catalog,
        private readonly CatalogSignature $signature,
        private readonly ModuleInstaller $installer,
        private readonly ModuleRegistry $registry,
        private readonly PackageVerifier $verifier,
    ) {}

    public function install(string $slug): Manifest
    {
        $entry = $this->catalog->entry($slug) ?? throw new CatalogException("Module {$slug} is not in the catalog.");
        if (($reason = $this->refusal($entry)) !== null) {
            throw new CatalogException($reason);
        }

        $file = tempnam(sys_get_temp_dir(), 'perysee-module-');
        if ($file === false) {
            throw new CatalogException('A temporary file could not be created.');
        }
        try {
            file_put_contents($file, $this->download($entry));
            try {
                $this->verifier->assertMatches($file, (string) $entry->sha256);
            } catch (UpdateException $exception) {
                throw new CatalogException($exception->getMessage(), 0, $exception);
            }
            try {
                $found = $this->installer->inspect($file);
                if ($found->id !== $entry->slug || $found->version !== $entry->version) {
                    throw new CatalogException("The archive holds {$found->id} {$found->version}, which does not match the catalog entry {$entry->slug} {$entry->version}.");
                }
                $manifest = isset($this->registry->all()[$slug]) ? $this->installer->update($file, $slug) : $this->installer->install($file);
            } catch (InvalidModuleBundle $exception) {
                throw new CatalogException($exception->getMessage(), 0, $exception);
            }
        } finally {
            @unlink($file);
        }
        $this->catalog->recordSource($entry);
        Log::info('A module was installed from the catalog.', ['module' => $entry->slug, 'version' => $entry->version, 'key' => $entry->keyId]);

        return $manifest;
    }

    /**
     * Why this entry cannot be installed here, or null when it can. The signature is checked here too, so an entry
     * that is listed as available is one that will verify.
     */
    public function refusal(CatalogEntry $entry): ?string
    {
        if ($entry->requiredPlan !== 'free') {
            return "Module {$entry->slug} belongs to a paid subscription and is not distributed through the catalog yet.";
        }
        if (! $entry->isDownloadable()) {
            return "Module {$entry->slug} has no published archive yet.";
        }
        if ($entry->api !== ModuleApi::VERSION) {
            return "Module {$entry->slug} was written for module API {$entry->api}, but this Perysee supports module API ".ModuleApi::VERSION.'.';
        }
        if (version_compare((string) config('perysee.version'), $entry->core, '<')) {
            return "Module {$entry->slug} requires Perysee {$entry->core} or later.";
        }
        try {
            $this->signature->verify($entry);
        } catch (CatalogException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function download(CatalogEntry $entry): string
    {
        $limit = (int) config('perysee.module_upload_max_kb') * 1024;
        try {
            $response = Http::withHeaders(['User-Agent' => 'Perysee/'.config('perysee.version')])->timeout(120)->connectTimeout(10)->get((string) $entry->downloadUrl);
        } catch (ConnectionException) {
            throw new CatalogException("The archive of {$entry->slug} cannot be downloaded from this server.");
        }
        if (! $response->successful()) {
            throw new CatalogException("The archive of {$entry->slug} could not be downloaded (HTTP {$response->status()}).");
        }
        $body = $response->body();
        if (strlen($body) > $limit) {
            throw new CatalogException("The archive of {$entry->slug} is too large (limit ".intdiv($limit, 1048576).' MB).');
        }

        return $body;
    }
}
