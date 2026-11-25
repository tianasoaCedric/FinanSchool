<template>
  <AppLayout
    :user="user"
    icon="bi-magic"
    :title="titre"
    :subtitle="sousTitre"
    section="Simulation"
    wide
  >
    <template v-if="mode === 'liste'" #actions>
      <UiButton v-if="aComparer.size >= 2" icon="bi-layout-three-columns" @click="ouvrirComparaison">Comparer ({{ aComparer.size }})</UiButton>
      <UiButton variant="primary" icon="bi-plus-lg" @click="naviguer({ nouveau: 1 })">Nouveau scénario</UiButton>
    </template>
    <template v-else-if="mode === 'comparaison'" #actions>
      <UiButton variant="ghost" icon="bi-arrow-left" @click="naviguer({})">Scénarios</UiButton>
      <UiButton icon="bi-printer" @click="imprimer">Imprimer</UiButton>
    </template>

    <!-- Éditeur (création, modification, duplication) -->
    <SimulationEditeur
      v-if="mode === 'editeur'"
      :key="cleEditeur"
      :simulation-id="Number(route.query.id) || null"
      :copie-de="Number(route.query.copie) || null"
      @fermer="naviguer({})"
      @enregistre="apresEnregistrement"
    />

    <!-- Comparaison -->
    <template v-else-if="mode === 'comparaison'">
      <section class="fs-card fs-table-card">
        <div class="fs-table-wrap">
          <table class="fs-table sl-comparaison">
            <thead>
              <tr>
                <th></th>
                <th v-for="s in compares" :key="s.id" class="text-right">
                  <span class="sl-comp-nom">{{ s.nom }}</span>
                  <small>{{ s.annee_cible ? `Budget ${s.annee_cible}` : '' }} · réf. {{ s.exercice_reference }}</small>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr><td>Hypothèse produits</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatPourcent(s.hypotheses.produits) }}</td></tr>
              <tr><td>Hypothèse charges fixes</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatPourcent(s.hypotheses.charges_fixes) }}</td></tr>
              <tr><td>Hypothèse charges variables</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatPourcent(s.hypotheses.charges_variables) }}</td></tr>
              <tr class="sl-sep"><td>Produits</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatMontant(s.totaux.produits) }}</td></tr>
              <tr><td>Charges fixes</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatMontant(s.totaux.charges_fixes) }}</td></tr>
              <tr><td>Charges variables</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatMontant(s.totaux.charges_variables) }}</td></tr>
              <tr><td>Total des charges</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatMontant(s.totaux.charges) }}</td></tr>
              <tr class="sl-sep sl-resultat">
                <td>Résultat prévisionnel</td>
                <td v-for="s in compares" :key="s.id" class="text-right num" :class="s.totaux.resultat >= 0 ? 'sl-positif' : 'sl-negatif'">{{ formatSigne(s.totaux.resultat) }}</td>
              </tr>
              <tr><td>Marge</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ marge(s) }}</td></tr>
              <tr><td>Évolution du résultat vs référence</td><td v-for="s in compares" :key="s.id" class="text-right num">{{ formatSigne(s.totaux.resultat - s.totaux.reference_resultat) }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
      <p class="sl-note">Montants en Ariary. Les scénarios peuvent avoir des exercices de référence différents.</p>
    </template>

    <!-- Liste des scénarios -->
    <template v-else>
      <section class="fs-card sl-filtres">
        <div class="sl-recherche">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input v-model="recherche" type="search" class="fs-input" placeholder="Rechercher un scénario…" aria-label="Rechercher" />
        </div>
        <span v-if="aComparer.size" class="sl-aide">{{ aComparer.size }} scénario(s) coché(s) pour comparaison</span>
        <span v-else class="sl-aide">Cochez deux scénarios ou plus pour les comparer</span>
      </section>

      <div v-if="chargement" class="fs-card fs-skeleton"><div v-for="n in 4" :key="n" class="fs-skeleton__row"></div></div>

      <section v-else-if="!simulations.length" class="fs-card sl-vide">
        <i class="bi bi-magic" aria-hidden="true"></i>
        <h2>Aucun scénario budgétaire</h2>
        <p>Partez du réalisé d'un exercice, appliquez vos hypothèses d'évolution et obtenez un budget prévisionnel.</p>
        <UiButton variant="primary" icon="bi-plus-lg" @click="naviguer({ nouveau: 1 })">Créer un premier scénario</UiButton>
      </section>

      <section v-else class="fs-card fs-table-card">
        <div class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th class="sl-col-check"><span class="sr-only">Comparer</span></th>
                <th>Scénario</th>
                <th>Base</th>
                <th class="text-right">Produits</th>
                <th class="text-right">Charges</th>
                <th class="text-right">Résultat</th>
                <th>Modifié</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in filtrees" :key="s.id" class="sl-ligne" tabindex="0" @click="naviguer({ id: s.id })" @keydown.enter="naviguer({ id: s.id })">
                <td class="sl-col-check" @click.stop>
                  <input type="checkbox" :checked="aComparer.has(s.id)" :aria-label="`Comparer ${s.nom}`" @change="cocher(s.id, $event.target.checked)" />
                </td>
                <td class="sl-nom">
                  <strong>{{ s.nom }}</strong>
                  <small v-if="s.description">{{ s.description }}</small>
                </td>
                <td class="sl-base">
                  <span v-if="s.annee_cible">Budget {{ s.annee_cible }}</span>
                  <small>réf. {{ s.exercice_reference }} · {{ resumeHypotheses(s.hypotheses) }}</small>
                </td>
                <td class="text-right num">{{ formatMontant(s.totaux.produits) }}</td>
                <td class="text-right num">{{ formatMontant(s.totaux.charges) }}</td>
                <td class="text-right num">
                  <UiBadge :tone="s.totaux.resultat >= 0 ? 'success' : 'danger'">{{ formatSigne(s.totaux.resultat) }}</UiBadge>
                </td>
                <td class="sl-date">{{ formatDate(s.modifie_le) }}<small v-if="s.auteur">{{ s.auteur }}</small></td>
                <td class="text-right sl-actions" @click.stop>
                  <UiButton variant="ghost" size="sm" icon="bi-pencil" icon-only :aria-label="`Ouvrir ${s.nom}`" title="Ouvrir" @click="naviguer({ id: s.id })" />
                  <UiButton variant="ghost" size="sm" icon="bi-files" icon-only :aria-label="`Dupliquer ${s.nom}`" title="Dupliquer" @click="naviguer({ copie: s.id })" />
                  <UiButton variant="ghost" size="sm" icon="bi-trash3" icon-only :aria-label="`Supprimer ${s.nom}`" title="Supprimer" @click="supprimer(s)" />
                </td>
              </tr>
              <tr v-if="!filtrees.length"><td colspan="8" class="fs-empty">Aucun scénario ne correspond à la recherche</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getUser } from '@/services/Auth';
import { deleteSimulation, getSimulations } from '@/services/SimulationService';
import AppLayout from '@/components/template/AppLayout.vue';
import SimulationEditeur from './SimulationEditeur.vue';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const simulations = ref([]);
const chargement = ref(true);
const recherche = ref('');
const aComparer = ref(new Set());
const cleEditeur = ref(0);

// L'état de la page est dans l'URL : ?nouveau=1, ?id=…, ?copie=…, ?comparer=1,2
const mode = computed(() => {
  if (route.query.nouveau || route.query.id || route.query.copie) return 'editeur';
  if (route.query.comparer) return 'comparaison';
  return 'liste';
});
const titre = computed(() => ({
  editeur: route.query.id ? 'Scénario budgétaire' : 'Nouveau scénario',
  comparaison: 'Comparaison de scénarios',
  liste: 'Simulations budgétaires',
}[mode.value]));
const sousTitre = computed(() => ({
  editeur: 'Partez du réalisé d\'un exercice et appliquez vos hypothèses pour construire le budget',
  comparaison: 'Hypothèses et résultats côte à côte',
  liste: 'Construisez et comparez des budgets prévisionnels à partir de votre réalisé',
}[mode.value]));

const filtrees = computed(() => {
  const q = recherche.value.trim().toLowerCase();
  return q ? simulations.value.filter(s => `${s.nom} ${s.description || ''}`.toLowerCase().includes(q)) : simulations.value;
});
const compares = computed(() => {
  const ids = String(route.query.comparer || '').split(',').map(Number);
  return ids.map(id => simulations.value.find(s => s.id === id)).filter(Boolean);
});

const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { maximumFractionDigits: 0 });
const formatSigne = n => (n > 0.5 ? '+' : n < -0.5 ? '−' : '') + formatMontant(Math.abs(n));
const formatPourcent = p => `${Number(p) > 0 ? '+' : ''}${Number(p || 0).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`;
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR') : '');
const marge = s => (s.totaux.produits ? formatPourcent(s.totaux.resultat / s.totaux.produits * 100).replace(/^\+/, '') : '—');
function resumeHypotheses(h) {
  if (!h) return 'aucune hypothèse';
  return `produits ${formatPourcent(h.produits)}, charges ${formatPourcent(h.charges_fixes)} / ${formatPourcent(h.charges_variables)}`;
}

function naviguer(query) { router.push({ path: '/simulations', query }); }
function cocher(id, oui) {
  const s = new Set(aComparer.value);
  oui ? s.add(id) : s.delete(id);
  aComparer.value = s;
}
function ouvrirComparaison() { naviguer({ comparer: [...aComparer.value].join(',') }); }
function imprimer() { window.print(); }

async function charger() {
  chargement.value = true;
  try {
    simulations.value = (await getSimulations()).data;
  } catch {
    simulations.value = [];
  } finally {
    chargement.value = false;
  }
}

async function supprimer(s) {
  if (!confirm(`Supprimer le scénario « ${s.nom} » ?`)) return;
  try {
    await deleteSimulation(s.id);
    cocher(s.id, false);
    await charger();
  } catch {
    alert('Suppression impossible.');
  }
}

function apresEnregistrement(data) {
  charger();
  // Après une création ou une duplication, l'URL pointe vers le scénario enregistré
  if (String(route.query.id) !== String(data.id)) {
    router.replace({ path: '/simulations', query: { id: data.id } });
  }
}

// Changer de scénario dans l'URL recharge l'éditeur (pas en cas de simple remplacement après création)
watch(() => [route.query.nouveau, route.query.copie], () => { cleEditeur.value++; });
watch(mode, m => { if (m !== 'editeur') charger(); });

onMounted(() => {
  if (!localStorage.getItem('token')) return router.push('/');
  getUser(localStorage.getItem('token')).then(r => { user.value = r.data; }).catch(() => {});
  charger();
});
</script>

<style scoped>
.sl-filtres { display: flex; align-items: center; gap: 16px; padding: 14px 20px; margin-bottom: 20px; }
.sl-recherche { position: relative; flex: 0 1 360px; }
.sl-recherche .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); pointer-events: none; }
.sl-recherche .fs-input { padding-left: 36px; }
.sl-aide { font-size: 13px; color: var(--fs-muted); }

.sl-vide { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 48px 24px; text-align: center; }
.sl-vide > .bi { font-size: 36px; color: var(--fs-primary); }
.sl-vide h2 { margin: 4px 0 0; font-size: 18px; }
.sl-vide p { margin: 0 0 12px; max-width: 460px; color: var(--fs-muted); font-size: 14px; }

.sl-col-check { width: 40px; text-align: center; }
.sl-col-check input { width: 16px; height: 16px; accent-color: var(--fs-primary); cursor: pointer; }
.sl-ligne { cursor: pointer; }
.sl-ligne:focus-visible td { background: var(--fs-row-hover); outline: none; }
.sl-nom strong { display: block; color: var(--fs-text); }
.sl-nom small, .sl-base small, .sl-date small { display: block; font-size: 12px; color: var(--fs-muted); max-width: 380px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sl-actions { white-space: nowrap; }

.sl-comparaison th { vertical-align: bottom; }
.sl-comp-nom { display: block; font-weight: 600; color: var(--fs-text); white-space: normal; }
.sl-comparaison th small { display: block; font-weight: 400; font-size: 12px; color: var(--fs-muted); }
.sl-sep td { border-top: 2px solid var(--fs-border-strong, var(--fs-border)); }
.sl-resultat td { font-weight: 700; }
.sl-positif { color: var(--fs-success); }
.sl-negatif { color: var(--fs-negative); }
.sl-note { margin: 10px 4px 0; font-size: 12.5px; color: var(--fs-muted); }

.num { font-variant-numeric: tabular-nums; }
.text-right { text-align: right; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* Impression : seul le contenu de la page */
@media print {
  :global(aside.sb), :global(header.hd), :global(.fs-header .fs-actions), :global(.fs-chat-btn), :global(.as-panel), :global(.fs-footer) { display: none !important; }
  :global(.fs-main) { margin: 0 !important; padding: 0 !important; }
}
</style>
