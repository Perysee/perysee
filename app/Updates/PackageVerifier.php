<?php

declare(strict_types=1);

namespace App\Updates;

/**
 * Checks a downloaded archive against the SHA256SUMS file published with its release. The checksums are fetched
 * over HTTPS from the release itself, so they prove that the archive is the one that was published, not that
 * the publisher is trustworthy.
 */
final class PackageVerifier
{
    public const ARCHIVE = 'perysee.zip';

    /**
     * The hash recorded for a file in a "<hex>  <name>" checksum list.
     */
    public function expectedHash(string $checksums, string $name = self::ARCHIVE): string
    {
        foreach (preg_split('/\R/', $checksums) ?: [] as $line) {
            if (preg_match('/^([0-9a-fA-F]{64}) [ *]?(\S+)$/', trim($line), $match) === 1 && $match[2] === $name) {
                return strtolower($match[1]);
            }
        }

        throw new UpdateException("The checksum list does not contain {$name}.");
    }

    public function assertMatches(string $archive, string $expected): void
    {
        $expected = strtolower(trim($expected));
        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new UpdateException('The expected checksum is not a SHA-256 value.');
        }
        $actual = is_file($archive) ? hash_file('sha256', $archive) : false;
        if ($actual === false || ! hash_equals($expected, $actual)) {
            throw new UpdateException('The archive does not match its published SHA-256 checksum. Nothing was installed.');
        }
    }
}
