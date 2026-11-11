<?php

return [
    'document_categories' => ['profile_photo', 'passport_id', 'medcon_contract', 'partner_contract', 'bank_document', 'other'],
    'sensitive_categories' => ['passport_id', 'bank_document'],
    'frontend_url' => env('FRONTEND_URL', env('MICROSOFT_FRONTEND_URL', 'http://localhost:3000')),
    'max_download_bytes' => (int) env('SPEAKER_MAX_DOWNLOAD_BYTES', 104857600),
];
