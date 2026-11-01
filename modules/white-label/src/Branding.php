<?php

declare(strict_types=1);

namespace Perysee\Modules\WhiteLabel;

use App\Modules\ModuleSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Reads and writes the white label settings. The name, accent and logo are all optional:
 * any missing value falls back to the Perysee identity.
 */
class Branding
{
    public const MODULE = 'white-label';

    public const DEFAULT_NAME = 'Perysee';

    public const DEFAULT_ACCENT = '#5B21FF';

    public const DIRECTORY = 'modules/white-label';

    public function __construct(private ModuleSettings $settings) {}

    /**
     * What the whole interface displays, including the sign-in page.
     *
     * @return array{name: string|null, logo: string|null, accent: string|null}
     */
    public function forDisplay(): array
    {
        return [
            'name' => $this->settings->get(self::MODULE, 'name'),
            'logo' => $this->logoUrl(),
            'accent' => $this->settings->get(self::MODULE, 'accent'),
        ];
    }

    /**
     * What the settings form needs.
     *
     * @return array<string, mixed>
     */
    public function forAdministrator(): array
    {
        return [
            'name' => $this->settings->get(self::MODULE, 'name'),
            'accent' => $this->settings->get(self::MODULE, 'accent'),
            'logo_url' => $this->logoUrl(),
            'has_logo' => $this->logo() !== null,
            'defaults' => ['name' => self::DEFAULT_NAME, 'accent' => self::DEFAULT_ACCENT],
            'links' => [
                'save' => route('white-label.save'),
                'reset' => route('white-label.reset'),
                'logo' => route('white-label.logo.upload'),
            ],
        ];
    }

    public function saveText(?string $name, ?string $accent): void
    {
        foreach (['name' => $name, 'accent' => $accent] as $key => $value) {
            $value === null || $value === '' ? $this->settings->forget(self::MODULE, $key) : $this->settings->set(self::MODULE, $key, $value);
        }
    }

    public function saveLogo(string $file, string $mime): void
    {
        $this->settings->set(self::MODULE, 'logo', ['file' => $file, 'mime' => $mime, 'version' => time()]);
    }

    public function removeLogo(): void
    {
        Storage::disk('local')->deleteDirectory(self::DIRECTORY);
        $this->settings->forget(self::MODULE, 'logo');
    }

    public function reset(): void
    {
        $this->removeLogo();
        $this->settings->forget(self::MODULE, 'name');
        $this->settings->forget(self::MODULE, 'accent');
    }

    /**
     * @return array{file: string, mime: string, version: int}|null
     */
    public function logo(): ?array
    {
        $logo = $this->settings->get(self::MODULE, 'logo');

        return is_array($logo) && Storage::disk('local')->exists(self::DIRECTORY.'/'.($logo['file'] ?? '')) ? $logo : null;
    }

    private function logoUrl(): ?string
    {
        $logo = $this->logo();

        return $logo ? route('white-label.public.logo', absolute: false).'?v='.$logo['version'] : null;
    }
}
