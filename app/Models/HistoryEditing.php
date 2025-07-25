<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoryEditing extends Model
{
    use HasFactory;

    // Define the table name if different from convention
    protected $table = 'history_editing';

    // Define fillable fields for mass assignment
    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'country',
        'speciality',
        'user_id',
        'data_id'
    ];

    /**
     * Relationship to the User model
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relationship to the CSVData model
     */
    public function csvData()
    {
        return $this->belongsTo(CSVData::class, 'data_id');
    }
}
