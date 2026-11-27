<script setup>
import AppLayout from "@/components/template/AppLayout.vue";
import { API_BASE } from '@/config/api';
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { getUser } from "../../../services/Auth"

const user = ref(null)
const API_URL = `${API_BASE}`

const immobilisations = ref([])
const sousComptes = ref([])
const tauxAmortissement = ref([])
const showModal = ref(false)
const isEditing = ref(false)
const formVerrouille = ref(false)
const currentPage = ref(1)
const itemsPerPage = 10
const exerciceDate = ref("")
const filters = ref({ search: '' })
const loading = ref(true)
const saving = ref(false)

// Messages (remplacent alert())
const notice = ref({ open: false, tone: 'danger', title: '', message: '' })
const alert = (message, tone = 'danger') => {
  notice.value = { open: true, tone, title: tone === 'success' ? 'Opération effectuée' : 'Erreur', message }
}

const ficheVide = () => ({
  id: null, libelle: '', Id_Sous_compte: '', taux_amortissement_id: '', valeur_brute: '',
  date_acquisition: '', date_debut_utilisation: '', Id_Sous_compte_amortissement: ''
})
const form = ref(ficheVide())

// Sortie d'actif
const showSortie = ref(false)
const sortie = ref({ immo: null, date_sortie: '', prix_cession: '', Id_Sous_compte_contrepartie: '' })

// Taux
const showTaux = ref(false)
const formTaux = ref({ id: null, intitule: '', taux: '', duree: '' })

const token = localStorage.getItem("token")
if (!token) {
  window.location.href = "/"
} else {
  axios.defaults.headers.common["Authorization"] = `Bearer ${token}`
}

// --- Comptes ------------------------------------------------------------
const NON_AMORTISSABLES = ['211', '23', '26', '27']
const estAmortissable = (code) => !!code && code.startsWith('2') && !code.startsWith('28') && !code.startsWith('29')
  && !NON_AMORTISSABLES.some(p => code.startsWith(p))
const comptesImmo = computed(() => sousComptes.value.filter(sc => {
  const c = String(sc.Code_sous_compte)
  return c.startsWith('2') && !c.startsWith('28') && !c.startsWith('29')
}))
const comptesAmort = computed(() => sousComptes.value.filter(sc => String(sc.Code_sous_compte).startsWith('28')))
const comptesContrepartie = computed(() => sousComptes.value.filter(sc => /^(4|5)/.test(String(sc.Code_sous_compte))))
const codeSelectionne = computed(() => String(sousComptes.value.find(sc => sc.Id_Sous_compte === form.value.Id_Sous_compte)?.Code_sous_compte || ''))
const ficheAmortissable = computed(() => estAmortissable(codeSelectionne.value))

// --- Formatage ------------------------------------------------------------
const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`
}
const formatMontant = (v) => (v === null || v === undefined || v === '') ? '' :
  Number(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const messageErreur = (error, defaut) => {
  const d = error?.response?.data
  const premiere = d?.errors && Object.values(d.errors).flat()[0]
  return premiere || d?.message || defaut
}

// --- Chargements -----------------------------------------------------------
const loadReferentiels = async () => {
  sousComptes.value = (await axios.get(`${API_URL}/sous-comptes`)).data
  tauxAmortissement.value = (await axios.get(`${API_URL}/taux-amortissement`)).data
}
const fetchExerciceCourant = async () => {
  const { data } = await axios.get(`${API_URL}/exercices/courant`)
  exerciceDate.value = data.exercice?.Date_fin ?? data.Date_fin ?? ""
}
const loadImmobilisations = async () => {
  immobilisations.value = (await axios.get(`${API_URL}/amortissement`)).data
}

// --- Fiches -----------------------------------------------------------------
const openCreateModal = () => {
  isEditing.value = false
  formVerrouille.value = false
  form.value = ficheVide()
  showModal.value = true
}
const openEditModal = (immo) => {
  isEditing.value = true
  formVerrouille.value = immo.verrouillee
  form.value = {
    id: immo.id, libelle: immo.libelle, Id_Sous_compte: immo.Id_Sous_compte,
    taux_amortissement_id: immo.taux_amortissement_id || '', valeur_brute: immo.valeur_brute,
    date_acquisition: immo.date_acquisition, date_debut_utilisation: immo.date_debut_utilisation,
    Id_Sous_compte_amortissement: immo.Id_Sous_compte_amortissement || ''
  }
  showModal.value = true
}
const saveImmobilisation = async () => {
  const payload = { ...form.value }
  if (!ficheAmortissable.value) payload.taux_amortissement_id = null
  if (!payload.Id_Sous_compte_amortissement) payload.Id_Sous_compte_amortissement = null
  if (!payload.date_debut_utilisation) payload.date_debut_utilisation = null
  saving.value = true
  try {
    if (isEditing.value && form.value.id) {
      await axios.put(`${API_URL}/amortissement/${form.value.id}`, payload)
    } else {
      await axios.post(`${API_URL}/amortissement`, payload)
    }
    showModal.value = false
    await Promise.all([loadImmobilisations(), loadReferentiels()])
  } catch (error) {
    alert(messageErreur(error, "Erreur lors de l'enregistrement de l'immobilisation"))
  } finally {
    saving.value = false
  }
}
const deleteImmobilisation = async (immo) => {
  if (!confirm(`Supprimer l'immobilisation « ${immo.libelle} » ?`)) return
  try {
    await axios.delete(`${API_URL}/amortissement/${immo.id}`)
    await loadImmobilisations()
    if (filteredImmobilisations.value.length <= (currentPage.value - 1) * itemsPerPage) {
      currentPage.value = Math.max(1, currentPage.value - 1)
    }
  } catch (error) {
    alert(messageErreur(error, 'Suppression impossible'))
  }
}

// --- Sorties ------------------------------------------------------------------
const openSortie = (immo) => {
  sortie.value = { immo, date_sortie: '', prix_cession: '', Id_Sous_compte_contrepartie: '' }
  showSortie.value = true
}
const enregistrerSortie = async () => {
  saving.value = true
  try {
    const { data } = await axios.post(`${API_URL}/amortissement/${sortie.value.immo.id}/sortie`, {
      date_sortie: sortie.value.date_sortie,
      prix_cession: sortie.value.prix_cession || 0,
      Id_Sous_compte_contrepartie: sortie.value.Id_Sous_compte_contrepartie || null,
    })
    showSortie.value = false
    alert(data.message, 'success')
    await loadImmobilisations()
  } catch (error) {
    alert(messageErreur(error, 'Sortie impossible'))
  } finally {
    saving.value = false
  }
}
const annulerSortie = async (immo) => {
  if (!confirm(`Annuler la sortie de « ${immo.libelle} » ? La pièce en brouillon sera supprimée.`)) return
  try {
    await axios.delete(`${API_URL}/amortissement/${immo.id}/sortie`)
    await loadImmobilisations()
  } catch (error) {
    alert(messageErreur(error, 'Annulation impossible'))
  }
}

// --- Taux ---------------------------------------------------------------------
const editerTaux = (t = null) => {
  formTaux.value = t ? { id: t.id, intitule: t.intitule, taux: t.taux, duree: t.duree } : { id: null, intitule: '', taux: '', duree: '' }
}
const enregistrerTaux = async () => {
  try {
    const payload = { intitule: formTaux.value.intitule, taux: formTaux.value.taux, duree: formTaux.value.duree || null }
    if (formTaux.value.id) await axios.put(`${API_URL}/taux-amortissement/${formTaux.value.id}`, payload)
    else await axios.post(`${API_URL}/taux-amortissement`, payload)
    editerTaux()
    await loadReferentiels()
  } catch (error) {
    alert(messageErreur(error, "Erreur lors de l'enregistrement du taux"))
  }
}
const supprimerTaux = async (t) => {
  if (!confirm(`Supprimer la catégorie « ${t.intitule} » ?`)) return
  try {
    await axios.delete(`${API_URL}/taux-amortissement/${t.id}`)
    await loadReferentiels()
  } catch (error) {
    alert(messageErreur(error, 'Suppression impossible'))
  }
}

// --- Liste ----------------------------------------------------------------------
const filteredImmobilisations = computed(() => {
  const s = filters.value.search.toLowerCase()
  return immobilisations.value.filter(immo => !s || immo.compte?.toLowerCase().includes(s) || immo.libelle?.toLowerCase().includes(s))
})
const paginatedImmobilisations = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  return filteredImmobilisations.value.slice(start, start + itemsPerPage)
})
const totalPages = computed(() => Math.ceil(filteredImmobilisations.value.length / itemsPerPage))

onMounted(async () => {
  if (!token) {
    window.location.href = "/"
    return
  }
  try {
    user.value = (await getUser(token)).data
  } catch (err) {
    localStorage.removeItem("token")
    window.location.href = "/"
    return
  }
  try {
    await fetchExerciceCourant()
    await loadReferentiels()
    await loadImmobilisations()
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <AppLayout
    :user="user"
    icon="bi-building-gear"
    title="Immobilisations & amortissements"
    subtitle="Registre des immobilisations, plans d'amortissement et sorties d'actif"
    section="États financiers"
  >
    <template #actions>
      <UiButton icon="bi-percent" @click="showTaux = true; editerTaux()">Taux d'amortissement</UiButton>
      <UiButton variant="primary" icon="bi-plus-lg" @click="openCreateModal">Nouvelle immobilisation</UiButton>
    </template>

    <div class="fs-stack">
      <section class="fs-card fs-toolbar" aria-label="Recherche">
        <div class="fs-toolbar__field">
          <label for="search" class="fs-label">Recherche</label>
          <input id="search" type="search" placeholder="Code, libellé ou compte…" v-model="filters.search" class="fs-input" @input="currentPage = 1" />
        </div>
        <div class="fs-stat">
          <span class="fs-label">Fin d'exercice</span>
          <span class="fs-value">{{ formatDate(exerciceDate) || '—' }}</span>
        </div>
        <div class="fs-stat">
          <span class="fs-label">Immobilisations</span>
          <span class="fs-value">{{ filteredImmobilisations.length }}</span>
        </div>
      </section>

      <p class="fs-alert fs-alert--info">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>
          Linéaire, au prorata des jours depuis la mise en service. Terrains (211), immobilisations en cours (23) et financières (26, 27) ne s'amortissent pas.
          Une immobilisation déjà amortie en comptabilité est verrouillée : seul son libellé reste modifiable.
        </span>
      </p>

      <section class="fs-card fs-table-card">
        <div class="fs-table-card__head">
          <h2 class="fs-table-card__title">Registre des immobilisations</h2>
          <span class="fs-table-card__hint">Montants en Ariary</span>
        </div>
        <UiSkeleton v-if="loading" variant="table" :rows="6" :cols="8" />
        <div v-else class="fs-table-wrap">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Compte</th>
                <th>Libellé</th>
                <th>Taux</th>
                <th class="text-right">Valeur brute</th>
                <th>Mise en service</th>
                <th class="text-right">Amort. cumulé fin exercice</th>
                <th class="text-right">VNC</th>
                <th>État</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="immo in paginatedImmobilisations" :key="immo.id">
                <td class="font-bold">{{ immo.compte }}<div v-if="immo.compte_amortissement" class="am-sous">→ {{ immo.compte_amortissement }}</div></td>
                <td>{{ immo.libelle }}</td>
                <td><UiBadge size="sm" :tone="immo.amortissable ? 'primary' : 'neutral'">{{ immo.amortissable ? `${immo.taux} %` : 'Non amortissable' }}</UiBadge></td>
                <td class="text-right">{{ formatMontant(immo.valeur_brute) }}</td>
                <td>{{ formatDate(immo.date_debut_utilisation) }}</td>
                <td class="text-right">{{ formatMontant(immo.cumul_amortissement) }}</td>
                <td class="text-right font-bold">{{ formatMontant(immo.vnc) }}</td>
                <td>
                  <UiBadge v-if="immo.date_sortie" tone="neutral" dot>Sortie le {{ formatDate(immo.date_sortie) }}</UiBadge>
                  <UiBadge v-else-if="immo.verrouillee" tone="warning" dot>Verrouillée</UiBadge>
                  <UiBadge v-else tone="success" dot>En service</UiBadge>
                </td>
                <td class="actions">
                  <div class="fs-row-actions">
                    <UiButton variant="soft-primary" size="sm" icon="bi-pencil" @click="openEditModal(immo)">Modifier</UiButton>
                    <UiButton v-if="!immo.date_sortie" size="sm" icon="bi-box-arrow-right" @click="openSortie(immo)">Sortie</UiButton>
                    <UiButton v-else-if="immo.id_piece_cession" size="sm" icon="bi-file-earmark-text" :to="`/ecriture/${immo.id_piece_cession}`">Pièce</UiButton>
                    <UiButton v-if="immo.date_sortie" variant="soft-danger" size="sm" icon="bi-arrow-counterclockwise" @click="annulerSortie(immo)">Annuler sortie</UiButton>
                    <UiButton v-if="!immo.verrouillee" variant="soft-danger" size="sm" icon="bi-trash" aria-label="Supprimer" title="Supprimer" @click="deleteImmobilisation(immo)" />
                  </div>
                </td>
              </tr>
              <tr v-if="!paginatedImmobilisations.length">
                <td colspan="9" class="fs-empty">Aucune immobilisation enregistrée.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <UiPagination v-model="currentPage" :total-pages="totalPages" :total="filteredImmobilisations.length" :per-page="itemsPerPage" />
      </section>
    </div>

    <!-- Fiche immobilisation -->
    <UiModal v-model="showModal" size="lg" :title="isEditing ? 'Modifier l\'immobilisation' : 'Nouvelle immobilisation'" :icon="isEditing ? 'bi-pencil' : 'bi-plus-lg'">
      <p v-if="formVerrouille" class="fs-alert fs-alert--warning am-mb">
        <i class="bi bi-lock" aria-hidden="true"></i> Immobilisation déjà amortie en comptabilité : seul le libellé est modifiable.
      </p>
      <form id="form-immo" class="ui-form" @submit.prevent="saveImmobilisation">
        <div class="ui-field ui-field--full">
          <label for="libelle" class="required">Libellé</label>
          <input id="libelle" type="text" v-model="form.libelle" required />
        </div>
        <div class="ui-field ui-field--full">
          <label for="compte" class="required">Compte d'immobilisation</label>
          <select id="compte" v-model="form.Id_Sous_compte" required :disabled="formVerrouille">
            <option v-for="sc in comptesImmo" :key="sc.Id_Sous_compte" :value="sc.Id_Sous_compte">
              {{ sc.Code_sous_compte }} - {{ sc.Libelle }}
            </option>
          </select>
        </div>
        <template v-if="ficheAmortissable || !form.Id_Sous_compte">
          <div class="ui-field">
            <label for="taux" class="required">Taux d'amortissement</label>
            <select id="taux" v-model="form.taux_amortissement_id" required :disabled="formVerrouille">
              <option v-for="t in tauxAmortissement" :key="t.id" :value="t.id">{{ t.intitule }} — {{ t.taux }} %</option>
            </select>
          </div>
          <div class="ui-field">
            <label for="compte-amort">Compte d'amortissement</label>
            <select id="compte-amort" v-model="form.Id_Sous_compte_amortissement" :disabled="formVerrouille">
              <option value="">Automatique (2Xyzzz → 28Xyzz)</option>
              <option v-for="sc in comptesAmort" :key="sc.Id_Sous_compte" :value="sc.Id_Sous_compte">
                {{ sc.Code_sous_compte }} - {{ sc.Libelle }}
              </option>
            </select>
          </div>
        </template>
        <p v-else class="ui-help ui-field--full">Compte non amortissable : aucun plan d'amortissement.</p>
        <div class="ui-field">
          <label for="valeur-brute" class="required">Valeur brute (coût HT)</label>
          <input id="valeur-brute" type="number" min="0.01" step="0.01" v-model="form.valeur_brute" required :disabled="formVerrouille" />
        </div>
        <div class="ui-field">
          <label for="date-acquisition" class="required">Date d'acquisition</label>
          <input id="date-acquisition" type="date" v-model="form.date_acquisition" required :disabled="formVerrouille" />
        </div>
        <div class="ui-field">
          <label for="date-debut-utilisation">Date de mise en service</label>
          <input id="date-debut-utilisation" type="date" v-model="form.date_debut_utilisation" :min="form.date_acquisition" :disabled="formVerrouille" />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-immo" :loading="saving">{{ isEditing ? 'Modifier' : 'Enregistrer' }}</UiButton>
      </template>
    </UiModal>

    <!-- Sortie d'actif -->
    <UiModal v-model="showSortie" title="Sortie de l'actif" :subtitle="sortie.immo?.libelle" icon="bi-box-arrow-right" tone="warning">
      <p class="ui-help am-mb">
        Génère une pièce en brouillon : reprise des amortissements cumulés (28), sortie de la valeur brute (2),
        prix de cession, et plus-value (752) ou moins-value (652). Sans prix : mise au rebut.
      </p>
      <form id="form-sortie" class="ui-form" @submit.prevent="enregistrerSortie">
        <div class="ui-field">
          <label for="date-sortie" class="required">Date de sortie</label>
          <input id="date-sortie" type="date" v-model="sortie.date_sortie" required />
        </div>
        <div class="ui-field">
          <label for="prix">Prix de cession</label>
          <input id="prix" type="number" min="0" step="0.01" v-model="sortie.prix_cession" placeholder="0 = mise au rebut" />
        </div>
        <div v-if="Number(sortie.prix_cession) > 0" class="ui-field ui-field--full">
          <label for="contrepartie" class="required">Compte qui reçoit le prix</label>
          <select id="contrepartie" v-model="sortie.Id_Sous_compte_contrepartie" required>
            <option v-for="sc in comptesContrepartie" :key="sc.Id_Sous_compte" :value="sc.Id_Sous_compte">
              {{ sc.Code_sous_compte }} - {{ sc.Libelle }}
            </option>
          </select>
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="showSortie = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-sortie" :loading="saving">Enregistrer la sortie</UiButton>
      </template>
    </UiModal>

    <!-- Taux d'amortissement -->
    <UiModal v-model="showTaux" size="lg" title="Taux d'amortissement" icon="bi-percent">
      <div class="am-taux-table">
        <table class="fs-table">
          <thead><tr><th>Catégorie</th><th class="text-right">Taux</th><th class="text-right">Durée</th><th class="actions">Actions</th></tr></thead>
          <tbody>
            <tr v-for="t in tauxAmortissement" :key="t.id">
              <td>{{ t.intitule }}</td>
              <td class="text-right"><UiBadge size="sm" tone="primary">{{ t.taux }} %</UiBadge></td>
              <td class="text-right">{{ t.duree }} {{ t.unite_duree || 'ans' }}</td>
              <td class="actions">
                <div class="fs-row-actions">
                  <UiButton variant="soft-primary" size="sm" icon="bi-pencil" aria-label="Modifier" title="Modifier" @click="editerTaux(t)" />
                  <UiButton v-if="!t.utilise" variant="soft-danger" size="sm" icon="bi-trash" aria-label="Supprimer" title="Supprimer" @click="supprimerTaux(t)" />
                </div>
              </td>
            </tr>
            <tr v-if="!tauxAmortissement.length"><td colspan="4" class="fs-empty">Aucune catégorie.</td></tr>
          </tbody>
        </table>
      </div>
      <form class="am-taux-form" @submit.prevent="enregistrerTaux">
        <input v-model="formTaux.intitule" type="text" required placeholder="Catégorie (ex. Matériel de transport)" aria-label="Catégorie" />
        <input v-model="formTaux.taux" type="number" min="0.01" max="100" step="0.01" required placeholder="Taux %" aria-label="Taux en pourcentage" />
        <input v-model="formTaux.duree" type="number" min="0.01" step="0.01" placeholder="Durée (ans)" aria-label="Durée en années" />
        <UiButton variant="primary" type="submit" :icon="formTaux.id ? 'bi-check-lg' : 'bi-plus-lg'">{{ formTaux.id ? 'Modifier' : 'Ajouter' }}</UiButton>
        <UiButton v-if="formTaux.id" variant="ghost" @click="editerTaux()">Annuler</UiButton>
      </form>
      <p class="ui-help">Le taux d'une catégorie déjà utilisée ne peut plus changer : créez une nouvelle catégorie.</p>
    </UiModal>

    <UiModal v-model="notice.open" :tone="notice.tone" :title="notice.title" size="sm">
      <p class="ui-modal__message">{{ notice.message }}</p>
      <template #footer><UiButton variant="primary" @click="notice.open = false">OK</UiButton></template>
    </UiModal>
  </AppLayout>
</template>

<style scoped>
.am-sous { font-size: var(--fs-text-xs); font-weight: 400; color: var(--fs-muted); }
.am-mb { margin: 0 0 16px; }
.am-taux-table { border: 1px solid var(--fs-border); border-radius: var(--fs-radius-sm); overflow: hidden; }
.am-taux-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 16px 0 8px; }
.am-taux-form input { flex: 1; min-width: 110px; }
.am-taux-form input:first-child { flex: 2; }
</style>
