<!-- Paramètres › Utilisateurs et rôles (administrateurs uniquement). -->
<template>
  <div class="pr-stack">
    <section class="fs-card pr-card">
      <header class="pr-card__head">
        <div>
          <h2>Utilisateurs et rôles</h2>
          <p>Comptes ayant accès à l'application. Un compte désactivé ne peut plus se connecter et ses sessions sont fermées.</p>
        </div>
        <UiButton variant="primary" size="sm" icon="bi-person-plus" @click="ouvrir()">Nouvel utilisateur</UiButton>
      </header>

      <div class="us-toolbar">
        <div class="us-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input v-model="recherche" class="fs-input" type="search" placeholder="Rechercher un nom ou un e-mail" aria-label="Rechercher un utilisateur" />
        </div>
        <div class="us-filtres" role="group" aria-label="Filtrer par rôle">
          <button v-for="f in FILTRES" :key="f.id" type="button" class="us-filtre" :class="{ 'is-active': filtre === f.id }" @click="filtre = f.id">
            {{ f.label }} <span>{{ compte(f.id) }}</span>
          </button>
        </div>
      </div>

      <UiSkeleton v-if="chargement" variant="table" :rows="4" :cols="5" />
      <div v-else class="fs-table-wrap pr-table">
        <table class="fs-table">
          <thead>
            <tr>
              <th>Utilisateur</th>
              <th>Rôle</th>
              <th>Statut</th>
              <th>Dernière connexion</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in filtres" :key="u.id" :class="{ 'us-inactif': !u.actif }">
              <td>
                <div class="us-user">
                  <span class="us-avatar" aria-hidden="true">{{ initiales(u.name) }}</span>
                  <div>
                    <strong>{{ u.name }}<span v-if="u.id === moiId" class="us-moi">vous</span></strong>
                    <span>{{ u.email }}</span>
                  </div>
                </div>
              </td>
              <td><UiBadge :tone="ROLE_TON[u.role]" :icon="ROLE_ICONE[u.role]" size="sm">{{ ROLE_LIBELLE[u.role] }}</UiBadge></td>
              <td><UiBadge :tone="u.actif ? 'success' : 'neutral'" dot size="sm">{{ u.actif ? 'Actif' : 'Désactivé' }}</UiBadge></td>
              <td class="us-date">{{ u.derniere_connexion ? dateHeure(u.derniere_connexion) : 'Jamais' }}</td>
              <td class="text-right">
                <div class="fs-row-actions pr-row-actions">
                  <UiButton variant="ghost" size="sm" icon="bi-pencil" :aria-label="`Modifier ${u.name}`" @click="ouvrir(u)" />
                  <UiButton v-if="u.id !== moiId" variant="ghost" size="sm" :icon="u.actif ? 'bi-person-slash' : 'bi-person-check'"
                            :aria-label="u.actif ? `Désactiver ${u.name}` : `Réactiver ${u.name}`" @click="basculerActif(u)" />
                </div>
              </td>
            </tr>
            <tr v-if="!filtres.length"><td colspan="5" class="fs-empty">Aucun utilisateur ne correspond.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="fs-card pr-card">
      <header class="pr-card__head">
        <div>
          <h2>Droits par rôle</h2>
          <p>Les droits sont vérifiés par le serveur : un lecteur ne peut rien modifier, même en appelant l'API directement.</p>
        </div>
      </header>
      <div class="fs-table-wrap pr-table">
        <table class="fs-table us-droits">
          <thead>
            <tr>
              <th>Action</th>
              <th v-for="r in ['admin', 'comptable', 'lecteur']" :key="r" class="text-center">{{ ROLE_LIBELLE[r] }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in DROITS" :key="d.libelle">
              <td>{{ d.libelle }}</td>
              <td v-for="r in ['admin', 'comptable', 'lecteur']" :key="r" class="text-center">
                <i v-if="d[r]" class="bi bi-check-circle-fill us-oui" aria-label="Autorisé"></i>
                <i v-else class="bi bi-dash-circle us-non" aria-label="Non autorisé"></i>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <UiModal v-model="modale" :title="form.id ? `Modifier ${form.name}` : 'Nouvel utilisateur'" :icon="form.id ? 'bi-pencil' : 'bi-person-plus'" size="md">
      <form id="form-utilisateur" class="ui-form" @submit.prevent="enregistrer">
        <div class="ui-field">
          <label for="us-nom" class="required">Nom complet</label>
          <input id="us-nom" v-model="form.name" type="text" required maxlength="255" />
          <p v-if="erreurs.name" class="pr-error">{{ erreurs.name }}</p>
        </div>
        <div class="ui-field">
          <label for="us-email" class="required">E-mail</label>
          <input id="us-email" v-model="form.email" type="email" required />
          <p v-if="erreurs.email" class="pr-error">{{ erreurs.email }}</p>
        </div>
        <fieldset class="ui-field ui-field--full us-roles">
          <legend class="required">Rôle</legend>
          <label v-for="(desc, r) in roles" :key="r" class="us-role" :class="{ 'is-active': form.role === r }">
            <input v-model="form.role" type="radio" name="role" :value="r" :disabled="form.id === moiId && r !== 'admin'" />
            <i :class="['bi', ROLE_ICONE[r]]" aria-hidden="true"></i>
            <span><strong>{{ ROLE_LIBELLE[r] }}</strong>{{ desc.split(':').slice(1).join(':') }}</span>
          </label>
          <p v-if="erreurs.role" class="pr-error">{{ erreurs.role }}</p>
        </fieldset>
        <div class="ui-field">
          <label for="us-mdp" :class="{ required: !form.id }">{{ form.id ? 'Nouveau mot de passe' : 'Mot de passe initial' }}</label>
          <input id="us-mdp" v-model="form.password" type="text" :required="!form.id" :minlength="minMdp" autocomplete="new-password"
                 :placeholder="form.id ? 'Laisser vide pour ne pas changer' : `${minMdp} caractères minimum`" />
          <p v-if="erreurs.password" class="pr-error">{{ erreurs.password }}</p>
          <p v-else class="pr-hint">À transmettre à l'utilisateur, qui pourra le changer dans son profil.</p>
        </div>
        <div v-if="form.id" class="ui-field">
          <span class="us-label">Accès</span>
          <label class="pr-switch pr-switch--inline">
            <input v-model="form.actif" type="checkbox" :disabled="form.id === moiId" />
            <span class="pr-switch__track" aria-hidden="true"></span>
            <span>{{ form.actif ? 'Compte actif' : 'Compte désactivé' }}</span>
          </label>
          <p v-if="erreurs.actif" class="pr-error">{{ erreurs.actif }}</p>
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="modale = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="form-utilisateur" :loading="enCours">{{ form.id ? 'Enregistrer' : 'Créer le compte' }}</UiButton>
      </template>
    </UiModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { useParametresStore } from '@/stores/parametres';
import { DROITS, ROLE_ICONE, ROLE_LIBELLE, ROLE_TON } from './roles';

defineProps({ moiId: { type: Number, default: null } });
const emit = defineEmits(['message']);
const store = useParametresStore();
const FILTRES = [
  { id: 'tous', label: 'Tous' }, { id: 'admin', label: 'Administrateurs' },
  { id: 'comptable', label: 'Comptables' }, { id: 'lecteur', label: 'Lecteurs' }, { id: 'inactifs', label: 'Désactivés' },
];

const utilisateurs = ref([]);
const roles = ref({});
const chargement = ref(true);
const recherche = ref('');
const filtre = ref('tous');
const modale = ref(false);
const enCours = ref(false);
const erreurs = ref({});
const form = ref({});

const minMdp = computed(() => Number(store.get('securite.mot_de_passe_min')) || 8);
const initiales = n => (n || '?').split(/\s+/).map(m => m[0]).slice(0, 2).join('').toUpperCase();
const dateHeure = d => new Date(d).toLocaleString('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
const correspond = (u, f) => (f === 'tous' ? true : f === 'inactifs' ? !u.actif : u.role === f);
const compte = f => utilisateurs.value.filter(u => correspond(u, f)).length;
const filtres = computed(() => {
  const q = recherche.value.trim().toLowerCase();
  return utilisateurs.value.filter(u => correspond(u, filtre.value)
    && (!q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)));
});

async function charger() {
  try {
    const { data } = await axios.get(`${API_BASE}/utilisateurs`);
    utilisateurs.value = data.utilisateurs;
    roles.value = data.roles;
  } catch (e) {
    emit('message', e.response?.data?.message || 'Liste des utilisateurs indisponible.', 'erreur');
  } finally {
    chargement.value = false;
  }
}
onMounted(charger);

function ouvrir(u = null) {
  form.value = u ? { ...u, password: '' } : { name: '', email: '', role: 'comptable', password: '', actif: true };
  erreurs.value = {};
  modale.value = true;
}

async function envoyer(u, donnees) {
  return u.id ? axios.put(`${API_BASE}/utilisateurs/${u.id}`, donnees) : axios.post(`${API_BASE}/utilisateurs`, donnees);
}

async function enregistrer() {
  enCours.value = true;
  erreurs.value = {};
  try {
    const { password, ...reste } = form.value;
    const { data } = await envoyer(form.value, password ? { ...reste, password } : reste);
    modale.value = false;
    emit('message', data.message, 'ok');
    await charger();
  } catch (e) {
    erreurs.value = Object.fromEntries(Object.entries(e.response?.data?.errors || {}).map(([k, v]) => [k, v[0]]));
    if (!Object.keys(erreurs.value).length) emit('message', e.response?.data?.message || 'Enregistrement impossible.', 'erreur');
  } finally {
    enCours.value = false;
  }
}

async function basculerActif(u) {
  const action = u.actif ? 'désactiver' : 'réactiver';
  if (!window.confirm(`Voulez-vous ${action} le compte de ${u.name} ?`)) return;
  try {
    const { data } = await envoyer(u, { name: u.name, email: u.email, role: u.role, actif: !u.actif });
    emit('message', data.message, 'ok');
    await charger();
  } catch (e) {
    const err = e.response?.data;
    emit('message', Object.values(err?.errors || {})[0]?.[0] || err?.message || 'Opération impossible.', 'erreur');
  }
}
</script>

<style scoped>
.us-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.us-search { position: relative; flex: 1; min-width: 220px; max-width: 340px; }
.us-search .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); }
.us-search .fs-input { padding-left: 36px; }
.us-filtres { display: flex; flex-wrap: wrap; gap: 4px; padding: 3px; border-radius: 10px; background: var(--fs-surface-3); }
.us-filtre {
  display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 10px;
  border: 0; border-radius: 7px; background: transparent; cursor: pointer;
  font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2);
}
.us-filtre span { font-size: var(--fs-text-xs); color: var(--fs-muted); font-variant-numeric: tabular-nums; }
.us-filtre.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.us-filtre:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 1px; }

.us-user { display: flex; align-items: center; gap: 12px; }
.us-user strong { display: flex; align-items: center; gap: 8px; color: var(--fs-text); font-weight: 600; }
.us-user span { display: block; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.us-avatar {
  flex: none; width: 34px; height: 34px; border-radius: 50%;
  display: grid; place-items: center;
  background: var(--fs-surface-3); color: var(--fs-text-2); font-size: var(--fs-text-xs); font-weight: 700;
}
.us-moi {
  display: inline-block !important; padding: 1px 7px; border-radius: 999px;
  background: var(--fs-primary-soft); color: var(--fs-primary-text) !important; font-size: 11px !important; font-weight: 600;
}
.us-inactif .us-user strong, .us-inactif .us-user span { opacity: 0.6; }
.us-date { color: var(--fs-muted); white-space: nowrap; }

.us-droits td:first-child { color: var(--fs-text-2); }
.us-oui { color: var(--fs-success); font-size: 16px; }
.us-non { color: var(--fs-border-strong); font-size: 16px; }

.us-roles { border: 0; padding: 0; margin: 0; display: grid; gap: 8px; }
.us-roles legend { margin-bottom: 6px; padding: 0; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2); }
.us-role {
  display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; cursor: pointer;
  border: 1px solid var(--fs-border-strong); border-radius: 10px; transition: border-color 0.15s ease, background-color 0.15s ease;
}
.us-role input { margin-top: 3px; accent-color: var(--fs-primary); }
.us-role .bi { font-size: 16px; color: var(--fs-muted); margin-top: 1px; }
.us-role span { font-size: var(--fs-text-sm); color: var(--fs-muted); line-height: 1.45; }
.us-role strong { display: block; color: var(--fs-text); font-weight: 600; }
.us-role.is-active { border-color: var(--fs-primary); background: var(--fs-primary-soft); }
.us-role.is-active .bi { color: var(--fs-primary-text); }
.us-role:has(input:disabled) { opacity: 0.5; cursor: not-allowed; }
.us-label { font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2); }
</style>
