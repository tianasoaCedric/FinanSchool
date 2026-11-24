<template>
  <AppLayout
    :user="user"
    :icon="icon"
    :title="title"
    :subtitle="subtitle"
    section="États financiers"
    back
    :back-label="backLabel"
    @back="$emit('back')"
  >
    <template #actions>
      <UiButton @click="$emit('export-excel')">
        <i class="bi bi-file-earmark-spreadsheet"></i> Excel
      </UiButton>
      <UiButton variant="primary" @click="$emit('export-pdf')">
        <i class="bi bi-file-earmark-pdf"></i> Exporter en PDF
      </UiButton>
    </template>

    <div class="fs-stack">
      <!-- Exercice + informations -->
      <section v-if="exerciceOptions.length || exerciceInfo?.date_debut" class="fs-card fs-toolbar" aria-label="Exercice">
        <div v-if="exerciceOptions.length" class="fs-toolbar__field">
          <label class="fs-label" :for="selectId">Exercice comptable</label>
          <select :id="selectId" class="fs-select" :value="exercice" @change="onSelect">
            <option v-for="opt in exerciceOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <template v-if="exerciceInfo?.date_debut">
          <div class="fs-stat">
            <span class="fs-label">Période</span>
            <span class="fs-value">{{ formatDate(exerciceInfo.date_debut) }} → {{ formatDate(exerciceInfo.date_fin) }}</span>
          </div>
          <div class="fs-stat">
            <span class="fs-label">Devise</span>
            <span class="fs-value">Ariary (Ar)</span>
          </div>
          <div class="fs-stat">
            <span class="fs-label">Statut</span>
            <UiBadge :tone="exerciceInfo.statut === 'OUVERT' ? 'success' : 'neutral'" dot>{{ statutLabel }}</UiBadge>
          </div>
        </template>
      </section>

      <!-- Contrôles de cohérence des états (PCG 2005) -->
      <section v-if="controles" class="fs-card ctl" aria-label="Contrôles de cohérence">
        <div class="ctl-tete">
          <span class="ctl-titre"><i class="bi bi-clipboard-check"></i> Contrôles de cohérence</span>
          <UiBadge :tone="nbAnomalies ? 'danger' : 'success'" dot>
            {{ nbAnomalies ? `${nbAnomalies} anomalie(s)` : 'États cohérents' }}
          </UiBadge>
        </div>
        <ul class="ctl-liste">
          <li v-for="c in listeControles" :key="c.cle" :class="c.ok ? 'is-ok' : 'is-ko'" :title="c.detail">
            <i :class="c.ok ? 'bi bi-check-circle-fill' : 'bi bi-exclamation-triangle-fill'" aria-hidden="true"></i>
            <span>{{ c.libelle }}</span>
            <small v-if="!c.ok">{{ c.detail }}</small>
          </li>
        </ul>
        <p v-if="controles.brouillons_exclus?.pieces" class="ctl-info">
          <i class="bi bi-info-circle"></i>
          {{ controles.brouillons_exclus.pieces }} pièce(s) en brouillon sur la période ne sont pas prises en compte :
          seules les écritures validées figurent dans les états.
          <router-link to="/validation">Valider les écritures</router-link>
        </p>
      </section>

      <!-- Filtres spécifiques à la page (ex. Balance) -->
      <section v-if="$slots.filters" class="fs-card">
        <slot name="filters" />
      </section>

      <!-- Tableau -->
      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">{{ tableTitle || title }}</h2>
          <span class="fs-table-card__hint"><slot name="table-hint">Montants en Ariary</slot></span>
        </div>
        <div v-if="loading" class="fs-skeleton" aria-busy="true" aria-label="Chargement">
          <div v-for="n in 9" :key="n" class="fs-skeleton__row"></div>
        </div>
        <div v-else class="fs-table-wrap">
          <slot />
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import AppLayout from '@/components/template/AppLayout.vue';

const props = defineProps({
  user: { type: Object, default: null },
  icon: { type: String, default: 'bi-file-earmark-bar-graph' },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  tableTitle: { type: String, default: '' },
  backLabel: { type: String, default: 'Journaux' },
  /** [{ value, label }] */
  exerciceOptions: { type: Array, default: () => [] },
  exercice: { type: [String, Number], default: '' },
  /** { annee_fiscale, date_debut, date_fin, statut } */
  exerciceInfo: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:exercice', 'exercice-change', 'export-pdf', 'export-excel', 'back']);
const selectId = `fs-exercice-${Math.random().toString(36).slice(2, 8)}`;

const statutLabel = computed(() => {
  const s = props.exerciceInfo?.statut;
  return s ? s.charAt(0) + s.slice(1).toLowerCase() : '—';
});

function onSelect(event) {
  const raw = event.target.value;
  const match = props.exerciceOptions.find(o => String(o.value) === raw);
  emit('update:exercice', match ? match.value : raw);
  emit('exercice-change');
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ---- Contrôles de cohérence de l'exercice affiché ----
const controles = ref(null);
const montant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

watch(
  () => [props.exerciceInfo?.date_debut, props.exerciceInfo?.date_fin],
  async ([debut, fin]) => {
    controles.value = null;
    if (!debut || !fin) return;
    try {
      const { data } = await axios.get(`${API_BASE}/etats-financiers/controles`, {
        params: { date_debut: String(debut).slice(0, 10), date_fin: String(fin).slice(0, 10) },
      });
      controles.value = data;
    } catch {
      controles.value = null;
    }
  },
  { immediate: true }
);

const listeControles = computed(() => {
  const c = controles.value;
  if (!c) return [];
  const liste = [
    { cle: 'bilan', libelle: 'Actif = Passif', ok: c.equilibre_bilan.ok,
      detail: `Actif ${montant(c.equilibre_bilan.actif)} · Passif ${montant(c.equilibre_bilan.passif)} · écart ${montant(c.equilibre_bilan.ecart)}` },
    { cle: 'resultat', libelle: 'Résultat identique partout', ok: c.resultat.ok,
      detail: `CR ${montant(c.resultat.compte_resultat)} · par fonction ${montant(c.resultat.par_fonction)} · bilan ${montant(c.resultat.bilan)}` },
    { cle: 'treso', libelle: 'Trésorerie rapprochée', ok: c.tresorerie.ok,
      detail: `Flux ${montant(c.tresorerie.flux)} · constatée ${montant(c.tresorerie.constatee)} · écart ${montant(c.tresorerie.ecart)}` },
    { cle: 'cp', libelle: 'Capitaux propres', ok: c.capitaux_propres.ok,
      detail: `Variations ${montant(c.capitaux_propres.variations)} · bilan ${montant(c.capitaux_propres.bilan)}` },
  ];
  if (c.pieces_validees_desequilibrees) {
    liste.push({ cle: 'deseq', libelle: 'Pièces validées équilibrées', ok: false, detail: `${c.pieces_validees_desequilibrees} pièce(s) validée(s) déséquilibrée(s)` });
  }
  if (c.comptes_hors_plan?.length) {
    liste.push({ cle: 'horsplan', libelle: 'Comptes conformes au plan', ok: false, detail: `Hors plan : ${c.comptes_hors_plan.join(', ')}` });
  }
  return liste;
});
const nbAnomalies = computed(() => listeControles.value.filter(c => !c.ok).length);
</script>

<style scoped>
.ctl { padding: 14px 20px; display: flex; flex-direction: column; gap: 10px; }
.ctl-tete { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.ctl-titre { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: var(--fs-text); }
.ctl-titre .bi { color: var(--fs-primary-text); }
.ctl-liste { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px 18px; }
.ctl-liste li { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--fs-text-2); cursor: help; }
.ctl-liste li.is-ok .bi { color: var(--fs-success); }
.ctl-liste li.is-ko .bi { color: var(--fs-negative); }
.ctl-liste li.is-ko { color: var(--fs-text); font-weight: 500; }
.ctl-liste small { color: var(--fs-negative); font-weight: 400; }
.ctl-info { margin: 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13px; color: var(--fs-muted); }
.ctl-info a { color: var(--fs-primary-text); font-weight: 500; }
</style>
