<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Droit « administrateur » : seul un administrateur peut annuler une clôture
 * d'exercice. Personne ne l'est par défaut ; on le donne avec
 *   php artisan utilisateur:admin email@exemple.mg
 * (en attendant une vraie gestion des rôles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
