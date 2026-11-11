<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickyNote extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'contact_id', 'media_group_id', 'content', 'idempotency_key_hash', 'status',
        'archive_due_at', 'archived_at', 'archived_by', 'created_by', 'updated_by',
    ];

    protected $hidden = ['idempotency_key_hash'];

    protected $casts = [
        'archive_due_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed();
    }

    public function mediaGroup()
    {
        return $this->belongsTo(ContactMediaGroup::class, 'media_group_id')->withTrashed();
    }

    public function mentions()
    {
        return $this->hasMany(StickyNoteMention::class, 'sticky_note_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function archiver()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
