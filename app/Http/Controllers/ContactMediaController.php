<?php

namespace App\Http\Controllers;

use App\Helper\_EmailHelper;
use App\Models\ContactMedia;
use App\Models\ContactMediaActivity;
use App\Models\CSVData;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactMediaController extends Controller
{
    public function index(Request $request, CSVData $contact)
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $media = $contact->media()->with('uploader:id,name,email')
            ->when(filled($input['search'] ?? null), function ($query) use ($input) {
                $search = '%'.trim($input['search']).'%';
                $query->where(fn ($nested) => $nested->where('title', 'like', $search)
                    ->orWhere('note', 'like', $search)
                    ->orWhere('file_name', 'like', $search));
            })
            ->latest('id')->paginate((int) ($input['per_page'] ?? 25));

        return response()->json([
            'data' => $media->items(),
            'pagination' => [
                'current_page' => $media->currentPage(), 'per_page' => $media->perPage(),
                'total' => $media->total(), 'last_page' => $media->lastPage(),
            ],
            'status_code' => 200,
            'message' => 'Contact media retrieved successfully.',
            'status' => true,
        ]);
    }

    public function cloudinarySignature(Request $request, CSVData $contact, CloudinaryUploadService $cloudinary)
    {
        if ($response = $this->requireAccess($request, ['RW', 'RWE', 'RWED', 'FULL'], 'upload contact media')) {
            return $response;
        }

        return response()->json([
            'data' => $cloudinary->contactMediaSignature($contact),
            'status_code' => 200,
            'message' => 'Cloudinary contact-media upload signature created successfully.',
            'status' => true,
        ]);
    }

    public function store(Request $request, CSVData $contact)
    {
        if ($response = $this->requireAccess($request, ['RW', 'RWE', 'RWED', 'FULL'], 'add contact media')) {
            return $response;
        }

        $input = $request->validate($this->rules($contact, true));
        $media = DB::transaction(function () use ($input, $contact, $request) {
            $media = $contact->media()->create([...$input, 'uploaded_by' => $request->user()->id]);
            ContactMediaActivity::create([
                'contact_media_id' => $media->id,
                'contact_id' => $contact->id,
                'user_id' => $request->user()->id,
                'action' => 'created',
                'after' => $this->snapshot($media),
            ]);

            return $media;
        });
        $this->notify('created', $media, $contact, $request);

        return response()->json([
            'data' => $media->load('uploader:id,name,email'),
            'status_code' => 201,
            'message' => 'Contact media created successfully.',
            'status' => true,
        ], 201);
    }

    public function update(Request $request, CSVData $contact, ContactMedia $media)
    {
        if ($response = $this->requireAccess($request, ['RWE', 'RWED', 'FULL'], 'edit contact media')) {
            return $response;
        }
        $this->ensureBelongsToContact($media, $contact);
        $input = $request->validate($this->rules($contact, false));
        $before = $this->snapshot($media);
        $oldAsset = null;

        DB::transaction(function () use ($input, $media, $contact, $request, $before, &$oldAsset) {
            if (isset($input['cloudinary_public_id']) && $input['cloudinary_public_id'] !== $media->cloudinary_public_id) {
                $oldAsset = [
                    'cloudinary_public_id' => $media->cloudinary_public_id,
                    'resource_type' => $media->resource_type,
                ];
            }
            $media->update($input);
            ContactMediaActivity::create([
                'contact_media_id' => $media->id,
                'contact_id' => $contact->id,
                'user_id' => $request->user()->id,
                'action' => 'updated',
                'before' => $before,
                'after' => $this->snapshot($media->fresh()),
            ]);
        });
        $this->notify('updated', $media->fresh(), $contact, $request);

        return response()->json([
            'data' => [
                'media' => $media->fresh()->load('uploader:id,name,email'),
                'replaced_asset' => $oldAsset,
            ],
            'status_code' => 200,
            'message' => 'Contact media updated successfully.',
            'status' => true,
        ]);
    }

    public function destroy(Request $request, CSVData $contact, ContactMedia $media)
    {
        if ($response = $this->requireAccess($request, ['RWED', 'FULL'], 'delete contact media')) {
            return $response;
        }
        $this->ensureBelongsToContact($media, $contact);
        $snapshot = $this->snapshot($media);

        DB::transaction(function () use ($media, $contact, $request, $snapshot) {
            ContactMediaActivity::create([
                'contact_media_id' => $media->id,
                'contact_id' => $contact->id,
                'user_id' => $request->user()->id,
                'action' => 'deleted',
                'before' => $snapshot,
            ]);
            $media->delete();
        });
        $this->notify('deleted', $media, $contact, $request);

        return response()->json([
            'data' => [
                'deleted_media_id' => $media->id,
                'cloudinary_asset' => [
                    'cloudinary_public_id' => $media->cloudinary_public_id,
                    'resource_type' => $media->resource_type,
                ],
            ],
            'status_code' => 200,
            'message' => 'Contact media deleted successfully.',
            'status' => true,
        ]);
    }

    private function rules(CSVData $contact, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $base = trim((string) config('services.cloudinary.base_folder', 'database'), '/ ');
        $folder = ($base === '' ? 'database' : $base).'/contacts/'.$contact->id.'/';
        $cloudName = (string) config('services.cloudinary.cloud_name');

        return [
            'title' => [$required, 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'file_url' => [$required, 'url:https', 'max:4000', function ($attribute, $value, $fail) use ($cloudName) {
                $expectedHost = $cloudName !== '' ? 'res.cloudinary.com' : null;
                if ($expectedHost && parse_url((string) $value, PHP_URL_HOST) !== $expectedHost) {
                    $fail('The file URL must be a Cloudinary secure URL.');
                }
            }],
            'cloudinary_public_id' => [$required, 'string', 'max:500', function ($attribute, $value, $fail) use ($folder) {
                if (! str_starts_with((string) $value, $folder)) {
                    $fail('The Cloudinary public ID does not belong to this contact folder.');
                }
            }],
            'file_name' => ['nullable', 'string', 'max:255'],
            'resource_type' => ['nullable', 'string', 'in:image,video,raw'],
            'format' => ['nullable', 'string', 'max:30'],
            'mime_type' => ['nullable', 'string', 'max:150'],
            'file_size' => ['nullable', 'integer', 'between:0,104857600'],
        ];
    }

    private function ensureBelongsToContact(ContactMedia $media, CSVData $contact): void
    {
        abort_unless($media->contact_id === $contact->id, 404);
    }

    private function snapshot(ContactMedia $media): array
    {
        return $media->only([
            'id', 'contact_id', 'title', 'note', 'file_url', 'cloudinary_public_id',
            'file_name', 'resource_type', 'format', 'mime_type', 'file_size', 'uploaded_by',
        ]);
    }

    private function notify(string $action, ContactMedia $media, CSVData $contact, Request $request): void
    {
        _EmailHelper::sendEmail([
            'action' => $action,
            'user_name' => $request->user()->name,
            'user_email' => $request->user()->email,
            'contact_name' => trim($contact->firstname.' '.$contact->lastname),
            'contact_email' => $contact->email,
            'media_title' => $media->title,
            'file_name' => $media->file_name,
            'note' => $media->note,
            'action_time' => now()->format('Y-m-d H:i:s'),
        ], 'emails.contact_media', null, 'Medcon Database contact file '.ucfirst($action));
    }

    private function requireAccess(Request $request, array $allowed, string $action)
    {
        return null;
    }
}
