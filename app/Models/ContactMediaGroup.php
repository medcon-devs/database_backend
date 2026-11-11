<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactMediaGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['contact_id', 'title', 'note', 'category', 'sensitivity', 'created_by'];

    public function files()
    {
        return $this->hasMany(ContactMediaFile::class, 'group_id');
    }

    public function filesWithTrashed()
    {
        return $this->hasMany(ContactMediaFile::class, 'group_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed();
    }
}
