<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'
import { useRouter } from 'vue-router'
import { getUser } from "../../../services/Auth";

// --- Configuration ---
const router = useRouter();
const API_URL = `${API_BASE}`
const itemsPerPage = 10

// --- State ---
const user = ref(null);
const showChat = ref(false);
const comptes = ref([])
const sousComptes = ref([])
const showModal = ref(false)
const isEditing = ref(false)
const currentPage = ref(1)
const loading = ref(true)
const saving = ref(false)

// --- State Popups (Nouveau) ---
const showSuccessModal = ref(false)
const successModalMessage = ref('')
const showErrorModal = ref(false)
const errorModalMessage = ref('')

const filters = ref({
  compte_id: '',
  search: ''
})

const form = ref({
  id: null,
  Id_Compte: '',
  suffixe: '',
  Code_sous_compte: '',
  Libelle: ''
})

// --- Auth & Init ---
const token = localStorage.getItem("token");

if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

onMounted(async () => {
  if (!token) {
    window.location.href = "/";
    return;
  }
  
  try {
    const res = await getUser(token);
    user.value = res.data;
  } catch (err) {
    console.error("Erreur Auth:", err);
    localStorage.removeItem("token");
    window.location.href = "/";
    return;
  }

  loadComptes();
  loadSousComptes();
});

// --- Methods: Navigation ---
const handleNavigation = (item) => {
  router.push(item.route);
};

// --- Methods: Data Loading ---
const loadComptes = async () => {
  try {
    const response = await axios.get(`${API_URL}/comptes`)
    comptes.value = response.data.data || response.data
    if (comptes.value.length && !form.value.Id_Compte) {
      form.value.Id_Compte = comptes.value[0].Id_Compte
      updateCode()
    }
  } catch (error) {
    console.error('Erreur chargement comptes:', error)
  }
}

const loadSousComptes = async () => {
  try {
    const res = await axios.get(`${API_URL}/sous-comptes`)
    sousComptes.value = res.data.data || res.data
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
}

// --- Methods: Form Handling ---
const updateCode = () => {
  const compte = comptes.value.find(c => c.Id_Compte === form.value.Id_Compte)
  if (compte && compte.Code_compte) {
    form.value.Code_sous_compte = compte.Code_compte + form.value.suffixe.padStart(3, '0')
  } else {
    form.value.Code_sous_compte = form.value.suffixe
  }
}

const openCreateModal = () => {
  isEditing.value = false
  form.value = { 
    id: null, 
    Id_Compte: comptes.value[0]?.Id_Compte || '', 
    suffixe: '', 
    Code_sous_compte: '', 
    Libelle: '' 
  }
  showModal.value = true
}

const openEditModal = async (sousCompte) => {
  isEditing.value = true
  form.value.id = sousCompte.Id_Sous_compte
  form.value.Id_Compte = sousCompte.Id_Compte
  form.value.Libelle = sousCompte.Libelle
  form.value.suffixe = sousCompte.Code_sous_compte.slice(-3)
  updateCode()
  showModal.value = true
}

const saveSousCompte = async () => {
  saving.value = true
  try {
    if (isEditing.value) {
      await axios.put(`${API_URL}/sous-comptes/${form.value.id}`, form.value)
      // MODIFIÉ : Utilisation du popup succès
      successModalMessage.value = 'Sous-compte modifié avec succès'
      showSuccessModal.value = true
    } else {
      await axios.post(`${API_URL}/sous-comptes`, form.value)
      // MODIFIÉ : Utilisation du popup succès
      successModalMessage.value = 'Sous-compte créé avec succès'
      showSuccessModal.value = true
      
      await axios.post(`${API_URL}/assigner-toutes-automatiquement`);
    }
    showModal.value = false
    loadSousComptes()
    currentPage.value = 1
  } catch (error) {
    console.error('Erreur enregistrement:', error)
    // MODIFIÉ : Utilisation du popup erreur
    errorModalMessage.value = error.response?.data?.message || 'Erreur lors de l\'enregistrement du sous-compte'
    showErrorModal.value = true
  } finally {
    saving.value = false
  }
}

const deleteSousCompte = async (id) => {
  // On garde le confirm natif pour la question "Êtes-vous sûr ?"
  if (confirm('Voulez-vous vraiment supprimer ce sous-compte ?')) {
    try {
      await axios.delete(`${API_URL}/sous-comptes/${id}`)
      
      // MODIFIÉ : Utilisation du popup succès
      successModalMessage.value = 'Sous-compte supprimé avec succès'
      showSuccessModal.value = true
      
      loadSousComptes()
      if (filteredSousComptes.value.length <= (currentPage.value - 1) * itemsPerPage) {
        currentPage.value = Math.max(1, currentPage.value - 1)
      }
    } catch (error) {
      console.error('Erreur suppression:', error)
      // MODIFIÉ : Utilisation du popup erreur
      errorModalMessage.value = error.response?.data?.message || 'Erreur lors de la suppression'
      showErrorModal.value = true
    }
  }
}

// --- Computed & Pagination ---
const debounceSearch = debounce((val) => {
  filters.value.search = val
  currentPage.value = 1
}, 300)

const filteredSousComptes = computed(() => {
  return sousComptes.value.filter(sc => {
    const matchCompte = !filters.value.compte_id || sc.Id_Compte == filters.value.compte_id
    const matchSearch = !filters.value.search ||
      sc.Code_sous_compte?.toLowerCase().includes(filters.value.search.toLowerCase()) ||
      sc.Libelle?.toLowerCase().includes(filters.value.search.toLowerCase())
    return matchCompte && matchSearch
  })
})

const paginatedSousComptes = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  const end = start + itemsPerPage
  return filteredSousComptes.value.slice(start, end)
})

const totalPages = computed(() => {
  return Math.ceil(filteredSousComptes.value.length / itemsPerPage)
})
</script>

<template>
  <AppLayout
    :user="user"
    icon="bi-diagram-3"
    title="Sous-comptes"
    subtitle="Sous-comptes utilisés pour la saisie des écritures"
    section="Structure"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreateModal">Nouveau sous-compte</UiButton>
    </template>

    <div class="fs-stack">
      <section class="fs-card fs-filters" aria-label="Filtres">
        <div class="fs-field">
          <label for="f-compte" class="fs-label">Compte</label>
          <select id="f-compte" v-model="filters.compte_id" class="fs-select">
            <option value="">Tous les comptes</option>
            <option v-for="compte in comptes" :key="compte.Id_Compte" :value="compte.Id_Compte">
              {{ compte.Code_compte }} - {{ compte.Libelle }}
            </option>
          </select>
        </div>
        <div class="fs-field">
          <label for="f-search" class="fs-label">Recherche</label>
          <input id="f-search" type="search" placeholder="Code ou libellé…" :value="filters.search" @input="debounceSearch($event.target.value)" class="fs-input" />
        </div>
      </section>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Liste des sous-comptes</h2>
          <UiBadge tone="primary">{{ filteredSousComptes.length }} sous-compte(s)</UiBadge>
        </div>
        <UiSkeleton v-if="loading" variant="table" :rows="8" :cols="4" />
        <div v-else class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Libellé</th>
                <th>Compte</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sc in paginatedSousComptes" :key="sc.Id_Sous_compte">
                <td class="font-bold">{{ sc.Code_sous_compte }}</td>
                <td>{{ sc.Libelle }}</td>
                <td><UiBadge size="sm">{{ sc.compte?.Code_compte }}</UiBadge></td>
                <td class="actions">
                  <div class="fs-row-actions">
                    <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="openEditModal(sc)">Modifier</UiButton>
                    <UiButton variant="soft-danger" size="sm" icon="bi-trash" @click="deleteSousCompte(sc.Id_Sous_compte)">Supprimer</UiButton>
                  </div>
                </td>
              </tr>
              <tr v-if="!paginatedSousComptes.length">
                <td colspan="4" class="fs-empty">Aucun sous-compte ne correspond aux filtres.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-model="currentPage" :total-pages="totalPages" :total="filteredSousComptes.length" :per-page="itemsPerPage" />
      </section>
    </div>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier le sous-compte' : 'Nouveau sous-compte'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'">
      <form id="form-sous-compte" class="ui-form" @submit.prevent="saveSousCompte">
        <div class="ui-field ui-field--full">
          <label class="required" for="m-compte">Compte</label>
          <select id="m-compte" v-model="form.Id_Compte" @change="updateCode" required>
            <option v-for="compte in comptes" :key="compte.Id_Compte" :value="compte.Id_Compte">
              {{ compte.Code_compte }} - {{ compte.Libelle }}
            </option>
          </select>
        </div>
        <div class="ui-field">
          <label class="required" for="m-suffixe">Suffixe</label>
          <input id="m-suffixe" type="text" inputmode="numeric" pattern="[0-9]{1,3}" title="1 à 3 chiffres" v-model="form.suffixe" @input="updateCode" maxlength="3" placeholder="001" required />
        </div>
        <div class="ui-field">
          <label for="m-code">Code complet</label>
          <input id="m-code" type="text" v-model="form.Code_sous_compte" readonly />
        </div>
        <div class="ui-field ui-field--full">
          <label class="required" for="m-libelle">Libellé</label>
          <input id="m-libelle" type="text" v-model="form.Libelle" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-sous-compte" :loading="saving">{{ isEditing ? 'Modifier' : 'Enregistrer' }}</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="showSuccessModal" tone="success" title="Succès" size="sm">
      <p class="ui-modal__message">{{ successModalMessage }}</p>
      <template #footer><UiButton variant="primary" @click="showSuccessModal = false">OK</UiButton></template>
    </UiModal>

    <UiModal v-model="showErrorModal" tone="danger" title="Erreur" size="sm">
      <p class="ui-modal__message">{{ errorModalMessage }}</p>
      <template #footer><UiButton variant="primary" @click="showErrorModal = false">OK</UiButton></template>
    </UiModal>
  </AppLayout>
</template>
