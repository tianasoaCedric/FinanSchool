<template>
  <AppLayout
    :user="user"
    icon="bi-journal-text"
    title="Journaux"
    subtitle="Journaux comptables de l'établissement"
    section="Saisie"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreate">Nouveau journal</UiButton>
    </template>

    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">Liste des journaux</h2>
        <UiBadge tone="primary">{{ journals.length }} journal(aux)</UiBadge>
      </div>
      <UiSkeleton v-if="loading" variant="table" :rows="5" :cols="6" />
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Code</th>
              <th>Libellé</th>
              <th>Type</th>
              <th>Sous-compte</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="journal in journals" :key="journal.Id_Journal">
              <td>{{ journal.Id_Journal }}</td>
              <td><UiBadge size="sm" tone="primary">{{ journal.Code }}</UiBadge></td>
              <td class="font-bold">{{ journal.Libelle }}</td>
              <td>{{ journal.type_journal?.Type || '—' }}</td>
              <td>{{ journal.sous_compte?.Libelle || '—' }}</td>
              <td class="actions">
                <div class="fs-row-actions">
                  <UiButton size="sm" icon="bi-eye" @click="viewEcritures(journal.Id_Journal)">Écritures</UiButton>
                  <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="editJournal(journal)">Modifier</UiButton>
                  <UiButton variant="soft-danger" size="sm" icon="bi-slash-circle" @click="deleteJournal(journal.Id_Journal)">Désactiver</UiButton>
                </div>
              </td>
            </tr>
            <tr v-if="!journals.length"><td colspan="6" class="fs-empty">Aucun journal.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier le journal' : 'Nouveau journal'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'">
      <form id="form-journal" class="ui-form" @submit.prevent="saveJournal">
        <div class="ui-field">
          <label class="required" for="j-code">Code</label>
          <input id="j-code" v-model="form.Code" type="text" placeholder="AC" required />
        </div>
        <div class="ui-field">
          <label class="required" for="j-type">Type de journal</label>
          <select id="j-type" v-model="form.Id_Type_Journal" required>
            <option value="">— Sélectionner —</option>
            <option v-for="type in typeJournals" :key="type.Id_Type_Journal" :value="type.Id_Type_Journal">{{ type.Type }}</option>
          </select>
        </div>
        <div class="ui-field ui-field--full">
          <label class="required" for="j-libelle">Libellé</label>
          <input id="j-libelle" v-model="form.Libelle" type="text" placeholder="Journal des achats" required />
        </div>
        <div class="ui-field ui-field--full">
          <label for="j-sc">Sous-compte (optionnel)</label>
          <select id="j-sc" v-model="form.Id_Sous_compte">
            <option value="">— Aucun —</option>
            <option v-for="compte in sousComptes" :key="compte.Id_Sous_compte" :value="compte.Id_Sous_compte">
              {{ compte.Code_sous_compte }} - {{ compte.Libelle }}
            </option>
          </select>
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-journal" :loading="saving">{{ isEditing ? 'Mettre à jour' : 'Ajouter' }}</UiButton>
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
import { ref, onMounted } from "vue";
import axios from "axios";
import { useRouter } from 'vue-router';
import { getUser } from "../../../services/Auth";

const router = useRouter();
const user = ref(null);

const journals = ref([]);
const typeJournals = ref([]);
const sousComptes = ref([]);

const form = ref({ Code: "", Libelle: "", Id_Type_Journal: "", Id_Sous_compte: "" });

const isEditing = ref(false);
const editingId = ref(null);
const showModal = ref(false);
const loading = ref(true);
const saving = ref(false);
const errorOpen = ref(false);
const errorMessage = ref('');
const showError = (e, defaut) => { errorMessage.value = e?.response?.data?.message || defaut; errorOpen.value = true; };

const token = localStorage.getItem("token");
if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const fetchJournals = async () => {
  try {
    const res = await axios.get(`${API_BASE}/journals`);
    journals.value = res.data;
  } catch (error) {
    console.error("Erreur lors du chargement des journaux:", error);
    journals.value = [];
  } finally {
    loading.value = false;
  }
};

const fetchOptions = async () => {
  try {
    const [types, comptes] = await Promise.all([
      axios.get(`${API_BASE}/type-journals`),
      axios.get(`${API_BASE}/sous-comptes`),
    ]);
    typeJournals.value = types.data;
    sousComptes.value = comptes.data;
  } catch (error) {
    console.error("Erreur lors du chargement des options:", error);
  }
};

const resetForm = () => {
  form.value = { Code: "", Libelle: "", Id_Type_Journal: "", Id_Sous_compte: "" };
  isEditing.value = false;
  editingId.value = null;
};

const openCreate = () => { resetForm(); showModal.value = true; };

const saveJournal = async () => {
  saving.value = true;
  try {
    if (isEditing.value) {
      await axios.put(`${API_BASE}/journals/${editingId.value}`, form.value);
    } else {
      await axios.post(`${API_BASE}/journals`, form.value);
    }
    showModal.value = false;
    resetForm();
    fetchJournals();
  } catch (error) {
    showError(error, "Erreur lors de l'enregistrement du journal");
  } finally {
    saving.value = false;
  }
};

const editJournal = (journal) => {
  form.value = {
    Code: journal.Code,
    Libelle: journal.Libelle,
    Id_Type_Journal: journal.Id_Type_Journal,
    Id_Sous_compte: journal.Id_Sous_compte || "",
  };
  isEditing.value = true;
  editingId.value = journal.Id_Journal;
  showModal.value = true;
};

const deleteJournal = async (id) => {
  if (!confirm("Désactiver ce journal ?")) return;
  try {
    await axios.delete(`${API_BASE}/journals/${id}`);
    fetchJournals();
  } catch (error) {
    showError(error, "Erreur lors de la suppression du journal");
  }
};

const viewEcritures = (id) => router.push(`/ecritures-journal/${id}`);

onMounted(async () => {
  if (!token) return;
  try {
    user.value = (await getUser(token)).data;
  } catch (err) {
    localStorage.removeItem("token");
    window.location.href = "/";
    return;
  }
  fetchJournals();
  fetchOptions();
});
</script>
