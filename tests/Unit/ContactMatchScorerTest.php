<?php

namespace Tests\Unit;

use App\Services\ContactMatchScorer;
use PHPUnit\Framework\TestCase;

class ContactMatchScorerTest extends TestCase
{
    private ContactMatchScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ContactMatchScorer;
    }

    public function test_misspelled_full_name_ranks_above_a_partial_name_collision(): void
    {
        $target = $this->scorer->score([
            'firstname' => 'Ali',
            'lastname' => 'Ahmad',
        ], 'Alo Ahmd');
        $partialCollision = $this->scorer->score([
            'firstname' => 'Ali',
            'lastname' => 'Hassan',
        ], 'Alo Ahmd');

        $this->assertGreaterThan($partialCollision['score'], $target['score']);
        $this->assertGreaterThanOrEqual(0.75, $target['score']);
        $this->assertContains('fuzzy_name', $target['reasons']);
    }

    public function test_reversed_first_and_last_name_is_a_near_exact_match(): void
    {
        $match = $this->scorer->score([
            'firstname' => 'Ali',
            'lastname' => 'Ahmad',
        ], 'Ahmad Ali');

        $this->assertSame(0.99, $match['score']);
        $this->assertSame(['reversed_name_order'], $match['reasons']);
    }

    public function test_email_and_formatted_phone_are_matched_as_identifiers(): void
    {
        $contact = [
            'email' => 'Doctor@example.com',
            'phone' => '+971 (50) 123-4567',
        ];

        $this->assertSame(1.0, $this->scorer->score($contact, 'doctor@example.com')['score']);
        $this->assertSame(1.0, $this->scorer->score($contact, '+971501234567')['score']);
    }

    public function test_arabic_full_name_is_matched_exactly(): void
    {
        $match = $this->scorer->score([
            'firstname' => 'علي',
            'lastname' => 'الأحمد',
        ], 'علي الأحمد');

        $this->assertSame(1.0, $match['score']);
        $this->assertSame(['exact_full_name'], $match['reasons']);
    }

    public function test_misspelled_organization_matches_the_email_domain(): void
    {
        $match = $this->scorer->scoreOrganization([
            'email' => 'majd.a@medcon-me.com',
        ], ['medcan']);

        $this->assertGreaterThanOrEqual(0.8, $match['score']);
        $this->assertContains('organization_email_domain', $match['reasons']);
    }

    public function test_name_can_match_email_local_part_when_name_fields_are_empty(): void
    {
        $match = $this->scorer->score([
            'firstname' => '',
            'lastname' => '',
            'email' => 'majd.a@medcon-me.com',
        ], 'majd');

        $this->assertGreaterThanOrEqual(0.9, $match['score']);
        $this->assertContains('email_local_identity', $match['reasons']);
    }
}
