<?php

namespace App\Console\Commands\Ai;

use App\Exceptions\Ai\AiGenerationException;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Console\Command;

/**
 * One tiny JSON-mode request, with no patient data in it, to check that the AI
 * provider works from THIS server: `php artisan ai:ping`.
 *
 * Prints ok / fail, the model, the latency and whether the provider's JSON mode
 * had to be retried as plain text. A failure prints the provider's HTTP status
 * and its own error message, never any prompt. Exit code 0 / 1.
 */
class PingAi extends Command
{
    protected $signature = 'ai:ping {--profile=default : default (plans, summary suggestion, insight) or summary (the weekly summaries)}';

    protected $description = 'Make one tiny JSON-mode request to the AI provider and print ok/fail, the model and the latency';

    public function handle(): int
    {
        $profile = (string) $this->option('profile');

        if (! in_array($profile, ['default', 'summary'], true)) {
            $this->error('The profile must be "default" or "summary".');

            return self::INVALID;
        }

        $client = $profile === 'summary' ? new OpenAiCompatibleClient((array) config('ai.summary')) : new OpenAiCompatibleClient;

        if (! $client->isConfigured()) {
            $this->line("fail profile={$profile} model=".($client->model() ?? '-').' reason=not_configured');

            return self::FAILURE;
        }

        $started = hrtime(true);

        try {
            $reply = $client->chatJson([
                ['role' => 'system', 'content' => 'Reply with a JSON object exactly like {"ok": true}.'],
                ['role' => 'user', 'content' => 'ping'],
            ], ['max_completion_tokens' => 50], ['feature' => 'ping']);
        } catch (AiGenerationException $e) {
            $this->line(sprintf('fail profile=%s model=%s latency=%dms reason="%s"', $profile, $client->model() ?? '-', $this->elapsed($started), $e->getMessage()));

            return self::FAILURE;
        }

        $ok = ($reply['ok'] ?? null) === true;

        $this->line(sprintf(
            '%s profile=%s model=%s latency=%dms json_mode=%s',
            $ok ? 'ok' : 'fail',
            $profile,
            $client->model() ?? '-',
            $this->elapsed($started),
            $client->lastCallRetried ? 'refused_retried_as_text' : 'accepted',
        ));

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
