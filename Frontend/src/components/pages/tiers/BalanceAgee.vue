<!-- Balance âgée : créances clients ou dettes fournisseurs par ancienneté de l'échéance. -->
<template>
  <AppLayout :user="user" icon="bi-hourglass-split" title="Balance âgée" :subtitle="sousTitre" section="Tiers" wide>
    <template #actions>
      <UiButton to="/tiers" icon="bi-people">Clients et fournisseurs</UiButton>
      <UiButton icon="bi-filetype-csv" :disabled="!data?.lignes.length" @click="exporter">Exporter (CSV)</UiButton>
    </template>

    <div class="ba">
      <div v-if="erreurs.length" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i><div><p v-for="(e, i) in erreurs" :key="i" class="ba-msg">{{ e }}</p></div>
      </div>

      <section class="fs-card ba-filtres" aria-label="Paramètres">
        <div class="ba-onglets" role="tablist" aria-label="Type">
          <button v-for="o in ONGLETS" :key="o.id" type="button" role="tab" class="ba-onglet"
                  :class="{ 'is-active': filtres.type === o.id }" :aria-selected="filtres.type === o.id" @click="filtres.type = o.id">{{ o.label }}</button>
        </div>
        <div class="fs-field ba-date">
          <label class="fs-label" for="ba-date">Situation au</label>
          <input id="ba-date" v-model="filtres.date" type="date" class="fs-input" />
        </div>
      </section>

      <section v-if="data" class="ba-tranches">
        <div v-for="t in TRANCHES" :key="t.id" class="fs-card ba-tranche" :class="`is-${t.id}`">
          <span class="fs-label">{{ t.label }}</span>
          <strong class="num">{{ formatMontant(data.totaux[t.id]) }}</strong>
          <div class="ba-jauge" aria-hidden="true"><span :style="{ width: part(data.totaux[t.id]) }"></span></div>
          <small>{{ part(data.totaux[t.id]) }} du total</small>
        </div>
      </section>

      <section class="fs-card ba-liste">
        <UiSkeleton v-if="chargement && !data" variant="table" :rows="6" :cols="8" />
        <div v-else-if="!data?.lignes.length" class="ba-vide">
          <span><i class="bi bi-emoji-smile"></i></span>
          <p>Aucun {{ filtres.type === 'client' ? 'impayé client' : 'montant dû aux fournisseurs' }}</p>
          <small>Seules les lignes non lettrées rattachées à un tiers sont prises en compte.</small>
        </div>
        <div v-else class="fs-table-wrap" :class="{ 'is-loading': chargement }">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Tiers</th><th class="text-right">Délai</th>
                <th v-for="t in TRANCHES" :key="t.id" class="text-right">{{ t.label }}</th>
                <th class="text-right">{{ filtres.type === 'client' ? 'Avances reçues' : 'Avances versées' }}</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in data.lignes" :key="l.id">
                <td><router-link :to="`/tiers/${l.id}`" class="ba-lien">{{ l.nom }}</router-link> <small class="ba-sous">{{ l.code }}</small></td>
                <td class="text-right num ba-sous">{{ l.delai_paiement }} j</td>
                <td v-for="t in TRANCHES" :key="t.id" class="text-right num" :class="{ [`is-${t.id}`]: l[t.id] > 0 }">{{ l[t.id] ? formatMontant(l[t.id]) : '' }}</td>
                <td class="text-right num ba-sous">{{ l.avance ? formatMontant(l.avance) : '' }}</td>
                <td class="text-right num"><strong>{{ formatMontant(l.total) }}</strong></td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2"><strong>Total</strong></td>
                <td v-for="t in TRANCHES" :key="t.id" class="text-right num"><strong>{{ formatMontant(data.totaux[t.id]) }}</strong></td>
                <td class="text-right num"><strong>{{ formatMontant(data.totaux.avance) }}</strong></td>
                <td class="text-right num"><strong>{{ formatMontant(data.totaux.total) }}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>
      <p class="ba-note">
        <i class="bi bi-info-circle"></i>
        Échéance = date de la facture + délai de paiement du tiers. Les règlements non lettrés sont imputés sur les factures les plus anciennes.
      </p>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import { formatDate, formatMontant, messagesErreur } from '@/composables/useTiers';

const ONGLETS = [{ id: 'client', label: 'Clients' }, { id: 'fournisseur', label: 'Fournisseurs' }];
const TRANCHES = [
  { id: 'non_echu', label: 'Non échu' },
  { id: 'j30', label: '1 à 30 j' },
  { id: 'j60', label: '31 à 60 j' },
  { id: 'j90', label: '61 à 90 j' },
  { id: 'plus90', label: '+ de 90 j' },
];

const router = useRouter();
const user = ref(null);
const data = ref(null);
const chargement = ref(true);
const erreurs = ref([]);
const filtres = reactive({ type: 'client', date: new Date().toISOString().slice(0, 10) });

const sousTitre = computed(() => `${filtres.type === 'client' ? 'Créances clients' : 'Dettes fournisseurs'} au ${formatDate(filtres.date)}, par retard`);
const totalBrut = computed(() => TRANCHES.reduce((s, t) => s + (data.value?.totaux[t.id] || 0), 0));
const part = v => totalBrut.value ? `${Math.round((v / totalBrut.value) * 100)} %` : '0 %';

async function charger() {
  chargement.value = true;
  erreurs.value = [];
  try {
    const r = await axios.get(`${API_BASE}/tiers-balance-agee`, { params: { type: filtres.type, date: filtres.date } });
    data.value = r.data;
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    chargement.value = false;
  }
}

function exporter() {
  const num = n => String(Number(n || 0).toFixed(2)).replace('.', ',');
  const entete = ['Code', 'Tiers', 'Délai (j)', ...TRANCHES.map(t => t.label), 'Avances', 'Total'];
  const lignes = data.value.lignes.map(l => [l.code, l.nom, l.delai_paiement, ...TRANCHES.map(t => num(l[t.id])), num(l.avance), num(l.total)]);
  const csv = [entete, ...lignes].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(';')).join('\r\n');
  const url = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: `balance-agee-${filtres.type}-${filtres.date}.csv` });
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

watch(() => [filtres.type, filtres.date], charger);

onMounted(() => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  charger();
});
</script>

<style scoped>
.ba { display: flex; flex-direction: column; gap: 16px; }
.ba-msg { margin: 0; }
.ba-filtres { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
.ba-onglets { display: inline-flex; gap: 4px; padding: 4px; border-radius: 10px; background: var(--fs-surface-3); }
.ba-onglet { padding: 7px 14px; border: 0; border-radius: 7px; background: transparent; cursor: pointer; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2); }
.ba-onglet.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.ba-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.ba-date { display: flex; flex-direction: column; gap: 4px; width: 180px; }

/* Une seule teinte, de plus en plus soutenue avec le retard */
.ba-tranches { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
.ba-tranche { display: flex; flex-direction: column; gap: 4px; padding: 14px 16px; --ba-c: var(--fs-muted); }
.ba-tranche strong { font-size: 18px; color: var(--fs-text); }
.ba-tranche small { font-size: 12px; color: var(--fs-muted); }
.ba-jauge { height: 4px; border-radius: 2px; background: var(--fs-surface-3); overflow: hidden; margin-top: 4px; }
.ba-jauge span { display: block; height: 100%; background: var(--ba-c); }
.ba-tranche.is-non_echu { --ba-c: var(--fs-success); }
.ba-tranche.is-j30 { --ba-c: color-mix(in srgb, var(--fs-negative) 35%, var(--fs-warning, #f59e0b)); }
.ba-tranche.is-j60 { --ba-c: color-mix(in srgb, var(--fs-negative) 60%, var(--fs-warning, #f59e0b)); }
.ba-tranche.is-j90 { --ba-c: color-mix(in srgb, var(--fs-negative) 80%, var(--fs-warning, #f59e0b)); }
.ba-tranche.is-plus90 { --ba-c: var(--fs-negative); }
td.is-j60, td.is-j90, td.is-plus90 { color: var(--fs-negative); font-weight: 600; }

.ba-liste { padding: 0; overflow: hidden; }
.fs-table-wrap.is-loading { opacity: 0.6; }
.ba-lien { color: var(--fs-text); font-weight: 600; text-decoration: none; }
.ba-lien:hover { color: var(--fs-primary-text); text-decoration: underline; }
.ba-sous { font-size: 12.5px; color: var(--fs-muted); }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
tfoot td { padding: 11px 16px; border-top: 2px solid var(--fs-border-strong); background: var(--fs-surface-2); }
.ba-vide { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 56px 24px; text-align: center; }
.ba-vide span {
  display: grid; place-items: center; width: 56px; height: 56px; margin-bottom: 8px; border-radius: 50%;
  background: var(--fs-surface-3); color: var(--fs-muted); font-size: 24px;
}
.ba-vide p { margin: 0; font-weight: 600; color: var(--fs-text); }
.ba-vide small { color: var(--fs-muted); }
.ba-note { margin: 0; font-size: 12.5px; color: var(--fs-muted); display: flex; gap: 6px; align-items: center; }
@media (max-width: 1000px) { .ba-tranches { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
