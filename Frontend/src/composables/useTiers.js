// Tiers (clients, fournisseurs…) et lettrage : constantes et appels API partagés.
import axios from 'axios';
import { API_BASE } from '@/config/api';

export const TYPES_TIERS = {
  client: { label: 'Client', pluriel: 'Clients', icon: 'bi-person-check', tone: 'primary' },
  fournisseur: { label: 'Fournisseur', pluriel: 'Fournisseurs', icon: 'bi-truck', tone: 'warning' },
  personnel: { label: 'Personnel', pluriel: 'Personnel', icon: 'bi-person-badge', tone: 'info' },
  autre: { label: 'Autre', pluriel: 'Autres', icon: 'bi-person', tone: 'neutral' },
};

/** Type de tiers attendu pour un compte (PCG 2005) : 40 → fournisseur, 41 → client, 42 → personnel. */
export function typePourCompte(code) {
  const c = String(code || '');
  if (c.startsWith('40')) return 'fournisseur';
  if (c.startsWith('41')) return 'client';
  if (c.startsWith('42')) return 'personnel';
  return null;
}

export function formatMontant(n) {
  return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatDate(d) {
  return d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('fr-FR') : '';
}

export function messagesErreur(e) {
  const d = e?.response?.data;
  if (d?.errors) return Object.values(d.errors).flat();
  return [d?.message || d?.error || 'Une erreur est survenue.'];
}

/** Liste complète des tiers actifs (sélecteurs). */
export async function chargerTousLesTiers() {
  const { data } = await axios.get(`${API_BASE}/tiers`, { params: { per_page: 0 } });
  return data;
}
