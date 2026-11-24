import './echo.js'
import { createPinia } from 'pinia'
import { createApp, defineAsyncComponent } from "vue";
import App from "./App.vue";
import router from "./router";
import '@fontsource-variable/inter'
import './assets/styles/fonts.css'
import './assets/styles/style.css'
import 'bootstrap-icons/font/bootstrap-icons.css'

import UiButton from './components/ui/UiButton.vue'
import UiBadge from './components/ui/UiBadge.vue'
import { installerIntercepteursHttp } from './config/http'

// Jeton envoyé à chaque appel API ; retour à la connexion si la session expire
installerIntercepteursHttp(router);

const app = createApp(App)
  .use(createPinia())
  .use(router);

// Atomes d'interface communs (boutons et badges de statut uniformes)
app.component('UiButton', UiButton);
app.component('UiBadge', UiBadge);

// <apexchart> reste disponible partout, mais la librairie (~580 kB) n'est
// téléchargée que lorsqu'une page affiche réellement un graphique.
app.component('apexchart', defineAsyncComponent(() => import('vue3-apexcharts')));

app.mount("#app");
