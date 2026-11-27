<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité des imports d'écritures :
 *  - chaque pièce importée garde l'import d'origine (id_import) ;
 *  - l'historique conserve les montants, la période, les erreurs détaillées
 *    et l'éventuelle annulation (statut « annulé »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            if (!Schema::hasColumn('mouvement_ecritures', 'id_import')) {
                $table->foreignId('id_import')->nullable()->constrained('import_histories')->nullOnDelete();
            }
        });

        Schema::table('import_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('import_histories', 'total_debit')) {
                $table->decimal('total_debit', 18, 2)->nullable();
                $table->date('date_min')->nullable();
                $table->date('date_max')->nullable();
                $table->jsonb('details')->nullable();
                $table->timestamp('annule_le')->nullable();
                $table->unsignedBigInteger('annule_par')->nullable();
            }
        });

        DB::statement('ALTER TABLE import_histories DROP CONSTRAINT IF EXISTS import_histories_statut_check');
        DB::statement("ALTER TABLE import_histories ADD CONSTRAINT import_histories_statut_check CHECK (statut IN ('réussi', 'échec', 'annulé'))");
    }

    public function down(): void
    {
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_import');
        });
        Schema::table('import_histories', function (Blueprint $table) {
            $table->dropColumn(['total_debit', 'date_min', 'date_max', 'details', 'annule_le', 'annule_par']);
        });
        DB::statement('ALTER TABLE import_histories DROP CONSTRAINT IF EXISTS import_histories_statut_check');
        DB::statement("ALTER TABLE import_histories ADD CONSTRAINT import_histories_statut_check CHECK (statut IN ('réussi', 'échec'))");
    }
};
