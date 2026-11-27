<!--
  Squelettes de chargement (pages hors module Analyse).

  Variantes : table (lignes × colonnes) | lines (paragraphes) | form (champs) | cards (tuiles KPI)
  Exemples  :
    <UiSkeleton v-if="loading" variant="table" :rows="8" :cols="5" />
    <UiSkeleton v-if="loading" variant="cards" :rows="4" />
  Dans un <tbody> : <UiSkeleton variant="rows" :rows="6" :cols="5" /> rend directement des <tr>.
-->
<template>
  <template v-if="variant === 'rows'">
    <tr v-for="r in rows" :key="r" class="ui-sk-tr" aria-hidden="true">
      <td v-for="c in cols" :key="c"><span class="ui-sk" :style="{ width: w(r, c) }"></span></td>
    </tr>
  </template>

  <div v-else class="ui-skeleton" :class="`ui-skeleton--${variant}`" role="status" aria-busy="true" :aria-label="label">
    <template v-if="variant === 'table'">
      <div class="ui-sk-row ui-sk-row--head">
        <span v-for="c in cols" :key="c" class="ui-sk ui-sk--sm" :style="{ width: c === 1 ? '40%' : '55%' }"></span>
      </div>
      <div v-for="r in rows" :key="r" class="ui-sk-row">
        <span v-for="c in cols" :key="c" class="ui-sk" :style="{ width: w(r, c) }"></span>
      </div>
    </template>

    <template v-else-if="variant === 'form'">
      <div v-for="r in rows" :key="r" class="ui-sk-field">
        <span class="ui-sk ui-sk--sm" style="width: 30%"></span>
        <span class="ui-sk ui-sk--input"></span>
      </div>
    </template>

    <template v-else-if="variant === 'cards'">
      <div v-for="r in rows" :key="r" class="ui-sk-card">
        <span class="ui-sk ui-sk--sm" style="width: 45%"></span>
        <span class="ui-sk ui-sk--lg" style="width: 70%"></span>
        <span class="ui-sk ui-sk--sm" style="width: 35%"></span>
      </div>
    </template>

    <template v-else>
      <span v-for="r in rows" :key="r" class="ui-sk" :style="{ width: w(r, 1) }"></span>
    </template>
    <span class="ui-sk-sr">{{ label }}</span>
  </div>
</template>

<script setup>
defineProps({
  variant: { type: String, default: 'lines' },
  rows: { type: Number, default: 5 },
  cols: { type: Number, default: 4 },
  label: { type: String, default: 'Chargement…' },
});
// Largeurs variées mais stables (pas d'aléatoire → pas de saut au re-rendu)
const widths = ['92%', '74%', '86%', '64%', '80%', '70%'];
const w = (r, c) => widths[(r * 3 + c) % widths.length];
</script>

<style scoped>
.ui-skeleton { display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; }
.ui-skeleton--table { gap: 0; padding: 0; }
.ui-skeleton--cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; padding: 0; }
.ui-skeleton--form { gap: 18px; padding: 0; }

.ui-sk {
  display: block;
  height: 12px;
  border-radius: 6px;
  background: linear-gradient(90deg, var(--fs-surface-3, #eef1f6) 0%, var(--fs-border, #e5e8ef) 50%, var(--fs-surface-3, #eef1f6) 100%);
  background-size: 200% 100%;
  animation: ui-sk-shimmer 1.4s ease-in-out infinite;
}
.ui-sk--sm { height: 10px; }
.ui-sk--lg { height: 22px; }
.ui-sk--input { height: 40px; width: 100%; border-radius: var(--fs-radius-sm, 10px); }

.ui-sk-row {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 16px;
  padding: 15px 16px;
  border-bottom: 1px solid var(--fs-border, #e5e8ef);
}
.ui-sk-row:last-of-type { border-bottom: 0; }
.ui-sk-row--head { background: var(--fs-thead, #f8fafc); padding: 14px 16px; }
.ui-sk-field { display: flex; flex-direction: column; gap: 8px; }
.ui-sk-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 18px 20px;
  background: var(--fs-surface, #fff);
  border: 1px solid var(--fs-border, #e5e8ef);
  border-radius: var(--fs-radius, 14px);
}
.ui-sk-tr td { padding: 14px 16px; border-bottom: 1px solid var(--fs-border, #e5e8ef); }

.ui-sk-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

@keyframes ui-sk-shimmer { from { background-position: 100% 0; } to { background-position: -100% 0; } }
@media (prefers-reduced-motion: reduce) { .ui-sk { animation: none; } }
</style>
