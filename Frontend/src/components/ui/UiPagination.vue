<!--
  Pagination unique de l'application (pages hors module Analyse).

  Props : v-model (page courante, 1-based), total-pages, total (nb d'éléments, optionnel),
          per-page (optionnel, pour « 21–40 sur 312 »)
  Exemple :
    <UiPagination v-model="currentPage" :total-pages="totalPages" :total="filtered.length" :per-page="20" />
  Masquée s'il n'y a qu'une page.
-->
<template>
  <nav v-if="totalPages > 1" class="ui-pag" aria-label="Pagination">
    <span v-if="total != null" class="ui-pag__info">
      <template v-if="perPage">{{ from }}–{{ to }} sur </template>{{ total }} élément{{ total > 1 ? 's' : '' }}
    </span>
    <div class="ui-pag__pages">
      <UiButton variant="ghost" size="sm" icon="bi-chevron-left" aria-label="Page précédente" :disabled="modelValue <= 1" @click="go(modelValue - 1)" />
      <template v-for="(p, i) in pages" :key="i">
        <span v-if="p === '…'" class="ui-pag__dots" aria-hidden="true">…</span>
        <UiButton
          v-else
          size="sm"
          class="ui-pag__num"
          :variant="p === modelValue ? 'primary' : 'ghost'"
          :aria-current="p === modelValue ? 'page' : undefined"
          @click="go(p)"
        >{{ p }}</UiButton>
      </template>
      <UiButton variant="ghost" size="sm" icon="bi-chevron-right" aria-label="Page suivante" :disabled="modelValue >= totalPages" @click="go(modelValue + 1)" />
    </div>
  </nav>
</template>

<script setup>
import { computed } from 'vue';
import UiButton from './UiButton.vue';

const props = defineProps({
  modelValue: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  total: { type: Number, default: null },
  perPage: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue', 'change']);

const from = computed(() => (props.modelValue - 1) * props.perPage + 1);
const to = computed(() => Math.min(props.modelValue * props.perPage, props.total ?? Infinity));

const pages = computed(() => {
  const cur = props.modelValue;
  const last = props.totalPages;
  const out = [];
  for (let p = 1; p <= last; p++) {
    if (p === 1 || p === last || Math.abs(p - cur) <= 1 || (cur <= 3 && p <= 4) || (cur >= last - 2 && p >= last - 3)) {
      if (out.length && p - out[out.length - 1] > 1) out.push('…');
      out.push(p);
    }
  }
  return out;
});

function go(p) {
  if (p < 1 || p > props.totalPages || p === props.modelValue) return;
  emit('update:modelValue', p);
  emit('change', p);
}
</script>

<style scoped>
.ui-pag {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 16px;
  border-top: 1px solid var(--fs-border, #e5e8ef);
}
.ui-pag__info { font-size: var(--fs-text-sm, 13px); color: var(--fs-muted, #64748b); font-variant-numeric: tabular-nums; }
.ui-pag__pages { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.ui-pag__num { min-width: 30px; padding: 0 8px; font-variant-numeric: tabular-nums; }
.ui-pag__dots { width: 24px; text-align: center; color: var(--fs-muted, #64748b); font-size: var(--fs-text-sm, 13px); }
</style>
