<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte des simulations budgétaires.
 *  - simulations : année visée, hypothèses globales (taux d'évolution), auteur ;
 *  - simulation_lignes : coefficient plus précis (2,5 % = 1,025).
 * La colonne moyenne_historique contient désormais le montant de référence
 * annuel (réalisé de l'exercice de référence, projeté sur 12 mois s'il est en cours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->unsignedSmallInteger('annee_cible')->nullable();
            $table->json('hypotheses')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::table('simulation_lignes', function (Blueprint $table) {
            $table->decimal('coefficient', 8, 4)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('simulation_lignes', function (Blueprint $table) {
            $table->decimal('coefficient', 5, 2)->default(1)->change();
        });
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn(['annee_cible', 'hypotheses', 'created_by']);
        });
    }
};
