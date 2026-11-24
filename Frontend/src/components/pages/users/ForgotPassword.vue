<template>
  <AuthLayout
    icon="bi-key"
    title="Mot de passe oublié ?"
    :subtitle="sent ? '' : 'Saisissez votre adresse email : nous vous enverrons un lien pour choisir un nouveau mot de passe.'"
  >
    <div v-if="sent" class="fs-success-box">
      <i class="bi bi-envelope-check"></i>
      <div>
        <p style="margin: 0 0 6px; font-weight: 600; color: var(--fs-text);">Consultez votre boîte mail</p>
        <p style="margin: 0; color: var(--fs-muted); font-size: 14px;">
          Si un compte existe pour <strong>{{ email }}</strong>, un lien de réinitialisation vient d'y être envoyé.
        </p>
      </div>
      <button type="button" class="fs-btn fs-btn--primary fs-btn--block" @click="openGmail">
        <i class="bi bi-box-arrow-up-right"></i> Ouvrir la messagerie
      </button>
    </div>

    <form v-else class="fs-form" @submit.prevent="sendLink">
      <div v-if="message" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i> <span>{{ message }}</span>
      </div>
      <div class="fs-field">
        <label for="forgot-email">Adresse email</label>
        <input id="forgot-email" v-model="email" type="email" class="fs-input" autocomplete="email" placeholder="nom@exemple.com" required />
      </div>
      <button type="submit" class="fs-btn fs-btn--primary fs-btn--block" :disabled="loading">
        <span v-if="loading" class="fs-spinner" aria-hidden="true"></span>
        {{ loading ? 'Envoi…' : 'Envoyer le lien' }}
      </button>
    </form>

    <p class="fs-muted-text">
      <router-link to="/" class="fs-link"><i class="bi bi-arrow-left"></i> Retour à la connexion</router-link>
    </p>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import AuthLayout from '@/components/template/AuthLayout.vue';

const email = ref('');
const sent = ref(false);
const loading = ref(false);
const message = ref('');

async function sendLink() {
  message.value = '';
  loading.value = true;
  try {
    await axios.post(`${API_BASE}/forgot-password`, { email: email.value });
    sent.value = true;
  } catch (err) {
    message.value = err.response?.data?.message || "Impossible d'envoyer le lien pour le moment.";
  } finally {
    loading.value = false;
  }
}

const openGmail = () => window.open('https://mail.google.com', '_blank', 'noopener');
</script>
