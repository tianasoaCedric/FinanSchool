<!-- Paramètres › Mon profil : identité, mot de passe et sessions de l'utilisateur connecté. -->
<template>
  <div class="pr-stack">
    <section class="fs-card pr-card">
      <header class="pr-card__head">
        <div class="pf-ident">
          <span class="pf-avatar" aria-hidden="true">{{ initiales }}</span>
          <div>
            <h2>{{ profil?.name || 'Mon profil' }}</h2>
            <p>
              <UiBadge v-if="profil" :tone="ROLE_TON[profil.role]" size="sm">{{ ROLE_LIBELLE[profil.role] }}</UiBadge>
              <span v-if="profil?.derniere_connexion" class="pf-meta">Dernière connexion le {{ dateHeure(profil.derniere_connexion) }}</span>
            </p>
          </div>
        </div>
      </header>

      <UiSkeleton v-if="!profil" :rows="3" />
      <form v-else class="pr-grid" @submit.prevent="enregistrerIdentite">
        <div class="fs-field">
          <label class="fs-label" for="pf-nom">Nom complet *</label>
          <input id="pf-nom" v-model="identite.name" class="fs-input" required maxlength="255" autocomplete="name" :aria-invalid="!!erreurs.name" />
          <p v-if="erreurs.name" class="pr-error">{{ erreurs.name }}</p>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="pf-email">E-mail *</label>
          <input id="pf-email" v-model="identite.email" class="fs-input" type="email" required autocomplete="email" :aria-invalid="!!erreurs.email" />
          <p v-if="erreurs.email" class="pr-error">{{ erreurs.email }}</p>
        </div>
        <div class="pr-form-actions">
          <UiButton type="submit" variant="primary" :loading="enCours === 'identite'" :disabled="!identiteModifiee">Enregistrer</UiButton>
        </div>
      </form>
    </section>

    <section class="fs-card pr-card">
      <header class="pr-card__head">
        <div>
          <h2>Mot de passe</h2>
          <p>Au moins {{ minMdp }} caractères. Vos autres sessions seront fermées après le changement.</p>
        </div>
      </header>
      <form class="pr-grid pr-grid--3" @submit.prevent="changerMotDePasse">
        <div class="fs-field">
          <label class="fs-label" for="pf-actuel">Mot de passe actuel *</label>
          <input id="pf-actuel" v-model="mdp.actuel" class="fs-input" type="password" required autocomplete="current-password" :aria-invalid="!!erreurs.actuel" />
          <p v-if="erreurs.actuel" class="pr-error">{{ erreurs.actuel }}</p>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="pf-nouveau">Nouveau mot de passe *</label>
          <input id="pf-nouveau" v-model="mdp.nouveau" class="fs-input" type="password" required :minlength="minMdp" autocomplete="new-password" :aria-invalid="!!erreurs.nouveau" />
          <p v-if="erreurs.nouveau" class="pr-error">{{ erreurs.nouveau }}</p>
          <div v-else-if="mdp.nouveau" class="pf-force" :data-niveau="force.niveau">
            <span v-for="n in 4" :key="n" :class="{ 'is-on': n <= force.niveau }"></span>
            <em>{{ force.libelle }}</em>
          </div>
        </div>
        <div class="fs-field">
          <label class="fs-label" for="pf-confirm">Confirmation *</label>
          <input id="pf-confirm" v-model="mdp.nouveau_confirmation" class="fs-input" type="password" required autocomplete="new-password"
                 :aria-invalid="mdp.nouveau_confirmation && mdp.nouveau_confirmation !== mdp.nouveau" />
          <p v-if="mdp.nouveau_confirmation && mdp.nouveau_confirmation !== mdp.nouveau" class="pr-error">Les deux saisies diffèrent.</p>
        </div>
        <div class="pr-form-actions">
          <UiButton type="submit" variant="primary" icon="bi-key" :loading="enCours === 'mdp'"
                    :disabled="!mdp.actuel || !mdp.nouveau || mdp.nouveau !== mdp.nouveau_confirmation">Changer le mot de passe</UiButton>
        </div>
      </form>
    </section>

    <section class="fs-card pr-card">
      <header class="pr-card__head pr-card__head--flat">
        <div>
          <h2>Sessions</h2>
          <p>{{ sessions > 1 ? `Vous êtes connecté sur ${sessions} appareils ou navigateurs.` : 'Vous n\'êtes connecté que sur cet appareil.' }}</p>
        </div>
        <UiButton variant="soft-danger" icon="bi-box-arrow-right" :disabled="sessions <= 1" :loading="enCours === 'sessions'" @click="fermerSessions">
          Fermer les autres sessions
        </UiButton>
      </header>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { useParametresStore } from '@/stores/parametres';
import { ROLE_LIBELLE, ROLE_TON } from './roles';

const emit = defineEmits(['message', 'profil']);
const store = useParametresStore();
const profil = ref(null);
const sessions = ref(1);
const identite = reactive({ name: '', email: '' });
const mdp = reactive({ actuel: '', nouveau: '', nouveau_confirmation: '' });
const erreurs = ref({});
const enCours = ref(null);

const minMdp = computed(() => Number(store.get('securite.mot_de_passe_min')) || 8);
const initiales = computed(() => (profil.value?.name || '?').split(/\s+/).map(m => m[0]).slice(0, 2).join('').toUpperCase());
const identiteModifiee = computed(() => profil.value && (identite.name !== profil.value.name || identite.email !== profil.value.email));
const force = computed(() => {
  const m = mdp.nouveau;
  let n = 0;
  if (m.length >= minMdp.value) n++;
  if (/[a-z]/.test(m) && /[A-Z]/.test(m)) n++;
  if (/\d/.test(m)) n++;
  if (/[^A-Za-z0-9]/.test(m) || m.length >= 14) n++;
  return { niveau: n, libelle: ['Trop court', 'Faible', 'Moyen', 'Bon', 'Fort'][n] };
});
const dateHeure = d => new Date(d).toLocaleString('fr-FR', { dateStyle: 'long', timeStyle: 'short' });
const premiereErreur = errs => Object.fromEntries(Object.entries(errs || {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));

async function charger() {
  const { data } = await axios.get(`${API_BASE}/profil`);
  profil.value = data.user;
  sessions.value = data.sessions;
  Object.assign(identite, { name: data.user.name, email: data.user.email });
}
onMounted(charger);

async function executer(cle, appel) {
  enCours.value = cle;
  erreurs.value = {};
  try {
    const { data } = await appel();
    emit('message', data.message, 'ok');
    return data;
  } catch (e) {
    erreurs.value = premiereErreur(e.response?.data?.errors);
    emit('message', e.response?.data?.message || 'Opération impossible.', 'erreur');
    return null;
  } finally {
    enCours.value = null;
  }
}

async function enregistrerIdentite() {
  const data = await executer('identite', () => axios.put(`${API_BASE}/profil`, identite));
  if (data) { profil.value = data.user; emit('profil', data.user); }
}
async function changerMotDePasse() {
  const data = await executer('mdp', () => axios.put(`${API_BASE}/profil/mot-de-passe`, mdp));
  if (data) { Object.assign(mdp, { actuel: '', nouveau: '', nouveau_confirmation: '' }); sessions.value = 1; }
}
async function fermerSessions() {
  if (await executer('sessions', () => axios.delete(`${API_BASE}/profil/sessions`))) sessions.value = 1;
}
</script>

<style scoped>
.pf-ident { display: flex; align-items: center; gap: 14px; }
.pf-avatar {
  flex: none; width: 52px; height: 52px; border-radius: 50%;
  display: grid; place-items: center;
  background: var(--fs-primary-soft); color: var(--fs-primary-text);
  font-size: var(--fs-text-lg); font-weight: 700;
}
.pf-ident p { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pf-meta { font-size: var(--fs-text-xs); color: var(--fs-muted); }
.pf-force { display: flex; align-items: center; gap: 4px; margin-top: 4px; }
.pf-force span { width: 28px; height: 4px; border-radius: 2px; background: var(--fs-surface-3); }
.pf-force[data-niveau="1"] span.is-on { background: var(--fs-negative); }
.pf-force[data-niveau="2"] span.is-on { background: var(--fs-warning); }
.pf-force[data-niveau="3"] span.is-on, .pf-force[data-niveau="4"] span.is-on { background: var(--fs-success); }
.pf-force em { margin-left: 6px; font-style: normal; font-size: var(--fs-text-xs); color: var(--fs-muted); }
</style>
