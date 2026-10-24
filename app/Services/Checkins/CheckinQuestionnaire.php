<?php

namespace App\Services\Checkins;

use Illuminate\Validation\ValidationException;

/**
 * Phase 2, A2: the questionnaire defined in config/checkin.php. The only
 * place that reads it: the patient's question list, the server-side check of
 * a submission, the flag rules, and the Arabic text of an answer for the
 * nutritionist's history.
 */
class CheckinQuestionnaire
{
    public function version(): int
    {
        return (int) config('checkin.version');
    }

    /** @return list<array<string, mixed>> */
    public function questions(): array
    {
        return config('checkin.questions');
    }

    /**
     * What the patient's app renders: key, type, label, options and the
     * limits. Nothing about flagging.
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(): array
    {
        return array_map(fn (array $q) => [
            'key' => $q['key'],
            'type' => $q['type'],
            'label' => $q['label'],
            'options' => $q['options'] ?? [],
            'max' => $q['max'] ?? null,
            'required' => (bool) ($q['required'] ?? false),
        ], $this->questions());
    }

    /**
     * Checks a submission against the questions and returns the cleaned
     * answers (an empty note is dropped). Throws a 422 naming each problem
     * under `answers.<key>`.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function validate(array $answers): array
    {
        $errors = [];
        $clean = [];
        $known = array_column($this->questions(), 'key');

        foreach (array_diff(array_keys($answers), $known) as $unknown) {
            $errors["answers.{$unknown}"] = ['This question does not exist.'];
        }

        foreach ($this->questions() as $q) {
            $key = $q['key'];
            $value = $answers[$key] ?? null;
            $blank = $value === null || $value === '' || $value === [];

            if ($blank) {
                if ($q['required'] ?? false) {
                    $errors["answers.{$key}"] = ['This question needs an answer.'];
                }

                continue;
            }

            $problem = match ($q['type']) {
                'single' => $this->checkSingle($q, $value),
                'multi' => $this->checkMulti($q, $value),
                'text' => $this->checkText($q, $value),
            };

            if ($problem !== null) {
                $errors["answers.{$key}"] = [$problem];

                continue;
            }

            $clean[$key] = $q['type'] === 'text' ? trim($value) : $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** Whether these (validated) answers match any flag rule. @param  array<string, mixed>  $answers */
    public function flags(array $answers): bool
    {
        foreach ((array) config('checkin.flag_rules') as $key => $flagValues) {
            $given = (array) ($answers[$key] ?? []);

            if (array_intersect($given, $flagValues) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answers in the clinic's own words, for the nutritionist's history:
     * one line per answered question with the option labels, not the keys.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<array{key: string, label: string, value: string, flagged: bool}>
     */
    public function display(?array $answers): array
    {
        $rules = (array) config('checkin.flag_rules');
        $lines = [];

        foreach ($this->questions() as $q) {
            if (! isset($answers[$q['key']])) {
                continue;
            }

            $given = (array) $answers[$q['key']];
            $labels = array_column($q['options'] ?? [], 'label', 'value');

            $lines[] = [
                'key' => $q['key'],
                'label' => $q['label'],
                'value' => $q['type'] === 'text' ? (string) $answers[$q['key']] : implode('، ', array_map(fn ($v) => $labels[$v] ?? (string) $v, $given)),
                'flagged' => array_intersect($given, $rules[$q['key']] ?? []) !== [],
            ];
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $q */
    private function checkSingle(array $q, mixed $value): ?string
    {
        return is_string($value) && in_array($value, array_column($q['options'], 'value'), true) ? null : 'Choose one of the listed options.';
    }

    /** @param  array<string, mixed>  $q */
    private function checkMulti(array $q, mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'Send a list of options.';
        }

        $allowed = array_column($q['options'], 'value');

        foreach ($value as $v) {
            if (! is_string($v) || ! in_array($v, $allowed, true)) {
                return 'Choose only from the listed options.';
            }
        }

        if (count($value) !== count(array_unique($value))) {
            return 'Each option can be chosen once.';
        }

        if (count($value) > ($q['max'] ?? PHP_INT_MAX)) {
            return "Choose at most {$q['max']} options.";
        }

        if (in_array('none', $value, true) && count($value) > 1) {
            return '"none" cannot be combined with other options.';
        }

        return null;
    }

    /** @param  array<string, mixed>  $q */
    private function checkText(array $q, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Send text.';
        }

        return mb_strlen(trim($value)) > ($q['max'] ?? 280) ? "At most {$q['max']} characters." : null;
    }
}
