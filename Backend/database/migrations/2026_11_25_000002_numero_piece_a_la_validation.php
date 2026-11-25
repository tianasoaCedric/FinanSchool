<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le numéro définitif d'une pièce est désormais attribué à la validation
 * (numérotation continue des écritures validées, PCG 2005). Les brouillons
 * existants perdent leur numéro provisoire : ils en recevront un en étant
 * validés. Les pièces validées conservent le leur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE mouvement_ecritures m
               SET "Numero_piece" = NULL
             WHERE "Numero_piece" IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM ligne_ecritures l
                    WHERE l."Id_Mouvement_ecriture" = m."Id_Mouvement_ecriture"
                      AND l.statut = 'valide'
               )
        SQL);
    }

    public function down(): void
    {
        // Irréversible : les numéros provisoires des brouillons ne sont pas restaurés.
    }
};
