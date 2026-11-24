<template>
  <AppLayout
    :user="user"
    icon="bi-credit-card"
    title="Modes de paiement"
    subtitle="Moyens de règlement associés aux écritures"
    section="Structure"
  >
<div class="card card-form">
        
        
        <div class="card-body">
          <!-- Formulaire -->
          <form @submit.prevent="isEditing ? updateModePaiement() : addModePaiement()" class="form-group">
            <div class="form-group">
              <label for="libelle" class="form-label required">Libellé</label>
              <input
                v-model="form.Libelle"
                id="libelle"
                type="text"
                placeholder="Libellé"
                class="form-input w-full"
                required
              />
            </div>
            <div class="form-group">
              <label for="abr" class="form-label required">Abréviation</label>
              <input
                v-model="form.Abr"
                id="abr"
                type="text"
                placeholder="Abréviation"
                class="form-input w-full"
                required
              />
            </div>

            <div class="d-flex gap-2">
              <UiButton variant="primary" type="submit">
                {{ isEditing ? "Mettre à jour" : "Ajouter" }}
              </UiButton>
              <UiButton variant="ghost" v-if="isEditing" @click="resetForm">
                Annuler
              </UiButton>
            </div>
          </form>

          <!-- Tableau -->
          <div class="table-container mt-4">
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th class="text-sm">ID</th>
                  <th class="text-sm">Libellé</th>
                  <th class="text-sm">Abréviation</th>
                  <th class="text-sm">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="mode in modePaiements" :key="mode.Id_Mode_paiement" class="fade-in">
                  <td>{{ mode.Id_Mode_paiement }}</td>
                  <td>{{ mode.Libelle }}</td>
                  <td>{{ mode.Abr }}</td>
                  <td class="d-flex gap-2">
                    <UiButton variant="soft-primary" @click="editModePaiement(mode)" size="sm">
                      Modifier
                    </UiButton>
                    <UiButton variant="soft-danger" @click="deleteModePaiement(mode.Id_Mode_paiement)" size="sm">
                      Supprimer
                    </UiButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        
        
      </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, onMounted } from "vue";
import { useRouter } from 'vue-router'
import axios from "axios";
import { getUser } from "../../../services/Auth";

const router = useRouter();
const user = ref(null);

const handleNavigation = (item) => {
  router.push(item.route);
};

const API_URL = `${API_BASE}/mode-paiements`;

const modePaiements = ref([]);
const isEditing = ref(false);
const editId = ref(null);

const form = ref({
  Libelle: "",
  Abr: "",
});

const token = localStorage.getItem("token"); // Récupérer le token

if (!token) {
  // Redirection vers login si pas de token
  window.location.href = "/";
} else {
  // Configurer Axios pour inclure le token dans toutes les requêtes
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}

// Charger les modes de paiement
const fetchModePaiements = async () => {
  const response = await axios.get(API_URL);
  modePaiements.value = response.data;
};

// Ajouter
const addModePaiement = async () => {
  await axios.post(API_URL, form.value);
  fetchModePaiements();
  resetForm();
};

// Supprimer
const deleteModePaiement = async (id) => {
  if (confirm("Voulez-vous vraiment supprimer ce mode de paiement ?")) {
    await axios.delete(`${API_URL}/${id}`);
    fetchModePaiements();
  }
};

// Préparer édition
const editModePaiement = (mode) => {
  isEditing.value = true;
  editId.value = mode.Id_Mode_paiement;
  form.value = { ...mode };
};

// Mettre à jour
const updateModePaiement = async () => {
  await axios.put(`${API_URL}/${editId.value}`, form.value);
  fetchModePaiements();
  resetForm();
};

// Réinitialiser
const resetForm = () => {
  form.value = { Libelle: "", Abr: "" };
  isEditing.value = false;
  editId.value = null;
};

// Charger au montage
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
    fetchModePaiements();
  }
});
</script>

<style scoped>
.dashboard-container {
  display: flex;
  min-height: 100vh;
  flex-direction: column;
}

.main-content {
  margin-left: 278px;
  padding: 32px;
  flex: 1;
  background: var(--fs-surface-2);
  min-height: calc(100vh - 80px);
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0;
    padding: 16px;
  }
}
</style>
