// Routes du module Analyse — pages chargées à la demande.
export default [
  { path: "/GetStarted", name: "Connexion", component: () => import("@/components/pages/loginAnalyse/Connexion.vue") },
  { path: "/analyse", name: "Analyse", component: () => import("@/components/pages/analyses/Dashboard.vue") },
  { path: "/couts", name: "Couts", component: () => import("@/components/pages/analyses/Couts.vue") },
  { path: "/profits", name: "Profits", component: () => import("@/components/pages/analyses/Profits.vue") },
  { path: "/comparatif", name: "Comparaison", component: () => import("@/components/pages/analyses/Comparaison.vue") },
  { path: "/indicateur-general", name: "General", component: () => import("@/components/pages/indicateurs/General.vue") },
  { path: "/indicateur-liquidite", name: "Liquidite", component: () => import("@/components/pages/indicateurs/Liquidite.vue") },
  { path: "/indicateur-solvabilite", name: "Solvabilite", component: () => import("@/components/pages/indicateurs/Solvabilite.vue") },
  { path: "/indicateur-rentabilite", name: "Rentabilite", component: () => import("@/components/pages/indicateurs/Rentabilite.vue") },
  { path: "/indicateur-pedagogique", name: "Pedagogique", component: () => import("@/components/pages/indicateurs/Pedagogique.vue") },
  { path: "/non-affected", name: "NonAffected", component: () => import("@/components/pages/configAnalytique/NonAffected.vue") },
  { path: "/code-analytique", name: "CodeAnalytique", component: () => import("@/components/pages/configAnalytique/CodeAnalytique.vue") },
  { path: "/parametres", name: "Parametres", component: () => import("@/components/pages/configAnalytique/Parametres.vue") },
  { path: "/effectif-eleve", name: "EffectifEleve", component: () => import("@/components/pages/configAnalytique/EffectifEleve.vue") },
];
