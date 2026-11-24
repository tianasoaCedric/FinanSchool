<!--
  Bouton unique de l'application (pages hors module Analyse).

  Variantes : primary | secondary | ghost | danger | success | warning
              soft-primary | soft-danger | soft-success  (actions discrètes, ex. dans un tableau)
              inverse (sur fond coloré) | link
  Tailles   : sm | md | lg
  Exemples  :
    <UiButton variant="primary" icon="bi-plus-lg" @click="add">Ajouter</UiButton>
    <UiButton variant="ghost" icon="bi-x-lg" aria-label="Fermer" />      ← bouton icône seule
    <UiButton to="/ecriture" variant="primary">Nouvelle écriture</UiButton>
    <UiButton type="submit" :loading="saving">Enregistrer</UiButton>
-->
<template>
  <component
    :is="tag"
    v-bind="linkAttrs"
    class="ui-btn"
    :class="[
      `ui-btn--${variant}`,
      `ui-btn--${size}`,
      { 'ui-btn--block': block, 'ui-btn--icon': isIconOnly, 'is-loading': loading },
    ]"
    :aria-busy="loading || undefined"
  >
    <span v-if="loading" class="ui-btn__spinner" aria-hidden="true"></span>
    <i v-else-if="icon" :class="['bi', icon]" aria-hidden="true"></i>
    <slot />
    <i v-if="iconRight && !loading" :class="['bi', iconRight]" aria-hidden="true"></i>
  </component>
</template>

<script setup>
import { computed, useSlots } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
  variant: { type: String, default: 'secondary' },
  size: { type: String, default: 'md' },
  icon: { type: String, default: '' },
  iconRight: { type: String, default: '' },
  iconOnly: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  type: { type: String, default: 'button' },
  /** Lien interne (router-link) */
  to: { type: [String, Object], default: null },
  /** Lien externe */
  href: { type: String, default: null },
});

const slots = useSlots();
const tag = computed(() => (props.to ? RouterLink : props.href ? 'a' : 'button'));
const linkAttrs = computed(() => {
  if (props.to) return { to: props.to, 'aria-disabled': props.disabled || undefined };
  if (props.href) return { href: props.href, 'aria-disabled': props.disabled || undefined };
  return { type: props.type, disabled: props.disabled || props.loading };
});
// Icône seule : pas de texte dans le slot (seulement une icône <i class="bi …">)
const isIconOnly = computed(() => {
  if (props.iconOnly) return true;
  const content = slots.default?.() || [];
  if (!content.length) return !!props.icon;
  return content.every(n =>
    (typeof n.children === 'string' && !n.children.trim() && n.type !== 'i') ||
    (n.type === 'i' && /\bbi\b/.test(n.props?.class || '')));
});
</script>

<style scoped>
.ui-btn {
  --b-bg: var(--fs-surface, #fff);
  --b-fg: var(--fs-text-2, #334155);
  --b-border: var(--fs-border-strong, #d5dae4);
  --b-bg-hover: var(--fs-surface-2, #f8fafc);
  --b-fg-hover: var(--fs-text, #0f172a);
  --b-border-hover: var(--fs-muted, #64748b);

  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 38px;
  padding: 0 14px;
  margin: 0;
  border: 1px solid var(--b-border);
  border-radius: 10px;
  background: var(--b-bg);
  color: var(--b-fg);
  font-family: inherit;
  font-size: 14px;
  font-weight: 500;
  line-height: 1;
  white-space: nowrap;
  text-decoration: none;
  vertical-align: middle;
  cursor: pointer;
  user-select: none;
  box-shadow: none;
  transform: none;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.ui-btn:hover { background: var(--b-bg-hover); border-color: var(--b-border-hover); color: var(--b-fg-hover); text-decoration: none; }
.ui-btn:focus-visible { outline: 2px solid var(--fs-primary, #1c45bd); outline-offset: 2px; }
.ui-btn:disabled, .ui-btn[aria-disabled="true"] { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
.ui-btn :deep(.bi) { margin: 0 !important; font-size: 1.05em; line-height: 1; }

/* Tailles */
.ui-btn--sm { height: 30px; padding: 0 10px; font-size: 13px; border-radius: 8px; gap: 6px; }
.ui-btn--lg { height: 44px; padding: 0 18px; font-size: 15px; }
.ui-btn--block { display: flex; width: 100%; }
.ui-btn--icon { padding: 0; width: 38px; }
.ui-btn--icon.ui-btn--sm { width: 30px; }
.ui-btn--icon.ui-btn--lg { width: 44px; }

/* Variantes pleines */
.ui-btn--primary {
  --b-bg: var(--fs-primary, #1c45bd); --b-fg: #fff; --b-border: var(--fs-primary, #1c45bd);
  --b-bg-hover: var(--fs-primary-hover, #163a9f); --b-fg-hover: #fff; --b-border-hover: var(--fs-primary-hover, #163a9f);
  box-shadow: 0 1px 2px rgba(28, 69, 189, 0.25);
}
.ui-btn--danger {
  --b-bg: var(--fs-negative, #dc2626); --b-fg: #fff; --b-border: var(--fs-negative, #dc2626);
  --b-bg-hover: color-mix(in srgb, var(--fs-negative, #dc2626) 88%, #000); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--success {
  --b-bg: var(--fs-success, #059669); --b-fg: #fff; --b-border: var(--fs-success, #059669);
  --b-bg-hover: color-mix(in srgb, var(--fs-success, #059669) 88%, #000); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--warning {
  --b-bg: var(--fs-warning, #d97706); --b-fg: #fff; --b-border: var(--fs-warning, #d97706);
  --b-bg-hover: color-mix(in srgb, var(--fs-warning, #d97706) 88%, #000); --b-fg-hover: #fff; --b-border-hover: transparent;
}

/* Variantes discrètes */
.ui-btn--ghost { --b-bg: transparent; --b-border: transparent; --b-bg-hover: var(--fs-surface-3, #eef1f6); --b-border-hover: transparent; }
.ui-btn--soft-primary {
  --b-bg: var(--fs-primary-soft, rgba(28, 69, 189, 0.08)); --b-fg: var(--fs-primary-text, #1c45bd); --b-border: transparent;
  --b-bg-hover: var(--fs-primary, #1c45bd); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--soft-danger {
  --b-bg: var(--fs-negative-soft, rgba(220, 38, 38, 0.1)); --b-fg: var(--fs-negative, #dc2626); --b-border: transparent;
  --b-bg-hover: var(--fs-negative, #dc2626); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--soft-success {
  --b-bg: var(--fs-success-soft, rgba(5, 150, 105, 0.12)); --b-fg: var(--fs-success, #059669); --b-border: transparent;
  --b-bg-hover: var(--fs-success, #059669); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--inverse {
  --b-bg: rgba(255, 255, 255, 0.14); --b-fg: #fff; --b-border: transparent;
  --b-bg-hover: rgba(255, 255, 255, 0.24); --b-fg-hover: #fff; --b-border-hover: transparent;
}
.ui-btn--link {
  --b-bg: transparent; --b-fg: var(--fs-primary-text, #1c45bd); --b-border: transparent;
  --b-bg-hover: transparent; --b-fg-hover: var(--fs-primary-text, #1c45bd); --b-border-hover: transparent;
  height: auto; padding: 0;
}
.ui-btn--link:hover { text-decoration: underline; }

/* Chargement */
.ui-btn__spinner {
  width: 1em;
  height: 1em;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: ui-btn-spin 0.7s linear infinite;
}
@keyframes ui-btn-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .ui-btn { transition: none; } .ui-btn__spinner { animation-duration: 1.6s; } }
</style>
