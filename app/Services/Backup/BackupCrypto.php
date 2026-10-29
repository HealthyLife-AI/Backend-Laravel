<?php

namespace App\Services\Backup;

use App\Exceptions\Backup\BackupException;

/**
 * AES-256-GCM file encryption with a key derived from a passphrase.
 *
 * File layout (all integers big-endian):
 *   "HLBK"            4   magic
 *   version           1   currently 1
 *   iterations        4   PBKDF2-SHA256 rounds used for this file
 *   salt             16   random per file
 *   iv               12   random per file
 *   tag              16   GCM authentication tag
 *   ciphertext        *
 *
 * Everything before the tag (magic .. iv, 37 bytes) is authenticated as additional data,
 * so a tampered header fails the tag check instead of decrypting to something else.
 * The whole file is held in memory: a backup is a few MB to a few tens of MB.
 */
class BackupCrypto
{
    public const MAGIC = 'HLBK';

    public const VERSION = 1;

    private const SALT_BYTES = 16;

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    private const HEADER_BYTES = 4 + 1 + 4 + self::SALT_BYTES + self::IV_BYTES + self::TAG_BYTES;

    /** Refuses a header asking for an absurd number of rounds (a crafted file would otherwise stall the CLI). */
    private const MAX_ITERATIONS = 10_000_000;

    private const MIN_ITERATIONS = 200_000;

    public function encrypt(string $plain, string $passphrase, ?int $iterations = null): string
    {
        $iterations ??= (int) config('backup.kdf_iterations');

        if ($iterations < self::MIN_ITERATIONS) {
            throw new BackupException('encrypt', 'The key derivation iteration count is below the minimum of '.self::MIN_ITERATIONS.'.');
        }

        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        $aad = $this->aad($iterations, $salt, $iv);
        $tag = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key($passphrase, $salt, $iterations), OPENSSL_RAW_DATA, $iv, $tag, $aad, self::TAG_BYTES);

        if ($cipher === false || strlen($tag) !== self::TAG_BYTES) {
            throw new BackupException('encrypt', 'Encryption failed.');
        }

        return $aad.$tag.$cipher;
    }

    public function decrypt(string $blob, string $passphrase): string
    {
        if (strlen($blob) < self::HEADER_BYTES || substr($blob, 0, 4) !== self::MAGIC) {
            throw new BackupException('decrypt', 'This is not a HealthyLife backup file.');
        }

        if (ord($blob[4]) !== self::VERSION) {
            throw new BackupException('decrypt', 'Unsupported backup format version '.ord($blob[4]).'.');
        }

        $iterations = unpack('N', substr($blob, 5, 4))[1];

        if ($iterations < self::MIN_ITERATIONS || $iterations > self::MAX_ITERATIONS) {
            throw new BackupException('decrypt', 'The backup header is invalid.');
        }

        $salt = substr($blob, 9, self::SALT_BYTES);
        $iv = substr($blob, 9 + self::SALT_BYTES, self::IV_BYTES);
        $tag = substr($blob, 9 + self::SALT_BYTES + self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($blob, self::HEADER_BYTES);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key($passphrase, $salt, $iterations), OPENSSL_RAW_DATA, $iv, $tag, $this->aad($iterations, $salt, $iv));

        if ($plain === false) {
            throw new BackupException('decrypt', 'Decryption failed: wrong passphrase, or the file is damaged.');
        }

        return $plain;
    }

    private function aad(int $iterations, string $salt, string $iv): string
    {
        return self::MAGIC.chr(self::VERSION).pack('N', $iterations).$salt.$iv;
    }

    private function key(string $passphrase, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, $iterations, 32, true);
    }
}
