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

class ContactSortingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_contact_search_sorts_before_pagination(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $key = 'Sort'.Str::random(10);
        $alpha = $this->contact($user, $key.' Alpha', 'zulu@example.test');
        $zulu = $this->contact($user, $key.' Zulu', 'alpha@example.test');

        $this->withToken($token)->postJson('/api/query-row', [
            'text' => $key,
            'sort_by' => 'firstname',
            'sort_direction' => 'desc',
            'page' => 1,
            'perPage' => 1,
        ])->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $zulu->id);

        $this->withToken($token)->postJson('/api/query-row', [
            'text' => $key,
            'sort_by' => 'email',
            'sort_direction' => 'asc',
        ])->assertOk()->assertJsonPath('data.0.id', $zulu->id);

        $this->assertNotSame($alpha->id, $zulu->id);
    }

    public function test_tag_and_available_contact_lists_support_the_same_sort_contract(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $tag = Tag::create([
            'name' => 'Sorting '.Str::random(10),
            'slug' => 'sorting-'.Str::lower(Str::random(10)),
            'created_by' => $user->id,
        ]);
        $key = 'Tagged'.Str::random(10);
        $alpha = $this->contact($user, $key.' Alpha');
        $zulu = $this->contact($user, $key.' Zulu');
        $tag->contacts()->attach([$alpha->id, $zulu->id]);

        $query = http_build_query([
            'search' => $key,
            'sort_by' => 'firstname',
            'sort_direction' => 'desc',
        ]);
        $this->withToken($token)->getJson("/api/tags/{$tag->id}/contacts?{$query}")
            ->assertOk()->assertJsonPath('data.0.id', $zulu->id);

        $availableAlpha = $this->contact($user, $key.' Available Alpha');
        $availableZulu = $this->contact($user, $key.' Available Zulu');
        $this->withToken($token)->getJson("/api/tags/{$tag->id}/available-contacts?{$query}")
            ->assertOk()->assertJsonPath('data.0.id', $availableZulu->id);

        $this->assertNotSame($availableAlpha->id, $availableZulu->id);
    }

    public function test_user_contact_list_and_ai_search_are_sortable(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $key = 'Intelli'.Str::random(10);
        $alpha = $this->contact($user, $key.' Alpha');
        $zulu = $this->contact($user, $key.' Zulu');

        $this->withToken($token)->postJson('/api/get_data', [
            'user_id' => $user->id,
            'sort_by' => 'firstname',
            'sort_direction' => 'desc',
        ])->assertOk()->assertJsonPath('data.0.id', $zulu->id);

        $this->withToken($token)->postJson('/api/ai-search', [
            'query' => $key,
            'sort_by' => 'firstname',
            'sort_direction' => 'asc',
        ])->assertOk()->assertJsonPath('data.0.id', $alpha->id);
    }

    public function test_unknown_sort_columns_are_rejected(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->withToken($token)->postJson('/api/query-row', [
            'sort_by' => 'password',
            'sort_direction' => 'asc',
        ])->assertUnprocessable()->assertJsonValidationErrors('sort_by');
    }

    public function test_contact_export_uses_the_requested_sorting(): void
    {
        [$user] = $this->authenticatedUser();
        $key = 'ExportSort'.Str::random(10);
        $this->contact($user, $key.' Alpha');
        $this->contact($user, $key.' Zulu');

        $rows = (new CSVDataExport(
            $user->id,
            [],
            [],
            $key,
            [],
            [],
            'firstname',
            'desc'
        ))->collection();

        $this->assertSame($key.' Zulu', $rows->first()['First Name']);
    }

    private function authenticatedUser(): array
    {
        $user = User::factory()->create(['access_type' => 'FULL']);

        return [$user, JWTAuth::fromUser($user)];
    }

    private function contact(User $user, string $firstname, ?string $email = null): CSVData
    {
        return CSVData::create([
            'firstname' => $firstname,
            'lastname' => 'Contact',
            'email' => $email ?? Str::lower(Str::random(12)).'@example.test',
            'phone' => '+9715'.random_int(10000000, 99999999),
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ]);
    }
}
