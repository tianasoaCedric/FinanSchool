<template>
  <AppLayout
    :user="user"
    icon="bi-credit-card"
    title="Modes de paiement"
    subtitle="Moyens de règlement associés aux écritures"
    section="Structure"
  >
    <template #actions>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreate">Nouveau mode</UiButton>
    </template>

    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">Liste des modes de paiement</h2>
        <UiBadge tone="primary">{{ modePaiements.length }} mode(s)</UiBadge>
      </div>
      <UiSkeleton v-if="loading" variant="table" :rows="4" :cols="4" />
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Libellé</th>
              <th>Abréviation</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="mode in modePaiements" :key="mode.Id_Mode_paiement">
              <td>{{ mode.Id_Mode_paiement }}</td>
              <td class="font-bold">{{ mode.Libelle }}</td>
              <td><UiBadge size="sm">{{ mode.Abr }}</UiBadge></td>
              <td class="actions">
                <div class="fs-row-actions">
                  <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="editModePaiement(mode)">Modifier</UiButton>
                  <UiButton variant="soft-danger" size="sm" icon="bi-trash" @click="deleteModePaiement(mode.Id_Mode_paiement)">Supprimer</UiButton>
                </div>
              </td>
            </tr>
            <tr v-if="!modePaiements.length"><td colspan="4" class="fs-empty">Aucun mode de paiement.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <UiModal v-model="showModal" :title="isEditing ? 'Modifier le mode de paiement' : 'Nouveau mode de paiement'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'" size="sm">
      <form id="form-mode" class="ui-form ui-form--1" @submit.prevent="isEditing ? updateModePaiement() : addModePaiement()">
        <div class="ui-field">
          <label for="libelle" class="required">Libellé</label>
          <input v-model="form.Libelle" id="libelle" type="text" placeholder="Virement bancaire" required />
        </div>
        <div class="ui-field">
          <label for="abr" class="required">Abréviation</label>
          <input v-model="form.Abr" id="abr" type="text" placeholder="VIR" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-mode" :loading="saving">{{ isEditing ? 'Mettre à jour' : 'Ajouter' }}</UiButton>
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
const API_URL = `${API_BASE}/mode-paiements`;

const modePaiements = ref([]);
const isEditing = ref(false);
const editId = ref(null);
const showModal = ref(false);
const loading = ref(true);
const saving = ref(false);
const errorOpen = ref(false);
const errorMessage = ref('');
const showError = (e, defaut) => { errorMessage.value = e?.response?.data?.message || defaut; errorOpen.value = true; };

const form = ref({ Libelle: "", Abr: "" });

const token = localStorage.getItem("token");
if (!token) {
  window.location.href = "/";
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

const fetchModePaiements = async () => {
  try {
    const response = await axios.get(API_URL);
    modePaiements.value = response.data;
  } finally {
    loading.value = false;
  }
};

const resetForm = () => {
  form.value = { Libelle: "", Abr: "" };
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
    fetchModePaiements();
  } catch (e) {
    showError(e, "Erreur lors de l'enregistrement");
  } finally {
    saving.value = false;
  }
};

const addModePaiement = () => enregistrer(() => axios.post(API_URL, form.value));
const updateModePaiement = () => enregistrer(() => axios.put(`${API_URL}/${editId.value}`, form.value));

const deleteModePaiement = async (id) => {
  if (!confirm("Voulez-vous vraiment supprimer ce mode de paiement ?")) return;
  try {
    await axios.delete(`${API_URL}/${id}`);
    fetchModePaiements();
  } catch (e) {
    showError(e, "Suppression impossible");
  }
};

const editModePaiement = (mode) => {
  isEditing.value = true;
  editId.value = mode.Id_Mode_paiement;
  form.value = { ...mode };
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
  fetchModePaiements();
});
</script>
