<!--
  Indicateur de chargement (pages hors module Analyse).

  Props : label, size (sm|md|lg), overlay (voile semi-transparent sur le parent, qui doit être
          position: relative), inline (en ligne, sans marge)
  Exemples :
    <UiLoader v-if="loading" label="Chargement des écritures…" />
    <div style="position:relative"> … <UiLoader v-if="saving" overlay label="Enregistrement…" /></div>
-->
<template>
  <Transition name="ui-loader" appear>
    <div class="ui-loader" :class="[`ui-loader--${size}`, { 'ui-loader--overlay': overlay, 'ui-loader--inline': inline }]" role="status" aria-live="polite">
      <span class="ui-loader__spin" aria-hidden="true"></span>
      <span v-if="label" class="ui-loader__label">{{ label }}</span>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  label: { type: String, default: 'Chargement…' },
  size: { type: String, default: 'md' },
  overlay: { type: Boolean, default: false },
  inline: { type: Boolean, default: false },
});
</script>

<style scoped>
.ui-loader {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 40px 16px;
  color: var(--fs-muted, #64748b);
  font-size: var(--fs-text-sm, 13px);
}
.ui-loader--inline { flex-direction: row; padding: 0; gap: 8px; display: inline-flex; }
.ui-loader--overlay {
  position: absolute;
  inset: 0;
  z-index: 5;
  padding: 0;
  background: color-mix(in srgb, var(--fs-surface, #fff) 72%, transparent);
  backdrop-filter: blur(1px);
  border-radius: inherit;
}
.ui-loader__spin {
  --s: 28px;
  width: var(--s);
  height: var(--s);
  border-radius: 50%;
  border: 3px solid var(--fs-primary-soft, rgba(28, 69, 189, 0.12));
  border-top-color: var(--fs-primary, #1c45bd);
  animation: ui-loader-spin 0.75s linear infinite;
}
.ui-loader--sm .ui-loader__spin { --s: 16px; border-width: 2px; }
.ui-loader--lg .ui-loader__spin { --s: 40px; }
.ui-loader__label { font-weight: 500; }

.ui-loader-enter-active, .ui-loader-leave-active { transition: opacity 0.2s ease; }
.ui-loader-enter-from, .ui-loader-leave-to { opacity: 0; }
@keyframes ui-loader-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .ui-loader__spin { animation-duration: 1.8s; } }
</style>
