<?php

namespace App\Models;

use App\Support\ConditionCodes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A medical condition the nutritionist recorded: one of the listed codes, or "other" with text. */
#[Fillable(['subscriber_id', 'code', 'other_text', 'note', 'created_by'])]
class PatientCondition extends Model
{
    public function label(): string
    {
        return ConditionCodes::label($this->code, $this->other_text);
    }
}
