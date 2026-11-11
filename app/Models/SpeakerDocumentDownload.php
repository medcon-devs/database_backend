<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeakerDocumentDownload extends Model
{
    protected $fillable = ['speaker_profile_id', 'contact_media_file_id', 'downloaded_by', 'category', 'ip_address_hash', 'user_agent_hash', 'downloaded_at', 'notification_queued_at'];
    protected $casts = ['downloaded_at' => 'datetime', 'notification_queued_at' => 'datetime'];

    public function speaker() { return $this->belongsTo(SpeakerProfile::class, 'speaker_profile_id'); }
    public function file() { return $this->belongsTo(ContactMediaFile::class, 'contact_media_file_id'); }
    public function user() { return $this->belongsTo(User::class, 'downloaded_by'); }
}
