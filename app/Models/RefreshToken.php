<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    use HasFactory;

    // Specify the table name (if not using Laravel's default pluralization)
    protected $table = 'refresh_tokens';

    // Fields that are mass assignable
    protected $fillable = ['user_id', 'token', 'expires_at'];

    /**
     * Relationship: A refresh token belongs to a user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
