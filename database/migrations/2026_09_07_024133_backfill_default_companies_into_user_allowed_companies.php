<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $missingDefaultCompanies = DB::table('users')
            ->select('users.id', 'users.default_company_id')
            ->whereNotNull('users.default_company_id')
            ->whereNotExists(function ($query) {
                $query
                    ->selectRaw('1')
                    ->from('user_allowed_companies')
                    ->whereColumn('user_allowed_companies.user_id', 'users.id')
                    ->whereColumn('user_allowed_companies.company_id', 'users.default_company_id');
            });

        DB::table('user_allowed_companies')->insertUsing(
            ['user_id', 'company_id'],
            $missingDefaultCompanies,
        );
    }

    public function down(): void
    {
        /**
         * Access rows are not removed because they may have become legitimate
         * user assignments after this migration ran.
         */
    }
};
