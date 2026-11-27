<!--
  Fenêtre modale unique de l'application (pages hors module Analyse).

  Props   : v-model (ouverte), title, subtitle, icon, tone (default|success|danger|warning|info),
            size (sm 420px | md 560px | lg 760px | xl 1040px), persistent (pas de fermeture
            au clic extérieur / Échap), loading (squelette à la place du contenu)
  Slots   : default (corps), footer (boutons, alignés à droite), header (remplace le titre)
  Exemple :
    <UiModal v-model="showModal" title="Nouveau compte" icon="bi-plus-lg">
      <form id="f-compte" @submit.prevent="save" class="ui-form">…</form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="f-compte" :loading="saving">Enregistrer</UiButton>
      </template>
    </UiModal>

  Rendue dans <body> (Teleport) : toujours au-dessus de tout et centrée sur
  l'écran, quel que soit le conteneur. Le wrapper .fs-page reprend les jetons
  du thème (clair/sombre) de la page.
-->
<template>
  <Teleport to="body">
  <div class="fs-page fs-content ui-modal-portail" :data-theme="theme">
  <Transition name="ui-modal">
    <div v-if="modelValue" class="ui-modal" role="presentation" @mousedown.self="onBackdrop">
      <div
        ref="dialog"
        class="ui-modal__dialog"
        :class="[`ui-modal__dialog--${size}`, `ui-modal--${tone}`]"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="title ? titleId : undefined"
        tabindex="-1"
      >
        <header class="ui-modal__head">
          <slot name="header">
            <span v-if="icon || toneIcon" class="ui-modal__icon" aria-hidden="true">
              <i :class="['bi', icon || toneIcon]"></i>
            </span>
            <div class="ui-modal__titles">
              <h2 v-if="title" :id="titleId" class="ui-modal__title">{{ title }}</h2>
              <p v-if="subtitle" class="ui-modal__subtitle">{{ subtitle }}</p>
            </div>
          </slot>
          <UiButton v-if="!hideClose" variant="ghost" size="sm" icon="bi-x-lg" aria-label="Fermer" class="ui-modal__close" @click="close" />
        </header>

        <div class="ui-modal__body">
          <UiSkeleton v-if="loading" variant="form" :rows="4" />
          <slot v-else />
        </div>

        <footer v-if="$slots.footer" class="ui-modal__foot">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Transition>
  </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import UiSkeleton from './UiSkeleton.vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
  tone: { type: String, default: 'default' },
  size: { type: String, default: 'md' },
  persistent: { type: Boolean, default: false },
  hideClose: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'close']);

const dialog = ref(null);
const theme = ref('light');
const titleId = `ui-modal-${Math.random().toString(36).slice(2, 9)}`;
const toneIcon = computed(() => ({
  success: 'bi-check-circle',
  danger: 'bi-exclamation-octagon',
  warning: 'bi-exclamation-triangle',
  info: 'bi-info-circle',
}[props.tone] || ''));

function close() {
  emit('update:modelValue', false);
  emit('close');
}
function onBackdrop() {
  if (!props.persistent) close();
}
function onKey(e) {
  if (e.key === 'Escape' && !props.persistent) close();
}

let lastFocus = null;
watch(() => props.modelValue, async (open) => {
  if (open) {
    theme.value = document.querySelector('.fs-page:not(.ui-modal-portail)')?.dataset.theme || 'light';
    lastFocus = document.activeElement;
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    await nextTick();
    const first = dialog.value?.querySelector('.ui-modal__body input:not([type=hidden]):not([readonly]), .ui-modal__body select, .ui-modal__body textarea');
    (first || dialog.value)?.focus({ preventScroll: true });
  } else {
    cleanup();
    lastFocus?.focus?.({ preventScroll: true });
  }
}, { immediate: true });

function cleanup() {
  document.removeEventListener('keydown', onKey);
  document.body.style.overflow = '';
}
onBeforeUnmount(cleanup);
</script>

<style scoped>
/* Le wrapper ne sert qu'à porter les jetons du thème : il n'occupe aucune place */
.fs-page.ui-modal-portail { display: contents; min-height: 0; background: none; }
.ui-modal {
  position: fixed;
  inset: 0;
  z-index: 10000;
  display: grid;
  place-items: center;
  padding: 24px;
  background: var(--fs-overlay, rgba(15, 23, 42, 0.45));
  backdrop-filter: blur(3px);
  overflow-y: auto;
}
.ui-modal__dialog {
  --m-accent: var(--fs-primary-text, #1c45bd);
  --m-accent-soft: var(--fs-primary-soft, rgba(28, 69, 189, 0.08));

  width: 100%;
  max-height: calc(100vh - 48px);
  display: flex;
  flex-direction: column;
  background: var(--fs-surface, #fff);
  color: var(--fs-text-2, #334155);
  border: 1px solid var(--fs-border, #e5e8ef);
  border-radius: var(--fs-radius, 14px);
  box-shadow: var(--fs-shadow-xl, 0 24px 48px -16px rgba(15, 23, 42, 0.28));
  text-align: left;
  outline: none;
}
.ui-modal__dialog--sm { max-width: 420px; }
.ui-modal__dialog--md { max-width: 560px; }
.ui-modal__dialog--lg { max-width: 760px; }
.ui-modal__dialog--xl { max-width: 1040px; }

.ui-modal--success { --m-accent: var(--fs-success, #059669); --m-accent-soft: var(--fs-success-soft, rgba(5, 150, 105, 0.12)); }
.ui-modal--danger  { --m-accent: var(--fs-negative, #dc2626); --m-accent-soft: var(--fs-negative-soft, rgba(220, 38, 38, 0.1)); }
.ui-modal--warning { --m-accent: var(--fs-warning, #d97706); --m-accent-soft: var(--fs-warning-soft, rgba(217, 119, 6, 0.12)); }
.ui-modal--info    { --m-accent: #0284c7; --m-accent-soft: rgba(14, 165, 233, 0.12); }

.ui-modal__head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 20px 16px 24px;
  border-bottom: 1px solid var(--fs-border, #e5e8ef);
}
.ui-modal__icon {
  flex: none;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  background: var(--m-accent-soft);
  color: var(--m-accent);
  font-size: var(--fs-text-lg, 18px);
}
.ui-modal__titles { flex: 1; min-width: 0; }
.ui-modal__title {
  margin: 0;
  font-size: var(--fs-text-lg, 18px);
  font-weight: 600;
  line-height: 1.3;
  letter-spacing: -0.01em;
  color: var(--fs-text, #0f172a);
}
.ui-modal__subtitle { margin: 2px 0 0; font-size: var(--fs-text-sm, 13px); color: var(--fs-muted, #64748b); }
.ui-modal__close { margin-left: auto; align-self: flex-start; }

.ui-modal__body {
  padding: 20px 24px;
  overflow-y: auto;
  font-size: var(--fs-text-base, 14px);
  line-height: 1.5;
}
.ui-modal__foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  padding: 14px 24px;
  border-top: 1px solid var(--fs-border, #e5e8ef);
  background: var(--fs-surface-2, #f8fafc);
  border-radius: 0 0 var(--fs-radius, 14px) var(--fs-radius, 14px);
}

/* Animation : fondu du voile + montée légère de la fenêtre */
.ui-modal-enter-active, .ui-modal-leave-active { transition: opacity 0.2s ease; }
.ui-modal-enter-active .ui-modal__dialog, .ui-modal-leave-active .ui-modal__dialog { transition: transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.2s ease; }
.ui-modal-enter-from, .ui-modal-leave-to { opacity: 0; }
.ui-modal-enter-from .ui-modal__dialog, .ui-modal-leave-to .ui-modal__dialog { transform: translateY(12px) scale(0.98); opacity: 0; }

@media (max-width: 640px) {
  .ui-modal { padding: 12px; align-items: end; }
  .ui-modal__head, .ui-modal__body, .ui-modal__foot { padding-left: 16px; padding-right: 16px; }
}
@media (prefers-reduced-motion: reduce) {
  .ui-modal-enter-active, .ui-modal-leave-active, .ui-modal-enter-active .ui-modal__dialog, .ui-modal-leave-active .ui-modal__dialog { transition: none; }
}
</style>
