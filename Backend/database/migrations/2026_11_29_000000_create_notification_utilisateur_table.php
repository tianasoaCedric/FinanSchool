<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État des notifications propre à chaque utilisateur (lue / supprimée).
 *
 * Les notifications restent des événements communs ; seul leur état de lecture
 * et leur masquage sont individuels. Absence de ligne = non lue, visible.
 * La colonne notifications.statut est conservée pour le module d'analyse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_utilisateur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('lu_a')->nullable();
            $table->timestamp('supprime_a')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'lu_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_utilisateur');
    }
};
