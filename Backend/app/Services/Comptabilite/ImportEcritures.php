<?php

namespace App\Services\Comptabilite;

use App\Console\Commands\NotificationImport;
use App\Models\import\ImportHistory;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Tiers\Tiers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import d'écritures à partir de lignes { date, compte, libelle, debit,
 * credit, reference, journal, tiers } (fichier lu par le navigateur ou CSV).
 *
 * Règles (inchangées) :
 *  - chaque ligne : date valide dans un exercice ouvert et un mois non clôturé,
 *    sous-compte et journal existants, montant positif d'un seul côté ;
 *  - regroupement en pièces : même date + référence + journal ; sans référence,
 *    lignes consécutives de même date et journal, une nouvelle pièce
 *    commençant dès que la précédente est équilibrée ;
 *  - chaque pièce : au moins deux lignes et équilibrée ;
 *  - tout ou rien : à la moindre erreur, rien n'est enregistré ;
 *  - les pièces sont créées en brouillon, rattachées à l'import (id_import),
 *    ce qui permet de les retrouver et d'annuler l'import tant qu'aucune n'est
 *    validée.
 */
class ImportEcritures
{
    public const MAX_LIGNES = 20000;

    private array $comptes = [];
    private array $journaux = [];
    private array $tiers = [];

    /** Aperçu détaillé, sans rien enregistrer. */
    public function analyser(array $lignes): array
    {
        $this->charger();
        $resultat = [];
        foreach (array_values($lignes) as $i => $l) {
            $r = $this->analyserLigne($i + 1, $l);
            if ($r !== null) {
                $resultat[] = $r;
            }
        }

        $pieces = $this->regrouper($resultat);
        $position = array_flip(array_column($resultat, 'ligne'));   // n° de ligne => index
        foreach ($pieces as $p => $piece) {
            foreach ($piece['lignes'] as $n) {
                $resultat[$position[$n]]['piece'] = $p + 1;
            }
        }

        $erreursLignes = array_sum(array_map(fn ($r) => count($r['erreurs']), $resultat));
        $erreursPieces = count(array_filter($pieces, fn ($p) => $p['erreur']));
        $valides = array_filter($resultat, fn ($r) => !$r['erreurs']);

        return [
            'lignes' => $resultat,
            'pieces' => $pieces,
            'resume' => [
                'lignes' => count($resultat),
                'pieces' => count($pieces),
                'lignes_en_erreur' => count($resultat) - count($valides),
                'pieces_en_erreur' => $erreursPieces,
                'erreurs' => $erreursLignes + $erreursPieces,
                'total_debit' => round(array_sum(array_column($valides, 'debit')), 2),
                'total_credit' => round(array_sum(array_column($valides, 'credit')), 2),
                'date_min' => $valides ? min(array_column($valides, 'date_iso')) : null,
                'date_max' => $valides ? max(array_column($valides, 'date_iso')) : null,
            ],
        ];
    }

    /**
     * Importe si (et seulement si) l'analyse ne relève aucune erreur.
     * Renvoie [analyse, historique].
     */
    public function importer(array $lignes, string $nomFichier): array
    {
        $analyse = $this->analyser($lignes);
        $resume = $analyse['resume'];

        if ($resume['erreurs'] > 0 || $resume['lignes'] === 0) {
            $messages = $this->messagesErreur($analyse);
            $historique = ImportHistory::create([
                'nom_fichier' => $nomFichier,
                'imported_by' => Auth::id(),
                'nombre_mouvements' => 0,
                'nombre_lignes' => 0,
                'statut' => 'échec',
                'erreur' => $resume['lignes'] === 0 ? 'Fichier vide.' : "{$resume['erreurs']} erreur(s) : aucune écriture importée.",
                'details' => ['erreurs' => array_slice($messages, 0, 200)],
            ]);
            NotificationImport::notifyImportError(Auth::id(), $nomFichier, implode(', ', array_slice($messages, 0, 10)));
            return [$analyse, $historique];
        }

        $historique = DB::transaction(function () use ($analyse, $nomFichier, $resume) {
            $historique = ImportHistory::create([
                'nom_fichier' => $nomFichier,
                'imported_by' => Auth::id(),
                'nombre_mouvements' => $resume['pieces'],
                'nombre_lignes' => $resume['lignes'],
                'statut' => 'réussi',
                'total_debit' => $resume['total_debit'],
                'date_min' => $resume['date_min'],
                'date_max' => $resume['date_max'],
            ]);

            $parNumero = array_column($analyse['lignes'], null, 'ligne');
            foreach ($analyse['pieces'] as $piece) {
                $journal = $this->journaux[$piece['journal']];
                $mouvement = MouvementEcriture::create([
                    'Date_mouvement' => $piece['date'],
                    'Id_Journal' => $journal,
                    'Reference' => $piece['reference'] !== '' ? $piece['reference'] : null,
                    'created_by' => Auth::id(),
                ]);
                $mouvement->forceFill(['id_import' => $historique->id])->save();

                foreach ($piece['lignes'] as $n) {
                    $l = $parNumero[$n];
                    LigneEcriture::create([
                        'Libelle' => $l['libelle'],
                        'Debit' => $l['debit'],
                        'Credit' => $l['credit'],
                        'Reference' => $piece['reference'] !== '' ? $piece['reference'] : null,
                        'Id_Mouvement_ecriture' => $mouvement->Id_Mouvement_ecriture,
                        'Id_Journal' => $journal,
                        'Id_Sous_compte' => $this->comptes[$l['compte']]['id'],
                        'id_tiers' => $l['id_tiers'],
                        'statut' => EcritureGuard::STATUT_BROUILLON,
                    ]);
                }
            }

            Audit::enregistrer(Audit::IMPORT_ECRITURES, 'import', $historique->id, "Import {$nomFichier}", null, null, [
                'fichier' => $nomFichier,
                'pieces' => $resume['pieces'],
                'lignes' => $resume['lignes'],
                'total' => $resume['total_debit'],
                'statut' => 'brouillon',
            ]);
            return $historique;
        });

        NotificationImport::notifyImportSuccess(Auth::id(), $nomFichier, $resume['pieces'], $resume['lignes']);
        return [$analyse, $historique];
    }

    /** Supprime les pièces d'un import réussi, si aucune n'a été validée. */
    public function annuler(ImportHistory $import): int
    {
        if ($import->statut !== 'réussi') {
            throw ValidationException::withMessages(['import' => ["Seul un import réussi peut être annulé (statut actuel : {$import->statut})."]]);
        }
        $pieces = MouvementEcriture::with('lignes')->where('id_import', $import->id)->get();
        $validees = $pieces->filter(fn ($p) => $p->lignes->contains(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE));
        if ($validees->isNotEmpty()) {
            throw ValidationException::withMessages(['import' => [$validees->count() . " pièce(s) de cet import sont déjà validées : l'import ne peut plus être annulé. Contre-passez-les si nécessaire."]]);
        }

        return DB::transaction(function () use ($import, $pieces) {
            foreach ($pieces as $piece) {
                EcritureGuard::assertPieceModifiable($piece);   // exercice / mois encore ouverts
                Audit::piece(Audit::PIECE_SUPPRESSION, $piece, Audit::etatPiece($piece), ['origine' => "annulation de l'import {$import->nom_fichier}"]);
                $piece->lignes()->delete();
                $piece->delete();
            }
            $import->forceFill(['statut' => 'annulé', 'annule_le' => now(), 'annule_par' => Auth::id()])->save();
            Audit::enregistrer(Audit::IMPORT_ANNULATION, 'import', $import->id, "Import {$import->nom_fichier}", null, null, ['pieces_supprimees' => $pieces->count()]);
            return $pieces->count();
        });
    }

    /** Lit un CSV (ancienne interface) : date;sous_compte;libelle;debit;credit;reference;journal[;tiers]. */
    public static function lignesDepuisCsv(string $chemin, string $separateur): array
    {
        $h = fopen($chemin, 'r');
        fgetcsv($h, 0, $separateur);      // en-tête
        $lignes = [];
        while (($row = fgetcsv($h, 0, $separateur)) !== false) {
            $lignes[] = array_combine(
                ['date', 'compte', 'libelle', 'debit', 'credit', 'reference', 'journal', 'tiers'],
                array_pad(array_slice($row, 0, 8), 8, '')
            );
        }
        fclose($h);
        return $lignes;
    }

    // ------------------------------------------------------------------

    private function charger(): void
    {
        $this->comptes = SousCompte::get(['Id_Sous_compte', 'Code_sous_compte', 'Libelle'])
            ->mapWithKeys(fn ($s) => [(string) $s->Code_sous_compte => ['id' => $s->Id_Sous_compte, 'libelle' => $s->Libelle]])->all();
        $this->journaux = Journal::pluck('Id_Journal', 'Code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
        $this->tiers = Tiers::get(['id', 'code', 'nom', 'actif'])
            ->mapWithKeys(fn ($t) => [mb_strtoupper($t->code) => ['id' => $t->id, 'nom' => $t->nom, 'actif' => (bool) $t->actif]])->all();
    }

    private function analyserLigne(int $num, array $l): ?array
    {
        $val = fn ($k) => trim((string) ($l[$k] ?? ''));
        if (implode('', array_map($val, ['date', 'compte', 'libelle', 'debit', 'credit', 'reference', 'journal', 'tiers'])) === '') {
            return null;   // ligne vide
        }

        $erreurs = [];
        $dateSource = $val('date');
        $compte = preg_replace('/\s+/', '', $val('compte'));
        $journal = $val('journal');
        $date = $dateSource !== '' ? self::lireDate($dateSource) : null;
        $debit = self::lireMontant($val('debit'));
        $credit = self::lireMontant($val('credit'));

        if ($dateSource === '') {
            $erreurs[] = 'Date manquante.';
        } elseif (!$date) {
            $erreurs[] = "Date illisible (« {$dateSource} »).";
        } elseif ($motif = EcritureGuard::motifDateFermee($date)) {
            $erreurs[] = $motif;
        }
        if ($compte === '') {
            $erreurs[] = 'Sous-compte manquant.';
        } elseif (!isset($this->comptes[$compte])) {
            $erreurs[] = "Sous-compte {$compte} introuvable dans le plan comptable.";
        }
        if ($journal === '') {
            $erreurs[] = 'Code journal manquant.';
        } elseif (!isset($this->journaux[$journal])) {
            $erreurs[] = "Journal {$journal} introuvable.";
        }
        if ($debit === null || $credit === null) {
            $erreurs[] = 'Montant illisible.';
        } elseif ($debit < 0 || $credit < 0) {
            $erreurs[] = 'Les montants doivent être positifs.';
        } elseif (($debit > 0) === ($credit > 0)) {
            $erreurs[] = 'Renseigner soit le débit, soit le crédit (un seul des deux).';
        }
        if (mb_strlen($val('libelle')) > 255) {
            $erreurs[] = 'Libellé trop long (255 caractères au maximum).';
        }

        // Tiers (facultatif) : code d'un tiers existant et actif, sur un compte de classe 4.
        // Absent sur un compte qui l'exige : simple avertissement (la pièce est importée
        // en brouillon, le tiers devra être renseigné avant la validation).
        $avertissements = [];
        $codeTiers = mb_strtoupper($val('tiers'));
        $tiers = $codeTiers !== '' ? ($this->tiers[$codeTiers] ?? null) : null;
        if ($codeTiers !== '') {
            if (!$tiers) {
                $erreurs[] = "Tiers {$codeTiers} introuvable.";
            } elseif (!$tiers['actif']) {
                $erreurs[] = "Le tiers {$codeTiers} est désactivé.";
            }
            if ($compte !== '' && isset($this->comptes[$compte]) && !TiersService::accepteTiers($compte)) {
                $erreurs[] = "Un tiers ne peut être indiqué que sur un compte de classe 4 (compte {$compte}).";
            }
        } elseif ($compte !== '' && TiersService::exigeTiers($compte)) {
            $avertissements[] = "Tiers non renseigné sur le compte {$compte} : à compléter avant la validation.";
        }

        return [
            'ligne' => $num,
            'date' => $dateSource,
            'date_iso' => $date ?: null,
            'compte' => $compte,
            'compte_libelle' => $this->comptes[$compte]['libelle'] ?? null,
            'libelle' => $val('libelle'),
            'debit' => $debit ?? 0.0,
            'credit' => $credit ?? 0.0,
            'reference' => $val('reference'),
            'journal' => $journal,
            'tiers' => $codeTiers,
            'tiers_nom' => $tiers['nom'] ?? null,
            'id_tiers' => $tiers['id'] ?? null,
            'piece' => null,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
        ];
    }

    /** Regroupement en pièces (lignes sans erreur de date / journal uniquement). */
    private function regrouper(array $lignes): array
    {
        $pieces = [];
        $index = [];
        $blocOuvert = [];
        foreach ($lignes as $l) {
            if (!$l['date_iso'] || !isset($this->journaux[$l['journal']])) {
                continue;
            }
            if ($l['reference'] !== '') {
                $cle = "{$l['date_iso']}|{$l['reference']}|{$l['journal']}";
                if (!isset($index[$cle])) {
                    $index[$cle] = count($pieces);
                    $pieces[] = $this->nouvellePiece($l);
                }
                $this->ajouter($pieces[$index[$cle]], $l);
                continue;
            }
            $cle = "{$l['date_iso']}|{$l['journal']}";
            $pos = $blocOuvert[$cle] ?? null;
            if ($pos !== null && count($pieces[$pos]['lignes']) >= 2
                && abs($pieces[$pos]['debit'] - $pieces[$pos]['credit']) < EcritureGuard::tolerance()) {
                $pos = null;
            }
            if ($pos === null) {
                $pos = count($pieces);
                $blocOuvert[$cle] = $pos;
                $pieces[] = $this->nouvellePiece($l);
            }
            $this->ajouter($pieces[$pos], $l);
        }

        foreach ($pieces as &$p) {
            $p['debit'] = round($p['debit'], 2);
            $p['credit'] = round($p['credit'], 2);
            $p['equilibree'] = abs($p['debit'] - $p['credit']) < EcritureGuard::tolerance();
            $p['erreur'] = match (true) {
                count($p['lignes']) < 2 => 'Une pièce doit comporter au moins deux lignes.',
                !$p['equilibree'] => sprintf('Pièce déséquilibrée : débit %s ≠ crédit %s.',
                    number_format($p['debit'], 2, ',', ' '), number_format($p['credit'], 2, ',', ' ')),
                default => null,
            };
        }
        return $pieces;
    }

    private function nouvellePiece(array $l): array
    {
        return ['date' => $l['date_iso'], 'journal' => $l['journal'], 'reference' => $l['reference'], 'lignes' => [], 'debit' => 0.0, 'credit' => 0.0];
    }

    private function ajouter(array &$piece, array $l): void
    {
        $piece['lignes'][] = $l['ligne'];
        $piece['debit'] += $l['debit'];
        $piece['credit'] += $l['credit'];
    }

    private function messagesErreur(array $analyse): array
    {
        $m = [];
        foreach ($analyse['lignes'] as $l) {
            foreach ($l['erreurs'] as $e) {
                $m[] = "Ligne {$l['ligne']} : {$e}";
            }
        }
        foreach ($analyse['pieces'] as $i => $p) {
            if ($p['erreur']) {
                $m[] = 'Pièce ' . ($i + 1) . ' (lignes ' . implode(', ', $p['lignes']) . ") : {$p['erreur']}";
            }
        }
        return $m;
    }

    /** Montant au format français ou international ; vide = 0 ; null si illisible. */
    public static function lireMontant(string $valeur): ?float
    {
        $v = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($valeur));
        if ($v === '') {
            return 0.0;
        }
        $v = str_contains($v, ',') && str_contains($v, '.') ? str_replace(',', '', $v) : str_replace(',', '.', $v);
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    /** Date JJ/MM/AAAA, AAAA-MM-JJ ou numéro de série Excel ; false si illisible. */
    public static function lireDate(string $d): string|false
    {
        if (is_numeric($d)) {
            return date('Y-m-d', (int) (((float) $d - 25569) * 86400));
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $d, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : false;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : false;
        }
        return false;
    }
}
