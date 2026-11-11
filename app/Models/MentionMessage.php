<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MentionMessage extends Model
{
    protected $fillable = ['sender_id', 'context_type', 'context_id', 'message', 'idempotency_key_hash'];
    protected $hidden = ['idempotency_key_hash'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function mentions()
    {
        return $this->hasMany(MessageMention::class, 'message_id');
    }
}
