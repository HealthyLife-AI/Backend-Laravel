<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-17: one patient's acceptance of one version of the privacy policy.
 * The text of the policy is not stored here (it lives at the configured
 * policy URL); this is the record of who agreed to which version, when,
 * and from where.
 */
#[Fillable(['user_id', 'version', 'accepted_at', 'ip_address', 'user_agent'])]
class PatientConsent extends Model
{
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
