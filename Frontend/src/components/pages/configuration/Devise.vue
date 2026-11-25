<template>
  <AppLayout
    :user="user"
    icon="bi-currency-exchange"
    title="Devises"
    subtitle="Monnaies utilisables dans les écritures"
    section="Structure"
  >
<div class="card card-form">
        
        
        <div class="card-body">
          <!-- Formulaire -->
          <form @submit.prevent="saveDevise" class="form-group">
            <div class="form-group">
              <label for="libelle" class="form-label required">Libellé</label>
              <input
                v-model="form.Libelle"
                id="libelle"
                type="text"
                class="form-input w-full"
                required
              />
            </div>
            <div class="form-group">
              <label for="code" class="form-label required">Code</label>
              <input
                v-model="form.Code"
                id="code"
                type="text"
                class="form-input w-full"
                required
              />
            </div>
            <div class="form-group">
              <label for="sigle" class="form-label required">Sigle</label>
              <input
                v-model="form.Sigle"
                id="sigle"
                type="text"
                class="form-input w-full"
                required
              />
            </div>
            <div class="d-flex gap-2">
              <UiButton variant="primary" type="submit">
                {{ isEditing ? "Mettre à jour" : "Ajouter" }}
              </UiButton>
              <UiButton variant="ghost" v-if="isEditing" @click="cancelEdit">
                Annuler
              </UiButton>
            </div>
          </form>

          <!-- Tableau des devises -->
          <div class="table-container mt-4">
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th class="text-sm">#</th>
                  <th class="text-sm">Libellé</th>
                  <th class="text-sm">Code</th>
                  <th class="text-sm">Sigle</th>
                  <th class="text-sm">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="devise in devises" :key="devise.Id_Devise" class="fade-in">
                  <td>{{ devise.Id_Devise }}</td>
                  <td>{{ devise.Libelle }}</td>
                  <td>{{ devise.Code }}</td>
                  <td>{{ devise.Sigle }}</td>
                  <td class="d-flex gap-2 justify-center">
                    <UiButton variant="soft-primary" @click="editDevise(devise)" size="sm">
                      <i class="bi bi-pencil"></i>
                    </UiButton>
                    <UiButton variant="soft-danger" @click="deleteDevise(devise.Id_Devise)" size="sm">
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
  import axios from "axios";
  import { useRouter } from 'vue-router'
import { getUser } from "../../../services/Auth"; 

const router = useRouter();
const user = ref(null);

// Ajouter cette méthode pour gérer la navigation
const handleNavigation = (item) => {
  router.push(item.route);
};
  
  const devises = ref([]);
  const form = ref({
    Libelle: "",
    Code: "",
    Sigle: "",
  });
  const isEditing = ref(false);
  const editingId = ref(null);

  const token = localStorage.getItem("token"); // Récupérer le token

if (!token) {
  // Redirection vers login si pas de token
  window.location.href = "/";
} else {
  // Configurer Axios pour inclure le token dans toutes les requêtes
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;
}
  
  // Charger les devises
  const fetchDevises = async () => {
    const res = await axios.get(`${API_BASE}/devises`);
    devises.value = res.data;
  };
  
  // Sauvegarder (ajout ou update)
  const saveDevise = async () => {
    if (isEditing.value) {
      await axios.put(`${API_BASE}/devises/${editingId.value}`, form.value);
    } else {
      await axios.post(`${API_BASE}/devises`, form.value);
    }
    resetForm();
    fetchDevises();
  };
  
  // Modifier
  const editDevise = (devise) => {
    form.value = { ...devise };
    isEditing.value = true;
    editingId.value = devise.Id_Devise;
  };
  
  // Annuler modification
  const cancelEdit = () => {
    resetForm();
  };
  
  // Supprimer
  const deleteDevise = async (id) => {
    if (confirm("Supprimer cette devise ?")) {
      await axios.delete(`${API_BASE}/devises/${id}`);
      fetchDevises();
    }
  };
  
  // Reset formulaire
  const resetForm = () => {
    form.value = { Libelle: "", Code: "", Sigle: "" };
    isEditing.value = false;
    editingId.value = null;
  };
  
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
    fetchDevises();
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

/* Responsive design */
@media (max-width: 768px) {
  .main-content {
    margin-left: 0;
    padding: 16px;
  }
}
</style>
  