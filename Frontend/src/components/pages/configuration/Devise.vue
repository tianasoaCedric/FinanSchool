<template>
  <AppLayout
    :user="user"
    icon="bi-currency-exchange"
    title="Devises"
    subtitle="Monnaies utilisables dans les écritures"
    section="Structure"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreate">Nouvelle devise</UiButton>
    </template>

    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">Liste des devises</h2>
        <UiBadge tone="primary">{{ devises.length }} devise(s)</UiBadge>
      </div>
      <UiSkeleton v-if="loading" variant="table" :rows="4" :cols="5" />
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Libellé</th>
              <th>Code</th>
              <th>Sigle</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="devise in devises" :key="devise.Id_Devise">
              <td>{{ devise.Id_Devise }}</td>
              <td class="font-bold">{{ devise.Libelle }}</td>
              <td><UiBadge size="sm" tone="primary">{{ devise.Code }}</UiBadge></td>
              <td>{{ devise.Sigle }}</td>
              <td class="actions">
                <div class="fs-row-actions">
                  <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="editDevise(devise)">Modifier</UiButton>
                  <UiButton variant="soft-danger" size="sm" icon="bi-trash" @click="deleteDevise(devise.Id_Devise)">Supprimer</UiButton>
                </div>
              </td>
            </tr>
            <tr v-if="!devises.length"><td colspan="5" class="fs-empty">Aucune devise.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier la devise' : 'Nouvelle devise'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'" size="sm">
      <form id="form-devise" class="ui-form ui-form--1" @submit.prevent="saveDevise">
        <div class="ui-field">
          <label for="libelle" class="required">Libellé</label>
          <input v-model="form.Libelle" id="libelle" type="text" placeholder="Ariary" required />
        </div>
        <div class="ui-field">
          <label for="code" class="required">Code</label>
          <input v-model="form.Code" id="code" type="text" placeholder="MGA" required />
        </div>
        <div class="ui-field">
          <label for="sigle" class="required">Sigle</label>
          <input v-model="form.Sigle" id="sigle" type="text" placeholder="Ar" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-devise" :loading="saving">{{ isEditing ? 'Mettre à jour' : 'Ajouter' }}</UiButton>
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
const devises = ref([]);
const form = ref({ Libelle: "", Code: "", Sigle: "" });
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

const fetchDevises = async () => {
  try {
    const res = await axios.get(`${API_BASE}/devises`);
    devises.value = res.data;
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { Libelle: "", Code: "", Sigle: "" };
  isEditing.value = false;
  editingId.value = null;
};

const openCreate = () => { resetForm(); showModal.value = true; };

const editDevise = (devise) => {
  form.value = { ...devise };
  isEditing.value = true;
  editingId.value = devise.Id_Devise;
  showModal.value = true;
};

const saveDevise = async () => {
  saving.value = true;
  try {
    if (isEditing.value) {
      await axios.put(`${API_BASE}/devises/${editingId.value}`, form.value);
    } else {
      await axios.post(`${API_BASE}/devises`, form.value);
    }
    showModal.value = false;
    resetForm();
    fetchDevises();
  } catch (e) {
    showError(e, "Erreur lors de l'enregistrement");
  } finally {
    saving.value = false;
  }
};

const deleteDevise = async (id) => {
  if (!confirm("Supprimer cette devise ?")) return;
  try {
    await axios.delete(`${API_BASE}/devises/${id}`);
    fetchDevises();
  } catch (e) {
    showError(e, "Suppression impossible");
  }
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
  fetchDevises();
});
</script>
