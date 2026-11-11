<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaign extends Model
{
    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'name', 'template_name', 'template_language', 'template_category', 'components',
        'status', 'require_opt_in', 'recipient_count', 'excluded_no_phone_count',
        'excluded_duplicate_phone_count', 'excluded_no_consent_count', 'scheduled_at',
        'started_at', 'completed_at', 'created_by',
        'contact_ids', 'tag_ids',
    ];

    protected $casts = [
        'components' => 'array',
        'contact_ids' => 'array',
        'tag_ids' => 'array',
        'require_opt_in' => 'boolean',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function recipients()
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'whatsapp_campaign_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
