<?php

namespace App\Http\Controllers;

use App\Models\ContactBankDetail;
use App\Models\CSVData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContactBankDetailController extends Controller
{
    public function show(Request $request, int $contact)
    {
        $details = $this->contact($request, $contact)->bankDetails;

        return response()->json(['data' => $details ? $this->payload($details) : null]);
    }

    public function update(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $input = $request->validate([
            'account_holder_name' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swift_bic' => ['nullable', 'string', 'max:50'],
            'bank_country' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'max:10'],
            'bank_address' => ['nullable', 'string', 'max:1000'],
            'beneficiary_address' => ['nullable', 'string', 'max:1000'],
            'verification_status' => ['sometimes', Rule::in(['unverified', 'pending', 'verified', 'rejected'])],
        ]);
        $existing = $model->bankDetails;
        $status = $input['verification_status'] ?? $existing?->verification_status ?? 'unverified';
        $becameVerified = $status === 'verified' && $existing?->verification_status !== 'verified';

        $details = DB::transaction(fn () => ContactBankDetail::updateOrCreate(['contact_id' => $model->id], [
            ...$input,
            'verification_status' => $status,
            'updated_by' => $request->user()->id,
            'verified_by' => $becameVerified ? $request->user()->id : ($status === 'verified' ? $existing?->verified_by : null),
            'verified_at' => $becameVerified ? now() : ($status === 'verified' ? $existing?->verified_at : null),
        ]));

        return response()->json([
            'data' => $this->payload($details),
            'message' => 'Contact bank details updated successfully.',
        ]);
    }

    private function contact(Request $request, int $id): CSVData
    {
        return CSVData::withTrashed()->forTenant($request->user()->microsoft_tenant_id)->findOrFail($id);
    }

    private function payload(ContactBankDetail $details): array
    {
        return collect([
            'id', 'contact_id', 'account_holder_name', 'bank_name', 'account_number',
            'iban', 'swift_bic', 'bank_country', 'currency', 'bank_address',
            'beneficiary_address', 'verification_status', 'updated_by', 'verified_by',
            'verified_at', 'updated_at',
        ])->mapWithKeys(fn ($field) => [$field => $details->{$field}])->all();
    }
}
