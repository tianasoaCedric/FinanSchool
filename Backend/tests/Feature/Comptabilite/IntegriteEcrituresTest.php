<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lot 1 — intégrité des écritures (partie double, exercice ouvert, verrouillage).
 *
 * Ces tests s'exécutent sur la base Postgres « finance_test » (copie du dump),
 * chaque test étant annulé par une transaction. Lancement :
 *   docker compose exec -e DB_DATABASE=finance_test backend php artisan test --filter=Comptabilite
 */
class IntegriteEcrituresTest extends TestCase
{
    use DatabaseTransactions;

    private Journal $journal;
    private int $compteA;
    private int $compteB;

    protected function setUp(): void
    {
        parent::setUp();

        // Garde-fou : ne jamais lancer ces tests sur la base de travail.
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }

        // Situation de référence : 2025 ouvert, 2024 clôturé.
        ExerciceComptable::where('Annee_fiscale', '!=', 2025)->update(['Statut' => 'CLOTURE']);
        ExerciceComptable::where('Annee_fiscale', 2025)->update(['Statut' => 'OUVERT']);

        [$this->compteA, $this->compteB] = SousCompte::orderBy('Id_Sous_compte')->limit(2)->pluck('Id_Sous_compte')->all();
        $this->journal = Journal::create([
            'Code' => 'TST',
            'Libelle' => 'Journal de test',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
            'Id_Sous_compte' => $this->compteB,
        ]);
    }

    private function connecter(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function creerPiece(string $date = '2025-06-15'): array
    {
        return $this->postJson('/api/mouvements', ['Date_mouvement' => $date, 'Id_Journal' => $this->journal->Id_Journal])
            ->assertOk()
            ->json('mouvement');
    }

    private function ligne(array $piece, float $debit, float $credit, ?int $compte = null): array
    {
        return [
            'Libelle' => 'Test',
            'Reference' => 'FAC-TEST',   // pièce justificative (exigée à la validation)
            'Debit' => $debit,
            'Credit' => $credit,
            'Id_Mouvement_ecriture' => $piece['Id_Mouvement_ecriture'],
            'Id_Journal' => $this->journal->Id_Journal,
            'Id_Sous_compte' => $compte ?? $this->compteA,
        ];
    }

    private function pieceEquilibree(float $montant = 1000): array
    {
        $piece = $this->creerPiece();
        $this->postJson('/api/lignes', $this->ligne($piece, $montant, 0, $this->compteA))->assertOk();
        $this->postJson('/api/lignes', $this->ligne($piece, 0, $montant, $this->compteB))->assertOk();
        return $piece;
    }

    // 1. Authentification -------------------------------------------------

    public function test_les_routes_comptables_exigent_une_connexion(): void
    {
        $this->getJson('/api/mouvements-complets')->assertUnauthorized();
        $this->postJson('/api/lignes/valider-toutes')->assertUnauthorized();
        $this->postJson('/api/exercices/1/cloturer')->assertUnauthorized();

        $this->connecter();
        $this->getJson('/api/mouvements-complets')->assertOk();
    }

    public function test_les_routes_du_module_analyse_ne_sont_pas_modifiees(): void
    {
        // Le module Analyse garde son fonctionnement : pas d'authentification ajoutée.
        $this->getJson('/api/axes')->assertStatus(200);
    }

    // 2. Saisie d'une ligne -----------------------------------------------

    public function test_une_ligne_negative_ou_double_sens_est_refusee_mais_0_0_tolere_en_brouillon(): void
    {
        $this->connecter();
        $piece = $this->creerPiece();

        $this->postJson('/api/lignes', $this->ligne($piece, -100, 0))->assertStatus(422);
        $this->postJson('/api/lignes', $this->ligne($piece, 100, 50))->assertStatus(422);
        $this->postJson('/api/lignes', $this->ligne($piece, 0, 0))->assertOk();
    }

    public function test_le_statut_ne_peut_pas_etre_force_a_la_saisie(): void
    {
        $this->connecter();
        $piece = $this->creerPiece();
        $id = $this->postJson('/api/lignes', $this->ligne($piece, 100, 0) + ['statut' => 'valide'])
            ->assertOk()->json('data.Id_Ligne_ecriture');

        $this->assertSame('brouillon', LigneEcriture::find($id)->statut);
    }

    // 3. Exercice ---------------------------------------------------------

    public function test_impossible_de_saisir_dans_un_exercice_cloture(): void
    {
        $this->connecter();
        $this->postJson('/api/mouvements', ['Date_mouvement' => '2024-05-10', 'Id_Journal' => $this->journal->Id_Journal])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "L'exercice 2024 est clôturé : aucune écriture ne peut y être saisie ou modifiée."]);
    }

    // 4. Validation d'une pièce ------------------------------------------

    public function test_une_piece_desequilibree_ne_peut_pas_etre_validee(): void
    {
        $this->connecter();
        $piece = $this->creerPiece();
        $this->postJson('/api/lignes', $this->ligne($piece, 1000, 0, $this->compteA))->assertOk();
        $this->postJson('/api/lignes', $this->ligne($piece, 0, 900, $this->compteB))->assertOk();

        $this->postJson("/api/mouvements/{$piece['Id_Mouvement_ecriture']}/valider")->assertStatus(422);
        $this->assertSame(0, LigneEcriture::where('Id_Mouvement_ecriture', $piece['Id_Mouvement_ecriture'])->where('statut', 'valide')->count());
    }

    public function test_une_piece_equilibree_est_reellement_validee(): void
    {
        $this->connecter();
        $piece = $this->pieceEquilibree();

        $this->postJson("/api/mouvements/{$piece['Id_Mouvement_ecriture']}/valider")->assertOk();

        $statuts = LigneEcriture::where('Id_Mouvement_ecriture', $piece['Id_Mouvement_ecriture'])->pluck('statut')->unique()->all();
        $this->assertSame(['valide'], $statuts);
    }

    // 5. Verrouillage après validation -----------------------------------

    public function test_une_piece_validee_est_figee(): void
    {
        $this->connecter();
        $piece = $this->pieceEquilibree();
        $id = $piece['Id_Mouvement_ecriture'];
        $this->postJson("/api/mouvements/{$id}/valider")->assertOk();
        $ligne = LigneEcriture::where('Id_Mouvement_ecriture', $id)->first();

        $this->putJson("/api/lignes/{$ligne->Id_Ligne_ecriture}", $this->ligne($piece, 5, 0))->assertStatus(422);
        $this->deleteJson("/api/lignes/{$ligne->Id_Ligne_ecriture}")->assertStatus(422);
        $this->postJson('/api/lignes', $this->ligne($piece, 5, 0))->assertStatus(422);
        $this->deleteJson("/api/mouvements/{$id}")->assertStatus(422);
        $this->postJson("/api/mouvements/{$id}/solder")->assertStatus(422);
        $this->putJson("/api/mouvements/{$id}", ['Date_mouvement' => '2025-07-01'])->assertStatus(422);
        $this->assertTrue(MouvementEcriture::whereKey($id)->exists());
    }

    // 6. Validation en masse ---------------------------------------------

    public function test_la_validation_en_masse_ignore_les_pieces_desequilibrees(): void
    {
        $this->connecter();
        $bonne = $this->pieceEquilibree();
        $mauvaise = $this->creerPiece();
        $this->postJson('/api/lignes', $this->ligne($mauvaise, 500, 0, $this->compteA))->assertOk();
        $this->postJson('/api/lignes', $this->ligne($mauvaise, 0, 400, $this->compteB))->assertOk();

        $reponse = $this->postJson('/api/lignes/valider-toutes')->assertOk();

        $this->assertContains($mauvaise['Numero_piece'], array_column($reponse->json('pieces_ignorees'), 'numero'));
        $this->assertSame(0, LigneEcriture::where('Id_Mouvement_ecriture', $mauvaise['Id_Mouvement_ecriture'])->where('statut', 'valide')->count());
        $this->assertSame(2, LigneEcriture::where('Id_Mouvement_ecriture', $bonne['Id_Mouvement_ecriture'])->where('statut', 'valide')->count());
    }

    // 7. Numérotation ------------------------------------------------------

    public function test_numerotation_sequentielle_par_journal_et_par_annee(): void
    {
        $this->connecter();
        $this->assertSame('TST2025-0001', $this->creerPiece()['Numero_piece']);
        $this->assertSame('TST2025-0002', $this->creerPiece()['Numero_piece']);

        MouvementEcriture::create(['Date_mouvement' => '2025-03-01', 'Id_Journal' => $this->journal->Id_Journal, 'Numero_piece' => 'TST2025-0041']);
        $this->assertSame('TST2025-0042', $this->creerPiece()['Numero_piece']);
    }

    // 8. Import ------------------------------------------------------------

    public function test_un_import_avec_une_piece_desequilibree_n_insere_rien(): void
    {
        $this->connecter();
        $codeA = SousCompte::find($this->compteA)->Code_sous_compte;
        $codeB = SousCompte::find($this->compteB)->Code_sous_compte;
        $csv = "Date;Sous-compte;Libelle;Debit;Credit;Reference;Journal\n"
             . "15/06/2025;{$codeA};Achat;1 000,00;;REF-1;TST\n"
             . "15/06/2025;{$codeB};Achat;;900,00;REF-1;TST\n";
        $avant = MouvementEcriture::count();

        $this->post('/api/ecritures/import', ['file_ecritures' => UploadedFile::fake()->createWithContent('import.csv', $csv)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Aucune donnée n'a été importée"]);

        $this->assertSame($avant, MouvementEcriture::count());
    }

    public function test_un_import_equilibre_avec_montants_au_format_francais_est_accepte(): void
    {
        $this->connecter();
        $codeA = SousCompte::find($this->compteA)->Code_sous_compte;
        $codeB = SousCompte::find($this->compteB)->Code_sous_compte;
        $csv = "Date;Sous-compte;Libelle;Debit;Credit;Reference;Journal\n"
             . "15/06/2025;{$codeA};Achat;1 000,50;;REF-2;TST\n"
             . "15/06/2025;{$codeB};Achat;;1 000,50;REF-2;TST\n";

        $this->post('/api/ecritures/import', ['file_ecritures' => UploadedFile::fake()->createWithContent('import.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk();

        $piece = MouvementEcriture::where('Id_Journal', $this->journal->Id_Journal)->with('lignes')->latest('Id_Mouvement_ecriture')->first();
        $this->assertEquals(1000.50, (float) $piece->lignes->sum('Debit'));
        $this->assertEquals(1000.50, (float) $piece->lignes->sum('Credit'));
    }

    // 9. Exercice : plus d'effet de bord sur une lecture ------------------

    public function test_verifier_exercice_courant_ne_modifie_aucun_statut(): void
    {
        $this->connecter();
        $avant = ExerciceComptable::orderBy('Id_Exercice_comptable')->pluck('Statut', 'Id_Exercice_comptable')->all();

        $this->getJson('/api/exercices/verifier/courant');

        $apres = ExerciceComptable::orderBy('Id_Exercice_comptable')->pluck('Statut', 'Id_Exercice_comptable')->all();
        $this->assertSame($avant, $apres);
    }
}
