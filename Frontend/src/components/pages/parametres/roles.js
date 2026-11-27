// Rôles des utilisateurs (miroir de App\Models\User::ROLES)
export const ROLE_LIBELLE = { admin: 'Administrateur', comptable: 'Comptable', lecteur: 'Lecteur' };
export const ROLE_TON = { admin: 'primary', comptable: 'success', lecteur: 'neutral' };
export const ROLE_ICONE = { admin: 'bi-shield-lock', comptable: 'bi-pencil-square', lecteur: 'bi-eye' };

/** Ce que chaque rôle peut faire (tableau récapitulatif de l'écran Utilisateurs). */
export const DROITS = [
  { libelle: 'Consulter états, grand livre, tableau de bord', admin: true, comptable: true, lecteur: true },
  { libelle: 'Saisir, modifier et valider des pièces', admin: true, comptable: true, lecteur: false },
  { libelle: 'Gérer le plan comptable, journaux, immobilisations', admin: true, comptable: true, lecteur: false },
  { libelle: 'Importer des écritures, clôturer un exercice', admin: true, comptable: true, lecteur: false },
  { libelle: 'Annuler une clôture', admin: true, comptable: false, lecteur: false },
  { libelle: 'Modifier les paramètres de l\'application', admin: true, comptable: false, lecteur: false },
  { libelle: 'Gérer les utilisateurs et leurs rôles', admin: true, comptable: false, lecteur: false },
];
