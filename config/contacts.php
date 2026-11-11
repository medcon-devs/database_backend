<?php

return [
    'document_categories' => ['profile_photo', 'passport_id', 'medcon_contract', 'partner_contract', 'bank_document', 'other'],
    'sensitive_categories' => ['passport_id', 'bank_document'],
    'max_download_bytes' => (int) env('CONTACT_MAX_DOWNLOAD_BYTES', env('SPEAKER_MAX_DOWNLOAD_BYTES', 104857600)),
];
