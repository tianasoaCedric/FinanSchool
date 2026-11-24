// URL de base de l'API Laravel, définie à un seul endroit.
// Surcharger avec VITE_API_URL (ex. dans Frontend/.env ou au build) pour un déploiement.
export const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '');
