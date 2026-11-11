<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaignRecipient extends Model
{
    protected $table = 'whatsapp_campaign_recipients';

    protected $fillable = [
        'whatsapp_campaign_id', 'csv_data_id', 'phone', 'firstname', 'lastname', 'email', 'country', 'speciality',
        'components', 'status', 'whatsapp_message_record_id', 'response_count',
        'last_response_content', 'last_responded_at', 'error_code', 'error_message',
        'sent_at', 'delivered_at', 'read_at', 'failed_at',
    ];

    protected $casts = [
        'components' => 'array', 'last_responded_at' => 'datetime', 'sent_at' => 'datetime',
        'delivered_at' => 'datetime', 'read_at' => 'datetime', 'failed_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id');
    }

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'csv_data_id')->withTrashed();
    }

    public function message()
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_record_id');
    }
}
