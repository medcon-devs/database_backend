<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Searchable;

class CSVData extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    // Specify the table name if different from 'csv_data'
    protected $table = 'csv_data';

    // Define fillable fields for mass assignment
    protected $fillable = [
        'firstname', 'lastname', 'email', 'phone', 'country', 'speciality', 'user_id',
        'microsoft_tenant_id', 'professional_title', 'biography', 'status',
        'profile_photo_file_id', 'archived_at', 'archived_by', 'profile_created_by',
        'profile_updated_by',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function toSearchableArray(): array
    {
        return array_filter([
            'id' => (string) $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'speciality' => $this->speciality,
            'user_id' => (int) $this->user_id,
            'created_at' => $this->created_at?->timestamp ?? 0,
        ], static fn ($value) => $value !== null);
    }

    public function typesenseSearchParameters(): array
    {
        return [
            'query_by_weights' => '10,10,8,6,5,3',
            'num_typos' => '1,1,1,1,1,1',
            'min_len_1typo' => 3,
            'min_len_2typo' => 7,
            'prioritize_exact_match' => true,
        ];
    }

    // Disable timestamps if not required (optional)
    // public $timestamps = false;

    /**
     * Relationship to the User model
     * This links each CSV data entry to the user who uploaded it
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'csv_data_tag', 'csv_data_id', 'tag_id')
            ->withTimestamps();
    }

    public function whatsAppMessages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'csv_data_id');
    }

    public function whatsAppConsents()
    {
        return $this->hasMany(WhatsAppConsent::class, 'csv_data_id');
    }

    public function media()
    {
        return $this->hasMany(ContactMedia::class, 'contact_id');
    }

    public function mediaGroups()
    {
        return $this->hasMany(ContactMediaGroup::class, 'contact_id');
    }

    public function profilePhoto()
    {
        return $this->belongsTo(ContactMediaFile::class, 'profile_photo_file_id');
    }

    public function bankDetails()
    {
        return $this->hasOne(ContactBankDetail::class, 'contact_id');
    }

    public function documentDownloads()
    {
        return $this->hasMany(ContactDocumentDownload::class, 'contact_id');
    }

    public function stickyNotes()
    {
        return $this->hasMany(StickyNote::class, 'contact_id');
    }

    public function scopeForTenant(Builder $query, ?string $tenantId): Builder
    {
        return $tenantId === null
            ? $query->whereNull('microsoft_tenant_id')
            : $query->where('microsoft_tenant_id', $tenantId);
    }

    public function speakerProfile()
    {
        return $this->hasOne(SpeakerProfile::class, 'contact_id');
    }
}
