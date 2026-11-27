<template>
  <FinancialStatementLayout
    :user="user"
    icon="bi-file-earmark-bar-graph"
    title="Bilan — Passif"
    subtitle="Capitaux propres et dettes de l'établissement"
    table-title="Passif et capitaux propres"
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
          <th>Passif et capitaux propres</th>
          <th class="text-center">Note</th>
          <th class="text-right">N</th>
          <th class="text-right">N-1</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(ligne, idx) in listeComplete"
          :key="idx"
          :class="{
            'title-row': ligne.isTitle,
            'subtitle-row': ligne.isSubtitle,
            'total-row': ligne.isTotal,
            'subtotal-row': ligne.isSubtotal,
            'detail-row': !ligne.isTitle && !ligne.isSubtitle && !ligne.isTotal && !ligne.isSubtotal
          }"
        >
          <td :class="{ 'pl-4': ligne.isSubtitle, 'pl-8': !ligne.isTitle && !ligne.isSubtitle && !ligne.isTotal && !ligne.isSubtotal }">{{ ligne.label }}</td>
          <td class="text-center note">{{ ligne.note || "" }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.montantN < 0 }">{{ formatMontant(ligne.montantN) }}</td>
          <td class="text-right" :class="{ 'text-red': ligne.montantN1 < 0 }">{{ formatMontant(ligne.montantN1) }}</td>
        </tr>
        <tr v-if="!listeComplete.length">
          <td colspan="4" class="fs-empty">Aucune donnée pour cet exercice</td>
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
import { exporterEtatPdf, infosExercice, typeLigne } from '@/utils/exportEtatPdf';
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
  date_debut_n1: "",
  date_fin_n1: "",
  annee_fiscale: "",
  statut: ""
});


const exercices = ref([]);
const selectedExercice = ref("");
const exerciceCourantId = ref("");


// ---------- LOGO ----------


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
  // Cherche le suivant (N-1)
  const idx = exercices.value.findIndex(e => e.Id_Exercice_comptable == ex.Id_Exercice_comptable);
  const exN1 = exercices.value[idx + 1];
  if (exN1) {
    exerciceInfo.value.date_debut_n1 = exN1.Date_debut;
    exerciceInfo.value.date_fin_n1   = exN1.Date_fin;
  } else {
    exerciceInfo.value.date_debut_n1 = "";
    exerciceInfo.value.date_fin_n1   = "";
  }
  fetchBilanPassif();
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


const fetchBilanPassif = async () => {
  loading.value = true;
  let resN = [], resN1 = [];
  try {
    if (exerciceInfo.value.date_debut && exerciceInfo.value.date_fin) {
      const { data } = await axios.get(`${API_BASE}/bilan/passif`, {
        params: { date_debut: exerciceInfo.value.date_debut, date_fin: exerciceInfo.value.date_fin }
      });
      resN = data;
    }
    if (exerciceInfo.value.date_debut_n1 && exerciceInfo.value.date_fin_n1) {
      const { data } = await axios.get(`${API_BASE}/bilan/passif`, {
        params: { date_debut: exerciceInfo.value.date_debut_n1, date_fin: exerciceInfo.value.date_fin_n1 }
      });
      resN1 = data;
    }
    listeComplete.value = resN.map((ligneN, idx) => ({
      label: ligneN.label,
      note: ligneN.note || "",
      montantN: ligneN.montant,
      montantN1: resN1[idx] ? resN1[idx].montant : null,
      isTitle: ligneN.isTitle || false,
      isSubtitle: ligneN.isSubtitle || false,
      isTotal: ligneN.isTotal || false,
      isSubtotal: ligneN.isSubtotal || false
    }));
  } catch (error) {
    console.error("Erreur chargement bilan passif:", error);
    alert("Erreur lors du chargement des données");
  } finally {
    loading.value = false;
  }
};


// Affiche la valeur absolue (comme dans le compte de résultat)
const formatMontant = n => {
  if (n === null || n === undefined) return "";
  return Number(n).toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};


const formatDate = d => {
  if (!d) return "";
  const date = new Date(d);
  return date.toLocaleDateString("fr-FR", { day: "2-digit", month: "long", year: "numeric" });
};


// ------ EXPORT PDF AVEC LOGO & STYLES ------
const exportToPDF = () => {
  if (!listeComplete.value.length) { alert("Aucune donnée à exporter !"); return; }
  return exporterEtatPdf({
    titre: 'Bilan — Passif et capitaux propres',
    infos: infosExercice(exerciceInfo.value, formatDate),
    entetes: [[{ label: 'Passif et capitaux propres' }, { label: 'Note', align: 'center' }, { label: 'N', align: 'right' }, { label: 'N-1', align: 'right' }]],
    alignements: ['left', 'center'],
    lignes: listeComplete.value.map(l => ({
      type: typeLigne(l),
      cellules: [l.label, l.note || '', { v: formatMontant(l.montantN), negatif: l.montantN < 0 }, { v: formatMontant(l.montantN1), negatif: l.montantN1 < 0 }],
    })),
    fichier: 'Bilan_Passif',
  });
};


// ------ EXPORT EXCEL STYLE PRO ------
const exportToExcel = async () => {
  if (!listeComplete.value.length) {
    alert("Aucune donnée à exporter !");
    return;
  }

  const now = new Date();
  const etablissement = (await parametres()).societe;

  const titre = [[`BILAN PASSIF ET CAPITAUX PROPRES – ${etablissement.nom}`]];
  const info = [
    [etablissement.nom],
    [etablissement.adresse],
    [etablissement.tel],
    [etablissement.email],
    [''],
    [`Exercice : ${exerciceInfo.value.annee_fiscale || ''}`],
    [`Période : Du ${formatDate(exerciceInfo.value.date_debut)} au ${formatDate(exerciceInfo.value.date_fin)}`],
    [`Statut : ${exerciceInfo.value.statut || "-"}`],
    [`Date édition : ${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'})}`],
    ['']
  ];

  const headers = [
    ['PASSIF ET CAPITAUX PROPRES', 'NOTE', 'N', 'N-1']
  ];

  const dataRows = listeComplete.value.map(l => [
    l.label,
    l.note || "",
    l.montantN !== null && l.montantN !== undefined ? parseFloat(l.montantN) : '',
    l.montantN1 !== null && l.montantN1 !== undefined ? parseFloat(l.montantN1) : ''
  ]);

  const ws = XLSX.utils.aoa_to_sheet([]);
  XLSX.utils.sheet_add_aoa(ws, titre, { origin: 'A1' });
  info.forEach((val, i) => XLSX.utils.sheet_add_aoa(ws, [val], { origin: `A${i+2}` }));
  XLSX.utils.sheet_add_aoa(ws, headers, { origin: 'A13' });
  XLSX.utils.sheet_add_aoa(ws, dataRows, { origin: 'A14' });

  ws['!cols'] = [
    { wch: 45 },
    { wch: 12 },
    { wch: 18 },
    { wch: 18 }
  ];

  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 3 } },
    ...[1,2,3,4,5,6,7,8,9,10,11,12].map(i => ({ s: { r: i, c: 0 }, e: { r: i, c: 3 } }))
  ];

  ws['A1'].s = {
    font: { bold: true, sz: 20, color: { rgb: "1C45BD" } },
    alignment: { horizontal: "center", vertical: "center" }
  };

  for (let i = 2; i <= 5; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 11, bold: (i==2), color: { rgb: "222831" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }

  for (let i = 6; i <= 12; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 10, color: { rgb: "555555" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }

  ['A13', 'B13', 'C13', 'D13'].forEach(cell => {
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

  // Détection des négatifs pour Excel
  const firstDataRow = 14;
  for (let i = 0; i < dataRows.length; ++i) {
    const rowIdx = firstDataRow + i;
    const ligne = listeComplete.value[i];
    
    ['A','B','C','D'].forEach((col, j) => {
      const cell = `${col}${rowIdx}`;
      if (!ws[cell]) return;

      const isNegative = (j === 2 && ligne.montantN < 0) || (j === 3 && ligne.montantN1 < 0);

      if (ligne.isTitle) {
        ws[cell].s = {
          font: { bold: true, sz: 13, color: { rgb: isNegative ? "dc2626" : "1e40af" } },
          fill: { fgColor: { rgb: "dbeafe" } },
          alignment: { horizontal: j===0?"left":"center", vertical: "center" },
          border: {
            top:    { style: "medium", color: { rgb: "3b82f6" } },
            left:   { style: "thin", color: { rgb: "1C45BD" } },
            right:  { style: "thin", color: { rgb: "1C45BD" } },
            bottom: { style: "thin", color: { rgb: "1C45BD" } }
          }
        };
      }
      else if (ligne.isTotal) {
        ws[cell].s = {
          font: { bold: true, sz: 12, color: { rgb: isNegative ? "dc2626" : "1e40af" } },
          fill: { fgColor: { rgb: "e0e7ff" } },
          alignment: { horizontal: j===0?"left":"center", vertical: "center" },
          border: {
            top:    { style: "medium", color: { rgb: "3b82f6" } },
            left:   { style: "thin", color: { rgb: "1C45BD" } },
            right:  { style: "thin", color: { rgb: "1C45BD" } },
            bottom: { style: "medium", color: { rgb: "3b82f6" } }
          }
        };
      }
      else if (ligne.isSubtitle) {
        ws[cell].s = {
          font: { sz: 11, italic: true, color: { rgb: isNegative ? "dc2626" : "0369a1" } },
          fill: { fgColor: { rgb: "f0f9ff" } },
          alignment: { horizontal: j===0?"left":"center", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "b0b0b0" } },
            left:   { style: "thin", color: { rgb: "b0b0b0" } },
            right:  { style: "thin", color: { rgb: "b0b0b0" } },
            bottom: { style: "thin", color: { rgb: "b0b0b0" } }
          }
        };
      }
      else if (ligne.isSubtotal) {
        ws[cell].s = {
          font: { sz: 11, bold: true, color: { rgb: isNegative ? "dc2626" : "5b21b6" } },
          fill: { fgColor: { rgb: "ede9fe" } },
          alignment: { horizontal: j===0?"left":"center", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "b0b0b0" } },
            left:   { style: "thin", color: { rgb: "b0b0b0" } },
            right:  { style: "thin", color: { rgb: "b0b0b0" } },
            bottom: { style: "thin", color: { rgb: "b0b0b0" } }
          }
        };
      }
      else {
        ws[cell].s = {
          font: { sz: 11, color: { rgb: isNegative ? "dc2626" : "000000" } },
          alignment: { horizontal: j===0?"left":"right", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "e5e7eb" } },
            left:   { style: "thin", color: { rgb: "e5e7eb" } },
            right:  { style: "thin", color: { rgb: "e5e7eb" } },
            bottom: { style: "thin", color: { rgb: "e5e7eb" } }
          }
        };
        if (j >= 2 && ws[cell].v !== '') {
          ws[cell].z = "#,##0.00";
        }
      }
    });
  }

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Bilan Passif");
  XLSX.writeFile(wb, `bilan_passif_${now.toISOString().split("T")[0]}.xlsx`);
};

</script>
