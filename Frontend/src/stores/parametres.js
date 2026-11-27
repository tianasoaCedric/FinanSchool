import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';

/**
 * Paramètres de l'application (écran « Paramètres », API /reglages).
 * Chargés une fois puis partagés : en-têtes des exports, devise, seuils du
 * tableau de bord… Les valeurs par défaut ci-dessous servent tant que l'API
 * n'a pas répondu (ou si elle est indisponible).
 */
const DEFAUTS = {
  'societe.nom': 'Mon entreprise',
  'societe.forme_juridique': '',
  'societe.activite': '',
  'societe.adresse': '',
  'societe.ville': 'Antananarivo',
  'societe.pays': 'Madagascar',
  'societe.telephone': '',
  'societe.email': '',
  'societe.site_web': '',
  'societe.nif': '',
  'societe.stat': '',
  'societe.rcs': '',
  'societe.logo': '',
  'compta.devise_code': 'MGA',
  'compta.devise_symbole': 'Ar',
  'compta.decimales': 2,
  'compta.tolerance_equilibre': 0.01,
  'compta.taux_tva': 20,
  'saisie.numero_separateur': '-',
  'saisie.numero_chiffres': 4,
  'saisie.justificatif_taille_max': 5,
  'tiers.obligatoire': true,
  'tiers.comptes': '40,41',
  'import.separateur_csv': ';',
  'import.taille_max': 10,
  'securite.inscription_ouverte': true,
  'securite.role_inscription': 'comptable',
  'securite.mot_de_passe_min': 8,
  'securite.duree_session': 0,
  'affichage.lignes_par_page': 25,
  'dashboard.liquidite_bon': 1.5,
  'dashboard.liquidite_moyen': 1,
  'dashboard.autonomie_bon': 50,
  'dashboard.autonomie_moyen': 25,
  'dashboard.marge_bon': 10,
  'dashboard.marge_moyen': 0,
  'dashboard.delai_clients_bon': 60,
  'dashboard.delai_clients_moyen': 90,
};

// Logo historique, utilisé tant qu'aucun logo n'a été déposé dans les paramètres
const LOGO_PAR_DEFAUT = '/01Raitra kidz 300px.png';

export const useParametresStore = defineStore('parametres', () => {
  const valeurs = ref({ ...DEFAUTS });
  const defauts = ref({ ...DEFAUTS });
  const charge = ref(false);
  let enCours = null;

  async function charger(forcer = false) {
    if (charge.value && !forcer) return valeurs.value;
    if (enCours && !forcer) return enCours;
    enCours = axios.get(`${API_BASE}/reglages`)
      .then(({ data }) => {
        valeurs.value = { ...DEFAUTS, ...data.valeurs };
        defauts.value = { ...DEFAUTS, ...data.defauts };
        charge.value = true;
        return valeurs.value;
      })
      .catch(() => valeurs.value)
      .finally(() => { enCours = null; });
    return enCours;
  }

  async function enregistrer(modifs) {
    const { data } = await axios.put(`${API_BASE}/reglages`, { valeurs: modifs });
    valeurs.value = { ...DEFAUTS, ...data.valeurs };
    return data;
  }

  const get = cle => valeurs.value[cle];

  /** Coordonnées de l'entreprise pour les en-têtes d'export. */
  const societe = computed(() => {
    const v = valeurs.value;
    const ville = [v['societe.ville'], v['societe.pays']].filter(Boolean).join(', ');
    const ids = [
      v['societe.nif'] && `NIF ${v['societe.nif']}`,
      v['societe.stat'] && `STAT ${v['societe.stat']}`,
      v['societe.rcs'] && `RCS ${v['societe.rcs']}`,
    ].filter(Boolean).join(' · ');
    return {
      nom: v['societe.nom'],
      adresse: [v['societe.adresse'], ville].filter(Boolean).join(', '),
      tel: v['societe.telephone'],
      email: v['societe.email'],
      identifiants: ids,
      logo: v['societe.logo'] || LOGO_PAR_DEFAUT,
    };
  });

  const devise = computed(() => ({ code: valeurs.value['compta.devise_code'], symbole: valeurs.value['compta.devise_symbole'] }));

  return { valeurs, defauts, charge, charger, enregistrer, get, societe, devise };
});

/**
 * Accès hors composant (utilitaires d'export) : charge les paramètres si
 * besoin et renvoie le store.
 */
export async function parametres() {
  const store = useParametresStore();
  await store.charger();
  return store;
}
