<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\CatalogEntry;
use App\Modules\Catalog\CatalogException;
use App\Modules\Catalog\CatalogSignature;
use PHPUnit\Framework\TestCase;

class CatalogSignatureTest extends TestCase
{
    private string $secret;

    private string $public;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = sodium_crypto_sign_publickey($pair);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return $overrides + [
            'slug' => 'example',
            'name' => 'Example',
            'description' => 'A minimal module.',
            'version' => '1.2.0',
            'requiredPlan' => 'free',
            'stability' => 'beta',
            'tier' => 'official',
            'api' => 1,
            'core' => '0.1.1',
            'downloadUrl' => 'https://github.com/Perysee/app/releases/download/v0.2.0/example-1.2.0.zip',
            'sha256' => str_repeat('ab', 32),
            'keyId' => 'k1',
        ];
    }

    private function signed(array $overrides = []): array
    {
        $data = $this->data($overrides);
        $signature = sodium_crypto_sign_detached(CatalogEntry::canonicalPayload($data), $this->secret);

        return $data + ['signature' => base64_encode($signature)];
    }

    private function verifier(array $keys = []): CatalogSignature
    {
        return new CatalogSignature($keys ?: ['k1' => base64_encode($this->public)]);
    }

    public function test_the_payload_is_canonical_whatever_the_order_of_the_fields(): void
    {
        $reversed = array_reverse($this->data(), true);

        $this->assertSame(CatalogEntry::canonicalPayload($this->data()), CatalogEntry::canonicalPayload($reversed));
        $this->assertStringStartsWith('{"api":1,"core":"0.1.1","downloadUrl":"https://github.com/', CatalogEntry::canonicalPayload($this->data()));
        $this->assertStringNotContainsString('name', CatalogEntry::canonicalPayload($this->data()), 'Display text is not part of what is signed.');
    }

    public function test_a_correctly_signed_entry_verifies(): void
    {
        $entry = CatalogEntry::fromArray($this->signed());

        $this->verifier()->verify($entry);

        $this->assertTrue($entry->isDownloadable());
    }

    public function test_changing_any_signed_field_breaks_the_signature(): void
    {
        $signed = $this->signed();

        foreach (['version' => '9.9.9', 'sha256' => str_repeat('cd', 32), 'downloadUrl' => 'https://example.com/evil.zip', 'api' => 2, 'core' => '0.0.1', 'stability' => 'stable', 'slug' => 'other'] as $field => $value) {
            $entry = CatalogEntry::fromArray([$field => $value] + $signed);
            try {
                $this->verifier()->verify($entry);
                $this->fail("Changing {$field} must invalidate the signature.");
            } catch (CatalogException $exception) {
                $this->assertStringContainsString('signature', $exception->getMessage());
            }
        }
    }

    public function test_a_signature_made_with_another_key_or_an_unknown_key_id_is_refused(): void
    {
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $entry = CatalogEntry::fromArray($this->signed());

        $this->expectException(CatalogException::class);
        $this->verifier(['k1' => base64_encode($other)])->verify($entry);
    }

    public function test_an_unknown_key_id_names_the_key(): void
    {
        $entry = CatalogEntry::fromArray($this->signed(['keyId' => 'rotated']));

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('rotated');
        $this->verifier()->verify($entry);
    }

    public function test_either_of_several_trusted_keys_can_verify_so_a_key_can_be_rotated(): void
    {
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $verifier = $this->verifier(['old' => base64_encode($other), 'k1' => base64_encode($this->public)]);

        $verifier->verify(CatalogEntry::fromArray($this->signed()));

        $this->addToAssertionCount(1);
    }

    public function test_malformed_keys_and_signatures_are_refused_without_a_warning(): void
    {
        $entry = CatalogEntry::fromArray($this->signed());
        foreach ([['k1' => 'not base64!!'], ['k1' => base64_encode('short')]] as $keys) {
            try {
                (new CatalogSignature($keys))->verify($entry);
                $this->fail('A malformed key must be refused.');
            } catch (CatalogException) {
                $this->addToAssertionCount(1);
            }
        }
        $broken = CatalogEntry::fromArray(['signature' => base64_encode('too short')] + $this->signed());
        $this->expectException(CatalogException::class);
        $this->verifier()->verify($broken);
    }

    public function test_an_entry_without_a_signature_is_listed_but_not_downloadable(): void
    {
        $data = $this->data();
        $entry = CatalogEntry::fromArray($data);

        $this->assertFalse($entry->isDownloadable());
        $this->expectException(CatalogException::class);
        $this->verifier()->verify($entry);
    }

    public function test_a_paid_module_is_never_downloadable_even_with_a_valid_signature(): void
    {
        $entry = CatalogEntry::fromArray($this->signed(['requiredPlan' => 'pro']));

        $this->assertFalse($entry->isDownloadable());
    }

    public function test_unsafe_values_are_rejected_when_the_entry_is_read(): void
    {
        foreach ([
            ['slug' => '../etc'],
            ['version' => 'latest'],
            ['downloadUrl' => 'http://example.com/a.zip'],
            ['downloadUrl' => 'https://example.com/a b.zip'],
            ['sha256' => 'xyz'],
            ['api' => '1'],
            ['tier' => 'community'],
            ['stability' => 'rock-solid'],
        ] as $override) {
            try {
                CatalogEntry::fromArray($this->signed($override));
                $this->fail('Expected '.json_encode($override).' to be refused.');
            } catch (CatalogException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
