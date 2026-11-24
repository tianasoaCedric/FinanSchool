<template>
  <FinancialStatementLayout
    :user="user"
    icon="bi-table"
    title="Balance générale"
    subtitle="Soldes débiteurs et créditeurs de tous les comptes, par classe"
    table-title="Comptes"
    :exercice-options="exercices.map(ex => ({ value: ex.id, label: `Exercice ${ex.Annee_fiscale}` }))"
    v-model:exercice="selectedExercice"
    :exercice-info="exerciceInfo"
    :loading="loading"
    @exercice-change="onExerciceChange"
    @export-pdf="exportToPDF"
    @export-excel="exportToExcel"
    @back="goBack"
  >
    <template #filters>
      <form class="fs-filters" @submit.prevent="applyFilters">
        <div class="fs-field">
          <label class="fs-label" for="balance-classe">Classe de compte</label>
          <select id="balance-classe" v-model="filters.classe_compte" class="fs-select" @change="applyFilters">
            <option value="">Toutes les classes</option>
            <option value="1">Classe 1 — Capitaux</option>
            <option value="2">Classe 2 — Immobilisations</option>
            <option value="3">Classe 3 — Stocks</option>
            <option value="4">Classe 4 — Tiers</option>
            <option value="5">Classe 5 — Finances</option>
            <option value="6">Classe 6 — Charges</option>
            <option value="7">Classe 7 — Produits</option>
          </select>
        </div>
        <div class="fs-filters__actions">
          <button type="button" class="fs-btn" @click="resetFilters">
            <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser
          </button>
        </div>
      </form>
    </template>

    <template #table-hint>Cliquez sur une classe pour voir le détail</template>

    <table class="fs-table">
      <thead>
        <tr>
          <th>Compte</th>
          <th class="text-right">Débit</th>
          <th class="text-right">Crédit</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="(mainAccount, mainCode) in groupedComptes" :key="mainCode">
          <tr class="main-account-row">
            <td @click="toggleAccount(mainCode)">
              <i :class="mainAccount.expanded ? 'bi bi-chevron-down' : 'bi bi-chevron-right'"></i>
              Classe {{ mainCode }} — {{ mainAccount.libelle }}
            </td>
            <td class="text-right">{{ formatNumber(Math.abs(mainAccount.total_debit)) }}</td>
            <td class="text-right">{{ formatNumber(Math.abs(mainAccount.total_credit)) }}</td>
          </tr>
          <template v-if="mainAccount.expanded">
            <tr v-for="(subAccount, index) in mainAccount.subAccounts" :key="`${mainCode}-${index}`" class="sub-account-row">
              <td>{{ subAccount.code_sous_compte }} — {{ subAccount.libelle_sous_compte }}</td>
              <td class="text-right">{{ subAccount.solde_final >= 0 ? formatNumber(subAccount.solde_final) : '' }}</td>
              <td class="text-right">{{ subAccount.solde_final < 0 ? formatNumber(Math.abs(subAccount.solde_final)) : '' }}</td>
            </tr>
          </template>
        </template>
        <tr v-if="!Object.keys(groupedComptes).length">
          <td colspan="3" class="fs-empty">Aucune donnée disponible</td>
        </tr>
        <tr v-else class="total-row">
          <td>Totaux</td>
          <td class="text-right">{{ formatNumber(totalDebit) }}</td>
          <td class="text-right">{{ formatNumber(totalCredit) }}</td>
        </tr>
      </tbody>
    </table>
  </FinancialStatementLayout>
</template>

<script setup>
import { API_BASE } from '@/config/api';
import FinancialStatementLayout from "@/components/template/FinancialStatementLayout.vue";
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import html2pdf from 'html2pdf.js'
import { jsPDF } from "jspdf";
import autoTable from "jspdf-autotable";
import * as XLSX from 'xlsx-js-style';
import { getUser } from "../../../services/Auth";

const router = useRouter();
const user = ref(null);
const comptes = ref([]);
const showChat = ref(false);
const loading = ref(false);
const expandedAccounts = ref(new Set());
const exercices = ref([]);
const selectedExercice = ref("");
const filters = ref({
  date_debut: "",
  date_fin: "",
  classe_compte: "",
  exercice_comptable: "",
});

// LOGO EN BASE64
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

const getClasseLibelle = (classe) => ({
  '1': 'Capitaux',
  '2': 'Immobilisations',
  '3': 'Stocks',
  '4': 'Tiers',
  '5': 'Finances',
  '6': 'Charges',
  '7': 'Produits'
})[classe] || 'Classe inconnue';

const groupedComptes = computed(() => {
  const grouped = {};
  comptes.value.forEach((compte) => {
    const mainCode = compte.code_compte.substring(0, 1);
    if (!grouped[mainCode]) {
      grouped[mainCode] = {
        libelle: getClasseLibelle(mainCode),
        total_debit: 0,
        total_credit: 0,
        expanded: expandedAccounts.value.has(mainCode),
        subAccounts: []
      };
    }
    grouped[mainCode].subAccounts.push({
      code_sous_compte: compte.code_sous_compte,
      libelle_sous_compte: compte.libelle_sous_compte,
      solde_final: parseFloat(compte.solde_final) || 0
    });
    if ((parseFloat(compte.solde_final) || 0) >= 0) {
      grouped[mainCode].total_debit += parseFloat(compte.solde_final) || 0;
    } else {
      grouped[mainCode].total_credit += Math.abs(parseFloat(compte.solde_final)) || 0;
    }
  });
  return grouped;
});

// Infos de l'exercice sélectionné (période, statut) affichées dans l'en-tête
const exerciceInfo = computed(() => {
  const ex = exercices.value.find(e => e.id == selectedExercice.value);
  return ex ? { annee_fiscale: ex.Annee_fiscale, date_debut: ex.Date_debut, date_fin: ex.Date_fin, statut: ex.Statut } : null;
});

const totalDebit = computed(() =>
  Object.values(groupedComptes.value).reduce((sum, acc) => sum + (acc.total_debit || 0), 0)
);

const totalCredit = computed(() =>
  Object.values(groupedComptes.value).reduce((sum, acc) => sum + (acc.total_credit || 0), 0)
);

const toggleAccount = (accountCode) => {
  if (expandedAccounts.value.has(accountCode)) {
    expandedAccounts.value.delete(accountCode);
  } else {
    expandedAccounts.value.add(accountCode);
  }
};
const handleNavigation = (item) => { router.push(item.route); };
const goBack = () => { router.push("/journal"); };

const token = localStorage.getItem("token");
if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const formatNumber = (number) =>
  Number(number).toLocaleString('fr-FR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    useGrouping: true
  });

const fetchBalanceGenerale = async () => {
  try {
    loading.value = true;
    const queryParams = new URLSearchParams({
      ...(filters.value.date_debut && { date_debut: filters.value.date_debut.slice(0, 10) }),
      ...(filters.value.date_fin && { date_fin: filters.value.date_fin.slice(0, 10) }),
      ...(filters.value.classe_compte && { classe_compte: filters.value.classe_compte }),
      ...(filters.value.exercice_comptable && { exercice_comptable: filters.value.exercice_comptable }),
    }).toString();
    const res = await axios.get(`${API_BASE}/balance-generale?${queryParams}`);
    comptes.value = res.data;
  } catch (error) {
    comptes.value = [];
  } finally {
    loading.value = false;
  }
};

const fetchExercices = async () => {
  try {
    const res = await axios.get(`${API_BASE}/exercices`);
    exercices.value = res.data.map(e => ({
      id: e.Id_Exercice_comptable,
      nom: `${e.Annee_fiscale} | Du ${e.Date_debut.slice(0, 10)} au ${e.Date_fin.slice(0, 10)} | ${e.Statut}`,
      Statut: e.Statut,
      Date_debut: e.Date_debut,
      Date_fin: e.Date_fin,
      ...e
    }));
    // Sélectionne exercice courant (statut OUVERT), sinon le premier
    const courant = exercices.value.find(ex => ex.Statut === 'OUVERT') || exercices.value[0];
    if (courant) {
      selectedExercice.value = courant.id;
      onExerciceChange();
    }
  } catch (e) {
    exercices.value = [];
  }
};

const onExerciceChange = () => {
  const exercice = exercices.value.find(ex => ex.id == selectedExercice.value);
  if (exercice) {
    filters.value.exercice_comptable = exercice.id;
    filters.value.date_debut = exercice.Date_debut.slice(0, 10);
    filters.value.date_fin = exercice.Date_fin.slice(0, 10);
    fetchBalanceGenerale();
  }
};

const applyFilters = () => { fetchBalanceGenerale(); };
const resetFilters = () => {
  filters.value.classe_compte = "";
  if (exercices.value.length) {
    const courant = exercices.value.find(ex => ex.Statut === 'OUVERT') || exercices.value[0];
    selectedExercice.value = courant.id;
    onExerciceChange();
  } else {
    filters.value = {
      date_debut: "",
      date_fin: "",
      classe_compte: "",
      exercice_comptable: "",
    };
    fetchBalanceGenerale();
  }
};

const exportToExcel = async () => {
  if (!Object.keys(groupedComptes.value).length) {
    alert("Aucune donnée à exporter");
    return;
  }

  const now = new Date();
  // En-tête informations
  const title = [[`BALANCE GÉNÉRALE – RAITRA KIDZ`]];
  const etablissement = ["RAITRA KIDZ", "Antananarivo, Madagascar", "+261 XX XX XXX XX", "contact@raitrakidz.mg"];
  const info = [
    [`Édité le : ${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`],
    [`Période : ${filters.value.date_debut || "N/A"} à ${filters.value.date_fin || "N/A"}`],
    [`Exercice : ${(exercices.value.find(ex => ex.id == selectedExercice.value)?.nom) || "Tous"}`],
    [`Classe de compte : ${getClasseLibelle(filters.value.classe_compte) || "Toutes"}`]
  ];

  const emptyRow = [''];
  const headers = [
    ['Compte', 'Libellé', 'Débit', 'Crédit']
  ];

  // Construction des lignes du tableau
  const dataRows = [];
  Object.entries(groupedComptes.value).forEach(([mainCode, mainAccount]) => {
    // Compte principal
    dataRows.push([
      `${mainCode} - ${mainAccount.libelle}`,
      '',
      mainAccount.total_debit,
      mainAccount.total_credit
    ]);
    // Sous-comptes
    mainAccount.subAccounts.forEach(subAccount => {
      dataRows.push([
        subAccount.code_sous_compte,
        subAccount.libelle_sous_compte,
        subAccount.solde_final >= 0 ? subAccount.solde_final : '',
        subAccount.solde_final < 0 ? Math.abs(subAccount.solde_final) : ''
      ]);
    });
  });
  // Ligne des totaux
  dataRows.push([
    "TOTAUX",
    "",
    totalDebit.value,
    totalCredit.value
  ]);

  // Création de la feuille Excel
  const ws = XLSX.utils.aoa_to_sheet([]);
  XLSX.utils.sheet_add_aoa(ws, title, { origin: 'A1' });
  etablissement.forEach((val, i) => XLSX.utils.sheet_add_aoa(ws, [[val]], { origin: `A${i+2}` }));
  XLSX.utils.sheet_add_aoa(ws, info, { origin: 'A6' });
  XLSX.utils.sheet_add_aoa(ws, emptyRow, { origin: 'A10' });
  XLSX.utils.sheet_add_aoa(ws, headers, { origin: 'A11' });
  XLSX.utils.sheet_add_aoa(ws, dataRows, { origin: 'A12' });

  // Largeurs colonnes
  ws['!cols'] = [
    { wch: 25 }, { wch: 35 }, { wch: 18 }, { wch: 18 }
  ];
  // Fusion de cellules pour titres/en-têtes
  ws['!merges'] = [
    { s: { r: 0, c: 0 }, e: { r: 0, c: 3 } }, // fusion titre
    ...[1,2,3,4,5,6,7,8,9,10].map(i => ({ s: { r: i, c: 0 }, e: { r: i, c: 3 } })) // infos
  ];

  // Styles Excel Avancé
  // Titre principal
  ws['A1'].s = {
    font: { bold: true, sz: 20, color: { rgb: "1C45BD" } },
    alignment: { horizontal: "center", vertical: "center" },
  };
  // Infos établissement
  for (let i = 2; i <= 5; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 11, bold: (i==2), color: { rgb: "222831" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }
  // Infos supplémentaires
  for (let i = 6; i <= 9; ++i) {
    const cell = `A${i}`;
    if (ws[cell]) ws[cell].s = {
      font: { sz: 10, color: { rgb: "555555" } },
      alignment: { horizontal: "left", vertical: "center" }
    };
  }
  // En-tête du tableau
  ['A11', 'B11', 'C11', 'D11'].forEach(cell => {
    if (ws[cell]) ws[cell].s = {
      font: { bold: true, sz: 12, color: { rgb: "FFFFFF" } },
      fill: { fgColor: { rgb: "1C45BD" } },
      alignment: { horizontal: "center", vertical: "center" },
      border: {
        top:    { style: "thick", color: { rgb: "1C45BD" } },
        left:   { style: "thin", color: { rgb: "1C45BD" } },
        right:  { style: "thin", color: { rgb: "1C45BD" } },
        bottom: { style: "thin", color: { rgb: "1C45BD" } }
      }
    };
  });

  // Données du tableau
  const firstDataRow = 12;
  for (let i = 0; i < dataRows.length; ++i) {
    const rowIdx = firstDataRow + i;
    ['A','B','C','D'].forEach((col, j) => {
      const cell = `${col}${rowIdx}`;
      if (!ws[cell]) return;
      if (i === dataRows.length - 1 || (dataRows[i][0] && /^\d - /.test(dataRows[i][0]))) {
        ws[cell].s = {
          font: { bold: true, sz: 12, color: { rgb: "1C45BD" } },
          fill: { fgColor: { rgb: "dbeafe" } },
          alignment: { horizontal: "center", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "1C45BD" } },
            left:   { style: "thin", color: { rgb: "1C45BD" } },
            right:  { style: "thin", color: { rgb: "1C45BD" } },
            bottom: { style: "thin", color: { rgb: "1C45BD" } }
          }
        };
      } else {
        ws[cell].s = {
          font: { sz: 11 },
          alignment: { horizontal: j<2?"left":"right", vertical: "center" },
          border: {
            top:    { style: "thin", color: { rgb: "b0b0b0" } },
            left:   { style: "thin", color: { rgb: "b0b0b0" } },
            right:  { style: "thin", color: { rgb: "b0b0b0" } },
            bottom: { style: "thin", color: { rgb: "b0b0b0" } }
          }
        };
      }
    });
  }

  // Classeur & sauvegarde
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Balance Générale");
  XLSX.writeFile(wb, `balance_generale_${now.toISOString().split("T")[0]}.xlsx`);
};

const exportToPDF = async () => {
  if (!Object.keys(groupedComptes.value).length) {
    alert("Aucune donnée à exporter");
    return;
  }

  const now = new Date();
  const exercice = exercices.value.find(ex => ex.id == selectedExercice.value);

  // Branding header infos
  const htmlContent = `
    <div style="font-family: 'Helvetica', Arial, sans-serif; max-width: 950px; padding: 0 15px; margin: 0;">
      <div style="display: flex; align-items: flex-start; margin-bottom: 22px; padding-bottom: 15px; border-bottom: 3px solid #2980b9;">
        <div style="flex: 1;">
          <img src="${logoBase64.value || ''}" alt="Logo" style="width: 100px; height: auto;" />
          <div style="margin-top: 8px; font-size: 11px; color: #555;">
            <div style="font-weight: bold; font-size: 13px; color: #2c3e50;">RAITRA KIDZ</div>
            <div>Antananarivo, Madagascar</div>
            <div>+261 XX XX XXX XX</div>
            <div>contact@raitrakidz.mg</div>
          </div>
        </div>
        <div style="flex: 3; text-align: center;">
          <h1 style="font-size: 22px; font-weight: bold; color: #1c45bd; margin: 0;">Balance Générale</h1>
          <div style="font-size: 12px; margin: 4px 0;">
            Exercice : ${exercice ? exercice.nom : "Tous"}
          </div>
          <div style="font-size: 11px;">
            Période : ${filters.value.date_debut || "N/A"} à ${filters.value.date_fin || "N/A"}
          </div>
          <div style="font-size: 10px;">
            Classe de compte : ${getClasseLibelle(filters.value.classe_compte) || "Toutes"}
          </div>
        </div>
        <div style="flex: 1; text-align: right; font-size: 10px; color: #555;">
          <div style="margin: 3px 0;"><strong>Date édition:</strong> ${now.toLocaleDateString('fr-FR')} ${now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
        </div>
      </div>
      <table style="width:725px; border-collapse: collapse; margin-top: 15px;">
        <thead>
          <tr style="background: linear-gradient(135deg, #1c45bd 0%, #1c45bd 100%); color: white;">
            <th style="padding: 9px 3px; font-size: 11px; border: 1px solid #2980b9; text-align: left;">Compte</th>
            <th style="padding: 9px 3px; font-size: 11px; border: 1px solid #2980b9; text-align: right;">Débit</th>
            <th style="padding: 9px 3px; font-size: 11px; border: 1px solid #2980b9; text-align: right;">Crédit</th>
          </tr>
        </thead>
        <tbody>
          ${Object.entries(groupedComptes.value).map(([mainCode, mainAccount]) => `
            <tr style="font-weight: bold; background-color: #f4f8fd;">
              <td style="padding:8px; font-size:11px; border:1px solid #eee;">${mainCode} - ${mainAccount.libelle}</td>
              <td style="padding:8px; font-size:11px; border:1px solid #eee;text-align:right;">${formatNumber(mainAccount.total_debit)}</td>
              <td style="padding:8px; font-size:11px; border:1px solid #eee;text-align:right;">${formatNumber(mainAccount.total_credit)}</td>
            </tr>
            ${mainAccount.subAccounts.map(sub =>
              `<tr>
                <td style="padding:7px 8px 7px 32px; font-size:10px; border:1px solid #f5f7fa;">${sub.code_sous_compte} - ${sub.libelle_sous_compte}</td>
                <td style="padding:7px; font-size:10px; border:1px solid #f5f7fa; text-align:right;">${sub.solde_final >= 0 ? formatNumber(sub.solde_final) : ''}</td>
                <td style="padding:7px; font-size:10px; border:1px solid #f5f7fa; text-align:right;">${sub.solde_final < 0 ? formatNumber(Math.abs(sub.solde_final)) : ''}</td>
              </tr>`
            ).join('')}
          `).join('')}
        </tbody>
        <tfoot>
          <tr style="background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); color: white; font-weight: bold;">
            <td style="padding:10px; font-size: 12px; text-align:right; border:1px solid #2980b9;">TOTAUX :</td>
            <td style="padding:10px; font-size: 12px; text-align:right; border:1px solid #2980b9;">${formatNumber(totalDebit.value)}</td>
            <td style="padding:10px; font-size: 12px; text-align:right; border:1px solid #2980b9;">${formatNumber(totalCredit.value)}</td>
          </tr>
        </tfoot>
      </table>
      <div style="margin-top:18px; text-align:center; font-size:10px; color:#888;">
        RAITRA KIDZ © ${now.getFullYear()} | Export PDF
      </div>
    </div>
  `;

  const element = document.createElement('div');
  element.innerHTML = htmlContent;
  element.style.width = '210mm';
  document.body.appendChild(element);
  await html2pdf()
    .set({
      margin: [10, 5, 15, 5],
      filename: `Balance_Generale_${now.toISOString().split("T")[0]}.pdf`,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    })
    .from(element)
    .save();
  document.body.removeChild(element);
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
    await fetchExercices();
    // La balance sur exercice courant sera affichée par défaut automatiquement
  }
});
</script>
