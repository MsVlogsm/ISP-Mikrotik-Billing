<?php

return [
    // Native app tokens expire independently of Sanctum's global default.
    'token_expiration_days' => max(1, (int) env('MOBILE_API_TOKEN_EXPIRATION_DAYS', 30)),
    'device_name_prefix' => 'android:',
    'login_per_minute' => max(1, (int) env('MOBILE_API_LOGIN_PER_MINUTE', 10)),
];
