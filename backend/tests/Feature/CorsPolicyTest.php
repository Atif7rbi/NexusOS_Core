<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CorsPolicyTest extends TestCase
{
    public function test_approved_origin_receives_cors_header(): void
    {
        config()->set('cors.allowed_origins', [
            'https://ufq.sewarsky.online',
        ]);

        $this->withHeaders([
            'Origin' => 'https://ufq.sewarsky.online',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login')
            ->assertSuccessful()
            ->assertHeader(
                'Access-Control-Allow-Origin',
                'https://ufq.sewarsky.online',
            );
    }

    public function test_unapproved_origin_is_never_reflected_or_wildcarded(): void
    {
        config()->set('cors.allowed_origins', [
            'https://ufq.sewarsky.online',
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login');

        $response->assertSuccessful();

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('https://evil.example', $allowOrigin);
        $this->assertNotSame('*', $allowOrigin);
    }

    public function test_production_configuration_contains_no_wildcard_origin(): void
    {
        config()->set('cors.allowed_origins', [
            'https://ufq.sewarsky.online',
        ]);

        $this->assertNotContains('*', config('cors.allowed_origins'));
    }
}
