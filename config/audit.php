<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit Log HMAC Key
    |--------------------------------------------------------------------------
    |
    | Used to compute the hash-chain entry_hash for every audit_log row
    | (spec §5.7). Must come from the environment — never hard-code a
    | fallback secret here, and never reuse APP_KEY, so a leaked APP_KEY
    | does not also compromise audit-log tamper detection.
    |
    */

    'hmac_key' => env('AUDIT_HMAC_SECRET'),

];
