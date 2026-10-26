<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Crypt;

/**
 * Phase 4, A2: file contents encrypted at rest with Laravel's encrypter (APP_KEY),
 * in frames of 1 MiB so neither writing nor streaming needs the whole file in
 * memory twice. Format: repeated [4-byte big-endian length][encrypted frame].
 * Each frame is authenticated by the encrypter (a tampered frame fails to decrypt).
 */
class ReportCrypto
{
    public const FRAME_BYTES = 1048576;

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function encryptStream($in, $out): void
    {
        while (! feof($in)) {
            $chunk = fread($in, self::FRAME_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $frame = Crypt::encryptString($chunk);
            fwrite($out, pack('N', strlen($frame)).$frame);
        }
    }

    /**
     * Calls $emit with each decrypted chunk, in order.
     *
     * @param  resource  $in
     */
    public function decryptStream($in, callable $emit): void
    {
        while (($header = fread($in, 4)) !== false && strlen($header) === 4) {
            $length = unpack('N', $header)[1];
            $frame = '';

            while (strlen($frame) < $length && ! feof($in)) {
                $frame .= fread($in, $length - strlen($frame));
            }

            $emit(Crypt::decryptString($frame));
        }
    }
}
