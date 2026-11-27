<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'
import { useRouter } from 'vue-router'
import { getUser } from "../../../services/Auth";

const router = useRouter();
const user = ref(null);

const handleNavigation = (item) => {
  router.push(item.route);
};

const classes = ref([])
const rubriques = ref([])
const comptes = ref([])
const showModal = ref(false)
const isEditing = ref(false)
const currentPage = ref(1)
const itemsPerPage = 20

const API_URL = `${API_BASE}`

const filters = ref({
  classe_id: '',
  rubrique_id: '',
  compte_id: '',
  search: ''
})

const form = ref({
  id: null,
  Code_compte: '',
  Libelle: '',
  Id_Rubrique: '',
  Id_Classe: '',
  suffixe: ''
})

const token = localStorage.getItem("token");

if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const loadClasses = async () => {
  try {
    const response = await axios.get(`${API_URL}/classes`)
    classes.value = response.data.data || response.data
  } catch (error) {
    console.error('Erreur chargement classes:', error)
  }
}

const loadRubriques = async () => {
  try {
    const response = await axios.get(`${API_URL}/rubriques`)
    rubriques.value = response.data.data || response.data
  } catch (error) {
    console.error('Erreur chargement rubriques:', error)
  }
}

const loading = ref(true)
const saving = ref(false)
const notice = ref({ open: false, tone: 'danger', title: '', message: '' })
const alert = (message, tone = 'danger') => {
  notice.value = { open: true, tone, title: tone === 'success' ? 'Succès' : 'Erreur', message }
}

const loadComptes = async () => {
  try {
    const response = await axios.get(`${API_URL}/comptes`)
    comptes.value = response.data.data || response.data
  } catch (error) {
    console.error('Erreur chargement comptes:', error)
  } finally {
    loading.value = false
  }
}

const filteredComptes = computed(() => {
  return comptes.value.filter(c => {
    const matchClasse = !filters.value.classe_id || c.rubrique?.Id_Classe == filters.value.classe_id
    const matchRubrique = !filters.value.rubrique_id || c.Id_Rubrique == filters.value.rubrique_id
    const matchCompte = !filters.value.compte_id || c.Id_Compte == filters.value.compte_id
    const matchSearch = !filters.value.search ||
      c.Code_compte?.toLowerCase().includes(filters.value.search.toLowerCase()) ||
      c.Libelle?.toLowerCase().includes(filters.value.search.toLowerCase())
    return matchClasse && matchRubrique && matchCompte && matchSearch
  })
})

const paginatedComptes = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  const end = start + itemsPerPage
  return filteredComptes.value.slice(start, end)
})

const totalPages = computed(() => {
  return Math.ceil(filteredComptes.value.length / itemsPerPage)
})


const debounceSearch = debounce((val) => {
  filters.value.search = val
  currentPage.value = 1
}, 300)

const openCreateModal = () => {
  isEditing.value = false
  form.value = { id: null, Code_compte: '', Libelle: '', Id_Rubrique: '', Id_Classe: '', suffixe: '' }
  showModal.value = true
}

const openEditModal = (compte) => {
  isEditing.value = true
  form.value = {
    id: compte.Id_Compte,
    Code_compte: compte.Code_compte,
    Libelle: compte.Libelle,
    Id_Rubrique: compte.Id_Rubrique,
    Id_Classe: compte.rubrique?.Id_Classe || '',
    suffixe: compte.Code_compte.slice(-1)
  }
  showModal.value = true
}

const viewGrandlivre = (compte) => {
  try {
    console.log("Navigating to /grand-livres/" + compte.Code_compte);
    router.push(`/grand-livres/${compte.Code_compte}`);
  } catch (error) {
    console.error("Erreur lors de la navigation:", error);
  }
};

const updateCode = () => {
  const rubrique = rubriques.value.find(r => r.Id_Rubrique === form.value.Id_Rubrique)
  if (rubrique && form.value.suffixe) {
    form.value.Code_compte = `${rubrique.Code_rubrique}${form.value.suffixe.padStart(1, '0')}`
  } else {
    form.value.Code_compte = ''
  }
}

watch(
  () => [form.value.Id_Rubrique, form.value.suffixe],
  () => {
    updateCode()
  }
)

const saveCompte = async () => {
  if (!form.value.Code_compte || !form.value.Libelle || !form.value.Id_Rubrique) {
    alert('Tous les champs sont obligatoires')
    return
  }

  saving.value = true
  try {
    if (isEditing.value) {
      await axios.put(`${API_URL}/comptes/${form.value.id}`, form.value)
      alert('Compte modifié avec succès', 'success')
    } else {
      await axios.post(`${API_URL}/comptes`, form.value)
      alert('Compte créé avec succès', 'success')
    }
    showModal.value = false
    loadComptes()
    currentPage.value = 1
  } catch (error) {
    console.error('Erreur enregistrement compte:', error.response?.data || error.message)
    alert(error.response?.data?.message || 'Erreur enregistrement compte')
  } finally {
    saving.value = false
  }
}

const deleteCompte = async (id) => {
  if (confirm('Voulez-vous vraiment supprimer ce compte ?')) {
    try {
      await axios.delete(`${API_URL}/comptes/${id}`)
      alert('Compte supprimé avec succès', 'success')
      loadComptes()
      if (filteredComptes.value.length <= (currentPage.value - 1) * itemsPerPage) {
        currentPage.value = Math.max(1, currentPage.value - 1)
      }
    } catch (error) {
      console.error('Erreur suppression compte:', error.response?.data || error.message)
      alert('Erreur suppression compte')
    }
  }
}

onMounted(async () => {
  console.log("Token récupéré :", token); // Vérifie si le token existe
  
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
      return; // Important : arrête l'exécution
    }
    loadClasses();
    loadRubriques();
    loadComptes();
  }
});
</script>

<template>
  <AppLayout
    :user="user"
    icon="bi-journals"
    title="Grand livre"
    subtitle="Tous les comptes mouvementés et leurs soldes"
    section="Saisie"
  >
    <template #actions>
      <UiButton icon="bi-plus-lg" @click="openCreateModal">Nouveau compte</UiButton>
    </template>

    <div class="fs-stack">
      <section class="fs-card fs-filters" aria-label="Filtres">
        <div class="fs-field">
          <label for="f-classe" class="fs-label">Classe</label>
          <select id="f-classe" v-model="filters.classe_id" class="fs-select" @change="currentPage = 1">
            <option value="">Toutes les classes</option>
            <option v-for="classe in classes" :key="classe.Id_Classe" :value="classe.Id_Classe">{{ classe.Code }}</option>
          </select>
        </div>
        <div class="fs-field">
          <label for="f-rubrique" class="fs-label">Rubrique</label>
          <select id="f-rubrique" v-model="filters.rubrique_id" class="fs-select" @change="currentPage = 1">
            <option value="">Toutes les rubriques</option>
            <option v-for="rubrique in rubriques" :key="rubrique.Id_Rubrique" :value="rubrique.Id_Rubrique">{{ rubrique.Code_rubrique }}</option>
          </select>
        </div>
        <div class="fs-field">
          <label for="f-search" class="fs-label">Recherche</label>
          <input id="f-search" :value="filters.search" @input="debounceSearch($event.target.value)" type="search" placeholder="Code ou libellé…" class="fs-input" />
        </div>
      </section>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Comptes</h2>
          <span class="fs-table-card__hint">Cliquez sur « Consulter » pour ouvrir le grand livre du compte</span>
        </div>
        <UiSkeleton v-if="loading" variant="table" :rows="8" :cols="5" />
        <div v-else class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Numéro</th>
                <th>Nom</th>
                <th>Classe</th>
                <th>Rubrique</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="compte in paginatedComptes" :key="compte.Id_Compte" class="detail-row">
                <td class="font-bold">{{ compte.Code_compte }}</td>
                <td>{{ compte.Libelle }}</td>
                <td><UiBadge size="sm">{{ compte.rubrique?.classe?.Code }}</UiBadge></td>
                <td>{{ compte.rubrique?.Libelle }}</td>
                <td class="actions">
                  <div class="fs-row-actions">
                    <UiButton variant="soft-primary" size="sm" icon-right="bi-arrow-right" @click="viewGrandlivre(compte)">Consulter</UiButton>
                  </div>
                </td>
              </tr>
              <tr v-if="!paginatedComptes.length">
                <td colspan="5" class="fs-empty">Aucun compte ne correspond aux filtres.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-model="currentPage" :total-pages="totalPages" :total="filteredComptes.length" :per-page="itemsPerPage" />
      </section>
    </div>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier le compte' : 'Nouveau compte'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'">
      <form id="form-compte-gl" class="ui-form" @submit.prevent="saveCompte">
        <div class="ui-field ui-field--full">
          <label class="required" for="m-rubrique">Rubrique</label>
          <select id="m-rubrique" v-model="form.Id_Rubrique" @change="updateCode" required>
            <option value="">Sélectionner une rubrique</option>
            <option v-for="rubrique in rubriques" :key="rubrique.Id_Rubrique" :value="rubrique.Id_Rubrique">
              {{ rubrique.Code_rubrique }} - {{ rubrique.Libelle }}
            </option>
          </select>
        </div>
        <div class="ui-field">
          <label class="required" for="m-suffixe">Suffixe</label>
          <input id="m-suffixe" type="text" v-model="form.suffixe" @input="updateCode" maxlength="1" placeholder="1" required />
        </div>
        <div class="ui-field">
          <label for="m-code">Code complet</label>
          <input id="m-code" type="text" v-model="form.Code_compte" readonly />
        </div>
        <div class="ui-field ui-field--full">
          <label class="required" for="m-libelle">Libellé</label>
          <input id="m-libelle" type="text" v-model="form.Libelle" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-compte-gl" :loading="saving">{{ isEditing ? 'Modifier' : 'Enregistrer' }}</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="notice.open" :tone="notice.tone" :title="notice.title" size="sm">
      <p class="ui-modal__message">{{ notice.message }}</p>
      <template #footer><UiButton variant="primary" @click="notice.open = false">OK</UiButton></template>
    </UiModal>
  </AppLayout>
</template>
