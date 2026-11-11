<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConsent extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_consents';

    public const STATUS_PENDING = 'pending';

    public const STATUS_OPTED_IN = 'opted_in';

    public const STATUS_OPTED_OUT = 'opted_out';

    protected $fillable = [
        'csv_data_id',
        'phone',
        'status',
        'scope',
        'source',
        'source_domain',
        'consent_text',
        'consent_version',
        'evidence_reference',
        'opted_in_at',
        'opted_out_at',
    ];

    protected $casts = [
        'opted_in_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'csv_data_id')->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(WhatsAppConsentEvent::class, 'whatsapp_consent_id');
    }
}
