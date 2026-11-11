<?php

namespace Tests\Unit;

use App\Services\AiSearchIntentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSearchIntentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => null]);
    }

    public function test_local_fallback_extracts_name_speciality_and_country(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('Find Ali cardiologist in UAE');

        $this->assertSame('Ali', $intent['search_text']);
        $this->assertSame(['United Arab Emirates'], $intent['countries']);
        $this->assertSame(['Cardiology'], $intent['specialities']);
        $this->assertSame('local', $intent['parser']);
    }

    public function test_local_fallback_understands_existing_aliases(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('looking for Alo GP from KSA');

        $this->assertSame('Alo', $intent['search_text']);
        $this->assertSame(['Saudi Arabia'], $intent['countries']);
        $this->assertSame(['General Practitioner'], $intent['specialities']);
    }

    public function test_smart_local_search_removes_business_role_noise(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('Find Majd IT in UAE');

        $this->assertSame('Majd', $intent['search_text']);
        $this->assertSame(['United Arab Emirates'], $intent['countries']);
        $this->assertSame([], $intent['specialities']);
        $this->assertSame(0.8, $intent['confidence']);
    }

    public function test_smart_local_search_understands_arabic(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('ابحث عن علي طبيب قلب في الإمارات');

        $this->assertSame('علي', $intent['search_text']);
        $this->assertSame(['United Arab Emirates'], $intent['countries']);
        $this->assertSame(['Cardiology'], $intent['specialities']);
    }

    public function test_smart_local_search_corrects_country_and_speciality_typos(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('looking for Alo cardilogist in Kuweit');

        $this->assertSame('Alo', $intent['search_text']);
        $this->assertSame(['Kuwait'], $intent['countries']);
        $this->assertSame(['Cardiology'], $intent['specialities']);
    }

    public function test_smart_local_search_prioritizes_an_exact_email_identifier(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('please find user@example.com in UAE');

        $this->assertSame('user@example.com', $intent['search_text']);
        $this->assertSame(['United Arab Emirates'], $intent['countries']);
        $this->assertSame(0.88, $intent['confidence']);
    }

    public function test_local_provider_never_calls_openai_even_when_a_key_exists(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openai.provider' => 'local',
            'services.openai.api_key' => 'unused-test-key',
        ]);

        $intent = app(AiSearchIntentService::class)->interpret('Find Ali in UAE');

        $this->assertSame('local', $intent['parser']);
        Http::assertNothingSent();
    }

    public function test_smart_local_search_understands_common_medical_language(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('Please find Sara skin doctor in Dubai');

        $this->assertSame('Sara', $intent['search_text']);
        $this->assertSame(['United Arab Emirates'], $intent['countries']);
        $this->assertSame(['Dermatology'], $intent['specialities']);
    }

    public function test_smart_local_search_understands_specialist_job_titles(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('I need Omar oncologist from Saudi Arabia');

        $this->assertSame('Omar', $intent['search_text']);
        $this->assertSame(['Saudi Arabia'], $intent['countries']);
        $this->assertSame(['Oncology'], $intent['specialities']);
    }

    public function test_smart_local_search_separates_an_employer_clue_from_the_name(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('find majd working in medcan');

        $this->assertSame('majd', $intent['search_text']);
        $this->assertSame(['medcan'], $intent['organization_terms']);
    }

    public function test_smart_local_search_understands_phone_presence_filter(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('find all where phone not null');

        $this->assertSame('', $intent['search_text']);
        $this->assertSame('has_phone', $intent['phone_filter']);
        $this->assertSame('local', $intent['parser']);
    }

    public function test_smart_local_search_understands_missing_phone_filter(): void
    {
        $intent = app(AiSearchIntentService::class)
            ->interpret('show contacts without phone number');

        $this->assertSame('', $intent['search_text']);
        $this->assertSame('no_phone', $intent['phone_filter']);
    }

    public function test_explicit_phone_filter_does_not_depend_on_openai(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openai.provider' => 'openai',
            'services.openai.api_key' => 'test-key',
        ]);

        $intent = app(AiSearchIntentService::class)
            ->interpret('find all where phone is not null');

        $this->assertSame('', $intent['search_text']);
        $this->assertSame('has_phone', $intent['phone_filter']);
        $this->assertSame('local', $intent['parser']);
        Http::assertNothingSent();
    }
}
