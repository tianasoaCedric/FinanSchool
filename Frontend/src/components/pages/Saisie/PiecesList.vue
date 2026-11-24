<template>
  <AppLayout
    :user="user"
    icon="bi-folder2-open"
    title="Pièces comptables"
    subtitle="Recherchez, ouvrez, dupliquez ou complétez vos pièces"
    section="Saisie"
    wide
  >
    <template #actions>
      <UiButton to="/validation" icon="bi-shield-check">Validation</UiButton>
      <UiButton variant="primary" icon="bi-plus-lg" to="/ecriture">Nouvelle pièce</UiButton>
    </template>

    <!-- Filtres -->
    <section class="fs-card pl-filtres" aria-label="Filtres">
      <div class="pl-onglets" role="tablist" aria-label="Statut">
        <button
          v-for="o in onglets" :key="o.valeur" type="button" role="tab"
          class="pl-onglet" :class="{ 'is-actif': filtres.statut === o.valeur }" :aria-selected="filtres.statut === o.valeur"
          @click="filtres.statut = o.valeur"
        >{{ o.libelle }}</button>
      </div>
      <div class="pl-champs">
        <div class="pl-recherche">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input v-model="filtres.q" type="search" class="fs-input" placeholder="N° de pièce, libellé, référence…" aria-label="Rechercher" />
        </div>
        <select v-model="filtres.journal" class="fs-select" aria-label="Journal">
          <option value="">Tous les journaux</option>
          <option v-for="j in journaux" :key="j.Id_Journal" :value="j.Id_Journal">{{ j.Code }} — {{ j.Libelle }}</option>
        </select>
        <input v-model="filtres.date_debut" type="date" class="fs-input" aria-label="Du" />
        <input v-model="filtres.date_fin" type="date" class="fs-input" aria-label="Au" />
      </div>
    </section>

    <!-- Tableau -->
    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">{{ resultat.total ?? 0 }} pièce(s)</h2>
        <span class="fs-table-card__hint">Cliquez sur une pièce pour l'ouvrir</span>
      </div>
      <div v-if="chargement" class="fs-skeleton"><div v-for="n in 8" :key="n" class="fs-skeleton__row"></div></div>
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th>N° pièce</th>
              <th>Date</th>
              <th>Journal</th>
              <th>Libellé</th>
              <th class="text-right">Débit</th>
              <th class="text-right">Crédit</th>
              <th>Statut</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in resultat.data" :key="p.id" class="detail-row pl-ligne" tabindex="0" @click="ouvrir(p)" @keydown.enter="ouvrir(p)">
              <td class="pl-numero">
                {{ p.numero }}
                <i v-if="p.a_justificatif" class="bi bi-paperclip" title="Justificatif joint"></i>
              </td>
              <td class="num">{{ formatDate(p.date) }}</td>
              <td>{{ p.journal?.code }}</td>
              <td class="pl-libelle">
                {{ p.libelle || '—' }}
                <small v-if="p.reference">Réf. {{ p.reference }}</small>
              </td>
              <td class="text-right">{{ formatMontant(p.total_debit) }}</td>
              <td class="text-right">{{ formatMontant(p.total_credit) }}</td>
              <td class="pl-statut">
                <UiBadge v-if="p.contre_passee" tone="neutral" dot>Contre-passée</UiBadge>
                <UiBadge v-else-if="TYPES[p.type_piece]" tone="info">{{ TYPES[p.type_piece] }}</UiBadge>
                <UiBadge v-if="p.statut === 'valide'" tone="success" dot>Validée</UiBadge>
                <UiBadge v-else-if="!p.nb_lignes" tone="neutral" dot>Vide</UiBadge>
                <UiBadge v-else-if="!p.equilibree" tone="danger" dot>Déséquilibrée</UiBadge>
                <UiBadge v-else tone="warning" dot>Brouillon</UiBadge>
              </td>
              <td class="text-right pl-actions" @click.stop>
                <UiButton variant="ghost" size="sm" icon="bi-files" icon-only :aria-label="`Dupliquer ${p.numero}`" title="Dupliquer" :to="`/ecriture?depuis=${p.id}`" />
                <UiButton v-if="p.statut !== 'valide'" variant="ghost" size="sm" icon="bi-trash3" icon-only :aria-label="`Supprimer ${p.numero}`" title="Supprimer" @click="supprimer(p)" />
              </td>
            </tr>
            <tr v-if="!resultat.data?.length">
              <td colspan="8" class="fs-empty">Aucune pièce ne correspond aux filtres</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="resultat.last_page > 1" class="pl-pagination">
        <UiButton variant="ghost" size="sm" icon="bi-chevron-left" :disabled="resultat.current_page <= 1" @click="charger(resultat.current_page - 1)">Précédent</UiButton>
        <span>Page {{ resultat.current_page }} / {{ resultat.last_page }}</span>
        <UiButton variant="ghost" size="sm" icon-right="bi-chevron-right" :disabled="resultat.current_page >= resultat.last_page" @click="charger(resultat.current_page + 1)">Suivant</UiButton>
      </div>
    </section>
  </AppLayout>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';

const router = useRouter();
const user = ref(null);
const journaux = ref([]);
const chargement = ref(true);
const resultat = ref({ data: [] });

const onglets = [
  { valeur: 'tous', libelle: 'Toutes' },
  { valeur: 'brouillon', libelle: 'Brouillons' },
  { valeur: 'valide', libelle: 'Validées' },
];
const filtres = reactive({ statut: 'brouillon', q: '', journal: '', date_debut: '', date_fin: '' });
const TYPES = { a_nouveau: 'À-nouveaux', cloture: 'Clôture', dotation: 'Dotations', extourne: 'Contre-passation', extourne_auto: 'Extourne', affectation: 'Affectation' };

const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR') : '');

async function charger(page = 1) {
  chargement.value = true;
  try {
    const params = { page, per_page: 25, statut: filtres.statut };
    for (const k of ['q', 'journal', 'date_debut', 'date_fin']) if (filtres[k]) params[k] = filtres[k];
    const { data } = await axios.get(`${API_BASE}/pieces`, { params });
    resultat.value = data;
  } catch {
    resultat.value = { data: [] };
  } finally {
    chargement.value = false;
  }
}

function ouvrir(p) { router.push(`/ecriture/${p.id}`); }

async function supprimer(p) {
  if (!confirm(`Supprimer définitivement la pièce ${p.numero} ?`)) return;
  try {
    await axios.delete(`${API_BASE}/pieces/${p.id}`);
    charger(resultat.value.current_page || 1);
  } catch (e) {
    alert(e.response?.data?.message || 'Suppression impossible');
  }
}

// Recherche : on attend la fin de la frappe
let minuteur = null;
watch(() => filtres.q, () => { clearTimeout(minuteur); minuteur = setTimeout(() => charger(1), 300); });
watch(() => [filtres.statut, filtres.journal, filtres.date_debut, filtres.date_fin], () => charger(1));

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  const [j, ex] = await Promise.all([
    axios.get(`${API_BASE}/journals`),
    axios.get(`${API_BASE}/exercices`).catch(() => ({ data: [] })),
  ]);
  journaux.value = j.data.data || j.data;
  // Par défaut : les exercices ouverts (N et N+1 le cas échéant) ; le watch relance le chargement
  const ouverts = (ex.data || []).filter(e => e.Statut === 'OUVERT');
  if (ouverts.length) {
    filtres.date_debut = ouverts.map(e => e.Date_debut.slice(0, 10)).sort()[0];
    filtres.date_fin = ouverts.map(e => e.Date_fin.slice(0, 10)).sort().at(-1);
  } else {
    charger(1);
  }
});
</script>

<style scoped>
.pl-filtres { display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; margin-bottom: 20px; }
.pl-onglets { display: inline-flex; gap: 4px; padding: 4px; width: fit-content; border-radius: 10px; background: var(--fs-surface-3); }
.pl-onglet {
  border: 0; background: transparent; padding: 7px 14px; border-radius: 7px; cursor: pointer;
  font: 500 13.5px var(--fs-font); color: var(--fs-text-2);
}
.pl-onglet.is-actif { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.pl-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.pl-champs { display: grid; grid-template-columns: minmax(240px, 2fr) minmax(180px, 1fr) 160px 160px; gap: 12px; }
.pl-recherche { position: relative; }
.pl-recherche .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); pointer-events: none; }
.pl-recherche .fs-input { padding-left: 36px; }
.pl-ligne { cursor: pointer; }
.pl-ligne:focus-visible td { background: var(--fs-row-hover); outline: none; }
.pl-numero { font-weight: 600; color: var(--fs-text); white-space: nowrap; }
.pl-numero .bi { color: var(--fs-muted); margin-left: 4px; }
.pl-libelle small { display: block; color: var(--fs-muted); font-size: 12px; }
.pl-actions { white-space: nowrap; }
.pl-statut > * + * { margin-left: 6px; }
.pl-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 12px; border-top: 1px solid var(--fs-border); font-size: 13px; color: var(--fs-muted); }
.num { font-variant-numeric: tabular-nums; }
@media (max-width: 900px) { .pl-champs { grid-template-columns: 1fr 1fr; } }
</style>
