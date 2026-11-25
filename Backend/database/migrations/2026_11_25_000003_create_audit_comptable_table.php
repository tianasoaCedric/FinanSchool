<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piste d'audit comptable (PCG 2005 : traçabilité des enregistrements).
 *
 * Une ligne par action (création, modification, validation, clôture…), avec
 * l'auteur, la date et l'état avant / après. La table est en ajout seul : un
 * trigger PostgreSQL refuse toute modification ou suppression, même directe
 * en SQL.
 *
 * Pas de clé étrangère vers users : la suppression d'un utilisateur ne doit
 * ni modifier ni effacer l'historique ; son nom est conservé dans user_nom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_comptable', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('date_action')->useCurrent();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nom', 255)->nullable();
            $table->string('action', 50);
            $table->string('objet_type', 50);
            $table->unsignedBigInteger('objet_id')->nullable();
            $table->string('objet_libelle', 255)->nullable();
            $table->jsonb('avant')->nullable();
            $table->jsonb('apres')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('ip', 45)->nullable();

            $table->index('date_action');
            $table->index(['objet_type', 'objet_id']);
            $table->index('action');
            $table->index('user_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_comptable_lecture_seule() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'La piste d''audit est en lecture seule : aucune modification ni suppression n''est permise.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_comptable_pas_de_modification
                BEFORE UPDATE OR DELETE ON audit_comptable
                FOR EACH ROW EXECUTE FUNCTION audit_comptable_lecture_seule();

            CREATE TRIGGER audit_comptable_pas_de_vidage
                BEFORE TRUNCATE ON audit_comptable
                FOR EACH STATEMENT EXECUTE FUNCTION audit_comptable_lecture_seule();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_comptable');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_comptable_lecture_seule()');
    }
};
