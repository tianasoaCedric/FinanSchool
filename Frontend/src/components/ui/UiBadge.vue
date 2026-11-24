<!--
  Badge de statut unique de l'application (pages hors module Analyse).

  Tons    : neutral | primary | success | danger | warning | info
  Tailles : sm | md | lg (lg : pour mettre en avant un chiffre)
  Exemples :
    <UiBadge tone="success">Validé</UiBadge>
    <UiBadge tone="warning" dot>Brouillon</UiBadge>
    <UiBadge :tone="ligne.type === 'produit' ? 'success' : 'danger'">…</UiBadge>
-->
<template>
  <span class="ui-badge" :class="[`ui-badge--${tone}`, `ui-badge--${size}`, { 'ui-badge--dot': dot }]">
    <i v-if="icon" :class="['bi', icon]" aria-hidden="true"></i>
    <slot />
  </span>
</template>

<script setup>
defineProps({
  tone: { type: String, default: 'neutral' },
  size: { type: String, default: 'md' },
  dot: { type: Boolean, default: false },
  icon: { type: String, default: '' },
});
</script>

<style scoped>
.ui-badge {
  --t-bg: var(--fs-neutral-soft, rgba(100, 116, 139, 0.14));
  --t-fg: var(--fs-text-2, #334155);

  display: inline-flex;
  align-items: center;
  gap: 6px;
  width: fit-content;
  height: 24px;
  padding: 0 10px;
  margin: 0;
  border: 0;
  border-radius: 999px;
  background: var(--t-bg);
  color: var(--t-fg);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
  letter-spacing: 0.01em;
  text-transform: none;
  white-space: nowrap;
  vertical-align: middle;
  font-variant-numeric: tabular-nums;
}
.ui-badge :deep(.bi) { margin: 0 !important; font-size: 1em; }
.ui-badge--dot::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.ui-badge--sm { height: 20px; padding: 0 8px; font-size: 11px; }
.ui-badge--lg { height: 32px; padding: 0 14px; font-size: 18px; font-weight: 700; }

.ui-badge--primary { --t-bg: var(--fs-primary-soft, rgba(28, 69, 189, 0.08)); --t-fg: var(--fs-primary-text, #1c45bd); }
.ui-badge--success { --t-bg: var(--fs-success-soft, rgba(5, 150, 105, 0.12)); --t-fg: var(--fs-success, #059669); }
.ui-badge--danger  { --t-bg: var(--fs-negative-soft, rgba(220, 38, 38, 0.1)); --t-fg: var(--fs-negative, #dc2626); }
.ui-badge--warning { --t-bg: var(--fs-warning-soft, rgba(217, 119, 6, 0.12)); --t-fg: var(--fs-warning, #d97706); }
.ui-badge--info    { --t-bg: rgba(14, 165, 233, 0.12); --t-fg: #0284c7; }
:global(.fs-page[data-theme="dark"]) .ui-badge--info { --t-fg: #38bdf8; --t-bg: rgba(56, 189, 248, 0.14); }
</style>
