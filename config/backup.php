<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encrypted off-site database backup (`php artisan db:backup`)
    |--------------------------------------------------------------------------
    |
    | The passphrase is deliberately NOT APP_KEY: a backup has to be restorable
    | even when the server and its environment are gone, so it is a separate
    | secret kept somewhere else (a password manager). Nothing here is ever
    | printed or logged.
    |
    */

    'passphrase' => env('BACKUP_PASSPHRASE'),

    'telegram' => [
        'bot_token' => env('BACKUP_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('BACKUP_TELEGRAM_CHAT_ID'),
    ],

    // PBKDF2-SHA256 rounds written into every backup's header. Decryption reads the
    // value from the file, so raising this later never strands an older backup.
    'kdf_iterations' => 300_000,

    'min_passphrase_length' => 16,

    // Telegram refuses bot uploads above 50 MB; leave a margin.
    'max_upload_bytes' => 49 * 1024 * 1024,

    // Where the dump, the gzip and the encrypted file are written while the job runs.
    'temp_dir' => env('BACKUP_TEMP_DIR'),

];
