<?php

namespace App\Exceptions\Auth;

use Exception;

/**
 * 403 `follow_up_ended`. `$details` is what the app's "follow-up ended"
 * screen shows (see FollowUpService::endedDetails()); rendered at the top
 * level of the error body in bootstrap/app.php.
 */
class FollowUpEndedException extends Exception
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly array $details = [])
    {
        parent::__construct('Your nutritionist has ended follow-up. Contact them to resume.');
    }
}
