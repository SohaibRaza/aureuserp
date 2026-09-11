<?php

use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/TestBootstrapHelper.php';

it('keeps a users default company in their allowed companies', function () {
    $company = Company::factory()->create();

    $user = User::factory()->create([
        'default_company_id' => $company->id,
    ]);

    expect($user->allowedCompanies()->whereKey($company->id)->exists())->toBeTrue();
});
