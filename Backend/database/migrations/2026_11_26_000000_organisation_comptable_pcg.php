<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 5 — organisation comptable PCG 2005.
 *  - clôture mensuelle des périodes (intangibilité / chronologie) ;
 *  - contre-passation des pièces validées (correction sans suppression) ;
 *  - régularisations à extourner au premier jour de l'exercice suivant ;
 *  - affectation du résultat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercice_comptable', function (Blueprint $table) {
            // Dernier jour du dernier mois clôturé : plus aucune écriture à cette date ou avant
            $table->date('periode_cloturee_au')->nullable();
            $table->unsignedBigInteger('id_piece_affectation')->nullable();
        });

        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            // Pièce contre-passée (extourne) par celle-ci
            $table->unsignedBigInteger('id_piece_origine')->nullable()->index();
            // Régularisation (CCA, PCA, charges à payer…) à extourner au 1er jour de N+1
            $table->boolean('a_extourner')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->dropIndex(['id_piece_origine']);
            $table->dropColumn(['id_piece_origine', 'a_extourner']);
        });
        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->dropColumn(['periode_cloturee_au', 'id_piece_affectation']);
        });
    }
};
