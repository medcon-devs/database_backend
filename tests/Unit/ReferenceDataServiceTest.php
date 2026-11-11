<?php

namespace Tests\Unit;

use App\Services\ReferenceDataService;
use Tests\TestCase;

class ReferenceDataServiceTest extends TestCase
{
    private ReferenceDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferenceDataService::class);
    }

    /** @dataProvider countryAliases */
    public function test_it_normalizes_country_aliases(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->service->normalizeCountry($raw));
    }

    public static function countryAliases(): array
    {
        return [
            ['UAE', 'United Arab Emirates'],
            ['Dubai', 'United Arab Emirates'],
            ['Abu dahbi', 'United Arab Emirates'],
            ['KSA', 'Saudi Arabia'],
            ['Jeddah', 'Saudi Arabia'],
            ["CÃ´te d'Ivoire", "Côte d'Ivoire"],
            ['DRC', 'Democratic Republic of the Congo'],
        ];
    }

    /** @dataProvider specialityAliases */
    public function test_it_normalizes_speciality_aliases(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->service->normalizeSpeciality($raw));
    }

    public static function specialityAliases(): array
    {
        return [
            ['GP', 'General Practitioner'],
            ['General Practioner', 'General Practitioner'],
            ['Cardiologist', 'Cardiology'],
            ['Cardio', 'Cardiology'],
            ['pharmacists', 'Pharmacy'],
            ['nurses', 'Nursing'],
            ['Internist', 'Internal Medicine'],
            ['Cardiolgy', 'Cardiology'],
            ['General practitional', 'General Practitioner'],
            ['Radiology specialist', 'Radiology'],
            ['Medical student / RAKMHSU', 'Medical Trainee'],
        ];
    }

    public function test_unknown_speciality_is_grouped_under_other(): void
    {
        $this->assertSame('Other', $this->service->normalizeSpeciality('qdw'));
        $this->assertSame('Other', $this->service->normalizeSpeciality('EVENT'));
    }

    public function test_it_normalizes_placeholder_values_to_null(): void
    {
        $this->assertNull($this->service->normalizeCountry('Country'));
        $this->assertNull($this->service->normalizeSpeciality('NULL'));
        $this->assertNull($this->service->normalizeSpeciality('-'));
    }
}
