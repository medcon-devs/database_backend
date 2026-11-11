<?php

namespace App\Http\Controllers;

use App\Models\ContactMediaFile;
use App\Models\CSVData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'archived', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $status = $input['status'] ?? 'active';
        $search = trim((string) ($input['search'] ?? ''));

        $query = CSVData::withTrashed()
            ->forTenant($request->user()->microsoft_tenant_id)
            ->with('profilePhoto:id,group_id,file_name,mime_type,format,file_size');

        if ($status === 'active') {
            $query->whereNull('deleted_at')->where('status', 'active');
        } elseif ($status === 'archived') {
            $query->where(fn ($nested) => $nested->where('status', 'archived')->orWhereNotNull('deleted_at'));
        } else {
            $query->whereNull('deleted_at')->where('status', 'inactive');
        }

        $contacts = $query
            ->when($search !== '', fn ($builder) => $builder->where(function ($nested) use ($search) {
                $like = "%{$search}%";
                $nested->where('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('speciality', 'like', $like);
            }))
            ->latest('id')
            ->paginate((int) ($input['per_page'] ?? 25));

        return response()->json([
            'data' => collect($contacts->items())->map(fn (CSVData $contact) => $this->payload($contact))->all(),
            'pagination' => $this->pagination($contacts),
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('csv_data', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:255'],
            'speciality' => ['required', 'string', 'max:255'],
            'professional_title' => ['nullable', 'string', 'max:255'],
            'biography' => ['nullable', 'string', 'max:20000'],
        ]);

        $contact = CSVData::create([
            ...$input,
            'user_id' => $request->user()->id,
            'microsoft_tenant_id' => $request->user()->microsoft_tenant_id,
            'status' => 'active',
            'profile_created_by' => $request->user()->id,
            'profile_updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->payload($contact),
            'message' => 'Contact created successfully.',
        ], 201);
    }

    public function show(Request $request, int $contact)
    {
        return response()->json(['data' => $this->payload($this->findContact($request, $contact))]);
    }

    public function update(Request $request, int $contact)
    {
        $model = $this->findContact($request, $contact);
        $input = $request->validate([
            'firstname' => ['sometimes', 'required', 'string', 'max:255'],
            'lastname' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('csv_data', 'email')->ignore($model->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'required', 'string', 'max:255'],
            'speciality' => ['sometimes', 'required', 'string', 'max:255'],
            'professional_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'profile_photo_file_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('profile_photo_file_id', $input) && $input['profile_photo_file_id'] !== null) {
            $valid = ContactMediaFile::whereKey($input['profile_photo_file_id'])
                ->whereHas('group', fn ($group) => $group->where('contact_id', $model->id)->where('category', 'profile_photo'))
                ->exists();
            if (! $valid) {
                return response()->json([
                    'message' => 'The selected profile photo does not belong to this contact.',
                    'errors' => ['profile_photo_file_id' => ['Select a profile-photo file belonging to this contact.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($model, $input, $request) {
            $model->update([...$input, 'profile_updated_by' => $request->user()->id]);
            if ($legacy = $model->speakerProfile) {
                $profileFields = array_intersect_key($input, array_flip(['professional_title', 'biography', 'profile_photo_file_id']));
                if ($profileFields !== []) {
                    $legacy->update([...$profileFields, 'updated_by' => $request->user()->id]);
                }
            }
        });

        return response()->json([
            'data' => $this->payload($model->fresh()),
            'message' => 'Contact updated successfully.',
        ]);
    }

    public function updateStatus(Request $request, int $contact)
    {
        $model = $this->findContact($request, $contact);
        $input = $request->validate(['status' => ['required', Rule::in(['active', 'archived'])]]);

        DB::transaction(function () use ($model, $input, $request) {
            if ($input['status'] === 'archived') {
                $model->forceFill([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'archived_by' => $request->user()->id,
                    'profile_updated_by' => $request->user()->id,
                ])->save();
                if (! $model->trashed()) {
                    $model->delete();
                }
            } else {
                if ($model->trashed()) {
                    $model->restore();
                }
                $model->forceFill([
                    'status' => 'active',
                    'archived_at' => null,
                    'archived_by' => null,
                    'profile_updated_by' => $request->user()->id,
                ])->save();
            }

            if ($legacy = $model->speakerProfile) {
                $legacy->update(['status' => $input['status'], 'updated_by' => $request->user()->id]);
            }
        });

        return response()->json([
            'data' => $this->payload($model->fresh()),
            'message' => $input['status'] === 'archived' ? 'Contact archived successfully.' : 'Contact reactivated successfully.',
        ]);
    }

    private function findContact(Request $request, int $id): CSVData
    {
        return CSVData::withTrashed()
            ->forTenant($request->user()->microsoft_tenant_id)
            ->with('profilePhoto:id,group_id,file_name,mime_type,format,file_size')
            ->findOrFail($id);
    }

    private function payload(CSVData $contact): array
    {
        return [
            'id' => $contact->id,
            'firstname' => $contact->firstname,
            'lastname' => $contact->lastname,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'country' => $contact->country,
            'speciality' => $contact->speciality,
            'professional_title' => $contact->professional_title,
            'biography' => $contact->biography,
            'status' => $contact->trashed() ? 'archived' : $contact->status,
            'profile_photo_file_id' => $contact->profile_photo_file_id,
            'profile_photo' => $contact->profilePhoto,
            'archived_at' => $contact->archived_at,
            'archived_by' => $contact->archived_by,
            'created_at' => $contact->created_at,
            'updated_at' => $contact->updated_at,
        ];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
