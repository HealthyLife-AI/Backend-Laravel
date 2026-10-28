<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads ai_usage_logs: a month's totals per feature, per nutritionist, and the cost per active
 * patient; and the monthly draft cap. Cost is computed here, from config/ai.php prices, when
 * read: input tokens not served from cache at the input price, cached ones at the cached price,
 * completion tokens (reasoning included) at the output price.
 *
 * "Active patient" is the app's own follow-up definition (Subscriber::active(): status active,
 * not archived), made month-aware: status active, activated on or before the month's last day,
 * and not archived before the month's first day. So a patient followed all month counts, one
 * archived in the middle of it still counts for that month, one archived before it does not.
 */
class AiUsageReport
{
    /** @return array{0: string, 1: string} first and last clinic day of the month, Y-m-d */
    public function monthRange(?string $month = null): array
    {
        $first = $month !== null ? ClinicDay::date($month.'-01') : ClinicDay::today()->startOfMonth();

        return [$first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    public function draftCapReached(User $nutritionist): bool
    {
        $cap = (int) config('ai.max_drafts_per_nutritionist_per_month');

        if ($cap <= 0) {
            return false;
        }

        [$from, $to] = $this->monthRange();

        return AiUsageLog::query()
            ->where('nutritionist_id', $nutritionist->id)->where('feature', 'ai_draft')->where('success', true)
            ->whereBetween('created_at', [ClinicDay::startUtc($from), ClinicDay::endUtc($to)])
            ->count() >= $cap;
    }

    /** USD for a set of token counts. */
    public function cost(int $promptTokens, int $completionTokens, int $cachedTokens = 0): float
    {
        $cached = min($cachedTokens, $promptTokens);

        return (($promptTokens - $cached) * (float) config('ai.price_per_million_input')
            + $cached * (float) config('ai.price_per_million_cached_input')
            + $completionTokens * (float) config('ai.price_per_million_output')) / 1_000_000;
    }

    /** @return array<string, mixed> */
    public function forMonth(?string $month = null): array
    {
        [$from, $to] = $this->monthRange($month);
        $between = [ClinicDay::startUtc($from), ClinicDay::endUtc($to)];

        $sums = fn (string $group) => AiUsageLog::query()
            ->whereBetween('created_at', $between)
            ->selectRaw("{$group} as k, count(*) as calls, sum(case when success then 1 else 0 end) as ok_calls, sum(prompt_tokens) as prompt, sum(completion_tokens) as completion, sum(coalesce(cached_tokens, 0)) as cached, sum(total_tokens) as total")
            ->groupBy($group)
            ->get();

        $row = fn ($r, array $extra = []) => $extra + [
            'calls' => (int) $r->calls,
            'successful_calls' => (int) $r->ok_calls,
            'prompt_tokens' => (int) $r->prompt,
            'completion_tokens' => (int) $r->completion,
            'cached_tokens' => (int) $r->cached,
            'total_tokens' => (int) $r->total,
            'cost_usd' => round($this->cost((int) $r->prompt, (int) $r->completion, (int) $r->cached), 4),
        ];

        $features = $sums('feature')->map(fn ($r) => $row($r, ['feature' => $r->k]))->sortByDesc('cost_usd')->values();

        $totals = [
            'calls' => $features->sum('calls'),
            'successful_calls' => $features->sum('successful_calls'),
            'prompt_tokens' => $features->sum('prompt_tokens'),
            'completion_tokens' => $features->sum('completion_tokens'),
            'cached_tokens' => $features->sum('cached_tokens'),
            'total_tokens' => $features->sum('total_tokens'),
            'cost_usd' => round($features->sum('cost_usd'), 4),
        ];

        // A row has no nutritionist when the call was not for one (a ping) or the account is gone: those are in the totals only.
        $names = User::query()->whereIn('id', $sums('nutritionist_id')->pluck('k')->filter()->all())->pluck('name', 'id');
        $top = $sums('nutritionist_id')->filter(fn ($r) => $r->k !== null)
            ->map(function ($r) use ($row, $names, $from, $to) {
                $active = $this->activePatients((int) $r->k, $from, $to);
                $data = $row($r, ['id' => (int) $r->k, 'name' => $names[(int) $r->k] ?? null]);

                return $data + ['active_patients' => $active, 'cost_per_active_patient_usd' => $active > 0 ? round($data['cost_usd'] / $active, 4) : null];
            })
            ->sortByDesc('cost_usd')->take(10)->values();

        $platformActive = $this->activePatients(null, $from, $to);

        return [
            'month' => substr($from, 0, 7),
            'from' => $from,
            'to' => $to,
            'prices_usd_per_million' => [
                'input' => (float) config('ai.price_per_million_input'),
                'output' => (float) config('ai.price_per_million_output'),
                'cached_input' => (float) config('ai.price_per_million_cached_input'),
            ],
            'totals' => $totals,
            'by_feature' => $features->all(),
            'top_nutritionists' => $top->all(),
            // The headline: everything spent in the month over every active patient of the platform.
            'active_patients' => $platformActive,
            'cost_per_active_patient_usd' => $platformActive > 0 ? round($totals['cost_usd'] / $platformActive, 4) : null,
            'active_patient_definition' => 'status active, activated by the end of the month, not archived before its start',
        ];
    }

    /** Active patients in the month, for one nutritionist or (null) the whole platform. */
    public function activePatients(?int $nutritionistId, string $from, string $to): int
    {
        return Subscriber::withoutGlobalScopes()
            ->where('status', 'active')
            ->when($nutritionistId !== null, fn ($q) => $q->where('nutritionist_id', $nutritionistId))
            // Activated on or before the month's end (a patient with no activation time counts: older rows).
            ->where(fn ($q) => $q->whereNull('activated_at')->orWhere('activated_at', '<=', ClinicDay::endUtc($to)))
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>=', ClinicDay::startUtc($from)))
            ->count();
    }
}
