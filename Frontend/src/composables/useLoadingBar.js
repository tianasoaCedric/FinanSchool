import { computed, ref } from 'vue';

// État global des chargements : navigation entre pages (booléen, robuste aux
// redirections) + requêtes API en cours (compteur). La barre fine en haut de
// AppLayout s'affiche tant que l'un des deux est actif.
const navigation = ref(false);
const requetes = ref(0);

export function setNavigation(v) { navigation.value = v; }
export function debutChargement() { requetes.value++; }
export function finChargement() { requetes.value = Math.max(0, requetes.value - 1); }

export function useLoadingBar() {
  return { actif: computed(() => navigation.value || requetes.value > 0) };
}
