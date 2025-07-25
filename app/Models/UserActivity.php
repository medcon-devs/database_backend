<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model
{
    use HasFactory;
    protected $table = 'user_activity';
    protected $fillable = ['user_id', 'data_id', 'status', 'created_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
  
    public function csvData()
    {
        return $this->belongsTo(CSVData::class, 'data_id');
    }

    public function historyEditing()
    {
        return $this->belongsTo(HistoryEditing::class, 'history_editing_id');
    }
}
