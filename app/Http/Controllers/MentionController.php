<?php

namespace App\Http\Controllers;

use App\Jobs\SendMentionEmail;
use App\Models\MentionMessage;
use App\Models\NotificationDelivery;
use App\Services\MentionContextService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MentionController extends Controller
{
    public function users(Request $request, MentionContextService $contexts)
    {
        $input = $request->validate([
            'context_type' => ['required', 'string', Rule::in($contexts->supportedUserSearchTypes())],
            'context_id' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,'.config('mentions.max_per_page')],
        ]);

        $context = $contexts->resolve($input['context_type'], (int) $input['context_id']);
        if (! $contexts->userCanAccess($request->user(), $input['context_type'])) {
            return $this->forbidden();
        }

        $search = trim((string) ($input['search'] ?? ''));
        $users = $contexts->eligibleUsersQuery($request->user(), $input['context_type'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->orderBy('name')
            ->paginate((int) ($input['per_page'] ?? config('mentions.per_page')), ['id', 'name', 'email']);

        return response()->json([
            'data' => collect($users->items())->map(fn ($user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => null,
            ]),
            'pagination' => $this->pagination($users),
        ]);
    }

    public function store(Request $request, MentionContextService $contexts)
    {
        $input = $request->validate([
            'context_type' => ['required', 'string', Rule::in($contexts->supportedMessageTypes())],
            'context_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:'.config('mentions.max_message_length')],
            'mentioned_user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'mentioned_user_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $context = $contexts->resolve($input['context_type'], (int) $input['context_id']);
        $sender = $request->user();
        if (! $contexts->userCanAccess($sender, $input['context_type'])) {
            return $this->forbidden();
        }

        $userIds = array_values(array_unique(array_map('intval', $input['mentioned_user_ids'])));
        $eligibleUsers = $contexts->eligibleUsersQuery($sender, $input['context_type'])
            ->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');
        if ($eligibleUsers->count() !== count($userIds)) {
            return response()->json([
                'code' => 'INVALID_MENTIONED_USERS',
                'message' => 'One or more selected users cannot be mentioned in this file.',
                'fieldErrors' => ['mentioned_user_ids' => ['One or more users are inactive or do not have access to this resource.']],
                'status' => 422,
            ], 422);
        }

        $messageText = trim(str_replace("\0", '', strip_tags($input['message'])));
        if ($messageText === '') {
            return response()->json([
                'message' => 'The message field is required.',
                'errors' => ['message' => ['The message field must contain plain text.']],
            ], 422);
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if (mb_strlen($idempotencyKey) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header may not exceed 255 characters.'], 422);
        }
        $keyHash = $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null;
        if ($keyHash && ($existing = MentionMessage::where('sender_id', $sender->id)->where('idempotency_key_hash', $keyHash)->first())) {
            return response()->json(['data' => $this->messageData($existing), 'message' => 'Message already sent.', 'idempotent_replay' => true]);
        }

        try {
            $message = DB::transaction(function () use ($sender, $input, $messageText, $keyHash, $userIds, $context) {
                $message = MentionMessage::create([
                    'sender_id' => $sender->id,
                    'context_type' => $input['context_type'],
                    'context_id' => $input['context_id'],
                    'message' => $messageText,
                    'idempotency_key_hash' => $keyHash,
                ]);
                foreach ($userIds as $userId) {
                    $mention = $message->mentions()->create(['mentioned_user_id' => $userId]);
                    NotificationDelivery::create([
                        'message_mention_id' => $mention->id, 'channel' => 'email', 'status' => 'queued',
                    ]);
                }
                DB::table('contact_media_group_activities')->insert([
                    'group_id' => $context->id,
                    'contact_id' => $context->contact_id,
                    'user_id' => $sender->id,
                    'action' => 'mention_created',
                    'before' => null,
                    'after' => json_encode(['message_id' => $message->id, 'mentioned_user_ids' => $userIds]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::afterCommit(function () use ($message) {
                    NotificationDelivery::whereIn('message_mention_id', $message->mentions()->pluck('id'))
                        ->pluck('id')->each(function ($deliveryId) {
                            try {
                                SendMentionEmail::dispatch($deliveryId)->onQueue('notifications');
                            } catch (\Throwable $exception) {
                                Log::error('Mention notification could not be queued.', [
                                    'delivery_id' => $deliveryId,
                                    'error' => $exception->getMessage(),
                                ]);
                            }
                        });
                });

                return $message;
            });
        } catch (QueryException $exception) {
            if ($keyHash && ($existing = MentionMessage::where('sender_id', $sender->id)->where('idempotency_key_hash', $keyHash)->first())) {
                return response()->json(['data' => $this->messageData($existing), 'message' => 'Message already sent.', 'idempotent_replay' => true]);
            }
            throw $exception;
        }

        return response()->json(['data' => $this->messageData($message), 'message' => 'Message sent successfully.'], 201);
    }

    public function index(Request $request, MentionContextService $contexts)
    {
        $input = $request->validate([
            'context_type' => ['required', 'string', Rule::in($contexts->supportedMessageTypes())],
            'context_id' => ['required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,'.config('mentions.max_per_page')],
        ]);
        $contexts->resolve($input['context_type'], (int) $input['context_id']);
        if (! $contexts->userCanAccess($request->user(), $input['context_type'])) {
            return $this->forbidden();
        }

        $messages = MentionMessage::with(['sender:id,name', 'mentions.user:id,name'])
            ->where('context_type', $input['context_type'])
            ->where('context_id', $input['context_id'])
            ->latest('id')->paginate((int) ($input['per_page'] ?? config('mentions.per_page')));

        return response()->json([
            'data' => collect($messages->items())->map(fn ($message) => $this->messageData($message, false)),
            'pagination' => $this->pagination($messages),
        ]);
    }

    private function messageData(MentionMessage $message, bool $includeEmail = true): array
    {
        $message->loadMissing(['sender:id,name', 'mentions.user:id,name,email', 'mentions.deliveries']);
        return [
            'id' => $message->id,
            'context_type' => $message->context_type,
            'context_id' => (int) $message->context_id,
            'message' => $message->message,
            'sender' => ['id' => $message->sender->id, 'name' => $message->sender->name],
            'mentions' => $message->mentions->map(fn ($mention) => [
                'user' => array_filter([
                    'id' => $mention->user->id,
                    'name' => $mention->user->name,
                    'email' => $includeEmail ? $mention->user->email : null,
                ], fn ($value) => $value !== null),
                ...($includeEmail ? ['email_status' => $mention->deliveries->firstWhere('channel', 'email')?->status] : []),
            ])->values(),
            'created_at' => $message->created_at?->toISOString(),
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

    private function forbidden()
    {
        return response()->json(['data' => null, 'status_code' => 403, 'message' => 'You do not have permission to access messages for this resource.', 'status' => false], 403);
    }
}
