<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time migration of the per-folder mention thread
 * (mention_messages / message_mentions with context_type = "contact_media_group")
 * into the unified sticky_notes feature.
 *
 * The legacy rows are left in place so the deprecated
 * GET/POST /mentions?context_type=contact_media_group endpoints keep working
 * as a read fallback for one release. A later migration drops them.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['mention_messages', 'message_mentions', 'sticky_notes', 'sticky_note_mentions', 'contact_media_groups'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $activeDays = max(1, (int) config('sticky_notes.active_days', 7));
        $now = Carbon::now();

        DB::table('mention_messages')
            ->where('context_type', 'contact_media_group')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use ($activeDays, $now) {
                $groupIds = $messages->pluck('context_id')->unique()->all();
                $groups = DB::table('contact_media_groups')->whereIn('id', $groupIds)->get()->keyBy('id');

                foreach ($messages as $message) {
                    $group = $groups->get($message->context_id);
                    if (! $group) {
                        continue;
                    }

                    $keyHash = hash('sha256', 'backfill:mention_message:'.$message->id);
                    if (DB::table('sticky_notes')->where('idempotency_key_hash', $keyHash)->exists()) {
                        continue;
                    }

                    $createdAt = $message->created_at ? Carbon::parse($message->created_at) : $now->copy();
                    $archiveDueAt = $createdAt->copy()->addDays($activeDays);
                    $isExpired = $archiveDueAt->lessThanOrEqualTo($now);

                    $noteId = DB::table('sticky_notes')->insertGetId([
                        'contact_id' => $group->contact_id,
                        'media_group_id' => $group->id,
                        'content' => $message->message,
                        'idempotency_key_hash' => $keyHash,
                        'status' => $isExpired ? 'archived' : 'active',
                        'archive_due_at' => $archiveDueAt,
                        'archived_at' => $isExpired ? $archiveDueAt : null,
                        'archived_by' => null,
                        'created_by' => $message->sender_id,
                        'updated_by' => $message->sender_id,
                        'created_at' => $createdAt,
                        'updated_at' => $message->updated_at ? Carbon::parse($message->updated_at) : $createdAt,
                    ]);

                    $mentions = DB::table('message_mentions')->where('message_id', $message->id)->get();
                    foreach ($mentions as $mention) {
                        $delivery = DB::table('notification_deliveries')
                            ->where('message_mention_id', $mention->id)
                            ->where('channel', 'email')
                            ->first();

                        DB::table('sticky_note_mentions')->insertOrIgnore([
                            'sticky_note_id' => $noteId,
                            'mentioned_user_id' => $mention->mentioned_user_id,
                            'email_status' => $delivery->status ?? 'sent',
                            'email_attempts' => (int) ($delivery->attempts ?? 0),
                            'email_sent_at' => $delivery->sent_at ?? null,
                            'email_failed_at' => $delivery->failed_at ?? null,
                            'email_failure_reason' => $delivery->failure_reason ?? null,
                            'read_at' => $mention->read_at ?? null,
                            'created_at' => $mention->created_at ?? $createdAt,
                            'updated_at' => $mention->updated_at ?? $createdAt,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sticky_notes') || ! Schema::hasTable('mention_messages')) {
            return;
        }

        // Backfilled notes carry a deterministic hash of their source
        // mention_message id; remove only those rows (sticky_note_mentions
        // cascade on delete).
        $hashes = DB::table('mention_messages')
            ->where('context_type', 'contact_media_group')
            ->pluck('id')
            ->map(fn ($id) => hash('sha256', 'backfill:mention_message:'.$id))
            ->all();

        DB::table('sticky_notes')->whereIn('idempotency_key_hash', $hashes)->delete();
    }
};
