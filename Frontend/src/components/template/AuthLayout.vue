<!-- Mise en page des écrans d'authentification (connexion, inscription, mot de passe). -->
<template>
  <div class="fs-page auth" :data-theme="theme">
    <aside class="auth-brand" aria-hidden="true">
      <img src="@/assets/img/01300.png" alt="" class="auth-brand__logo" />
      <div class="auth-brand__body">
        <h2 class="auth-brand__title">La gestion financière de votre école, claire et maîtrisée.</h2>
        <ul class="auth-brand__list">
          <li><i class="bi bi-journal-check"></i> Saisie et validation des écritures</li>
          <li><i class="bi bi-file-earmark-bar-graph"></i> États financiers générés automatiquement</li>
          <li><i class="bi bi-graph-up-arrow"></i> Indicateurs et simulations budgétaires</li>
        </ul>
      </div>
      <p class="auth-brand__foot">© {{ year }} Raitra Kidz</p>
    </aside>

    <main class="auth-main">
      <button
        type="button"
        class="fs-btn fs-btn--icon fs-btn--ghost auth-theme"
        :aria-label="theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'"
        :title="theme === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre'"
        @click="toggleTheme"
      >
        <i :class="theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'"></i>
      </button>

      <div class="auth-panel">
        <img src="@/assets/img/01Raitra kidz 300px.png" alt="Raitra Kidz" class="auth-panel__logo" />
        <div v-if="icon" class="auth-panel__icon" aria-hidden="true"><i :class="['bi', icon]"></i></div>
        <h1 class="auth-panel__title">{{ title }}</h1>
        <p v-if="subtitle" class="auth-panel__subtitle">{{ subtitle }}</p>
        <slot />
      </div>
    </main>
  </div>
</template>

<script setup>
import '@/assets/styles/app-theme.css';
import { useTheme } from '@/composables/useTheme';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
});

const { theme, toggleTheme } = useTheme();
const year = new Date().getFullYear();
</script>

<style scoped>
.auth {
  display: grid;
  grid-template-columns: minmax(360px, 44%) 1fr;
  min-height: 100vh;
}

/* Panneau de marque */
.auth-brand {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 40px 48px;
  overflow: hidden;
  color: #dbe3fb;
  background: linear-gradient(160deg, #13297a 0%, #0b1640 60%, #08102e 100%);
}
.auth-brand::after {
  content: "";
  position: absolute;
  right: -120px;
  bottom: -120px;
  width: 420px;
  height: 420px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(91, 127, 255, 0.35) 0%, rgba(91, 127, 255, 0) 70%);
  pointer-events: none;
}
.auth-brand__logo { height: 64px; width: auto; align-self: flex-start; }
.auth-brand__body { position: relative; z-index: 1; max-width: 440px; }
.auth-brand__title {
  margin: 0 0 28px;
  color: #fff;
  font-size: 32px;
  line-height: 1.2;
  font-weight: 700;
  letter-spacing: -0.02em;
}
.auth-brand__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.auth-brand__list li { display: flex; align-items: center; gap: 12px; font-size: 15px; }
.auth-brand__list .bi {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.08);
  color: #9db2ff;
  font-size: 16px;
}
.auth-brand__foot { position: relative; z-index: 1; margin: 0; font-size: 13px; color: rgba(219, 227, 251, 0.6); }

/* Panneau formulaire */
.auth-main {
  position: relative;
  display: grid;
  place-items: center;
  padding: 48px 24px;
}
.auth-theme { position: absolute; top: 20px; right: 20px; }
.auth-panel { width: 100%; max-width: 400px; }
.auth-panel__logo { display: none; height: 56px; width: auto; margin-bottom: 24px; }
.auth-panel__icon {
  width: 48px;
  height: 48px;
  margin-bottom: 20px;
  display: grid;
  place-items: center;
  border-radius: 14px;
  background: var(--fs-primary-soft);
  color: var(--fs-primary-text);
  font-size: 22px;
}
.auth-panel__title {
  margin: 0;
  font-size: 26px;
  line-height: 1.25;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--fs-text);
}
.auth-panel__subtitle { margin: 8px 0 0; font-size: 15px; line-height: 1.5; color: var(--fs-muted); }

@media (max-width: 900px) {
  .auth { grid-template-columns: 1fr; }
  .auth-brand { display: none; }
  .auth-panel__logo { display: block; }
}
</style>
