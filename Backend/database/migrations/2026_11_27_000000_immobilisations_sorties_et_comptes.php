<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registre des immobilisations :
 *  - sortie d'actif (cession / mise au rebut) : date, prix, pièce générée ;
 *  - compte d'amortissement explicite (évite les collisions de la règle 2Xyzzz → 28Xyzz) ;
 *  - taux facultatif pour les immobilisations non amortissables (terrains, en-cours…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            if (!Schema::hasColumn('immobilisations', 'date_sortie')) {
                $table->date('date_sortie')->nullable();
                $table->decimal('prix_cession', 15, 2)->nullable();
                $table->unsignedBigInteger('id_piece_cession')->nullable();
                $table->unsignedBigInteger('Id_Sous_compte_amortissement')->nullable();
            }
        });
        DB::statement('ALTER TABLE immobilisations ALTER COLUMN taux_amortissement_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('immobilisations', function (Blueprint $table) {
            $table->dropColumn(['date_sortie', 'prix_cession', 'id_piece_cession', 'Id_Sous_compte_amortissement']);
        });
    }
};
