<!-- Paramètres › Modes de paiement (ancien référentiel « Modes de paiement »). -->
<template>
  <section class="fs-card pr-card">
    <header class="pr-card__head">
      <div>
        <h2>Modes de paiement</h2>
        <p>Moyens de règlement proposés sur les lignes d'écriture (espèces, virement, chèque, mobile money…).</p>
      </div>
      <UiButton v-if="modifiable" variant="primary" size="sm" icon="bi-plus-lg" @click="ouvrir()">Nouveau mode</UiButton>
    </header>

    <UiSkeleton v-if="chargement" variant="table" :rows="4" :cols="3" />
    <div v-else class="fs-table-wrap pr-table">
      <table class="fs-table">
        <thead>
          <tr>
            <th>Libellé</th>
            <th>Abréviation</th>
            <th v-if="modifiable" class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in modes" :key="m.Id_Mode_paiement">
            <td class="font-bold">{{ m.Libelle }}</td>
            <td><UiBadge size="sm">{{ m.Abr }}</UiBadge></td>
            <td v-if="modifiable" class="text-right">
              <div class="fs-row-actions pr-row-actions">
                <UiButton variant="ghost" size="sm" icon="bi-pencil" :aria-label="`Modifier ${m.Libelle}`" @click="ouvrir(m)" />
                <UiButton variant="ghost" size="sm" icon="bi-trash" :aria-label="`Supprimer ${m.Libelle}`" @click="supprimer(m)" />
              </div>
            </td>
          </tr>
          <tr v-if="!modes.length"><td :colspan="modifiable ? 3 : 2" class="fs-empty">Aucun mode de paiement.</td></tr>
        </tbody>
      </table>
    </div>

    <UiModal v-model="modale" :title="form.Id_Mode_paiement ? 'Modifier le mode de paiement' : 'Nouveau mode de paiement'"
             :icon="form.Id_Mode_paiement ? 'bi-pencil' : 'bi-plus-lg'" size="sm">
      <form id="form-mode-paiement" class="ui-form ui-form--1" @submit.prevent="enregistrer">
        <div class="ui-field">
          <label for="mp-libelle" class="required">Libellé</label>
          <input id="mp-libelle" v-model="form.Libelle" type="text" placeholder="Virement bancaire" required maxlength="100" />
        </div>
        <div class="ui-field">
          <label for="mp-abr" class="required">Abréviation</label>
          <input id="mp-abr" v-model="form.Abr" type="text" placeholder="VIR" required maxlength="10" />
        </div>
        <p v-if="erreur" class="pr-error">{{ erreur }}</p>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="modale = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-mode-paiement" :loading="enCours">
          {{ form.Id_Mode_paiement ? 'Mettre à jour' : 'Ajouter' }}
        </UiButton>
      </template>
    </UiModal>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';

defineProps({ modifiable: { type: Boolean, default: false } });
const emit = defineEmits(['message']);
const URL = `${API_BASE}/mode-paiements`;

const modes = ref([]);
const chargement = ref(true);
const modale = ref(false);
const enCours = ref(false);
const erreur = ref('');
const form = ref({ Libelle: '', Abr: '' });

const messageErreur = e => e.response?.data?.message
  || Object.values(e.response?.data?.errors || {})[0]?.[0]
  || 'Opération impossible.';

async function charger() {
  try {
    modes.value = (await axios.get(URL)).data;
  } finally {
    chargement.value = false;
  }
}
onMounted(charger);

function ouvrir(m = null) {
  form.value = m ? { ...m } : { Libelle: '', Abr: '' };
  erreur.value = '';
  modale.value = true;
}

async function enregistrer() {
  enCours.value = true;
  erreur.value = '';
  try {
    const id = form.value.Id_Mode_paiement;
    await (id ? axios.put(`${URL}/${id}`, form.value) : axios.post(URL, form.value));
    modale.value = false;
    emit('message', id ? 'Mode de paiement modifié.' : 'Mode de paiement ajouté.', 'ok');
    await charger();
  } catch (e) {
    erreur.value = messageErreur(e);
  } finally {
    enCours.value = false;
  }
}

async function supprimer(m) {
  if (!window.confirm(`Supprimer le mode de paiement « ${m.Libelle} » ?`)) return;
  try {
    await axios.delete(`${URL}/${m.Id_Mode_paiement}`);
    emit('message', 'Mode de paiement supprimé.', 'ok');
    await charger();
  } catch (e) {
    emit('message', messageErreur(e), 'erreur');
  }
}
</script>
