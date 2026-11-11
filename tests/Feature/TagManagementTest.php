<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class TagManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_administrator_can_create_update_list_and_delete_a_tag(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $token = JWTAuth::fromUser($administrator);
        $name = 'Dubai Event '.Str::random(8);

        $response = $this->withToken($token)->postJson('/api/tags', [
            'name' => $name,
            'color' => '#2563eb',
            'description' => 'Contacts invited to the Dubai event.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', $name)
            ->assertJsonPath('data.color', '#2563EB')
            ->assertJsonPath('data.contacts_count', 0);

        $tagId = $response->json('data.id');

        $this->withToken($token)
            ->getJson('/api/tags?search='.urlencode($name))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $tagId);

        $this->withToken($token)->patchJson("/api/tags/{$tagId}", [
            'name' => 'Updated '.$name,
            'color' => '#DC2626',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated '.$name)
            ->assertJsonPath('data.color', '#DC2626');

        $contact = $this->createContact($administrator, 'Preserved', 'United Arab Emirates', 'Cardiology');
        Tag::findOrFail($tagId)->contacts()->attach($contact->id);

        $this->withToken($token)
            ->deleteJson("/api/tags/{$tagId}")
            ->assertOk()
            ->assertJsonPath('data.detached_contacts', 1);

        $this->assertDatabaseMissing('tags', ['id' => $tagId]);
        $this->assertDatabaseHas('csv_data', ['id' => $contact->id]);
    }

    public function test_non_administrator_cannot_create_edit_or_delete_tags(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $readOnlyUser = User::factory()->create(['access_type' => 'R']);
        $tag = Tag::create([
            'name' => 'Protected '.Str::random(8),
            'slug' => 'protected-'.Str::lower(Str::random(8)),
            'created_by' => $administrator->id,
        ]);
        $token = JWTAuth::fromUser($readOnlyUser);

        $this->withToken($token)->postJson('/api/tags', [
            'name' => 'Not Allowed '.Str::random(8),
        ])->assertForbidden();

        $this->withToken($token)->patchJson("/api/tags/{$tag->id}", [
            'name' => 'Not Allowed',
        ])->assertForbidden();

        $this->withToken($token)->deleteJson("/api/tags/{$tag->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_editor_can_bulk_add_list_filter_remove_and_replace_tag_contacts(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $editor = User::factory()->create(['access_type' => 'RWE']);
        $tag = Tag::create([
            'name' => 'VIP '.Str::random(8),
            'slug' => 'vip-'.Str::lower(Str::random(8)),
            'created_by' => $administrator->id,
        ]);
        $ali = $this->createContact($administrator, 'Ali', 'United Arab Emirates', 'Cardiology');
        $sara = $this->createContact($administrator, 'Sara', 'Saudi Arabia', 'Cardiology');
        $availableContactName = 'Omar'.Str::random(12);
        $omar = $this->createContact($administrator, $availableContactName, 'United Arab Emirates', 'Dermatology');
        $token = JWTAuth::fromUser($editor);

        $this->withToken($token)->postJson("/api/tags/{$tag->id}/contacts", [
            'contact_ids' => [$ali->id, $sara->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.tag.contacts_count', 2)
            ->assertJsonCount(2, 'data.attached_contact_ids');

        $this->withToken($token)
            ->getJson("/api/tags/{$tag->id}/contacts?countries[]=United%20Arab%20Emirates&specialities[]=Cardiology")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ali->id);

        $this->withToken($token)
            ->getJson("/api/tags/{$tag->id}/available-contacts?search={$availableContactName}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $omar->id);

        $this->withToken($token)->deleteJson("/api/tags/{$tag->id}/contacts", [
            'contact_ids' => [$ali->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.tag.contacts_count', 1)
            ->assertJsonPath('data.detached_contacts', 1);

        $this->withToken($token)->putJson("/api/tags/{$tag->id}/contacts", [
            'contact_ids' => [$omar->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.tag.contacts_count', 1)
            ->assertJsonFragment(['attached_contact_ids' => [$omar->id]])
            ->assertJsonFragment(['detached_contact_ids' => [$sara->id]]);

        $this->assertDatabaseHas('csv_data_tag', [
            'tag_id' => $tag->id,
            'csv_data_id' => $omar->id,
        ]);
        $this->assertDatabaseMissing('csv_data_tag', [
            'tag_id' => $tag->id,
            'csv_data_id' => $sara->id,
        ]);

        $this->assertDatabaseHas('csv_data', ['id' => $omar->id]);
    }

    public function test_read_only_user_can_view_tags_but_cannot_change_membership(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $readOnlyUser = User::factory()->create(['access_type' => 'R']);
        $tag = Tag::create([
            'name' => 'Visible '.Str::random(8),
            'slug' => 'visible-'.Str::lower(Str::random(8)),
            'created_by' => $administrator->id,
        ]);
        $contact = $this->createContact($administrator, 'Read Only', 'United Arab Emirates', 'Cardiology');
        $token = JWTAuth::fromUser($readOnlyUser);

        $this->withToken($token)->getJson("/api/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tag->id);

        $this->withToken($token)->postJson("/api/tags/{$tag->id}/contacts", [
            'contact_ids' => [$contact->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('csv_data_tag', [
            'tag_id' => $tag->id,
            'csv_data_id' => $contact->id,
        ]);
    }

    public function test_tag_contact_filters_expand_unified_country_and_speciality_values(): void
    {
        $administrator = User::factory()->create(['access_type' => 'FULL']);
        $tag = Tag::create([
            'name' => 'Unified filters '.Str::random(8),
            'slug' => 'unified-filters-'.Str::lower(Str::random(8)),
            'created_by' => $administrator->id,
        ]);
        $searchKey = 'Alias'.Str::random(12);
        $uae = $this->createContact($administrator, $searchKey.'A', 'UAE', 'Cardiologist');
        $dubai = $this->createContact($administrator, $searchKey.'B', 'Dubai', 'Cardio');
        $canonical = $this->createContact($administrator, $searchKey.'C', 'United Arab Emirates', 'Cardiology');
        $saudi = $this->createContact($administrator, $searchKey.'D', 'Saudi Arabia', 'Cardiology');
        $token = JWTAuth::fromUser($administrator);
        $filter = "search={$searchKey}&countries[]=United%20Arab%20Emirates&specialties[]=Cardiology";

        $this->withToken($token)
            ->getJson("/api/tags/{$tag->id}/available-contacts?{$filter}")
            ->assertOk()
            ->assertJsonPath('total', 3);

        $idsResponse = $this->withToken($token)
            ->getJson("/api/tags/{$tag->id}/available-contact-ids?{$filter}")
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        $this->assertEqualsCanonicalizing(
            [$uae->id, $dubai->id, $canonical->id],
            $idsResponse->json('data.contact_ids')
        );

        $tag->contacts()->attach([$uae->id, $dubai->id, $canonical->id, $saudi->id]);

        $this->withToken($token)
            ->getJson("/api/tags/{$tag->id}/contacts?{$filter}")
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    private function createContact(User $owner, string $firstName, string $country, string $speciality): CSVData
    {
        return CSVData::create([
            'firstname' => $firstName,
            'lastname' => 'Contact',
            'email' => Str::lower(Str::random(12)).'@example.com',
            'phone' => '+9715'.random_int(10000000, 99999999),
            'country' => $country,
            'speciality' => $speciality,
            'user_id' => $owner->id,
        ]);
    }
}
