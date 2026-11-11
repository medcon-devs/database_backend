<?php

return [
    /* Number of days deleted media remains recoverable before Cloudinary purge. */
    'recovery_days' => max(1, (int) env('CONTACT_MEDIA_RECOVERY_DAYS', 30)),
];
