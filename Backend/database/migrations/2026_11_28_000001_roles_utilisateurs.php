<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles des utilisateurs (admin / comptable / lecteur), compte actif ou
 * désactivé, date de dernière connexion.
 * is_admin est conservé et tenu synchronisé (role = admin) : il est encore lu
 * par la clôture et par le frontend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('comptable');
            }
            if (!Schema::hasColumn('users', 'actif')) {
                $table->boolean('actif')->default(true);
            }
            if (!Schema::hasColumn('users', 'derniere_connexion')) {
                $table->timestamp('derniere_connexion')->nullable();
            }
        });

        // Les administrateurs existants gardent leurs droits ; les autres
        // restent comptables (même accès qu'avant).
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'actif', 'derniere_connexion']);
        });
    }
};
