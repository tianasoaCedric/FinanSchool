<template>
  <AppLayout
    :user="user"
    icon="bi-journal-text"
    title="Écritures du journal"
    subtitle="Mouvements validés enregistrés dans ce journal"
    section="Journaux"
    back
    back-label="Journaux"
    wide
    @back="goBack"
  >
    <template #actions>
      <UiButton icon="bi-file-earmark-spreadsheet" @click="exportToExcel">Excel</UiButton>
      <UiButton variant="primary" icon="bi-file-earmark-pdf" @click="exportToPDF">Exporter en PDF</UiButton>
    </template>

    <div class="fs-stack">
      <form class="fs-card fs-filters" aria-label="Filtrer les écritures" @submit.prevent="applyDateFilter">
        <div class="fs-field">
          <label class="fs-label" for="ej-debut">Date de début</label>
          <input id="ej-debut" v-model="dateFilter.date_debut" type="date" class="fs-input" />
        </div>
        <div class="fs-field">
          <label class="fs-label" for="ej-fin">Date de fin</label>
          <input id="ej-fin" v-model="dateFilter.date_fin" type="date" class="fs-input" />
        </div>
        <div class="fs-filters__actions">
          <UiButton variant="primary" type="submit" icon="bi-funnel">Appliquer</UiButton>
          <UiButton variant="ghost" @click="resetDateFilter">Réinitialiser</UiButton>
        </div>
      </form>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Écritures</h2>
          <UiBadge tone="primary">{{ ecritures.length }} ligne(s)</UiBadge>
        </div>
        <UiSkeleton v-if="loading" variant="table" :rows="8" :cols="8" />
        <div v-else class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>N° pièce</th>
                <th>Compte</th>
                <th>Libellé</th>
                <th>Référence</th>
                <th>Mode de paiement</th>
                <th class="text-right">Débit</th>
                <th class="text-right">Crédit</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ecriture in ecritures" :key="ecriture.Id_Ligne_ecriture">
                <td>{{ ecriture.mouvement ? new Date(ecriture.mouvement.Date_mouvement).toLocaleDateString('fr-FR') : '—' }}</td>
                <td><UiBadge size="sm">{{ ecriture.mouvement ? ecriture.mouvement.Numero_piece : '—' }}</UiBadge></td>
                <td class="font-bold">{{ ecriture.sous_compte ? ecriture.sous_compte.Code_sous_compte : '—' }}</td>
                <td>{{ ecriture.Libelle || '—' }}</td>
                <td>{{ ecriture.Reference || '—' }}</td>
                <td>{{ ecriture.mode_paiement ? ecriture.mode_paiement.Libelle : '—' }}</td>
                <td class="num">{{ ecriture.Debit ? formatNumber(ecriture.Debit) : '—' }}</td>
                <td class="num">{{ ecriture.Credit ? formatNumber(ecriture.Credit) : '—' }}</td>
              </tr>
              <tr v-if="ecritures.length" class="total-row">
                <td colspan="6" class="text-right">Totaux</td>
                <td class="num">{{ formatNumber(totalDebit) }}</td>
                <td class="num">{{ formatNumber(totalCredit) }}</td>
              </tr>
              <tr v-else>
                <td colspan="8" class="fs-empty">Aucune écriture trouvée.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </AppLayout>
</template>

<script setup>
import { useParametresStore } from '@/stores/parametres';
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import html2pdf from "html2pdf.js";
import * as XLSX from "xlsx-js-style";
import { getUser } from "../../../services/Auth";
import logo from '@/assets/img/01Raitra kidz 300px.png';

const route = useRoute();
const user = ref(null);
const router = useRouter();
const journalId = ref(route.params.id);
const journal = ref(route.params.journal || route.params.journalLibelle);
const ecritures = ref([]);
const dateFilter = ref({
  date_debut: "",
  date_fin: "",
});

const showChat = ref(false);

// Informations de l'établissement
const parametresStore = useParametresStore();
parametresStore.charger();
// Coordonnées de l'entreprise (écran « Paramètres »), lues au moment de l'export
const etablissement = {
  get nom() { return parametresStore.societe.nom; },
  get adresse() { return parametresStore.societe.adresse; },
  get tel() { return parametresStore.societe.tel; },
  get email() { return parametresStore.societe.email; },
};

const handleNavigation = (item) => {
  router.push(item.route);
};

const token = localStorage.getItem("token");

if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const formatNumber = (number) => {
  if (number === null || number === undefined || isNaN(number)) return '-';
  const num = Number(number);
  return num.toLocaleString('fr-FR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).replace(/\s/g, ' ');
};

const loading = ref(true);
const fetchEcritures = async () => {
  loading.value = true;
  try {
    const queryParams = new URLSearchParams({
      status: "valide",
      ...(dateFilter.value.date_debut && { date_debut: dateFilter.value.date_debut }),
      ...(dateFilter.value.date_fin && { date_fin: dateFilter.value.date_fin }),
    }).toString();
    const res = await axios.get(`${API_BASE}/journals/${journalId.value}/ecritures?${queryParams}`);
    ecritures.value = res.data;
    console.log("Écritures chargées:", ecritures.value);
  } catch (error) {
    console.error("Erreur lors du chargement des écritures:", error);
    ecritures.value = [];
  } finally {
    loading.value = false;
  }
};


const totalDebit = computed(() => {
  return ecritures.value.reduce((sum, ecriture) => sum + (Number(ecriture.Debit) || 0), 0);
});

const totalCredit = computed(() => {
  return ecritures.value.reduce((sum, ecriture) => sum + (Number(ecriture.Credit) || 0), 0);
});

const applyDateFilter = () => {
  fetchEcritures();
};

const resetDateFilter = () => {
  dateFilter.value = { date_debut: "", date_fin: "" };
  fetchEcritures();
};

// Export vers PDF avec html2pdf.js (CORRIGÉ)
// Export vers PDF avec html2pdf.js (AVEC CONTENU DÉCALÉ À GAUCHE)
const exportToPDF = async () => {
  if (!ecritures.value || ecritures.value.length === 0) {
    alert("Aucune écriture à exporter !");
    return;
  }

  const now = new Date();
  const dateTirage = `${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
  
  let period = '';
  if (dateFilter.value.date_debut) {
    const d = new Date(dateFilter.value.date_debut);
    period = d.toLocaleString('fr-FR', { month: 'long', year: 'numeric' });
  }

  const rows = ecritures.value.map(ecriture => {
    const dateMouvement = ecriture.mouvement ? ecriture.mouvement.Date_mouvement : null;
    let jour = '-';
    if (dateMouvement) {
      const d = new Date(dateMouvement);
      jour = `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getFullYear()}`;
    }
    return `
      <tr>
        <td>${jour}</td>
        <td>${ecriture.mouvement?.Numero_piece || '-'}</td>
        <td>${ecriture.sous_compte?.Code_sous_compte || '-'}</td>
        <td>${ecriture.Reference || '-'}</td>
        <td>${ecriture.Libelle || '-'}</td>
        <td style="text-align: right;">${ecriture.Debit ? formatNumber(ecriture.Debit) : '-'}</td>
        <td style="text-align: right;">${ecriture.Credit ? formatNumber(ecriture.Credit) : '-'}</td>
      </tr>
    `;
  }).join('');

  const htmlContent = `
    <div style="font-family: 'Helvetica', Arial, sans-serif; padding: 15px; width:725px; margin: 0;">
      <!-- En-tête -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #2980b9;">
        <div style="flex: 1;">
          <img src="${parametresStore.get('societe.logo') || logo}" alt="Logo" style="width: 100px; height: auto;" />
          <div style="margin-top: 8px; font-size: 11px; color: #555;">
            <div style="font-weight: bold; font-size: 13px; color: #2c3e50;">${etablissement.nom}</div>
            <div>${etablissement.adresse}</div>
            <div>${etablissement.tel}</div>
            <div>${etablissement.email}</div>
          </div>
        </div>
        <div style="flex: 2; text-align: center;">
          <h1 style="font-size: 22px; font-weight: bold; color: #2c3e50; margin: 0; text-transform: uppercase;">
            Journal ${journal.value ? journal.value.toUpperCase() : ''}
          </h1>
        </div>
        <div style="flex: 1; text-align: right; font-size: 10px; color: #555;">
          <div style="margin: 3px 0;"><strong>Devise:</strong> ${parametresStore.devise.symbole}</div>
          <div style="margin: 3px 0;"><strong>Période:</strong> ${period}</div>
          <div style="margin: 3px 0;"><strong>Journal N°:</strong> ${journalId.value}</div>
        </div>
      </div>
      
      <!-- Métadonnées -->
      <div style="display: flex; justify-content: space-between; margin-bottom: 20px; font-size: 10px; color: #666; padding: 10px; background: #f8f9fa; border-radius: 5px;width:725px;">
        <div><strong>Date de tirage:</strong> ${dateTirage}</div>
        <div><strong>Plage:</strong> Du ${dateFilter.value.date_debut || '-'} au ${dateFilter.value.date_fin || '-'}</div>
      </div>

      <!-- Tableau -->
      <table style="width:725px; border-collapse: collapse; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <thead>
          <tr style="background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); color: white;">
            <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">Jour</th>
            <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">N° Pièce</th>
            <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">N° Compte</th>
            <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">Référence</th>
            <th style="padding: 10px 8px; text-align: left; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">Libellé</th>
            <th style="padding: 10px 8px; text-align: right; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">Débit</th>
            <th style="padding: 10px 8px; text-align: right; font-weight: 600; font-size: 10px; border: 1px solid #2980b9;">Crédit</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
        <tfoot>
          <tr style="background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); color: white; font-weight: bold;">
            <td colspan="5" style="padding: 10px 8px; text-align: right; border: 1px solid #2980b9; font-size: 11px;">TOTAUX</td>
            <td style="padding: 10px 8px; text-align: right; border: 1px solid #2980b9; font-size: 11px;">${formatNumber(totalDebit.value)}</td>
            <td style="padding: 10px 8px; text-align: right; border: 1px solid #2980b9; font-size: 11px;">${formatNumber(totalCredit.value)}</td>
          </tr>
        </tfoot>
      </table>

      <!-- Pied de page -->
      <div style="margin-top: 25px; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #ddd; padding-top: 10px;">
        <p style="margin: 0;">${etablissement.nom} © ${now.getFullYear()} | Document généré automatiquement | Impression provisoire</p>
      </div>
    </div>
  `;

  // Styles pour le tableau dans tbody
  const tbodyStyle = `
    <style>
      tbody tr { border: 1px solid var(--fs-border); }
      tbody tr:nth-child(even) { background-color: var(--fs-surface-2); }
      tbody td { padding: 8px; border: 1px solid var(--fs-border); font-size: 9px; }
    </style>
  `;

  const fullHTML = tbodyStyle + htmlContent;

  // Créer un élément temporaire
  const element = document.createElement('div');
  element.innerHTML = fullHTML;
  element.style.width = '210mm';
  element.style.padding = '0'; // Suppression du padding
  element.style.margin = '0';   // Suppression du margin

  const opt = {
    margin: [10, 5, 15, 5], // [haut, droite, bas, gauche] - Marge gauche réduite de 10 à 5
    filename: `Journal_${journalId.value}_${now.toISOString().split('T')[0]}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { 
      scale: 2,
      useCORS: true,
      logging: false,
      letterRendering: true,
      allowTaint: true,
      x: 0,  // Commencer à gauche
      scrollX: 0,
      scrollY: 0
    },
    jsPDF: { 
      unit: 'mm', 
      format: 'a4', 
      orientation: 'portrait' 
    },
    pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
  };

  try {
    await html2pdf().set(opt).from(element).save();
    console.log('PDF généré avec succès');
  } catch (err) {
    console.error('Erreur lors de l\'export PDF:', err);
    alert('Erreur lors de la génération du PDF. Vérifiez la console.');
  }
};


// Export vers Excel avec logo et nom établissement (CORRIGÉ)
const exportToExcel = async () => {
  if (!ecritures.value || ecritures.value.length === 0) {
    alert("Aucune écriture à exporter !");
    return;
  }

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet([]);
  
  const now = new Date();
  let period = '';
  if (dateFilter.value.date_debut) {
    const d = new Date(dateFilter.value.date_debut);
    period = d.toLocaleString('fr-FR', { month: 'long', year: 'numeric' });
  }

  // Styles
  const titleStyle = {
    font: { bold: true, sz: 20, color: { rgb: "2C3E50" } },
    alignment: { horizontal: "center", vertical: "center" }
  };

  const headerStyle = {
    font: { bold: true, color: { rgb: "FFFFFF" }, sz: 11 },
    fill: { fgColor: { rgb: "2980B9" } },
    alignment: { horizontal: "center", vertical: "center" },
    border: {
      top: { style: "thin", color: { rgb: "000000" } },
      bottom: { style: "thin", color: { rgb: "000000" } },
      left: { style: "thin", color: { rgb: "000000" } },
      right: { style: "thin", color: { rgb: "000000" } }
    }
  };

  const etablissementStyle = {
    font: { bold: true, sz: 14, color: { rgb: "2980B9" } },
    alignment: { horizontal: "left", vertical: "center" }
  };

  const infoStyle = {
    font: { sz: 10, color: { rgb: "555555" } },
    alignment: { horizontal: "left", vertical: "center" }
  };

  const dataStyle = {
    font: { sz: 10 },
    alignment: { horizontal: "left", vertical: "center", wrapText: true },
    border: {
      top: { style: "thin", color: { rgb: "DDDDDD" } },
      bottom: { style: "thin", color: { rgb: "DDDDDD" } },
      left: { style: "thin", color: { rgb: "DDDDDD" } },
      right: { style: "thin", color: { rgb: "DDDDDD" } }
    }
  };

  const numberStyle = {
    ...dataStyle,
    alignment: { horizontal: "right", vertical: "center" },
    numFmt: "#,##0.00"
  };

  const totalStyle = {
    font: { bold: true, sz: 11, color: { rgb: "FFFFFF" } },
    fill: { fgColor: { rgb: "2980B9" } },
    alignment: { horizontal: "right", vertical: "center" },
    border: {
      top: { style: "medium", color: { rgb: "000000" } },
      bottom: { style: "medium", color: { rgb: "000000" } },
      left: { style: "medium", color: { rgb: "000000" } },
      right: { style: "medium", color: { rgb: "000000" } }
    }
  };

  // Logo et informations établissement (ligne 1-5)
  const logoInfo = [
    [etablissement.nom],
    [etablissement.adresse],
    [etablissement.tel],
    [etablissement.email],
    ['']
  ];
  
  XLSX.utils.sheet_add_aoa(ws, logoInfo, { origin: 'A1' });
  ws['A1'].s = etablissementStyle;
  ws['A2'].s = infoStyle;
  ws['A3'].s = infoStyle;
  ws['A4'].s = infoStyle;

  // Titre principal (ligne 6)
  const title = [[`JOURNAL ${journal.value ? journal.value.toUpperCase() : ''}`]];
  XLSX.utils.sheet_add_aoa(ws, title, { origin: 'A6' });
  ws['A6'].s = titleStyle;
  
  if (!ws['!merges']) ws['!merges'] = [];
  ws['!merges'].push({ s: { r: 5, c: 0 }, e: { r: 5, c: 6 } });

  // Informations du document (ligne 8-12)
  const info = [
    [''],
    [`Journal N°: ${journalId.value}`],
    [`Devise: ${parametresStore.devise.code} (${parametresStore.devise.symbole})`],
    [`Période: ${period}`],
    [`Date d'export: ${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`],
    [`Plage: Du ${dateFilter.value.date_debut || '-'} au ${dateFilter.value.date_fin || '-'}`],
    ['']
  ];
  
  XLSX.utils.sheet_add_aoa(ws, info, { origin: 'A8' });
  for (let i = 9; i <= 13; i++) {
    if (ws[`A${i}`]) ws[`A${i}`].s = infoStyle;
  }

  // En-têtes du tableau (ligne 15)
  const headers = [
    ['Date Mouvement', 'N° Pièce', 'N° Compte', 'Référence', 'Libellé', 'Débit', 'Crédit']
  ];
  XLSX.utils.sheet_add_aoa(ws, headers, { origin: 'A15' });
  
  ['A15', 'B15', 'C15', 'D15', 'E15', 'F15', 'G15'].forEach(cell => {
    ws[cell].s = headerStyle;
  });

  // Données (à partir de ligne 16)
  const data = ecritures.value.map(ecriture => {
    const dateMouvement = ecriture.mouvement ? new Date(ecriture.mouvement.Date_mouvement).toLocaleDateString('fr-FR') : '-';
    return [
      dateMouvement,
      ecriture.mouvement?.Numero_piece || '-',
      ecriture.sous_compte?.Code_sous_compte || '-',
      ecriture.Reference || '-',
      ecriture.Libelle || '-',
      ecriture.Debit ? Number(ecriture.Debit) : 0,
      ecriture.Credit ? Number(ecriture.Credit) : 0
    ];
  });

  XLSX.utils.sheet_add_aoa(ws, data, { origin: 'A16' });

  // Application des styles aux données
  const startRow = 16;
  data.forEach((_, index) => {
    const rowNum = startRow + index;
    ['A', 'B', 'C', 'D', 'E'].forEach(col => {
      const cellRef = `${col}${rowNum}`;
      if (ws[cellRef]) ws[cellRef].s = dataStyle;
    });
    ['F', 'G'].forEach(col => {
      const cellRef = `${col}${rowNum}`;
      if (ws[cellRef]) ws[cellRef].s = numberStyle;
    });
  });

  // Ligne des totaux
  const totalRow = startRow + data.length;
  XLSX.utils.sheet_add_aoa(ws, [
    ['', '', '', '', 'TOTAUX', totalDebit.value, totalCredit.value]
  ], { origin: `A${totalRow}` });

  ['A', 'B', 'C', 'D', 'E', 'F', 'G'].forEach(col => {
    const cellRef = `${col}${totalRow}`;
    if (ws[cellRef]) ws[cellRef].s = totalStyle;
  });

  // Largeur des colonnes
  ws['!cols'] = [
    { wch: 15 },  // Date
    { wch: 15 },  // N° Pièce
    { wch: 15 },  // Compte
    { wch: 15 },  // Référence
    { wch: 40 },  // Libellé
    { wch: 15 },  // Débit
    { wch: 15 }   // Crédit
  ];

  // Hauteur des lignes
  ws['!rows'] = [
    { hpt: 25 },  // Nom établissement
    { hpt: 18 },  // Adresse
    { hpt: 18 },  // Tel
    { hpt: 18 },  // Email
    { hpt: 5 },   // Espace
    { hpt: 35 },  // Titre
    { hpt: 5 },   // Espace
    { hpt: 5 },   // Espace
    { hpt: 18 },  // Journal N°
    { hpt: 18 },  // Devise
    { hpt: 18 },  // Période
    { hpt: 18 },  // Date export
    { hpt: 18 },  // Plage
    { hpt: 5 },   // Espace
    { hpt: 25 }   // En-têtes
  ];

  // Ajout de la feuille au classeur
  XLSX.utils.book_append_sheet(wb, ws, `Journal ${journalId.value}`);

  // Sauvegarde
  const fileName = `Journal_${journalId.value}_${now.toISOString().split('T')[0]}.xlsx`;
  XLSX.writeFile(wb, fileName);
  
  console.log('Excel généré avec succès');
};

const goBack = () => {
  router.push('/journal');
};

onMounted(async () => {
  console.log("Token récupéré :", token);
  
  if (!token) {
    console.log("Pas de token → Redirection vers /");
    window.location.href = "/";
  } else {
    axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
    try {
      console.log("Appel getUser en cours...");
      const res = await getUser(token);
      user.value = res.data;
      console.log("User récupéré :", user.value);
    } catch (err) {
      console.error("Erreur lors de getUser :", err);
      localStorage.removeItem("token");
      window.location.href = "/";
      return;
    }
    fetchEcritures();
  }
});
</script>
