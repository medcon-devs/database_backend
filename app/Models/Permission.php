<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $timestamps = false;
    protected $fillable = ['name', 'label', 'group', 'subgroup'];

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
