<?php

return [
    'frontend_url' => env('FRONTEND_URL', env('MICROSOFT_FRONTEND_URL', 'http://localhost:3000')),
    'max_message_length' => 5000,
    'per_page' => 20,
    'max_per_page' => 50,
];
