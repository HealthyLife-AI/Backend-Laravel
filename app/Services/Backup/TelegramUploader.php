<?php

namespace App\Services\Backup;

use App\Exceptions\Backup\BackupException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends a file to a Telegram chat with the bot API (`sendDocument`).
 *
 * The bot token is part of the request URL, so no exception from the HTTP client is
 * ever passed on (its message can contain that URL): only the status code is.
 */
class TelegramUploader
{
    public function send(string $path, string $filename, string $caption, string $botToken, string $chatId): void
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new BackupException('upload', 'The encrypted file could not be read.');
        }

        try {
            $response = Http::timeout(180)
                ->attach('document', $contents, $filename)
                ->post("https://api.telegram.org/bot{$botToken}/sendDocument", ['chat_id' => $chatId, 'caption' => $caption]);
        } catch (Throwable) {
            throw new BackupException('upload', 'Telegram could not be reached.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new BackupException('upload', 'Telegram refused the upload (HTTP '.$response->status().').');
        }
    }
}
