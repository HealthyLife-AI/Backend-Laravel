<?php

namespace App\Services\Reports;

use App\Exceptions\ApiCodeException;

/**
 * Phase 4, A2: takes the metadata out of an image BEFORE it is stored: EXIF (GPS
 * position, device, time), XMP, IPTC / Photoshop blocks, comments and text chunks.
 * What is left is the picture and its colour data, and for a JPEG a one-entry
 * EXIF block holding only the orientation, so a phone photo still shows upright.
 *
 * Pure PHP on the file's own structure (no GD / Imagick, no re-encoding):
 *   JPEG  drops APP1 (EXIF / XMP), APP3-APP13, APP15 and COM segments;
 *   PNG   keeps critical and colour chunks, drops text, time and eXIf chunks;
 *   WebP  drops EXIF and XMP chunks and clears their flags in VP8X.
 * HEIC cannot be cleaned this way, so it is REFUSED rather than stored with its
 * metadata. Anything that does not parse is refused too.
 */
class ImageMetadataStripper
{
    public const ORIENTATION_TAG = 0x0112;

    /** @throws ApiCodeException */
    public function strip(string $bytes, string $mime): string
    {
        try {
            return match ($mime) {
                'image/jpeg' => $this->jpeg($bytes),
                'image/png' => $this->png($bytes),
                'image/webp' => $this->webp($bytes),
                default => throw new \RuntimeException('unsupported'),
            };
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'unsupported') {
                throw new ApiCodeException('This image format cannot be cleaned of its metadata. Send it as JPEG or PNG.', 'unsupported_image_format', 422);
            }

            throw new ApiCodeException('This image could not be read.', 'invalid_image', 422);
        }
    }

    private function jpeg(string $b): string
    {
        $length = strlen($b);

        if ($length < 4 || ! str_starts_with($b, "\xFF\xD8")) {
            throw new \RuntimeException('not a jpeg');
        }

        $kept = [];
        $orientation = null;
        $pos = 2;

        while ($pos < $length) {
            if ($b[$pos] !== "\xFF") {
                throw new \RuntimeException('bad marker');
            }

            while ($pos < $length && $b[$pos] === "\xFF") {
                $pos++;
            }

            if ($pos >= $length) {
                throw new \RuntimeException('truncated');
            }

            $marker = ord($b[$pos]);
            $pos++;

            if ($marker === 0xD9) {
                break;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($pos + 2 > $length) {
                throw new \RuntimeException('truncated');
            }

            $size = (ord($b[$pos]) << 8) | ord($b[$pos + 1]);
            if ($size < 2 || $pos + $size > $length) {
                throw new \RuntimeException('truncated segment');
            }

            $segment = substr($b, $pos - 2, $size + 2);
            $payload = substr($b, $pos + 2, $size - 2);

            if ($marker === 0xDA) {
                // The scan: everything up to the end-of-image marker is picture data.
                $end = strrpos($b, "\xFF\xD9");
                if ($end === false || $end < $pos) {
                    throw new \RuntimeException('no end of image');
                }
                $kept[] = substr($b, $pos - 2, $end + 2 - ($pos - 2));

                return "\xFF\xD8".$this->withOrientation($kept, $orientation);
            }

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation ??= $this->exifOrientation(substr($payload, 6));
            }

            if ($this->keepsJpegSegment($marker, $payload)) {
                $kept[] = $segment;
            }

            $pos += $size;
        }

        throw new \RuntimeException('no scan');
    }

    private function keepsJpegSegment(int $marker, string $payload): bool
    {
        if ($marker >= 0xE0 && $marker <= 0xEF) {
            return match ($marker) {
                0xE0, 0xEE => true,
                0xE2 => str_starts_with($payload, "ICC_PROFILE\0"),
                default => false,
            };
        }

        return $marker !== 0xFE;
    }

    /** @param  list<string>  $segments */
    private function withOrientation(array $segments, ?int $orientation): string
    {
        $exif = '';

        if ($orientation !== null && $orientation > 1 && $orientation <= 8) {
            $tiff = "MM\0*".pack('N', 8).pack('n', 1).pack('nnNnn', self::ORIENTATION_TAG, 3, 1, $orientation, 0).pack('N', 0);
            $body = "Exif\0\0".$tiff;
            $exif = "\xFF\xE1".pack('n', strlen($body) + 2).$body;
        }

        if ($exif !== '' && isset($segments[0]) && str_starts_with($segments[0], "\xFF\xE0")) {
            return array_shift($segments).$exif.implode('', $segments);
        }

        return $exif.implode('', $segments);
    }

    private function exifOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $little = str_starts_with($tiff, 'II');
        if (! $little && ! str_starts_with($tiff, 'MM')) {
            return null;
        }

        $short = fn (int $at) => $at + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] : null;
        $long = fn (int $at) => $at + 4 <= strlen($tiff) ? unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] : null;

        $ifd = $long(4);
        $count = $ifd === null ? null : $short($ifd);

        if ($count === null || $count > 512) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($short($entry) === self::ORIENTATION_TAG && $short($entry + 2) === 3) {
                $value = $short($entry + 8);

                return $value !== null && $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    private function png(string $b): string
    {
        $signature = "\x89PNG\r\n\x1A\n";

        if (! str_starts_with($b, $signature)) {
            throw new \RuntimeException('not a png');
        }

        $keep = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'bKGD', 'hIST', 'pHYs', 'acTL', 'fcTL', 'fdAT'];
        $out = $signature;
        $pos = 8;
        $length = strlen($b);

        while ($pos + 12 <= $length) {
            $size = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);

            if ($pos + 12 + $size > $length) {
                throw new \RuntimeException('truncated chunk');
            }

            if (in_array($type, $keep, true)) {
                $out .= substr($b, $pos, 12 + $size);
            }

            $pos += 12 + $size;

            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new \RuntimeException('no IEND');
    }

    private function webp(string $b): string
    {
        if (strlen($b) < 12 || ! str_starts_with($b, 'RIFF') || substr($b, 8, 4) !== 'WEBP') {
            throw new \RuntimeException('not a webp');
        }

        $pos = 12;
        $end = min(strlen($b), 8 + unpack('V', substr($b, 4, 4))[1]);
        $chunks = '';

        while ($pos + 8 <= $end) {
            $type = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            $padded = $size + ($size % 2);

            if ($pos + 8 + $size > $end) {
                throw new \RuntimeException('truncated chunk');
            }

            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $chunk = substr($b, $pos, 8 + $padded);

                if ($type === 'VP8X' && $size >= 1) {
                    // Flags: 0x08 = EXIF present, 0x04 = XMP present.
                    $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
                }

                $chunks .= $chunk;
            }

            $pos += 8 + $padded;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }
}
