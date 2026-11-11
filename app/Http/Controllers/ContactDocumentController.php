<?php

namespace App\Http\Controllers;

use App\Jobs\SendContactDownloadNotification;
use App\Models\ContactDocumentDownload;
use App\Models\ContactMediaFile;
use App\Models\CSVData;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class ContactDocumentController extends Controller
{
    public function index(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $input = $request->validate(['category' => ['nullable', Rule::in(config('contacts.document_categories'))]]);
        $groups = $model->mediaGroups()
            ->where('sensitivity', '!=', 'normal')
            ->with('files')
            ->when(isset($input['category']), fn ($query) => $query->where('category', $input['category']))
            ->latest('id')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function uploadSignature(Request $request, int $contact, CloudinaryUploadService $cloudinary)
    {
        return response()->json(['data' => $cloudinary->contactMediaSignature($this->contact($request, $contact))]);
    }

    public function store(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(config('contacts.document_categories'))],
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*.file_url' => ['required', 'url:https', 'max:4000'],
            'files.*.cloudinary_public_id' => ['required', 'string', 'max:500'],
            'files.*.file_name' => ['nullable', 'string', 'max:255'],
            'files.*.resource_type' => ['required', Rule::in(['image', 'video', 'raw'])],
            'files.*.format' => ['nullable', 'string', 'max:30'],
            'files.*.mime_type' => ['nullable', 'string', 'max:150'],
            'files.*.file_size' => ['nullable', 'integer', 'between:0,'.config('contacts.max_download_bytes')],
        ]);
        $base = trim((string) config('services.cloudinary.base_folder', 'database'), '/ ') ?: 'database';
        $folder = $base.'/contacts/'.$model->id.'/';
        foreach ($input['files'] as $file) {
            if (parse_url($file['file_url'], PHP_URL_HOST) !== 'res.cloudinary.com'
                || ! str_starts_with($file['cloudinary_public_id'], $folder)) {
                return response()->json([
                    'message' => 'One or more uploaded files do not belong to this contact.',
                    'errors' => ['files' => ['Use the upload signature issued for this contact.']],
                ], 422);
            }
        }

        $sensitive = in_array($input['category'], config('contacts.sensitive_categories'), true);
        $group = DB::transaction(function () use ($model, $request, $input, $sensitive) {
            $group = $model->mediaGroups()->create([
                'title' => $input['title'],
                'note' => $input['note'] ?? null,
                'category' => $input['category'],
                'sensitivity' => $sensitive ? 'restricted' : 'confidential',
                'created_by' => $request->user()->id,
            ]);
            $group->files()->createMany($input['files']);

            return $group->fresh('files');
        });

        return response()->json(['data' => $group, 'message' => 'Contact documents uploaded successfully.'], 201);
    }

    public function download(Request $request, int $contact, ContactMediaFile $file)
    {
        $model = $this->contact($request, $contact);
        $file->load('group');
        abort_unless($file->group && $file->group->contact_id === $model->id && $file->group->sensitivity !== 'normal', 404);
        $category = $file->group->category;

        if ($category === 'passport_id' && ! $request->user()->hasAnyPermission(['contact_identity_documents.download', 'speaker_identity_documents.download'])) {
            return $this->forbidden('Additional permission is required to download this restricted document.');
        }
        if ($category === 'bank_document' && ! $request->user()->hasAnyPermission(['contact_bank_details.view', 'speaker_bank_details.view'])) {
            return $this->forbidden('Bank-details permission is required to download this document.');
        }

        $upstream = Http::timeout(30)->withOptions(['stream' => true])->get($file->getRawOriginal('file_url'));
        if (! $upstream->successful()) {
            throw new RuntimeException('The document storage provider could not supply this file.');
        }

        $download = ContactDocumentDownload::create([
            'contact_id' => $model->id,
            'contact_media_file_id' => $file->id,
            'downloaded_by' => $request->user()->id,
            'category' => $category,
            'ip_address_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'user_agent_hash' => $request->userAgent() ? hash('sha256', $request->userAgent()) : null,
            'downloaded_at' => now(),
            'notification_queued_at' => in_array($category, config('contacts.sensitive_categories'), true) ? now() : null,
        ]);
        if ($download->notification_queued_at) {
            try {
                SendContactDownloadNotification::dispatch($download->id)->onQueue('notifications');
            } catch (\Throwable $exception) {
                Log::error('Contact download notification could not be queued.', [
                    'download_id' => $download->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $body = $upstream->toPsrResponse()->getBody();
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file->file_name ?: 'contact-document');

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
            }
        }, $name, ['Content-Type' => $file->mime_type ?: 'application/octet-stream']);
    }

    public function destroy(Request $request, int $contact, ContactMediaFile $file)
    {
        $model = $this->contact($request, $contact);
        $file->load('group');
        abort_unless($file->group && $file->group->contact_id === $model->id && $file->group->sensitivity !== 'normal', 404);

        DB::transaction(function () use ($model, $file, $request) {
            if ($model->profile_photo_file_id === $file->id) {
                $model->update(['profile_photo_file_id' => null, 'profile_updated_by' => $request->user()->id]);
            }
            $file->update(['deleted_via_group' => false]);
            $file->delete();
            DB::table('contact_media_group_activities')->insert([
                'group_id' => $file->group_id,
                'contact_id' => $model->id,
                'user_id' => $request->user()->id,
                'action' => 'file_deleted',
                'before' => json_encode([
                    'file_id' => $file->id,
                    'category' => $file->group->category,
                    'file_name' => $file->file_name,
                ]),
                'after' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'data' => ['deleted_file_id' => $file->id],
            'message' => 'Contact document deleted successfully.',
        ]);
    }

    public function activity(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $rows = $model->documentDownloads()
            ->with(['user:id,name,email', 'file:id,file_name,mime_type'])
            ->latest('downloaded_at')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => $rows->items(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    private function contact(Request $request, int $id): CSVData
    {
        return CSVData::withTrashed()->forTenant($request->user()->microsoft_tenant_id)->findOrFail($id);
    }

    private function forbidden(string $message)
    {
        return response()->json([
            'data' => null,
            'status_code' => 403,
            'message' => $message,
            'status' => false,
        ], 403);
    }
}
