<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { debounce } from 'lodash'
import { useRouter } from 'vue-router'
import { getUser } from "../../../services/Auth"; 
const router = useRouter();
const user = ref(null);
const showChat = ref(false);

const handleNavigation = (item) => {
  router.push(item.route);
};

const API_URL = `${API_BASE}`
const rubriques = ref([])
const classes = ref([])
const showModal = ref(false)
const isEditing = ref(false)
const currentPage = ref(1)
const itemsPerPage = 10

const filters = ref({ search: '', classe: '' })
const form = ref({ id: null, Code_rubrique: '', Libelle: '', Id_Classe: '', suffixe: '' })

const token = localStorage.getItem("token");

if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const loadClasses = async () => {
  const res = await axios.get(`${API_URL}/classes`)
  classes.value = res.data.data || res.data
}

const loadRubriques = async () => {
  try {
    const res = await axios.get(`${API_URL}/rubriques`)
    rubriques.value = res.data.data || res.data
  } finally {
    loading.value = false
  }
}

const filteredRubriques = computed(() => {
  return rubriques.value.filter(r => {
    const matchSearch =
      !filters.value.search ||
      r.Code_rubrique.includes(filters.value.search) ||
      r.Libelle.toLowerCase().includes(filters.value.search.toLowerCase())

    const matchClasse =
      !filters.value.classe || r.Id_Classe === filters.value.classe

    return matchSearch && matchClasse
  })
})

const paginatedRubriques = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  const end = start + itemsPerPage
  return filteredRubriques.value.slice(start, end)
})

const totalPages = computed(() => {
  return Math.ceil(filteredRubriques.value.length / itemsPerPage)
})

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const showErrorModal = ref(false)
const showError = (msg) => { errorMessage.value = msg; showErrorModal.value = true }

const debounceSearch = debounce((val) => { filters.value.search = val; currentPage.value = 1 }, 300)

const openCreateModal = () => {
  isEditing.value = false
  form.value = { id: null, Code_rubrique: '', Libelle: '', Id_Classe: '', suffixe: '' }
  showModal.value = true
}

const openEditModal = (rubrique) => {
  isEditing.value = true
  form.value = {
    id: rubrique.Id_Rubrique,
    Code_rubrique: rubrique.Code_rubrique,
    Libelle: rubrique.Libelle,
    Id_Classe: rubrique.Id_Classe,
    suffixe: rubrique.Code_rubrique.slice(1)
  }
  showModal.value = true
}

const updateCode = () => {
  const classe = classes.value.find(c => c.Id_Classe === form.value.Id_Classe)
  if (classe && form.value.suffixe) {
    form.value.Code_rubrique = classe.Code + form.value.suffixe.padStart(1, "0")
  }
}

const saveRubrique = async () => {
  saving.value = true
  try {
    if (isEditing.value) {
      await axios.put(`${API_URL}/rubriques/${form.value.id}`, form.value)
    } else {
      await axios.post(`${API_URL}/rubriques`, form.value)
    }
    loadRubriques()
    showModal.value = false
    currentPage.value = 1
  } catch (e) {
    showError(e.response?.data?.message || "Erreur lors de l'enregistrement")
  } finally {
    saving.value = false
  }
}

const deleteRubrique = async (id) => {
  if (confirm("Supprimer cette rubrique ?")) {
    try {
      await axios.delete(`${API_URL}/rubriques/${id}`)
    } catch (e) {
      showError(e.response?.data?.message || "Erreur lors de la suppression")
      return
    }
    loadRubriques()
    if (filteredRubriques.value.length <= (currentPage.value - 1) * itemsPerPage) {
      currentPage.value = Math.max(1, currentPage.value - 1)
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
  }
});


</script>

<template>
  <AppLayout
    :user="user"
    icon="bi-diagram-3"
    title="Rubriques"
    subtitle="Regroupements des comptes par classe du plan comptable"
    section="Structure"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreateModal">Nouvelle rubrique</UiButton>
    </template>

    <div class="fs-stack">
      <section class="fs-card fs-filters" aria-label="Filtres">
        <div class="fs-field">
          <label for="f-classe" class="fs-label">Classe</label>
          <select id="f-classe" v-model="filters.classe" class="fs-select" @change="currentPage = 1">
            <option value="">Toutes les classes</option>
            <option v-for="classe in classes" :key="classe.Id_Classe" :value="classe.Id_Classe">
              {{ classe.Code }} - {{ classe.Libelle }}
            </option>
          </select>
        </div>
        <div class="fs-field">
          <label for="f-search" class="fs-label">Recherche</label>
          <input id="f-search" type="search" placeholder="Code ou libellé…" @input="debounceSearch($event.target.value)" class="fs-input" />
        </div>
      </section>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Liste des rubriques</h2>
          <UiBadge tone="primary">{{ filteredRubriques.length }} rubrique(s)</UiBadge>
        </div>
        <UiSkeleton v-if="loading" variant="table" :rows="8" :cols="4" />
        <div v-else class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Libellé</th>
                <th>Classe</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rubrique in paginatedRubriques" :key="rubrique.Id_Rubrique">
                <td class="font-bold">{{ rubrique.Code_rubrique }}</td>
                <td>{{ rubrique.Libelle }}</td>
                <td><UiBadge size="sm">{{ rubrique.classe?.Code }}</UiBadge> {{ rubrique.classe?.Libelle }}</td>
                <td class="actions">
                  <div class="fs-row-actions">
                    <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="openEditModal(rubrique)">Modifier</UiButton>
                    <UiButton variant="soft-danger" size="sm" icon="bi-trash" @click="deleteRubrique(rubrique.Id_Rubrique)">Supprimer</UiButton>
                  </div>
                </td>
              </tr>
              <tr v-if="!paginatedRubriques.length">
                <td colspan="4" class="fs-empty">Aucune rubrique ne correspond aux filtres.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-model="currentPage" :total-pages="totalPages" :total="filteredRubriques.length" :per-page="itemsPerPage" />
      </section>
    </div>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier la rubrique' : 'Nouvelle rubrique'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'">
      <form id="form-rubrique" class="ui-form" @submit.prevent="saveRubrique">
        <div v-if="!isEditing" class="ui-field ui-field--full">
          <label class="required" for="m-classe">Classe</label>
          <select id="m-classe" v-model="form.Id_Classe" @change="updateCode" required>
            <option v-for="classe in classes" :key="classe.Id_Classe" :value="classe.Id_Classe">
              {{ classe.Code }} - {{ classe.Libelle }}
            </option>
          </select>
        </div>
        <div class="ui-field">
          <label class="required" for="m-suffixe">Suffixe</label>
          <input id="m-suffixe" type="text" inputmode="numeric" pattern="[0-9]" title="Un chiffre" v-model="form.suffixe" @input="updateCode" maxlength="1" placeholder="1" required />
        </div>
        <div class="ui-field">
          <label for="m-code">Code complet</label>
          <input id="m-code" type="text" v-model="form.Code_rubrique" readonly />
        </div>
        <div class="ui-field ui-field--full">
          <label class="required" for="m-libelle">Libellé</label>
          <input id="m-libelle" type="text" v-model="form.Libelle" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-rubrique" :loading="saving">{{ isEditing ? 'Modifier' : 'Enregistrer' }}</UiButton>
      </template>
    </UiModal>

    <UiModal v-model="showErrorModal" tone="danger" title="Erreur" size="sm">
      <p class="ui-modal__message">{{ errorMessage }}</p>
      <template #footer><UiButton variant="primary" @click="showErrorModal = false">OK</UiButton></template>
    </UiModal>
  </AppLayout>
</template>
