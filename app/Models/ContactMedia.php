<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactMedia extends Model
{
    use SoftDeletes;

    protected $table = 'contact_media';

    protected $fillable = [
        'contact_id', 'title', 'note', 'file_url', 'cloudinary_public_id', 'file_name',
        'resource_type', 'format', 'mime_type', 'file_size', 'uploaded_by',
    ];

    protected $casts = ['file_size' => 'integer'];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activities()
    {
        return $this->hasMany(ContactMediaActivity::class);
    }
}
