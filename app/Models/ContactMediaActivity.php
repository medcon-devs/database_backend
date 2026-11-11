<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMediaActivity extends Model
{
    protected $fillable = ['contact_media_id', 'contact_id', 'user_id', 'action', 'before', 'after'];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
