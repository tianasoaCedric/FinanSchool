<!-- Relevé de compte d'un tiers : écritures, solde progressif, échéances et lettrage. -->
<template>
  <AppLayout :user="user" icon="bi-file-text" :title="data ? data.tiers.nom : 'Relevé du tiers'" :subtitle="sousTitre" section="Tiers" wide>
    <template #actions>
      <UiButton to="/tiers" icon="bi-arrow-left">Tous les tiers</UiButton>
      <UiButton v-if="data" icon="bi-link-45deg" :to="{ path: '/lettrage', query: { tiers: data.tiers.id, compte: compteLettrage } }">Lettrer</UiButton>
      <UiButton v-if="data && peutModifier" variant="primary" icon="bi-pencil" @click="modale = true">Modifier la fiche</UiButton>
    </template>

    <div class="tr">
      <div v-if="erreurs.length" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><p v-for="(e, i) in erreurs" :key="i" class="tr-msg">{{ e }}</p></div>
      </div>

      <UiSkeleton v-if="chargement && !data" variant="cards" :rows="4" />
      <template v-else-if="data">
        <section class="tr-kpis">
          <div class="fs-card tr-kpi">
            <span class="fs-label">Solde</span>
            <strong class="num">{{ formatMontant(Math.abs(data.totaux.solde)) }}</strong>
            <small>{{ libelleSolde(data.totaux.solde) }}</small>
          </div>
          <div class="fs-card tr-kpi">
            <span class="fs-label">Non lettré</span>
            <strong class="num">{{ formatMontant(Math.abs(data.totaux.non_lettre)) }}</strong>
            <small>{{ nbNonLettrees }} ligne(s) à lettrer</small>
          </div>
          <div class="fs-card tr-kpi" :class="{ 'is-alerte': echues.montant > 0 }">
            <span class="fs-label">Échu à ce jour</span>
            <strong class="num">{{ formatMontant(echues.montant) }}</strong>
            <small>{{ echues.nombre }} facture(s) en retard</small>
          </div>
          <div class="fs-card tr-kpi tr-fiche">
            <span class="fs-label">{{ data.tiers.type_libelle }} · {{ data.tiers.code }}</span>
            <span>{{ data.tiers.compte ? `${data.tiers.compte.code} — ${data.tiers.compte.libelle}` : 'Pas de compte collectif' }}</span>
            <small>Délai {{ data.tiers.delai_paiement }} j<span v-if="data.tiers.nif"> · NIF {{ data.tiers.nif }}</span><span v-if="data.tiers.telephone"> · {{ data.tiers.telephone }}</span></small>
          </div>
        </section>

        <section class="fs-card tr-filtres" aria-label="Filtres">
          <div class="fs-field"><label class="fs-label" for="tr-debut">Du</label><input id="tr-debut" v-model="filtres.date_debut" type="date" class="fs-input" /></div>
          <div class="fs-field"><label class="fs-label" for="tr-fin">Au</label><input id="tr-fin" v-model="filtres.date_fin" type="date" class="fs-input" /></div>
          <label class="tr-switch"><input v-model="filtres.non_lettrees" type="checkbox" /> Non lettrées uniquement</label>
          <label class="tr-switch"><input v-model="filtres.brouillons" type="checkbox" /> Inclure les brouillons</label>
        </section>

        <section class="fs-card tr-liste">
          <div v-if="!data.lignes.length" class="tr-vide">Aucune écriture pour ce tiers sur la période.</div>
          <div v-else class="fs-table-wrap" :class="{ 'is-loading': chargement }">
            <table class="fs-table">
              <thead>
                <tr>
                  <th>Date</th><th>Pièce</th><th>Réf.</th><th>Compte</th><th>Libellé</th><th>Échéance</th>
                  <th class="text-right">Débit</th><th class="text-right">Crédit</th><th class="text-right">Solde</th><th class="text-center">Lettr.</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in data.lignes" :key="l.id" :class="{ 'is-brouillon': l.statut !== 'valide' }">
                  <td class="num">{{ formatDate(l.date) }}</td>
                  <td><router-link :to="`/ecriture/${l.piece_id}`" class="tr-lien">{{ l.piece }}</router-link></td>
                  <td class="tr-sous">{{ l.reference || '' }}</td>
                  <td class="tr-sous">{{ l.compte }}</td>
                  <td>{{ l.libelle }} <UiBadge v-if="l.statut !== 'valide'" tone="warning" size="sm">Brouillon</UiBadge></td>
                  <td class="num" :class="{ 'is-retard': enRetard(l) }">{{ formatDate(l.echeance) }}</td>
                  <td class="text-right num">{{ l.debit ? formatMontant(l.debit) : '' }}</td>
                  <td class="text-right num">{{ l.credit ? formatMontant(l.credit) : '' }}</td>
                  <td class="text-right num">{{ formatMontant(Math.abs(l.solde)) }} <small class="tr-sous">{{ l.solde > 0 ? 'D' : l.solde < 0 ? 'C' : '' }}</small></td>
                  <td class="text-center"><UiBadge v-if="l.lettrage" tone="success" size="sm">{{ l.lettrage }}</UiBadge></td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="6"><strong>Totaux</strong></td>
                  <td class="text-right num"><strong>{{ formatMontant(data.totaux.debit) }}</strong></td>
                  <td class="text-right num"><strong>{{ formatMontant(data.totaux.credit) }}</strong></td>
                  <td class="text-right num"><strong>{{ formatMontant(Math.abs(data.totaux.solde)) }}</strong></td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>
      </template>
    </div>

    <TiersFormModal v-if="data" v-model="modale" :tiers="data.tiers" @enregistre="charger" />
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import TiersFormModal from '@/components/molecules/tiers/TiersFormModal.vue';
import { formatDate, formatMontant, messagesErreur } from '@/composables/useTiers';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const data = ref(null);
const chargement = ref(true);
const erreurs = ref([]);
const modale = ref(false);
const filtres = reactive({ date_debut: '', date_fin: '', non_lettrees: false, brouillons: false });

const aujourdhui = new Date().toISOString().slice(0, 10);
const peutModifier = computed(() => !user.value || user.value.role !== 'lecteur');
const sousTitre = computed(() => data.value
  ? `Relevé de compte${data.value.periode.debut ? ` depuis le ${formatDate(data.value.periode.debut)}` : ''}`
  : '');
const nbNonLettrees = computed(() => data.value?.lignes.filter(l => !l.lettrage && l.statut === 'valide').length || 0);
const compteLettrage = computed(() => data.value?.tiers.compte?.id || undefined);
const enRetard = l => l.echeance && l.echeance < aujourdhui;
const echues = computed(() => {
  const lignes = (data.value?.lignes || []).filter(enRetard);
  return { nombre: lignes.length, montant: lignes.reduce((s, l) => s + l.debit + l.credit, 0) };
});

function libelleSolde(s) {
  if (!s) return 'Soldé';
  const client = data.value?.tiers.type !== 'fournisseur';
  if (s > 0) return client ? 'Le tiers vous doit (débiteur)' : 'Solde débiteur (avance versée)';
  return client ? 'Solde créditeur (avance reçue)' : 'Vous devez au tiers (créditeur)';
}

async function charger() {
  chargement.value = true;
  erreurs.value = [];
  try {
    const params = {};
    if (filtres.date_debut) params.date_debut = filtres.date_debut;
    if (filtres.date_fin) params.date_fin = filtres.date_fin;
    if (filtres.non_lettrees) params.non_lettrees = 1;
    if (filtres.brouillons) params.brouillons = 1;
    const r = await axios.get(`${API_BASE}/tiers/${route.params.id}/releve`, { params });
    data.value = r.data;
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    chargement.value = false;
  }
}

watch(() => ({ ...filtres }), charger, { deep: true });
watch(() => route.params.id, id => { if (id) charger(); });

onMounted(() => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  charger();
});
</script>

<style scoped>
.tr { display: flex; flex-direction: column; gap: 16px; }
.tr-msg { margin: 0; }
.tr-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 1.4fr); gap: 16px; }
.tr-kpi { display: flex; flex-direction: column; gap: 4px; padding: 16px 20px; }
.tr-kpi strong { font-size: 22px; color: var(--fs-text); }
.tr-kpi small { font-size: 12.5px; color: var(--fs-muted); }
.tr-kpi.is-alerte { border-left: 4px solid var(--fs-negative); }
.tr-kpi.is-alerte strong { color: var(--fs-negative); }
.tr-fiche > span:nth-child(2) { font-size: 14px; font-weight: 500; color: var(--fs-text); }
.tr-filtres { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
.tr-filtres .fs-field { display: flex; flex-direction: column; gap: 4px; }
.tr-switch { display: inline-flex; align-items: center; gap: 8px; height: 40px; font-size: 13.5px; color: var(--fs-text-2); cursor: pointer; }
.tr-switch input { width: 16px; height: 16px; accent-color: var(--fs-primary); }
.tr-liste { padding: 0; overflow: hidden; }
.fs-table-wrap.is-loading { opacity: 0.6; }
.tr-lien { color: var(--fs-primary-text); font-weight: 600; text-decoration: none; }
.tr-lien:hover { text-decoration: underline; }
.tr-sous { font-size: 12.5px; color: var(--fs-muted); }
.is-retard { color: var(--fs-negative); font-weight: 600; }
.is-brouillon td { font-style: italic; color: var(--fs-text-2); }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
tfoot td { padding: 11px 16px; border-top: 2px solid var(--fs-border-strong); background: var(--fs-surface-2); }
.tr-vide { padding: 40px; text-align: center; color: var(--fs-muted); }
@media (max-width: 1100px) { .tr-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
