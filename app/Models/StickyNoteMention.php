<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickyNoteMention extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sticky_note_id', 'mentioned_user_id', 'email_status', 'email_attempts',
        'email_sent_at', 'email_failed_at', 'email_failure_reason', 'read_at',
    ];

    protected $casts = [
        'email_attempts' => 'integer',
        'email_sent_at' => 'datetime',
        'email_failed_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function note()
    {
        return $this->belongsTo(StickyNote::class, 'sticky_note_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
