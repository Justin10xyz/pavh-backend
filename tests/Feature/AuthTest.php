<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_fetch_profile(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertOk();

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonFragment(['email' => $user->email]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');
    }
}
