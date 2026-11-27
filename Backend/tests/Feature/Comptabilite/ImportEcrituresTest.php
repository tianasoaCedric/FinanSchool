<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\import\ImportHistory;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import d'écritures (lignes lues par le navigateur) : aperçu, import tout ou
 * rien, traçabilité des pièces, historique et annulation.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class ImportEcrituresTest extends TestCase
{
    use DatabaseTransactions;

    private string $codeA;
    private string $codeB;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        ExerciceComptable::where('Annee_fiscale', '!=', 2025)->update(['Statut' => 'CLOTURE']);
        ExerciceComptable::where('Annee_fiscale', 2025)->update(['Statut' => 'OUVERT', 'periode_cloturee_au' => null]);
        [$this->codeA, $this->codeB] = SousCompte::orderBy('Id_Sous_compte')->limit(2)->pluck('Code_sous_compte')->all();
        Journal::create([
            'Code' => 'TIM', 'Libelle' => 'Journal test import',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function ligne(string $compte, string $debit, string $credit, string $ref = 'R1', string $date = '15/06/2025'): array
    {
        return ['date' => $date, 'compte' => $compte, 'libelle' => 'Test import', 'debit' => $debit, 'credit' => $credit, 'reference' => $ref, 'journal' => 'TIM'];
    }

    private function deuxPieces(): array
    {
        return [
            $this->ligne($this->codeA, '1 000,00', ''),
            $this->ligne($this->codeB, '', '1 000,00'),
            $this->ligne($this->codeA, '250.5', '', 'R2', '2025-06-20'),
            $this->ligne($this->codeB, '', '250,50', 'R2', '2025-06-20'),
        ];
    }

    public function test_l_apercu_detaille_les_lignes_et_les_pieces_sans_rien_enregistrer(): void
    {
        $avant = MouvementEcriture::count();
        $lignes = array_merge($this->deuxPieces(), [
            $this->ligne('999999', '10', '', 'R3'),            // compte inconnu
            $this->ligne($this->codeA, '10', '10', 'R3'),      // débit et crédit
            ['date' => '31/02/2025', 'compte' => $this->codeA, 'debit' => '5', 'journal' => 'TIM'],   // date invalide
        ]);

        $r = $this->postJson('/api/ecritures/import/analyse', ['lignes' => $lignes])->assertOk();

        $this->assertSame(7, $r->json('resume.lignes'));
        $this->assertSame(3, $r->json('resume.lignes_en_erreur'));
        $this->assertEquals(1250.5, $r->json('resume.total_debit'));
        $this->assertSame(1, $r->json('lignes.0.piece'));
        $this->assertSame(2, $r->json('lignes.2.piece'));
        $this->assertStringContainsString('introuvable', $r->json('lignes.4.erreurs.0'));
        $this->assertSame($avant, MouvementEcriture::count());
    }

    public function test_un_import_valide_cree_des_brouillons_rattaches_a_l_import(): void
    {
        $r = $this->postJson('/api/ecritures/import/lignes', ['lignes' => $this->deuxPieces(), 'fichier' => 'juin.xlsx'])
            ->assertOk()->assertJsonPath('historique.statut', 'réussi');

        $id = $r->json('historique.id');
        $pieces = MouvementEcriture::where('id_import', $id)->with('lignes')->get();
        $this->assertCount(2, $pieces);
        $this->assertTrue($pieces->flatMap->lignes->every(fn ($l) => $l->statut === 'brouillon'));
        $this->assertEquals(1250.5, ImportHistory::find($id)->total_debit);

        // Filtre de la liste des pièces par import
        $this->getJson("/api/pieces?import={$id}")->assertOk()->assertJsonPath('total', 2);
    }

    public function test_une_erreur_bloque_tout_et_est_historisee(): void
    {
        $avant = MouvementEcriture::count();
        $lignes = $this->deuxPieces();
        $lignes[1]['credit'] = '900';

        $this->postJson('/api/ecritures/import/lignes', ['lignes' => $lignes, 'fichier' => 'faux.csv'])
            ->assertStatus(422)->assertJsonPath('historique.statut', 'échec');

        $this->assertSame($avant, MouvementEcriture::count());
        $h = ImportHistory::where('nom_fichier', 'faux.csv')->latest('id')->first();
        $this->assertStringContainsString('déséquilibrée', $h->details['erreurs'][0]);
    }

    public function test_annuler_un_import_supprime_ses_brouillons(): void
    {
        $id = $this->postJson('/api/ecritures/import/lignes', ['lignes' => $this->deuxPieces(), 'fichier' => 'a-annuler.csv'])->json('historique.id');

        $this->postJson("/api/ecritures/imports/{$id}/annuler")->assertOk()->assertJsonPath('historique.statut', 'annulé');
        $this->assertSame(0, MouvementEcriture::where('id_import', $id)->count());
        $this->assertDatabaseHas('audit_comptable', ['action' => 'import.annulation', 'objet_id' => $id]);
    }

    public function test_un_import_dont_une_piece_est_validee_ne_peut_plus_etre_annule(): void
    {
        $id = $this->postJson('/api/ecritures/import/lignes', ['lignes' => $this->deuxPieces(), 'fichier' => 'valide.csv'])->json('historique.id');
        $piece = MouvementEcriture::where('id_import', $id)->first();
        LigneEcriture::where('Id_Mouvement_ecriture', $piece->Id_Mouvement_ecriture)->update(['statut' => 'valide']);

        $this->postJson("/api/ecritures/imports/{$id}/annuler")->assertStatus(422);
        $this->assertSame(2, MouvementEcriture::where('id_import', $id)->count());
    }

    public function test_l_historique_se_filtre_et_indique_si_l_import_est_annulable(): void
    {
        $id = $this->postJson('/api/ecritures/import/lignes', ['lignes' => $this->deuxPieces(), 'fichier' => 'histo-unique.csv'])->json('historique.id');

        $r = $this->getJson('/api/ecritures/imports/historique?statut=réussi&q=histo-unique')->assertOk();
        $this->assertSame($id, $r->json('data.0.id'));
        $this->assertTrue($r->json('data.0.annulable'));
        $this->assertSame(2, $r->json('data.0.pieces_restantes'));
        $this->assertNotNull($r->json('compteurs'));
    }
}
