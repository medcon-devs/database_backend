<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $fillable = [
        'source_domain', 'external_reference', 'firstname', 'lastname', 'email', 'phone',
        'country', 'speciality', 'submission_type', 'status', 'matched_csv_data_id',
        'metadata', 'reviewed_by', 'reviewed_at', 'migrated_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'migrated_at' => 'datetime',
    ];

    public function matchedContact()
    {
        return $this->belongsTo(CSVData::class, 'matched_csv_data_id')->withTrashed();
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
