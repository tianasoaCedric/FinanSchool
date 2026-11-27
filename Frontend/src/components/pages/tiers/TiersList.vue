<!-- Fichier des tiers : clients, fournisseurs, personnel… avec leur solde. -->
<template>
  <AppLayout :user="user" icon="bi-people" title="Clients et fournisseurs" subtitle="Fiches des tiers, compte collectif, délai de paiement et solde" section="Tiers">
    <template #actions>
      <UiButton icon="bi-hourglass-split" to="/balance-agee">Balance âgée</UiButton>
      <UiButton icon="bi-link-45deg" to="/lettrage">Lettrage</UiButton>
      <UiButton v-if="peutModifier" variant="primary" icon="bi-plus-lg" @click="ouvrir()">Nouveau tiers</UiButton>
    </template>

    <div class="tl">
      <div v-if="erreurs.length" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><p v-for="(e, i) in erreurs" :key="i" class="tl-msg">{{ e }}</p></div>
      </div>

      <section class="fs-card tl-filtres" aria-label="Filtres">
        <div class="tl-onglets" role="tablist" aria-label="Type de tiers">
          <button v-for="o in onglets" :key="o.id" type="button" role="tab" class="tl-onglet"
                  :class="{ 'is-active': filtres.type === o.id }" :aria-selected="filtres.type === o.id" @click="filtres.type = o.id">
            {{ o.label }}
          </button>
        </div>
        <div class="tl-champs">
          <div class="tl-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input v-model="filtres.q" class="fs-input" type="search" placeholder="Nom, code ou NIF" aria-label="Rechercher un tiers" />
          </div>
          <select v-model="filtres.actif" class="fs-select" aria-label="Statut">
            <option value="1">Actifs</option>
            <option value="0">Désactivés</option>
            <option value="tous">Tous</option>
          </select>
        </div>
      </section>

      <section class="fs-card tl-liste">
        <UiSkeleton v-if="chargement && !resultat.data.length" variant="table" :rows="6" :cols="6" />
        <div v-else-if="!resultat.data.length" class="tl-vide">
          <span><i class="bi bi-people"></i></span>
          <p>{{ filtres.q ? 'Aucun tiers ne correspond' : 'Aucun tiers' }}</p>
          <small>Créez vos clients et fournisseurs pour suivre leurs factures, règlements et soldes.</small>
          <UiButton v-if="peutModifier && !filtres.q" variant="primary" icon="bi-plus-lg" @click="ouvrir()">Créer un tiers</UiButton>
        </div>
        <div v-else class="fs-table-wrap" :class="{ 'is-loading': chargement }">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Code</th><th>Nom</th><th>Type</th><th>Compte</th><th>NIF</th>
                <th class="text-right">Délai</th><th class="text-right">Solde</th><th><span class="visually-hidden">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in resultat.data" :key="t.id" :class="{ 'is-inactif': !t.actif }">
                <td class="tl-code">{{ t.code }}</td>
                <td>
                  <router-link :to="`/tiers/${t.id}`" class="tl-nom">{{ t.nom }}</router-link>
                  <small v-if="t.telephone || t.email" class="tl-sous">{{ [t.telephone, t.email].filter(Boolean).join(' · ') }}</small>
                </td>
                <td><UiBadge :tone="TYPES_TIERS[t.type]?.tone" size="sm">{{ t.type_libelle }}</UiBadge><UiBadge v-if="!t.actif" size="sm">Désactivé</UiBadge></td>
                <td class="tl-sous">{{ t.compte ? t.compte.code : '—' }}</td>
                <td class="tl-sous">{{ t.nif || '—' }}</td>
                <td class="text-right num">{{ t.delai_paiement }} j</td>
                <td class="text-right num" :class="{ 'is-neg': t.solde < 0 }">
                  {{ formatMontant(Math.abs(t.solde)) }} <small v-if="t.solde" class="tl-sens">{{ t.solde > 0 ? 'D' : 'C' }}</small>
                </td>
                <td class="tl-actions">
                  <UiButton variant="ghost" size="sm" icon="bi-file-text" :to="`/tiers/${t.id}`" :aria-label="`Relevé de ${t.nom}`" icon-only />
                  <UiButton v-if="peutModifier" variant="ghost" size="sm" icon="bi-pencil" :aria-label="`Modifier ${t.nom}`" icon-only @click="ouvrir(t)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-if="resultat.last_page > 1" :model-value="resultat.current_page" :total-pages="resultat.last_page"
                      :total="resultat.total" :per-page="resultat.per_page" class="tl-pagination" @change="charger" />
      </section>
    </div>

    <TiersFormModal v-model="modale" :tiers="edition" @enregistre="apresEnregistrement" />

    <transition name="fs-fade">
      <div v-if="message" class="tl-toast" role="status"><i class="bi bi-check-circle-fill"></i> {{ message }}</div>
    </transition>
  </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref, watch, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import TiersFormModal from '@/components/molecules/tiers/TiersFormModal.vue';
import { TYPES_TIERS, formatMontant, messagesErreur } from '@/composables/useTiers';

const router = useRouter();
const user = ref(null);
const filtres = reactive({ type: '', q: '', actif: '1' });
const resultat = ref({ data: [] });
const chargement = ref(true);
const erreurs = ref([]);
const modale = ref(false);
const edition = ref(null);
const message = ref('');
let minuteur = null;

const onglets = [{ id: '', label: 'Tous' }, ...Object.entries(TYPES_TIERS).map(([id, t]) => ({ id, label: t.pluriel }))];
const peutModifier = computed(() => !user.value || user.value.role !== 'lecteur');

async function charger(page = 1) {
  chargement.value = true;
  erreurs.value = [];
  try {
    const params = { page, per_page: 25, actif: filtres.actif };
    if (filtres.type) params.type = filtres.type;
    if (filtres.q.trim()) params.q = filtres.q.trim();
    const { data } = await axios.get(`${API_BASE}/tiers`, { params });
    resultat.value = data;
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    chargement.value = false;
  }
}

function ouvrir(t = null) {
  edition.value = t;
  modale.value = true;
}

function apresEnregistrement(msg) {
  message.value = msg;
  setTimeout(() => { message.value = ''; }, 3500);
  charger(resultat.value.current_page || 1);
}

watch(() => filtres.q, () => { clearTimeout(minuteur); minuteur = setTimeout(() => charger(1), 300); });
watch(() => [filtres.type, filtres.actif], () => charger(1));

onMounted(() => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  charger(1);
});
</script>

<style scoped>
.tl { display: flex; flex-direction: column; gap: 16px; }
.tl-msg { margin: 0; }
.tl-filtres { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 14px 20px; }
.tl-onglets { display: inline-flex; gap: 4px; padding: 4px; border-radius: 10px; background: var(--fs-surface-3); }
.tl-onglet {
  padding: 7px 14px; border: 0; border-radius: 7px; background: transparent; cursor: pointer;
  font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2);
}
.tl-onglet.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.tl-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.tl-champs { display: flex; gap: 10px; flex: 1; justify-content: flex-end; min-width: 280px; }
.tl-search { position: relative; flex: 1; max-width: 360px; }
.tl-search .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); }
.tl-search .fs-input { padding-left: 36px; }
.tl-champs .fs-select { width: 140px; }

.tl-liste { padding: 0; overflow: hidden; }
.fs-table-wrap.is-loading { opacity: 0.6; }
.tl-code { font-family: var(--fs-font-mono, monospace); font-size: 13px; color: var(--fs-text-2); }
.tl-nom { font-weight: 600; color: var(--fs-text); text-decoration: none; }
.tl-nom:hover { color: var(--fs-primary-text); text-decoration: underline; }
.tl-sous { display: block; font-size: 12.5px; color: var(--fs-muted); }
.tl-sens { font-size: 11px; color: var(--fs-muted); }
.is-neg { color: var(--fs-text); }
.is-inactif td { opacity: 0.6; }
.tl-actions { white-space: nowrap; text-align: right; width: 90px; }
.num { font-variant-numeric: tabular-nums; }
.tl-pagination { padding: 10px 20px; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.tl-vide { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 56px 24px; text-align: center; }
.tl-vide span {
  display: grid; place-items: center; width: 56px; height: 56px; margin-bottom: 8px; border-radius: 50%;
  background: var(--fs-surface-3); color: var(--fs-muted); font-size: 24px;
}
.tl-vide p { margin: 0; font-weight: 600; color: var(--fs-text); }
.tl-vide small { color: var(--fs-muted); margin-bottom: 8px; }
.tl-toast {
  position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1100;
  display: inline-flex; align-items: center; gap: 8px; padding: 11px 16px; border-radius: 10px;
  background: var(--fs-success); color: #fff; box-shadow: var(--fs-shadow-lg); font-size: var(--fs-text-sm); font-weight: 500;
}
</style>
