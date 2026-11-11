<?php

namespace Tests\Unit;

use App\Services\WhatsAppPhoneService;
use Tests\TestCase;

class WhatsAppPhoneServiceTest extends TestCase
{
    private WhatsAppPhoneService $phones;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.default_country_code' => '971']);
        $this->phones = app(WhatsAppPhoneService::class);
    }

    public function test_it_adds_the_uae_country_code_to_local_mobile_numbers(): void
    {
        $this->assertSame('971566167139', $this->phones->normalize('566167139'));
        $this->assertSame('971566167139', $this->phones->normalize('056 616 7139'));
    }

    public function test_it_preserves_numbers_that_already_have_an_international_prefix(): void
    {
        $this->assertSame('971566167139', $this->phones->normalize('+971 56 616 7139'));
        $this->assertSame('971566167139', $this->phones->normalize('00971 56 616 7139'));
        $this->assertSame('971566167139', $this->phones->normalize('971566167139'));
    }

    public function test_it_does_not_prefix_long_international_numbers_without_a_plus(): void
    {
        $this->assertSame('15551717511', $this->phones->normalize('15551717511'));
    }
}
