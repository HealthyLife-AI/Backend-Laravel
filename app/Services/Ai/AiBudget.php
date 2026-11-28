<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiBudgetExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Audit BE-16: the platform's per-minute LLM token budget, shared by every
 * clinic and every feature (the free tier allows about 8,000 a minute).
 *
 * Before a request it RESERVES an estimate; after it, the real `total_tokens`
 * replaces the estimate (settle), or the reservation is released when the
 * provider refused it. A request that timed out keeps its reservation, because
 * the provider may have counted it. One clinic can hold at most `clinic_share`
 * of the budget. Windows are fixed 60-second slots on a cache counter, so
 * it is atomic across processes; a limit of 0 switches it all off.
 */
class AiBudget
{
    /**
     * @return array{tokens: int, platform: string, clinic: string|null}|null null when the budget is off
     *
     * @throws AiBudgetExceededException when over, and `$waitSeconds` is not enough to reach the next window
     */
    public function reserve(int $tokens, ?int $clinicId, int $waitSeconds = 0): ?array
    {
        $limit = (int) config('ai.budget.tokens_per_minute');

        if ($limit <= 0) {
            return null;
        }

        $clinicLimit = (int) max(1, floor($limit * (float) config('ai.budget.clinic_share')));

        while (true) {
            $slot = intdiv(now()->timestamp, 60);
            $platform = "ai-budget:all:{$slot}";
            $clinic = $clinicId === null ? null : "ai-budget:clinic:{$clinicId}:{$slot}";

            if ($this->take($platform, $tokens, $limit)) {
                if ($clinic === null || $this->take($clinic, $tokens, $clinicLimit)) {
                    return ['tokens' => $tokens, 'platform' => $platform, 'clinic' => $clinic];
                }

                // The clinic is over its share: give the platform's take back.
                Cache::decrement($platform, $tokens);
            }

            $untilNext = 60 - (now()->timestamp % 60);

            if ($waitSeconds < $untilNext) {
                throw new AiBudgetExceededException($untilNext);
            }

            Sleep::for($untilNext)->seconds();
            $waitSeconds -= $untilNext;
        }
    }

    /**
     * Replaces the estimate with what the provider reported. A refused request
     * reports nothing and gives the whole reservation back.
     *
     * @param  array{tokens: int, platform: string, clinic: string|null}|null  $reservation
     */
    public function settle(?array $reservation, ?int $actualTokens): void
    {
        if ($reservation === null) {
            return;
        }

        $delta = ($actualTokens ?? 0) - $reservation['tokens'];

        $this->add($reservation['platform'], $delta);

        if ($reservation['clinic'] !== null) {
            $this->add($reservation['clinic'], $delta);
        }
    }

    /** An empty slot always admits one request, so a single large one is never stuck forever. */
    private function take(string $key, int $tokens, int $limit): bool
    {
        Cache::add($key, 0, 180);
        $total = (int) Cache::increment($key, $tokens);

        if ($total > $limit && $total - $tokens > 0) {
            Cache::decrement($key, $tokens);

            return false;
        }

        return true;
    }

    private function add(string $key, int $delta): void
    {
        if ($delta > 0) {
            Cache::increment($key, $delta);
        } elseif ($delta < 0) {
            Cache::decrement($key, -$delta);
        }
    }
}
