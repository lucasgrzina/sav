<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Links every existing client staff profile to all establishments of its client,
     * so no current access or alert recipient is lost. Idempotent and DB-portable.
     */
    public function up(): void
    {
        $now = now()->toDateTimeString();

        $select = DB::table('establishments as e')
            ->join('user_profiles as up', function ($join) {
                $join->on('up.authenticatable_id', '=', 'e.client_id')
                    ->where('up.authenticatable_type', 'client');
            })
            ->join('roles as r', 'r.id', '=', 'up.role_id')
            ->whereIn('r.name', ['client-owner', 'client-manager', 'client-administrative'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('establishment_user_profile as x')
                    ->whereColumn('x.establishment_id', 'e.id')
                    ->whereColumn('x.user_profile_id', 'up.id');
            })
            ->select('e.id', 'up.id', DB::raw("'{$now}'"), DB::raw("'{$now}'"));

        DB::table('establishment_user_profile')->insertUsing(
            ['establishment_id', 'user_profile_id', 'created_at', 'updated_at'],
            $select,
        );
    }

    public function down(): void
    {
        // Intentionally empty: the table is dropped by the schema migration.
    }
};
