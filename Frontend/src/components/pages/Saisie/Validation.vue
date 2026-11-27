<template>
  <AppLayout
    :user="user"
    icon="bi-shield-check"
    title="Validation des pièces"
    subtitle="Contrôlez les pièces en brouillon et validez-les : une pièce validée est numérotée et ne peut plus être modifiée"
    section="Saisie"
    wide
  >
    <template #actions>
      <UiButton to="/pieces" icon="bi-folder2-open">Toutes les pièces</UiButton>
      <UiButton
        variant="primary" icon="bi-check2-all" :loading="validation === 'toutes'"
        :disabled="!synthese.pretes || !!validation" @click="validerPretes"
      >Valider les {{ synthese.pretes || '' }} pièce(s) prête(s)</UiButton>
    </template>

    <!-- Synthèse -->
    <UiSkeleton v-if="chargement && synthese.total == null" variant="cards" :rows="3" class="va-tuiles-sk" />
    <section v-else class="va-tuiles" aria-label="Synthèse">
      <div class="fs-card va-tuile">
        <span class="va-tuile__label">En attente de validation</span>
        <span class="va-tuile__valeur">{{ synthese.total ?? '—' }}</span>
        <span class="va-tuile__aide">{{ formatMontant(synthese.montant) }} Ar au débit</span>
      </div>
      <div class="fs-card va-tuile va-tuile--ok">
        <span class="va-tuile__label"><i class="bi bi-check-circle" aria-hidden="true"></i> Prêtes à valider</span>
        <span class="va-tuile__valeur">{{ synthese.pretes ?? '—' }}</span>
        <span class="va-tuile__aide">{{ formatMontant(synthese.montant_pret) }} Ar au débit</span>
      </div>
      <div class="fs-card va-tuile va-tuile--ko">
        <span class="va-tuile__label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> À corriger</span>
        <span class="va-tuile__valeur">{{ synthese.a_corriger ?? '—' }}</span>
        <span class="va-tuile__aide">Déséquilibre, référence manquante, période close…</span>
      </div>
    </section>

    <!-- Compte rendu de la dernière validation -->
    <section v-if="compteRendu" class="fs-card va-compte-rendu" :class="{ 'va-compte-rendu--mixte': compteRendu.ignorees.length }" role="status">
      <div class="va-compte-rendu__entete">
        <i :class="compteRendu.ignorees.length ? 'bi bi-info-circle' : 'bi bi-check-circle-fill'" aria-hidden="true"></i>
        <strong>{{ compteRendu.message }}</strong>
        <UiButton variant="ghost" size="sm" icon="bi-x-lg" icon-only aria-label="Fermer le compte rendu" @click="compteRendu = null" />
      </div>
      <p v-if="compteRendu.validees.length" class="va-compte-rendu__ligne">
        Numéros attribués : {{ compteRendu.validees.slice(0, 12).map(v => v.numero).join(', ') }}<span v-if="compteRendu.validees.length > 12"> …</span>
      </p>
      <ul v-if="compteRendu.ignorees.length" class="va-compte-rendu__ignorees">
        <li v-for="p in compteRendu.ignorees.slice(0, 10)" :key="p.id">
          <router-link :to="`/ecriture/${p.id}`">{{ p.numero }}</router-link> : {{ p.motif }}
        </li>
      </ul>
    </section>

    <!-- Filtres -->
    <section class="fs-card va-filtres" aria-label="Filtres">
      <div class="va-onglets" role="tablist" aria-label="Statut">
        <button
          v-for="o in onglets" :key="o.valeur" type="button" role="tab"
          class="va-onglet" :class="{ 'is-actif': onglet === o.valeur }" :aria-selected="onglet === o.valeur"
          @click="onglet = o.valeur"
        >{{ o.libelle }} <span class="va-onglet__nb">{{ o.nombre() }}</span></button>
      </div>
      <div class="va-champs">
        <div class="va-recherche">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input v-model="filtres.q" type="search" class="fs-input" placeholder="Libellé, référence…" aria-label="Rechercher" />
        </div>
        <select v-model="filtres.journal" class="fs-select" aria-label="Journal">
          <option value="">Tous les journaux</option>
          <option v-for="j in journaux" :key="j.Id_Journal" :value="j.Id_Journal">{{ j.Code }} — {{ j.Libelle }}</option>
        </select>
        <input v-model="filtres.date_debut" type="date" class="fs-input" aria-label="Du" />
        <input v-model="filtres.date_fin" type="date" class="fs-input" aria-label="Au" />
      </div>
    </section>

    <!-- File des pièces -->
    <section class="fs-card fs-table-card">
      <div class="fs-table-card__head">
        <h2 class="fs-table-card__title">{{ piecesAffichees.length }} pièce(s)</h2>
        <div class="va-selection">
          <span v-if="selection.size" class="va-selection__nb">{{ selection.size }} sélectionnée(s)</span>
          <UiButton
            v-if="selection.size" variant="success" size="sm" icon="bi-check2"
            :loading="validation === 'selection'" :disabled="!!validation" @click="validerSelection"
          >Valider la sélection</UiButton>
        </div>
      </div>
      <p v-if="synthese.total > synthese.affichees" class="va-limite">
        Les {{ synthese.affichees }} pièces les plus anciennes sont affichées sur {{ synthese.total }} : réduisez la période pour voir les autres.
      </p>

      <UiSkeleton v-if="chargement" variant="table" :rows="6" :cols="8" />
      <div v-else class="fs-table-wrap">
        <table class="fs-table">
          <thead>
            <tr>
              <th class="va-col-check">
                <input
                  type="checkbox" :checked="toutCoche" :indeterminate.prop="selection.size > 0 && !toutCoche"
                  :disabled="!pretesAffichees.length" aria-label="Sélectionner toutes les pièces prêtes affichées"
                  @change="cocherTout($event.target.checked)"
                />
              </th>
              <th>Pièce</th>
              <th>Date</th>
              <th>Journal</th>
              <th>Libellé</th>
              <th class="text-right">Montant</th>
              <th>Contrôle</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="p in piecesAffichees" :key="p.id">
              <tr class="va-ligne" :class="{ 'is-ouverte': ouverte === p.id, 'is-cochee': selection.has(p.id) }" @click="basculer(p.id)">
                <td class="va-col-check" @click.stop>
                  <input
                    type="checkbox" :checked="selection.has(p.id)" :disabled="!!p.motif"
                    :aria-label="`Sélectionner ${p.numero}`" :title="p.motif ? 'Pièce à corriger avant validation' : ''"
                    @change="cocher(p.id, $event.target.checked)"
                  />
                </td>
                <td class="va-numero">
                  <i class="bi va-chevron" :class="ouverte === p.id ? 'bi-chevron-down' : 'bi-chevron-right'" aria-hidden="true"></i>
                  {{ p.numero }}
                </td>
                <td class="num">{{ formatDate(p.date) }}</td>
                <td>{{ p.journal?.code || '—' }}</td>
                <td class="va-libelle">
                  {{ p.libelle || '—' }}
                  <small v-if="p.reference">Réf. {{ p.reference }}</small>
                </td>
                <td class="text-right num">{{ formatMontant(p.total_debit) }}</td>
                <td class="va-controle">
                  <UiBadge v-if="!p.motif" tone="success" dot>Prête</UiBadge>
                  <span v-else class="va-motif"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ p.motif }}</span>
                </td>
                <td class="actions" @click.stop>
                  <div class="fs-row-actions">
                    <UiButton variant="ghost" size="sm" icon="bi-pencil" icon-only :to="`/ecriture/${p.id}`" :aria-label="`Ouvrir ${p.numero}`" title="Ouvrir / corriger" />
                    <UiButton
                      v-if="!p.motif" variant="soft-success" size="sm" icon="bi-check2"
                      :loading="validation === p.id" :disabled="!!validation" @click="validerUne(p)"
                    >Valider</UiButton>
                  </div>
                </td>
              </tr>
              <tr v-if="ouverte === p.id" class="va-detail">
                <td></td>
                <td colspan="7">
                  <table class="va-lignes">
                    <thead>
                      <tr><th>Compte</th><th>Libellé</th><th class="text-right">Débit</th><th class="text-right">Crédit</th></tr>
                    </thead>
                    <tbody>
                      <tr v-for="(l, i) in p.lignes" :key="i">
                        <td class="num">{{ l.compte }} <small>{{ l.libelle_compte }}</small></td>
                        <td>{{ l.libelle }}</td>
                        <td class="text-right num">{{ l.debit ? formatMontant(l.debit) : '' }}</td>
                        <td class="text-right num">{{ l.credit ? formatMontant(l.credit) : '' }}</td>
                      </tr>
                      <tr v-if="!p.lignes.length"><td colspan="4" class="fs-empty">Pièce sans ligne</td></tr>
                    </tbody>
                    <tfoot v-if="p.lignes.length">
                      <tr :class="{ 'is-ecart': Math.abs(p.total_debit - p.total_credit) >= 0.01 }">
                        <td colspan="2">Total{{ Math.abs(p.total_debit - p.total_credit) >= 0.01 ? ` — écart ${formatMontant(Math.abs(p.total_debit - p.total_credit))}` : '' }}</td>
                        <td class="text-right num">{{ formatMontant(p.total_debit) }}</td>
                        <td class="text-right num">{{ formatMontant(p.total_credit) }}</td>
                      </tr>
                    </tfoot>
                  </table>
                  <p v-if="p.saisi_par" class="va-saisi">Saisie par {{ p.saisi_par }}</p>
                </td>
              </tr>
            </template>
            <tr v-if="!piecesAffichees.length">
              <td colspan="8" class="fs-empty">
                <template v-if="!pieces.length"><i class="bi bi-check2-circle" aria-hidden="true"></i> Aucune pièce en attente : tout est validé sur cette période.</template>
                <template v-else>Aucune pièce dans cet onglet.</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';

const router = useRouter();
const user = ref(null);
const journaux = ref([]);
const pieces = ref([]);
const synthese = ref({});
const chargement = ref(true);
const validation = ref(null); // null | 'toutes' | 'selection' | id de pièce
const compteRendu = ref(null);
const ouverte = ref(null);
const onglet = ref('toutes');
const selection = ref(new Set());
const filtres = reactive({ q: '', journal: '', date_debut: '', date_fin: '' });

const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR') : '');

const onglets = [
  { valeur: 'toutes', libelle: 'Toutes', nombre: () => pieces.value.length },
  { valeur: 'pretes', libelle: 'Prêtes', nombre: () => pieces.value.filter(p => !p.motif).length },
  { valeur: 'a_corriger', libelle: 'À corriger', nombre: () => pieces.value.filter(p => p.motif).length },
];
const piecesAffichees = computed(() => {
  if (onglet.value === 'pretes') return pieces.value.filter(p => !p.motif);
  if (onglet.value === 'a_corriger') return pieces.value.filter(p => p.motif);
  return pieces.value;
});
const pretesAffichees = computed(() => piecesAffichees.value.filter(p => !p.motif));
const toutCoche = computed(() => pretesAffichees.value.length > 0 && pretesAffichees.value.every(p => selection.value.has(p.id)));

function cocher(id, oui) {
  const s = new Set(selection.value);
  oui ? s.add(id) : s.delete(id);
  selection.value = s;
}
function cocherTout(oui) {
  const s = new Set(selection.value);
  for (const p of pretesAffichees.value) oui ? s.add(p.id) : s.delete(p.id);
  selection.value = s;
}
function basculer(id) { ouverte.value = ouverte.value === id ? null : id; }

async function charger() {
  chargement.value = true;
  try {
    const params = {};
    for (const k of ['q', 'journal', 'date_debut', 'date_fin']) if (filtres[k]) params[k] = filtres[k];
    const { data } = await axios.get(`${API_BASE}/validation/pieces`, { params });
    pieces.value = data.pieces;
    synthese.value = data.synthese;
    // La sélection ne garde que des pièces encore prêtes
    const pretes = new Set(data.pieces.filter(p => !p.motif).map(p => p.id));
    selection.value = new Set([...selection.value].filter(id => pretes.has(id)));
  } catch {
    pieces.value = [];
    synthese.value = {};
  } finally {
    chargement.value = false;
  }
}

async function valider(ids, cle) {
  if (!ids.length) return;
  validation.value = cle;
  try {
    const { data } = await axios.post(`${API_BASE}/validation/valider`, { ids });
    compteRendu.value = data;
    selection.value = new Set();
  } catch (e) {
    compteRendu.value = { message: e.response?.data?.message || 'La validation a échoué.', validees: [], ignorees: [] };
  } finally {
    validation.value = null;
    await charger();
  }
}

function validerUne(p) { valider([p.id], p.id); }
function validerSelection() {
  const n = selection.value.size;
  if (!confirm(`Valider ${n} pièce(s) ? Elles recevront leur numéro définitif et ne pourront plus être modifiées.`)) return;
  valider([...selection.value], 'selection');
}
function validerPretes() {
  const ids = pieces.value.filter(p => !p.motif).map(p => p.id);
  if (!confirm(`Valider les ${ids.length} pièce(s) prête(s) affichées ? Elles recevront leur numéro définitif et ne pourront plus être modifiées.`)) return;
  valider(ids, 'toutes');
}

let minuteur = null;
watch(() => filtres.q, () => { clearTimeout(minuteur); minuteur = setTimeout(charger, 300); });
watch(() => [filtres.journal, filtres.date_debut, filtres.date_fin], charger);

onMounted(async () => {
  if (!localStorage.getItem('token')) return router.push('/');
  getUser(localStorage.getItem('token')).then(r => { user.value = r.data; }).catch(() => {});
  const [j, ex] = await Promise.all([
    axios.get(`${API_BASE}/journals`).catch(() => ({ data: [] })),
    axios.get(`${API_BASE}/exercices`).catch(() => ({ data: [] })),
  ]);
  journaux.value = j.data.data || j.data;
  // Par défaut : les exercices ouverts (le watch déclenche le chargement)
  const ouverts = (ex.data || []).filter(e => e.Statut === 'OUVERT');
  if (ouverts.length) {
    filtres.date_debut = ouverts.map(e => e.Date_debut.slice(0, 10)).sort()[0];
    filtres.date_fin = ouverts.map(e => e.Date_fin.slice(0, 10)).sort().at(-1);
  } else {
    charger();
  }
});
</script>

<style scoped>
.va-tuiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
.va-tuile { display: flex; flex-direction: column; gap: 4px; padding: 16px 20px; border-left: 4px solid var(--fs-border-strong, var(--fs-border)); }
.va-tuile--ok { border-left-color: var(--fs-success); }
.va-tuile--ko { border-left-color: var(--fs-warning); }
.va-tuile__label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--fs-text-2); }
.va-tuile--ok .va-tuile__label .bi { color: var(--fs-success); }
.va-tuile--ko .va-tuile__label .bi { color: var(--fs-warning); }
.va-tuile__valeur { font-size: 28px; font-weight: 700; color: var(--fs-text); font-variant-numeric: tabular-nums; line-height: 1.2; }
.va-tuile__aide { font-size: 12.5px; color: var(--fs-muted); }

.va-compte-rendu { padding: 14px 18px; margin-bottom: 20px; border-left: 4px solid var(--fs-success); }
.va-compte-rendu--mixte { border-left-color: var(--fs-warning); }
.va-compte-rendu__entete { display: flex; align-items: center; gap: 10px; }
.va-compte-rendu__entete .bi { font-size: 18px; color: var(--fs-success); }
.va-compte-rendu--mixte .va-compte-rendu__entete .bi { color: var(--fs-warning); }
.va-compte-rendu__entete strong { flex: 1; }
.va-compte-rendu__ligne { margin: 6px 0 0 28px; font-size: 13px; color: var(--fs-text-2); }
.va-compte-rendu__ignorees { margin: 8px 0 0 28px; padding-left: 16px; font-size: 13px; color: var(--fs-text-2); }
.va-compte-rendu__ignorees li { margin: 3px 0; }
.va-compte-rendu__ignorees a { color: var(--fs-primary); font-weight: 600; }

.va-filtres { display: flex; flex-direction: column; gap: 14px; padding: 16px 20px; margin-bottom: 20px; }
.va-onglets { display: inline-flex; gap: 4px; padding: 4px; width: fit-content; border-radius: 10px; background: var(--fs-surface-3); }
.va-onglet { border: 0; background: transparent; padding: 7px 14px; border-radius: 7px; cursor: pointer; font: 500 13.5px var(--fs-font); color: var(--fs-text-2); display: inline-flex; gap: 6px; align-items: center; }
.va-onglet.is-actif { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.va-onglet:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.va-onglet__nb { min-width: 20px; padding: 0 6px; border-radius: 999px; background: var(--fs-surface-3); font-size: 12px; font-variant-numeric: tabular-nums; }
.va-onglet.is-actif .va-onglet__nb { background: var(--fs-primary-soft); color: var(--fs-primary-text, var(--fs-primary)); }
.va-champs { display: grid; grid-template-columns: minmax(220px, 2fr) minmax(180px, 1fr) 160px 160px; gap: 12px; }
.va-recherche { position: relative; }
.va-recherche .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); pointer-events: none; }
.va-recherche .fs-input { padding-left: 36px; }

.va-selection { display: flex; align-items: center; gap: 10px; }
.va-selection__nb { font-size: 13px; color: var(--fs-text-2); }
.va-limite { margin: 0; padding: 8px 20px; font-size: 13px; color: var(--fs-text-2); background: var(--fs-warning-soft); }

.va-col-check { width: 40px; text-align: center; }
.va-col-check input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--fs-primary); }
.va-col-check input:disabled { cursor: not-allowed; opacity: .4; }
.va-ligne { cursor: pointer; }
.va-ligne:hover td, .va-ligne.is-ouverte td { background: var(--fs-row-hover); }
.va-ligne.is-cochee td { background: var(--fs-primary-soft); }
.va-numero { font-weight: 600; white-space: nowrap; }
.va-chevron { font-size: 11px; color: var(--fs-muted); margin-right: 4px; }
.va-libelle small { display: block; font-size: 12px; color: var(--fs-muted); }
.va-controle { max-width: 320px; }
.va-motif { display: inline-flex; gap: 6px; align-items: flex-start; font-size: 13px; color: var(--fs-text-2); line-height: 1.35; }
.va-motif .bi { color: var(--fs-warning); margin-top: 2px; }
.va-actions { white-space: nowrap; }
.va-actions > * + * { margin-left: 6px; }

.va-detail > td { background: var(--fs-surface-2, var(--fs-surface-3)); padding: 10px 16px 14px; }
.va-lignes { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--fs-surface); border: 1px solid var(--fs-border); border-radius: 8px; }
.va-lignes th { text-align: left; font-weight: 600; color: var(--fs-muted); padding: 7px 10px; border-bottom: 1px solid var(--fs-border); }
.va-lignes td { padding: 6px 10px; border-bottom: 1px solid var(--fs-border); }
.va-lignes td small { color: var(--fs-muted); margin-left: 4px; }
.va-lignes tfoot td { font-weight: 600; border-bottom: 0; }
.va-lignes tfoot tr.is-ecart td { color: var(--fs-negative); }
.va-saisi { margin: 8px 0 0; font-size: 12px; color: var(--fs-muted); }

.num { font-variant-numeric: tabular-nums; }
.text-right { text-align: right; }
@media (max-width: 1000px) {
  .va-tuiles { grid-template-columns: 1fr; }
  .va-champs { grid-template-columns: 1fr 1fr; }
}
</style>
