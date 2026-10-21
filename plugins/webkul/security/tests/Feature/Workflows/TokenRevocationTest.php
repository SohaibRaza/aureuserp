<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
});

function makeTokenUser(): User
{
    return User::withoutEvents(fn (): User => User::factory()->create());
}

it('revokes every api token when revokeApiTokens is called', function () {
    $user = makeTokenUser();

    $user->createToken('api-token');
    $user->createToken('api-token');

    expect($user->tokens()->count())->toBe(2);

    $user->revokeApiTokens();

    expect($user->tokens()->count())->toBe(0);
});

it('revokes api tokens when a password reset completes', function () {
    $user = makeTokenUser();

    $user->createToken('api-token');

    event(new PasswordReset($user));

    expect($user->tokens()->count())->toBe(0);
});

it('keeps api tokens when the password column is rehashed on a normal save', function () {
    $user = makeTokenUser();

    $this->actingAs($user);

    $user->createToken('api-token');

    $user->password = Hash::make('rehashed-password');

    $user->save();

    expect($user->tokens()->count())->toBe(1);
});
