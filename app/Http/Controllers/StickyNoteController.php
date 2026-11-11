<?php

namespace App\Http\Controllers;

use App\Jobs\SendStickyNoteMentionEmail;
use App\Models\ContactMediaGroup;
use App\Models\CSVData;
use App\Models\StickyNote;
use App\Models\StickyNoteMention;
use App\Services\MentionContextService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StickyNoteController extends Controller
{
    public function __construct(private MentionContextService $mentionContexts) {}

    public function index(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $input = $request->validate([
            'status' => ['nullable', Rule::in([StickyNote::STATUS_ACTIVE, StickyNote::STATUS_ARCHIVED])],
            'media_group_id' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $this->archiveExpired($model->id);
        $status = $input['status'] ?? StickyNote::STATUS_ACTIVE;

        $groupFilter = isset($input['media_group_id']) && trim((string) $input['media_group_id']) !== ''
            ? trim((string) $input['media_group_id'])
            : null;

        $notes = $model->stickyNotes()
            ->where('status', $status)
            ->when($groupFilter !== null, function ($query) use ($groupFilter) {
                $groupFilter === 'none'
                    ? $query->whereNull('media_group_id')
                    : $query->where('media_group_id', (int) $groupFilter);
            })
            ->with([
                'mediaGroup:id,title',
                'creator:id,name,email',
                'updater:id,name,email',
                'archiver:id,name,email',
                'mentions.user:id,name,email',
            ])
            ->latest('created_at')
            ->paginate((int) ($input['per_page'] ?? 25));

        return response()->json([
            'data' => collect($notes->items())->map(fn (StickyNote $note) => $this->noteData($note, false))->all(),
            'pagination' => [
                'current_page' => $notes->currentPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
                'last_page' => $notes->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, int $contact)
    {
        $model = $this->contact($request, $contact);
        $input = $request->validate([
            'content' => ['required', 'string', 'max:'.config('sticky_notes.max_content_length')],
            'media_group_id' => ['nullable', 'integer'],
            'mentioned_user_ids' => ['nullable', 'array', 'max:'.config('sticky_notes.max_mentions')],
            'mentioned_user_ids.*' => ['integer', 'min:1'],
        ]);

        $mediaGroup = $this->mediaGroupForContact($model, $input['media_group_id'] ?? null);
        $userId = $request->user()->id;

        $keyHash = $this->idempotencyHash($request);
        if ($keyHash && ($existing = StickyNote::where('created_by', $userId)->where('idempotency_key_hash', $keyHash)->first())) {
            return response()->json([
                'data' => $this->noteData($this->loadForResponse($existing), true),
                'message' => 'Sticky note already created.',
                'idempotent_replay' => true,
            ]);
        }

        $mentionIds = $this->validatedMentionIds($request, $input['mentioned_user_ids'] ?? []);

        try {
            $note = DB::transaction(function () use ($model, $mediaGroup, $input, $userId, $keyHash, $mentionIds) {
                $note = $model->stickyNotes()->create([
                    'media_group_id' => $mediaGroup?->id,
                    'content' => $input['content'],
                    'idempotency_key_hash' => $keyHash,
                    'status' => StickyNote::STATUS_ACTIVE,
                    'archive_due_at' => now()->addDays((int) config('sticky_notes.active_days')),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $this->createMentions($note, $mentionIds);

                return $note;
            });
        } catch (QueryException $exception) {
            if ($keyHash && ($existing = StickyNote::where('created_by', $userId)->where('idempotency_key_hash', $keyHash)->first())) {
                return response()->json([
                    'data' => $this->noteData($this->loadForResponse($existing), true),
                    'message' => 'Sticky note already created.',
                    'idempotent_replay' => true,
                ]);
            }
            throw $exception;
        }

        return response()->json([
            'data' => $this->noteData($this->loadForResponse($note), true),
            'message' => 'Sticky note created successfully.',
        ], 201);
    }

    public function update(Request $request, int $note)
    {
        $model = $this->note($request, $note);
        $input = $request->validate([
            'content' => ['required', 'string', 'max:'.config('sticky_notes.max_content_length')],
            'mentioned_user_ids' => ['sometimes', 'array', 'max:'.config('sticky_notes.max_mentions')],
            'mentioned_user_ids.*' => ['integer', 'min:1'],
        ]);

        $syncMentions = array_key_exists('mentioned_user_ids', $input);
        $desiredIds = $syncMentions
            ? $this->validatedMentionIds($request, $input['mentioned_user_ids'])
            : [];

        DB::transaction(function () use ($model, $input, $request, $syncMentions, $desiredIds) {
            $model->update(['content' => $input['content'], 'updated_by' => $request->user()->id]);
            if ($syncMentions) {
                $this->syncMentions($model, $desiredIds);
            }
        });

        return response()->json([
            'data' => $this->noteData($this->loadForResponse($model->fresh()), true),
            'message' => 'Sticky note updated successfully.',
        ]);
    }

    public function updateStatus(Request $request, int $note)
    {
        $model = $this->note($request, $note);
        $input = $request->validate([
            'status' => ['required', Rule::in([StickyNote::STATUS_ACTIVE, StickyNote::STATUS_ARCHIVED])],
        ]);

        if ($input['status'] === StickyNote::STATUS_ARCHIVED && $model->status !== StickyNote::STATUS_ARCHIVED) {
            $model->update([
                'status' => StickyNote::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archived_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
        } elseif ($input['status'] === StickyNote::STATUS_ACTIVE && $model->status !== StickyNote::STATUS_ACTIVE) {
            $model->update([
                'status' => StickyNote::STATUS_ACTIVE,
                'archive_due_at' => now()->addDays((int) config('sticky_notes.active_days')),
                'archived_at' => null,
                'archived_by' => null,
                'updated_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'data' => $this->noteData($this->loadForResponse($model->fresh()), true),
            'message' => $input['status'] === StickyNote::STATUS_ARCHIVED
                ? 'Sticky note archived successfully.'
                : 'Sticky note restored successfully.',
        ]);
    }

    private function contact(Request $request, int $id): CSVData
    {
        return CSVData::withTrashed()->forTenant($request->user()->microsoft_tenant_id)->findOrFail($id);
    }

    private function note(Request $request, int $id): StickyNote
    {
        return StickyNote::query()
            ->whereHas('contact', fn ($query) => $query->forTenant($request->user()->microsoft_tenant_id))
            ->findOrFail($id);
    }

    private function mediaGroupForContact(CSVData $contact, ?int $mediaGroupId): ?ContactMediaGroup
    {
        if ($mediaGroupId === null) {
            return null;
        }

        $group = ContactMediaGroup::where('id', $mediaGroupId)
            ->where('contact_id', $contact->id)
            ->first();

        abort_unless($group !== null, response()->json([
            'message' => 'The selected folder does not belong to this contact.',
            'errors' => ['media_group_id' => ['Select a document folder that belongs to this contact.']],
        ], 422));

        return $group;
    }

    private function idempotencyHash(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }
        abort_if(mb_strlen($key) > 255, response()->json([
            'message' => 'The Idempotency-Key header may not exceed 255 characters.',
        ], 422));

        return hash('sha256', $key);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function validatedMentionIds(Request $request, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $eligible = $this->mentionContexts
            ->eligibleUsersQuery($request->user(), MentionContextService::CONTACT)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($eligible) !== count($ids)) {
            abort(response()->json([
                'code' => 'INVALID_MENTIONED_USERS',
                'message' => 'One or more selected users cannot be mentioned on this note.',
                'fieldErrors' => ['mentioned_user_ids' => ['One or more users are inactive or cannot view this contact.']],
                'status' => 422,
            ], 422));
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function createMentions(StickyNote $note, array $ids): void
    {
        foreach ($ids as $id) {
            $mention = $note->mentions()->create([
                'mentioned_user_id' => $id,
                'email_status' => StickyNoteMention::STATUS_QUEUED,
            ]);
            $this->dispatchMentionEmail($mention->id);
        }
    }

    /**
     * @param  array<int, int>  $desiredIds
     */
    private function syncMentions(StickyNote $note, array $desiredIds): void
    {
        $current = $note->mentions()->pluck('mentioned_user_id')->all();
        $toAdd = array_values(array_diff($desiredIds, $current));
        $toRemove = array_values(array_diff($current, $desiredIds));

        if ($toRemove !== []) {
            $note->mentions()->whereIn('mentioned_user_id', $toRemove)->delete();
        }
        $this->createMentions($note, $toAdd);
    }

    private function dispatchMentionEmail(int $mentionId): void
    {
        DB::afterCommit(function () use ($mentionId) {
            try {
                SendStickyNoteMentionEmail::dispatch($mentionId)->onQueue('notifications');
            } catch (\Throwable $exception) {
                Log::error('Sticky note mention notification could not be queued.', [
                    'mention_id' => $mentionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function loadForResponse(StickyNote $note): StickyNote
    {
        return $note->load([
            'mediaGroup:id,title',
            'creator:id,name,email',
            'updater:id,name,email',
            'archiver:id,name,email',
            'mentions.user:id,name,email',
        ]);
    }

    private function noteData(StickyNote $note, bool $includeEmailStatus): array
    {
        return [
            'id' => $note->id,
            'contact_id' => $note->contact_id,
            'media_group_id' => $note->media_group_id,
            'media_group' => $note->relationLoaded('mediaGroup') && $note->mediaGroup
                ? ['id' => $note->mediaGroup->id, 'title' => $note->mediaGroup->title]
                : null,
            'content' => $note->content,
            'status' => $note->status,
            'archive_due_at' => $note->archive_due_at?->toISOString(),
            'archived_at' => $note->archived_at?->toISOString(),
            'archived_by' => $note->archived_by,
            'created_by' => $note->created_by,
            'updated_by' => $note->updated_by,
            'created_at' => $note->created_at?->toISOString(),
            'updated_at' => $note->updated_at?->toISOString(),
            'creator' => $note->relationLoaded('creator') ? $note->creator : null,
            'updater' => $note->relationLoaded('updater') ? $note->updater : null,
            'archiver' => $note->relationLoaded('archiver') ? $note->archiver : null,
            'mentions' => $note->relationLoaded('mentions')
                ? $note->mentions->map(fn (StickyNoteMention $mention) => array_filter([
                    'user' => $mention->relationLoaded('user') && $mention->user ? [
                        'id' => $mention->user->id,
                        'name' => $mention->user->name,
                        'email' => $mention->user->email,
                    ] : null,
                    'email_status' => $includeEmailStatus ? $mention->email_status : null,
                ], fn ($value) => $value !== null))->values()
                : [],
        ];
    }

    private function archiveExpired(int $contactId): void
    {
        StickyNote::query()
            ->where('contact_id', $contactId)
            ->where('status', StickyNote::STATUS_ACTIVE)
            ->where('archive_due_at', '<=', now())
            ->update([
                'status' => StickyNote::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archived_by' => null,
                'updated_at' => now(),
            ]);
    }
}
