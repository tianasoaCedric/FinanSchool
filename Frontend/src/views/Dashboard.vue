<template>
  <AppLayout
    :user="user"
    icon="bi-grid-1x2"
    :title="user ? `Bonjour, ${user.name}` : 'Tableau de bord'"
    :subtitle="exercice ? `Exercice du ${formatDate(exercice.Date_debut)} au ${formatDate(exercice.Date_fin)} · comparé à la même période N-1` : 'Vue d\'ensemble de l\'activité financière'"
  >
    <template #actions>
      <label v-if="exercicesProches.length" class="db-exercice">
        <span class="visually-hidden">Exercice</span>
        <select v-model="selectedExercice" class="fs-select" @change="charger">
          <option v-for="ex in exercicesProches" :key="ex.Id_Exercice_comptable" :value="ex.Id_Exercice_comptable">
            Exercice {{ ex.Annee_fiscale || new Date(ex.Date_debut).getFullYear() }}
          </option>
        </select>
      </label>
      <UiButton variant="primary" to="/ecriture" icon="bi-plus-lg">Nouvelle écriture</UiButton>
    </template>

    <!-- ===== Indicateurs clés ===== -->
    <section class="db-kpis" aria-label="Indicateurs clés">
      <article v-for="k in kpis" :key="k.cle" class="fs-card db-kpi">
        <div class="db-kpi__top">
          <span class="db-kpi__icon" :class="`db-kpi__icon--${k.ton}`"><i :class="['bi', k.icon]"></i></span>
          <span class="db-kpi__label">{{ k.label }}</span>
        </div>
        <p class="db-kpi__value" :class="{ 'is-neg': k.valeur < 0 }">
          <template v-if="data">{{ compact(k.valeur) }}<small> Ar</small></template>
          <span v-else class="db-shimmer"></span>
        </p>
        <p class="db-kpi__foot">
          <span v-if="k.variation !== null" class="db-delta" :class="k.variation >= 0 ? 'is-up' : 'is-down'">
            <i :class="['bi', k.variation >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right']"></i>
            {{ pourcent(k.variation, true) }}
          </span>
          <span class="db-kpi__hint">{{ k.hint }}</span>
        </p>
      </article>

      <router-link to="/validation" class="fs-card db-kpi db-kpi--link">
        <div class="db-kpi__top">
          <span class="db-kpi__icon" :class="saisie.pieces_brouillon ? 'db-kpi__icon--amber' : 'db-kpi__icon--green'">
            <i :class="['bi', saisie.pieces_brouillon ? 'bi-hourglass-split' : 'bi-shield-check']"></i>
          </span>
          <span class="db-kpi__label">Pièces à valider</span>
        </div>
        <p class="db-kpi__value">
          <template v-if="data">{{ saisie.pieces_brouillon }}<small> / {{ saisie.pieces }}</small></template>
          <span v-else class="db-shimmer"></span>
        </p>
        <p class="db-kpi__foot">
          <span class="db-kpi__hint db-kpi__hint--link">
            {{ saisie.pieces_brouillon ? `${saisie.lignes_brouillon} lignes en brouillon` : 'Tout est validé' }}
            <i class="bi bi-arrow-right"></i>
          </span>
        </p>
      </router-link>
    </section>

    <!-- ===== Activité ===== -->
    <section class="db-grid db-grid--2-1">
      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Produits, charges et résultat</h2>
            <p>Mouvements validés par mois</p>
          </div>
          <ul class="db-legend">
            <li><i :style="{ background: c.produits }"></i>Produits</li>
            <li><i :style="{ background: c.charges }"></i>Charges</li>
            <li><i class="db-legend__line" :style="{ background: c.ligne }"></i>Résultat du mois</li>
          </ul>
        </header>
        <div class="db-chart__body db-chart__body--lg">
          <Bar v-if="data" :data="activiteData" :options="activiteOptions" aria-label="Produits, charges et résultat par mois" />
          <div v-else class="db-placeholder"></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Répartition des charges</h2>
            <p>Par nature (classe 6)</p>
          </div>
        </header>
        <div class="db-donut">
          <div class="db-donut__chart">
            <Doughnut v-if="data" :data="chargesData" :options="donutOptions" aria-label="Répartition des charges par nature" />
            <div class="db-donut__center">
              <span>Total</span>
              <strong>{{ compact(totalCharges) }}</strong>
            </div>
          </div>
          <ul class="db-donut__list">
            <li v-for="(ch, i) in charges" :key="ch.label">
              <i :style="{ background: c.serie[i] }"></i>
              <span class="db-donut__name">{{ ch.label }}</span>
              <span class="db-donut__pct">{{ pourcent(totalCharges ? ch.montant / totalCharges : 0) }}</span>
            </li>
          </ul>
        </div>
      </article>
    </section>

    <section class="db-grid db-grid--1-1">
      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Chiffre d'affaires</h2>
            <p>Comparaison mensuelle avec l'exercice précédent</p>
          </div>
          <ul class="db-legend">
            <li><i :style="{ background: c.produits }"></i>Exercice N</li>
            <li><i class="db-legend__dash" :style="{ borderColor: c.neutre }"></i>N-1</li>
          </ul>
        </header>
        <div class="db-chart__body">
          <Line v-if="data" :data="caData" :options="lineOptions" aria-label="Chiffre d'affaires mensuel, N et N-1" />
          <div v-else class="db-placeholder"></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Trésorerie</h2>
            <p>Solde en fin de mois (comptes 5)</p>
          </div>
          <span v-if="data" class="db-chip">Min. {{ compact(tresoMin) }} Ar</span>
        </header>
        <div class="db-chart__body">
          <Line v-if="data" :data="tresoData" :options="lineOptions" aria-label="Solde de trésorerie en fin de mois" />
          <div v-else class="db-placeholder"></div>
        </div>
      </article>
    </section>

    <!-- ===== Rentabilité et structure ===== -->
    <section class="db-grid db-grid--1-1">
      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Formation du résultat</h2>
            <p>Soldes intermédiaires de gestion</p>
          </div>
          <ul class="db-legend">
            <li><i :style="{ background: c.produits }"></i>Hausse</li>
            <li><i :style="{ background: c.charges }"></i>Baisse</li>
            <li><i :style="{ background: c.neutre }"></i>Solde</li>
          </ul>
        </header>
        <div class="db-chart__body db-chart__body--lg">
          <Bar v-if="data" :data="sigData" :options="sigOptions" aria-label="Cascade des soldes intermédiaires de gestion" />
          <div v-else class="db-placeholder"></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <div>
            <h2>Structure du bilan</h2>
            <p>Valeurs nettes à la clôture</p>
          </div>
          <span v-if="data" class="db-chip">Total {{ compact(totalBilan) }} Ar</span>
        </header>
        <div class="db-chart__body db-chart__body--bilan">
          <Bar v-if="data" :data="bilanData" :options="bilanOptions" aria-label="Structure de l'actif et du passif" />
          <div v-else class="db-placeholder"></div>
        </div>
        <div class="db-bilan-legend">
          <ul>
            <li class="db-bilan-legend__title">Actif</li>
            <li v-for="(s, i) in BILAN.actif" :key="s.cle"><i :style="{ background: c.serie[i] }"></i>{{ s.label }}<span>{{ compact(data?.bilan.actif[s.cle]) }}</span></li>
          </ul>
          <ul>
            <li class="db-bilan-legend__title">Passif</li>
            <li v-for="(s, i) in BILAN.passif" :key="s.cle"><i :style="{ background: c.serie[i] }"></i>{{ s.label }}<span>{{ compact(data?.bilan.passif[s.cle]) }}</span></li>
          </ul>
        </div>
      </article>
    </section>

    <!-- ===== Santé financière ===== -->
    <section class="db-grid db-grid--3">
      <article class="fs-card db-panel">
        <header class="db-chart__head">
          <div>
            <h2>Ratios clés</h2>
            <p>Santé financière à la clôture</p>
          </div>
        </header>
        <ul class="db-ratios">
          <li v-for="r in ratios" :key="r.label">
            <div>
              <span class="db-ratios__label">{{ r.label }}</span>
              <span class="db-ratios__hint">{{ r.hint }}</span>
            </div>
            <div class="db-ratios__right">
              <strong>{{ r.texte }}</strong>
              <span class="db-status" :class="`db-status--${r.statut.ton}`">
                <i :class="['bi', r.statut.icon]"></i>{{ r.statut.label }}
              </span>
            </div>
          </li>
        </ul>
      </article>

      <article class="fs-card db-panel">
        <header class="db-chart__head">
          <div>
            <h2>Principaux postes de charges</h2>
            <p>Top 5 des comptes de la période</p>
          </div>
        </header>
        <ol class="db-top">
          <li v-for="t in data?.top_charges || []" :key="t.code">
            <div class="db-top__row">
              <span class="db-top__name"><code>{{ t.code }}</code> {{ t.libelle }}</span>
              <span class="db-top__val">{{ compact(t.montant) }}</span>
            </div>
            <div class="db-top__bar"><span :style="{ width: `${(t.montant / maxTop) * 100}%` }"></span></div>
          </li>
          <li v-if="data && !data.top_charges.length" class="db-empty">Aucune charge sur la période</li>
        </ol>
      </article>

      <article class="fs-card db-panel">
        <header class="db-chart__head">
          <div>
            <h2>Équilibre de l'exercice</h2>
            <p>Produits et charges de la période</p>
          </div>
        </header>
        <div class="db-balance">
          <div class="db-balance__row">
            <span>Produits</span><strong>{{ montant(data?.kpis.total_produits) }}</strong>
          </div>
          <div class="db-balance__bar"><span :style="{ width: `${partProduits}%`, background: c.produits }"></span></div>
          <div class="db-balance__row">
            <span>Charges</span><strong>{{ montant(data?.kpis.total_charges) }}</strong>
          </div>
          <div class="db-balance__bar"><span :style="{ width: `${partCharges}%`, background: c.charges }"></span></div>
          <div class="db-balance__result" :class="resultatNet >= 0 ? 'is-up' : 'is-down'">
            <span>{{ resultatNet >= 0 ? 'Bénéfice' : 'Perte' }}</span>
            <strong>{{ montant(resultatNet) }} Ar</strong>
          </div>
          <p class="db-balance__note">
            Marge nette : <strong>{{ pourcent(data?.ratios.marge_nette) }}</strong> du chiffre d'affaires
          </p>
        </div>
      </article>
    </section>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import {
  Chart as ChartJS, BarElement, LineElement, ArcElement, PointElement,
  CategoryScale, LinearScale, Filler, Tooltip, Legend, BarController, LineController,
} from 'chart.js';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import { useTheme } from '@/composables/useTheme';
import { useParametresStore } from '@/stores/parametres';

ChartJS.register(BarElement, LineElement, ArcElement, PointElement, CategoryScale, LinearScale, Filler, Tooltip, Legend, BarController, LineController);
ChartJS.defaults.font.family = "'Inter Variable', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
ChartJS.defaults.font.size = 12;

/* Palette catégorielle validée (contraste, daltonisme) : ordre fixe, jamais recyclé. */
const PALETTES = {
  light: {
    serie: ['#1c45bd', '#e8590c', '#0d9488', '#ca8a04', '#db2777', '#7c3aed'],
    neutre: '#94a3b8', ligne: '#0f172a', texte: '#64748b', grille: 'rgba(15, 23, 42, 0.06)', surface: '#ffffff',
    fill: 'rgba(13, 148, 136, 0.10)',
  },
  dark: {
    serie: ['#5b7fff', '#ea6a1f', '#0fa596', '#c8930a', '#e0569a', '#9575f0'],
    neutre: '#64748b', ligne: '#e6eaf2', texte: '#8b97ad', grille: 'rgba(255, 255, 255, 0.06)', surface: '#121a2b',
    fill: 'rgba(15, 165, 150, 0.14)',
  },
};
const MOIS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
const BILAN = {
  actif: [
    { cle: 'immobilisations', label: 'Immobilisations' },
    { cle: 'stocks', label: 'Stocks' },
    { cle: 'creances', label: 'Créances' },
    { cle: 'tresorerie', label: 'Trésorerie' },
  ],
  passif: [
    { cle: 'capitaux_propres', label: 'Capitaux propres' },
    { cle: 'dettes_financieres', label: 'Dettes financières et provisions' },
    { cle: 'fournisseurs', label: 'Fournisseurs' },
    { cle: 'autres_dettes', label: 'Autres dettes' },
  ],
};

const router = useRouter();
const { theme } = useTheme();
const parametresStore = useParametresStore();
parametresStore.charger();
const user = ref(null);
const exercices = ref([]);
const exerciceCourantId = ref(null);
const selectedExercice = ref(null);
const data = ref(null);

const c = computed(() => {
  const p = PALETTES[theme.value] || PALETTES.light;
  return { ...p, produits: p.serie[0], charges: p.serie[1] };
});

// ---------- Formatage ----------
const nf = (v, opts) => (v === null || v === undefined ? '–' : Number(v).toLocaleString('fr-FR', opts));
const compact = v => nf(v, { notation: 'compact', maximumFractionDigits: 1 });
const montant = v => nf(v, { maximumFractionDigits: 0 });
const pourcent = (v, signe = false) => (v === null || v === undefined
  ? '–'
  : `${signe && v > 0 ? '+' : ''}${(v * 100).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`);
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' }) : '');
const variation = (n, n1) => (n1 ? (n - n1) / Math.abs(n1) : null);

// ---------- Exercices ----------
const exercice = computed(() => exercices.value.find(e => e.Id_Exercice_comptable === selectedExercice.value) || null);
const exercicesProches = computed(() => {
  const i = exercices.value.findIndex(e => e.Id_Exercice_comptable === exerciceCourantId.value);
  if (i === -1) return exercices.value.slice(0, 7);
  return exercices.value.slice(Math.max(0, i - 3), Math.min(exercices.value.length, i + 4));
});

async function charger() {
  if (!exercice.value) return;
  data.value = null;
  try {
    const { data: d } = await axios.get(`${API_BASE}/dashboard/synthese`, {
      params: { date_debut: exercice.value.Date_debut.slice(0, 10), date_fin: exercice.value.Date_fin.slice(0, 10) },
    });
    data.value = d;
  } catch (e) {
    console.error('Erreur de chargement du tableau de bord :', e);
  }
}

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  try {
    user.value = (await getUser(token)).data;
  } catch {
    return router.push('/');
  }
  const [resEx, resCourant] = await Promise.allSettled([
    axios.get(`${API_BASE}/exercices`),
    axios.get(`${API_BASE}/exercices/courant`),
  ]);
  if (resEx.status === 'fulfilled') {
    const d = resEx.value.data;
    exercices.value = Array.isArray(d?.exercices) ? d.exercices : Array.isArray(d) ? d : [];
  }
  const courant = resCourant.status === 'fulfilled' ? resCourant.value.data?.exercice : null;
  exerciceCourantId.value = courant?.Id_Exercice_comptable ?? exercices.value[0]?.Id_Exercice_comptable ?? null;
  selectedExercice.value = exerciceCourantId.value;
  await charger();
});

// ---------- Indicateurs ----------
const saisie = computed(() => data.value?.saisie || { pieces: 0, pieces_brouillon: 0, lignes_brouillon: 0 });
const kpis = computed(() => {
  const k = data.value?.kpis;
  const def = (cle, label, icon, ton, hint) => ({
    cle, label, icon, ton, hint,
    valeur: k?.[cle]?.n ?? null,
    variation: k ? variation(k[cle].n, k[cle].n1) : null,
  });
  return [
    def('ca', "Chiffre d'affaires", 'bi-graph-up-arrow', 'blue', 'vs N-1'),
    def('resultat_net', 'Résultat net', 'bi-trophy', 'green', data.value ? `Marge ${pourcent(data.value.ratios.marge_nette)}` : ''),
    def('ebe', "Excédent brut d'exploitation", 'bi-speedometer2', 'violet', 'vs N-1'),
    def('tresorerie', 'Trésorerie nette', 'bi-wallet2', 'teal', 'À la clôture, vs N-1'),
  ];
});
const resultatNet = computed(() => data.value?.kpis.resultat_net.n ?? 0);
const partProduits = computed(() => {
  const p = data.value?.kpis.total_produits || 0, ch = data.value?.kpis.total_charges || 0;
  return Math.max(p, ch) ? (p / Math.max(p, ch)) * 100 : 0;
});
const partCharges = computed(() => {
  const p = data.value?.kpis.total_produits || 0, ch = data.value?.kpis.total_charges || 0;
  return Math.max(p, ch) ? (ch / Math.max(p, ch)) * 100 : 0;
});

// ---------- Options communes ----------
const tooltip = computed(() => ({
  padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
  titleFont: { weight: '600' },
  callbacks: { label: ctx => ` ${ctx.dataset.label} : ${montant(ctx.parsed.y ?? ctx.parsed)} Ar` },
}));
const echelles = computed(() => ({
  x: { grid: { display: false }, border: { display: false }, ticks: { color: c.value.texte } },
  y: {
    grid: { color: c.value.grille }, border: { display: false },
    ticks: { color: c.value.texte, callback: v => compact(v), maxTicksLimit: 6 },
  },
}));
const base = computed(() => ({
  responsive: true, maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: { legend: { display: false }, tooltip: tooltip.value },
  scales: echelles.value,
}));

const libelleMois = m => MOIS[Number(m.slice(5, 7)) - 1];
const mois = computed(() => data.value?.mois || []);

// ---------- Activité mensuelle ----------
const activiteData = computed(() => ({
  labels: mois.value.map(m => libelleMois(m.mois)),
  datasets: [
    {
      type: 'line', label: 'Résultat du mois', data: mois.value.map(m => m.resultat),
      borderColor: c.value.ligne, backgroundColor: c.value.ligne, borderWidth: 2, tension: 0.3,
      pointRadius: 3, pointHoverRadius: 5, pointBackgroundColor: c.value.surface, pointBorderWidth: 2, order: 0,
    },
    {
      label: 'Produits', data: mois.value.map(m => m.produits), backgroundColor: c.value.produits,
      borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18, order: 1,
    },
    {
      label: 'Charges', data: mois.value.map(m => m.charges), backgroundColor: c.value.charges,
      borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18, order: 1,
    },
  ],
}));
const activiteOptions = computed(() => ({
  ...base.value,
  datasets: { bar: { categoryPercentage: 0.7, barPercentage: 0.85 } },
}));

// ---------- Charges par nature ----------
const charges = computed(() => (data.value?.charges || []).filter(ch => ch.montant > 0));
const totalCharges = computed(() => charges.value.reduce((s, ch) => s + ch.montant, 0));
const chargesData = computed(() => ({
  labels: charges.value.map(ch => ch.label),
  datasets: [{
    label: 'Charges', data: charges.value.map(ch => ch.montant),
    backgroundColor: c.value.serie, borderColor: c.value.surface, borderWidth: 2, hoverOffset: 6,
  }],
}));
const donutOptions = computed(() => ({
  responsive: true, maintainAspectRatio: false, cutout: '70%',
  plugins: {
    legend: { display: false },
    tooltip: {
      ...tooltip.value,
      callbacks: { label: ctx => ` ${montant(ctx.parsed)} Ar · ${pourcent(ctx.parsed / totalCharges.value)}` },
    },
  },
}));

// ---------- Chiffre d'affaires N / N-1 ----------
const caData = computed(() => ({
  labels: mois.value.map(m => libelleMois(m.mois)),
  datasets: [
    {
      label: 'Exercice N', data: mois.value.map(m => m.ca), borderColor: c.value.produits, backgroundColor: c.value.produits,
      borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 5,
    },
    {
      label: 'N-1', data: mois.value.map(m => m.ca_n1), borderColor: c.value.neutre, backgroundColor: c.value.neutre,
      borderWidth: 2, borderDash: [5, 4], tension: 0.3, pointRadius: 0, pointHoverRadius: 4, spanGaps: true,
    },
  ],
}));

// ---------- Trésorerie ----------
const tresoMin = computed(() => (mois.value.length ? Math.min(...mois.value.map(m => m.tresorerie)) : 0));
const tresoData = computed(() => ({
  labels: mois.value.map(m => libelleMois(m.mois)),
  datasets: [{
    label: 'Trésorerie', data: mois.value.map(m => m.tresorerie),
    borderColor: c.value.serie[2], backgroundColor: c.value.fill, fill: true,
    borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 5,
  }],
}));
const lineOptions = computed(() => ({
  ...base.value,
  scales: { ...echelles.value, y: { ...echelles.value.y, beginAtZero: false, grace: '10%' } },
}));

// ---------- Cascade des SIG ----------
const sig = computed(() => {
  let cumul = 0;
  return (data.value?.sig || []).map(s => {
    if (s.total || s.code === 'production') {
      cumul = s.montant;
      return { ...s, plage: [0, s.montant], type: s.code === 'production' ? 'hausse' : 'solde' };
    }
    const avant = cumul;
    cumul += s.montant;
    return { ...s, plage: [avant, cumul], type: s.montant >= 0 ? 'hausse' : 'baisse' };
  });
});
const sigData = computed(() => ({
  labels: sig.value.map(s => s.label),
  datasets: [{
    label: 'Montant', data: sig.value.map(s => s.plage),
    backgroundColor: sig.value.map(s => (s.type === 'solde' ? c.value.neutre : s.type === 'hausse' ? c.value.produits : c.value.charges)),
    borderRadius: 4, borderSkipped: false, barPercentage: 0.75, categoryPercentage: 0.9,
  }],
}));
const sigOptions = computed(() => ({
  responsive: true, maintainAspectRatio: false, indexAxis: 'y',
  plugins: {
    legend: { display: false },
    tooltip: { ...tooltip.value, callbacks: { label: ctx => ` ${montant(sig.value[ctx.dataIndex].montant)} Ar` } },
  },
  scales: {
    x: { grid: { color: c.value.grille }, border: { display: false }, ticks: { color: c.value.texte, callback: v => compact(v), maxTicksLimit: 6 } },
    y: {
      grid: { display: false }, border: { display: false },
      ticks: {
        color: ctx => (sig.value[ctx.index]?.total ? c.value.ligne : c.value.texte),
        font: ctx => ({ weight: sig.value[ctx.index]?.total ? '600' : '400' }),
      },
    },
  },
}));

// ---------- Structure du bilan ----------
const totalBilan = computed(() => {
  const a = data.value?.bilan.actif;
  return a ? Object.values(a).reduce((s, v) => s + v, 0) : 0;
});
const bilanData = computed(() => ({
  labels: ['Actif', 'Passif'],
  datasets: [0, 1, 2, 3].map(i => ({
    label: `${BILAN.actif[i].label}|${BILAN.passif[i].label}`,
    data: [data.value?.bilan.actif[BILAN.actif[i].cle] || 0, data.value?.bilan.passif[BILAN.passif[i].cle] || 0],
    backgroundColor: c.value.serie[i], borderColor: c.value.surface, borderWidth: { left: 0, right: 2, top: 0, bottom: 0 },
    borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.9,
  })),
}));
const bilanOptions = computed(() => ({
  responsive: true, maintainAspectRatio: false, indexAxis: 'y',
  plugins: {
    legend: { display: false },
    tooltip: {
      ...tooltip.value, mode: 'nearest', intersect: true,
      callbacks: {
        title: () => '',
        label: ctx => {
          const nom = ctx.dataset.label.split('|')[ctx.dataIndex];
          return ` ${nom} : ${montant(ctx.parsed.x)} Ar · ${pourcent(totalBilan.value ? ctx.parsed.x / totalBilan.value : 0)}`;
        },
      },
    },
  },
  scales: {
    x: { stacked: true, display: false, max: totalBilan.value || undefined },
    y: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { color: c.value.texte, font: { weight: '600' } } },
  },
}));

// ---------- Ratios ----------
const STATUT = {
  bon: { ton: 'good', icon: 'bi-check-circle-fill', label: 'Bon' },
  moyen: { ton: 'warn', icon: 'bi-exclamation-circle-fill', label: 'À surveiller' },
  faible: { ton: 'bad', icon: 'bi-x-circle-fill', label: 'Faible' },
  na: { ton: 'neutral', icon: 'bi-dash-circle', label: 'N/D' },
};
const seuil = (v, bon, moyen, inverse = false) => {
  if (v === null || v === undefined) return STATUT.na;
  if (inverse) return v <= bon ? STATUT.bon : v <= moyen ? STATUT.moyen : STATUT.faible;
  return v >= bon ? STATUT.bon : v >= moyen ? STATUT.moyen : STATUT.faible;
};
// Seuils réglables dans Paramètres › Tableau de bord (pourcentages saisis en %)
const ratios = computed(() => {
  const r = data.value?.ratios || {};
  const s = cle => Number(parametresStore.get(`dashboard.${cle}`));
  const jours = v => (v === null || v === undefined ? '–' : `${v} j`);
  return [
    { label: 'Liquidité générale', hint: 'Actif courant / dettes à court terme', texte: nf(r.liquidite_generale, { maximumFractionDigits: 2 }), statut: seuil(r.liquidite_generale, s('liquidite_bon'), s('liquidite_moyen')) },
    { label: 'Autonomie financière', hint: 'Capitaux propres / total bilan', texte: pourcent(r.autonomie_financiere), statut: seuil(r.autonomie_financiere, s('autonomie_bon') / 100, s('autonomie_moyen') / 100) },
    { label: 'Marge nette', hint: "Résultat net / chiffre d'affaires", texte: pourcent(r.marge_nette), statut: seuil(r.marge_nette, s('marge_bon') / 100, s('marge_moyen') / 100) },
    { label: 'Délai clients', hint: 'Créances clients en jours de CA TTC', texte: jours(r.delai_clients), statut: seuil(r.delai_clients, s('delai_clients_bon'), s('delai_clients_moyen'), true) },
    { label: 'Délai fournisseurs', hint: 'Dettes fournisseurs en jours d\'achats TTC', texte: jours(r.delai_fournisseurs), statut: r.delai_fournisseurs === null || r.delai_fournisseurs === undefined ? STATUT.na : STATUT.bon },
    { label: 'Besoin en fonds de roulement', hint: 'Stocks + créances − dettes d\'exploitation', texte: `${compact(r.bfr)} Ar`, statut: r.bfr === undefined ? STATUT.na : r.bfr <= 0 ? STATUT.bon : STATUT.moyen },
  ];
});

const maxTop = computed(() => Math.max(1, ...(data.value?.top_charges || []).map(t => t.montant)));
</script>

<style scoped>
.visually-hidden {
  position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}
.db-exercice .fs-select { width: auto; min-width: 170px; }

/* ---------- Indicateurs ---------- */
.db-kpis {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 20px;
}
.db-kpi {
  display: flex; flex-direction: column; gap: 10px;
  padding: 18px 18px 16px;
  text-decoration: none; color: inherit; min-width: 0;
}
.db-kpi__top { display: flex; align-items: center; gap: 10px; min-width: 0; }
.db-kpi__icon {
  flex: none; width: 32px; height: 32px; border-radius: 9px;
  display: grid; place-items: center; font-size: 15px;
}
.db-kpi__icon--blue { background: var(--fs-primary-soft); color: var(--fs-primary-text); }
.db-kpi__icon--green { background: var(--fs-success-soft); color: var(--fs-success); }
.db-kpi__icon--amber { background: var(--fs-warning-soft); color: var(--fs-warning); }
.db-kpi__icon--violet { background: rgba(124, 58, 237, 0.12); color: #7c3aed; }
.db-kpi__icon--teal { background: rgba(13, 148, 136, 0.12); color: #0d9488; }
.fs-page[data-theme="dark"] .db-kpi__icon--violet { color: #a78bfa; }
.fs-page[data-theme="dark"] .db-kpi__icon--teal { color: #2dd4bf; }
.db-kpi__label {
  font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-muted);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.db-kpi__value {
  margin: 0; font-size: var(--fs-text-2xl); line-height: 1.1; font-weight: 700;
  letter-spacing: -0.02em; color: var(--fs-text); font-variant-numeric: tabular-nums;
}
.db-kpi__value small { font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-muted); letter-spacing: 0; }
.db-kpi__value.is-neg { color: var(--fs-negative); }
.db-kpi__foot { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.db-kpi__hint { font-size: var(--fs-text-xs); color: var(--fs-muted); }
.db-kpi__hint--link { color: var(--fs-primary-text); font-weight: 500; }
.db-kpi--link { transition: border-color 0.15s ease, transform 0.15s ease; }
.db-kpi--link:hover { border-color: var(--fs-primary); transform: translateY(-1px); }
.db-kpi--link:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }

.db-delta {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 7px; border-radius: 999px;
  font-size: var(--fs-text-xs); font-weight: 600; font-variant-numeric: tabular-nums;
}
.db-delta.is-up { background: var(--fs-success-soft); color: var(--fs-success); }
.db-delta.is-down { background: var(--fs-negative-soft); color: var(--fs-negative); }

.db-shimmer {
  display: inline-block; width: 60%; height: 28px; border-radius: 6px;
  background: linear-gradient(90deg, var(--fs-surface-3) 0%, var(--fs-surface-2) 50%, var(--fs-surface-3) 100%);
  background-size: 200% 100%; animation: db-shimmer 1.2s linear infinite;
}
@keyframes db-shimmer { to { background-position: -200% 0; } }

/* ---------- Grilles ---------- */
.db-grid { display: grid; gap: 20px; margin-bottom: 20px; }
.db-grid--2-1 { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
.db-grid--1-1 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.db-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }

/* ---------- Cartes graphiques ---------- */
.db-chart, .db-panel { display: flex; flex-direction: column; min-width: 0; }
.db-chart__head {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  padding: 18px 20px 0;
}
.db-chart__head h2 { margin: 0; font-size: var(--fs-text-md); font-weight: 600; color: var(--fs-text); }
.db-chart__head p { margin: 2px 0 0; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.db-chart__body { position: relative; height: 260px; padding: 14px 20px 18px; }
.db-chart__body--lg { height: 300px; }
.db-chart__body--bilan { height: 150px; padding-bottom: 4px; }
.db-placeholder {
  height: 100%; border-radius: 10px;
  background: linear-gradient(90deg, var(--fs-surface-2) 0%, var(--fs-surface-3) 50%, var(--fs-surface-2) 100%);
  background-size: 200% 100%; animation: db-shimmer 1.4s linear infinite;
}

.db-legend { display: flex; gap: 14px; flex-wrap: wrap; margin: 2px 0 0; padding: 0; list-style: none; }
.db-legend li { display: inline-flex; align-items: center; gap: 6px; font-size: var(--fs-text-xs); color: var(--fs-text-2); }
.db-legend i { width: 10px; height: 10px; border-radius: 3px; }
.db-legend .db-legend__line { width: 14px; height: 2px; border-radius: 1px; }
.db-legend .db-legend__dash { width: 14px; height: 0; border-top: 2px dashed; border-radius: 0; background: none; }

.db-chip {
  font-size: var(--fs-text-xs); font-weight: 500; color: var(--fs-text-2);
  background: var(--fs-surface-3); padding: 3px 9px; border-radius: 999px; white-space: nowrap;
}

/* ---------- Donut ---------- */
.db-donut { display: flex; flex-direction: column; gap: 14px; padding: 14px 20px 18px; flex: 1; }
.db-donut__chart { position: relative; height: 180px; }
.db-donut__center {
  position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
  pointer-events: none;
}
.db-donut__center span { font-size: var(--fs-text-xs); color: var(--fs-muted); }
.db-donut__center strong { font-size: var(--fs-text-lg); font-weight: 700; color: var(--fs-text); font-variant-numeric: tabular-nums; }
.db-donut__list { margin: 0; padding: 0; list-style: none; display: grid; gap: 7px; }
.db-donut__list li { display: flex; align-items: center; gap: 8px; font-size: var(--fs-text-sm); }
.db-donut__list i { flex: none; width: 10px; height: 10px; border-radius: 3px; }
.db-donut__name { flex: 1; color: var(--fs-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-donut__pct { color: var(--fs-text); font-weight: 600; font-variant-numeric: tabular-nums; }

/* ---------- Légende bilan ---------- */
.db-bilan-legend { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 4px 20px 18px; }
.db-bilan-legend ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
.db-bilan-legend li { display: flex; align-items: center; gap: 8px; font-size: var(--fs-text-sm); color: var(--fs-text-2); min-width: 0; }
.db-bilan-legend li i { flex: none; width: 10px; height: 10px; border-radius: 3px; }
.db-bilan-legend li span { margin-left: auto; color: var(--fs-text); font-weight: 600; font-variant-numeric: tabular-nums; }
.db-bilan-legend__title {
  font-size: var(--fs-text-xs) !important; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;
  color: var(--fs-muted) !important;
}

/* ---------- Ratios ---------- */
.db-ratios { margin: 0; padding: 8px 20px 14px; list-style: none; }
.db-ratios li {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 10px 0; border-bottom: 1px solid var(--fs-border);
}
.db-ratios li:last-child { border-bottom: 0; }
.db-ratios__label { display: block; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text); }
.db-ratios__hint { display: block; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.db-ratios__right { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex: none; }
.db-ratios__right strong { font-size: var(--fs-text-base); font-variant-numeric: tabular-nums; color: var(--fs-text); }
.db-status { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; }
.db-status--good { color: var(--fs-success); }
.db-status--warn { color: var(--fs-warning); }
.db-status--bad { color: var(--fs-negative); }
.db-status--neutral { color: var(--fs-muted); }

/* ---------- Top charges ---------- */
.db-top { margin: 0; padding: 12px 20px 18px; list-style: none; display: grid; gap: 14px; }
.db-top__row { display: flex; justify-content: space-between; gap: 10px; font-size: var(--fs-text-sm); margin-bottom: 6px; }
.db-top__name { color: var(--fs-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-top__name code {
  font-size: var(--fs-text-xs); color: var(--fs-muted); background: var(--fs-surface-3);
  padding: 1px 5px; border-radius: 4px; margin-right: 4px;
}
.db-top__val { font-weight: 600; color: var(--fs-text); font-variant-numeric: tabular-nums; flex: none; }
.db-top__bar { height: 6px; border-radius: 999px; background: var(--fs-surface-3); overflow: hidden; }
.db-top__bar span { display: block; height: 100%; border-radius: 999px; background: var(--fs-primary); }
.db-empty { font-size: var(--fs-text-sm); color: var(--fs-muted); }

/* ---------- Équilibre ---------- */
.db-balance { padding: 14px 20px 18px; display: flex; flex-direction: column; gap: 8px; }
.db-balance__row { display: flex; justify-content: space-between; font-size: var(--fs-text-sm); color: var(--fs-text-2); }
.db-balance__row strong { color: var(--fs-text); font-variant-numeric: tabular-nums; }
.db-balance__bar { height: 10px; border-radius: 999px; background: var(--fs-surface-3); overflow: hidden; margin-bottom: 6px; }
.db-balance__bar span { display: block; height: 100%; border-radius: 999px; }
.db-balance__result {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 6px; padding: 12px 14px; border-radius: 10px; font-size: var(--fs-text-sm); font-weight: 500;
}
.db-balance__result strong { font-size: var(--fs-text-lg); font-variant-numeric: tabular-nums; }
.db-balance__result.is-up { background: var(--fs-success-soft); color: var(--fs-success); }
.db-balance__result.is-down { background: var(--fs-negative-soft); color: var(--fs-negative); }
.db-balance__note { margin: 4px 0 0; font-size: var(--fs-text-xs); color: var(--fs-muted); }

/* ---------- Responsive ---------- */
@media (max-width: 1400px) {
  .db-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 1200px) {
  .db-grid--2-1, .db-grid--1-1 { grid-template-columns: 1fr; }
  .db-grid--3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 860px) {
  .db-kpis, .db-grid--3 { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .db-shimmer, .db-placeholder { animation: none; }
}
</style>
