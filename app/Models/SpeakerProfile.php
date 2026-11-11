<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeakerProfile extends Model
{
    protected $fillable = ['contact_id', 'microsoft_tenant_id', 'professional_title', 'biography', 'status', 'profile_photo_file_id', 'created_by', 'updated_by'];

    public function contact() { return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed(); }
    public function profilePhoto() { return $this->belongsTo(ContactMediaFile::class, 'profile_photo_file_id'); }
    public function bankDetails() { return $this->hasOne(SpeakerBankDetail::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function updater() { return $this->belongsTo(User::class, 'updated_by'); }
    public function downloads() { return $this->hasMany(SpeakerDocumentDownload::class); }
}
