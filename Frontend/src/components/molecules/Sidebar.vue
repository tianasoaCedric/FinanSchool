<!-- Barre latérale principale (hors module Analyse, qui a la sienne). -->
<template>
  <aside class="sb" aria-label="Navigation principale">
    <div class="sb-brand">
      <img src="../../assets/img/01300.png" alt="Raitra Kidz" class="sb-logo" />
    </div>

    <nav class="sb-nav">
      <div v-for="group in menu" :key="group.title" class="sb-group">
        <p class="sb-group-title">{{ group.title }}</p>

        <template v-for="item in group.items" :key="item.label">
          <!-- Lien simple -->
          <router-link
            v-if="!item.children"
            :to="item.to"
            class="sb-link"
            :class="{ 'is-active': isActive(item) }"
            :aria-current="isActive(item) ? 'page' : undefined"
          >
            <i :class="['bi', item.icon]" aria-hidden="true"></i>
            <span>{{ item.label }}</span>
          </router-link>

          <!-- Groupe dépliable -->
          <div v-else class="sb-folder" :class="{ 'is-open': open[item.label], 'has-active': isActive(item) }">
            <button
              type="button"
              class="sb-link sb-folder-btn"
              :aria-expanded="!!open[item.label]"
              @click="open[item.label] = !open[item.label]"
            >
              <i :class="['bi', item.icon]" aria-hidden="true"></i>
              <span>{{ item.label }}</span>
              <i class="bi bi-chevron-down sb-chevron" aria-hidden="true"></i>
            </button>
            <div v-show="open[item.label]" class="sb-sub">
              <router-link
                v-for="child in item.children"
                :key="child.to"
                :to="child.to"
                class="sb-sublink"
                :class="{ 'is-active': isActive(child) }"
                :aria-current="isActive(child) ? 'page' : undefined"
              >
                {{ child.label }}
              </router-link>
            </div>
          </div>
        </template>
      </div>
    </nav>
  </aside>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { useRoute } from 'vue-router';

// `currentRoute` est encore passé par certaines pages : accepté mais inutile
// (la route active est lue directement depuis le routeur).
defineProps({ currentRoute: { type: String, default: '' } });

const route = useRoute();

const menu = [
  {
    title: "Vue d'ensemble",
    items: [{ label: 'Tableau de bord', icon: 'bi-grid-1x2', to: '/dashboard' }],
  },
  {
    title: 'Saisie',
    items: [
      {
        label: 'Écritures', icon: 'bi-pencil-square',
        children: [
          { label: 'Nouvelle pièce', to: '/ecriture' },
          { label: 'Pièces', to: '/pieces', match: ['/ecriture/'] },
          { label: 'Toutes les lignes', to: '/liste-ecriture' },
          { label: 'Validation', to: '/validation' },
          { label: 'Importer des écritures', to: '/import' },
        ],
      },
      {
        label: 'Journaux', icon: 'bi-journal-text',
        children: [
          { label: 'Tous les journaux', to: '/journal', match: ['/ecritures-journal/'] },
          { label: 'Types de journaux', to: '/type-journal' },
        ],
      },
      { label: 'Grand livre', icon: 'bi-journals', to: '/liste-grand-livre', match: ['/grand-livres/'] },
      { label: 'Clôtures', icon: 'bi-lock', to: '/cloture' },
      { label: "Piste d'audit", icon: 'bi-clock-history', to: '/audit' },
    ],
  },
  {
    title: 'Tiers',
    items: [
      { label: 'Clients et fournisseurs', icon: 'bi-people', to: '/tiers', match: ['/tiers/'] },
      { label: 'Lettrage', icon: 'bi-link-45deg', to: '/lettrage' },
      { label: 'Balance âgée', icon: 'bi-hourglass-split', to: '/balance-agee' },
    ],
  },
  {
    title: 'Structure',
    items: [
      {
        label: 'Plan comptable', icon: 'bi-diagram-3',
        children: [
          { label: 'Rubriques', to: '/rubrique' },
          { label: 'Comptes', to: '/compte' },
          { label: 'Sous-comptes', to: '/souscompte' },
          { label: 'Importer / exporter', to: '/plan-comptable/import' },
        ],
      },
    ],
  },
  {
    title: 'États financiers',
    items: [
      { label: 'Balance générale', icon: 'bi-clipboard-data', to: '/balance' },
      {
        label: 'Compte de résultat', icon: 'bi-receipt',
        children: [
          { label: 'Par nature', to: '/CR-Nature' },
          { label: 'Par fonction', to: '/CR-Fonction' },
        ],
      },
      {
        label: 'Bilan', icon: 'bi-bank',
        children: [
          { label: 'Actif', to: '/BilanActif' },
          { label: 'Passif', to: '/BilanPassif' },
        ],
      },
      {
        label: 'Trésorerie & capitaux', icon: 'bi-cash-stack',
        children: [
          { label: 'Flux de trésorerie', to: '/flux-tresorerie' },
          { label: 'Variation des capitaux propres', to: '/variation-capitaux' },
        ],
      },
      { label: 'Immobilisations et amortissements', icon: 'bi-building-gear', to: '/amortissement' },
    ],
  },
  {
    title: 'Outils',
    items: [{ label: 'Simulations', icon: 'bi-magic', to: '/simulations' }],
  },
  {
    title: 'Administration',
    items: [{ label: 'Paramètres', icon: 'bi-gear', to: '/reglages' }],
  },
];

function isActive(item) {
  if (item.children) return item.children.some(isActive);
  const path = route.path;
  return path === item.to || (item.match || []).some(prefix => path.startsWith(prefix));
}

// Groupes ouverts : ceux qui contiennent la page courante.
const open = reactive({});
watch(
  () => route.path,
  () => {
    for (const group of menu) {
      for (const item of group.items) {
        if (item.children && isActive(item)) open[item.label] = true;
      }
    }
  },
  { immediate: true },
);
</script>

<style scoped>
.sb {
  position: fixed;
  inset: 0 auto 0 0;
  z-index: 1000;
  width: 278px;
  display: flex;
  flex-direction: column;
  background: #0d1b4c;
  background-image: linear-gradient(180deg, #13297a 0%, #0b1640 55%, #08102e 100%);
  color: #c9d3f0;
  font-family: 'Inter Variable', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  font-size: 14px;
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: rgba(255, 255, 255, 0.15) transparent;
}

.sb-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 22px 20px 18px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.sb-logo { height: 56px; width: auto; }

.sb-nav { flex: 1; padding: 14px 14px 24px; display: flex; flex-direction: column; gap: 18px; }

.sb-group { display: flex; flex-direction: column; gap: 2px; }
.sb-group-title {
  margin: 0 0 6px;
  padding: 0 12px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(201, 211, 240, 0.5);
}

.sb-link {
  all: unset;
  box-sizing: border-box;
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 40px;
  padding: 0 12px;
  border-radius: 10px;
  color: #c9d3f0;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}
.sb-link > .bi:first-child { font-size: 17px; width: 20px; text-align: center; opacity: 0.85; }
.sb-link:hover { background: rgba(255, 255, 255, 0.07); color: #fff; }
.sb-link:focus-visible, .sb-sublink:focus-visible { outline: 2px solid #8fb0ff; outline-offset: -2px; }
.sb-link.is-active {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
}
.sb-link.is-active::before {
  content: "";
  position: absolute;
  left: -14px;
  top: 8px;
  bottom: 8px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: #8fb0ff;
}

.sb-folder.has-active > .sb-folder-btn { color: #fff; }
.sb-chevron { margin-left: auto; font-size: 12px; opacity: 0.6; transition: transform 0.2s ease; }
.sb-folder.is-open .sb-chevron { transform: rotate(180deg); }

.sb-sub {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 1px;
  margin: 2px 0 6px 21px;
  padding-left: 13px;
  border-left: 1px solid rgba(255, 255, 255, 0.1);
}
.sb-sublink {
  display: block;
  padding: 8px 12px;
  border-radius: 8px;
  color: rgba(201, 211, 240, 0.8);
  font-size: 13.5px;
  text-decoration: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}
.sb-sublink:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }
.sb-sublink.is-active { background: rgba(143, 176, 255, 0.16); color: #fff; font-weight: 500; }

@media (max-width: 768px) {
  .sb { transform: translateX(-100%); }
}
@media (prefers-reduced-motion: reduce) {
  .sb-link, .sb-sublink, .sb-chevron { transition: none; }
}
</style>
