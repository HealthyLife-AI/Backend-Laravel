<?php

namespace App\Services\Progress;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\PatientCheckin;
use App\Models\Subscriber;
use App\Models\WeightInsight;
use App\Services\Adherence\AdherenceService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\ArabicCount;
use App\Support\ArabicText;
use App\Support\ClinicDay;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3, C: why did the weight move between the two latest readings?
 *
 * The server computes the FACTS (the change, how well the patient followed
 * the plan, off-plan and high-sodium logs, what the last check-ins said) and
 * a few booleans from them (a water fluctuation is likely; the change fits
 * the plan). The model is asked only to word them in two or three kind
 * sentences, and its reply is thrown away for the deterministic fallback
 * text when it invents a number, runs long, or says anything from the
 * blocklist (medicines, diagnoses, calories).
 *
 * What is sent to the model: the facts. Never the name, phone, conditions,
 * medications or any free-text note. The facts contain no free text at all.
 *
 * Cached per reading pair (`weight_insights`, unique per subscriber and end
 * date): a new reading is a new pair, a corrected reading changes the change
 * and so regenerates. The nutritionist can also ask for a fresh wording.
 */
class WeightInsightService
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly AdherenceService $adherence,
    ) {}

    /**
     * The window and its facts, or null with fewer than two readings, or when the
     * latest two (or the first one at least `min_days` earlier) are too far
     * apart to be explained by the days between them.
     *
     * @return array{from_date: string, to_date: string, facts: array<string, mixed>}|null
     */
    public function facts(Subscriber $subscriber): ?array
    {
        $window = $this->window($subscriber);

        if ($window === null) {
            return null;
        }

        [$from, $to] = $window;
        $days = (int) $from['at']->diffInDays($to['at']);
        $change = round($to['kg'] - $from['kg'], 1);

        // One window for every count below: the `days` calendar days AFTER the first weigh-in, up to and
        // including the last (the change happened in them). So `logged_days` can never exceed `days`.
        $start = $from['at']->addDay()->toDateString();
        $end = $to['at']->toDateString();

        $adherence = $this->adherence->summary($subscriber, $start, $end);

        $logs = $subscriber->mealLogs()->reorder()
            ->loggedBetween($start, $end)
            ->with('food:id,sodium_mg_per_100g')->get(['id', 'food_id', 'logged_at']);

        $sodiumLimit = (float) config('nutrition.high_sodium_mg_per_100g');
        $highSodium = $logs->filter(fn ($log) => $log->food?->sodium_mg_per_100g !== null && (float) $log->food->sodium_mg_per_100g >= $sodiumLimit)->count();
        $loggedDays = $logs->map(fn ($log) => ClinicDay::ymdOf($log->logged_at))->unique()->count();

        $adherencePercent = $adherence['adherence_percent'] === null ? null : round((float) $adherence['adherence_percent'], 1);

        $water = (abs($change) < (float) config('weight_insight.water_change_kg') && $days <= (int) config('weight_insight.water_max_days'))
            || $highSodium >= (int) config('weight_insight.water_high_sodium_logs');

        return [
            'from_date' => $from['at']->toDateString(),
            'to_date' => $to['at']->toDateString(),
            'facts' => [
                'change_kg' => $change,
                'days' => $days,
                'adherence_percent' => $adherencePercent,
                'logged_days' => $loggedDays,
                'off_plan_meals' => (int) $adherence['off_plan_logs'],
                'high_sodium_logs' => $highSodium,
                'checkins' => $this->checkinSignals($subscriber, $start, $end),
                'likely_water_fluctuation' => $water,
                'consistent_with_plan' => $this->directionMatchesGoal($subscriber, $change)
                    && $adherencePercent !== null && $adherencePercent >= (float) config('weight_insight.consistent_adherence_percent'),
            ],
        ];
    }

    /**
     * The cached insight for the latest reading pair; written (once) when there
     * is none yet, or when the pair or its change differs from what was cached.
     */
    public function ensure(Subscriber $subscriber): ?WeightInsight
    {
        $computed = $this->facts($subscriber);

        if ($computed === null) {
            return null;
        }

        $cached = WeightInsight::query()->where('subscriber_id', $subscriber->id)->whereDate('to_date', $computed['to_date'])->first();

        if ($cached !== null
            && $cached->from_date->toDateString() === $computed['from_date']
            && (float) ($cached->facts['change_kg'] ?? NAN) === (float) $computed['facts']['change_kg']) {
            return $cached;
        }

        return $this->store($subscriber, $computed);
    }

    /** A fresh wording for the same pair (the nutritionist asked for it). */
    public function regenerate(Subscriber $subscriber): ?WeightInsight
    {
        $computed = $this->facts($subscriber);

        return $computed === null ? null : $this->store($subscriber, $computed);
    }

    /**
     * What the patient gets: null while hidden or without a pair, and never
     * more than the change itself next to the text.
     *
     * @return array{from_date: string, to_date: string, change_kg: float, text_ar: string}|null
     */
    public function forPatient(Subscriber $subscriber): ?array
    {
        if (! $subscriber->show_weight_insight) {
            return null;
        }

        $insight = $this->ensure($subscriber);

        return $insight === null ? null : [
            'from_date' => $insight->from_date->toDateString(),
            'to_date' => $insight->to_date->toDateString(),
            'change_kg' => (float) $insight->facts['change_kg'],
            'text_ar' => $insight->text_ar,
        ];
    }

    /**
     * The nutritionist's view: the text, whether it is the automatic fallback,
     * and every fact it was built from.
     *
     * @return array<string, mixed>
     */
    public function present(WeightInsight $insight): array
    {
        return [
            'from_date' => $insight->from_date->toDateString(),
            'to_date' => $insight->to_date->toDateString(),
            'change_kg' => (float) $insight->facts['change_kg'],
            'text_ar' => $insight->text_ar,
            'is_fallback' => $insight->is_fallback,
            // Nutritionist only: why the fixed text was used (null when the model's was).
            'fallback_reason' => $insight->fallback_reason,
            'generated_at' => $insight->generated_at->toIso8601String(),
            'facts' => $insight->facts,
        ];
    }

    /** @param  array{from_date: string, to_date: string, facts: array<string, mixed>}  $computed */
    private function store(Subscriber $subscriber, array $computed): WeightInsight
    {
        [$text, $reason] = $this->words($computed['facts']);

        // whereDate: a date column may carry a time part on some drivers (SQLite), and the unique key must still find its row.
        $insight = WeightInsight::query()->where('subscriber_id', $subscriber->id)->whereDate('to_date', $computed['to_date'])->first()
            ?? new WeightInsight(['subscriber_id' => $subscriber->id, 'to_date' => $computed['to_date']]);

        $insight->fill([
            'from_date' => $computed['from_date'], 'facts' => $computed['facts'], 'text_ar' => $text, 'is_fallback' => $reason !== null, 'fallback_reason' => $reason, 'generated_at' => now(),
        ])->save();

        return $insight;
    }

    /**
     * The text to show and, when it is the fixed fallback, why (null = the model's own words).
     *
     * @param  array<string, mixed>  $facts
     * @return array{0: string, 1: string|null} text, fallback_reason
     */
    public function words(array $facts): array
    {
        if (! $this->llm->isConfigured()) {
            return $this->fallback($facts, 'not_configured');
        }

        try {
            $reply = $this->llm->chatJson([
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => json_encode($facts, JSON_UNESCAPED_UNICODE)],
            ], ['max_completion_tokens' => 600]);
        } catch (AiGenerationException $e) {
            // The message, never the facts.
            Log::warning('Weight insight: provider failed.', ['reason' => $e->getMessage()]);

            return $this->fallback($facts, 'provider_failed');
        }

        [$text, $reason] = $this->evaluate((string) ($reply['text'] ?? ''), $facts);

        return $text === null ? $this->fallback($facts, $reason) : [$text, null];
    }

    /**
     * The reply as it will be shown, or null when it must be thrown away.
     *
     * @param  array<string, mixed>  $facts
     */
    public function accepted(string $text, array $facts): ?string
    {
        return $this->evaluate($text, $facts)[0];
    }

    /**
     * Judges a reply: empty, too long, a number that is not in the facts, or a
     * blocklisted whole word. Returns the text to show, or null and the reason.
     *
     * @param  array<string, mixed>  $facts
     * @return array{0: string|null, 1: string|null} text, reason
     */
    public function evaluate(string $text, array $facts): array
    {
        $text = trim($text);

        if ($text === '') {
            return [null, 'empty'];
        }

        $closing = (string) config('weight_insight.closing_ar');
        if (! str_ends_with($text, $closing)) {
            $text = rtrim($text).' '.$closing;
        }

        if (mb_strlen($text) > (int) config('weight_insight.max_chars')) {
            return [null, 'rejected_length'];
        }

        if ($this->inventsNumbers($text, $facts)) {
            return [null, 'rejected_digit'];
        }

        $word = $this->blockedWord($text);
        if ($word !== null) {
            return [null, "rejected_blocklist:{$word}"];
        }

        return [$text, null];
    }

    /**
     * The first blocklisted word the text contains AS A WORD, counting the
     * prefixes and suffixes Arabic writes onto it:
     *
     * - prefixes, in this order: و / ف, then ب / ل / ك, then ال (or the
     *   contracted لل): "السكر", "للسكري", "بالسكري", "والضغط", "فالدواء", "كالدواء";
     * - pronoun suffixes: ك كم كن ه ها هم هن ي نا (كِ loses its vowel mark first):
     *   "دوائك", "مرضها", "علاجي", "ضغطك".
     *
     * A longer word that merely contains one ("مسكرات", "اضغط") is not a match.
     * Over-blocking ("ضغط الشغل") is accepted on purpose: the patient then gets
     * the fixed text. Spelling variants of alef, taa marbuta, the diacritics and
     * the hamza carriers are ignored, so "دواء" is found inside "دوائك".
     */
    private function blockedWord(string $text): ?string
    {
        $haystack = $this->bare($text);

        foreach ((array) config('weight_insight.blocklist') as $word) {
            $needle = preg_quote($this->bare($word), '/');

            $pattern = '/(?<![\p{L}\p{N}])(?:[وف])?(?:لل|[بلك]?(?:ال)?)'.$needle.'(?:كم|كن|ها|هم|هن|نا|ك|ه|ي)?(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $haystack) === 1) {
                return $word;
            }
        }

        return null;
    }

    /** Normalized spelling with the hamza carriers dropped (دواء / دوائك share a stem). */
    private function bare(string $text): string
    {
        return strtr((string) ArabicText::normalize($text), ['ء' => '', 'ئ' => '', 'ؤ' => '']);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{0: string, 1: string} text, reason
     */
    private function fallback(array $facts, ?string $reason): array
    {
        $reason ??= 'rejected';
        Log::info('Weight insight: fixed text used.', ['reason' => $reason]);

        return [$this->fallbackText($facts), $reason];
    }

    /** @param  array<string, mixed>  $facts */
    public function fallbackText(array $facts): string
    {
        $change = (float) $facts['change_kg'];
        $phrases = (array) config('weight_insight.change_phrase');

        $phrase = $change == 0.0
            ? $phrases['flat']
            : str_replace(':kg', number_format(abs($change), 1, '.', ''), $change < 0 ? $phrases['down'] : $phrases['up']);

        $key = match (true) {
            (bool) $facts['likely_water_fluctuation'] => 'water_fluctuation',
            (bool) $facts['consistent_with_plan'] => 'consistent',
            default => 'neutral',
        };

        return strtr((string) config("weight_insight.fallback.{$key}"), [':change' => $phrase, ':days' => ArabicCount::days((int) $facts['days'])]);
    }

    /**
     * Any digit sequence in the reply that is not one of the facts' own means
     * the model produced a figure: reject the whole reply rather than repair it.
     *
     * @param  array<string, mixed>  $facts
     */
    private function inventsNumbers(string $text, array $facts): bool
    {
        preg_match_all('/\d+/u', Digits::toLatin((string) json_encode($facts)), $allowed);
        preg_match_all('/\d+/u', Digits::toLatin($text), $found);

        return array_diff($found[0], $allowed[0]) !== [];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You write a short note for a patient of a nutritionist, in Arabic, explaining why their weight changed.

            You are given computed facts as JSON. Use ONLY these facts. Do not compute, estimate or add anything.
            Fields: change_kg (negative = lost weight), days (the period),  adherence_percent (share of logged meals that were on the plan),
            logged_days, off_plan_meals, high_sodium_logs, checkins (what the patient reported: appetite, sleep, main barriers; may be null),
            likely_water_fluctuation, consistent_with_plan.

            Reply as JSON: {"text": "..."}

            The text must be:
            - 2 to 3 short sentences in friendly Egyptian/Levantine-friendly Arabic, addressed to the patient ("وزنك", "أكلك");
            - based only on the facts; when likely_water_fluctuation is true, say that part of the change is normal water and salt fluctuation;
            - at most 350 characters, ending with exactly: وأخصائيتك بتتابع معك.

            Never give medical advice, never name a disease, medicine or diagnosis, never mention calories,
            never blame the patient, never promise a result. Use only numbers that appear in the facts.
            Write counts with correct Arabic agreement: يوم واحد، يومين، 5 أيام، 12 يومًا.
            PROMPT;
    }

    /**
     * The two readings the explanation is about: the latest, and the nearest one
     * at least `min_days` earlier, and no more than `max_days` before it.
     *
     * @return array{0: array{at: CarbonImmutable, kg: float}, 1: array{at: CarbonImmutable, kg: float}}|null from, to
     */
    private function window(Subscriber $subscriber): ?array
    {
        $readings = $subscriber->bodyCompositionReadings()->reorder()->whereNotNull('weight_kg')
            ->orderByDesc('recorded_at')->orderByDesc('id')->get(['id', 'recorded_at', 'weight_kg']);

        // One reading per day: the last one entered.
        $perDay = $readings->unique(fn ($r) => $r->recorded_at->toDateString())->values();

        if ($perDay->count() < 2) {
            return null;
        }

        $latest = $perDay->first();
        $to = ['at' => CarbonImmutable::parse($latest->recorded_at->toDateString()), 'kg' => (float) $latest->weight_kg];

        foreach ($perDay->slice(1) as $reading) {
            $at = CarbonImmutable::parse($reading->recorded_at->toDateString());
            $gap = (int) $at->diffInDays($to['at']);

            if ($gap < (int) config('weight_insight.min_days')) {
                continue;
            }

            return $gap > (int) config('weight_insight.max_days') ? null : [['at' => $at, 'kg' => (float) $reading->weight_kg], $to];
        }

        return null;
    }

    private function directionMatchesGoal(Subscriber $subscriber, float $change): bool
    {
        return match ($subscriber->patientGoal?->goal_type) {
            'weight_loss' => $change < 0,
            'weight_gain', 'muscle_gain' => $change > 0,
            'weight_maintenance' => abs($change) < (float) config('weight_insight.water_change_kg'),
            default => false,
        };
    }

    /**
     * What the patient said in the check-ins answered inside the window: the
     * latest appetite and sleep, and the (up to two) barriers named most often.
     * Fixed answer codes only; the free-text note is never read.
     *
     * @return array{appetite: ?string, sleep: ?string, barriers: list<string>}|null
     */
    private function checkinSignals(Subscriber $subscriber, string $from, string $to): ?array
    {
        $checkins = PatientCheckin::query()->where('subscriber_id', $subscriber->id)
            ->where('status', PatientCheckin::STATUS_SUBMITTED)
            ->whereBetween('submitted_at', [ClinicDay::startUtc($from), ClinicDay::endUtc($to)])
            ->orderByDesc('submitted_at')->orderByDesc('id')->get(['id', 'answers', 'submitted_at']);

        if ($checkins->isEmpty()) {
            return null;
        }

        $counts = [];
        foreach ($checkins as $checkin) {
            foreach ((array) ($checkin->answers['barriers'] ?? []) as $barrier) {
                if ($barrier !== 'none') {
                    $counts[$barrier] = ($counts[$barrier] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return [
            'appetite' => $checkins->first(fn ($c) => isset($c->answers['appetite']))?->answers['appetite'],
            'sleep' => $checkins->first(fn ($c) => isset($c->answers['sleep']))?->answers['sleep'],
            'barriers' => array_slice(array_keys($counts), 0, 2),
        ];
    }
}
