import { createRouter, createWebHistory } from "vue-router";
import AnalyseRoutes from "./routesAnalyse.js";

// Chaque page est chargée à la demande (code-splitting) : le navigateur ne
// télécharge que le code de la page affichée au lieu de toute l'application.
const routes = [
  { path: "/", name: "Login", component: () => import("@/components/pages/users/Login.vue") },
  ...AnalyseRoutes,
  { path: "/produits", name: "Produits", component: () => import("@/views/Produits.vue") },
  { path: "/home", name: "Home", component: () => import("@/views/ModuleChoice.vue") },
  { path: "/dashboard", name: "Dashboard", component: () => import("@/views/Dashboard.vue") },
  { path: "/forgot-password", name: "ForgotPassword", component: () => import("@/components/pages/users/ForgotPassword.vue") },
  { path: "/reset-password", name: "ResetPassword", component: () => import("@/components/pages/users/ResetPassword.vue") },
  { path: "/signup", name: "Register", component: () => import("@/components/pages/users/Register.vue") },

  // Configuration
  { path: "/compte", name: "Compte", component: () => import("@/components/pages/configuration/Compte.vue") },
  { path: "/souscompte", name: "SousCompte", component: () => import("@/components/pages/configuration/SousCompte.vue") },
  { path: "/rubrique", name: "Rubrique", component: () => import("@/components/pages/configuration/Rubrique.vue") },
  { path: "/type-journal", name: "TypeJournal", component: () => import("@/components/pages/configuration/TypeJournal.vue") },
  { path: "/devise", name: "Devise", component: () => import("@/components/pages/configuration/Devise.vue") },
  { path: "/journal", name: "Journal", component: () => import("@/components/pages/configuration/Journal.vue") },
  { path: "/paiement-mode", name: "ModePaiement", component: () => import("@/components/pages/configuration/ModePaiement.vue") },
  { path: "/amortissement", name: "Amortissement", component: () => import("@/components/pages/configuration/Amortissement.vue") },
  { path: "/ecritures-journal/:id", name: "EcritureJournal", component: () => import("@/components/pages/configuration/EcritureJournal.vue"), props: true },

  // Saisie
  { path: "/ecriture", name: "Ecriture", component: () => import("@/components/pages/Saisie/PieceForm.vue") },
  { path: "/ecriture/:id(\\d+)", name: "Piece", component: () => import("@/components/pages/Saisie/PieceForm.vue") },
  { path: "/pieces", name: "Pieces", component: () => import("@/components/pages/Saisie/PiecesList.vue") },
  { path: "/liste-grand-livre", name: "ListeGrandLivre", component: () => import("@/components/pages/Saisie/ListeGrandLivre.vue") },
  { path: "/grand-livres/:codeCompte", name: "GrandLivre", component: () => import("@/components/pages/Saisie/GrandLivre.vue"), props: true },
  { path: "/liste-ecriture", name: "ListeEcriture", component: () => import("@/components/pages/Saisie/ListeEcriture.vue") },
  { path: "/validation", name: "Validation", component: () => import("@/components/pages/Saisie/Validation.vue") },
  { path: "/import", name: "Import", component: () => import("@/components/pages/import/Import.vue") },
  { path: "/cloture", name: "Cloture", component: () => import("@/components/pages/Saisie/ClotureExercice.vue") },

  // Analytique
  { path: "/type-centre", name: "TypeCentre", component: () => import("@/components/pages/configAnalytique/TypeCentre.vue") },
  { path: "/axe-analytique", name: "AxeAnalytique", component: () => import("@/components/pages/configAnalytique/AxeAnalytique.vue") },
  { path: "/centre-analytique", name: "CentreAnalytique", component: () => import("@/components/pages/configAnalytique/CentreAnalytique.vue") },
  { path: "/affectation-analytique", name: "AffectationAnalytique", component: () => import("@/components/pages/configAnalytique/AffectationAnalytique.vue") },
  { path: "/couts-et-profits", name: "CoutsEtProfits", component: () => import("@/components/pages/analyses/Couts.vue") },

  // États financiers
  { path: "/balance", name: "Balance", component: () => import("@/components/pages/general/Balance.vue") },
  { path: "/CR-Nature", name: "CR-Nature", component: () => import("@/components/pages/general/CRNature.vue") },
  { path: "/CR-Fonction", name: "CR-Fonction", component: () => import("@/components/pages/general/CRFonction.vue") },
  { path: "/BilanActif", name: "BilanActif", component: () => import("@/components/pages/general/BilanActif.vue") },
  { path: "/BilanPassif", name: "BilanPassif", component: () => import("@/components/pages/general/BilanPassif.vue") },
  { path: "/flux-tresorerie", name: "FluxTresorerie", component: () => import("@/components/pages/general/FluxTresorerie.vue") },
  { path: "/variation-capitaux", name: "VariationCapitaux", component: () => import("@/components/pages/general/VariationsCapitaux.vue") },

  // Simulation
  { path: "/simulations", name: "Simulations", component: () => import("@/components/pages/Simulation/SimulationList.vue") },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

export default router;
