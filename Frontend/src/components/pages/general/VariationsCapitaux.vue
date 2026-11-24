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
          <th class="text-right">Primes &amp; réserves</th>
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
import html2pdf from 'html2pdf.js';
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
const logoBase64 = ref(null);
async function fetchImageAsBase64(url) {
  const response = await fetch(url);
  const blob = await response.blob();
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

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
  logoBase64.value = await fetchImageAsBase64("/01Raitra kidz 300px.png");
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
    alert("Erreur lors du chargement des données. Vérifiez que vous avez bien 3 exercices consécutifs.");
  } finally {
    loading.value = false;
  }
};

// ================= MODIFICATION ICI (SUPPRESSION DU SIGNE MOINS) =================
const formatMontant = n => {
  if (n === null || n === undefined) return "";
  // Math.abs() transforme le nombre en positif (enlève le signe moins)
  return Math.abs(Number(n)).toLocaleString("fr-FR", { minimumFractionDigits: 2 });
};

const formatDate = d => {
  if (!d) return "";
  const date = new Date(d);
  return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "long", year: "numeric" });
};


// ================= EXPORT PDF =================
const exportToPDF = () => {
  if (!listeComplete.value.length) {
    alert("Aucune donnée à exporter !");
    return;
  }
  const now = new Date();

  const header = `
    <div style="display: flex; align-items: flex-start; border-bottom: 3px solid #2980b9; padding-bottom: 11px; margin-bottom: 8px;">
      <div style="flex: 0 0 70px;">
        <img src="${logoBase64.value || ''}" style="width:58px; height:auto;" />
      </div>
      <div style="flex:1; padding-left:10px;">
        <div style="font-size:10px; color:#555;">
          <div style="font-weight:bold; font-size:12px; color:#2c3e50;">RAITRA KIDZ</div>
          <div>Antananarivo, Madagascar</div>
          <div>+261 XX XX XXX XX</div>
        </div>
      </div>
      <div style="flex:2;text-align:center;">
        <h1 style="font-size:18px; font-weight:700; color:#1c45bd;margin:0 0 5px 0;">Tableau des Variations des Capitaux Propres</h1>
        <div style="font-size:10px; margin:2px 0;">Exercice : ${exerciceInfo.value.annee_fiscale || ''}</div>
        <div style="font-size:9px;">Période : Du ${formatDate(exerciceInfo.value.date_debut)} au ${formatDate(exerciceInfo.value.date_fin)}</div>
        <div style="font-size:9px;">Statut : ${exerciceInfo.value.statut || '-'}</div>
        <div style="font-size:9px;">Unité monétaire : Ariary (Ar)</div>
      </div>
      <div style="flex:0 0 90px;text-align:right;font-size:8px;color:#555;">
        <div><strong>Date édition:</strong></div>
        <div>${now.toLocaleDateString('fr-FR')} ${now.toLocaleTimeString('fr-FR', { hour:'2-digit', minute:'2-digit' })}</div>
      </div>
    </div>`;

  const tableRows = listeComplete.value.map(l =>
    `<tr
      style="${l.isTotal ? 'background:#e0e7ff;font-weight:700;font-size:10px;color:#1e40af;' : 'font-size:9px;background:#f0f9ff;'}">
      <td style="padding:6px 4px;${l.isTotal?'font-weight:700;':''}border:1px solid #ddd;">
        ${l.label ?? ""}
      </td>
      <td style="padding:6px 3px;text-align:right;border:1px solid #ddd;${l.capital < 0 ? 'color:#dc2626;':''}">${formatMontant(l.capital)}</td>
      <td style="padding:6px 3px;text-align:right;border:1px solid #ddd;${l.reserves < 0 ? 'color:#dc2626;':''}">${formatMontant(l.reserves)}</td>
      <td style="padding:6px 3px;text-align:right;border:1px solid #ddd;${l.result < 0 ? 'color:#dc2626;':''}">${formatMontant(l.result)}</td>
      <td style="padding:6px 3px;text-align:right;border:1px solid #ddd;${l.report < 0 ? 'color:#dc2626;':''}">${formatMontant(l.report)}</td>
      <td style="padding:6px 3px;text-align:right;border:1px solid #ddd;${l.isTotal?'font-weight:700;':''}${l.total < 0 ? 'color:#dc2626;':''}">${formatMontant(l.total)}</td>
    </tr>`
  ).join('');

  const htmlContent = `
    <div style="font-family:'Manrope',sans-serif;max-width:1100px;margin:auto;">
      ${header}
      <table style="width:1040px;max-width:100%;border-collapse:collapse;margin:10px auto 0 auto;">
        <thead>
          <tr style="background:linear-gradient(135deg,#2980b9 0%,#1e40af 100%);color:#fff;">
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;">CAPITAUX PROPRES</th>
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;text-align:right;">Capital</th>
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;text-align:right;">Primes & Réserves</th>
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;text-align:right;">Résultat</th>
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;text-align:right;">Report à nouveau</th>
            <th style="padding:8px 4px;border:1px solid #1e40af;font-size:10px;text-align:right;">Total</th>
          </tr>
        </thead>
        <tbody>
          ${tableRows}
        </tbody>
      </table>
      <div style="margin-top:13px;text-align:center;font-size:8.5px;color:#888;">
        RAITRA KIDZ © ${now.getFullYear()} | Tableau des Variations - Export PDF
      </div>
    </div>
  `;

  const element = document.createElement('div');
  element.innerHTML = htmlContent;
  element.style.width = '1040px';
  element.style.margin = '0 auto';
  document.body.appendChild(element);

  html2pdf()
    .set({
      margin: [8, 5, 14, 5],
      filename: `variations_capitaux_propres_${now.toISOString().split("T")[0]}.pdf`,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    })
    .from(element)
    .save()
    .then(() => document.body.removeChild(element))
    .catch(err => {
      document.body.removeChild(element);
      alert("Erreur lors de la génération du PDF.");
      console.error(err);
    });
};


// ================= EXPORT EXCEL (MODIFICATION SIGNE) =================
const exportToExcel = () => {
  if (!listeComplete.value.length) return alert("Aucune donnée à exporter !");
  const now = new Date();
  const etablissement = {
    nom: "RAITRA KIDZ",
    adresse: "Antananarivo, Madagascar",
    tel: "+261 XX XX XXX XX",
    email: "contact@raitrakidz.mg"
  };

  const titre = [[`TABLEAU DES VARIATIONS DES CAPITAUX PROPRES – RAITRA KIDZ`]];
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
            ws[cell].v = Math.abs(ws[cell].v); // Le nombre devient positif dans Excel
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
