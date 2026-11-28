<?php
// Audit I6: runs the production backup chain (DatabaseDumper -> gzip -9 -> BackupCrypto) against a source DB
// WITHOUT the Number::fileSize caption step (needs ext-intl, missing locally) and without Telegram.
// Usage: P84_ENV="-e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1 -e DB_DATABASE=healthylife_ai -e DB_USERNAME=root -e DB_PASSWORD=root -e BACKUP_PASSPHRASE=..." p84 php i6-backup-chain.php <outdir>
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\DatabaseDumper;

require '/home/user/Backend-Laravel/vendor/autoload.php';
$app = require '/home/user/Backend-Laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $argv[1];
$t0 = microtime(true);
$tables = (new DatabaseDumper)->dump("$out/dump.sql");
$t1 = microtime(true);
$gz = gzencode(file_get_contents("$out/dump.sql"), 9);
file_put_contents("$out/dump.sql.gz", $gz);
$enc = (new BackupCrypto)->encrypt($gz, (string) getenv('BACKUP_PASSPHRASE'));
file_put_contents("$out/healthylife-test.sql.gz.enc", $enc);
$t2 = microtime(true);
printf("tables=%d sql_bytes=%d gz_bytes=%d enc_bytes=%d max_upload=%d dump_s=%.1f gz+enc_s=%.1f\n",
    $tables, filesize("$out/dump.sql"), strlen($gz), strlen($enc), config('backup.max_upload_bytes'), $t1 - $t0, $t2 - $t1);
foreach (DB::select("select table_name t, table_rows r, round((data_length+index_length)/1024) kb from information_schema.tables where table_schema=database() order by data_length desc limit 8") as $r) {
    echo "  {$r->t} rows~{$r->r} {$r->kb}KB\n";
}
