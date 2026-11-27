<!-- Centre de notifications : toutes les notifications, filtres et actions groupées. -->
<template>
  <AppLayout
    :user="user"
    icon="bi-bell"
    title="Notifications"
    subtitle="Imports, alertes de clôture et contrôles de l'application"
  >
    <template #actions>
      <UiButton icon="bi-sliders" :to="{ path: '/reglages', query: { section: 'notifications' } }">Régler les alertes</UiButton>
      <UiButton variant="primary" icon="bi-check2-all" :disabled="!compteurs.non_lu" :loading="action === 'lire'" @click="toutLire">
        Tout marquer comme lu
      </UiButton>
    </template>

    <div class="np">
      <!-- Filtres -->
      <section class="fs-card np-filtres" aria-label="Filtres">
        <div class="np-onglets" role="tablist" aria-label="Statut">
          <button v-for="o in ONGLETS" :key="o.id" type="button" role="tab" class="np-onglet"
                  :class="{ 'is-active': filtres.statut === o.id }" :aria-selected="filtres.statut === o.id" @click="filtres.statut = o.id">
            {{ o.label }}
            <span v-if="o.compteur(compteurs) !== null">{{ o.compteur(compteurs) }}</span>
          </button>
        </div>
        <div class="np-champs">
          <div class="np-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input v-model="filtres.q" class="fs-input" type="search" placeholder="Rechercher dans les notifications" aria-label="Rechercher" />
          </div>
          <select v-model="filtres.categorie" class="fs-select" aria-label="Catégorie">
            <option value="">Toutes les catégories</option>
            <option v-for="(c, id) in CATEGORIES" :key="id" :value="id">{{ c.label }}</option>
          </select>
          <select v-model="filtres.niveau" class="fs-select" aria-label="Niveau">
            <option value="">Tous les niveaux</option>
            <option v-for="(nv, id) in NIVEAUX" :key="id" :value="id">{{ nv.label }}</option>
          </select>
        </div>
      </section>

      <div v-if="compteurs.urgent_non_lu" class="np-alerte" role="status">
        <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
        <span>{{ compteurs.urgent_non_lu }} notification(s) urgente(s) non lue(s).</span>
        <UiButton size="sm" variant="ghost" @click="Object.assign(filtres, { niveau: 'urgent', statut: 'non_lu' })">Afficher</UiButton>
      </div>

      <!-- Liste -->
      <section class="fs-card np-liste">
        <header class="np-liste__head">
          <p>{{ resultat.total ?? 0 }} notification(s){{ filtresActifs ? ' pour ces critères' : '' }}</p>
          <div class="np-liste__actions">
            <UiButton v-if="filtresActifs" size="sm" variant="ghost" icon="bi-x-lg" @click="effacerFiltres">Effacer les filtres</UiButton>
            <UiButton size="sm" variant="ghost" icon="bi-trash3" :disabled="!lues" :loading="action === 'purger'" @click="supprimerLues">
              Supprimer les lues
            </UiButton>
          </div>
        </header>

        <UiSkeleton v-if="chargement && !resultat.data.length" :rows="5" class="np-skel" />
        <div v-else-if="!resultat.data.length" class="np-vide">
          <span><i :class="['bi', filtresActifs ? 'bi-funnel' : 'bi-bell-slash']"></i></span>
          <p>{{ filtresActifs ? 'Aucune notification ne correspond' : 'Aucune notification' }}</p>
          <small>{{ filtresActifs ? 'Modifiez ou effacez les filtres.' : 'Les imports, alertes de clôture et contrôles apparaîtront ici.' }}</small>
        </div>
        <div v-else class="np-groupes" :class="{ 'is-loading': chargement }">
          <section v-for="g in groupes" :key="g.titre" class="np-groupe">
            <h3>{{ g.titre }}</h3>
            <NotificationItem v-for="n in g.items" :key="n.id" :n="n" @supprimer="supprimer" />
          </section>
        </div>

        <UiPagination
          v-if="resultat.last_page > 1"
          :model-value="resultat.current_page"
          :total-pages="resultat.last_page"
          :total="resultat.total"
          :per-page="resultat.per_page"
          class="np-pagination"
          @change="charger"
        />
      </section>
    </div>

    <transition name="fs-fade">
      <div v-if="message" class="np-toast" role="status"><i class="bi bi-check-circle-fill"></i> {{ message }}</div>
    </transition>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import NotificationItem from '@/components/molecules/notifications/NotificationItem.vue';
import { CATEGORIES, NIVEAUX, grouper, useCentreNotifications } from '@/composables/useCentreNotifications';

const ONGLETS = [
  { id: 'tous', label: 'Toutes', compteur: c => c.tous },
  { id: 'non_lu', label: 'Non lues', compteur: c => c.non_lu },
  { id: 'lu', label: 'Lues', compteur: c => c.tous - c.non_lu },
];

const router = useRouter();
const centre = useCentreNotifications();
const user = ref(null);
const filtres = reactive({ statut: 'tous', categorie: '', niveau: '', q: '' });
const resultat = ref({ data: [] });
const compteurs = ref({ tous: 0, non_lu: 0, urgent_non_lu: 0 });
const chargement = ref(true);
const action = ref(null);
const message = ref('');
let minuteur = null;
let minuteurMsg = null;

const groupes = computed(() => grouper(resultat.value.data));
const lues = computed(() => compteurs.value.tous - compteurs.value.non_lu);
const filtresActifs = computed(() => filtres.statut !== 'tous' || filtres.categorie || filtres.niveau || filtres.q.trim());

async function charger(page = 1) {
  chargement.value = true;
  try {
    const params = { page, per_page: 20, statut: filtres.statut };
    for (const k of ['categorie', 'niveau']) if (filtres[k]) params[k] = filtres[k];
    if (filtres.q.trim()) params.q = filtres.q.trim();
    const { data } = await axios.get(centre.URL, { params });
    resultat.value = data;
    compteurs.value = data.compteurs;
  } catch {
    resultat.value = { data: [] };
  } finally {
    chargement.value = false;
  }
}

function annoncer(t) {
  message.value = t;
  clearTimeout(minuteurMsg);
  minuteurMsg = setTimeout(() => { message.value = ''; }, 3500);
}

function effacerFiltres() {
  Object.assign(filtres, { statut: 'tous', categorie: '', niveau: '', q: '' });
}

async function toutLire() {
  action.value = 'lire';
  try { annoncer(await centre.toutLire()); await charger(resultat.value.current_page || 1); } finally { action.value = null; }
}
async function supprimerLues() {
  if (!window.confirm(`Supprimer définitivement les ${lues.value} notification(s) lue(s) ?`)) return;
  action.value = 'purger';
  try { annoncer(await centre.supprimerLues()); await charger(1); } finally { action.value = null; }
}
async function supprimer(n) {
  await centre.supprimer(n);
  await charger(resultat.value.current_page || 1);
}

watch(() => filtres.q, () => { clearTimeout(minuteur); minuteur = setTimeout(() => charger(1), 300); });
watch(() => [filtres.statut, filtres.categorie, filtres.niveau], () => charger(1));
// Les actions faites depuis le menu de l'en-tête (ou en temps réel) se reflètent ici
watch(() => centre.etat.compteurs, () => charger(resultat.value.current_page || 1), { deep: true });

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => router.push('/'));
  charger(1);
});
onBeforeUnmount(() => { clearTimeout(minuteur); clearTimeout(minuteurMsg); });
</script>

<style scoped>
.np { display: flex; flex-direction: column; gap: 16px; max-width: 920px; }
.np-filtres { display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; }
.np-onglets { display: inline-flex; gap: 4px; padding: 4px; width: fit-content; border-radius: 10px; background: var(--fs-surface-3); }
.np-onglet {
  display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border: 0; border-radius: 7px;
  background: transparent; cursor: pointer; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2);
}
.np-onglet span { font-size: var(--fs-text-xs); color: var(--fs-muted); font-variant-numeric: tabular-nums; }
.np-onglet.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.np-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.np-champs { display: grid; grid-template-columns: minmax(0, 1fr) 200px 180px; gap: 10px; }
.np-search { position: relative; }
.np-search .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); }
.np-search .fs-input { padding-left: 36px; }

.np-alerte {
  display: flex; align-items: center; gap: 10px; padding: 8px 8px 8px 14px; border-radius: var(--fs-radius-sm);
  background: var(--fs-negative-soft); color: var(--fs-negative); font-size: var(--fs-text-sm); font-weight: 500;
}
.np-alerte span { flex: 1; }

.np-liste { padding: 0 0 8px; }
.np-liste__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--fs-border); }
.np-liste__head p { margin: 0; font-size: var(--fs-text-sm); color: var(--fs-muted); }
.np-liste__actions { display: flex; gap: 6px; }
.np-skel { padding: 16px 20px; }
.np-groupes { padding: 4px 10px; transition: opacity 0.15s ease; }
.np-groupes.is-loading { opacity: 0.6; }
.np-groupe h3 {
  margin: 0; padding: 14px 6px 6px; font-size: var(--fs-text-xs); font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--fs-muted);
}
.np-vide { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 56px 24px; text-align: center; }
.np-vide span {
  display: grid; place-items: center; width: 56px; height: 56px; margin-bottom: 10px; border-radius: 50%;
  background: var(--fs-surface-3); color: var(--fs-muted); font-size: 24px;
}
.np-vide p { margin: 0; font-size: var(--fs-text-md); font-weight: 600; color: var(--fs-text); }
.np-vide small { font-size: var(--fs-text-sm); color: var(--fs-muted); }
.np-pagination { padding: 10px 20px 4px; }

.np-toast {
  position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1100;
  display: inline-flex; align-items: center; gap: 8px; padding: 11px 16px; border-radius: 10px;
  background: var(--fs-success); color: #fff; box-shadow: var(--fs-shadow-lg); font-size: var(--fs-text-sm); font-weight: 500;
}

@media (max-width: 760px) {
  .np-champs { grid-template-columns: 1fr; }
  .np-liste__head { flex-direction: column; align-items: flex-start; }
}
</style>
