<?php

return [
    // Bearer token required by every /ksef/* route (these routes can move money-relevant data: send invoices, read invoices).
    // Unset = the routes answer 503 (fail closed). Use a long random value: php -r "echo bin2hex(random_bytes(32));"
    'api_token' => env('EXAMPLE_API_TOKEN'),
];
