<template>
  <FinancialStatementLayout
    :user="user"
    icon="bi-graph-up-arrow"
    title="Variation des capitaux propres"
    subtitle="Évolution du capital, des réserves, du résultat et du report à nouveau"
    table-title="Capitaux propres"
    :exercice-options="exercices.map(ex => ({ value: ex.Id_Exercice_comptable, label: `Exercice ${ex.Annee_fiscale}` }))"
    v-model:exercice="selectedExercice"
    :exercice-info="exerciceInfo"
    :loading="loading"
    @exercice-change="onExerciceChange"
    @export-pdf="exportToPDF"
    @export-excel="exportToExcel"
    @back="goBack"
  >
    <table class="fs-table">
      <thead>
        <tr>
          <th>Capitaux propres</th>
          <th class="text-right">Capital</th>
          <th class="text-right">Primes, réserves et écarts</th>
          <th class="text-right">Résultat</th>
          <th class="text-right">Report à nouveau</th>
          <th class="text-right">Total</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(ligne, idx) in listeComplete"
          :key="idx"
          :class="{ 'total-row': ligne.isTotal, 'detail-row': ligne.isDetail }"
        >
          <td>{{ ligne.label }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.capital < 0 }">{{ formatMontant(ligne.capital) }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.reserves < 0 }">{{ formatMontant(ligne.reserves) }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.result < 0 }">{{ formatMontant(ligne.result) }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.report < 0 }">{{ formatMontant(ligne.report) }}</td>
          <td class="text-right font-bold" :class="{ 'text-red': ligne.total < 0 }">{{ formatMontant(ligne.total) }}</td>
        </tr>
        <tr v-if="!listeComplete.length">
          <td colspan="6" class="fs-empty">Aucune donnée pour cet exercice</td>
        </tr>
      </tbody>
    </table>
  </FinancialStatementLayout>
</template>

<script setup>
import { API_BASE } from '@/config/api';
import FinancialStatementLayout from "@/components/template/FinancialStatementLayout.vue";
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { exporterEtatPdf, infosExercice } from '@/utils/exportEtatPdf';
import { parametres } from '@/stores/parametres';
import * as XLSX from 'xlsx-js-style';
import { getUser } from "../../../services/Auth";

const router = useRouter();
const user = ref(null);
const showChat = ref(false);
const goBack = () => { router.push("/journal"); };
const handleNavigation = item => { router.push(item.route); };

const loading = ref(false);
const listeComplete = ref([]);
const exerciceInfo = ref({
  date_debut: "",
  date_fin: "",
  annee_fiscale: "",
  statut: ""
});

const exercices = ref([]);
const selectedExercice = ref("");
const exerciceCourantId = ref("");

// ------------ LOGO PDF ------------

const token = localStorage.getItem("token");
if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const fetchExerciceCourant = async () => {
  try {
    const { data } = await axios.get(`${API_BASE}/exercices/courant`);
    exerciceCourantId.value = data.Id_Exercice_comptable ?? (data.exercice?.Id_Exercice_comptable) ?? "";
  } catch (e) {
    exerciceCourantId.value = "";
  }
};

const loadExercices = async () => {
  try {
    const { data } = await axios.get(`${API_BASE}/exercices`);
    exercices.value = Array.isArray(data) ? data : (data.exercices || []);
  } catch (error) {
    exercices.value = [];
  }
};

const onExerciceChange = () => {
  const ex = exercices.value.find(e => e.Id_Exercice_comptable == selectedExercice.value);
  if (!ex) return;
  exerciceInfo.value.date_debut    = ex.Date_debut;
  exerciceInfo.value.date_fin      = ex.Date_fin;
  exerciceInfo.value.annee_fiscale = ex.Annee_fiscale;
  exerciceInfo.value.statut        = ex.Statut;
  fetchVariationsCapitaux();
};

onMounted(async () => {
  if (!token) {
    window.location.href = "/";
  } else {
    axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
    try {
      const res = await getUser(token);
      user.value = res.data;
    } catch (err) {
      localStorage.removeItem("token");
      window.location.href = "/";
      return;
    }
    await fetchExerciceCourant();
    await loadExercices();
    if (exercices.value.length) {
      const courant = exercices.value.find(e => e.Id_Exercice_comptable == exerciceCourantId.value);
      selectedExercice.value = courant
        ? courant.Id_Exercice_comptable
        : exercices.value[0].Id_Exercice_comptable;
      onExerciceChange();
    }
  }
});

const fetchVariationsCapitaux = async () => {
  loading.value = true;
  try {
    if (exerciceInfo.value.date_debut && exerciceInfo.value.date_fin) {
      const { data } = await axios.get(`${API_BASE}/variations-capitaux`, {
        params: { date_debut: exerciceInfo.value.date_debut, date_fin: exerciceInfo.value.date_fin }
      });
      listeComplete.value = Array.isArray(data) ? data : (data.structure || []);
    }
  } catch (error) {
    console.error("Erreur chargement variations capitaux:", error);
    alert("Erreur lors du chargement des données.");
  } finally {
    loading.value = false;
  }
};

// ================= MODIFICATION ICI (SUPPRESSION DU SIGNE MOINS) =================
const formatMontant = n => {
  if (n === null || n === undefined) return "";
  // montants affichés avec leur signe
  return Number(n).toLocaleString("fr-FR", { minimumFractionDigits: 2 });
};

const formatDate = d => {
  if (!d) return "";
  const date = new Date(d);
  return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "long", year: "numeric" });
};


// ================= EXPORT PDF =================
const exportToPDF = () => {
  if (!listeComplete.value.length) { alert("Aucune donnée à exporter !"); return; }
  const m = v => ({ v: formatMontant(v), negatif: v < 0 });
  return exporterEtatPdf({
    titre: 'Tableau de variation des capitaux propres',
    infos: infosExercice(exerciceInfo.value, formatDate),
    entetes: [[{ label: 'Capitaux propres' }, { label: 'Capital', align: 'right' }, { label: 'Primes et réserves', align: 'right' },
      { label: 'Résultat', align: 'right' }, { label: 'Report à nouveau', align: 'right' }, { label: 'Total', align: 'right' }]],
    lignes: listeComplete.value.map(l => ({
      type: l.isTotal ? 'total' : 'detail',
      cellules: [l.label ?? '', m(l.capital), m(l.reserves), m(l.result), m(l.report), { ...m(l.total), gras: true }],
    })),
    fichier: 'Variation_Capitaux_Propres',
  });
};


// ================= EXPORT EXCEL (MODIFICATION SIGNE) =================
const exportToExcel = async () => {
  if (!listeComplete.value.length) return alert("Aucune donnée à exporter !");
  const now = new Date();
  const etablissement = (await parametres()).societe;

  const titre = [[`TABLEAU DES VARIATIONS DES CAPITAUX PROPRES – ${etablissement.nom}`]];
  const info = [
    [etablissement.nom],
    [etablissement.adresse],
    [etablissement.tel],
    [etablissement.email],
    [''],
    [`Exercice : ${exerciceInfo.value.annee_fiscale || ''}`],
    [`Période : Du ${formatDate(exerciceInfo.value.date_debut)} au ${formatDate(exerciceInfo.value.date_fin)}`],
    [`Statut : ${exerciceInfo.value.statut || "-"}`],
    [`Unité monétaire : Ariary (Ar)`],
    [`Date édition : ${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'})}`],
    ['']
  ];

  const headers = [
    ["CAPITAUX PROPRES", "Capital", "Primes & Réserves", "Résultat", "Report à nouveau", "Total"]
  ];

  const dataRows = listeComplete.value.map(l => [
    l.label,
    l.capital ?? 0,
    l.reserves ?? 0,
    l.result ?? 0,
    l.report ?? 0,
    l.total ?? 0
  ]);

  const ws = XLSX.utils.aoa_to_sheet([]);
  XLSX.utils.sheet_add_aoa(ws, titre, { origin: 'A1' });
  info.forEach((val, i) => XLSX.utils.sheet_add_aoa(ws, [val], { origin: `A${i+2}` }));
  XLSX.utils.sheet_add_aoa(ws, headers, { origin: 'A14' });
  XLSX.utils.sheet_add_aoa(ws, dataRows, { origin: 'A15' });

  ws['!cols'] = [
    { wch: 30 }, { wch: 18 }, { wch: 20 }, { wch: 18 }, { wch: 20 }, { wch: 22 }
  ];

  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 5 } },
    ...[1,2,3,4,5,6,7,8,9,10,11,12,13].map(i => ({ s: { r: i, c: 0 }, e: { r: i, c: 5 } }))
  ];

  ws['A1'].s = {
    font: { bold: true, sz: 18, color: { rgb: "1C45BD" } },
    alignment: { horizontal: "center", vertical: "center" }
  };
  for (let i = 2; i <= 5; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 11, bold: (i==2), color: { rgb: "222831" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }
  for (let i = 6; i <= 13; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 10, color: { rgb: "555555" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }

  ['A14','B14','C14','D14','E14','F14'].forEach(cell => {
    if (ws[cell]) ws[cell].s = {
      font: { bold: true, sz: 12, color: { rgb: "FFFFFF" } },
      fill: { fgColor: { rgb: "1C45BD" } },
      alignment: { horizontal: "center", vertical: "center" },
      border: {
        top:    { style: "thick", color: { rgb: "1C45BD" } },
        left:   { style: "thin", color: { rgb: "1C45BD" } },
        right:  { style: "thin", color: { rgb: "1C45BD" } },
        bottom: { style: "thick", color: { rgb: "1C45BD" } }
      }
    };
  });

  const firstDataRow = 15;
  for (let i = 0; i < dataRows.length; ++i) {
    const rowIdx = firstDataRow + i;
    const ligne = listeComplete.value[i];
    ['A','B','C','D','E','F'].forEach((col, j) => {
      const cell = `${col}${rowIdx}`;
      if (!ws[cell]) return;
      if (ligne.isTotal) {
        ws[cell].s = {
          font: { bold: true, sz: 12, color: { rgb: "1e40af" } },
          fill: { fgColor: { rgb: "e0e7ff" } },
          alignment: { horizontal: "center", vertical: "center" },
          border: {
            top:    { style: "medium", color: { rgb: "3b82f6" } },
            left:   { style: "thin", color: { rgb: "3b82f6" } },
            right:  { style: "thin", color: { rgb: "3b82f6" } },
            bottom: { style: "medium", color: { rgb: "3b82f6" } }
          }
        };
      } else {
        ws[cell].s = {
          font: { sz: 10 },
          alignment: { horizontal: "left", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "e5e7eb" } },
            left:   { style: "thin", color: { rgb: "e5e7eb" } },
            right:  { style: "thin", color: { rgb: "e5e7eb" } },
            bottom: { style: "thin", color: { rgb: "e5e7eb" } }
          }
        };
        // Format nombre pour colonnes numériques
        if (j >= 1 && ws[cell].v !== '') {
          ws[cell].z = "#,##0.00";
          // Couleur rouge si négatif ET ON ENLÈVE LE SIGNE DANS LA CELLULE
          if(ws[cell].v < 0) {
            ws[cell].s.font.color = { rgb: "dc2626" };
            // montants signés conservés (une perte reste négative)
          }
        }
      }
    });
  }

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Variations_Capitaux");
  XLSX.writeFile(wb, `variations_capitaux_propres_${now.toISOString().split("T")[0]}.xlsx`);
};

</script>
