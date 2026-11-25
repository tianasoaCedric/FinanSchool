<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens proposés par l'assistant (vers les écrans de l'application),
 * conservés pour les réafficher quand on rouvre une conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('liens')->nullable();
            // comptabilite | analyse : chaque assistant ne liste que ses conversations
            $table->string('profil', 20)->default('comptabilite');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['liens', 'profil']);
        });
    }
};
