<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class CSVData extends Model
{
    use HasFactory, SoftDeletes;

    // Specify the table name if different from 'csv_data'
    protected $table = 'csv_data';

    // Define fillable fields for mass assignment
    protected $fillable = ['firstname', 'lastname', 'email', 'country', 'speciality', 'user_id'];

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
}
