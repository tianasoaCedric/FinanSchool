import axios from 'axios';
import { API_BASE } from './api';

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
