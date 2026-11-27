<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres de l'application (clé → valeur JSON), modifiables depuis l'écran
 * « Paramètres » au lieu d'intervenir en base ou dans le code.
 * Les valeurs absentes prennent la valeur par défaut définie dans
 * App\Services\Parametres : la table peut donc rester vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parametres')) {
            return;
        }
        Schema::create('parametres', function (Blueprint $table) {
            $table->string('cle', 100)->primary();
            $table->jsonb('valeur')->nullable();
            $table->unsignedBigInteger('modifie_par')->nullable();
            $table->timestamp('modifie_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
