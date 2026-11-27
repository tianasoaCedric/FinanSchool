import axios from 'axios';
import { API_BASE } from './api';
import { debutChargement, finChargement, setNavigation } from '@/composables/useLoadingBar';

// Pages accessibles sans être connecté : pas de redirection depuis celles-ci.
const PAGES_PUBLIQUES = ['/', '/signup', '/forgot-password', '/reset-password'];

const versApi = (url = '') => url.startsWith(API_BASE) || url.startsWith('/api');

/**
 * Authentification des appels à l'API :
 *  - ajoute le jeton (Bearer) à chaque requête vers l'API ;
 *  - si l'API répond 401 (jeton absent ou expiré), efface le jeton et renvoie
 *    vers la page de connexion.
 */
export function installerIntercepteursHttp(router) {
  // Barre de chargement globale : navigation entre pages
  router.beforeEach(() => { setNavigation(true); });
  router.afterEach(() => { setNavigation(false); });
  router.onError(() => { setNavigation(false); });

  // … et requêtes API en cours
  axios.interceptors.request.use((config) => {
    if (versApi(config.url)) { config.__barre = true; debutChargement(); }
    return config;
  });
  const fin = (config) => { if (config?.__barre) { config.__barre = false; finChargement(); } };
  axios.interceptors.response.use(
    (response) => { fin(response.config); return response; },
    (error) => { fin(error.config); return Promise.reject(error); }
  );

  axios.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token && versApi(config.url) && !config.headers?.Authorization) {
      config.headers = config.headers || {};
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  });

  axios.interceptors.response.use(
    (response) => response,
    (error) => {
      const status = error.response?.status;
      const url = error.config?.url || '';
      // Droits insuffisants (ex. rôle lecteur) : message affiché par AppLayout
      if (status === 403 && versApi(url) && error.response?.data?.message) {
        window.dispatchEvent(new CustomEvent('fs:acces-refuse', { detail: error.response.data.message }));
      }
      if (status === 401 && versApi(url) && !url.endsWith('/login')) {
        localStorage.removeItem('token');
        const actuelle = router.currentRoute.value?.path || window.location.pathname;
        if (!PAGES_PUBLIQUES.includes(actuelle)) {
          router.push('/');
        }
      }
      return Promise.reject(error);
    }
  );
}
