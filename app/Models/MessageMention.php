<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageMention extends Model
{
    protected $fillable = ['message_id', 'mentioned_user_id', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    public function message()
    {
        return $this->belongsTo(MentionMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    public function deliveries()
    {
        return $this->hasMany(NotificationDelivery::class, 'message_mention_id');
    }
}
