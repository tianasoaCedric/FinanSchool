<?php

namespace App\Http\Controllers\import;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\Journal;
use App\Models\PlanCompte\SousCompte;
use Illuminate\Support\Facades\Auth;
use App\Models\import\ImportHistory;
use App\Models\exercice\ExerciceComptable;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\EcritureGuard;
use Exception;
use App\Console\Commands\NotificationImport;

class ImportEcritureController extends Controller
{
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_ecritures' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Fichier invalide',
                'messages' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $stats = $this->processImport($request->file('file_ecritures'));

            // Si des erreurs sont détectées, annuler tout
            if (!empty($stats['errors'])) {
                DB::rollBack();
                
                ImportHistory::create([
                    'nom_fichier'       => $request->file('file_ecritures')->getClientOriginalName(),
                    'imported_by'       => Auth::id(),
                    'nombre_mouvements' => 0,
                    'nombre_lignes'     => 0,
                    'statut'            => 'échec',
                    'erreur'            => implode("\n", $stats['errors']),
                ]);

                 NotificationImport::notifyImportError(
                    Auth::id(),
                    $request->file('file_ecritures')->getClientOriginalName(),
                    implode(', ', $stats['errors'])
                );

                return response()->json([
                    'error' => 'Erreurs détectées lors de l\'importation',
                    'errors' => $stats['errors'],
                    'message' => 'Aucune donnée n\'a été importée'
                ], 422);
            }

            $nomFichier = $request->file('file_ecritures')->getClientOriginalName();
            Audit::enregistrer(Audit::IMPORT_ECRITURES, 'import', null, "Import {$nomFichier}", null, null, [
                'fichier' => $nomFichier,
                'pieces' => $stats['mouvements'],
                'lignes' => $stats['lignes'],
                'statut' => 'brouillon',
            ]);

            DB::commit();

            ImportHistory::create([
                'nom_fichier'       => $request->file('file_ecritures')->getClientOriginalName(),
                'imported_by'       => Auth::id(),
                'nombre_mouvements' => $stats['mouvements'],
                'nombre_lignes'     => $stats['lignes'],
                'statut'            => 'réussi',
                'erreur'            => null,
            ]);

            NotificationImport::notifyImportSuccess(
                Auth::id(),
                $request->file('file_ecritures')->getClientOriginalName(),
                $stats['mouvements'],
                $stats['lignes']
            );

            return response()->json([
                'message' => "Importation réussie : {$stats['mouvements']} mouvements et {$stats['lignes']} lignes importés.",
                'data' => $stats
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            ImportHistory::create([
                'nom_fichier'       => $request->file('file_ecritures')->getClientOriginalName(),
                'imported_by'       => Auth::id(),
                'nombre_mouvements' => 0,
                'nombre_lignes'     => 0,
                'statut'            => 'échec',
                'erreur'            => $e->getMessage(),
            ]);

            NotificationImport::notifyImportError(
                Auth::id(),
                $request->file('file_ecritures')->getClientOriginalName(),
                $e->getMessage()
            );
            return response()->json([
                'error' => 'Erreur lors de l\'importation',
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    public function validateImport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_ecritures' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Fichier invalide',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $path = $request->file('file_ecritures')->getRealPath();
            $handle = fopen($path, 'r');
            
            $header = fgetcsv($handle, 0, ';');
            
            $preview = [];
            $count = 0;
            while (($row = fgetcsv($handle, 0, ';')) !== false && $count < 5) {
                $preview[] = [
                    'date' => $row[0] ?? '',
                    'sous_compte' => $row[1] ?? '',
                    'libelle' => $row[2] ?? '',
                    'debit' => $row[3] ?? '',
                    'credit' => $row[4] ?? '',
                    'reference' => $row[5] ?? '',
                    'code_journal' => $row[6] ?? '',
                ];
                $count++;
            }
            
            $totalLignes = $count;
            while (fgetcsv($handle, 0, ';') !== false) {
                $totalLignes++;
            }
            
            fclose($handle);
            
            return response()->json([
                'total_lignes' => $totalLignes,
                'preview' => $preview,
                'format_valide' => true,
                'header' => $header
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Erreur lors de la validation',
                'message' => $e->getMessage(),
                'format_valide' => false
            ], 500);
        }
    }

    public function historique()
    {
        try {
            $historique = ImportHistory::with('user')
                ->orderBy('created_at', 'desc')
                ->paginate(30);

            return response()->json($historique);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Erreur lors du chargement de l\'historique',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function processImport($file)
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ';');

        $data = [];
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $data[] = $row;
        }
        fclose($handle);

        // ÉTAPE 1 : contrôle de chaque ligne
        $errors = $this->validateData($data);
        if (!empty($errors)) {
            return ['mouvements' => 0, 'lignes' => 0, 'errors' => $errors];
        }

        // ÉTAPE 2 : regroupement en pièces, puis contrôle de l'équilibre de chacune
        $pieces = $this->regrouperEnPieces($data);
        foreach ($pieces as $piece) {
            $totalDebit = round(array_sum(array_column($piece['lignes'], 'debit')), 2);
            $totalCredit = round(array_sum(array_column($piece['lignes'], 'credit')), 2);
            $lignesFichier = implode(', ', array_column($piece['lignes'], 'numero'));
            $libelle = "Pièce du " . date('d/m/Y', strtotime($piece['date']))
                . ($piece['reference'] !== '' ? " réf. {$piece['reference']}" : '')
                . " (lignes {$lignesFichier})";

            if (count($piece['lignes']) < 2) {
                $errors[] = "{$libelle} : une pièce doit comporter au moins deux lignes";
            } elseif (abs($totalDebit - $totalCredit) >= EcritureGuard::TOLERANCE) {
                $errors[] = sprintf('%s : débit %s ≠ crédit %s', $libelle,
                    number_format($totalDebit, 2, ',', ' '), number_format($totalCredit, 2, ',', ' '));
            }
        }
        if (!empty($errors)) {
            return ['mouvements' => 0, 'lignes' => 0, 'errors' => $errors];
        }

        // ÉTAPE 3 : insertion (la transaction est ouverte par import())
        $journaux = Journal::all()->keyBy('Code');
        $sousComptes = SousCompte::pluck('Id_Sous_compte', 'Code_sous_compte');
        $mouvementCount = 0;
        $ligneCount = 0;

        foreach ($pieces as $piece) {
            $journal = $journaux[$piece['journal']];
            $mouvement = MouvementEcriture::create([
                'Date_mouvement' => $piece['date'],
                'Id_Journal'     => $journal->Id_Journal,
                // Référence de la pièce justificative (exigée à la validation)
                'Reference'      => $piece['reference'] !== '' ? $piece['reference'] : null,
            ]);
            $mouvementCount++;

            foreach ($piece['lignes'] as $ligne) {
                LigneEcriture::create([
                    'Libelle'                => $ligne['libelle'],
                    'Debit'                  => $ligne['debit'],
                    'Credit'                 => $ligne['credit'],
                    'Reference'              => $piece['reference'] !== '' ? $piece['reference'] : null,
                    'Quantite'               => null,
                    'Id_Mode_paiement'       => null,
                    'Id_Mouvement_ecriture'  => $mouvement->Id_Mouvement_ecriture,
                    'Id_Journal'             => $journal->Id_Journal,
                    'Id_Sous_compte'         => $sousComptes[$ligne['sous_compte']],
                    'statut'                 => EcritureGuard::STATUT_BROUILLON,
                ]);
                $ligneCount++;
            }
        }

        return [
            'mouvements' => $mouvementCount,
            'lignes' => $ligneCount,
            'errors' => []
        ];
    }

    /**
     * Regroupe les lignes du fichier en pièces comptables.
     *  - avec une référence : même date + même référence + même journal ;
     *  - sans référence : lignes consécutives de même date et même journal, une
     *    nouvelle pièce commençant dès que la précédente est équilibrée (évite de
     *    fusionner toute une journée en une seule pièce).
     */
    private function regrouperEnPieces(array $data): array
    {
        $pieces = [];
        $index = [];       // clé => position dans $pieces (lignes avec référence)
        $blocOuvert = [];  // date|journal => position de la pièce sans référence en cours

        foreach ($data as $i => $row) {
            if ($this->ligneVide($row)) {
                continue;
            }
            $date = $this->parseDate(trim($row[0]));
            $journal = trim($row[6]);
            $reference = trim($row[5] ?? '');
            $ligne = [
                'numero' => $i + 2, // +1 pour l'en-tête, +1 car les lignes commencent à 1
                'sous_compte' => trim($row[1]),
                'libelle' => trim($row[2] ?? ''),
                'debit' => $this->parseMontant($row[3] ?? ''),
                'credit' => $this->parseMontant($row[4] ?? ''),
            ];

            if ($reference !== '') {
                $cle = "{$date}|{$reference}|{$journal}";
                if (!isset($index[$cle])) {
                    $index[$cle] = count($pieces);
                    $pieces[] = ['date' => $date, 'journal' => $journal, 'reference' => $reference, 'lignes' => []];
                }
                $pieces[$index[$cle]]['lignes'][] = $ligne;
                continue;
            }

            $cle = "{$date}|{$journal}";
            $pos = $blocOuvert[$cle] ?? null;
            if ($pos !== null) {
                $lignes = $pieces[$pos]['lignes'];
                $equilibree = count($lignes) >= 2
                    && abs(array_sum(array_column($lignes, 'debit')) - array_sum(array_column($lignes, 'credit'))) < EcritureGuard::TOLERANCE;
                if ($equilibree) {
                    $pos = null;
                }
            }
            if ($pos === null) {
                $pos = count($pieces);
                $blocOuvert[$cle] = $pos;
                $pieces[] = ['date' => $date, 'journal' => $journal, 'reference' => '', 'lignes' => []];
            }
            $pieces[$pos]['lignes'][] = $ligne;
        }

        return $pieces;
    }

    /**
     * Valide chaque ligne AVANT insertion.
     */
    private function validateData($data)
    {
        $errors = [];
        $codesSousComptes = SousCompte::pluck('Id_Sous_compte', 'Code_sous_compte');
        $codesJournaux = Journal::pluck('Id_Journal', 'Code');

        foreach ($data as $i => $row) {
            $lineNumber = $i + 2;
            if ($this->ligneVide($row)) {
                continue;
            }

            // Colonnes obligatoires
            if (empty($row[0])) {
                $errors[] = "Ligne {$lineNumber}: Date manquante";
            }
            if (empty($row[1])) {
                $errors[] = "Ligne {$lineNumber}: Sous-compte manquant";
            }
            if (empty($row[6])) {
                $errors[] = "Ligne {$lineNumber}: Code journal manquant";
            }

            // Date et exercice
            $date = !empty($row[0]) ? $this->parseDate(trim($row[0])) : false;
            if (!empty($row[0]) && !$date) {
                $errors[] = "Ligne {$lineNumber}: Format de date invalide ({$row[0]})";
            } elseif ($date) {
                // Exercice ouvert et mois non clôturé
                if ($motif = EcritureGuard::motifDateFermee($date)) {
                    $errors[] = "Ligne {$lineNumber}: {$motif}";
                }
            }

            // Sous-compte et journal
            if (!empty($row[1]) && !isset($codesSousComptes[trim($row[1])])) {
                $errors[] = "Ligne {$lineNumber}: Sous-compte {$row[1]} introuvable";
            }
            if (!empty($row[6]) && !isset($codesJournaux[trim($row[6])])) {
                $errors[] = "Ligne {$lineNumber}: Journal {$row[6]} introuvable";
            }

            // Montants : positifs, un seul côté renseigné
            $debit = $this->parseMontant($row[3] ?? '');
            $credit = $this->parseMontant($row[4] ?? '');
            if ($debit === null || $credit === null) {
                $errors[] = "Ligne {$lineNumber}: montant illisible (débit « " . ($row[3] ?? '') . " », crédit « " . ($row[4] ?? '') . " »)";
            } elseif ($debit < 0 || $credit < 0) {
                $errors[] = "Ligne {$lineNumber}: les montants doivent être positifs";
            } elseif (($debit > 0) === ($credit > 0)) {
                $errors[] = "Ligne {$lineNumber}: renseigner soit le débit, soit le crédit (un seul des deux)";
            }
        }

        return $errors;
    }

    private function ligneVide($row): bool
    {
        return !is_array($row) || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0;
    }

    /**
     * Lit un montant au format français ou international : "1 234,56", "1234.56",
     * "1,234.56". Vide => 0. Retourne null si illisible.
     */
    private function parseMontant($valeur): ?float
    {
        $v = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim((string) $valeur));
        if ($v === '') {
            return 0.0;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);        // 1,234.56
        } else {
            $v = str_replace(',', '.', $v);       // 1234,56
        }
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private function parseDate($dateString)
    {
        if (is_numeric($dateString)) {
            $unixTimestamp = ($dateString - 25569) * 86400;
            return date('Y-m-d', $unixTimestamp);
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateString, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }

        return false;
    }
}
