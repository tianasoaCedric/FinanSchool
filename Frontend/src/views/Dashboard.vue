<template>
  <AppLayout
    :user="user"
    icon="bi-grid-1x2"
    :title="user ? `Bonjour, ${user.name}` : 'Tableau de bord'"
    :subtitle="exerciceCourant ? `Exercice du ${formatDate(exerciceCourant.Date_debut)} au ${formatDate(exerciceCourant.Date_fin)}` : 'Vue d\'ensemble de l\'activité financière'"
  >
    <template #actions>
      <label v-if="exercicesFiltrés.length" class="db-exercice">
        <span class="visually-hidden">Exercice</span>
        <select v-model="selectedExercice" class="fs-select" @change="changeExercice">
          <option v-for="ex in exercicesFiltrés" :key="ex.Id_Exercice_comptable" :value="ex.Id_Exercice_comptable">
            Exercice {{ ex.Annee_fiscale || new Date(ex.Date_debut).getFullYear() }}
          </option>
        </select>
      </label>
      <UiButton variant="primary" to="/ecriture">
        <i class="bi bi-plus-lg"></i> Nouvelle écriture
      </UiButton>
    </template>

    <!-- Indicateurs clés -->
    <section class="db-kpis" aria-label="Indicateurs clés">
      <article class="fs-card db-kpi">
        <div class="db-kpi__icon db-kpi__icon--blue"><i class="bi bi-graph-up-arrow"></i></div>
        <div>
          <p class="db-kpi__label">Chiffre d'affaires</p>
          <p class="db-kpi__value">{{ formatMontant(resumeData?.solde_ca) }}</p>
          <p class="db-kpi__hint">Solde actuel · Ariary</p>
        </div>
      </article>
      <article class="fs-card db-kpi">
        <div class="db-kpi__icon db-kpi__icon--amber"><i class="bi bi-receipt"></i></div>
        <div>
          <p class="db-kpi__label">Charges</p>
          <p class="db-kpi__value">{{ formatMontant(resumeData?.solde_charges) }}</p>
          <p class="db-kpi__hint">Solde actuel · Ariary</p>
        </div>
      </article>
      <router-link to="/validation" class="fs-card db-kpi db-kpi--link">
        <div class="db-kpi__icon db-kpi__icon--green"><i class="bi bi-shield-check"></i></div>
        <div>
          <p class="db-kpi__label">Écritures à valider</p>
          <p class="db-kpi__value">{{ resumeData?.nb_ecritures_non_validees ?? '–' }}</p>
          <p class="db-kpi__hint">Ouvrir la validation <i class="bi bi-arrow-right"></i></p>
        </div>
      </router-link>
    </section>

    <!-- Graphiques -->
    <section class="db-charts" aria-label="Graphiques">
      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <h2>Évolution du chiffre d'affaires</h2>
          <span>Par mois</span>
        </header>
        <div class="db-chart__body">
          <Line v-if="caData.labels.length" :data="caChartData" :options="lineOptions" />
          <div v-else class="fs-skeleton"><div v-for="n in 4" :key="n" class="fs-skeleton__row"></div></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <h2>Évolution de la trésorerie</h2>
          <span>Par mois</span>
        </header>
        <div class="db-chart__body">
          <Line v-if="tresorerieData.labels.length" :data="tresorerieChartData" :options="lineOptions" />
          <div v-else class="fs-skeleton"><div v-for="n in 4" :key="n" class="fs-skeleton__row"></div></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <h2>Composition du bilan</h2>
          <span>À la clôture</span>
        </header>
        <div class="db-chart__body db-chart__body--pie">
          <Doughnut v-if="bilanData.labels.length" :data="bilanChartData" :options="pieOptions" />
          <div v-else class="fs-skeleton"><div v-for="n in 4" :key="n" class="fs-skeleton__row"></div></div>
        </div>
      </article>

      <article class="fs-card db-chart">
        <header class="db-chart__head">
          <h2>Décomposition du résultat</h2>
          <span>Du chiffre d'affaires au résultat net</span>
        </header>
        <div class="db-chart__body">
          <Bar v-if="resultatData.labels.length" :data="resultatChartData" :options="barOptions" />
          <div v-else class="fs-skeleton"><div v-for="n in 4" :key="n" class="fs-skeleton__row"></div></div>
        </div>
      </article>
    </section>
  </AppLayout>
</template>

<script>
import { API_BASE } from '@/config/api';
import { getUser } from "../services/Auth";
import AppLayout from "@/components/template/AppLayout.vue";
import { useTheme } from '@/composables/useTheme';
import axios from 'axios';
import { Line, Doughnut, Bar } from 'vue-chartjs';
import {
  Chart as ChartJS,
  LineElement,
  BarElement,
  ArcElement,
  CategoryScale,
  LinearScale,
  PointElement,
  Filler,
  Tooltip,
  Legend
} from 'chart.js';

ChartJS.register(LineElement, BarElement, ArcElement, CategoryScale, LinearScale, PointElement, Filler, Tooltip, Legend);
ChartJS.defaults.font.family = "'Inter Variable', 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

// Couleurs des graphiques selon le thème
const PALETTES = {
  light: {
    text: '#64748b', grid: 'rgba(15, 23, 42, 0.06)', surface: '#ffffff',
    blue: '#1c45bd', blueFill: 'rgba(28, 69, 189, 0.10)',
    green: '#059669', greenFill: 'rgba(5, 150, 105, 0.10)',
    bars: ['#1c45bd', '#3b6cf0', '#7c9cf5', '#d97706', '#059669', '#64748b'],
    pie: ['#1c45bd', '#3b6cf0', '#7c9cf5', '#b6c8fa', '#0ea5e9', '#059669', '#d97706', '#64748b'],
  },
  dark: {
    text: '#8b97ad', grid: 'rgba(255, 255, 255, 0.06)', surface: '#121a2b',
    blue: '#5b7fff', blueFill: 'rgba(91, 127, 255, 0.16)',
    green: '#34d399', greenFill: 'rgba(52, 211, 153, 0.14)',
    bars: ['#5b7fff', '#7593ff', '#9db2ff', '#fbbf24', '#34d399', '#8b97ad'],
    pie: ['#5b7fff', '#7593ff', '#9db2ff', '#c7d4ff', '#38bdf8', '#34d399', '#fbbf24', '#8b97ad'],
  },
};

const formatAxis = value => Number(value).toLocaleString('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });

export default {
  components: { AppLayout, Line, Doughnut, Bar },
  setup() {
    const { theme } = useTheme();
    return { theme };
  },
  data() {
    return {
      user: null,
      exercices: [],
      selectedExercice: null,
      exerciceCourant: null,
      caData: { labels: [], series: [] },
      tresorerieData: { labels: [], series: [] },
      bilanData: { labels: [], series: [] },
      resultatData: { labels: [], series: [] },
      resumeData: null,
    };
  },
  computed: {
    palette() {
      return PALETTES[this.theme] || PALETTES.light;
    },
    baseScales() {
      const p = this.palette;
      return {
        x: { grid: { display: false }, border: { display: false }, ticks: { color: p.text } },
        y: {
          beginAtZero: true,
          grid: { color: p.grid },
          border: { display: false },
          ticks: { color: p.text, callback: formatAxis },
        },
      };
    },
    tooltip() {
      return {
        mode: 'index',
        intersect: false,
        padding: 10,
        cornerRadius: 8,
        titleFont: { weight: '600' },
        callbacks: { label: ctx => ` ${ctx.dataset.label || ctx.label} : ${Number(ctx.parsed.y ?? ctx.parsed).toLocaleString('fr-FR')} Ar` },
      };
    },
    lineOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: this.tooltip },
        scales: this.baseScales,
      };
    },
    barOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: this.tooltip },
        scales: this.baseScales,
      };
    },
    pieOptions() {
      const p = this.palette;
      return {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { position: 'right', labels: { color: p.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 14 } },
          tooltip: { ...this.tooltip, mode: 'nearest', callbacks: { label: ctx => ` ${ctx.label} : ${Number(ctx.parsed).toLocaleString('fr-FR')} Ar` } },
        },
      };
    },
    caChartData() {
      const p = this.palette;
      return {
        labels: this.caData.labels,
        datasets: [{
          label: "Chiffre d'affaires",
          data: this.caData.series,
          borderColor: p.blue,
          backgroundColor: p.blueFill,
          borderWidth: 2,
          tension: 0.35,
          fill: true,
          pointRadius: 0,
          pointHoverRadius: 5,
        }],
      };
    },
    tresorerieChartData() {
      const p = this.palette;
      return {
        labels: this.tresorerieData.labels,
        datasets: [{
          label: 'Trésorerie',
          data: this.tresorerieData.series,
          borderColor: p.green,
          backgroundColor: p.greenFill,
          borderWidth: 2,
          tension: 0.35,
          fill: true,
          pointRadius: 0,
          pointHoverRadius: 5,
        }],
      };
    },
    bilanChartData() {
      const p = this.palette;
      return {
        labels: this.bilanData.labels,
        datasets: [{ data: this.bilanData.series, backgroundColor: p.pie, borderColor: p.surface, borderWidth: 2 }],
      };
    },
    resultatChartData() {
      const p = this.palette;
      return {
        labels: this.resultatData.labels,
        datasets: [{
          label: 'Montant',
          data: this.resultatData.series,
          backgroundColor: this.resultatData.series.map((_, i) => p.bars[i % p.bars.length]),
          borderRadius: 6,
          maxBarThickness: 48,
        }],
      };
    },
    exercicesFiltrés() {
      if (!Array.isArray(this.exercices) || !this.exercices.length || !this.exerciceCourant) return [];
      const index = this.exercices.findIndex(
        e => e.Id_Exercice_comptable === this.exerciceCourant.Id_Exercice_comptable
      );
      if (index === -1) return this.exercices.slice(0, 7);
      const debut = Math.max(0, index - 3);
      const fin = Math.min(this.exercices.length, index + 4);
      return this.exercices.slice(debut, fin);
    }
  },
  async mounted() {
    try {
      const token = localStorage.getItem("token");
      if (token) {
        const res = await getUser(token);
        this.user = res.data;

        await this.loadExercices();
        await this.loadExerciceCourant();

        if (this.exerciceCourant && Array.isArray(this.exercices) && this.exercices.length) {
          this.selectedExercice = this.exerciceCourant.Id_Exercice_comptable;
          await this.changeExercice();
        }
      } else {
        this.$router.push("/");
      }
    } catch (err) {
      console.error(err.response?.data);
      this.$router.push("/");
    }
  },
  methods: {
    async loadExercices() {
      try {
        const res = await axios.get(`${API_BASE}/exercices`);
        this.exercices = Array.isArray(res.data.exercices)
          ? res.data.exercices
          : Array.isArray(res.data) ? res.data : [];
        if (this.exercices.length) {
          this.selectedExercice = this.exercices[0].Id_Exercice_comptable;
        }
      } catch (error) {
        console.error('Erreur chargement exercices:', error);
        this.exercices = [];
      }
    },
    async changeExercice() {
      const selected = this.exercices.find(e => e.Id_Exercice_comptable === this.selectedExercice);
      this.exerciceCourant = selected || null;
      if (this.exerciceCourant) {
        await this.loadDashboardData();
      }
    },
    async loadExerciceCourant() {
      try {
        const res = await axios.get(`${API_BASE}/exercices/courant`);
        this.exerciceCourant = res.data.exercice;
      } catch (error) {
        console.error('Erreur de chargement de l\'exercice courant:', error);
        this.exerciceCourant = null;
      }
    },
    async loadDashboardData() {
      if (!this.exerciceCourant) return;
      try {
        const date_debut = this.exerciceCourant.Date_debut.slice(0, 10);
        const date_fin = this.exerciceCourant.Date_fin.slice(0, 10);

        // Les 5 appels sont indépendants : on les lance en parallèle.
        const [resResume, resCA, resTres, resBil, resRes] = await Promise.all([
          axios.get(`${API_BASE}/dashboard/resume`, { params: { date_debut, date_fin } }),
          axios.get(`${API_BASE}/dashboard/evolution-ca`, { params: { date_debut, date_fin } }),
          axios.get(`${API_BASE}/dashboard/evolution-tresorerie`, { params: { date_debut, date_fin } }),
          axios.get(`${API_BASE}/dashboard/composition-bilan`, { params: { date_fin } }),
          axios.get(`${API_BASE}/dashboard/decomposition-resultat`, { params: { date_fin } }),
        ]);
        this.resumeData = resResume.data;
        this.caData = this.extractLabelsAndSeries(resCA.data, 'mois', 'montant');
        this.tresorerieData = this.extractLabelsAndSeries(resTres.data, 'mois', 'montant');
        this.bilanData = this.extractLabelsAndSeries(resBil.data, 'poste', 'montant');
        this.resultatData = this.extractLabelsAndSeries(resRes.data, 'etape', 'montant');
      } catch (error) {
        console.error('Erreur de chargement des données:', error);
      }
    },
    extractLabelsAndSeries(items, labelKey, valueKey) {
      const moisNoms = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
      if (labelKey === 'mois' && this.exerciceCourant && this.exerciceCourant.Date_debut) {
        const dateDebut = new Date(this.exerciceCourant.Date_debut);
        const dateFin = new Date(this.exerciceCourant.Date_fin);
        const moisDebut = dateDebut.getMonth() + 1;
        const moisFin = dateFin.getMonth() + 1;
        const dataMap = {};
        items.forEach(item => {
          dataMap[parseInt(item[labelKey])] = parseFloat(item[valueKey]) || 0;
        });
        const labels = [];
        const series = [];
        const push = m => { labels.push(moisNoms[m - 1]); series.push(dataMap[m] || 0); };
        if (moisDebut <= moisFin) {
          for (let m = moisDebut; m <= moisFin; m++) push(m);
        } else {
          for (let m = moisDebut; m <= 12; m++) push(m);
          for (let m = 1; m <= moisFin; m++) push(m);
        }
        return { labels, series };
      }
      return {
        labels: items.map(i => i[labelKey] ?? i.etape ?? i.poste),
        series: items.map(i => parseFloat(i[valueKey]) || 0)
      };
    },
    formatDate(dateString) {
      if (!dateString) return '';
      return new Date(dateString).toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' });
    },
    formatMontant(value) {
      if (value === null || value === undefined) return '–';
      return Number(value).toLocaleString('fr-FR', { maximumFractionDigits: 0 });
    }
  }
}
</script>

<style scoped>
.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
.db-exercice .fs-select { width: auto; min-width: 170px; }

.db-kpis {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
  margin-bottom: 20px;
}
.db-kpi {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 20px;
  text-decoration: none;
  color: inherit;
}
.db-kpi--link { transition: border-color 0.15s ease, transform 0.15s ease; }
.db-kpi--link:hover { border-color: var(--fs-primary); transform: translateY(-1px); }
.db-kpi--link:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.db-kpi__icon {
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  font-size: 20px;
}
.db-kpi__icon--blue { background: var(--fs-primary-soft); color: var(--fs-primary-text); }
.db-kpi__icon--amber { background: var(--fs-warning-soft); color: var(--fs-warning); }
.db-kpi__icon--green { background: var(--fs-success-soft); color: var(--fs-success); }
.db-kpi__label { margin: 0; font-size: 13px; font-weight: 500; color: var(--fs-muted); }
.db-kpi__value {
  margin: 4px 0 2px;
  font-size: 28px;
  line-height: 1.15;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--fs-text);
  font-variant-numeric: tabular-nums;
}
.db-kpi__hint { margin: 0; font-size: 12.5px; color: var(--fs-muted); }
.db-kpi--link .db-kpi__hint { color: var(--fs-primary-text); }

.db-charts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}
.db-chart { display: flex; flex-direction: column; min-width: 0; }
.db-chart__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px 0;
}
.db-chart__head h2 { margin: 0; font-size: 15px; font-weight: 600; color: var(--fs-text); }
.db-chart__head span { font-size: 12.5px; color: var(--fs-muted); }
.db-chart__body { position: relative; height: 280px; padding: 16px 20px 20px; }

@media (max-width: 1100px) {
  .db-charts { grid-template-columns: 1fr; }
}
@media (max-width: 860px) {
  .db-kpis { grid-template-columns: 1fr; }
}
</style>
