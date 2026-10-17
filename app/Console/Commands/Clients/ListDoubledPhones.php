<?php

namespace App\Console\Commands\Clients;

use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: lists patients whose stored phone repeats its country code
 * ("+970970599123456"), so a person can decide what, if anything, to fix.
 *
 * It never writes. Plain SELECTs through the query builder — no model, so
 * no event, observer or global scope can turn a look into a change — and
 * there is deliberately no --fix flag. The "reads as" column is only what
 * the number would be with one copy removed, printed for the reader.
 */
class ListDoubledPhones extends Command
{
    protected $signature = 'phones:list-doubled';

    protected $description = 'List patients whose stored phone has a doubled country code (read-only; changes nothing)';

    public function handle(): int
    {
        $rows = [];

        DB::table('subscribers')
            ->join('users', 'users.id', '=', 'subscribers.user_id')
            ->whereNotNull('users.phone')
            ->orderBy('subscribers.id')
            ->select('subscribers.id', 'subscribers.code', 'subscribers.nutritionist_id', 'users.phone')
            ->chunkById(500, function ($chunk) use (&$rows) {
                foreach ($chunk as $row) {
                    $code = Phone::doubledCountryCode(Phone::clean((string) $row->phone));

                    if ($code === null) {
                        continue;
                    }

                    $rows[] = [
                        $row->id,
                        $row->code,
                        $row->nutritionist_id,
                        $row->phone,
                        '+'.$code.substr(Phone::clean((string) $row->phone), 1 + strlen($code) * 2),
                    ];
                }
            }, 'subscribers.id', 'id');

        if ($rows === []) {
            $this->info('No stored phone has a doubled country code.');

            return self::SUCCESS;
        }

        $this->table(['subscriber', 'code', 'nutritionist', 'stored phone', 'reads as (not applied)'], $rows);
        $this->line(count($rows).' found. Nothing was changed.');

        return self::SUCCESS;
    }
}
