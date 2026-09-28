<?php

namespace App\Exceptions\Auth;

use Exception;

/**
 * Thrown when a patient whose nutritionist ended follow-up (archived)
 * tries to log in or use the API. Rendered as 403 `follow_up_ended`
 * (bootstrap/app.php, API_CONTRACT.md) so the patient app can show a
 * specific message instead of a generic auth error.
 */
class FollowUpEndedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Your nutritionist has ended follow-up. Contact them to resume.');
    }
}
