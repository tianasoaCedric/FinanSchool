import { computed, reactive } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';

/**
 * Centre de notifications de l'interface comptable (menu de l'en-tête et
 * page /notifications). État partagé : un seul chargement, une seule écoute
 * temps réel (Reverb, canal « notifications ») et un rafraîchissement de
 * secours toutes les 60 s.
 *
 * Le module d'analyse garde son propre store (stores/notificationStore.js).
 */
const URL = `${API_BASE}/centre-notifications`;

export const CATEGORIES = {
  import: { label: 'Import', icon: 'bi-cloud-arrow-up' },
  cloture: { label: 'Clôture', icon: 'bi-calendar-check' },
  analyse: { label: 'Analytique', icon: 'bi-diagram-3' },
  systeme: { label: 'Système', icon: 'bi-gear' },
};
export const NIVEAUX = {
  info: { label: 'Information', ton: 'info' },
  avertissement: { label: 'Avertissement', ton: 'warning' },
  urgent: { label: 'Urgent', ton: 'danger' },
};

const etat = reactive({
  recentes: [],
  compteurs: { tous: 0, non_lu: 0, urgent_non_lu: 0 },
  charge: false,
  nouvelles: 0,          // arrivées depuis l'ouverture du menu (animation de la cloche)
});
let abonnes = 0;
let minuteur = null;
let ecoute = false;

async function rafraichir() {
  try {
    const { data } = await axios.get(URL, { params: { per_page: 15 } });
    const avant = etat.compteurs.non_lu;
    etat.recentes = data.data;
    etat.compteurs = data.compteurs;
    if (etat.charge && data.compteurs.non_lu > avant) etat.nouvelles += data.compteurs.non_lu - avant;
    etat.charge = true;
  } catch {
    /* hors ligne / session expirée : on réessaiera */
  }
}

function demarrer() {
  abonnes += 1;
  if (abonnes > 1) return;
  rafraichir();
  minuteur = setInterval(() => { if (!document.hidden) rafraichir(); }, 60000);
  if (!ecoute && window.Echo) {
    window.Echo.channel('notifications').listen('NotificationCreee', () => rafraichir());
    ecoute = true;
  }
}
function arreter() {
  abonnes = Math.max(0, abonnes - 1);
  if (abonnes === 0) { clearInterval(minuteur); minuteur = null; }
}

/** Met à jour une notification dans la liste locale (optimiste). */
function majLocale(id, modif) {
  const n = etat.recentes.find(x => x.id === id);
  if (!n) return;
  if (modif.statut && modif.statut !== n.statut) {
    etat.compteurs.non_lu += modif.statut === 'non_lu' ? 1 : -1;
  }
  Object.assign(n, modif);
}

async function marquerLue(n) {
  if (n.statut === 'lu') return;
  majLocale(n.id, { statut: 'lu' });
  n.statut = 'lu';
  await axios.patch(`${URL}/${n.id}/lue`).catch(rafraichir);
}
async function marquerNonLue(n) {
  majLocale(n.id, { statut: 'non_lu' });
  n.statut = 'non_lu';
  await axios.patch(`${URL}/${n.id}/non-lue`).catch(rafraichir);
}
async function toutLire() {
  const { data } = await axios.post(`${URL}/tout-lire`);
  await rafraichir();
  return data.message;
}
async function supprimer(n) {
  etat.recentes = etat.recentes.filter(x => x.id !== n.id);
  await axios.delete(`${URL}/${n.id}`);
  await rafraichir();
}
async function supprimerLues() {
  const { data } = await axios.delete(`${URL}/lues`);
  await rafraichir();
  return data.message;
}

/** « il y a 5 min », « hier à 14:02 », « 12 nov. » */
export function dateRelative(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const s = (Date.now() - d.getTime()) / 1000;
  if (s < 60) return "à l'instant";
  if (s < 3600) return `il y a ${Math.floor(s / 60)} min`;
  const heure = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  const jour = groupeJour(iso);
  if (jour === "Aujourd'hui") return `il y a ${Math.floor(s / 3600)} h`;
  if (jour === 'Hier') return `hier à ${heure}`;
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}

/** Regroupement par jour : Aujourd'hui, Hier, Cette semaine, Plus ancien. */
export function groupeJour(iso) {
  const d = new Date(iso);
  const debut = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const ecart = Math.round((debut(new Date()) - debut(d)) / 86400000);
  if (ecart <= 0) return "Aujourd'hui";
  if (ecart === 1) return 'Hier';
  if (ecart < 7) return 'Cette semaine';
  return 'Plus ancien';
}

export function grouper(liste) {
  const groupes = [];
  for (const n of liste) {
    const g = groupeJour(n.date);
    if (!groupes.length || groupes.at(-1).titre !== g) groupes.push({ titre: g, items: [] });
    groupes.at(-1).items.push(n);
  }
  return groupes;
}

export function useCentreNotifications() {
  return {
    etat,
    nonLues: computed(() => etat.compteurs.non_lu),
    urgentes: computed(() => etat.compteurs.urgent_non_lu),
    demarrer, arreter, rafraichir,
    marquerLue, marquerNonLue, toutLire, supprimer, supprimerLues,
    URL,
  };
}
