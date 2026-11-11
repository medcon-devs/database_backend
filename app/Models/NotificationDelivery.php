<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = ['message_mention_id', 'channel', 'status', 'attempts', 'sent_at', 'failed_at', 'failure_reason', 'provider_message_id'];
    protected $casts = ['sent_at' => 'datetime', 'failed_at' => 'datetime', 'attempts' => 'integer'];

    public function mention()
    {
        return $this->belongsTo(MessageMention::class, 'message_mention_id');
    }
}
