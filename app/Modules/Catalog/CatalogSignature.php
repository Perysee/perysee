<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

/**
 * Checks the Ed25519 signature of a catalog entry against the public keys this Perysee trusts. Several keys can be
 * trusted at once, identified by the entry's "keyId", so a key can be replaced without breaking older releases.
 * The signature proves that Perysee published the entry, whatever server delivered it.
 */
final class CatalogSignature
{
    /**
     * @param  array<string, string>  $keys  key id => base64 of the 32-byte public key
     */
    public function __construct(private readonly array $keys) {}

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = $json === false ? null : json_decode($json, true);
        $keys = [];
        foreach (is_array($data['keys'] ?? null) ? $data['keys'] : [] as $id => $key) {
            if (is_string($id) && is_string($key)) {
                $keys[$id] = $key;
            }
        }

        return new self($keys);
    }

    public function verify(CatalogEntry $entry): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new CatalogException('The PHP sodium extension is required to verify module signatures.');
        }
        if ($entry->signature === null || $entry->keyId === null) {
            throw new CatalogException("Module {$entry->slug} has no signature.");
        }
        $key = $this->keys[$entry->keyId] ?? throw new CatalogException("Module {$entry->slug} is signed with the unknown key \"{$entry->keyId}\".");
        $publicKey = base64_decode($key, true);
        $signature = base64_decode($entry->signature, true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new CatalogException("The trusted key \"{$entry->keyId}\" is malformed.");
        }
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($signature, $entry->payload(), $publicKey)) {
            throw new CatalogException("The signature of module {$entry->slug} {$entry->version} is not valid. It was not installed.");
        }
    }
}
