<template>
  <AppLayout
    :user="user"
    icon="bi-tags"
    title="Types de journaux"
    subtitle="Catégories utilisées pour classer les journaux"
    section="Saisie"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreate">Nouveau type</UiButton>
    </template>

    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">Liste des types de journaux</h2>
        <UiBadge tone="primary">{{ typeJournals.length }} type(s)</UiBadge>
      </div>
      <UiSkeleton v-if="loading" variant="table" :rows="4" :cols="3" />
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Type</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="journal in typeJournals" :key="journal.Id_Type_Journal">
              <td>{{ journal.Id_Type_Journal }}</td>
              <td class="font-bold">{{ journal.Type }}</td>
              <td class="actions">
                <div class="fs-row-actions">
                  <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="editTypeJournal(journal)">Modifier</UiButton>
                  <UiButton variant="soft-danger" size="sm" icon="bi-trash" @click="deleteTypeJournal(journal.Id_Type_Journal)">Supprimer</UiButton>
                </div>
              </td>
            </tr>
            <tr v-if="!typeJournals.length"><td colspan="3" class="fs-empty">Aucun type de journal.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier le type de journal' : 'Nouveau type de journal'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'" size="sm">
      <form id="form-type-journal" class="ui-form ui-form--1" @submit.prevent="isEditing ? updateTypeJournal() : addTypeJournal()">
        <div class="ui-field">
          <label for="journal-type" class="required">Type de journal</label>
          <input v-model="form.Type" id="journal-type" type="text" placeholder="Achats, Ventes, Trésorerie…" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-type-journal" :loading="saving">{{ isEditing ? 'Mettre à jour' : 'Ajouter' }}</UiButton>
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
import { getUser } from "../../../services/Auth";

const user = ref(null);
const API_URL = `${API_BASE}/type-journals`;

const typeJournals = ref([]);
const isEditing = ref(false);
const editId = ref(null);
const showModal = ref(false);
const loading = ref(true);
const saving = ref(false);
const errorOpen = ref(false);
const errorMessage = ref('');
const showError = (e, defaut) => { errorMessage.value = e?.response?.data?.message || defaut; errorOpen.value = true; };

const form = ref({ Type: "" });

const token = localStorage.getItem("token");
if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const fetchTypeJournals = async () => {
  try {
    const response = await axios.get(API_URL);
    typeJournals.value = response.data;
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { Type: "" };
  isEditing.value = false;
  editId.value = null;
};

const openCreate = () => { resetForm(); showModal.value = true; };

const enregistrer = async (appel) => {
  saving.value = true;
  try {
    await appel();
    showModal.value = false;
    resetForm();
    fetchTypeJournals();
  } catch (e) {
    showError(e, "Erreur lors de l'enregistrement");
  } finally {
    saving.value = false;
  }
};

const addTypeJournal = () => enregistrer(() => axios.post(API_URL, form.value));
const updateTypeJournal = () => enregistrer(() => axios.put(`${API_URL}/${editId.value}`, form.value));

const deleteTypeJournal = async (id) => {
  if (!confirm("Voulez-vous vraiment supprimer ce type de journal ?")) return;
  try {
    await axios.delete(`${API_URL}/${id}`);
    fetchTypeJournals();
  } catch (e) {
    showError(e, "Suppression impossible");
  }
};

const editTypeJournal = (journal) => {
  isEditing.value = true;
  editId.value = journal.Id_Type_Journal;
  form.value = { ...journal };
  showModal.value = true;
};

onMounted(async () => {
  if (!token) return;
  try {
    user.value = (await getUser(token)).data;
  } catch (err) {
    localStorage.removeItem("token");
    window.location.href = "/";
    return;
  }
  fetchTypeJournals();
});
</script>
