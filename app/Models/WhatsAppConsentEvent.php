<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppConsentEvent extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_consent_events';

    protected $fillable = [
        'whatsapp_consent_id',
        'whatsapp_message_record_id',
        'event',
        'source',
        'evidence_reference',
        'consent_text',
        'consent_version',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function consent()
    {
        return $this->belongsTo(WhatsAppConsent::class, 'whatsapp_consent_id');
    }

    public function message()
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_record_id');
    }
}
