<?php

namespace Tests\Unit;

use App\Modules\InvalidManifest;
use App\Modules\Manifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModuleManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'pdf-export',
            'name' => 'PDF export',
            'version' => '1.2.0',
            'core' => '0.1.0',
            'requires' => ['notifications'],
            'entitlement' => 'pdf-export',
            'description' => 'Export reports as PDF.',
        ];
    }

    public function test_a_valid_manifest_exposes_its_fields(): void
    {
        $manifest = Manifest::fromArray($this->valid());

        $this->assertSame('pdf-export', $manifest->id);
        $this->assertSame('PDF export', $manifest->name);
        $this->assertSame('1.2.0', $manifest->version);
        $this->assertSame(['notifications'], $manifest->requires);
        $this->assertSame('pdf-export', $manifest->entitlement);
        $this->assertSame('PdfExport', $manifest->namespaceSegment());
    }

    public function test_requires_and_entitlement_are_optional(): void
    {
        $data = $this->valid();
        unset($data['requires'], $data['entitlement'], $data['description']);

        $manifest = Manifest::fromArray($data);

        $this->assertSame([], $manifest->requires);
        $this->assertSame('pdf-export', $manifest->entitlement);
    }

    #[DataProvider('missingFields')]
    public function test_missing_required_fields_are_rejected(string $field): void
    {
        $data = $this->valid();
        unset($data[$field]);

        $this->expectException(InvalidManifest::class);
        Manifest::fromArray($data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingFields(): array
    {
        return ['id' => ['id'], 'name' => ['name'], 'version' => ['version'], 'core' => ['core']];
    }

    #[DataProvider('dangerousIds')]
    public function test_unsafe_identifiers_are_rejected(string $id): void
    {
        $this->expectException(InvalidManifest::class);
        Manifest::fromArray($this->valid(['id' => $id]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousIds(): array
    {
        return [
            'traversal' => ['../etc'],
            'slash' => ['a/b'],
            'uppercase' => ['Pdf'],
            'leading dash' => ['-pdf'],
            'empty' => [''],
            'too long' => [str_repeat('a', 41)],
        ];
    }

    public function test_malformed_versions_are_rejected(): void
    {
        $this->expectException(InvalidManifest::class);
        Manifest::fromArray($this->valid(['version' => 'latest']));
    }

    public function test_compatibility_requires_core_at_least_the_declared_version(): void
    {
        $manifest = Manifest::fromArray($this->valid(['core' => '0.2.0']));

        $this->assertFalse($manifest->isCompatibleWith('0.1.1'));
        $this->assertTrue($manifest->isCompatibleWith('0.2.0'));
        $this->assertTrue($manifest->isCompatibleWith('1.0.0'));
    }

    public function test_it_reads_a_manifest_file_and_rejects_invalid_json(): void
    {
        $directory = sys_get_temp_dir().'/perysee-manifest-'.bin2hex(random_bytes(4));
        mkdir($directory);
        try {
            file_put_contents($directory.'/module.json', json_encode($this->valid()));
            $this->assertSame('pdf-export', Manifest::fromFile($directory.'/module.json')->id);

            file_put_contents($directory.'/module.json', '{broken');
            $this->expectException(InvalidManifest::class);
            Manifest::fromFile($directory.'/module.json');
        } finally {
            @unlink($directory.'/module.json');
            @rmdir($directory);
        }
    }
}
