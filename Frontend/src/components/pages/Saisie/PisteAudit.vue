<template>
  <AppLayout
    :user="user"
    icon="bi-clock-history"
    title="Piste d'audit"
    subtitle="Historique des actions comptables : qui a fait quoi, quand, avec l'état avant et après"
    section="Saisie"
    wide
  >
    <!-- Filtres -->
    <section class="fs-card pa-filtres" aria-label="Filtres">
      <div class="pa-champs">
        <div class="pa-recherche">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input v-model="filtres.q" type="search" class="fs-input" placeholder="Pièce, compte, utilisateur…" aria-label="Rechercher" />
        </div>
        <select v-model="filtres.action" class="fs-select" aria-label="Action">
          <option value="">Toutes les actions</option>
          <option v-for="a in options.actions" :key="a.code" :value="a.code">{{ a.libelle }}</option>
        </select>
        <select v-model="filtres.user_id" class="fs-select" aria-label="Utilisateur">
          <option value="">Tous les utilisateurs</option>
          <option v-for="u in options.utilisateurs" :key="u.id" :value="u.id">{{ u.nom }}</option>
        </select>
        <input v-model="filtres.date_debut" type="date" class="fs-input" aria-label="Du" />
        <input v-model="filtres.date_fin" type="date" class="fs-input" aria-label="Au" />
      </div>
      <p v-if="filtres.objet_id" class="pa-objet">
        Historique de l'objet n°{{ filtres.objet_id }}
        <UiButton variant="ghost" size="sm" icon="bi-x-lg" @click="toutAfficher">Tout afficher</UiButton>
      </p>
    </section>

    <!-- Tableau -->
    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">{{ resultat.total ?? 0 }} action(s)</h2>
        <span class="fs-table-card__hint">Lecture seule — cliquez sur une ligne pour voir le détail</span>
      </div>
      <div v-if="chargement" class="fs-skeleton"><div v-for="n in 8" :key="n" class="fs-skeleton__row"></div></div>
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>Date et heure</th>
              <th>Utilisateur</th>
              <th>Action</th>
              <th>Objet</th>
              <th class="pa-col-chevron"><span class="sr-only">Détail</span></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="e in resultat.data" :key="e.id">
              <tr
                class="detail-row pa-ligne" tabindex="0" :aria-expanded="ouvert === e.id"
                @click="basculer(e.id)" @keydown.enter="basculer(e.id)"
              >
                <td class="num pa-date">{{ formatDateHeure(e.date) }}</td>
                <td>{{ e.utilisateur }}</td>
                <td><UiBadge :tone="ton(e.action)" dot>{{ e.action_libelle }}</UiBadge></td>
                <td class="pa-objet-cell">
                  <router-link v-if="lienObjet(e)" :to="lienObjet(e)" @click.stop>{{ e.objet }}</router-link>
                  <span v-else>{{ e.objet || '—' }}</span>
                </td>
                <td class="pa-col-chevron"><i class="bi" :class="ouvert === e.id ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i></td>
              </tr>
              <tr v-if="ouvert === e.id" class="pa-detail">
                <td colspan="5">
                  <div class="pa-detail__grille" :class="{ 'pa-detail__grille--double': e.avant && e.apres }">
                    <div v-for="bloc in blocs(e)" :key="bloc.titre" class="pa-bloc">
                      <h3 class="pa-bloc__titre">{{ bloc.titre }}</h3>
                      <dl class="pa-champs-liste">
                        <template v-for="(valeur, cle) in bloc.champs" :key="cle">
                          <dt>{{ libelleChamp(cle) }}</dt>
                          <dd :class="{ 'is-change': bloc.changes?.has(cle) }">{{ formatValeur(valeur) }}</dd>
                        </template>
                      </dl>
                      <table v-if="bloc.lignes?.length" class="pa-mini-table">
                        <thead>
                          <tr><th>Compte</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th></tr>
                        </thead>
                        <tbody>
                          <tr v-for="(l, i) in bloc.lignes" :key="i">
                            <td class="num">{{ l.compte }}</td>
                            <td>{{ l.libelle }}</td>
                            <td class="text-right num">{{ formatMontant(l.debit) }}</td>
                            <td class="text-right num">{{ formatMontant(l.credit) }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <p v-if="e.ip" class="pa-ip">Adresse IP : {{ e.ip }}</p>
                </td>
              </tr>
            </template>
            <tr v-if="!resultat.data?.length">
              <td colspan="5" class="fs-empty">Aucune action ne correspond aux filtres</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="resultat.last_page > 1" class="pa-pagination">
        <UiButton variant="ghost" size="sm" icon="bi-chevron-left" :disabled="resultat.current_page <= 1" @click="charger(resultat.current_page - 1)">Précédent</UiButton>
        <span>Page {{ resultat.current_page }} / {{ resultat.last_page }}</span>
        <UiButton variant="ghost" size="sm" icon-right="bi-chevron-right" :disabled="resultat.current_page >= resultat.last_page" @click="charger(resultat.current_page + 1)">Suivant</UiButton>
      </div>
    </section>
  </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const chargement = ref(true);
const resultat = ref({ data: [] });
const options = ref({ actions: [], utilisateurs: [] });
const ouvert = ref(null);

const filtres = reactive({
  q: '', action: '', user_id: '', date_debut: '', date_fin: '',
  objet_type: route.query.objet_type || '',
  objet_id: route.query.objet_id || '',
});

const LIBELLES_CHAMPS = {
  numero: 'N° pièce', date: 'Date', journal: 'Journal', libelle: 'Libellé', reference: 'Référence',
  statut: 'Statut', total_debit: 'Total débit', total_credit: 'Total crédit',
  piece_origine: "Pièce d'origine", type: 'Type', motif: 'Motif', exercice: 'Exercice',
  fichier: 'Fichier', pieces: 'Pièces', lignes: 'Lignes', resultat: 'Résultat',
  resultat_cloture: 'Résultat de clôture', piece_cloture: 'Pièce de clôture',
  piece_a_nouveaux: 'Pièce d\'à-nouveaux', regularisations_extournees: 'Régularisations extournées',
  exercice_ouvert: 'Exercice ouvert', pieces_supprimees: 'Pièces supprimées', mois: 'Mois',
  cloture_jusqu_au: "Clôturé jusqu'au", immobilisations: 'Immobilisations', repartition: 'Répartition',
  Code: 'Code', Code_rubrique: 'Code', Code_compte: 'Code', Code_sous_compte: 'Code', Libelle: 'Libellé',
};

const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDateHeure = d => (d ? new Date(d).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'medium' }) : '');
const libelleChamp = cle => LIBELLES_CHAMPS[cle] || cle;

function formatValeur(v) {
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'boolean') return v ? 'Oui' : 'Non';
  if (typeof v === 'number') return Number.isInteger(v) ? String(v) : formatMontant(v);
  if (Array.isArray(v)) {
    return v.map(x => (x && typeof x === 'object') ? Object.values(x).join(' : ') : x).join(', ') || '—';
  }
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

function ton(action) {
  if (action.endsWith('suppression') || action === 'exercice.annulation_cloture') return 'danger';
  if (action.endsWith('validation') || action.includes('cloture') || action === 'resultat.affectation') return 'success';
  if (action.endsWith('modification') || action === 'piece.contre_passation') return 'warning';
  return 'info';
}

function lienObjet(e) {
  if (e.objet_type === 'piece' && e.objet_id && !e.action.endsWith('suppression')) return `/ecriture/${e.objet_id}`;
  return null;
}

/** Blocs affichés dans le détail : avant / après (avec les champs modifiés) et informations. */
function blocs(e) {
  const separer = etat => {
    if (!etat) return null;
    const { lignes, ...champs } = etat;
    return { champs, lignes: Array.isArray(lignes) ? lignes : null };
  };
  const avant = separer(e.avant);
  const apres = separer(e.apres);
  const changes = new Set();
  if (avant && apres) {
    for (const k of new Set([...Object.keys(avant.champs), ...Object.keys(apres.champs)])) {
      if (JSON.stringify(avant.champs[k]) !== JSON.stringify(apres.champs[k])) changes.add(k);
    }
  }
  const liste = [];
  if (avant) liste.push({ titre: 'Avant', ...avant, changes });
  if (apres) liste.push({ titre: avant ? 'Après' : 'État enregistré', ...apres, changes });
  if (e.details) liste.push({ titre: 'Informations', champs: e.details });
  if (!liste.length) liste.push({ titre: 'Informations', champs: { objet: e.objet } });
  return liste;
}

function basculer(id) { ouvert.value = ouvert.value === id ? null : id; }

async function charger(page = 1) {
  chargement.value = true;
  ouvert.value = null;
  try {
    const params = { page, per_page: 50 };
    for (const k of ['q', 'action', 'user_id', 'date_debut', 'date_fin', 'objet_type', 'objet_id']) {
      if (filtres[k]) params[k] = filtres[k];
    }
    const { data } = await axios.get(`${API_BASE}/audit`, { params });
    resultat.value = data;
  } catch {
    resultat.value = { data: [] };
  } finally {
    chargement.value = false;
  }
}

function toutAfficher() {
  filtres.objet_type = '';
  filtres.objet_id = '';
  router.replace({ query: {} });
}

let minuteur = null;
watch(() => filtres.q, () => { clearTimeout(minuteur); minuteur = setTimeout(() => charger(1), 300); });
watch(() => [filtres.action, filtres.user_id, filtres.date_debut, filtres.date_fin, filtres.objet_type, filtres.objet_id], () => charger(1));

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  axios.get(`${API_BASE}/audit/options`).then(r => { options.value = r.data; }).catch(() => {});
  charger(1);
});
</script>

<style scoped>
.pa-filtres { display: flex; flex-direction: column; gap: 12px; padding: 16px 20px; margin-bottom: 20px; }
.pa-champs { display: grid; grid-template-columns: minmax(220px, 2fr) minmax(180px, 1fr) minmax(160px, 1fr) 150px 150px; gap: 12px; }
.pa-recherche { position: relative; }
.pa-recherche .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); pointer-events: none; }
.pa-recherche .fs-input { padding-left: 36px; }
.pa-objet { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 13px; color: var(--fs-text-2); }

.pa-ligne { cursor: pointer; }
.pa-ligne:focus-visible td { background: var(--fs-row-hover); outline: none; }
.pa-date { white-space: nowrap; }
.pa-objet-cell a { color: var(--fs-primary); text-decoration: none; font-weight: 500; }
.pa-objet-cell a:hover { text-decoration: underline; }
.pa-col-chevron { width: 40px; text-align: center; color: var(--fs-muted); }

.pa-detail > td { background: var(--fs-surface-2, var(--fs-surface-3)); padding: 16px 20px; }
.pa-detail__grille { display: grid; grid-template-columns: 1fr; gap: 16px; }
.pa-detail__grille--double { grid-template-columns: 1fr 1fr; }
.pa-bloc { background: var(--fs-surface); border: 1px solid var(--fs-border); border-radius: 10px; padding: 12px 14px; }
.pa-bloc__titre { margin: 0 0 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--fs-muted); }
.pa-champs-liste { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; font-size: 13px; }
.pa-champs-liste dt { color: var(--fs-muted); }
.pa-champs-liste dd { margin: 0; color: var(--fs-text); word-break: break-word; }
.pa-champs-liste dd.is-change { font-weight: 600; color: var(--fs-warning, #b45309); }
.pa-mini-table { width: 100%; margin-top: 10px; border-collapse: collapse; font-size: 12.5px; }
.pa-mini-table th { text-align: left; font-weight: 600; color: var(--fs-muted); border-bottom: 1px solid var(--fs-border); padding: 4px 6px; }
.pa-mini-table td { padding: 4px 6px; border-bottom: 1px solid var(--fs-border); }
.pa-ip { margin: 10px 0 0; font-size: 12px; color: var(--fs-muted); }

.pa-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 12px; border-top: 1px solid var(--fs-border); font-size: 13px; color: var(--fs-muted); }
.num { font-variant-numeric: tabular-nums; }
.text-right { text-align: right; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
@media (max-width: 1000px) {
  .pa-champs { grid-template-columns: 1fr 1fr; }
  .pa-detail__grille--double { grid-template-columns: 1fr; }
}
</style>
