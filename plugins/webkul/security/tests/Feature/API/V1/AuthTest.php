<?php

use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

function makeLoginUser(string $email): User
{
    return User::withoutEvents(fn (): User => User::factory()->create([
        'email' => $email,
    ]));
}

it('logs in and returns a bearer token', function () {
    $user = makeLoginUser('login@example.com');

    $response = $this->postJson('/admin/api/v1/login', [
        'email'    => 'login@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer');

    expect($response->json('token'))->not->toBeEmpty();
});

it('issues a token that never expires on its own', function () {
    $user = makeLoginUser('forever@example.com');

    $this->postJson('/admin/api/v1/login', [
        'email'    => 'forever@example.com',
        'password' => 'password',
    ])->assertOk();

    expect($user->tokens()->first()->expires_at)->toBeNull();
});

it('keeps earlier tokens valid when a new one is issued', function () {
    $user = makeLoginUser('multi@example.com');

    $this->postJson('/admin/api/v1/login', [
        'email'    => 'multi@example.com',
        'password' => 'password',
    ])->assertOk();

    $this->postJson('/admin/api/v1/login', [
        'email'    => 'multi@example.com',
        'password' => 'password',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(2)
        ->and($user->tokens()->whereNotNull('expires_at')->count())->toBe(0);
});
