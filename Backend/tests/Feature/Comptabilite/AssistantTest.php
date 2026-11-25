<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\User;
use App\Services\Assistant\Analyseur;
use App\Services\Assistant\Assistant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assistant (chatbot) sans IA : analyse des questions, choix de l'intention,
 * réponses calculées sur les écritures validées, compatibilité du module d'analyse.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class AssistantTest extends TestCase
{
    use DatabaseTransactions;

    private array $sc = [];
    private Journal $journal;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Sanctum::actingAs(User::factory()->create(['name' => 'Comptable Test']));

        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        ExerciceComptable::create(['Annee_fiscale' => 2090, 'Date_debut' => '2090-01-01', 'Date_fin' => '2090-12-31', 'Statut' => 'OUVERT']);

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['512998' => 'Banque Assistant', '531998' => 'Caisse Assistant', '706998' => 'Prestations Assistant',
                  '601998' => 'Achats Assistant', '101998' => 'Capital Assistant'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TAS', 'Libelle' => 'Journal test assistant',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
    }

    private function piece(string $debit, string $credit, float $montant, string $date = '2090-03-10', string $action = 'valider', ?string $ref = 'FAC-AS'): array
    {
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => $date, 'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => "Opération {$debit}/{$credit}", 'Reference' => $ref, 'action' => $action,
            'lignes' => [
                ['Id_Sous_compte' => $this->sc[$debit], 'Debit' => $montant, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc[$credit], 'Debit' => 0, 'Credit' => $montant],
            ],
        ])->assertCreated()->json('piece');
    }

    private function demander(string $question): array
    {
        return $this->postJson('/api/chat/sending', ['message' => $question, 'session_id' => 'test-assistant'])
            ->assertOk()->json();
    }

    // ---- Analyseur -----------------------------------------------------

    public function test_l_analyseur_extrait_compte_piece_et_periode(): void
    {
        $a = new Analyseur();

        // « cherche » n'est plus pris pour un code de compte
        $q = $a->analyser('je cherche le sous-compte 512998');
        $this->assertSame(['512998'], $q->codesComptes);

        $q = $a->analyser('Solde du 512998 au 31/03/2090');
        $this->assertSame(['512998'], $q->codesComptes, 'les nombres de la date ne sont pas des comptes');
        $this->assertSame('2090-03-31', $q->periode['fin']);

        $q = $a->analyser('solde 531998 au 30 juin 2090');
        $this->assertSame('2090-06-30', $q->periode['fin']);
        $this->assertSame(['531998'], $q->codesComptes);

        $q = $a->analyser('résultat en mars 2090');
        $this->assertSame(['2090-03-01', '2090-03-31'], [$q->periode['debut'], $q->periode['fin']]);

        $q = $a->analyser('résultat exercice 2090');
        $this->assertSame(['2090-01-01', '2090-12-31'], [$q->periode['debut'], $q->periode['fin']]);
        $this->assertSame([], $q->codesComptes, "l'année n'est pas un compte");

        $this->assertSame(['brouillon' => 128], $a->analyser('pourquoi BR-128 ?')->piece);
        $this->assertSame(['numero' => 'TAS2090-0003'], $a->analyser('pièce tas2090-0003')->piece);
        $this->assertSame('TAS', $a->analyser('détail du journal TAS')->journal['code']);
        $this->assertSame([], $a->analyser('solde du compte 999999999')->codesComptes, 'code inexistant ignoré');
    }

    public function test_le_bon_type_de_question_est_reconnu(): void
    {
        $assistant = new Assistant();
        $attendus = [
            'Solde du compte 512998' => 'SoldeCompte',
            'solde de la caisse' => 'SoldeCompte',
            'derniers mouvements du 531998' => 'MouvementsCompte',
            'grand livre de la banque' => 'MouvementsCompte',
            'quel est le compte de la caisse ?' => 'RechercheCompte',
            'Quel est le chiffre d\'affaires ?' => 'Resultat',
            'résultat 2090' => 'Resultat',
            'combien en trésorerie ?' => 'Tresorerie',
            'tresorie' => 'Tresorerie', // faute de frappe
            'pièces à valider' => 'PiecesAValider',
            'combien de brouillons' => 'PiecesAValider',
            'pourquoi BR-12 n\'est pas validable ?' => 'EtatPiece',
            'que faire avant de clôturer ?' => 'PreparationCloture',
            'liste des journaux' => 'Journaux',
            'aide' => 'Aide',
            'bonjour' => 'Aide',
        ];
        foreach ($attendus as $question => $intention) {
            $this->assertSame($intention, $assistant->intentionRetenue($question), "« {$question} »");
        }
        $this->assertNull($assistant->intentionRetenue('quelle est la météo demain'));
    }

    // ---- Réponses calculées ---------------------------------------------

    public function test_solde_et_mouvements_d_un_compte(): void
    {
        $this->piece('512998', '101998', 1_000_000, '2090-01-05');
        $this->piece('601998', '512998', 250_000, '2090-02-10');
        $this->piece('601998', '512998', 50_000, '2090-04-10');
        $this->piece('601998', '512998', 999, '2090-02-11', 'brouillon'); // brouillon : ignoré

        $r = $this->demander('Solde du compte 512998 au 31/03/2090');
        $this->assertStringContainsString('750 000,00 Ar (débiteur)', $r['response']);
        $this->assertSame('/grand-livres/512', $r['liens'][0]['url']);

        $r = $this->demander('solde de la banque assistant au 30/04/2090');
        $this->assertStringContainsString('512998', $r['response']);
        $this->assertStringContainsString('700 000,00 Ar (débiteur)', $r['response']);

        $r = $this->demander('derniers mouvements du compte 512998');
        $this->assertStringContainsString('3 mouvement(s)', $r['response']);
        $this->assertStringContainsString('Crédit 50 000,00 Ar', $r['response']);
        $this->assertStringNotContainsString('999,00', $r['response']);
    }

    public function test_la_recherche_par_nom_porte_sur_des_mots_entiers(): void
    {
        $idCompte = DB::table('sous_comptes')->where('Id_Sous_compte', $this->sc['512998'])->value('Id_Compte');
        SousCompte::create(['Code_sous_compte' => '512997', 'Libelle' => 'Chèques à encaisser assistant', 'Id_Compte' => $idCompte]);

        $trouves = \App\Services\Assistant\PlanComptable::parLibelle(['caisse', 'assistant'])->pluck('code')->all();
        $this->assertSame('531998', $trouves[0], 'la caisse arrive en premier');
        $this->assertNotContains('512997', array_slice($trouves, 0, 1));

        $r = $this->demander('solde de la caisse assistant');
        $this->assertStringContainsString('531998', $r['response']);
        $this->assertStringNotContainsString('512997', $r['response'], '« encaisser » ne correspond pas à « caisse »');
    }

    public function test_resultat_et_tresorerie(): void
    {
        $this->piece('512998', '706998', 400_000, '2090-03-01');
        $this->piece('531998', '706998', 100_000, '2090-03-02');
        $this->piece('601998', '512998', 150_000, '2090-03-03');

        $r = $this->demander("Quel est le chiffre d'affaires en mars 2090 ?");
        $this->assertStringContainsString("Chiffre d'affaires : **500 000,00 Ar**", $r['response']);
        $this->assertStringContainsString('Résultat net : 350 000,00 Ar (bénéfice)', $r['response']);

        $r = $this->demander('trésorerie au 31/03/2090');
        $this->assertStringContainsString('512998', $r['response']);
        $this->assertStringContainsString('531998', $r['response']);
    }

    public function test_pieces_a_valider_et_etat_d_une_piece(): void
    {
        $ok = $this->piece('601998', '512998', 1_000, '2090-05-01', 'brouillon');
        $sansRef = $this->piece('601998', '512998', 2_000, '2090-05-02', 'brouillon', null);
        $validee = $this->piece('601998', '512998', 3_000, '2090-05-03');

        $r = $this->demander('pièces à valider du journal TAS');
        $this->assertStringContainsString('2 pièce(s) en brouillon', $r['response']);
        $this->assertStringContainsString('1 prête(s)', $r['response']);
        $this->assertStringContainsString("BR-{$sansRef['id']}", $r['response']);
        $this->assertStringContainsString('Référence de la pièce justificative manquante', $r['response']);

        $r = $this->demander("pourquoi BR-{$sansRef['id']} n'est pas validable ?");
        $this->assertStringContainsString('Brouillon non validable', $r['response']);
        $this->assertSame("/ecriture/{$sansRef['id']}", $r['liens'][0]['url']);

        $this->assertStringContainsString('prêt à être validé', $this->demander("état de BR-{$ok['id']}")['response']);
        $r = $this->demander("pièce {$validee['numero']}");
        $this->assertStringContainsString('**Validée**', $r['response']);
        $this->assertStringContainsString('Comptable Test', $r['response']);
    }

    public function test_preparation_de_cloture_et_journaux(): void
    {
        $this->piece('601998', '512998', 1_000, '2090-05-01', 'brouillon');

        $r = $this->demander('que faire avant de clôturer ?');
        $this->assertStringContainsString("exercice **2090**", $r['response']);
        $this->assertStringContainsString('[À faire] Toutes les pièces sont validées', $r['response']);
        $this->assertStringContainsString('La clôture est bloquée', $r['response']);

        $this->assertStringContainsString('**TAS** — Journal test assistant', $this->demander('liste des journaux')['response']);
        $r = $this->demander('journal TAS');
        $this->assertStringContainsString('Pièces : 1', $r['response']);
        $this->assertSame("/ecritures-journal/{$this->journal->Id_Journal}", $r['liens'][0]['url']);
    }

    public function test_question_incomprise_et_historique(): void
    {
        $r = $this->demander('quelle est la météo demain');
        $this->assertStringContainsString("Je n'ai pas compris", $r['response']);
        $this->assertNotEmpty($r['suggestions']);

        $historique = $this->getJson('/api/chat/history/test-assistant')->assertOk()->json();
        $this->assertCount(1, $historique);
        $this->assertSame('quelle est la météo demain', $historique[0]['message']);
    }

    public function test_conversations_listees_rouvertes_et_supprimees(): void
    {
        $this->piece('512998', '101998', 1_000, '2090-01-05');
        $this->postJson('/api/chat/sending', ['message' => 'Solde du compte 512998', 'session_id' => 'conv-1'])->assertOk();
        $this->postJson('/api/chat/sending', ['message' => 'liste des journaux', 'session_id' => 'conv-1'])->assertOk();
        $this->postJson('/api/chat/sending', ['message' => 'aide', 'session_id' => 'conv-2'])->assertOk();
        // Le module d'analyse n'apparaît pas dans les conversations de l'assistant comptable
        $this->postJson('/api/chat/send', ['message' => 'bonjour', 'session_id' => 'conv-analyse'])->assertOk();

        $convs = collect($this->getJson('/api/chat/conversations')->assertOk()->json())->keyBy('session_id');
        $this->assertEqualsCanonicalizing(['conv-1', 'conv-2'], $convs->keys()->all());
        $this->assertSame('Solde du compte 512998', $convs['conv-1']['titre']);
        $this->assertSame(2, $convs['conv-1']['messages']);

        // Les liens sont conservés pour la réouverture
        $historique = $this->getJson('/api/chat/history/conv-1')->assertOk()->json();
        $this->assertSame('/grand-livres/512', $historique[0]['liens'][0]['url']);

        // Un autre utilisateur ne voit ni ne supprime ces conversations
        Sanctum::actingAs(User::factory()->create());
        $this->assertSame([], $this->getJson('/api/chat/conversations')->json());
        $this->assertSame(0, $this->deleteJson('/api/chat/conversations/conv-1')->json('supprimes'));
        $this->assertSame([], $this->getJson('/api/chat/history/conv-1')->json());
    }

    // ---- Module d'analyse : réponses inchangées -------------------------

    public function test_le_module_d_analyse_garde_ses_reponses(): void
    {
        $envoyer = fn ($m) => $this->postJson('/api/chat/send', ['message' => $m, 'session_id' => 'test-analyse'])->assertOk()->json();

        $r = $envoyer('Bonjour');
        $this->assertSame("Bonjour ! Je suis votre assistant pour l'analyse financière scolaire. Comment puis-je vous aider ?", $r['response']);
        $this->assertSame(['Un résumé des indicateurs financiers de cette année.'], $r['suggestions']);

        $r = $envoyer('quelle est la météo');
        $this->assertStringStartsWith('Je suis votre assistant financier pour établissements scolaires.', $r['response']);
    }
}
