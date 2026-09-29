<?php

namespace App\Services\Clients;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues a nutritionist's next patient code (`PT-101`, `PT-102`, …).
 *
 * The number comes from `users.client_code_counter`, read under a row lock
 * (`FOR UPDATE`) and then incremented, so two patients added at the same
 * moment are serialized and cannot receive the same code. The counter never
 * goes down: deleting the highest-numbered patient does not free its number,
 * so a code is never reused (BR-21). That also keeps an account-deletion notice
 * (BR-18), which names only the code, unambiguous.
 *
 * Must run inside the transaction that inserts the patient: the lock lasts
 * until that transaction ends, and a rollback also rolls the counter back —
 * safe, because the number was never assigned to anyone.
 */
class ClientCodeAllocator
{
    public function next(User $nutritionist): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ClientCodeAllocator::next() must run inside the transaction that creates the patient.');
        }

        $number = (int) DB::table('users')->where('id', $nutritionist->id)->lockForUpdate()->value('client_code_counter');

        do {
            $number++;
            $code = sprintf('PT-%03d', $number);
            // Safety net for codes written outside this allocator (imports,
            // manual inserts): never hand out one that is already in use.
        } while (Subscriber::withoutGlobalScopes()
            ->where('nutritionist_id', $nutritionist->id)
            ->where('code', $code)
            ->exists());

        DB::table('users')->where('id', $nutritionist->id)->update(['client_code_counter' => $number]);

        return $code;
    }
}
