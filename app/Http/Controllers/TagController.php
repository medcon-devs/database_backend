<?php

namespace App\Http\Controllers;

use App\Models\CSVData;
use App\Models\Tag;
use App\Services\ReferenceDataService;
use App\Services\ContactSortingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TagController extends Controller
{
    private const MEMBERSHIP_CHUNK_SIZE = 500;

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'perPage' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $tags = Tag::query()
            ->withCount('contacts')
            ->when($validated['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($validated['perPage'] ?? 30);

        return $this->paginatedResponse($tags, 'Tags retrieved successfully.');
    }

    public function store(Request $request)
    {
        if (! $this->isAdministrator($request)) {
            return $this->forbidden('Only administrators can create tags.');
        }

        $validated = $request->validate($this->tagRules());
        $name = trim($validated['name']);

        $tag = Tag::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'color' => strtoupper($validated['color'] ?? '#2563EB'),
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->tagData($tag->loadCount('contacts')),
            'status_code' => 201,
            'message' => 'Tag created successfully.',
            'status' => true,
        ], 201);
    }

    public function show(Tag $tag)
    {
        return response()->json([
            'data' => $this->tagData($tag->loadCount('contacts')),
            'status_code' => 200,
            'message' => 'Tag retrieved successfully.',
            'status' => true,
        ]);
    }

    public function update(Request $request, Tag $tag)
    {
        if (! $this->isAdministrator($request)) {
            return $this->forbidden('Only administrators can edit tags.');
        }

        $validated = $request->validate($this->tagRules($tag));
        $attributes = [];

        if (array_key_exists('name', $validated)) {
            $attributes['name'] = trim($validated['name']);
            $attributes['slug'] = $this->uniqueSlug($attributes['name'], $tag->id);
        }
        if (array_key_exists('color', $validated)) {
            $attributes['color'] = strtoupper($validated['color']);
        }
        if (array_key_exists('description', $validated)) {
            $attributes['description'] = $validated['description'];
        }

        $tag->update($attributes);

        return response()->json([
            'data' => $this->tagData($tag->fresh()->loadCount('contacts')),
            'status_code' => 200,
            'message' => 'Tag updated successfully.',
            'status' => true,
        ]);
    }

    public function destroy(Request $request, Tag $tag)
    {
        if (! $this->isAdministrator($request)) {
            return $this->forbidden('Only administrators can delete tags.');
        }

        $detachedContacts = $tag->contacts()->count();
        $tag->delete();

        return response()->json([
            'data' => ['detached_contacts' => $detachedContacts],
            'status_code' => 200,
            'message' => 'Tag deleted successfully. Contact data was not deleted.',
            'status' => true,
        ]);
    }

    public function contacts(
        Request $request,
        Tag $tag,
        ReferenceDataService $referenceData,
        ContactSortingService $sorting
    )
    {
        return $this->contactListResponse(
            $request,
            $tag->contacts()->getQuery(),
            'Tag contacts retrieved successfully.',
            $referenceData,
            $sorting
        );
    }

    public function availableContacts(
        Request $request,
        Tag $tag,
        ReferenceDataService $referenceData,
        ContactSortingService $sorting
    )
    {
        $query = CSVData::query()->whereDoesntHave('tags', function (Builder $query) use ($tag) {
            $query->whereKey($tag->id);
        });

        return $this->contactListResponse(
            $request,
            $query,
            'Contacts available for this tag retrieved successfully.',
            $referenceData,
            $sorting
        );
    }

    public function availableContactIds(Request $request, Tag $tag, ReferenceDataService $referenceData)
    {
        if (! $this->canManageMembership($request)) {
            return $this->forbidden('You do not have permission to add contacts to tags.');
        }

        $validated = $request->validate($this->contactFilterRules());

        $query = CSVData::query()->whereDoesntHave('tags', function (Builder $query) use ($tag) {
            $query->whereKey($tag->id);
        });

        $this->applyContactFilters($query, $validated, $referenceData);

        $total = (clone $query)->count();
        $contactIds = $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'data' => [
                'contact_ids' => $contactIds,
                'total' => $total,
                'truncated' => false,
            ],
            'status_code' => 200,
            'message' => 'Available contact selection retrieved successfully.',
            'status' => true,
        ]);
    }

    public function attachContacts(Request $request, Tag $tag)
    {
        if (! $this->canManageMembership($request)) {
            return $this->forbidden('You do not have permission to add contacts to tags.');
        }

        $contactIds = $this->validatedContactIds($request);
        $attachedContactIds = DB::transaction(function () use ($tag, $contactIds) {
            $existingIds = $this->existingMembershipIds($tag->id, $contactIds);
            $newIds = array_values(array_diff($contactIds, $existingIds));
            $this->insertMemberships($tag->id, $newIds);

            return $newIds;
        });
        $contactsCount = $this->membershipCount($tag->id);

        return response()->json([
            'data' => [
                'tag' => $this->tagDataWithCount($tag, $contactsCount),
                'attached_contact_ids' => $attachedContactIds,
                'attached_contacts' => count($attachedContactIds),
            ],
            'status_code' => 200,
            'message' => count($attachedContactIds).' contact(s) added to the tag.',
            'status' => true,
        ]);
    }

    public function syncContacts(Request $request, Tag $tag)
    {
        if (! $this->canManageMembership($request)) {
            return $this->forbidden('You do not have permission to manage tag contacts.');
        }

        $contactIds = $this->validatedContactIds($request, true);
        [$attachedContactIds, $detachedContactIds] = DB::transaction(function () use ($tag, $contactIds) {
            $currentIds = DB::table('csv_data_tag')
                ->where('tag_id', $tag->id)
                ->pluck('csv_data_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $newIds = array_values(array_diff($contactIds, $currentIds));
            $removedIds = array_values(array_diff($currentIds, $contactIds));

            $this->insertMemberships($tag->id, $newIds);
            $this->deleteMemberships($tag->id, $removedIds);

            return [$newIds, $removedIds];
        });
        $contactsCount = count($contactIds);

        return response()->json([
            'data' => [
                'tag' => $this->tagDataWithCount($tag, $contactsCount),
                'attached_contact_ids' => $attachedContactIds,
                'detached_contact_ids' => $detachedContactIds,
                'attached_contacts' => count($attachedContactIds),
                'detached_contacts' => count($detachedContactIds),
            ],
            'status_code' => 200,
            'message' => 'Tag contacts synchronized successfully.',
            'status' => true,
        ]);
    }

    public function detachContacts(Request $request, Tag $tag)
    {
        if (! $this->canManageMembership($request)) {
            return $this->forbidden('You do not have permission to remove contacts from tags.');
        }

        $contactIds = $this->validatedContactIds($request);
        $detached = $tag->contacts()->detach($contactIds);

        return response()->json([
            'data' => [
                'tag' => $this->tagData($tag->loadCount('contacts')),
                'detached_contacts' => $detached,
            ],
            'status_code' => 200,
            'message' => $detached.' contact(s) removed from the tag.',
            'status' => true,
        ]);
    }

    private function tagRules(?Tag $tag = null): array
    {
        $required = $tag ? 'sometimes' : 'required';

        return [
            'name' => [
                $required,
                'string',
                'max:100',
                Rule::unique('tags', 'name')->ignore($tag?->id),
            ],
            'color' => [$tag ? 'sometimes' : 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function validatedContactIds(Request $request, bool $allowEmpty = false): array
    {
        $validated = $request->validate([
            'contact_ids' => $allowEmpty
                ? ['present', 'array']
                : ['required', 'array', 'min:1'],
            'contact_ids.*' => [
                'integer',
                'distinct',
            ],
        ]);

        $contactIds = array_map('intval', $validated['contact_ids']);

        $existingContactCount = 0;
        foreach (array_chunk($contactIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            $existingContactCount += CSVData::query()->whereIn('id', $chunk)->count();
        }

        if ($existingContactCount !== count($contactIds)) {
            throw ValidationException::withMessages([
                'contact_ids' => ['One or more selected contacts do not exist or were deleted.'],
            ]);
        }

        return $contactIds;
    }

    private function existingMembershipIds(int $tagId, array $contactIds): array
    {
        $existingIds = [];

        foreach (array_chunk($contactIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            $ids = DB::table('csv_data_tag')
                ->where('tag_id', $tagId)
                ->whereIn('csv_data_id', $chunk)
                ->pluck('csv_data_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            array_push($existingIds, ...$ids);
        }

        return $existingIds;
    }

    private function insertMemberships(int $tagId, array $contactIds): void
    {
        if ($contactIds === []) {
            return;
        }

        $timestamp = now();
        foreach (array_chunk($contactIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DB::table('csv_data_tag')->insertOrIgnore(array_map(fn (int $contactId) => [
                'tag_id' => $tagId,
                'csv_data_id' => $contactId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $chunk));
        }
    }

    private function deleteMemberships(int $tagId, array $contactIds): void
    {
        foreach (array_chunk($contactIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DB::table('csv_data_tag')
                ->where('tag_id', $tagId)
                ->whereIn('csv_data_id', $chunk)
                ->delete();
        }
    }

    private function membershipCount(int $tagId): int
    {
        return DB::table('csv_data_tag')->where('tag_id', $tagId)->count();
    }

    private function tagDataWithCount(Tag $tag, int $contactsCount): array
    {
        $tag->setAttribute('contacts_count', $contactsCount);

        return $this->tagData($tag);
    }

    private function contactListResponse(
        Request $request,
        Builder $query,
        string $message,
        ReferenceDataService $referenceData,
        ContactSortingService $sorting
    ) {
        $validated = $request->validate($this->contactFilterRules(true));

        $query
            ->select('csv_data.*')
            ->with('tags:id,name,slug,color');

        $this->applyContactFilters($query, $validated, $referenceData);

        if (isset($validated['sort_by'])) {
            $sorting->apply(
                $query,
                $validated['sort_by'],
                $validated['sort_direction'] ?? null,
                'firstname',
                'asc',
                'csv_data'
            );
        } else {
            $query->orderBy('csv_data.firstname')
                ->orderBy('csv_data.lastname')
                ->orderBy('csv_data.id');
        }

        $contacts = $query->paginate($validated['perPage'] ?? 30);

        return $this->paginatedResponse($contacts, $message);
    }

    private function contactFilterRules(bool $withPagination = false): array
    {
        $rules = [
            'search' => 'nullable|string|max:255',
            'countries' => 'nullable|array',
            'countries.*' => 'string|max:255',
            'specialities' => 'nullable|array',
            'specialities.*' => 'string|max:255',
            'specialties' => 'nullable|array',
            'specialties.*' => 'string|max:255',
            'sort_by' => 'nullable|string|in:'.implode(',', ContactSortingService::FIELDS),
            'sort_direction' => 'nullable|string|in:asc,desc',
        ];

        if ($withPagination) {
            $rules['perPage'] = 'nullable|integer|min:1|max:100';
            $rules['page'] = 'nullable|integer|min:1';
        }

        return $rules;
    }

    private function applyContactFilters(
        Builder $query,
        array $validated,
        ReferenceDataService $referenceData
    ): void {
        $countries = $referenceData->expandCountries($validated['countries'] ?? []);
        $requestedSpecialities = array_merge(
            $validated['specialities'] ?? [],
            $validated['specialties'] ?? []
        );
        $specialities = $referenceData->expandSpecialities($requestedSpecialities);

        $query->when($validated['search'] ?? null, function (Builder $query, string $search) {
            $query->where(function (Builder $query) use ($search) {
                $query->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%")
                    ->orWhere('speciality', 'like', "%{$search}%");
            });
        });

        $this->applyExpandedFilter($query, 'country', $countries);
        $this->applyExpandedFilter($query, 'speciality', $specialities);
    }

    private function applyExpandedFilter(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $query->where(function (Builder $query) use ($column, $values) {
            $nonEmptyValues = array_values(array_filter($values, fn ($value) => $value !== ''));

            if ($nonEmptyValues !== []) {
                $query->whereIn($column, $nonEmptyValues);
            }

            if (in_array('', $values, true)) {
                $query->orWhereNull($column)->orWhere($column, '');
            }
        });
    }

    private function paginatedResponse($paginator, string $message)
    {
        return response()->json([
            'data' => $paginator->items(),
            'status_code' => 200,
            'message' => $message,
            'status' => true,
            'perPage' => $paginator->perPage(),
            'currentPage' => $paginator->currentPage(),
            'total' => $paginator->total(),
            'totalPages' => $paginator->lastPage(),
            'is_next' => $paginator->hasMorePages(),
        ]);
    }

    private function tagData(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color,
            'description' => $tag->description,
            'contacts_count' => $tag->contacts_count ?? $tag->contacts()->count(),
            'created_by' => $tag->created_by,
            'created_at' => $tag->created_at?->toISOString(),
            'updated_at' => $tag->updated_at?->toISOString(),
        ];
    }

    private function uniqueSlug(string $name, ?int $ignoredTagId = null): string
    {
        $base = Str::slug($name) ?: 'tag';
        $slug = $base;
        $suffix = 2;

        while (Tag::query()
            ->where('slug', $slug)
            ->when($ignoredTagId, fn (Builder $query) => $query->whereKeyNot($ignoredTagId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function isAdministrator(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('tags.manage');
    }

    private function canManageMembership(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('tags.manage_contacts');
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
