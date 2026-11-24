<template>
  <AuthLayout :icon="stepInfo.icon" :title="stepInfo.title" :subtitle="stepInfo.subtitle">
    <!-- Inscription terminée -->
    <div v-if="registered" class="fs-success-box">
      <i class="bi bi-check2"></i>
      <p style="margin: 0; font-weight: 600; color: var(--fs-text);">Félicitations, votre compte est créé !</p>
      <router-link to="/" class="fs-btn fs-btn--primary fs-btn--block">Se connecter</router-link>
    </div>

    <template v-else>
      <div class="fs-steps" aria-hidden="true">
        <span v-for="n in 3" :key="n" :class="{ 'is-done': n <= step }"></span>
      </div>

      <!-- Étape 1 : email -->
      <form v-if="step === 1" class="fs-form" @submit.prevent="sendCode">
        <div v-if="message" class="fs-alert fs-alert--error" role="alert"><i class="bi bi-exclamation-circle"></i> <span>{{ message }}</span></div>
        <div class="fs-field">
          <label for="reg-email">Adresse email</label>
          <input id="reg-email" v-model="email" type="email" class="fs-input" autocomplete="email" placeholder="nom@exemple.com" required />
        </div>
        <button type="submit" class="fs-btn fs-btn--primary fs-btn--block" :disabled="loading">
          <span v-if="loading" class="fs-spinner" aria-hidden="true"></span>
          {{ loading ? 'Envoi du code…' : 'Recevoir un code de vérification' }}
        </button>
      </form>

      <!-- Étape 2 : code -->
      <form v-else-if="step === 2" class="fs-form" @submit.prevent="verifyCode">
        <div v-if="message" class="fs-alert fs-alert--error" role="alert"><i class="bi bi-exclamation-circle"></i> <span>{{ message }}</span></div>
        <div v-else-if="successMessage" class="fs-alert fs-alert--success" role="status"><i class="bi bi-check-circle"></i> <span>{{ successMessage }}</span></div>
        <div class="fs-code" @paste.prevent="onPaste">
          <input
            v-for="(digit, index) in codeDigits"
            :key="index"
            :ref="el => (codeInputs[index] = el)"
            v-model="codeDigits[index]"
            type="text"
            inputmode="numeric"
            maxlength="1"
            :aria-label="`Chiffre ${index + 1}`"
            required
            @input="focusNext(index, $event)"
            @keydown.backspace="focusPrev(index, $event)"
          />
        </div>
        <button type="submit" class="fs-btn fs-btn--primary fs-btn--block" :disabled="loading">
          <span v-if="loading" class="fs-spinner" aria-hidden="true"></span>
          {{ loading ? 'Vérification…' : 'Vérifier le code' }}
        </button>
        <p class="fs-muted-text" style="margin-top: 4px;">
          Rien reçu ? <button type="button" class="fs-link" :disabled="loading" @click="sendCode">Renvoyer le code</button>
        </p>
      </form>

      <!-- Étape 3 : profil -->
      <form v-else class="fs-form" @submit.prevent="registerUser">
        <div v-if="message" class="fs-alert fs-alert--error" role="alert"><i class="bi bi-exclamation-circle"></i> <span>{{ message }}</span></div>
        <div class="fs-field">
          <label for="reg-name">Nom d'utilisateur</label>
          <input id="reg-name" v-model="name" type="text" class="fs-input" autocomplete="name" required />
        </div>
        <div class="fs-field">
          <label for="reg-password">Mot de passe</label>
          <input id="reg-password" v-model="password" type="password" class="fs-input" autocomplete="new-password" required />
        </div>
        <div class="fs-field">
          <label for="reg-password2">Confirmer le mot de passe</label>
          <input id="reg-password2" v-model="password_confirmation" type="password" class="fs-input" autocomplete="new-password" required />
        </div>
        <button type="submit" class="fs-btn fs-btn--primary fs-btn--block" :disabled="loading">
          <span v-if="loading" class="fs-spinner" aria-hidden="true"></span>
          {{ loading ? 'Création du compte…' : 'Créer mon compte' }}
        </button>
      </form>

      <p v-if="step === 1" class="fs-muted-text">
        Déjà un compte ? <router-link to="/" class="fs-link">Se connecter</router-link>
      </p>
    </template>
  </AuthLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import AuthLayout from '@/components/template/AuthLayout.vue';

const step = ref(1);
const email = ref('');
const name = ref('');
const password = ref('');
const password_confirmation = ref('');
const codeDigits = ref(['', '', '', '', '', '']);
const codeInputs = [];
const message = ref('');
const successMessage = ref('');
const loading = ref(false);
const registered = ref(false);

const stepInfo = computed(() => {
  if (registered.value) return { icon: 'bi-person-check', title: 'Compte créé', subtitle: '' };
  return [
    { icon: 'bi-person-plus', title: 'Créer un compte', subtitle: 'Commençons par vérifier votre adresse email.' },
    { icon: 'bi-envelope-open', title: 'Vérifiez votre email', subtitle: `Saisissez le code à 6 chiffres envoyé à ${email.value}.` },
    { icon: 'bi-person-vcard', title: 'Finalisez votre inscription', subtitle: 'Choisissez votre nom et votre mot de passe.' },
  ][step.value - 1];
});

// Étape 1 : envoyer le code
async function sendCode() {
  message.value = '';
  successMessage.value = '';
  loading.value = true;
  try {
    const res = await axios.post(`${API_BASE}/request-verification`, { email: email.value });
    if (res.data.status === 'success') {
      step.value = 2;
      successMessage.value = res.data.message;
    }
  } catch (err) {
    message.value = err.response?.data?.message || "Erreur lors de l'envoi du code";
  } finally {
    loading.value = false;
  }
}

// Saisie du code : passage automatique d'une case à l'autre
function focusNext(index, e) {
  if (e.inputType === 'insertText' && index < 5) codeInputs[index + 1]?.focus();
}
function focusPrev(index, e) {
  if (!codeDigits.value[index] && index > 0) {
    e.preventDefault();
    codeInputs[index - 1]?.focus();
  }
}
function onPaste(e) {
  const digits = (e.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6).split('');
  digits.forEach((d, i) => (codeDigits.value[i] = d));
  codeInputs[Math.min(digits.length, 5)]?.focus();
}

// Étape 2 : vérifier le code
async function verifyCode() {
  message.value = '';
  successMessage.value = '';
  loading.value = true;
  try {
    const res = await axios.post(`${API_BASE}/verify-code`, { email: email.value, code: codeDigits.value.join('') });
    if (res.data.status === 'success') step.value = 3;
  } catch (err) {
    message.value = err.response?.data?.message || 'Code invalide';
  } finally {
    loading.value = false;
  }
}

// Étape 3 : finaliser l'inscription
async function registerUser() {
  message.value = '';
  loading.value = true;
  try {
    const res = await axios.post(`${API_BASE}/register`, {
      email: email.value,
      name: name.value,
      password: password.value,
      password_confirmation: password_confirmation.value,
    });
    if (res.data.status === 'success') registered.value = true;
  } catch (err) {
    message.value = err.response?.data?.message || 'Erreur lors de la création';
  } finally {
    loading.value = false;
  }
}
</script>
