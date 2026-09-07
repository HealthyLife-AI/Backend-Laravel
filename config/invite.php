<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invite Link Lifetime (BR-3 / FR-02)
    |--------------------------------------------------------------------------
    |
    | How many days a client's invite link stays valid before it must be
    | reissued. Long enough that a nutritionist sharing it manually over
    | WhatsApp isn't racing a clock, short enough that a stale, unused
    | link isn't a standing liability.
    |
    */

    'ttl_days' => (int) env('INVITE_TOKEN_TTL_DAYS', 7),

];
