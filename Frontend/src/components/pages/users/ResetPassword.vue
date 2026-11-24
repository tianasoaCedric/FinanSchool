<template>
  <AuthLayout
    icon="bi-shield-lock"
    title="Nouveau mot de passe"
    :subtitle="done ? '' : 'Choisissez un nouveau mot de passe pour votre compte.'"
  >
    <div v-if="done" class="fs-success-box">
      <i class="bi bi-check2"></i>
      <p style="margin: 0; font-weight: 600; color: var(--fs-text);">Mot de passe réinitialisé !</p>
      <UiButton variant="primary" to="/" block>Se connecter</UiButton>
    </div>

    <form v-else class="fs-form" @submit.prevent="submitForm">
      <div v-if="errorMessage" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i> <span>{{ errorMessage }}</span>
      </div>
      <div class="fs-field">
        <label for="reset-email">Adresse email</label>
        <input id="reset-email" v-model="email" type="email" class="fs-input" readonly />
      </div>
      <div class="fs-field">
        <label for="reset-password">Nouveau mot de passe</label>
        <input id="reset-password" v-model="password" type="password" class="fs-input" autocomplete="new-password" required />
      </div>
      <div class="fs-field">
        <label for="reset-password2">Confirmer le mot de passe</label>
        <input id="reset-password2" v-model="password_confirmation" type="password" class="fs-input" autocomplete="new-password" required />
      </div>
      <UiButton variant="primary" :loading="loading" block type="submit">
        {{ loading ? 'Enregistrement…' : 'Réinitialiser le mot de passe' }}
      </UiButton>
    </form>
  </AuthLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import AuthLayout from '@/components/template/AuthLayout.vue';

const email = ref('');
const password = ref('');
const password_confirmation = ref('');
const token = ref('');
const errorMessage = ref('');
const done = ref(false);
const loading = ref(false);

// Le lien reçu par email contient ?token=…&email=…
onMounted(() => {
  const params = new URLSearchParams(window.location.search);
  token.value = params.get('token') || '';
  email.value = params.get('email') || '';
});

async function submitForm() {
  errorMessage.value = '';
  loading.value = true;
  try {
    await axios.post(`${API_BASE}/reset-password`, {
      token: token.value,
      email: email.value,
      password: password.value,
      password_confirmation: password_confirmation.value,
    });
    done.value = true;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Erreur lors de la réinitialisation.';
  } finally {
    loading.value = false;
  }
}
</script>
