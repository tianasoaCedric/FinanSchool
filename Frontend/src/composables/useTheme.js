import { ref, watch } from 'vue';

// Thème clair/sombre partagé entre les pages (un seul état pour toute l'app).
// Mémorisé dans le navigateur ; par défaut, suit la préférence du système.
const STORAGE_KEY = 'fs-theme';

function initialTheme() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved === 'light' || saved === 'dark') return saved;
  } catch { /* stockage indisponible : on ignore */ }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

const theme = ref(initialTheme());

watch(theme, (value) => {
  try { localStorage.setItem(STORAGE_KEY, value); } catch { /* ignore */ }
});

export function useTheme() {
  const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
  };
  return { theme, toggleTheme };
}
