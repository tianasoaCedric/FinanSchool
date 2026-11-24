<template>
  <div class="fs-page" :data-theme="theme">
    <Sidebar />
    <Header v-if="user" :user="user" />

    <main class="fs-main">
      <div class="fs-container" :class="{ 'fs-container--wide': wide }">
        <header v-if="title" class="fs-header">
          <div>
            <nav v-if="section || back" class="fs-breadcrumb" aria-label="Fil d'Ariane">
              <button v-if="back" type="button" @click="$emit('back')">
                <i class="bi bi-arrow-left"></i> {{ backLabel }}
              </button>
              <span v-if="back && section" aria-hidden="true">/</span>
              <span v-if="section">{{ section }}</span>
            </nav>
            <div class="fs-title-row">
              <span v-if="icon" class="fs-title-icon" aria-hidden="true"><i :class="['bi', icon]"></i></span>
              <div>
                <h1 class="fs-title">{{ title }}</h1>
                <p v-if="subtitle" class="fs-subtitle">{{ subtitle }}</p>
              </div>
            </div>
          </div>
          <div v-if="$slots.actions" class="fs-actions">
            <slot name="actions" />
          </div>
        </header>

        <div class="fs-content">
          <slot />
        </div>

        <AppFooter class="fs-footer" />
      </div>
    </main>

    <button
      type="button"
      class="fs-chat-btn"
      :aria-label="showChat ? 'Fermer l\'assistant' : 'Ouvrir l\'assistant'"
      @click="showChat = !showChat"
    >
      <i :class="showChat ? 'bi bi-x-lg' : 'bi bi-chat-dots'"></i>
    </button>
    <transition name="fs-fade">
      <div v-if="showChat"><ChatBot /></div>
    </transition>
  </div>
</template>

<script setup>
import '@/assets/styles/app-theme.css';
import { ref } from 'vue';
import Header from '@/components/molecules/Header.vue';
import Sidebar from '@/components/molecules/Sidebar.vue';
import AppFooter from '@/components/molecules/Footer.vue';
import ChatBot from '@/components/molecules/ChatBot.vue';
import { useTheme } from '@/composables/useTheme';

defineProps({
  user: { type: Object, default: null },
  icon: { type: String, default: '' },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  /** Libellé de la rubrique dans le fil d'Ariane (ex. « Saisie ») */
  section: { type: String, default: '' },
  /** Affiche un lien retour dans le fil d'Ariane (émet `back`) */
  back: { type: Boolean, default: false },
  backLabel: { type: String, default: 'Retour' },
  /** Contenu pleine largeur (tableaux très larges) */
  wide: { type: Boolean, default: false },
});
defineEmits(['back']);

const { theme } = useTheme();
const showChat = ref(false);
</script>
