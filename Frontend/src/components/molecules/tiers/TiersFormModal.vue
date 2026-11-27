<!-- Création / modification d'un tiers (modale). Émet « enregistre » (message) et « cree » (tiers). -->
<template>
  <UiModal v-model="ouverte" :title="tiers ? `Modifier ${tiers.nom}` : 'Nouveau tiers'" :subtitle="tiers?.code" icon="bi-person-vcard" size="lg">
    <div v-if="erreurs.length" class="fs-alert fs-alert--error tf-erreurs" role="alert">
      <i class="bi bi-exclamation-circle"></i><div><p v-for="(e, i) in erreurs" :key="i">{{ e }}</p></div>
    </div>
    <form id="tf-form" class="ui-form" @submit.prevent="enregistrer">
      <div class="ui-field">
        <label for="tf-type" class="required">Type</label>
        <select id="tf-type" v-model="form.type" class="fs-select" required @change="typeChange">
          <option v-for="(t, id) in TYPES_TIERS" :key="id" :value="id">{{ t.label }}</option>
        </select>
      </div>
      <div class="ui-field">
        <label for="tf-code">Code</label>
        <input id="tf-code" v-model="form.code" class="fs-input" maxlength="20" :placeholder="codePropose || 'Automatique'" />
        <span class="ui-help">Laissez vide pour {{ codePropose || 'un code automatique' }}</span>
      </div>
      <div class="ui-field ui-field--full">
        <label for="tf-nom" class="required">Nom ou raison sociale</label>
        <input id="tf-nom" ref="champNom" v-model="form.nom" class="fs-input" maxlength="150" required />
      </div>
      <div class="ui-field">
        <label for="tf-compte">Compte collectif</label>
        <select id="tf-compte" v-model="form.Id_Sous_compte" class="fs-select">
          <option :value="null">—</option>
          <option v-for="c in comptesProposes" :key="c.Id_Sous_compte" :value="c.Id_Sous_compte">{{ c.Code_sous_compte }} — {{ c.Libelle }}</option>
        </select>
        <span class="ui-help">Proposé à la saisie quand vous choisissez ce tiers</span>
      </div>
      <div class="ui-field">
        <label for="tf-delai">Délai de paiement (jours)</label>
        <input id="tf-delai" v-model.number="form.delai_paiement" type="number" min="0" max="365" class="fs-input" />
        <span class="ui-help">Sert au calcul des échéances et de la balance âgée</span>
      </div>
      <div class="ui-field">
        <label for="tf-nif">NIF</label>
        <input id="tf-nif" v-model="form.nif" class="fs-input" maxlength="50" />
      </div>
      <div class="ui-field">
        <label for="tf-stat">STAT</label>
        <input id="tf-stat" v-model="form.stat" class="fs-input" maxlength="50" />
      </div>
      <div class="ui-field">
        <label for="tf-tel">Téléphone</label>
        <input id="tf-tel" v-model="form.telephone" class="fs-input" maxlength="50" />
      </div>
      <div class="ui-field">
        <label for="tf-email">E-mail</label>
        <input id="tf-email" v-model="form.email" type="email" class="fs-input" maxlength="150" />
      </div>
      <div class="ui-field ui-field--full">
        <label for="tf-adresse">Adresse</label>
        <input id="tf-adresse" v-model="form.adresse" class="fs-input" maxlength="255" />
      </div>
      <div class="ui-field ui-field--full">
        <label for="tf-notes">Notes</label>
        <textarea id="tf-notes" v-model="form.notes" class="fs-input tf-notes" rows="2" maxlength="2000"></textarea>
      </div>
      <label v-if="tiers" class="tf-switch ui-field--full">
        <input v-model="form.actif" type="checkbox" />
        <span><strong>Actif</strong> — un tiers désactivé n'est plus proposé à la saisie</span>
      </label>
    </form>
    <template #footer>
      <UiButton v-if="tiers" variant="soft-danger" icon="bi-trash3" :disabled="enCours" class="tf-suppr" @click="supprimer">Supprimer</UiButton>
      <UiButton variant="ghost" @click="ouverte = false">Annuler</UiButton>
      <UiButton variant="primary" type="submit" form="tf-form" :loading="enCours" :disabled="!form.nom.trim()">Enregistrer</UiButton>
    </template>
  </UiModal>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { TYPES_TIERS, messagesErreur } from '@/composables/useTiers';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  tiers: { type: Object, default: null },
  /** Valeurs initiales pour une création (ex. type déduit du compte saisi). */
  initial: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue', 'enregistre', 'cree']);

const ouverte = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) });
const VIDE = { code: '', type: 'client', nom: '', nif: '', stat: '', adresse: '', telephone: '', email: '', delai_paiement: 30, Id_Sous_compte: null, actif: true, notes: '' };
const form = reactive({ ...VIDE });
const erreurs = ref([]);
const enCours = ref(false);
const codePropose = ref('');
const comptes = ref([]);
const champNom = ref(null);

// Comptes collectifs : classe 4, ceux du type en premier (40 fournisseurs, 41 clients, 42 personnel)
const RACINES = { fournisseur: '40', client: '41', personnel: '42' };
const comptesProposes = computed(() => {
  const racine = RACINES[form.type];
  const classe4 = comptes.value.filter(c => String(c.Code_sous_compte).startsWith('4'));
  return racine ? [...classe4.filter(c => c.Code_sous_compte.startsWith(racine)), ...classe4.filter(c => !c.Code_sous_compte.startsWith(racine))] : classe4;
});

async function chargerComptes() {
  if (comptes.value.length) return;
  const { data } = await axios.get(`${API_BASE}/sous-comptes`);
  comptes.value = (data.data || data).slice().sort((a, b) => a.Code_sous_compte.localeCompare(b.Code_sous_compte));
}

async function proposerCode() {
  if (props.tiers) return;
  try {
    const { data } = await axios.get(`${API_BASE}/tiers/prochain-code`, { params: { type: form.type } });
    codePropose.value = data.code;
  } catch { codePropose.value = ''; }
}

function compteParDefaut() {
  const racine = RACINES[form.type];
  const c = racine && comptes.value.find(x => x.Code_sous_compte.startsWith(racine));
  return c ? c.Id_Sous_compte : null;
}

function typeChange() {
  proposerCode();
  if (!props.tiers) form.Id_Sous_compte = compteParDefaut();
}

watch(ouverte, async v => {
  if (!v) return;
  erreurs.value = [];
  await chargerComptes().catch(() => {});
  if (props.tiers) {
    Object.assign(form, { ...VIDE, ...props.tiers, notes: props.tiers.notes || '' });
  } else {
    Object.assign(form, { ...VIDE, ...props.initial });
    form.Id_Sous_compte = props.initial.Id_Sous_compte ?? compteParDefaut();
    proposerCode();
  }
  nextTick(() => champNom.value?.focus());
});

async function enregistrer() {
  enCours.value = true;
  erreurs.value = [];
  const corps = { ...form, code: form.code?.trim() || null };
  try {
    const { data } = props.tiers
      ? await axios.put(`${API_BASE}/tiers/${props.tiers.id}`, corps)
      : await axios.post(`${API_BASE}/tiers`, corps);
    ouverte.value = false;
    emit('enregistre', data.message);
    if (!props.tiers) emit('cree', data.tiers);
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    enCours.value = false;
  }
}

async function supprimer() {
  if (!confirm(`Supprimer le tiers ${props.tiers.nom} ?`)) return;
  enCours.value = true;
  erreurs.value = [];
  try {
    const { data } = await axios.delete(`${API_BASE}/tiers/${props.tiers.id}`);
    ouverte.value = false;
    emit('enregistre', data.message);
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    enCours.value = false;
  }
}
</script>

<style scoped>
.tf-erreurs { margin-bottom: 16px; }
.tf-erreurs p { margin: 0; }
.tf-notes { height: auto; padding: 8px 12px; resize: vertical; }
.tf-switch { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--fs-text-2); cursor: pointer; grid-column: 1 / -1; }
.tf-switch input { width: 16px; height: 16px; accent-color: var(--fs-primary); }
.tf-suppr { margin-right: auto; }
</style>
