<template>
  <AppLayout
    :user="user"
    icon="bi-list-check"
    title="Toutes les écritures"
    subtitle="Recherchez, filtrez et modifiez les lignes d'écriture"
    section="Saisie"
    wide
  >
    <template #actions>
      <UiButton v-if="selectedRows.length > 0" variant="primary" icon="bi-pencil-square" @click="editGroup">
        Modifier la sélection
        <UiBadge size="sm" class="le-badge-in-btn">{{ selectedRows.length }}</UiBadge>
      </UiButton>
    </template>

    <div class="fs-stack">
      <form class="fs-card fs-filters" aria-label="Filtres" @submit.prevent="filters.page = 1; fetchEcritures()">
        <div class="fs-field le-span-2">
          <label class="fs-label" for="le-q">Recherche</label>
          <input id="le-q" v-model="filters.q" type="search" class="fs-input" placeholder="Libellé, référence…" />
        </div>
        <div class="fs-field">
          <label class="fs-label" for="le-debut">Date début</label>
          <input id="le-debut" v-model="filters.date_debut" type="date" class="fs-input" />
        </div>
        <div class="fs-field">
          <label class="fs-label" for="le-fin">Date fin</label>
          <input id="le-fin" v-model="filters.date_fin" type="date" class="fs-input" />
        </div>
        <div class="fs-field">
          <label class="fs-label" for="le-classe">Classe</label>
          <select id="le-classe" v-model="filters.Id_Classe" class="fs-select" :disabled="loadingLists">
            <option value="">{{ loadingLists ? 'Chargement…' : 'Toutes les classes' }}</option>
            <option v-for="cl in classes" :value="cl.Id_Classe" :key="cl.Id_Classe">{{ cl.Code }} - {{ cl.Libelle }}</option>
          </select>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="le-journal">Journal</label>
          <select id="le-journal" v-model="filters.Id_Journal" class="fs-select" :disabled="loadingLists">
            <option value="">{{ loadingLists ? 'Chargement…' : 'Tous les journaux' }}</option>
            <option v-for="j in journaux" :value="j.Id_Journal" :key="j.Id_Journal">{{ j.Code }} - {{ j.Libelle }}</option>
          </select>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="le-compte">Compte</label>
          <select id="le-compte" v-model="filters.Id_Compte" class="fs-select" :disabled="loadingLists">
            <option value="">{{ loadingLists ? 'Chargement…' : 'Tous les comptes' }}</option>
            <option v-for="cp in comptes" :value="cp.Id_Compte" :key="cp.Id_Compte">{{ cp.Code_compte }} - {{ cp.Libelle }}</option>
          </select>
        </div>
        <div class="fs-filters__actions">
          <UiButton variant="primary" type="submit" icon="bi-funnel" :loading="isLoading">Filtrer</UiButton>
          <UiButton variant="ghost" @click="resetFilters">Réinitialiser</UiButton>
        </div>
      </form>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Lignes d'écriture</h2>
          <div class="fs-inline">
            <UiBadge v-if="selectedRows.length" tone="primary" dot>{{ selectedRows.length }} sélectionnée(s)</UiBadge>
            <UiBadge>{{ ecritures.total ?? filteredEcritures.length }} ligne(s)</UiBadge>
          </div>
        </div>
        <div class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th class="le-check"><input type="checkbox" v-model="allChecked" @change="toggleAllRows" :disabled="isLoading" aria-label="Tout sélectionner" /></th>
                <th>Date</th>
                <th>N° pièce</th>
                <th>Sous-compte</th>
                <th>Libellé</th>
                <th class="text-right">Débit</th>
                <th class="text-right">Crédit</th>
                <th>Réf.</th>
                <th>Statut</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <UiSkeleton v-if="isLoading" variant="rows" :rows="8" :cols="10" />
              <template v-else>
                <tr v-for="ligne in filteredEcritures" :key="ligne.Id_Ligne_ecriture" class="detail-row">
                  <td class="le-check">
                    <input v-if="ligne.statut !== 'valide'" type="checkbox" :value="ligne.Id_Ligne_ecriture" v-model="selectedRows" :aria-label="`Sélectionner la ligne ${ligne.Libelle || ''}`" />
                  </td>
                  <td class="num">{{ formatDate(ligne.mouvement?.Date_mouvement) }}</td>
                  <td class="font-bold">{{ ligne.mouvement?.Numero_piece || 'Brouillon' }}</td>
                  <td>{{ ligne.sous_compte?.Code_sous_compte }}</td>
                  <td class="le-libelle" :title="ligne.Libelle">{{ ligne.Libelle }}</td>
                  <td class="num">{{ formatMontant(ligne.Debit) }}</td>
                  <td class="num">{{ formatMontant(ligne.Credit) }}</td>
                  <td class="le-muted">{{ ligne.Reference }}</td>
                  <td>
                    <UiBadge v-if="ligne.statut === 'valide'" tone="success" dot>Validé</UiBadge>
                    <UiBadge v-else tone="warning" dot>Brouillon</UiBadge>
                  </td>
                  <td class="actions">
                    <div class="fs-row-actions">
                      <UiButton v-if="ligne.statut !== 'valide'" variant="soft-primary" size="sm" icon="bi-pencil" @click="editLigne(ligne)">Modifier</UiButton>
                      <UiBadge v-else size="sm" icon="bi-lock">Verrouillé</UiBadge>
                    </div>
                  </td>
                </tr>
                <tr v-if="filteredEcritures.length === 0">
                  <td colspan="10" class="fs-empty">Aucune écriture trouvée.</td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <UiPagination
          v-if="!isLoading"
          :model-value="ecritures.current_page || 1"
          :total-pages="ecritures.last_page || 1"
          :total="ecritures.total"
          :per-page="ecritures.per_page"
          @update:model-value="changePage"
        />
      </section>
    </div>

    <!-- Modification d'une ligne -->
    <UiModal v-model="showEditModal" title="Modifier l'écriture" icon="bi-pencil" size="lg">
      <form id="form-ligne" class="ui-form" @submit.prevent="updateLigneEdit">
        <div class="ui-field ui-field--full">
          <label for="le-sc">Sous-compte d'imputation</label>
          <select id="le-sc" v-model="ligneEdit.Id_Sous_compte" :disabled="loadingLists">
            <option value="">{{ loadingLists ? 'Chargement des comptes…' : 'Sélectionner un sous-compte…' }}</option>
            <option v-for="sc in sousComptes" :value="sc.Id_Sous_compte" :key="sc.Id_Sous_compte">{{ sc.Code_sous_compte }} - {{ sc.Libelle }}</option>
          </select>
        </div>
        <div class="ui-field ui-field--full">
          <label for="le-lib">Libellé</label>
          <input id="le-lib" v-model="ligneEdit.Libelle" type="text" />
        </div>
        <div class="ui-field">
          <label for="le-ref">Référence</label>
          <input id="le-ref" v-model="ligneEdit.Reference" type="text" />
        </div>
        <div class="ui-field">
          <label for="le-debit">Débit</label>
          <input id="le-debit" v-model.number="ligneEdit.Debit" type="number" step="0.01" class="text-right" placeholder="0,00" @input="onDebitInput('ligne')" />
        </div>
        <div class="ui-field">
          <label for="le-credit">Crédit</label>
          <input id="le-credit" v-model.number="ligneEdit.Credit" type="number" step="0.01" class="text-right" placeholder="0,00" @input="onCreditInput('ligne')" />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showEditModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-ligne" :disabled="loadingLists" :loading="saving">Enregistrer</UiButton>
      </template>
    </UiModal>

    <!-- Modification groupée -->
    <UiModal v-model="showGroupModal" :title="`Modification groupée`" :subtitle="`${selectedRows.length} ligne(s) sélectionnée(s)`" icon="bi-pencil-square" size="lg">
      <p class="fs-alert fs-alert--warning le-mb"><i class="bi bi-info-circle" aria-hidden="true"></i> Les champs laissés vides ne seront pas modifiés.</p>
      <form id="form-groupe" class="ui-form" @submit.prevent="updateGroup">
        <div class="ui-field ui-field--full">
          <label for="lg-sc">Nouveau sous-compte</label>
          <select id="lg-sc" v-model="groupEdit.Id_Sous_compte" :disabled="loadingLists">
            <option value="">— Ne pas modifier —</option>
            <option v-for="sc in sousComptes" :value="sc.Id_Sous_compte" :key="sc.Id_Sous_compte">{{ sc.Code_sous_compte }} - {{ sc.Libelle }}</option>
          </select>
        </div>
        <div class="ui-field ui-field--full">
          <label for="lg-lib">Nouveau libellé</label>
          <input id="lg-lib" v-model="groupEdit.Libelle" type="text" placeholder="Laisser vide…" />
        </div>
        <div class="ui-field">
          <label for="lg-ref">Nouvelle référence</label>
          <input id="lg-ref" v-model="groupEdit.Reference" type="text" placeholder="Optionnel" />
        </div>
        <div class="ui-field">
          <label for="lg-debit">Forcer le débit</label>
          <input id="lg-debit" v-model.number="groupEdit.Debit" type="number" step="0.01" class="text-right" placeholder="Inchangé" @input="onDebitInput('group')" />
        </div>
        <div class="ui-field">
          <label for="lg-credit">Forcer le crédit</label>
          <input id="lg-credit" v-model.number="groupEdit.Credit" type="number" step="0.01" class="text-right" placeholder="Inchangé" @input="onCreditInput('group')" />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showGroupModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-groupe" :loading="saving">Appliquer</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="errorOpen" tone="danger" title="Erreur" size="sm">
      <p class="ui-modal__message">{{ errorMessage }}</p>
      <template #footer><UiButton variant="primary" @click="errorOpen = false">OK</UiButton></template>
    </UiModal>
  </AppLayout>
</template>

<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, computed, onMounted } from "vue";
import axios from "axios";

const user = ref(null);
const showChat = ref(false);
const token = localStorage.getItem("token");
if (!token) window.location.href = "/";
axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;

const handleNavigation = (item) => { window.location.href = item.route; };

// Etats
const filters = ref({ q: "", date_debut: "", date_fin: "", Id_Classe: "", Id_Journal: "", Id_Compte: "", page: 1 });
const classes = ref([]); 
const comptes = ref([]); 
const journaux = ref([]); 
const sousComptes = ref([]);

const ecritures = ref({ data: [], current_page: 1, last_page: 1 });
const isLoading = ref(true); // Pour le tableau
const loadingLists = ref(true); // Pour les selects

const selectedRows = ref([]);
const allChecked = ref(false);

const showEditModal = ref(false);
const ligneEdit = ref({});
const showGroupModal = ref(false);
const groupEdit = ref({ Libelle: "", Debit: null, Credit: null, Reference: "", Id_Sous_compte: "" });

// --- LOGIQUE METIER ---

// Gestion auto Débit/Crédit (si l'un est rempli, l'autre se vide)
function onDebitInput(context) {
    const target = context === 'ligne' ? ligneEdit.value : groupEdit.value;
    if (target.Debit > 0) target.Credit = 0;
}
function onCreditInput(context) {
    const target = context === 'ligne' ? ligneEdit.value : groupEdit.value;
    if (target.Credit > 0) target.Debit = 0;
}

function formatDate(d) { return d ? new Date(d).toLocaleDateString("fr-FR") : ""; }
function formatMontant(m) { if (!m || m === 0) return "-"; return Number(m).toLocaleString('fr-FR', { minimumFractionDigits: 2 }); }

async function fetchEcritures() {
  isLoading.value = true;
  try {
    let params = { ...filters.value, page: filters.value.page || 1 };
    const res = await axios.get(`${API_BASE}/ecritures`, { params });
    ecritures.value = res.data;
    
    // Recalculer les checkbox
    const idsEditable = ecritures.value.data.filter(l => l.statut !== 'valide').map(l => l.Id_Ligne_ecriture);
    allChecked.value = idsEditable.length > 0 && idsEditable.every(id => selectedRows.value.includes(id));
  } catch { ecritures.value = { data: [] }; }
  isLoading.value = false;
}

const filteredEcritures = computed(() => {
  let result = ecritures.value.data || [];
  if(filters.value.Id_Classe) result = result.filter(l => l.sous_compte?.compte?.rubrique?.classe?.Id_Classe == filters.value.Id_Classe);
  if(filters.value.Id_Compte) result = result.filter(l => l.sous_compte?.compte?.Id_Compte == filters.value.Id_Compte);
  if(filters.value.Id_Journal) result = result.filter(l => l.Id_Journal == filters.value.Id_Journal);
  return result;
});

function resetFilters() {
  filters.value = { q: "", date_debut: "", date_fin: "", Id_Classe: "", Id_Journal: "", Id_Compte: "", page: 1 };
  fetchEcritures();
  selectedRows.value = [];
  allChecked.value = false;
}
function changePage(p) { filters.value.page = p; fetchEcritures(); }
function toggleAllRows() {
  const editableRows = ecritures.value.data.filter(l => l.statut !== 'valide');
  if (allChecked.value) selectedRows.value = editableRows.map(l => l.Id_Ligne_ecriture);
  else selectedRows.value = [];
}

function editLigne(ligne) {
  ligneEdit.value = JSON.parse(JSON.stringify(ligne));
  ligneEdit.value.Id_Sous_compte = ligne.sous_compte?.Id_Sous_compte || "";
  showEditModal.value = true;
}
const saving = ref(false);
const errorOpen = ref(false);
const errorMessage = ref('');
function alert(msg) { errorMessage.value = msg; errorOpen.value = true; }

async function updateLigneEdit() {
  saving.value = true;
  try {
    await axios.put(`${API_BASE}/lignes/${ligneEdit.value.Id_Ligne_ecriture}`, ligneEdit.value);
    showEditModal.value = false;
    fetchEcritures();
  } catch(e) { alert(e.response?.data?.message || "Erreur lors de la sauvegarde"); }
  finally { saving.value = false; }
}

function editGroup() {
  groupEdit.value = { Libelle: "", Debit: null, Credit: null, Reference: "", Id_Sous_compte: "" };
  showGroupModal.value = true;
}
async function updateGroup() {
  if(!confirm("Confirmer la modification ?")) return;
  saving.value = true;
  try {
    await axios.put(`${API_BASE}/lignes/batch-update`, { ids: selectedRows.value, data: groupEdit.value });
    showGroupModal.value = false;
    fetchEcritures();
    selectedRows.value = [];
    allChecked.value = false;
  } catch(e) { alert(e.response?.data?.message || "Erreur lors de la modification groupée"); }
  finally { saving.value = false; }
}

onMounted(() => {
  // 1. Charger l'utilisateur rapidement
  axios.get(`${API_BASE}/user`).then(res => user.value = res.data);

  // 2. Charger le tableau IMMÉDIATEMENT
  fetchEcritures();

  // 3. Charger les listes en ARRIÈRE-PLAN (Non-bloquant)
  loadingLists.value = true;
  Promise.all([
      axios.get(`${API_BASE}/classes`),
      axios.get(`${API_BASE}/comptes`),
      axios.get(`${API_BASE}/journals`),
      axios.get(`${API_BASE}/sous-comptes`)
  ]).then(([clRes, cpRes, jRes, scRes]) => {
      classes.value = clRes.data;
      comptes.value = cpRes.data;
      journaux.value = jRes.data;
      sousComptes.value = scRes.data;
  }).finally(() => {
      loadingLists.value = false;
  });
});
</script>

<style scoped>
.le-span-2 { grid-column: span 2; }
.le-check { width: 40px; text-align: center; }
.le-libelle { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.le-muted { color: var(--fs-muted); font-size: var(--fs-text-sm); }
.le-mb { margin: 0 0 16px; }
.le-badge-in-btn { background: rgba(255, 255, 255, 0.22); color: inherit; }
@media (max-width: 768px) { .le-span-2 { grid-column: auto; } }
</style>
