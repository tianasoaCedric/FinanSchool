<!-- Une notification (menu de l'en-tête et page /notifications). -->
<template>
  <article
    class="nt"
    :class="[`nt--${n.niveau}`, { 'is-unread': n.statut !== 'lu', 'nt--compact': compact, 'is-link': !!n.lien }]"
    :tabindex="n.lien ? 0 : undefined"
    :role="n.lien ? 'link' : undefined"
    @click="ouvrir"
    @keydown.enter="ouvrir"
  >
    <span class="nt-icon" aria-hidden="true">
      <i :class="['bi', CATEGORIES[n.categorie]?.icon || 'bi-bell']"></i>
    </span>

    <div class="nt-body">
      <div class="nt-top">
        <p class="nt-title">{{ n.titre }}</p>
        <time class="nt-date" :datetime="n.date" :title="dateComplete">{{ dateRelative(n.date) }}</time>
      </div>
      <p class="nt-msg">{{ n.message }}</p>
      <div v-if="!compact || n.niveau !== 'info'" class="nt-meta">
        <span v-if="n.niveau !== 'info'" class="nt-level"><i :class="['bi', n.niveau === 'urgent' ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill']"></i>{{ NIVEAUX[n.niveau]?.label }}</span>
        <span v-if="!compact" class="nt-cat">{{ CATEGORIES[n.categorie]?.label }}</span>
        <span v-if="!compact && n.lien" class="nt-open">Ouvrir <i class="bi bi-arrow-right"></i></span>
      </div>
    </div>

    <span v-if="n.statut !== 'lu'" class="nt-dot" aria-label="Non lue"></span>

    <div class="nt-actions" @click.stop @keydown.enter.stop>
      <button
        type="button"
        class="nt-act"
        :title="n.statut === 'lu' ? 'Marquer comme non lue' : 'Marquer comme lue'"
        :aria-label="n.statut === 'lu' ? 'Marquer comme non lue' : 'Marquer comme lue'"
        @click="n.statut === 'lu' ? marquerNonLue(n) : marquerLue(n)"
      >
        <i :class="['bi', n.statut === 'lu' ? 'bi-envelope' : 'bi-envelope-open']"></i>
      </button>
      <button type="button" class="nt-act nt-act--danger" title="Supprimer" aria-label="Supprimer la notification" @click="$emit('supprimer', n)">
        <i class="bi bi-trash3"></i>
      </button>
    </div>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { CATEGORIES, NIVEAUX, dateRelative, useCentreNotifications } from '@/composables/useCentreNotifications';

const props = defineProps({
  n: { type: Object, required: true },
  compact: { type: Boolean, default: false },
});
const emit = defineEmits(['supprimer', 'ouvert']);
const router = useRouter();
const { marquerLue, marquerNonLue } = useCentreNotifications();

const dateComplete = computed(() => (props.n.date ? new Date(props.n.date).toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' }) : ''));

function ouvrir() {
  marquerLue(props.n);
  if (props.n.lien) {
    emit('ouvert');
    router.push(props.n.lien);
  }
}
</script>

<style scoped>
.nt {
  --nt-accent: var(--fs-primary, #1c45bd);
  --nt-soft: var(--fs-primary-soft, rgba(28, 69, 189, 0.08));
  position: relative;
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px;
  border-radius: 10px;
  transition: background-color 0.15s ease;
}
.nt--avertissement { --nt-accent: var(--fs-warning, #d97706); --nt-soft: var(--fs-warning-soft, rgba(217, 119, 6, 0.12)); }
.nt--urgent { --nt-accent: var(--fs-negative, #dc2626); --nt-soft: var(--fs-negative-soft, rgba(220, 38, 38, 0.1)); }
.nt.is-link { cursor: pointer; }
.nt:hover, .nt:focus-within { background: var(--fs-surface-2, #f8fafc); }
.nt:focus-visible { outline: 2px solid var(--fs-primary, #1c45bd); outline-offset: -2px; }
.nt--compact { padding: 11px 12px; gap: 11px; }

.nt-icon {
  flex: none; width: 36px; height: 36px; border-radius: 10px;
  display: grid; place-items: center;
  background: var(--nt-soft); color: var(--nt-accent); font-size: 16px;
}
.nt--compact .nt-icon { width: 32px; height: 32px; font-size: 15px; }

.nt-body { flex: 1; min-width: 0; padding-right: 6px; }
.nt-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.nt-title {
  margin: 0; font-size: var(--fs-text-sm, 13px); font-weight: 500; color: var(--fs-text-2, #334155);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.nt.is-unread .nt-title { font-weight: 650; color: var(--fs-text, #0f172a); }
.nt-date { flex: none; font-size: var(--fs-text-xs, 12px); color: var(--fs-muted, #64748b); white-space: nowrap; }
.nt-msg {
  margin: 3px 0 0; font-size: var(--fs-text-sm, 13px); line-height: 1.45; color: var(--fs-muted, #64748b);
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.nt:not(.nt--compact) .nt-msg { -webkit-line-clamp: 3; }
.nt-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 7px; font-size: var(--fs-text-xs, 12px); }
.nt-level { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; color: var(--nt-accent); }
.nt-cat { color: var(--fs-muted, #64748b); }
.nt-cat::before { content: '·'; margin-right: 10px; }
.nt-level + .nt-cat::before { display: inline; }
.nt-meta > .nt-cat:first-child::before { display: none; }
.nt-open { margin-left: auto; color: var(--fs-primary-text, #1c45bd); font-weight: 500; }

.nt-dot {
  position: absolute; top: 19px; right: 14px;
  width: 8px; height: 8px; border-radius: 50%; background: var(--fs-primary, #1c45bd);
}
.nt--compact .nt-dot { top: 16px; right: 10px; }

.nt-actions {
  position: absolute; right: 10px; bottom: 10px;
  display: flex; gap: 2px; padding: 2px; border-radius: 8px;
  background: var(--fs-surface, #fff); box-shadow: var(--fs-shadow, 0 1px 3px rgba(0,0,0,.1));
  opacity: 0; transform: translateY(2px); transition: opacity 0.15s ease, transform 0.15s ease;
}
.nt:hover .nt-actions, .nt:focus-within .nt-actions { opacity: 1; transform: none; }
.nt-act {
  display: grid; place-items: center; width: 28px; height: 28px; border: 0; border-radius: 6px;
  background: transparent; color: var(--fs-text-2, #334155); cursor: pointer; font-size: 14px;
}
.nt-act:hover { background: var(--fs-surface-3, #eef1f6); color: var(--fs-text, #0f172a); }
.nt-act--danger:hover { background: var(--fs-negative-soft, rgba(220,38,38,.1)); color: var(--fs-negative, #dc2626); }
.nt-act:focus-visible { outline: 2px solid var(--fs-primary, #1c45bd); outline-offset: 1px; }

@media (hover: none) { .nt-actions { opacity: 1; transform: none; position: static; margin-left: 4px; align-self: center; box-shadow: none; } }
@media (prefers-reduced-motion: reduce) { .nt, .nt-actions { transition: none; } }
</style>
