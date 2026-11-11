<?php

return [
    /*
     | Number of days a sticky note stays "active" from its creation (or last
     | manual restore) before it is automatically moved to "archived".
     */
    'active_days' => max(1, (int) env('STICKY_NOTE_ACTIVE_DAYS', 7)),

    /* Maximum number of users that can be mentioned on a single note. */
    'max_mentions' => 100,

    /* Maximum length of a sticky note body. */
    'max_content_length' => 10000,
];
