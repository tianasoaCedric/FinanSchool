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
import { computed } from 'vue';
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
</script>
