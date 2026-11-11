<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiAuthenticationResponseTest extends TestCase
{
    public function test_protected_api_always_returns_json_401_without_authentication(): void
    {
        $this->get('/api/countries')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJson([
                'data' => null,
                'status_code' => 401,
                'message' => 'Unauthenticated.',
                'status' => false,
            ]);
    }
}
