<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 (suite) — clôture par écriture 6/7 → 12.
 *  - référence de la pièce de clôture sur l'exercice ;
 *  - les vues de reporting (balance, grand livre) utilisées par le tableau de
 *    bord et les indicateurs d'analyse excluent les pièces techniques :
 *      · clôture (sinon les comptes 6/7 de N tombent à zéro au 31/12),
 *      · à-nouveaux (sinon les cumuls « depuis l'origine » des comptes de
 *        bilan seraient comptés deux fois : historique + reprise des soldes).
 *    Leur comportement reste ainsi celui d'avant le lot 4.
 */
return new class extends Migration
{
    private const FILTRE = "me.type_piece NOT IN ('cloture', 'a_nouveau')";

    public function up(): void
    {
        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->unsignedBigInteger('id_piece_cloture')->nullable();
        });

        $this->vues(self::FILTRE);
    }

    public function down(): void
    {
        $this->vues('TRUE');

        Schema::table('exercice_comptable', function (Blueprint $table) {
            $table->dropColumn('id_piece_cloture');
        });
    }

    private function vues(string $filtre): void
    {
        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW public.vue_balance_generale AS
             SELECT c."Code_compte" AS code_compte,
                c."Libelle" AS libelle_compte,
                sc."Code_sous_compte" AS code_sous_compte,
                sc."Libelle" AS libelle_sous_compte,
                COALESCE(sum(le."Debit"), (0)::numeric) AS total_debit,
                COALESCE(sum(le."Credit"), (0)::numeric) AS total_credit,
                COALESCE((sum(le."Debit") - sum(le."Credit")), (0)::numeric) AS solde_final,
                me."Date_mouvement" AS date_mouvement
               FROM (((public.ligne_ecritures le
                 JOIN public.sous_comptes sc ON ((le."Id_Sous_compte" = sc."Id_Sous_compte")))
                 JOIN public.comptes c ON ((sc."Id_Compte" = c."Id_Compte")))
                 JOIN public.mouvement_ecritures me ON ((le."Id_Mouvement_ecriture" = me."Id_Mouvement_ecriture")))
              WHERE {$filtre}
              GROUP BY c."Code_compte", c."Libelle", sc."Code_sous_compte", sc."Libelle", me."Date_mouvement"
              ORDER BY c."Code_compte", sc."Code_sous_compte", me."Date_mouvement"
        SQL);

        DB::statement(<<<SQL
            CREATE OR REPLACE VIEW public.vue_grand_livre AS
             WITH sous_compte_totals AS (
                     SELECT sc_1."Id_Sous_compte",
                        sum(le_1."Debit") AS total_debit_sous_compte,
                        sum(le_1."Credit") AS total_credit_sous_compte,
                        (sum(le_1."Debit") - sum(le_1."Credit")) AS solde_final_sous_compte
                       FROM ((public.ligne_ecritures le_1
                         JOIN public.sous_comptes sc_1 ON ((le_1."Id_Sous_compte" = sc_1."Id_Sous_compte")))
                         JOIN public.mouvement_ecritures me ON ((le_1."Id_Mouvement_ecriture" = me."Id_Mouvement_ecriture")))
                      WHERE {$filtre}
                      GROUP BY sc_1."Id_Sous_compte"
                    ), compte_totals AS (
                     SELECT c_1."Id_Compte",
                        sum(le_1."Debit") AS total_debit_compte,
                        sum(le_1."Credit") AS total_credit_compte,
                        (sum(le_1."Debit") - sum(le_1."Credit")) AS solde_final_compte
                       FROM (((public.ligne_ecritures le_1
                         JOIN public.sous_comptes sc_1 ON ((le_1."Id_Sous_compte" = sc_1."Id_Sous_compte")))
                         JOIN public.comptes c_1 ON ((sc_1."Id_Compte" = c_1."Id_Compte")))
                         JOIN public.mouvement_ecritures me ON ((le_1."Id_Mouvement_ecriture" = me."Id_Mouvement_ecriture")))
                      WHERE {$filtre}
                      GROUP BY c_1."Id_Compte"
                    )
             SELECT c."Code_compte" AS code_compte,
                c."Libelle" AS libelle_compte,
                sc."Code_sous_compte" AS code_sous_compte,
                sc."Libelle" AS libelle_sous_compte,
                me."Date_mouvement" AS date_mouvement,
                me."Numero_piece" AS numero_piece,
                le."Libelle" AS libelle_ecriture,
                le."Debit",
                le."Credit",
                sum((le."Debit" - le."Credit")) OVER (PARTITION BY sc."Id_Sous_compte" ORDER BY me."Date_mouvement", le."Id_Ligne_ecriture") AS solde_progressif,
                sct.solde_final_sous_compte,
                ct.solde_final_compte
               FROM (((((public.ligne_ecritures le
                 JOIN public.sous_comptes sc ON ((le."Id_Sous_compte" = sc."Id_Sous_compte")))
                 JOIN public.comptes c ON ((sc."Id_Compte" = c."Id_Compte")))
                 JOIN public.mouvement_ecritures me ON ((le."Id_Mouvement_ecriture" = me."Id_Mouvement_ecriture")))
                 JOIN sous_compte_totals sct ON ((sc."Id_Sous_compte" = sct."Id_Sous_compte")))
                 JOIN compte_totals ct ON ((c."Id_Compte" = ct."Id_Compte")))
              WHERE {$filtre}
              ORDER BY c."Code_compte", sc."Code_sous_compte", me."Date_mouvement", le."Id_Ligne_ecriture"
        SQL);
    }
};
