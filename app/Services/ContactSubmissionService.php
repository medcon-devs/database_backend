<?php

namespace App\Services;

use App\Models\ContactSubmission;
use App\Models\CSVData;
use App\Models\HistoryEditing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactSubmissionService
{
    private const CONTACT_FIELDS = ['firstname', 'lastname', 'email', 'phone', 'country', 'speciality'];

    public function submit(array $input): ContactSubmission
    {
        $email = mb_strtolower(trim($input['email']));
        $existing = CSVData::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        $attributes = [
            ...array_intersect_key($input, array_flip(self::CONTACT_FIELDS)),
            'email' => $email,
            'source_domain' => mb_strtolower($input['source_domain']),
            'external_reference' => $input['external_reference'] ?? null,
            'submission_type' => $existing ? 'update' : 'new',
            'status' => 'pending',
            'matched_csv_data_id' => $existing?->id,
            'metadata' => $input['metadata'] ?? null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'migrated_at' => null,
        ];

        if (filled($attributes['external_reference'])) {
            return ContactSubmission::updateOrCreate([
                'source_domain' => $attributes['source_domain'],
                'external_reference' => $attributes['external_reference'],
            ], $attributes);
        }

        return ContactSubmission::create($attributes);
    }

    public function query(array $filters): Builder
    {
        return ContactSubmission::query()
            ->with(['matchedContact:id,firstname,lastname,email,phone,country,speciality', 'reviewer:id,name,email'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['submission_type']), fn ($query) => $query->where('submission_type', $filters['submission_type']))
            ->when(filled($filters['source_domain'] ?? null), fn ($query) => $query->where('source_domain', $filters['source_domain']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(function ($nested) use ($search) {
                    $nested->where('email', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('firstname', 'like', $search)
                        ->orWhere('lastname', 'like', $search);
                });
            });
    }

    public function details(ContactSubmission $submission): array
    {
        $submission->load('matchedContact:id,firstname,lastname,email,phone,country,speciality');
        $current = $submission->matchedContact;

        return [
            'submission' => $submission,
            'current_contact' => $current,
            'differences' => collect(self::CONTACT_FIELDS)->mapWithKeys(function ($field) use ($submission, $current) {
                $old = $current?->{$field};
                $new = $submission->{$field};

                return [$field => [
                    'current' => $old,
                    'incoming' => $new,
                    'changed' => (string) $old !== (string) $new,
                ]];
            }),
        ];
    }

    public function migrate(array $ids, User $administrator): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($ids, $administrator, &$counts) {
            $submissions = ContactSubmission::whereIn('id', $ids)
                ->where('status', 'pending')->lockForUpdate()->orderBy('id')->get();

            if ($submissions->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'ids' => ['One or more selected submissions are no longer pending or do not exist.'],
                ]);
            }

            foreach ($submissions as $submission) {
                $contact = CSVData::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($submission->email)])->first();
                $data = collect(self::CONTACT_FIELDS)->mapWithKeys(function ($field) use ($submission, $contact) {
                    $incoming = $submission->{$field};

                    return [$field => $incoming !== null && $incoming !== '' ? $incoming : $contact?->{$field}];
                })->all();

                if (! $contact) {
                    $contact = CSVData::create([
                        ...$data,
                        'user_id' => $administrator->id,
                        'microsoft_tenant_id' => $administrator->microsoft_tenant_id,
                        'status' => 'active',
                    ]);
                    $counts['created']++;
                } else {
                    $changed = collect(self::CONTACT_FIELDS)->contains(
                        fn ($field) => (string) $contact->{$field} !== (string) $data[$field]
                    );
                    if ($changed) {
                        $history = HistoryEditing::create([
                            ...$contact->only(self::CONTACT_FIELDS),
                            'user_id' => $administrator->id,
                            'data_id' => $contact->id,
                        ]);
                        $contact->update($data);
                        DB::table('user_activity')->insert([
                            'user_id' => $administrator->id,
                            'data_id' => $contact->id,
                            'status' => 'update',
                            'history_editing_id' => $history->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $counts['updated']++;
                    } else {
                        $counts['unchanged']++;
                    }
                    if ($contact->trashed()) {
                        $contact->restore();
                    }
                }

                $submission->update([
                    'status' => 'migrated',
                    'submission_type' => $submission->matched_csv_data_id ? 'update' : 'new',
                    'matched_csv_data_id' => $contact->id,
                    'reviewed_by' => $administrator->id,
                    'reviewed_at' => now(),
                    'migrated_at' => now(),
                ]);
            }
        });

        return [...$counts, 'processed' => array_sum($counts)];
    }

    public function reject(array $ids, User $administrator): int
    {
        return ContactSubmission::whereIn('id', $ids)->where('status', 'pending')->update([
            'status' => 'rejected',
            'reviewed_by' => $administrator->id,
            'reviewed_at' => now(),
        ]);
    }
}
