<!--
  Sélecteur de compte avec recherche (code ou libellé, sans accents).
  Clavier : ↑ ↓ pour parcourir, Entrée pour choisir, Échap pour fermer.
  Émet `choisi` après une sélection, pour passer au champ suivant.
-->
<template>
  <div class="cs" :class="{ 'is-open': ouvert }">
    <input
      ref="champ"
      v-model="recherche"
      type="text"
      class="fs-input cs-input"
      :class="{ 'cs-input--vide': !modelValue, 'is-invalid': invalide }"
      :placeholder="placeholder"
      :disabled="disabled"
      role="combobox"
      autocomplete="off"
      :aria-expanded="ouvert"
      :aria-label="ariaLabel"
      @focus="onFocus"
      @input="ouvert = true; actif = 0"
      @keydown.down.prevent="deplacer(1)"
      @keydown.up.prevent="deplacer(-1)"
      @keydown.enter.prevent="choisir(resultats[actif])"
      @keydown.esc="fermer"
      @blur="onBlur"
    />
    <!-- Rendue dans <body> en position fixe : ni l'en-tête du tableau ni la
         barre des totaux (collants) ne peuvent la recouvrir. -->
    <Teleport to="body">
      <!-- Reprend les jetons de thème (définis sur .fs-page) hors de la page -->
      <div v-if="ouvert" class="fs-page cs-portail" :data-theme="theme">
      <ul v-if="ouvert && resultats.length" class="cs-liste" :style="position" role="listbox">
        <li
          v-for="(c, i) in resultats"
          :key="c.Id_Sous_compte"
          role="option"
          :aria-selected="i === actif"
          class="cs-option"
          :class="{ 'is-actif': i === actif }"
          @mousedown.prevent="choisir(c)"
          @mouseenter="actif = i"
        >
          <span class="cs-code">{{ c.Code_sous_compte }}</span>
          <span class="cs-libelle">{{ c.Libelle }}</span>
        </li>
      </ul>
      <div v-else-if="ouvert && recherche" class="cs-liste cs-vide" :style="position">Aucun compte trouvé</div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: [Number, String, null], default: null },
  comptes: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Code ou libellé du compte' },
  ariaLabel: { type: String, default: 'Compte' },
  disabled: { type: Boolean, default: false },
  invalide: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'choisi']);

const champ = ref(null);
const recherche = ref('');
const ouvert = ref(false);
const actif = ref(0);
const position = ref({});
const theme = ref('light');

// Hauteur de la liste (8 options) + barre des totaux collée en bas de la saisie :
// s'il n'y a pas cette place sous le champ, la liste s'ouvre vers le haut.
const PLACE_NECESSAIRE = 420;
function placer() {
  const r = champ.value?.getBoundingClientRect();
  if (!r) return;
  theme.value = champ.value.closest('.fs-page')?.dataset.theme || 'light';
  const dessous = window.innerHeight - r.bottom;
  const haut = dessous < PLACE_NECESSAIRE && r.top > dessous;
  position.value = {
    left: `${r.left}px`,
    minWidth: `${r.width}px`,
    // Toujours positionnée par `top` (vers le haut : décalée de sa propre hauteur)
    ...(haut ? { top: `${r.top - 4}px`, transform: 'translateY(-100%)' } : { top: `${r.bottom + 4}px` }),
  };
}
// Suit le champ pendant le défilement / redimensionnement
watch(ouvert, o => {
  if (o) {
    placer();
    window.addEventListener('scroll', placer, true);
    window.addEventListener('resize', placer);
  } else {
    window.removeEventListener('scroll', placer, true);
    window.removeEventListener('resize', placer);
  }
});
onBeforeUnmount(() => {
  window.removeEventListener('scroll', placer, true);
  window.removeEventListener('resize', placer);
});

const normaliser = s => (s || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const libelleDe = c => (c ? `${c.Code_sous_compte} — ${c.Libelle}` : '');
const selection = computed(() => props.comptes.find(c => c.Id_Sous_compte == props.modelValue));

// Affiche le compte choisi quand on ne tape pas
watch(selection, c => { if (!ouvert.value) recherche.value = libelleDe(c); }, { immediate: true });

const resultats = computed(() => {
  const q = normaliser(recherche.value).trim();
  if (!q || recherche.value === libelleDe(selection.value)) return props.comptes.slice(0, 8);
  const parCode = props.comptes.filter(c => c.Code_sous_compte.startsWith(q));
  const parLibelle = props.comptes.filter(c => !c.Code_sous_compte.startsWith(q) && normaliser(c.Libelle).includes(q));
  return [...parCode, ...parLibelle].slice(0, 8);
});

function onFocus(e) {
  ouvert.value = true;
  actif.value = 0;
  e.target.select();
}
function deplacer(pas) {
  if (!ouvert.value) { ouvert.value = true; return; }
  const n = resultats.value.length;
  if (n) actif.value = (actif.value + pas + n) % n;
}
function choisir(c) {
  if (!c) return;
  emit('update:modelValue', c.Id_Sous_compte);
  recherche.value = libelleDe(c);
  ouvert.value = false;
  emit('choisi', c);
}
function fermer() {
  ouvert.value = false;
  recherche.value = libelleDe(selection.value);
}
function onBlur() {
  // Un code exact tapé sans valider avec Entrée est accepté
  const q = recherche.value.trim();
  const exact = props.comptes.find(c => c.Code_sous_compte === q);
  if (exact && exact.Id_Sous_compte != props.modelValue) choisir(exact);
  else fermer();
}

defineExpose({ focus: () => champ.value?.focus() });
</script>

<style scoped>
.cs { position: relative; }
.cs-input { padding-right: 12px; height: 36px; font-size: 13.5px; }
.cs-input--vide { color: var(--fs-text); }
.cs-input.is-invalid { border-color: var(--fs-negative); }
.cs-liste {
  position: fixed;
  z-index: 1100;
  width: max-content;
  max-width: 460px;
  margin: 0;
  padding: 4px;
  list-style: none;
  background: var(--fs-surface);
  border: 1px solid var(--fs-border);
  border-radius: 10px;
  box-shadow: var(--fs-shadow-lg);
}
.fs-page.cs-portail { min-height: 0; background: none; }
.cs-option {
  display: flex;
  gap: 10px;
  align-items: baseline;
  padding: 7px 10px;
  border-radius: 7px;
  cursor: pointer;
  font-size: 13.5px;
  color: var(--fs-text-2);
}
.cs-option.is-actif { background: var(--fs-primary-soft); color: var(--fs-text); }
.cs-code { font-weight: 600; font-variant-numeric: tabular-nums; color: var(--fs-primary-text); min-width: 64px; }
.cs-libelle { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cs-vide { padding: 10px 12px; font-size: 13px; color: var(--fs-muted); }
</style>
