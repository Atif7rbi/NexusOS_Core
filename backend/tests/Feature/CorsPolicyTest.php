<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class CorsPolicyTest extends TestCase
{
    public function test_approved_origin_receives_cors_header(): void
    {
        config()->set('cors.allowed_origins', [
            'https://tenant.example.test',
        ]);

        $this->withHeaders([
            'Origin' => 'https://tenant.example.test',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login')
            ->assertSuccessful()
            ->assertHeader(
                'Access-Control-Allow-Origin',
                'https://tenant.example.test',
            );
    }

    public function test_unapproved_origin_is_never_reflected_or_wildcarded(): void
    {
        config()->set('cors.allowed_origins', [
            'https://tenant.example.test',
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

    public function test_config_rejects_wildcard_even_if_environment_contains_it(): void
    {
        $original = getenv('CORS_ALLOWED_ORIGINS');

        try {
            putenv('CORS_ALLOWED_ORIGINS=https://tenant.example.test,*');

            /** @var array<string, mixed> $cors */
            $cors = require config_path('cors.php');

            $this->assertSame(
                ['https://tenant.example.test'],
                $cors['allowed_origins'],
            );
        } finally {
            if ($original === false) {
                putenv('CORS_ALLOWED_ORIGINS');
            } else {
                putenv('CORS_ALLOWED_ORIGINS='.$original);
            }
        }
    }
}
