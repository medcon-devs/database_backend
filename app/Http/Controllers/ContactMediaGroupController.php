<?php

namespace App\Http\Controllers;

use App\Helper\_EmailHelper;
use App\Models\ContactMediaFile;
use App\Models\ContactMediaGroup;
use App\Models\CSVData;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ContactMediaGroupController extends Controller
{
    public function index(Request $request, CSVData $contact)
    {
        $this->tenant($request, $contact);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $groups = $contact->mediaGroups()->with(['files', 'creator:id,name,email'])->withCount('files')
            ->when(! $request->user()->hasPermission('contact_documents.view'), fn ($query) => $query->where('sensitivity', 'normal'))
            ->when(filled($input['search'] ?? null), function ($query) use ($input) {
                $search = '%'.trim($input['search']).'%';
                $query->where(fn ($nested) => $nested->where('title', 'like', $search)
                    ->orWhere('note', 'like', $search)
                    ->orWhereHas('files', fn ($files) => $files->where('file_name', 'like', $search)));
            })->latest('id')->paginate((int) ($input['per_page'] ?? 25));

        return $this->success($groups->items(), 'Contact media groups retrieved successfully.', [
            'current_page' => $groups->currentPage(), 'per_page' => $groups->perPage(),
            'total' => $groups->total(), 'last_page' => $groups->lastPage(),
        ]);
    }

    public function deleted(Request $request, CSVData $contact)
    {
        $this->tenant($request, $contact);
        $input = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        $recoveryDays = $this->recoveryDays();

        $groups = ContactMediaGroup::onlyTrashed()
            ->where('contact_id', $contact->id)
            ->when(! $request->user()->hasPermission('contact_documents.view'), fn ($query) => $query->where('sensitivity', 'normal'))
            ->with(['filesWithTrashed', 'creator:id,name,email'])
            ->latest('deleted_at')
            ->get()
            ->map(function (ContactMediaGroup $group) use ($recoveryDays) {
                $files = $group->filesWithTrashed;

                return [
                    'type' => 'group',
                    'id' => $group->id,
                    'title' => $group->title,
                    'note' => $group->note,
                    'deleted_at' => $group->deleted_at?->toIso8601String(),
                    'recoverable_until' => $group->deleted_at?->copy()->addDays($recoveryDays)->toIso8601String(),
                    'restorable' => $this->isWithinRecoveryPeriod($group->deleted_at)
                        && $files->every(fn (ContactMediaFile $file) => $file->asset_purged_at === null),
                    'files_count' => $files->count(),
                    'files' => $files->map(fn (ContactMediaFile $file) => $this->deletedFilePayload($file))->values(),
                    'creator' => $group->creator,
                ];
            });

        $files = ContactMediaFile::onlyTrashed()
            ->where('deleted_via_group', false)
            ->whereHas('group', fn ($query) => $query->where('contact_id', $contact->id)
                ->when(! $request->user()->hasPermission('contact_documents.view'), fn ($group) => $group->where('sensitivity', 'normal')))
            ->with('group:id,contact_id,title')
            ->latest('deleted_at')
            ->get()
            ->map(fn (ContactMediaFile $file) => [
                'type' => 'file',
                ...$this->deletedFilePayload($file),
                'group' => ['id' => $file->group->id, 'title' => $file->group->title],
            ]);

        $items = $groups->concat($files)->sortByDesc('deleted_at')->values();
        $perPage = (int) ($input['per_page'] ?? 25);
        $page = max(1, (int) $request->input('page', 1));
        $pageItems = $items->forPage($page, $perPage)->values();

        return $this->success($pageItems, 'Deleted contact media retrieved successfully.', [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $items->count(),
            'last_page' => max(1, (int) ceil($items->count() / $perPage)),
        ]);
    }

    public function cloudinarySignature(Request $request, CSVData $contact, CloudinaryUploadService $cloudinary)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RW', 'RWE', 'RWED', 'FULL'], 'upload files')) {
            return $denied;
        }

        return $this->success($cloudinary->contactMediaSignature($contact), 'Cloudinary upload signature created successfully.');
    }

    public function store(Request $request, CSVData $contact)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RW', 'RWE', 'RWED', 'FULL'], 'create media groups')) {
            return $denied;
        }
        $input = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'files' => ['sometimes', 'array', 'max:50'],
            ...$this->fileRules($contact, 'files.*.'),
        ]);
        $group = DB::transaction(function () use ($input, $contact, $request) {
            $group = $contact->mediaGroups()->create(['title' => $input['title'], 'note' => $input['note'] ?? null, 'created_by' => $request->user()->id]);
            $group->files()->createMany($input['files'] ?? []);
            $this->activity($group, $request, 'created', null, $this->snapshot($group->fresh('files')));

            return $group->fresh(['files', 'creator:id,name,email'])->loadCount('files');
        });
        $this->notify($request, $contact, $group, 'created');

        return response()->json(['data' => $group, 'status_code' => 201, 'message' => 'Folder created successfully.', 'status' => true], 201);
    }

    public function update(Request $request, CSVData $contact, ContactMediaGroup $group)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWE', 'RWED', 'FULL'], 'edit media groups')) {
            return $denied;
        }
        $this->scope($contact, $group);
        $this->documentPermission($request, $group, 'contact_documents.upload');
        $input = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'note' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $before = $this->snapshot($group->load('files'));
        DB::transaction(function () use ($group, $input, $request, $before) {
            $group->update($input);
            $this->activity($group, $request, 'updated', $before, $this->snapshot($group->fresh('files')));
        });
        $group = $group->fresh(['files', 'creator:id,name,email'])->loadCount('files');
        $this->notify($request, $contact, $group, 'updated');

        return $this->success($group, 'Contact media group updated successfully.');
    }

    public function addFiles(Request $request, CSVData $contact, ContactMediaGroup $group)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RW', 'RWE', 'RWED', 'FULL'], 'add files')) {
            return $denied;
        }
        $this->scope($contact, $group);
        $this->documentPermission($request, $group, 'contact_documents.upload');
        $input = $request->validate(['files' => ['required', 'array', 'min:1', 'max:50'], ...$this->fileRules($contact, 'files.*.')]);
        DB::transaction(function () use ($group, $input, $request) {
            $group->files()->createMany($input['files']);
            $this->activity($group, $request, 'files_added', null, ['files' => $input['files']]);
        });
        $group = $group->fresh(['files', 'creator:id,name,email'])->loadCount('files');
        $this->notify($request, $contact, $group, 'files added to');

        return $this->success($group, 'Files added successfully.');
    }

    public function replaceFile(Request $request, CSVData $contact, ContactMediaGroup $group, ContactMediaFile $file, CloudinaryUploadService $cloudinary)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWE', 'RWED', 'FULL'], 'replace files')) {
            return $denied;
        }
        $this->scopeFile($contact, $group, $file);
        $this->documentPermission($request, $group, 'contact_documents.upload');
        $input = $request->validate($this->fileRules($contact));
        try {
            $cloudinary->destroyAsset($file->cloudinary_public_id, $file->resource_type);
        } catch (RuntimeException $e) {
            return $this->cloudinaryError($e);
        }
        $before = $file->toArray();
        DB::transaction(function () use ($file, $input, $group, $request, $before) {
            $file->update($input);
            $this->activity($group, $request, 'file_replaced', $before, $file->fresh()->toArray());
        });
        $this->notify($request, $contact, $group, 'file replaced in');

        return $this->success($file->fresh(), 'Media file replaced successfully.');
    }

    public function destroyFile(Request $request, CSVData $contact, ContactMediaGroup $group, ContactMediaFile $file)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWED', 'FULL'], 'delete files')) {
            return $denied;
        }
        $this->scopeFile($contact, $group, $file);
        $this->documentPermission($request, $group, 'contact_documents.delete');
        DB::transaction(function () use ($file, $group, $request) {
            $this->activity($group, $request, 'file_deleted', $file->toArray(), null);
            $file->deleted_via_group = false;
            $file->save();
            $file->delete();
        });
        $this->notify($request, $contact, $group, 'file deleted from');

        return $this->success(['deleted_file_id' => $file->id], 'Media file deleted successfully.');
    }

    public function destroy(Request $request, CSVData $contact, ContactMediaGroup $group)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWED', 'FULL'], 'delete media groups')) {
            return $denied;
        }
        $this->scope($contact, $group);
        $this->documentPermission($request, $group, 'contact_documents.delete');
        $group->load('files');
        $before = $this->snapshot($group);
        DB::transaction(function () use ($group, $request, $before) {
            $group->files()->each(function (ContactMediaFile $file) {
                $file->update(['deleted_via_group' => true]);
                $file->delete();
            });
            $this->activity($group, $request, 'deleted', $before, null);
            $group->delete();
        });
        $this->notify($request, $contact, $group, 'deleted');

        return $this->success(['deleted_group_id' => $group->id], 'Contact media group and its files deleted successfully.');
    }

    public function restore(Request $request, CSVData $contact, int $group)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWED', 'FULL'], 'restore media groups')) {
            return $denied;
        }

        $mediaGroup = ContactMediaGroup::withTrashed()->where('contact_id', $contact->id)->findOrFail($group);
        $this->documentPermission($request, $mediaGroup, 'contact_documents.delete');
        if (! $mediaGroup->trashed()) {
            return $this->conflict('MEDIA_GROUP_NOT_DELETED', 'The media group is already active.');
        }

        $files = $mediaGroup->filesWithTrashed()->where('deleted_via_group', true)->get();
        if (! $this->isWithinRecoveryPeriod($mediaGroup->deleted_at)
            || $files->contains(fn (ContactMediaFile $file) => $file->asset_purged_at !== null)) {
            return $this->conflict('MEDIA_ASSET_NOT_RECOVERABLE', 'This media group can no longer be restored because its recovery period expired or a Cloudinary asset was removed.');
        }

        DB::transaction(function () use ($mediaGroup, $files, $request) {
            $mediaGroup->restore();
            foreach ($files as $file) {
                $file->restore();
                $file->update(['deleted_via_group' => false]);
            }
            $this->activity($mediaGroup, $request, 'restored', null, $this->snapshot($mediaGroup->fresh()->load('files')));
        });

        $mediaGroup = $mediaGroup->fresh(['files', 'creator:id,name,email'])->loadCount('files');
        $this->notify($request, $contact, $mediaGroup, 'restored');

        return $this->success($mediaGroup, 'Contact media group restored successfully.');
    }

    public function restoreFile(Request $request, CSVData $contact, int $group, int $file)
    {
        $this->tenant($request, $contact);
        if ($denied = $this->access($request, ['RWED', 'FULL'], 'restore files')) {
            return $denied;
        }

        $mediaGroup = ContactMediaGroup::withTrashed()->where('contact_id', $contact->id)->findOrFail($group);
        $this->documentPermission($request, $mediaGroup, 'contact_documents.delete');
        if ($mediaGroup->trashed()) {
            return $this->conflict('MEDIA_GROUP_DELETED', 'Restore the media group before restoring an individual file.');
        }

        $mediaFile = ContactMediaFile::onlyTrashed()->where('group_id', $mediaGroup->id)->findOrFail($file);
        if (! $this->isWithinRecoveryPeriod($mediaFile->deleted_at) || $mediaFile->asset_purged_at !== null) {
            return $this->conflict('MEDIA_ASSET_NOT_RECOVERABLE', 'This file can no longer be restored because its recovery period expired or its Cloudinary asset was removed.');
        }

        DB::transaction(function () use ($mediaFile, $mediaGroup, $request) {
            $mediaFile->restore();
            $mediaFile->update(['deleted_via_group' => false]);
            $this->activity($mediaGroup, $request, 'file_restored', null, $mediaFile->fresh()->toArray());
        });
        $this->notify($request, $contact, $mediaGroup->fresh('files'), 'file restored in');

        return $this->success($mediaFile->fresh(), 'Media file restored successfully.');
    }

    public function downloadFile(Request $request, CSVData $contact, ContactMediaGroup $group, ContactMediaFile $file)
    {
        $this->tenant($request, $contact);
        $this->scopeFile($contact, $group, $file);

        // Sensitive/restricted document groups are served only through the
        // audited /contacts/{contact}/documents/{file}/download endpoint.
        abort_unless($group->sensitivity === 'normal', 404);
        abort_if($file->asset_purged_at !== null, 410, 'This file is no longer available.');

        $disposition = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        $upstream = Http::timeout(30)->withOptions(['stream' => true])->get($file->getRawOriginal('file_url'));
        if (! $upstream->successful()) {
            throw new RuntimeException('The document storage provider could not supply this file.');
        }

        $body = $upstream->toPsrResponse()->getBody();
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file->file_name ?: 'contact-file');

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
            }
        }, $name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ], $disposition);
    }

    private function fileRules(CSVData $contact, string $prefix = ''): array
    {
        $base = trim((string) config('services.cloudinary.base_folder', 'database'), '/ ');
        $folder = ($base ?: 'database').'/contacts/'.$contact->id.'/';
        $required = $prefix === '' ? 'required' : 'required';

        return [
            $prefix.'file_url' => [$required, 'url:https', 'max:4000', function ($a, $v, $fail) {
                if (parse_url((string) $v, PHP_URL_HOST) !== 'res.cloudinary.com') {
                    $fail('The file URL must be a Cloudinary secure URL.');
                }
            }],
            $prefix.'cloudinary_public_id' => [$required, 'string', 'max:500', function ($a, $v, $fail) use ($folder) {
                if (! str_starts_with((string) $v, $folder)) {
                    $fail('The Cloudinary public ID does not belong to this contact folder.');
                }
            }],
            $prefix.'file_name' => ['required', 'string', 'max:255'], $prefix.'resource_type' => ['required', 'in:image,video,raw'],
            $prefix.'format' => ['nullable', 'string', 'max:30'], $prefix.'mime_type' => ['nullable', 'string', 'max:150'],
            $prefix.'file_size' => ['required', 'integer', 'between:0,104857600'],
        ];
    }

    private function scope(CSVData $contact, ContactMediaGroup $group): void
    {
        abort_unless($group->contact_id === $contact->id, 404);
    }

    private function tenant(Request $request, CSVData $contact): void
    {
        abort_unless(
            strtolower((string) $contact->microsoft_tenant_id) === strtolower((string) $request->user()->microsoft_tenant_id),
            404
        );
    }

    private function documentPermission(Request $request, ContactMediaGroup $group, string $permission): void
    {
        if ($group->sensitivity !== 'normal') {
            abort_unless($request->user()->hasPermission($permission), 403);
        }
    }

    private function scopeFile(CSVData $contact, ContactMediaGroup $group, ContactMediaFile $file): void
    {
        $this->scope($contact, $group);
        abort_unless($file->group_id === $group->id, 404);
    }

    private function snapshot(ContactMediaGroup $group): array
    {
        return ['id' => $group->id, 'title' => $group->title, 'note' => $group->note, 'files' => $group->files->toArray()];
    }

    private function deletedFilePayload(ContactMediaFile $file): array
    {
        return [
            'id' => $file->id,
            'deleted_at' => $file->deleted_at?->toIso8601String(),
            'recoverable_until' => $file->deleted_at?->copy()->addDays($this->recoveryDays())->toIso8601String(),
            'restorable' => $this->isWithinRecoveryPeriod($file->deleted_at) && $file->asset_purged_at === null,
            'file_url' => $file->file_url,
            'file_name' => $file->file_name,
            'mime_type' => $file->mime_type,
            'format' => $file->format,
            'resource_type' => $file->resource_type,
            'file_size' => $file->file_size,
        ];
    }

    private function recoveryDays(): int
    {
        return max(1, (int) config('contact_media.recovery_days', 30));
    }

    private function isWithinRecoveryPeriod($deletedAt): bool
    {
        return $deletedAt !== null && $deletedAt->copy()->addDays($this->recoveryDays())->isFuture();
    }

    private function conflict(string $code, string $message)
    {
        return response()->json(['code' => $code, 'data' => null, 'status_code' => 409, 'message' => $message, 'status' => false], 409);
    }

    private function activity(ContactMediaGroup $group, Request $request, string $action, ?array $before, ?array $after): void
    {
        DB::table('contact_media_group_activities')->insert(['group_id' => $group->id, 'contact_id' => $group->contact_id, 'user_id' => $request->user()->id, 'action' => $action, 'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function notify(Request $request, CSVData $contact, ContactMediaGroup $group, string $action): void
    {
        _EmailHelper::sendEmail(['action' => $action, 'user_name' => $request->user()->name, 'user_email' => $request->user()->email, 'contact_name' => trim($contact->firstname.' '.$contact->lastname), 'contact_email' => $contact->email, 'media_title' => $group->title, 'file_name' => $group->files->pluck('file_name')->filter()->implode(', '), 'note' => $group->note, 'action_time' => now()->format('Y-m-d H:i:s')], 'emails.contact_media');
    }

    private function access(Request $request, array $allowed, string $action)
    {
        return null;
    }

    private function success($data, string $message, ?array $pagination = null)
    {
        $body = ['data' => $data, 'status_code' => 200, 'message' => $message, 'status' => true];
        if ($pagination) {
            $body['pagination'] = $pagination;
        }

        return response()->json($body);
    }

    private function cloudinaryError(RuntimeException $e)
    {
        return response()->json(['code' => 'CLOUDINARY_DELETE_FAILED', 'data' => null, 'status_code' => 502, 'message' => $e->getMessage(), 'status' => false], 502);
    }
}
