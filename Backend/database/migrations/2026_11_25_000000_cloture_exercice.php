<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 — clôture d'exercice, à-nouveaux et dotations aux amortissements.
 *  - type de pièce : normale, a_nouveau (ouverture N+1), dotation (amortissements) ;
 *  - traçabilité de la clôture sur l'exercice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->string('type_piece', 20)->default('normale');
            $table->index('type_piece');
        });

        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->timestamp('cloture_le')->nullable();
            $table->foreignId('cloture_par')->nullable()->constrained('users')->nullOnDelete();
            // Pièce d'à-nouveaux générée dans l'exercice suivant
            $table->unsignedBigInteger('id_piece_an')->nullable();
            $table->decimal('resultat_cloture', 19, 2)->nullable();
        });

        // Exercice qui démarre sur des à-nouveaux : l'historique antérieur est
        // remplacé par cette pièce pour le calcul des soldes cumulés.
        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->boolean('ouvert_par_a_nouveaux')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloture_par');
            $table->dropColumn(['cloture_le', 'id_piece_an', 'resultat_cloture', 'ouvert_par_a_nouveaux']);
        });
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->dropIndex(['type_piece']);
            $table->dropColumn('type_piece');
        });
    }
};
