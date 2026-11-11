<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactBankDetail extends Model
{
    protected $fillable = [
        'contact_id', 'account_holder_name', 'bank_name', 'account_number', 'iban',
        'swift_bic', 'bank_country', 'currency', 'bank_address', 'beneficiary_address',
        'verification_status', 'updated_by', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'account_holder_name' => 'encrypted',
        'bank_name' => 'encrypted',
        'account_number' => 'encrypted',
        'iban' => 'encrypted',
        'swift_bic' => 'encrypted',
        'bank_country' => 'encrypted',
        'currency' => 'encrypted',
        'bank_address' => 'encrypted',
        'beneficiary_address' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    protected $hidden = ['account_number', 'iban'];

    public function contact()
    {
        return $this->belongsTo(CSVData::class, 'contact_id')->withTrashed();
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
