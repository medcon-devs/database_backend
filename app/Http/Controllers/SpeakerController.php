<?php

namespace App\Http\Controllers;

use App\Models\ContactMediaFile;
use App\Models\CSVData;
use App\Models\SpeakerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SpeakerController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $search = trim((string) ($input['search'] ?? ''));
        $speakers = SpeakerProfile::with(['contact:id,firstname,lastname,email,phone,country,speciality', 'profilePhoto:id,group_id,file_name,mime_type,format'])
            ->where(fn ($query) => $request->user()->microsoft_tenant_id === null ? $query->whereNull('microsoft_tenant_id') : $query->where('microsoft_tenant_id', $request->user()->microsoft_tenant_id))
            ->when(isset($input['status']), fn ($query) => $query->where('status', $input['status']))
            ->when($search !== '', fn ($query) => $query->whereHas('contact', fn ($contact) => $contact->where(fn ($nested) => $nested->where('firstname', 'like', "%{$search}%")->orWhere('lastname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('speciality', 'like', "%{$search}%"))))
            ->latest('id')->paginate((int) ($input['per_page'] ?? 25));
        return response()->json(['data' => $speakers->items(), 'pagination' => $this->pagination($speakers)]);
    }

    public function store(Request $request)
    {
        $input = $request->validate($this->profileRules());
        $contact = CSVData::with('user:id,microsoft_tenant_id')->findOrFail($input['contact_id']);
        if (strtolower((string) $contact->user?->microsoft_tenant_id) !== strtolower((string) $request->user()->microsoft_tenant_id)) {
            abort(404);
        }
        $speaker = DB::transaction(function () use ($input, $request, $contact) {
            $speaker = SpeakerProfile::create([...$input, 'microsoft_tenant_id' => $request->user()->microsoft_tenant_id, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $contact->update([
                'microsoft_tenant_id' => $request->user()->microsoft_tenant_id,
                'professional_title' => $input['professional_title'] ?? null,
                'biography' => $input['biography'] ?? null,
                'status' => $input['status'] ?? 'active',
                'profile_photo_file_id' => $input['profile_photo_file_id'] ?? null,
                'profile_created_by' => $request->user()->id,
                'profile_updated_by' => $request->user()->id,
            ]);

            return $speaker;
        });
        return response()->json(['data' => $speaker->load('contact'), 'message' => 'Speaker created successfully.'], 201);
    }

    public function show(SpeakerProfile $speaker)
    {
        return response()->json(['data' => $speaker->load(['contact', 'profilePhoto:id,group_id,file_name,mime_type,format,file_size'])]);
    }

    public function update(Request $request, SpeakerProfile $speaker)
    {
        $input = $request->validate($this->profileRules($speaker));
        if (isset($input['profile_photo_file_id'])) {
            $valid = ContactMediaFile::whereKey($input['profile_photo_file_id'])->whereHas('group', fn ($group) => $group->where('contact_id', $speaker->contact_id)->where('category', 'profile_photo'))->exists();
            if (! $valid) {
                return response()->json(['message' => 'The selected profile photo does not belong to this speaker.', 'errors' => ['profile_photo_file_id' => ['Select a profile-photo file belonging to this speaker.']]], 422);
            }
        }
        DB::transaction(function () use ($speaker, $input, $request) {
            $speaker->update([...$input, 'updated_by' => $request->user()->id]);
            $speaker->contact->update([
                ...array_intersect_key($input, array_flip(['professional_title', 'biography', 'profile_photo_file_id'])),
                'profile_updated_by' => $request->user()->id,
            ]);
        });
        return response()->json(['data' => $speaker->fresh()->load('contact'), 'message' => 'Speaker updated successfully.']);
    }

    public function updateStatus(Request $request, SpeakerProfile $speaker)
    {
        $input = $request->validate(['status' => ['required', Rule::in(['active', 'inactive', 'archived'])]]);
        DB::transaction(function () use ($speaker, $request, $input) {
            $contact = $speaker->contact;
            if ($input['status'] === 'archived') {
                $contact->update([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'archived_by' => $request->user()->id,
                    'profile_updated_by' => $request->user()->id,
                ]);
                if (! $contact->trashed()) {
                    $contact->delete();
                }
            } else {
                if ($contact->trashed()) {
                    $contact->restore();
                }
                $contact->update([
                    'status' => $input['status'],
                    'archived_at' => null,
                    'archived_by' => null,
                    'profile_updated_by' => $request->user()->id,
                ]);
            }
            if ($input['status'] !== 'archived' && $contact->trashed()) {
                $contact->restore();
            }
            $speaker->update(['status' => $input['status'], 'updated_by' => $request->user()->id]);
        });
        return response()->json(['data' => $speaker->fresh(), 'message' => 'Speaker status updated successfully.']);
    }

    public function bankDetails(Request $request, SpeakerProfile $speaker, ContactBankDetailController $contacts)
    {
        return $contacts->show($request, $speaker->contact_id);
    }

    public function updateBankDetails(Request $request, SpeakerProfile $speaker, ContactBankDetailController $contacts)
    {
        return $contacts->update($request, $speaker->contact_id);
    }

    private function profileRules(?SpeakerProfile $speaker = null): array
    {
        return [
            ...($speaker ? [] : ['contact_id' => ['required', 'integer', Rule::exists('csv_data', 'id'), Rule::unique('speaker_profiles', 'contact_id')]]),
            'professional_title' => ['nullable', 'string', 'max:255'], 'biography' => ['nullable', 'string', 'max:20000'],
            ...($speaker ? [] : ['status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])]]),
            'profile_photo_file_id' => ['nullable', 'integer'],
        ];
    }

    private function pagination($paginator): array { return ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]; }
}
