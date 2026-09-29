<?php

namespace App\Services\Clients;

use App\Models\PatientDeletionNotice;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

/**
 * The one way a patient is deleted, whoever asks: the nutritionist from the
 * dashboard (`DELETE /clients/{id}`) or the patient from the app
 * (`DELETE /me/account`, BR-18).
 *
 * Deleting the login account cascades, through foreign keys, to the
 * subscriber row and everything recorded about them: health profile,
 * readings, plans, meals, logs, alerts, AI summaries, invites, consent
 * records and refresh tokens (see AccountDeletionTest, which proves it
 * table by table). The few things that hang off the user WITHOUT a foreign
 * key are removed here explicitly: any password-reset token filed under
 * their e-mail, and any session row.
 *
 * Irreversible by design. `$noticeForNutritionist` is true only for a
 * patient-initiated deletion: the nutritionist who deleted a patient
 * themselves doesn't need telling.
 */
class ClientDeletionService
{
    public function delete(Subscriber $subscriber, bool $noticeForNutritionist = false): void
    {
        DB::transaction(function () use ($subscriber, $noticeForNutritionist) {
            $user = $subscriber->user()->first();

            if ($noticeForNutritionist) {
                PatientDeletionNotice::create([
                    'nutritionist_id' => $subscriber->nutritionist_id,
                    'patient_code' => $subscriber->code,
                    'deleted_at' => now(),
                ]);
            }

            if ($user === null) {
                $subscriber->delete();

                return;
            }

            if ($user->email !== null) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();
        });
    }
}
