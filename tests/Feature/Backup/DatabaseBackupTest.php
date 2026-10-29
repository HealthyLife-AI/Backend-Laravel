<?php

namespace Tests\Feature\Backup;

use App\Exceptions\Backup\BackupException;
use App\Services\Backup\BackupCrypto;
use App\Services\Backup\DatabaseDumper;
use App\Services\Scheduling\SelfScheduler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** `db:backup` and `db:backup-decrypt`: dump -> gzip -> AES-256-GCM -> Telegram, and back. */
class DatabaseBackupTest extends TestCase
{
    private const PASSPHRASE = 'correct horse battery staple 42';

    private const TOKEN = '123456:SECRET-BOT-TOKEN';

    private const CHAT = '-1009988776655';

    private const SQL = "-- fake dump\nCREATE TABLE `patients` (`name` text);\nINSERT INTO `patients` VALUES ('سلمى الخطيب 0599123456');\n";

    private string $tmp;

    private bool $dumperRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hlbk-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);

        config([
            'backup.temp_dir' => $this->tmp,
            'backup.passphrase' => self::PASSPHRASE,
            'backup.telegram.bot_token' => self::TOKEN,
            'backup.telegram.chat_id' => self::CHAT,
            'backup.kdf_iterations' => 200_000,
        ]);

        $this->dumperThatWrites(self::SQL);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);

        parent::tearDown();
    }

    private function dumperThatWrites(string $sql, ?\Throwable $fails = null): void
    {
        $test = $this;
        $this->app->instance(DatabaseDumper::class, new class($test, $sql, $fails) extends DatabaseDumper
        {
            public function __construct(private object $test, private string $sql, private ?\Throwable $fails) {}

            public function dump(string $path, ?string $connection = null): int
            {
                $this->test->markDumperRan();

                if ($this->fails !== null) {
                    throw $this->fails;
                }

                file_put_contents($path, $this->sql);

                return 3;
            }
        });
    }

    public function markDumperRan(): void
    {
        $this->dumperRan = true;
    }

    private function leftovers(): array
    {
        return glob($this->tmp.DIRECTORY_SEPARATOR.'*') ?: [];
    }

    /** @return array<string, array{contents: string, filename: ?string}> the multipart parts of the one Telegram request */
    private function telegramParts(): array
    {
        $parts = [];
        Http::assertSent(function ($request) use (&$parts) {
            foreach ($request->data() as $part) {
                $parts[$part['name']] = ['contents' => $part['contents'], 'filename' => $part['filename'] ?? null];
            }

            return str_contains($request->url(), '/sendDocument');
        });

        return $parts;
    }

    private function fakeTelegram(int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($status === 200 ? ['ok' => true, 'result' => []] : ['ok' => false, 'description' => 'nope'], $status)]);
    }

    // ---- encryption ------------------------------------------------------------------------------------------------

    public function test_encrypt_then_decrypt_returns_the_same_bytes(): void
    {
        $crypto = new BackupCrypto;
        $plain = random_bytes(5000).self::SQL;

        $blob = $crypto->encrypt($plain, self::PASSPHRASE, 200_000);

        $this->assertSame('HLBK', substr($blob, 0, 4));
        $this->assertSame(1, ord($blob[4]), 'format version');
        $this->assertSame(200_000, unpack('N', substr($blob, 5, 4))[1], 'iterations are in the header');
        $this->assertSame($plain, $crypto->decrypt($blob, self::PASSPHRASE));
        $this->assertStringNotContainsString('سلمى', $crypto->encrypt(self::SQL, self::PASSPHRASE, 200_000));
        $this->assertNotSame($blob, $crypto->encrypt($plain, self::PASSPHRASE, 200_000), 'fresh salt and iv every time');
    }

    public function test_a_wrong_passphrase_fails_cleanly(): void
    {
        $blob = (new BackupCrypto)->encrypt(self::SQL, self::PASSPHRASE, 200_000);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('wrong passphrase');

        (new BackupCrypto)->decrypt($blob, 'not the passphrase at all');
    }

    public function test_a_damaged_or_foreign_file_is_refused(): void
    {
        $crypto = new BackupCrypto;
        $blob = $crypto->encrypt(self::SQL, self::PASSPHRASE, 200_000);

        $flip = fn (int $at) => substr_replace($blob, chr(ord($blob[$at]) ^ 1), $at, 1);

        foreach ([['ciphertext', $flip(strlen($blob) - 1)], ['header salt', $flip(10)], ['tag', $flip(40)]] as [$what, $bad]) {
            try {
                $crypto->decrypt($bad, self::PASSPHRASE);
                $this->fail("a damaged {$what} was accepted");
            } catch (BackupException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['', 'not a backup at all, just some text that is long enough to pass the length check....', 'HLBK'.chr(9).str_repeat("\0", 60)] as $junk) {
            try {
                $crypto->decrypt($junk, self::PASSPHRASE);
                $this->fail('junk was accepted');
            } catch (BackupException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_absurd_iteration_count_in_a_header_is_refused_without_working(): void
    {
        $blob = (new BackupCrypto)->encrypt(self::SQL, self::PASSPHRASE, 200_000);
        $evil = substr_replace($blob, pack('N', 4_000_000_000), 5, 4);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('header is invalid');

        (new BackupCrypto)->decrypt($evil, self::PASSPHRASE);
    }

    public function test_too_few_iterations_are_not_allowed(): void
    {
        $this->expectException(BackupException::class);

        (new BackupCrypto)->encrypt(self::SQL, self::PASSPHRASE, 1000);
    }

    // ---- the dump ------------------------------------------------------------------------------------------------------

    /**
     * Needs a real MySQL/MariaDB (the dumper has no other target): set BACKUP_TEST_MYSQL=host:port:user:password,
     * a user allowed to create databases. Skipped otherwise.
     */
    public function test_the_dumper_writes_structure_and_data_and_the_whole_chain_round_trips_on_a_real_server(): void
    {
        $env = (string) getenv('BACKUP_TEST_MYSQL');
        if ($env === '') {
            $this->markTestSkipped('Set BACKUP_TEST_MYSQL=host:port:user:password to run the dump against a real MySQL.');
        }

        [$host, $port, $user, $password] = explode(':', $env, 4);
        $admin = new PDO("mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source = 'hlbk_src_'.bin2hex(random_bytes(3));
        $target = 'hlbk_dst_'.bin2hex(random_bytes(3));

        try {
            $admin->exec("CREATE DATABASE `{$source}` CHARACTER SET utf8mb4");
            $admin->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
            $admin->exec("CREATE TABLE `{$source}`.`patients` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, note TEXT NULL, photo BLOB NULL, born DATE NULL, UNIQUE KEY uq_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $admin->exec("CREATE TABLE `{$source}`.`visits` (id INT AUTO_INCREMENT PRIMARY KEY, patient_id INT UNSIGNED NOT NULL, CONSTRAINT fk_visit_patient FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE) ENGINE=InnoDB");
            $insert = $admin->prepare("INSERT INTO `{$source}`.`patients` (id, name, note, photo, born) VALUES (?, ?, ?, ?, ?)");
            $insert->execute([1, 'سلمى الخطيب', "it's a \"quoted\" \\ note\nwith a new line 😀", "\x00\x01\xff", '1990-05-17']);
            $insert->execute([2, 'Omar', null, null, null]);
            $admin->exec("INSERT INTO `{$source}`.`visits` (patient_id) VALUES (1), (1), (2)");

            config(['database.connections.fixture' => ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $source, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']]);
            $sqlFile = $this->tmp.DIRECTORY_SEPARATOR.'dump.sql';
            $tables = (new DatabaseDumper)->dump($sqlFile, 'fixture');
            $sql = file_get_contents($sqlFile);

            $this->assertSame(2, $tables);
            $this->assertStringContainsString('CREATE TABLE', $sql);
            $this->assertStringContainsString('utf8mb4', $sql);

            // dump -> gzip -> encrypt -> decrypt -> gunzip: byte-identical SQL ...
            $crypto = new BackupCrypto;
            $restored = gzdecode($crypto->decrypt($crypto->encrypt(gzencode($sql, 9), self::PASSPHRASE, 200_000), self::PASSPHRASE));
            $this->assertSame($sql, $restored);

            // ... that rebuilds the same data in a fresh database.
            $admin->exec("USE `{$target}`");
            $admin->exec('SET NAMES utf8mb4');
            $admin->exec($restored);

            $this->assertSame(
                $admin->query("SELECT id, name, note, HEX(photo), born FROM `{$source}`.patients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
                $admin->query("SELECT id, name, note, HEX(photo), born FROM `{$target}`.patients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            );
            $this->assertSame(3, (int) $admin->query("SELECT COUNT(*) FROM `{$target}`.visits")->fetchColumn());
            $this->assertSame(1, (int) $admin->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$target}' AND CONSTRAINT_NAME = 'fk_visit_patient'")->fetchColumn(), 'foreign keys survive');
        } finally {
            $admin->exec("DROP DATABASE IF EXISTS `{$source}`");
            $admin->exec("DROP DATABASE IF EXISTS `{$target}`");
        }
    }

    public function test_an_unsupported_driver_is_refused(): void
    {
        config(['database.connections.lite' => ['driver' => 'sqlite', 'database' => ':memory:']]);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Unsupported database driver');

        (new DatabaseDumper)->dump($this->tmp.DIRECTORY_SEPARATOR.'x.sql', 'lite');
    }

    public function test_a_dump_failure_message_carries_no_driver_text(): void
    {
        config(['database.connections.broken' => ['driver' => 'mysql', 'host' => 'db-secret-host.internal', 'port' => 1, 'database' => 'secretdb', 'username' => 'u', 'password' => 'p']]);

        try {
            (new DatabaseDumper)->dump($this->tmp.DIRECTORY_SEPARATOR.'x.sql', 'broken');
            $this->fail('expected a failure');
        } catch (BackupException $e) {
            $this->assertSame('dump', $e->stage);
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    // ---- the command -----------------------------------------------------------------------------------------------------

    public function test_the_backup_is_sent_encrypted_with_a_clean_caption_and_the_temp_files_are_gone(): void
    {
        $this->fakeTelegram();

        $this->artisan('db:backup')->assertSuccessful();

        $parts = $this->telegramParts();

        $this->assertMatchesRegularExpression('/^healthylife-\d{8}-\d{4}\.sql\.gz\.enc$/', $parts['document']['filename']);
        $this->assertSame('HLBK', substr($parts['document']['contents'], 0, 4), 'an encrypted file, not a dump');
        $this->assertSame(self::CHAT, $parts['chat_id']['contents']);

        $caption = $parts['caption']['contents'];
        $this->assertStringContainsString('Tables: 3', $caption);
        $this->assertStringContainsString('Compressed size:', $caption);
        $this->assertStringContainsString('Date: ', $caption);
        $this->assertStringContainsString('SHA-256: '.hash('sha256', $parts['document']['contents']), $caption);
        foreach (['سلمى', 'patients', 'INSERT', self::PASSPHRASE, self::TOKEN] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $caption);
        }

        $sql = gzdecode((new BackupCrypto)->decrypt($parts['document']['contents'], self::PASSPHRASE));
        $this->assertSame(self::SQL, $sql, 'what was sent restores to exactly what was dumped');
        $this->assertSame([], $this->leftovers(), 'temp files removed after success');
    }

    public function test_the_secrets_are_never_printed(): void
    {
        $this->fakeTelegram();

        Artisan::call('db:backup');
        $output = Artisan::output();

        foreach ([self::PASSPHRASE, self::TOKEN, self::CHAT, 'SECRET-BOT-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_a_missing_setting_exits_non_zero_dumps_nothing_and_makes_no_http_call(): void
    {
        foreach (['BACKUP_PASSPHRASE' => 'backup.passphrase', 'BACKUP_TELEGRAM_BOT_TOKEN' => 'backup.telegram.bot_token', 'BACKUP_TELEGRAM_CHAT_ID' => 'backup.telegram.chat_id'] as $env => $key) {
            $original = config($key);
            config([$key => null]);
            Http::fake();
            $this->dumperRan = false;

            $this->artisan('db:backup')->expectsOutputToContain($env)->assertFailed();

            Http::assertNothingSent();
            $this->assertFalse($this->dumperRan, "no dump without {$env}");
            $this->assertSame([], $this->leftovers());
            config([$key => $original]);
        }
    }

    public function test_a_short_passphrase_is_refused_before_anything_runs(): void
    {
        config(['backup.passphrase' => 'short']);
        Http::fake();

        $this->artisan('db:backup')->expectsOutputToContain('at least 16')->assertFailed();

        Http::assertNothingSent();
        $this->assertFalse($this->dumperRan);
    }

    public function test_a_telegram_refusal_fails_reports_without_the_token_and_cleans_up(): void
    {
        Exceptions::fake();
        $this->fakeTelegram(401);

        $this->assertSame(1, Artisan::call('db:backup'));
        Exceptions::assertReported(fn (BackupException $e) => $e->stage === 'upload' && ! str_contains($e->getMessage(), 'SECRET') && ! str_contains($e->getMessage(), self::TOKEN));
        $this->assertSame([], $this->leftovers(), 'temp files removed after a failed upload');
        $this->assertStringNotContainsString(self::TOKEN, Artisan::output());
    }

    public function test_a_connection_error_message_with_the_token_url_is_not_passed_on(): void
    {
        Exceptions::fake();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot'.self::TOKEN.'/sendDocument'));

        $this->assertSame(1, Artisan::call('db:backup'));

        Exceptions::assertReported(fn (BackupException $e) => ! str_contains($e->getMessage(), 'SECRET') && $e->getPrevious() === null);
        $this->assertStringNotContainsString('SECRET', Artisan::output());
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_dump_failure_sends_nothing_reports_no_data_and_cleans_up(): void
    {
        Exceptions::fake();
        Http::fake();
        $this->dumperThatWrites('partial sql with سلمى', new RuntimeException('SQLSTATE: access denied for user "root" at host db-secret.internal row سلمى'));

        $this->assertSame(1, Artisan::call('db:backup'));

        Http::assertNothingSent();
        Exceptions::assertReported(fn (BackupException $e) => str_contains($e->getMessage(), 'RuntimeException') && ! str_contains($e->getMessage(), 'db-secret') && ! str_contains($e->getMessage(), 'سلمى'));
        $this->assertStringNotContainsString('db-secret', Artisan::output());
        $this->assertSame([], $this->leftovers(), 'temp files removed after a failed dump');
    }

    public function test_a_backup_too_big_for_telegram_is_not_sent(): void
    {
        config(['backup.max_upload_bytes' => 100]);
        Http::fake();

        $this->artisan('db:backup')->expectsOutputToContain('larger than Telegram allows')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame([], $this->leftovers());
    }

    public function test_the_outcome_is_recorded_for_the_scheduler_status(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true])->push([], 500)]);
        $this->artisan('db:backup')->assertSuccessful();

        $scheduler = app(SelfScheduler::class);
        $this->assertNotNull($scheduler->lastRan('backup'));
        $this->assertSame(1, $scheduler->lastResult('backup')['ok']);
        $this->assertSame(3, $scheduler->lastResult('backup')['tables']);

        $this->artisan('db:backup')->assertFailed();

        $this->assertSame(['ok' => 0, 'failed_stage' => 'upload'], array_diff_key($scheduler->lastResult('backup'), ['ran_at' => 1]));
    }

    public function test_it_is_scheduled_daily_at_0315_hebron_without_overlap_on_one_server(): void
    {
        Artisan::call('list');   // boots the console kernel, which registers the schedule
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'db:backup'));

        $this->assertNotNull($event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertSame('Asia/Hebron', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    // ---- db:backup-decrypt -----------------------------------------------------------------------------------------------------

    private function encryptedFile(): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'healthylife-20261028-0315.sql.gz.enc';
        file_put_contents($path, (new BackupCrypto)->encrypt(gzencode(self::SQL), self::PASSPHRASE, 200_000));

        return $path;
    }

    public function test_decrypt_writes_the_gzip_next_to_the_file_and_it_gunzips_to_the_sql(): void
    {
        $file = $this->encryptedFile();

        $this->artisan('db:backup-decrypt', ['file' => $file])->assertSuccessful();

        $out = substr($file, 0, -4);
        $this->assertSame(self::SQL, gzdecode(file_get_contents($out)));
    }

    public function test_decrypt_honours_out_and_will_not_overwrite_without_force(): void
    {
        $file = $this->encryptedFile();
        $out = $this->tmp.DIRECTORY_SEPARATOR.'restore.sql.gz';
        file_put_contents($out, 'precious');

        $this->artisan('db:backup-decrypt', ['file' => $file, '--out' => $out])->expectsOutputToContain('already exists')->assertFailed();
        $this->assertSame('precious', file_get_contents($out));

        $this->artisan('db:backup-decrypt', ['file' => $file, '--out' => $out, '--force' => true])->assertSuccessful();
        $this->assertSame(self::SQL, gzdecode(file_get_contents($out)));
    }

    public function test_decrypt_with_a_wrong_passphrase_fails_and_writes_nothing(): void
    {
        $file = $this->encryptedFile();
        config(['backup.passphrase' => 'a completely different passphrase']);

        $this->artisan('db:backup-decrypt', ['file' => $file])->expectsOutputToContain('wrong passphrase')->assertFailed();

        $this->assertFileDoesNotExist(substr($file, 0, -4));
    }

    public function test_decrypt_prompts_for_the_passphrase_when_it_is_not_in_the_env(): void
    {
        $file = $this->encryptedFile();
        config(['backup.passphrase' => null]);

        $this->artisan('db:backup-decrypt', ['file' => $file])->expectsQuestion('Backup passphrase', self::PASSPHRASE)->assertSuccessful();

        $this->assertSame(self::SQL, gzdecode(file_get_contents(substr($file, 0, -4))));
    }

    public function test_decrypt_of_a_missing_file_fails(): void
    {
        $this->artisan('db:backup-decrypt', ['file' => $this->tmp.'/nope.enc'])->expectsOutputToContain('File not found')->assertFailed();
    }
}
