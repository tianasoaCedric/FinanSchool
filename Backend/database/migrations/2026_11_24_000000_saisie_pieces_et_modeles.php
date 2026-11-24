<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot « Saisie » :
 *  - en-tête de pièce complet (libellé, référence, justificatif, auteur) ;
 *  - modèles d'écriture réutilisables (paie, écolages, factures…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->string('Libelle', 255)->nullable();
            $table->string('Reference', 100)->nullable();
            $table->string('justificatif_path')->nullable();
            $table->string('justificatif_nom')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_saisie')->nullable()->useCurrent();
        });

        Schema::create('modeles_ecriture', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150)->unique();
            $table->unsignedBigInteger('Id_Journal')->nullable();
            $table->string('libelle', 255)->nullable();
            // [{ Id_Sous_compte, Libelle, sens: 'debit'|'credit', montant: number|null }]
            $table->jsonb('lignes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('Id_Journal')->references('Id_Journal')->on('journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modeles_ecriture');
        Schema::table('mouvement_ecritures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['Libelle', 'Reference', 'justificatif_path', 'justificatif_nom', 'date_saisie']);
        });
    }
};
