<?php

namespace Tests\Feature;

use App\Models\CSVData;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AiContactSearchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ai_search_ranks_a_typo_across_first_and_last_name_with_medical_filters(): void
    {
        $user = User::factory()->create(['access_type' => 'R']);
        $target = $this->createContact($user, [
            'firstname' => 'Nadim',
            'lastname' => 'Qorashi',
            'country' => 'Dubai',
            'speciality' => 'Cardiologist',
        ]);
        $this->createContact($user, [
            'firstname' => 'Nadeem',
            'lastname' => 'Hassan',
            'country' => 'Dubai',
            'speciality' => 'Cardiologist',
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/ai-search', [
            'query' => 'Find Nadeem Qorashi heart doctor in UAE',
            'perPage' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('interpretation.search_text', 'Nadeem Qorashi')
            ->assertJsonPath('interpretation.countries.0', 'United Arab Emirates')
            ->assertJsonPath('interpretation.specialities.0', 'Cardiology')
            ->assertJsonPath('search_meta.engine', 'medcon_fuzzy_v2');
    }

    public function test_ai_search_understands_reversed_name_order(): void
    {
        $user = User::factory()->create(['access_type' => 'R']);
        $target = $this->createContact($user, [
            'firstname' => 'Zaydon',
            'lastname' => 'Qorashi',
        ]);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/ai-search', [
            'query' => 'Qorashi Zaydon',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.search_match.reasons.0', 'reversed_name_order');
    }

    public function test_ai_search_matches_email_and_normalized_phone(): void
    {
        $user = User::factory()->create(['access_type' => 'R']);
        $email = 'smart-'.Str::lower(Str::random(12)).'@example.com';
        $target = $this->createContact($user, [
            'email' => $email,
            'phone' => '+971 (50) 987-6543',
        ]);
        $token = JWTAuth::fromUser($user);

        $this->withToken($token)->postJson('/api/ai-search', [
            'query' => "Find {$email}",
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.search_match.reasons.0', 'exact_email');
    }

    public function test_ai_search_filters_contacts_that_have_a_phone_number(): void
    {
        $user = User::factory()->create(['access_type' => 'R']);
        $withPhone = $this->createContact($user, ['phone' => '+971501234567']);
        $this->createContact($user, ['phone' => null]);
        $this->createContact($user, ['phone' => '']);

        $this->withToken(JWTAuth::fromUser($user))->postJson('/api/ai-search', [
            'query' => 'find all where phone not null',
            'perPage' => 100,
        ])->assertOk()
            ->assertJsonPath('interpretation.search_text', '')
            ->assertJsonPath('interpretation.phone_filter', 'has_phone')
            ->assertJsonPath('data.0.id', $withPhone->id);
    }

    private function createContact(User $user, array $attributes): CSVData
    {
        return CSVData::create(array_merge([
            'firstname' => 'Search',
            'lastname' => 'Contact',
            'email' => Str::lower(Str::random(12)).'@example.com',
            'phone' => '+9715'.random_int(10000000, 99999999),
            'country' => 'United Arab Emirates',
            'speciality' => 'Cardiology',
            'user_id' => $user->id,
        ], $attributes));
    }
}
