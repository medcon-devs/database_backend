<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'csv_data_id',
        'whatsapp_campaign_id',
        'whatsapp_campaign_recipient_id',
        'whatsapp_message_id',
        'reply_to_whatsapp_message_id',
        'phone',
        'direction',
        'type',
        'template_name',
        'template_language',
        'status',
        'content',
        'metadata',
        'error_code',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'received_at',
        'viewed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'failed_at' => 'datetime',
        'received_at' => 'datetime',
        'viewed_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'csv_data_id')->withTrashed();
    }

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id');
    }

    public function campaignRecipient()
    {
        return $this->belongsTo(WhatsAppCampaignRecipient::class, 'whatsapp_campaign_recipient_id');
    }

    public function contextMessage()
    {
        return $this->belongsTo(
            self::class,
            'reply_to_whatsapp_message_id',
            'whatsapp_message_id'
        );
    }
}
