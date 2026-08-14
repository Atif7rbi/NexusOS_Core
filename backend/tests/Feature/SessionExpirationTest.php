<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;

final class SessionExpirationTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_sanctum_bearer_session_has_an_absolute_twelve_hour_lifetime(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-08-14T08:00:00Z');
        $this->travelTo($issuedAt);

        [$user, $token] = $this->actingAsActiveUser();
        $createdAt = $user->tokens()->firstOrFail()->created_at;

        $this->assertSame(720, config('sanctum.expiration'));

        $this->travelTo($issuedAt->addMinutes(719));
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertOk();

        $this->assertTrue(
            $createdAt->equalTo(
                $user->tokens()->firstOrFail()->created_at,
            ),
            'Authenticated requests must not slide token creation time.',
        );

        $this->travelTo($issuedAt->addMinutes(720));
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertUnauthorized();
    }
}
