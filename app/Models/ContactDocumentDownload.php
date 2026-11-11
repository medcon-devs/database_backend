<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactDocumentDownload extends Model
{
    protected $fillable = [
        'contact_id', 'contact_media_file_id', 'downloaded_by', 'category',
        'ip_address_hash', 'user_agent_hash', 'downloaded_at', 'notification_queued_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'notification_queued_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed();
    }

    public function file()
    {
        return $this->belongsTo(ContactMediaFile::class, 'contact_media_file_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }
}
