<?php

namespace Tests\Feature;

use App\Exports\CSVDataExport;
use App\Models\CSVData;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class TagExportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_export_uses_included_tag_union_then_removes_every_excluded_tag_contact(): void
    {
        $user = User::factory()->create(['access_type' => 'FULL']);
        [$x, $y, $z] = $this->createExampleTags($user);
        $contacts = $this->createExampleContacts($user);

        $x->contacts()->attach($this->ids($contacts, ['u1', 'u2', 'u3', 'u4']));
        $y->contacts()->attach($this->ids($contacts, ['u2', 'u5', 'u6']));
        $z->contacts()->attach($this->ids($contacts, ['u1', 'u2']));

        $includedOnly = new CSVDataExport($user->id, includeTagIds: [$x->id, $y->id]);
        $this->assertSame(6, $includedOnly->count());
        $this->assertCount(6, $includedOnly->collection());

        $export = new CSVDataExport(
            $user->id,
            includeTagIds: [$x->id, $y->id],
            excludeTagIds: [$z->id]
        );
        $emails = $export->collection()->pluck('Email')->all();

        $this->assertSame(4, $export->count());
        $this->assertEqualsCanonicalizing([
            $contacts['u3']->email,
            $contacts['u4']->email,
            $contacts['u5']->email,
            $contacts['u6']->email,
        ], $emails);
    }

    public function test_excluded_tags_can_be_used_without_included_tags(): void
    {
        $user = User::factory()->create(['access_type' => 'FULL']);
        [, , $z] = $this->createExampleTags($user);
        $searchKey = 'TagExport'.Str::random(12);
        $contacts = $this->createExampleContacts($user, $searchKey);
        $z->contacts()->attach($this->ids($contacts, ['u1', 'u2']));

        $export = new CSVDataExport(
            $user->id,
            text: $searchKey,
            excludeTagIds: [$z->id]
        );

        $this->assertSame(5, $export->count());
        $this->assertEqualsCanonicalizing([
            $contacts['u3']->email,
            $contacts['u4']->email,
            $contacts['u5']->email,
            $contacts['u6']->email,
            $contacts['u7']->email,
        ], $export->collection()->pluck('Email')->all());
    }

    public function test_export_api_rejects_unknown_tag_ids(): void
    {
        $user = User::factory()->create(['access_type' => 'FULL']);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson('/api/export-data', [
            'include_tag_ids' => [PHP_INT_MAX],
        ])->assertUnprocessable()->assertJsonValidationErrors('include_tag_ids.0');
    }

    private function createExampleTags(User $user): array
    {
        return collect(['X', 'Y', 'Z'])->map(function (string $label) use ($user) {
            $unique = Str::lower(Str::random(10));

            return Tag::create([
                'name' => $label.' '.$unique,
                'slug' => Str::lower($label).'-'.$unique,
                'created_by' => $user->id,
            ]);
        })->all();
    }

    private function createExampleContacts(User $user, ?string $searchKey = null): array
    {
        $searchKey ??= 'Export'.Str::random(12);
        $contacts = [];

        foreach (range(1, 7) as $number) {
            $key = 'u'.$number;
            $contacts[$key] = CSVData::create([
                'firstname' => $searchKey.$key,
                'lastname' => 'Contact',
                'email' => Str::lower(Str::random(12)).'@example.com',
                'phone' => '+9715'.random_int(10000000, 99999999),
                'country' => 'United Arab Emirates',
                'speciality' => 'Cardiology',
                'user_id' => $user->id,
            ]);
        }

        return $contacts;
    }

    private function ids(array $contacts, array $keys): array
    {
        return array_map(fn (string $key) => $contacts[$key]->id, $keys);
    }
}
