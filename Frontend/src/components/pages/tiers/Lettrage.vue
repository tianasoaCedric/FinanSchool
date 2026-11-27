<!-- Lettrage des comptes de tiers : sélectionner des lignes dont débits = crédits. -->
<template>
  <AppLayout :user="user" icon="bi-link-45deg" title="Lettrage" subtitle="Rapprochez factures et règlements d'un même compte et d'un même tiers" section="Tiers" wide>
    <template #actions>
      <UiButton to="/tiers" icon="bi-people">Clients et fournisseurs</UiButton>
      <UiButton v-if="peutModifier" variant="primary" icon="bi-magic" :loading="action === 'auto'" :disabled="!filtres.compte && !filtres.tiers" @click="automatique">
        Lettrage automatique
      </UiButton>
    </template>

    <div class="lt">
      <div v-if="erreurs.length" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><p v-for="(e, i) in erreurs" :key="i" class="lt-msg">{{ e }}</p></div>
      </div>
      <div v-if="succes" class="fs-alert fs-alert--success" role="status"><i class="bi bi-check-circle"></i> <span>{{ succes }}</span></div>

      <section class="fs-card lt-filtres" aria-label="Compte et tiers">
        <div class="fs-field">
          <label class="fs-label" for="lt-compte">Compte</label>
          <select id="lt-compte" v-model="filtres.compte" class="fs-select">
            <option value="">Tous les comptes du tiers</option>
            <option v-for="c in comptes" :key="c.id" :value="c.id">
              {{ c.code }} — {{ c.libelle }}{{ c.non_lettrees ? ` (${c.non_lettrees} à lettrer)` : '' }}
            </option>
          </select>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="lt-tiers">Tiers</label>
          <select id="lt-tiers" v-model="filtres.tiers" class="fs-select">
            <option value="">Tous les tiers</option>
            <option v-for="t in tiers" :key="t.id" :value="t.id">{{ t.nom }} ({{ t.code }})</option>
          </select>
        </div>
        <div class="lt-onglets" role="tablist" aria-label="État">
          <button v-for="o in ETATS" :key="o.id" type="button" role="tab" class="lt-onglet"
                  :class="{ 'is-active': filtres.etat === o.id }" :aria-selected="filtres.etat === o.id" @click="filtres.etat = o.id">{{ o.label }}</button>
        </div>
      </section>

      <section class="fs-card lt-liste">
        <div v-if="!filtres.compte && !filtres.tiers" class="lt-vide">
          <span><i class="bi bi-link-45deg"></i></span>
          <p>Choisissez un compte ou un tiers</p>
          <small>Le lettrage se fait sur un même compte (411…, 401…) et un même tiers.</small>
        </div>
        <UiSkeleton v-else-if="chargement && !lignes.length" variant="table" :rows="6" :cols="7" />
        <div v-else-if="!lignes.length" class="lt-vide">
          <span><i class="bi bi-check2-all"></i></span>
          <p>{{ filtres.etat === 'non_lettre' ? 'Tout est lettré' : 'Aucune ligne' }}</p>
          <small>Aucune ligne validée {{ filtres.etat === 'non_lettre' ? 'à lettrer ' : '' }}pour ces critères.</small>
        </div>
        <div v-else class="fs-table-wrap" :class="{ 'is-loading': chargement }">
          <table class="fs-table">
            <thead>
              <tr>
                <th class="lt-col-check">
                  <input type="checkbox" :checked="toutSelectionne" :indeterminate.prop="selection.size && !toutSelectionne" :disabled="!selectionnables.length" aria-label="Tout sélectionner" @change="toutBasculer" />
                </th>
                <th>Date</th><th>Pièce</th><th>Réf.</th><th>Compte</th><th>Tiers</th><th>Libellé</th>
                <th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-center">Lettrage</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in lignes" :key="l.id" :class="{ 'is-sel': selection.has(l.id) }" @click="!l.id_lettrage && basculer(l.id)">
                <td class="lt-col-check" @click.stop>
                  <input v-if="!l.id_lettrage" type="checkbox" :checked="selection.has(l.id)" :aria-label="`Sélectionner ${l.piece}`" @change="basculer(l.id)" />
                </td>
                <td class="num">{{ formatDate(l.date) }}</td>
                <td><router-link :to="`/ecriture/${l.piece_id}`" class="lt-lien" @click.stop>{{ l.piece }}</router-link></td>
                <td class="lt-sous">{{ l.reference || '' }}</td>
                <td class="lt-sous">{{ l.compte }}</td>
                <td>{{ l.tiers ? l.tiers.nom : '—' }}</td>
                <td>{{ l.libelle }}</td>
                <td class="text-right num">{{ l.debit ? formatMontant(l.debit) : '' }}</td>
                <td class="text-right num">{{ l.credit ? formatMontant(l.credit) : '' }}</td>
                <td class="text-center lt-code" @click.stop>
                  <template v-if="l.lettrage">
                    <UiBadge tone="success" size="sm">{{ l.lettrage }}</UiBadge>
                    <UiButton v-if="peutModifier" variant="ghost" size="sm" icon="bi-x-lg" icon-only :aria-label="`Délettrer ${l.lettrage}`" title="Délettrer" @click="delettrer(l)" />
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="lignes.length >= 2000" class="lt-sous lt-limite">Affichage limité à 2 000 lignes : précisez le compte ou le tiers.</p>
      </section>

      <!-- Barre de sélection -->
      <div v-if="selection.size" class="lt-barre" :class="{ 'is-ok': equilibre }">
        <div class="lt-totaux">
          <div><span class="fs-label">Sélection</span><strong>{{ selection.size }} ligne(s)</strong></div>
          <div><span class="fs-label">Débit</span><strong class="num">{{ formatMontant(totaux.d) }}</strong></div>
          <div><span class="fs-label">Crédit</span><strong class="num">{{ formatMontant(totaux.c) }}</strong></div>
          <div>
            <span class="fs-label">Écart</span>
            <strong class="num">{{ formatMontant(Math.abs(totaux.d - totaux.c)) }}</strong>
          </div>
          <UiBadge :tone="equilibre ? 'success' : 'danger'" dot>{{ motifSelection || 'Équilibré' }}</UiBadge>
        </div>
        <div class="lt-actions">
          <UiButton variant="ghost" @click="selection.clear()">Désélectionner</UiButton>
          <UiButton v-if="peutModifier" variant="primary" icon="bi-link-45deg" :disabled="!!motifSelection" :loading="action === 'lettrer'" @click="lettrer">Lettrer</UiButton>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import { chargerTousLesTiers, formatDate, formatMontant, messagesErreur } from '@/composables/useTiers';

const ETATS = [
  { id: 'non_lettre', label: 'À lettrer' },
  { id: 'lettre', label: 'Lettrées' },
  { id: 'tous', label: 'Toutes' },
];

const route = useRoute();
const router = useRouter();
const user = ref(null);
const comptes = ref([]);
const tiers = ref([]);
const lignes = ref([]);
const chargement = ref(false);
const action = ref(null);
const erreurs = ref([]);
const succes = ref('');
const selection = reactive(new Set());
const filtres = reactive({
  compte: route.query.compte ? Number(route.query.compte) : '',
  tiers: route.query.tiers ? Number(route.query.tiers) : '',
  etat: 'non_lettre',
});

const peutModifier = computed(() => !user.value || user.value.role !== 'lecteur');
const selectionnables = computed(() => lignes.value.filter(l => !l.id_lettrage));
const toutSelectionne = computed(() => selectionnables.value.length > 0 && selectionnables.value.every(l => selection.has(l.id)));
const lignesSel = computed(() => lignes.value.filter(l => selection.has(l.id)));
const totaux = computed(() => lignesSel.value.reduce((s, l) => ({ d: s.d + l.debit, c: s.c + l.credit }), { d: 0, c: 0 }));
const equilibre = computed(() => Math.abs(totaux.value.d - totaux.value.c) < 0.01);

// Mêmes contrôles que le serveur, pour guider avant l'envoi
const motifSelection = computed(() => {
  const sel = lignesSel.value;
  if (sel.length < 2) return 'Au moins deux lignes';
  if (new Set(sel.map(l => l.Id_Sous_compte)).size > 1) return 'Comptes différents';
  if (new Set(sel.map(l => l.tiers?.id || 0)).size > 1) return 'Tiers différents';
  if (!equilibre.value) return 'Débit ≠ crédit';
  return '';
});

function basculer(id) {
  if (selection.has(id)) selection.delete(id); else selection.add(id);
}
function toutBasculer() {
  if (toutSelectionne.value) selection.clear();
  else selectionnables.value.forEach(l => selection.add(l.id));
}

async function chargerReferentiels() {
  const [c, t] = await Promise.all([
    axios.get(`${API_BASE}/lettrage/comptes`).then(r => r.data).catch(() => []),
    chargerTousLesTiers().catch(() => []),
  ]);
  comptes.value = c;
  tiers.value = t;
}

async function charger() {
  selection.clear();
  if (!filtres.compte && !filtres.tiers) { lignes.value = []; return; }
  chargement.value = true;
  erreurs.value = [];
  try {
    const params = { etat: filtres.etat };
    if (filtres.compte) params.Id_Sous_compte = filtres.compte;
    if (filtres.tiers) params.id_tiers = filtres.tiers;
    const { data } = await axios.get(`${API_BASE}/lettrage/lignes`, { params });
    lignes.value = data.lignes;
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    chargement.value = false;
  }
}

async function apres(promesse, nom) {
  action.value = nom;
  erreurs.value = [];
  succes.value = '';
  try {
    const { data } = await promesse();
    succes.value = data.message;
    await Promise.all([charger(), axios.get(`${API_BASE}/lettrage/comptes`).then(r => { comptes.value = r.data; }).catch(() => {})]);
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    action.value = null;
  }
}

const lettrer = () => apres(() => axios.post(`${API_BASE}/lettrage`, { lignes: [...selection] }), 'lettrer');
const automatique = () => apres(() => axios.post(`${API_BASE}/lettrage/auto`, {
  Id_Sous_compte: filtres.compte || null, id_tiers: filtres.tiers || null,
}), 'auto');
function delettrer(l) {
  if (!confirm(`Délettrer le lettrage ${l.lettrage} ? Toutes ses lignes redeviendront à lettrer.`)) return;
  apres(() => axios.delete(`${API_BASE}/lettrage/${l.id_lettrage}`), 'delettrer');
}

watch(() => [filtres.compte, filtres.tiers, filtres.etat], () => {
  router.replace({ query: { ...(filtres.compte ? { compte: filtres.compte } : {}), ...(filtres.tiers ? { tiers: filtres.tiers } : {}) } });
  charger();
});

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  await chargerReferentiels();
  charger();
});
</script>

<style scoped>
.lt { display: flex; flex-direction: column; gap: 16px; }
.lt-msg { margin: 0; }
.lt-filtres { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) auto; align-items: end; gap: 16px; padding: 14px 20px; }
.lt-filtres .fs-field { display: flex; flex-direction: column; gap: 4px; }
.lt-onglets { display: inline-flex; gap: 4px; padding: 4px; border-radius: 10px; background: var(--fs-surface-3); }
.lt-onglet { padding: 7px 14px; border: 0; border-radius: 7px; background: transparent; cursor: pointer; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2); }
.lt-onglet.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.lt-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.lt-liste { padding: 0; overflow: hidden; }
.fs-table-wrap.is-loading { opacity: 0.6; }
.lt-col-check { width: 40px; text-align: center; }
.lt-col-check input { width: 16px; height: 16px; accent-color: var(--fs-primary); cursor: pointer; }
.fs-table tbody tr { cursor: pointer; }
.fs-table tbody tr.is-sel td { background: var(--fs-primary-soft); }
.lt-lien { color: var(--fs-primary-text); font-weight: 600; text-decoration: none; }
.lt-lien:hover { text-decoration: underline; }
.lt-sous { font-size: 12.5px; color: var(--fs-muted); }
.lt-code { white-space: nowrap; }
.lt-limite { padding: 10px 20px; margin: 0; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.lt-vide { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 56px 24px; text-align: center; }
.lt-vide span {
  display: grid; place-items: center; width: 56px; height: 56px; margin-bottom: 8px; border-radius: 50%;
  background: var(--fs-surface-3); color: var(--fs-muted); font-size: 24px;
}
.lt-vide p { margin: 0; font-weight: 600; color: var(--fs-text); }
.lt-vide small { color: var(--fs-muted); }

.lt-barre {
  position: sticky; bottom: 16px; z-index: 20;
  display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
  padding: 14px 20px; background: var(--fs-surface); border: 1px solid var(--fs-border);
  border-left: 4px solid var(--fs-negative); border-radius: var(--fs-radius); box-shadow: var(--fs-shadow-lg);
}
.lt-barre.is-ok { border-left-color: var(--fs-success); }
@media (min-width: 769px) { .lt-barre { margin-right: 64px; } }
.lt-totaux { display: flex; align-items: center; gap: 28px; flex-wrap: wrap; }
.lt-totaux > div { display: flex; flex-direction: column; gap: 2px; }
.lt-totaux strong { font-size: 16px; color: var(--fs-text); }
.lt-actions { display: flex; gap: 10px; }
@media (max-width: 900px) { .lt-filtres { grid-template-columns: 1fr; } }
</style>
