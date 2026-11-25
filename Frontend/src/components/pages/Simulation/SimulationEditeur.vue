<template>
  <div class="se">
    <!-- Barre d'actions -->
    <div class="se-barre no-print">
      <UiButton variant="ghost" icon="bi-arrow-left" @click="quitter">Scénarios</UiButton>
      <div class="se-barre__droite">
        <span v-if="modifie" class="se-modifie"><i class="bi bi-circle-fill" aria-hidden="true"></i> Modifications non enregistrées</span>
        <UiButton v-if="form.id" icon="bi-printer" @click="imprimer">Imprimer</UiButton>
        <UiButton variant="primary" icon="bi-check2" :loading="enregistrement" :disabled="!peutEnregistrer" @click="enregistrer">
          {{ form.id ? 'Enregistrer' : 'Créer le scénario' }}
        </UiButton>
      </div>
    </div>
    <p v-if="erreur" class="se-erreur" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> {{ erreur }}</p>

    <div class="se-grille">
      <div class="se-principal">
        <!-- En-tête du scénario -->
        <section class="fs-card se-carte">
          <h2 class="se-titre-section">Scénario</h2>
          <div class="se-champs">
            <label class="se-champ se-champ--large">
              <span>Nom du scénario</span>
              <input v-model="form.nom" class="fs-input" maxlength="255" placeholder="Ex. Budget 2027 — hypothèse prudente" @input="modifie = true" />
            </label>
            <label class="se-champ">
              <span>Exercice de référence</span>
              <select v-model="form.id_exercice" class="fs-select" @change="changerReference">
                <option v-for="e in exercices" :key="e.Id_Exercice_comptable" :value="e.Id_Exercice_comptable">{{ e.Annee_fiscale }}</option>
              </select>
            </label>
            <label class="se-champ">
              <span>Année budgétée</span>
              <input v-model.number="form.annee_cible" type="number" min="2000" max="2200" class="fs-input" @input="modifie = true" />
            </label>
            <label class="se-champ se-champ--plein">
              <span>Description / hypothèses retenues</span>
              <textarea v-model="form.description" class="fs-input" rows="2" maxlength="2000" placeholder="Ex. hausse des écolages de 5 %, recrutement de deux enseignants…" @input="modifie = true"></textarea>
            </label>
          </div>
          <p v-if="reference" class="se-info">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Base de calcul : réalisé de l'exercice {{ reference.annee }} (écritures validées)
            <template v-if="reference.en_cours">
              arrêté au {{ formatDate(reference.arrete_au) }}, soit {{ reference.avancement }} % de l'exercice,
              <strong>projeté sur 12 mois</strong>.
            </template>
            <template v-else>, exercice complet.</template>
          </p>
        </section>

        <!-- Hypothèses globales -->
        <section class="fs-card se-carte">
          <h2 class="se-titre-section">Hypothèses d'évolution</h2>
          <p class="se-aide">Appliquées à tous les comptes, sauf ceux que vous ajustez un par un dans le tableau.</p>
          <div class="se-hypotheses">
            <div v-for="h in HYPOTHESES" :key="h.cle" class="se-hypothese">
              <span class="se-hypothese__label"><i :class="['bi', h.icone]" aria-hidden="true"></i> {{ h.libelle }}</span>
              <div class="se-pourcent">
                <button type="button" class="se-pas" :aria-label="`${h.libelle} : moins 1 %`" @click="pasHypothese(h.cle, -1)">−</button>
                <input
                  v-model.number="form.hypotheses[h.cle]" type="number" step="0.5" class="fs-input"
                  :aria-label="`${h.libelle} en %`" @input="appliquerHypotheses"
                />
                <span>%</span>
                <button type="button" class="se-pas" :aria-label="`${h.libelle} : plus 1 %`" @click="pasHypothese(h.cle, 1)">+</button>
              </div>
              <span class="se-hypothese__aide">{{ h.aide }}</span>
            </div>
          </div>
        </section>

        <!-- Comptes -->
        <section class="fs-card fs-table-card">
          <div class="fs-table-card__head se-table-head">
            <h2 class="fs-table-card__title">Budget par compte</h2>
            <div class="se-table-outils no-print">
              <div class="se-recherche">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input v-model="recherche" type="search" class="fs-input" placeholder="Compte ou libellé…" aria-label="Filtrer les comptes" />
              </div>
              <UiButton variant="ghost" size="sm" :icon="toutOuvert ? 'bi-arrows-collapse' : 'bi-arrows-expand'" @click="basculerTout">
                {{ toutOuvert ? 'Tout replier' : 'Tout déplier' }}
              </UiButton>
            </div>
          </div>

          <div v-if="chargement" class="fs-skeleton"><div v-for="n in 6" :key="n" class="fs-skeleton__row"></div></div>
          <p v-else-if="!lignes.length" class="fs-empty se-vide">
            Aucune écriture validée sur les comptes de charges et de produits de l'exercice {{ reference?.annee }}.
            Choisissez un autre exercice de référence.
          </p>
          <div v-else class="fs-table-wrap">
            <table class="fs-table se-table">
              <thead>
                <tr>
                  <th>Compte</th>
                  <th v-if="!lectureSeule" class="se-col-nature">Nature</th>
                  <th class="text-right">Référence {{ reference?.annee }}</th>
                  <th class="text-right se-col-var">Évolution</th>
                  <th class="text-right se-col-budget">Budget {{ form.annee_cible || '' }}</th>
                  <th class="text-right">Écart</th>
                </tr>
              </thead>
              <tbody v-for="section in sections" :key="section.type">
                <tr class="se-section">
                  <td :colspan="lectureSeule ? 5 : 6">{{ section.libelle }}</td>
                </tr>
                <template v-for="g in section.groupes" :key="g.cle">
                  <tr class="se-groupe" tabindex="0" @click="basculer(g.cle)" @keydown.enter="basculer(g.cle)">
                    <td>
                      <i class="bi se-chevron" :class="ouverts.has(g.cle) ? 'bi-chevron-down' : 'bi-chevron-right'" aria-hidden="true"></i>
                      <strong>{{ g.compte }}</strong> {{ g.libelle }}
                      <small class="se-nb">{{ g.lignes.length }}</small>
                    </td>
                    <td v-if="!lectureSeule"></td>
                    <td class="text-right num">{{ formatMontant(g.reference) }}</td>
                    <td class="text-right num se-muted">{{ formatVariation(g.reference, g.budget) }}</td>
                    <td class="text-right num"><strong>{{ formatMontant(g.budget) }}</strong></td>
                    <td class="text-right num" :class="classeEcart(section.type, g.budget - g.reference)">{{ formatSigne(g.budget - g.reference) }}</td>
                  </tr>
                  <template v-if="ouverts.has(g.cle)">
                    <tr v-for="l in g.lignes" :key="l.cle" class="se-ligne" :class="{ 'is-ajuste': l.ajuste }">
                      <td class="se-ligne__compte">
                        <span class="num">{{ l.code }}</span> {{ l.libelle }}
                        <button
                          v-if="l.ajuste && !lectureSeule" type="button" class="se-reset no-print"
                          title="Revenir à l'hypothèse globale" @click="reinitialiser(l)"
                        ><i class="bi bi-arrow-counterclockwise"></i> ajusté</button>
                      </td>
                      <td v-if="!lectureSeule" class="se-col-nature">
                        <select v-if="l.type === 'charge'" v-model="l.nature_charge" class="se-mini-select" :aria-label="`Nature de ${l.code}`" @change="changerNature(l)">
                          <option value="fixe">Fixe</option>
                          <option value="variable">Variable</option>
                        </select>
                      </td>
                      <td class="text-right num se-muted">{{ formatMontant(l.reference) }}</td>
                      <td class="text-right se-col-var">
                        <span v-if="lectureSeule" class="num">{{ formatPourcent((l.coefficient - 1) * 100) }}</span>
                        <div v-else class="se-cellule-pct">
                          <input
                            :value="arrondi((l.coefficient - 1) * 100, 2)" type="number" step="0.5" class="se-mini-input"
                            :aria-label="`Évolution de ${l.code} en %`" @change="saisirVariation(l, $event.target.value)"
                          /><span>%</span>
                        </div>
                      </td>
                      <td class="text-right se-col-budget">
                        <span v-if="lectureSeule" class="num">{{ formatMontant(l.montant_simule) }}</span>
                        <input
                          v-else :value="arrondi(l.montant_simule, 2)" type="number" step="1000" class="se-mini-input se-mini-input--montant"
                          :aria-label="`Budget de ${l.code}`" @change="saisirMontant(l, $event.target.value)"
                        />
                      </td>
                      <td class="text-right num" :class="classeEcart(l.type, l.montant_simule - l.reference)">{{ formatSigne(l.montant_simule - l.reference) }}</td>
                    </tr>
                  </template>
                </template>
                <tr class="se-total">
                  <td>Total {{ section.libelle.toLowerCase() }}</td>
                  <td v-if="!lectureSeule"></td>
                  <td class="text-right num">{{ formatMontant(section.reference) }}</td>
                  <td class="text-right num">{{ formatVariation(section.reference, section.budget) }}</td>
                  <td class="text-right num">{{ formatMontant(section.budget) }}</td>
                  <td class="text-right num" :class="classeEcart(section.type, section.budget - section.reference)">{{ formatSigne(section.budget - section.reference) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <!-- Synthèse (reste visible au défilement) -->
      <aside class="se-synthese">
        <section class="fs-card se-carte">
          <h2 class="se-titre-section">Synthèse</h2>
          <dl class="se-chiffres">
            <div>
              <dt>Produits</dt>
              <dd class="num">{{ formatMontant(totaux.produits) }}</dd>
              <dd class="se-delta num" :class="classeEcart('produit', totaux.produits - totaux.refProduits)">{{ formatVariation(totaux.refProduits, totaux.produits) }} vs {{ reference?.annee }}</dd>
            </div>
            <div>
              <dt>Charges</dt>
              <dd class="num">{{ formatMontant(totaux.charges) }}</dd>
              <dd class="se-delta num" :class="classeEcart('charge', totaux.charges - totaux.refCharges)">{{ formatVariation(totaux.refCharges, totaux.charges) }} vs {{ reference?.annee }}</dd>
              <dd class="se-detail">dont fixes {{ formatMontant(totaux.fixes) }} · variables {{ formatMontant(totaux.variables) }}</dd>
            </div>
          </dl>
          <div class="se-resultat" :class="totaux.resultat >= 0 ? 'is-positif' : 'is-negatif'">
            <span>Résultat prévisionnel</span>
            <strong class="num">{{ formatSigne(totaux.resultat) }} Ar</strong>
            <small>{{ totaux.resultat >= 0 ? 'Excédent' : 'Déficit' }} · marge {{ formatPourcent(totaux.produits ? totaux.resultat / totaux.produits * 100 : 0) }}</small>
          </div>
          <p class="se-compare">
            Résultat de référence {{ reference?.annee }} : <span class="num">{{ formatSigne(totaux.refResultat) }} Ar</span>
          </p>
          <!-- Barres produits / charges -->
          <div class="se-barres" aria-hidden="true">
            <div class="se-barre-ligne"><span>Produits</span><div><i class="se-barre-produits" :style="{ width: largeur(totaux.produits) }"></i></div></div>
            <div class="se-barre-ligne"><span>Charges</span><div><i class="se-barre-charges" :style="{ width: largeur(totaux.charges) }"></i></div></div>
          </div>
        </section>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { createSimulation, getHistoricalData, getSimulation, updateSimulation } from '@/services/SimulationService';

const props = defineProps({
  /** Scénario à ouvrir (id), ou null pour un nouveau */
  simulationId: { type: Number, default: null },
  /** Scénario à dupliquer (id) */
  copieDe: { type: Number, default: null },
  lectureSeule: { type: Boolean, default: false },
});
const emit = defineEmits(['fermer', 'enregistre']);

const HYPOTHESES = [
  { cle: 'produits', libelle: 'Produits', icone: 'bi-graph-up-arrow', aide: 'Écolages, ventes, subventions…' },
  { cle: 'charges_fixes', libelle: 'Charges fixes', icone: 'bi-building', aide: 'Salaires, loyers, assurances…' },
  { cle: 'charges_variables', libelle: 'Charges variables', icone: 'bi-cart', aide: "Achats liés à l'activité" },
];

const exercices = ref([]);
const reference = ref(null);
const lignes = ref([]);
const chargement = ref(true);
const enregistrement = ref(false);
const erreur = ref('');
const modifie = ref(false);
const recherche = ref('');
const ouverts = ref(new Set());
const form = reactive({
  id: null, nom: '', description: '', id_exercice: null, annee_cible: null,
  hypotheses: { produits: 0, charges_fixes: 0, charges_variables: 0 },
});

// ---- Formatage
const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const formatSigne = n => (n > 0.5 ? '+' : n < -0.5 ? '−' : '') + formatMontant(Math.abs(n));
const formatPourcent = p => `${p > 0.005 ? '+' : ''}${Number(p || 0).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`;
const formatVariation = (ref_, val) => (Math.abs(ref_) < 0.5 ? '—' : formatPourcent((val - ref_) / Math.abs(ref_) * 100));
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR') : '');
const arrondi = (n, d) => Math.round(Number(n || 0) * 10 ** d) / 10 ** d;
/** Écart favorable (vert) : plus de produits ou moins de charges */
const classeEcart = (type, ecart) => (Math.abs(ecart) < 0.5 ? '' : (type === 'produit') === (ecart > 0) ? 'se-favorable' : 'se-defavorable');

// ---- Hypothèses
function coefficientGlobal(l) {
  const h = form.hypotheses;
  const pct = l.type === 'produit' ? h.produits : (l.nature_charge === 'variable' ? h.charges_variables : h.charges_fixes);
  return 1 + (Number(pct) || 0) / 100;
}
function appliquerHypotheses() {
  for (const l of lignes.value) {
    if (!l.ajuste) {
      l.coefficient = coefficientGlobal(l);
      l.montant_simule = arrondi(l.reference * l.coefficient, 2);
    }
  }
  modifie.value = true;
}
function pasHypothese(cle, pas) {
  form.hypotheses[cle] = arrondi((Number(form.hypotheses[cle]) || 0) + pas, 2);
  appliquerHypotheses();
}
function saisirVariation(l, valeur) {
  l.coefficient = 1 + (Number(valeur) || 0) / 100;
  l.montant_simule = arrondi(l.reference * l.coefficient, 2);
  l.ajuste = Math.abs(l.coefficient - coefficientGlobal(l)) > 1e-9;
  modifie.value = true;
}
function saisirMontant(l, valeur) {
  l.montant_simule = arrondi(Number(valeur) || 0, 2);
  l.coefficient = Math.abs(l.reference) > 0.005 ? l.montant_simule / l.reference : 1;
  l.ajuste = true;
  modifie.value = true;
}
function reinitialiser(l) {
  l.ajuste = false;
  l.coefficient = coefficientGlobal(l);
  l.montant_simule = arrondi(l.reference * l.coefficient, 2);
  modifie.value = true;
}
function changerNature(l) {
  if (!l.ajuste) reinitialiser(l);
  modifie.value = true;
}

// ---- Regroupement par compte
const sections = computed(() => {
  const q = recherche.value.trim().toLowerCase();
  const visibles = lignes.value.filter(l => !q || `${l.code} ${l.libelle} ${l.compte} ${l.libelle_compte}`.toLowerCase().includes(q));
  return [
    { type: 'produit', libelle: 'Produits' },
    { type: 'charge', libelle: 'Charges' },
  ].map(s => {
    const groupes = new Map();
    for (const l of visibles.filter(x => x.type === s.type)) {
      const cle = `${s.type}-${l.compte || l.code}`;
      if (!groupes.has(cle)) groupes.set(cle, { cle, compte: l.compte || l.code, libelle: l.libelle_compte || '', lignes: [], reference: 0, budget: 0 });
      const g = groupes.get(cle);
      g.lignes.push(l);
      g.reference += l.reference;
      g.budget += l.montant_simule;
    }
    const liste = [...groupes.values()];
    return { ...s, groupes: liste, reference: liste.reduce((a, g) => a + g.reference, 0), budget: liste.reduce((a, g) => a + g.budget, 0) };
  }).filter(s => s.groupes.length);
});

const totaux = computed(() => {
  const somme = (f, champ) => lignes.value.filter(f).reduce((a, l) => a + l[champ], 0);
  const produits = somme(l => l.type === 'produit', 'montant_simule');
  const charges = somme(l => l.type === 'charge', 'montant_simule');
  const refProduits = somme(l => l.type === 'produit', 'reference');
  const refCharges = somme(l => l.type === 'charge', 'reference');
  return {
    produits, charges, resultat: produits - charges,
    refProduits, refCharges, refResultat: refProduits - refCharges,
    fixes: somme(l => l.type === 'charge' && l.nature_charge === 'fixe', 'montant_simule'),
    variables: somme(l => l.type === 'charge' && l.nature_charge === 'variable', 'montant_simule'),
  };
});
const largeur = v => `${Math.max(2, Math.min(100, Math.abs(v) / (Math.max(Math.abs(totaux.value.produits), Math.abs(totaux.value.charges)) || 1) * 100))}%`;

const toutOuvert = computed(() => sections.value.every(s => s.groupes.every(g => ouverts.value.has(g.cle))));
function basculer(cle) {
  const s = new Set(ouverts.value);
  s.has(cle) ? s.delete(cle) : s.add(cle);
  ouverts.value = s;
}
function basculerTout() {
  ouverts.value = toutOuvert.value ? new Set() : new Set(sections.value.flatMap(s => s.groupes.map(g => g.cle)));
}

// ---- Chargement
function versLigne(h, coefficient = null, montant = null) {
  const l = {
    cle: `${h.id_sous_compte ?? h.code}`,
    id_sous_compte: h.id_sous_compte, code: h.code, libelle: h.libelle,
    compte: h.compte, libelle_compte: h.libelle_compte, type: h.type,
    nature_charge: h.nature_charge || 'fixe',
    reference: Number(h.reference) || 0,
    ajuste: false, coefficient: 1, montant_simule: 0,
  };
  l.coefficient = coefficient ?? coefficientGlobal(l);
  l.montant_simule = montant ?? arrondi(l.reference * l.coefficient, 2);
  l.ajuste = coefficient !== null && Math.abs(l.coefficient - coefficientGlobal(l)) > 1e-6;
  return l;
}

async function chargerReference(idExercice) {
  chargement.value = true;
  try {
    const { data } = await getHistoricalData(idExercice);
    reference.value = data.exercice;
    form.id_exercice = data.exercice.id;
    if (!form.annee_cible) form.annee_cible = data.exercice.annee + 1;
    lignes.value = data.lignes.map(h => versLigne(h));
  } finally {
    chargement.value = false;
  }
}

async function changerReference() {
  if (modifie.value && lignes.value.some(l => l.ajuste)
      && !confirm('Changer d\'exercice de référence recalcule tous les montants : vos ajustements par compte seront perdus. Continuer ?')) {
    form.id_exercice = reference.value.id;
    return;
  }
  await chargerReference(form.id_exercice);
  modifie.value = true;
}

async function chargerScenario(id, copie) {
  const { data } = await getSimulation(id);
  Object.assign(form, {
    id: copie ? null : data.id,
    nom: copie ? `${data.nom} (copie)` : data.nom,
    description: data.description || '',
    id_exercice: data.id_exercice_comptable,
    annee_cible: data.annee_cible,
    hypotheses: { produits: 0, charges_fixes: 0, charges_variables: 0, ...(data.hypotheses || {}) },
  });
  // Référence (date d'arrêté, avancement) de l'exercice du scénario
  const ref_ = await getHistoricalData(data.id_exercice_comptable).catch(() => null);
  reference.value = ref_?.data.exercice || { annee: data.exercice_reference };
  lignes.value = data.lignes.map(l => versLigne(l, Number(l.coefficient), Number(l.montant_simule)));
  modifie.value = copie;
}

// ---- Enregistrement
const peutEnregistrer = computed(() => !props.lectureSeule && form.nom.trim() && lignes.value.length && !enregistrement.value);

async function enregistrer() {
  erreur.value = '';
  enregistrement.value = true;
  const corps = {
    nom_simulation: form.nom.trim(),
    description: form.description || null,
    id_exercice_comptable: form.id_exercice,
    annee_cible: form.annee_cible || null,
    hypotheses: form.hypotheses,
    lignes: lignes.value.map(l => ({
      libelle: l.libelle, type: l.type, nature_charge: l.nature_charge, id_sous_compte: l.id_sous_compte,
      reference: arrondi(l.reference, 2), coefficient: arrondi(l.coefficient, 4), montant_simule: arrondi(l.montant_simule, 2),
    })),
  };
  try {
    const { data } = form.id ? await updateSimulation(form.id, corps) : await createSimulation(corps);
    form.id = data.id;
    modifie.value = false;
    emit('enregistre', data);
  } catch (e) {
    erreur.value = e.response?.data?.message || "L'enregistrement a échoué.";
  } finally {
    enregistrement.value = false;
  }
}

function quitter() {
  if (modifie.value && !confirm('Quitter sans enregistrer les modifications ?')) return;
  emit('fermer');
}
function imprimer() {
  ouverts.value = new Set(sections.value.flatMap(s => s.groupes.map(g => g.cle)));
  setTimeout(() => window.print(), 50);
}

// Avertit avant de fermer l'onglet avec des modifications
const avantFermeture = e => { if (modifie.value) { e.preventDefault(); e.returnValue = ''; } };

onMounted(async () => {
  window.addEventListener('beforeunload', avantFermeture);
  try {
    exercices.value = (await axios.get(`${API_BASE}/exercices`)).data
      .slice().sort((a, b) => b.Annee_fiscale - a.Annee_fiscale);
    if (props.simulationId || props.copieDe) {
      await chargerScenario(props.simulationId || props.copieDe, !!props.copieDe);
    } else {
      // Par défaut : le dernier exercice clôturé (complet), sinon l'exercice en cours
      const cloture = exercices.value.find(e => e.Statut !== 'OUVERT' && e.cloture_le) || exercices.value.find(e => e.Statut !== 'OUVERT');
      await chargerReference(cloture?.Id_Exercice_comptable ?? exercices.value[0]?.Id_Exercice_comptable);
    }
  } catch (e) {
    erreur.value = e.response?.data?.message || 'Impossible de charger les données.';
  } finally {
    chargement.value = false;
  }
});
onBeforeUnmount(() => window.removeEventListener('beforeunload', avantFermeture));
</script>

<style scoped>
.se-barre { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; }
.se-barre__droite { display: flex; align-items: center; gap: 10px; }
.se-modifie { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--fs-text-2); }
.se-modifie .bi { font-size: 7px; color: var(--fs-warning); }
.se-erreur { display: flex; gap: 8px; align-items: center; margin: 0 0 16px; padding: 10px 14px; border-radius: 10px; background: var(--fs-negative-soft); color: var(--fs-negative); font-size: 13.5px; }

.se-grille { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; }
.se-principal { display: flex; flex-direction: column; gap: 20px; min-width: 0; }
.se-synthese { position: sticky; top: 16px; }
.se-carte { padding: 18px 20px; }
.se-titre-section { margin: 0 0 12px; font-size: 15px; font-weight: 600; color: var(--fs-text); }
.se-aide { margin: -6px 0 14px; font-size: 13px; color: var(--fs-muted); }

.se-champs { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px 14px; }
.se-champ { display: flex; flex-direction: column; gap: 5px; font-size: 13px; color: var(--fs-text-2); }
.se-champ--plein { grid-column: 1 / -1; }
.se-champ textarea { resize: vertical; font-family: inherit; }
.se-info { display: flex; gap: 8px; align-items: flex-start; margin: 14px 0 0; padding: 10px 12px; border-radius: 9px; background: var(--fs-primary-soft); font-size: 13px; color: var(--fs-text-2); }
.se-info .bi { color: var(--fs-primary); margin-top: 1px; }

.se-hypotheses { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.se-hypothese { display: flex; flex-direction: column; gap: 6px; padding: 12px; border: 1px solid var(--fs-border); border-radius: 12px; }
.se-hypothese__label { display: flex; gap: 6px; align-items: center; font-size: 13.5px; font-weight: 600; color: var(--fs-text); }
.se-hypothese__label .bi { color: var(--fs-primary); }
.se-hypothese__aide { font-size: 12px; color: var(--fs-muted); }
.se-pourcent { display: flex; align-items: center; gap: 6px; }
.se-pourcent input { width: 80px; text-align: right; }
.se-pas { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--fs-border); background: var(--fs-surface); color: var(--fs-text); cursor: pointer; font-size: 16px; line-height: 1; }
.se-pas:hover { background: var(--fs-surface-3); }

.se-table-head { flex-wrap: wrap; gap: 10px; }
.se-table-outils { display: flex; gap: 8px; align-items: center; }
.se-recherche { position: relative; }
.se-recherche .bi { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); pointer-events: none; }
.se-recherche .fs-input { padding-left: 32px; width: 220px; }
.se-vide { padding: 28px 20px; }
.se-table td, .se-table th { white-space: nowrap; }
.se-col-nature { width: 110px; }
.se-col-var { width: 120px; }
.se-col-budget { width: 160px; }
.se-section td { background: var(--fs-surface-3); font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--fs-text-2); }
.se-groupe { cursor: pointer; }
.se-groupe td { background: var(--fs-surface-2, var(--fs-surface)); }
.se-groupe:hover td, .se-groupe:focus-visible td { background: var(--fs-row-hover); outline: none; }
.se-chevron { font-size: 11px; color: var(--fs-muted); margin-right: 6px; }
.se-nb { margin-left: 6px; padding: 0 6px; border-radius: 999px; background: var(--fs-surface-3); color: var(--fs-muted); font-size: 11px; }
.se-ligne__compte { padding-left: 34px !important; white-space: normal !important; color: var(--fs-text-2); }
.se-ligne.is-ajuste td { background: var(--fs-warning-soft); }
.se-reset { margin-left: 6px; padding: 1px 7px; border-radius: 999px; border: 1px solid var(--fs-warning); background: transparent; color: var(--fs-text-2); font-size: 11px; cursor: pointer; }
.se-mini-select { padding: 3px 6px; border: 1px solid var(--fs-border); border-radius: 7px; background: var(--fs-surface); color: var(--fs-text); font-size: 12.5px; }
.se-cellule-pct { display: inline-flex; align-items: center; gap: 4px; }
.se-mini-input { width: 72px; padding: 4px 6px; text-align: right; border: 1px solid var(--fs-border); border-radius: 7px; background: var(--fs-surface); color: var(--fs-text); font: 13px var(--fs-font); font-variant-numeric: tabular-nums; }
.se-mini-input--montant { width: 130px; }
.se-mini-input:focus, .se-mini-select:focus { outline: 2px solid var(--fs-primary); outline-offset: 0; border-color: transparent; }
.se-total td { font-weight: 700; border-top: 2px solid var(--fs-border-strong, var(--fs-border)); }
.se-muted { color: var(--fs-muted); }
.se-favorable { color: var(--fs-success); }
.se-defavorable { color: var(--fs-negative); }

.se-chiffres { margin: 0; display: flex; flex-direction: column; gap: 14px; }
.se-chiffres dt { font-size: 12.5px; color: var(--fs-muted); }
.se-chiffres dd { margin: 0; }
.se-chiffres dd.num:first-of-type { font-size: 20px; font-weight: 700; color: var(--fs-text); }
.se-delta { font-size: 12.5px; }
.se-detail { font-size: 12px; color: var(--fs-muted); }
.se-resultat { display: flex; flex-direction: column; gap: 2px; margin-top: 16px; padding: 14px; border-radius: 12px; }
.se-resultat span { font-size: 12.5px; color: var(--fs-text-2); }
.se-resultat strong { font-size: 24px; }
.se-resultat small { font-size: 12px; color: var(--fs-text-2); }
.se-resultat.is-positif { background: var(--fs-success-soft); }
.se-resultat.is-positif strong { color: var(--fs-success); }
.se-resultat.is-negatif { background: var(--fs-negative-soft); }
.se-resultat.is-negatif strong { color: var(--fs-negative); }
.se-compare { margin: 10px 0 0; font-size: 12.5px; color: var(--fs-muted); }
.se-barres { display: flex; flex-direction: column; gap: 6px; margin-top: 14px; }
.se-barre-ligne { display: grid; grid-template-columns: 62px 1fr; align-items: center; gap: 8px; font-size: 12px; color: var(--fs-muted); }
.se-barre-ligne div { height: 8px; border-radius: 4px; background: var(--fs-surface-3); overflow: hidden; }
.se-barre-ligne i { display: block; height: 100%; border-radius: 4px; transition: width .2s; }
.se-barre-produits { background: var(--fs-success); }
.se-barre-charges { background: var(--fs-negative); }

.num { font-variant-numeric: tabular-nums; }
.text-right { text-align: right; }
@media (max-width: 1200px) {
  .se-grille { grid-template-columns: 1fr; }
  .se-synthese { position: static; order: -1; }
}
@media (max-width: 800px) {
  .se-champs, .se-hypotheses { grid-template-columns: 1fr; }
}
@media print {
  .no-print { display: none !important; }
  .se-grille { grid-template-columns: 1fr; }
  .se-synthese { position: static; }
  .se-mini-input, .se-mini-select { border: 0; background: transparent; }
}
</style>
