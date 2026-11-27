<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes de tiers (clients, fournisseurs, personnel…) et lettrage.
 *
 *  - tiers : fiche du tiers, avec son compte collectif par défaut (401…, 411…) ;
 *  - lettrages : un groupe de lignes validées d'un même compte et d'un même
 *    tiers dont les débits égalent les crédits (facture ↔ règlement) ;
 *  - ligne_ecritures.id_tiers / id_lettrage : rattachement des lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('type', 20)->default('client'); // client | fournisseur | personnel | autre
            $table->string('nom', 150);
            $table->string('nif', 50)->nullable();
            $table->string('stat', 50)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedSmallInteger('delai_paiement')->default(30); // jours
            $table->unsignedBigInteger('Id_Sous_compte')->nullable();     // compte collectif par défaut
            $table->foreign('Id_Sous_compte')->references('Id_Sous_compte')->on('sous_comptes')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['type', 'actif']);
        });

        Schema::create('lettrages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10);
            $table->unsignedBigInteger('Id_Sous_compte');
            $table->foreign('Id_Sous_compte')->references('Id_Sous_compte')->on('sous_comptes')->restrictOnDelete();
            $table->foreignId('id_tiers')->nullable()->constrained('tiers')->restrictOnDelete();
            $table->decimal('montant', 18, 2);
            $table->boolean('automatique')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['Id_Sous_compte', 'code']);
        });

        Schema::table('ligne_ecritures', function (Blueprint $table) {
            $table->foreignId('id_tiers')->nullable()->constrained('tiers')->restrictOnDelete();
            $table->foreignId('id_lettrage')->nullable()->constrained('lettrages')->nullOnDelete();
            $table->index(['Id_Sous_compte', 'id_tiers', 'id_lettrage']);
        });
    }

    public function down(): void
    {
        Schema::table('ligne_ecritures', function (Blueprint $table) {
            $table->dropIndex(['Id_Sous_compte', 'id_tiers', 'id_lettrage']);
            $table->dropConstrainedForeignId('id_lettrage');
            $table->dropConstrainedForeignId('id_tiers');
        });
        Schema::dropIfExists('lettrages');
        Schema::dropIfExists('tiers');
    }
};
