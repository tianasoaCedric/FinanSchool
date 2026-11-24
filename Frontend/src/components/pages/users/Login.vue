<template>
  <AuthLayout title="Connexion" subtitle="Bienvenue ! Connectez-vous à votre espace financier.">
    <form class="fs-form" @submit.prevent="handleLogin">
      <div v-if="errorMessage" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i> <span>{{ errorMessage }}</span>
      </div>

      <div class="fs-field">
        <label for="login-email">Adresse email</label>
        <input id="login-email" v-model="email" type="email" class="fs-input" autocomplete="email" placeholder="nom@exemple.com" required />
      </div>

      <div class="fs-field">
        <div class="fs-field__row">
          <label for="login-password">Mot de passe</label>
          <router-link to="/forgot-password" class="fs-link" style="font-size: 13px;">Mot de passe oublié ?</router-link>
        </div>
        <input id="login-password" v-model="password" type="password" class="fs-input" autocomplete="current-password" required />
      </div>

      <button type="submit" class="fs-btn fs-btn--primary fs-btn--block" :disabled="loading">
        <span v-if="loading" class="fs-spinner" aria-hidden="true"></span>
        {{ loading ? 'Connexion…' : 'Se connecter' }}
      </button>
    </form>

    <p class="fs-muted-text">
      Pas encore de compte ? <router-link to="/signup" class="fs-link">Créer un compte</router-link>
    </p>
  </AuthLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import AuthLayout from '@/components/template/AuthLayout.vue';
import { login } from '../../../services/Auth';

const router = useRouter();
const email = ref('');
const password = ref('');
const errorMessage = ref('');
const loading = ref(false);

async function handleLogin() {
  errorMessage.value = '';
  loading.value = true;
  try {
    const res = await login(email.value, password.value);
    localStorage.setItem('token', res.data.token);
    router.push('/home');
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Connexion impossible. Vérifiez vos identifiants.';
  } finally {
    loading.value = false;
  }
}
</script>
