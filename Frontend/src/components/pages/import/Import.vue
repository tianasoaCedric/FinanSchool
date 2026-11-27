<!--
  Import d'écritures (CSV, XLSX, XLS).
  Le fichier est lu dans le navigateur ; l'utilisateur fait correspondre les
  colonnes, le serveur renvoie un aperçu détaillé (lignes, pièces, erreurs),
  puis importe tout ou rien en brouillon. Historique avec annulation.
-->
<template>
  <AppLayout
    :user="user"
    icon="bi-cloud-arrow-up"
    title="Import d'écritures"
    subtitle="Pièces comptables depuis un fichier CSV ou Excel, créées en brouillon"
    section="Écritures"
  >
    <template #actions>
      <UiButton icon="bi-file-earmark-spreadsheet" @click="telechargerModele">Télécharger un modèle</UiButton>
    </template>

    <div class="ip-stack">
      <!-- ===== 1. Fichier ===== -->
      <section class="fs-card ip-card">
        <header class="ip-head">
          <span class="ip-step">1</span>
          <div>
            <h2>Fichier</h2>
            <p>Une ligne par mouvement. Les lignes de même date, référence et journal forment une pièce, qui doit être équilibrée.</p>
          </div>
          <UiButton v-if="fichier" size="sm" variant="ghost" icon="bi-x-lg" @click="reinitialiser">Changer de fichier</UiButton>
        </header>

        <div class="ip-file-row">
          <label
            class="ip-drop"
            :class="{ 'is-over': survol, 'has-file': fichier }"
            @dragover.prevent="survol = true"
            @dragleave.prevent="survol = false"
            @drop.prevent="deposer"
          >
            <input type="file" accept=".csv,.txt,.xlsx,.xls" class="ip-drop__input" @change="choisir" />
            <i :class="['bi', fichier ? 'bi-file-earmark-check' : 'bi-cloud-arrow-up']" aria-hidden="true"></i>
            <span v-if="fichier"><strong>{{ fichier.name }}</strong> · {{ lignesBrutes.length }} ligne(s) lue(s) — cliquez pour changer</span>
            <span v-else><strong>Glissez un fichier ici</strong> ou cliquez pour choisir · CSV, XLSX, XLS · {{ tailleMax }} Mo max</span>
          </label>

          <ul class="ip-regles">
            <li><i class="bi bi-calendar3"></i> Date : <code>15/06/2025</code> ou <code>2025-06-15</code></li>
            <li><i class="bi bi-hash"></i> Sous-compte existant : <code>601001</code></li>
            <li><i class="bi bi-cash"></i> Débit <em>ou</em> crédit : <code>1 000,50</code></li>
            <li><i class="bi bi-journal"></i> Code journal existant : <code>ACH</code></li>
            <li><i class="bi bi-people"></i> Code tiers (facultatif, comptes 40/41) : <code>F0001</code></li>
            <li class="ip-regles__note">Tout ou rien : à la moindre erreur, aucune écriture n'est enregistrée.</li>
          </ul>
        </div>

        <p v-if="erreurLecture" class="ip-error" role="alert"><i class="bi bi-exclamation-circle"></i> {{ erreurLecture }}</p>

        <!-- Correspondance des colonnes -->
        <div v-if="tableau.length" class="ip-mapping">
          <div v-for="c in CHAMPS" :key="c.id" class="fs-field">
            <label class="fs-label" :for="`ip-col-${c.id}`">{{ c.label }}{{ c.requis ? ' *' : '' }}</label>
            <select :id="`ip-col-${c.id}`" v-model.number="colonnes[c.id]" class="fs-select" @change="analyser">
              <option v-if="!c.requis" :value="-1">— Aucune —</option>
              <option v-for="(t, i) in titresColonnes" :key="i" :value="i">{{ t }}</option>
            </select>
          </div>
          <label class="ip-check ip-mapping__full">
            <input v-model="avecEntetes" type="checkbox" @change="analyser" />
            <span>La première ligne contient les en-têtes</span>
          </label>
        </div>
      </section>

      <!-- ===== 2. Aperçu ===== -->
      <section v-if="analyse || analyseEnCours" class="fs-card ip-card">
        <header class="ip-head">
          <span class="ip-step">2</span>
          <div>
            <h2>Aperçu</h2>
            <p>Rien n'est encore enregistré.</p>
          </div>
          <div v-if="analyse" class="ip-vues" role="group" aria-label="Affichage">
            <button type="button" :class="{ 'is-active': vue === 'lignes' }" @click="vue = 'lignes'">Lignes</button>
            <button type="button" :class="{ 'is-active': vue === 'pieces' }" @click="vue = 'pieces'">Pièces</button>
          </div>
        </header>

        <UiSkeleton v-if="analyseEnCours && !analyse" :rows="4" />
        <template v-else-if="analyse">
          <div class="ip-stats">
            <div class="ip-stat"><span class="ip-stat__val">{{ analyse.resume.lignes }}</span><span class="ip-stat__lbl"><i class="bi bi-list-ul"></i>Lignes</span></div>
            <div class="ip-stat"><span class="ip-stat__val">{{ analyse.resume.pieces }}</span><span class="ip-stat__lbl"><i class="bi bi-files"></i>Pièces</span></div>
            <div class="ip-stat"><span class="ip-stat__val ip-num">{{ montant(analyse.resume.total_debit) }}</span><span class="ip-stat__lbl"><i class="bi bi-arrow-left-right"></i>Total débit = crédit</span></div>
            <div class="ip-stat"><span class="ip-stat__val ip-stat__val--sm">{{ periode }}</span><span class="ip-stat__lbl"><i class="bi bi-calendar-range"></i>Période</span></div>
            <button type="button" class="ip-stat ip-stat--danger" :class="{ 'is-active': filtreErreurs, 'is-zero': !analyse.resume.erreurs }"
                    :disabled="!analyse.resume.erreurs" :aria-pressed="filtreErreurs" @click="filtreErreurs = !filtreErreurs">
              <span class="ip-stat__val">{{ analyse.resume.erreurs }}</span>
              <span class="ip-stat__lbl"><i :class="['bi', analyse.resume.erreurs ? 'bi-x-circle' : 'bi-check-circle']"></i>{{ analyse.resume.erreurs ? 'Erreurs — afficher' : 'Aucune erreur' }}</span>
            </button>
          </div>

          <!-- Vue par ligne -->
          <div v-if="vue === 'lignes'" class="fs-table-wrap ip-table">
            <table class="fs-table">
              <thead>
                <tr>
                  <th class="ip-col-num">N°</th>
                  <th>Date</th>
                  <th>Compte</th>
                  <th>Libellé</th>
                  <th class="text-right">Débit</th>
                  <th class="text-right">Crédit</th>
                  <th>Réf.</th>
                  <th>Jnl</th>
                  <th>Tiers</th>
                  <th>Pièce</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="l in lignesAffichees" :key="l.ligne">
                  <tr :class="{ 'ip-row-err': l.erreurs.length }">
                    <td class="ip-col-num">{{ l.ligne }}</td>
                    <td class="ip-nowrap">{{ l.date_iso ? dateCourte(l.date_iso) : l.date || '—' }}</td>
                    <td>
                      <code class="ip-code">{{ l.compte || '—' }}</code>
                      <span v-if="l.compte_libelle" class="ip-sub">{{ l.compte_libelle }}</span>
                    </td>
                    <td class="ip-lib">{{ l.libelle || '—' }}</td>
                    <td class="text-right ip-num">{{ l.debit ? montant(l.debit) : '' }}</td>
                    <td class="text-right ip-num">{{ l.credit ? montant(l.credit) : '' }}</td>
                    <td class="ip-muted">{{ l.reference }}</td>
                    <td class="ip-muted">{{ l.journal }}</td>
                    <td>
                      <code v-if="l.tiers" class="ip-code">{{ l.tiers }}</code>
                      <span v-if="l.tiers_nom" class="ip-sub">{{ l.tiers_nom }}</span>
                    </td>
                    <td>
                      <UiBadge v-if="l.piece" size="sm" :tone="pieceEnErreur(l.piece) ? 'danger' : 'neutral'">#{{ l.piece }}</UiBadge>
                    </td>
                  </tr>
                  <tr v-if="l.erreurs.length" class="ip-row-msg">
                    <td></td>
                    <td colspan="9"><i class="bi bi-exclamation-circle"></i> {{ l.erreurs.join(' · ') }}</td>
                  </tr>
                  <tr v-if="l.avertissements?.length" class="ip-row-avert">
                    <td></td>
                    <td colspan="9"><i class="bi bi-info-circle"></i> {{ l.avertissements.join(' · ') }}</td>
                  </tr>
                </template>
                <tr v-if="!lignesFiltrees.length"><td colspan="10" class="fs-empty">Aucune ligne.</td></tr>
              </tbody>
            </table>
          </div>

          <!-- Vue par pièce -->
          <div v-else class="fs-table-wrap ip-table">
            <table class="fs-table">
              <thead>
                <tr>
                  <th>Pièce</th>
                  <th>Date</th>
                  <th>Journal</th>
                  <th>Référence</th>
                  <th>Lignes</th>
                  <th class="text-right">Débit</th>
                  <th class="text-right">Crédit</th>
                  <th>État</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in piecesAffichees" :key="p.num" :class="{ 'ip-row-err': p.erreur }">
                  <td><strong>#{{ p.num }}</strong></td>
                  <td class="ip-nowrap">{{ dateCourte(p.date) }}</td>
                  <td>{{ p.journal }}</td>
                  <td class="ip-muted">{{ p.reference || '—' }}</td>
                  <td class="ip-muted">{{ p.lignes.join(', ') }}</td>
                  <td class="text-right ip-num">{{ montant(p.debit) }}</td>
                  <td class="text-right ip-num">{{ montant(p.credit) }}</td>
                  <td>
                    <UiBadge v-if="p.erreur" tone="danger" icon="bi-x-circle" size="sm" :title="p.erreur">{{ p.erreur }}</UiBadge>
                    <UiBadge v-else tone="success" icon="bi-check-circle" size="sm">Équilibrée</UiBadge>
                  </td>
                </tr>
                <tr v-if="!piecesAffichees.length"><td colspan="8" class="fs-empty">Aucune pièce.</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="resteAAfficher > 0" class="ip-more">
            <UiButton size="sm" variant="ghost" @click="limite += 200">Afficher 200 de plus ({{ resteAAfficher }} restantes)</UiButton>
          </div>
        </template>
      </section>

      <!-- ===== 3. Import ===== -->
      <section v-if="analyse" class="fs-card ip-card ip-final" :class="{ 'ip-final--ko': analyse.resume.erreurs }">
        <div>
          <h2 v-if="analyse.resume.erreurs">Corrigez les {{ analyse.resume.erreurs }} erreur(s) avant d'importer</h2>
          <h2 v-else>{{ analyse.resume.pieces }} pièce(s) prêtes à importer</h2>
          <p v-if="analyse.resume.erreurs">Modifiez le fichier puis rechargez-le : l'import est en tout ou rien.</p>
          <p v-else>Elles seront créées en <strong>brouillon</strong>, à valider ensuite. L'import pourra être annulé tant qu'aucune n'est validée.</p>
        </div>
        <UiButton variant="primary" icon="bi-check2-circle" :disabled="!!analyse.resume.erreurs || !analyse.resume.pieces" :loading="importEnCours" @click="importer">
          Importer
        </UiButton>
      </section>

      <!-- ===== Historique ===== -->
      <section class="fs-card ip-card">
        <header class="ip-head ip-head--plain">
          <div>
            <h2>Historique des imports</h2>
            <p>Chaque import peut être consulté (ses pièces) ou annulé tant qu'aucune de ses pièces n'est validée.</p>
          </div>
        </header>

        <div class="ih-toolbar">
          <div class="ih-filtres" role="group" aria-label="Filtrer par statut">
            <button v-for="f in FILTRES_HISTO" :key="f.id" type="button" class="ih-filtre" :class="{ 'is-active': histo.statut === f.id }"
                    @click="histo.statut = f.id; chargerHistorique(1)">
              {{ f.label }} <span v-if="f.id">{{ histo.compteurs[f.id] || 0 }}</span>
            </button>
          </div>
          <div class="ih-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input v-model="histo.q" class="fs-input" type="search" placeholder="Nom de fichier" aria-label="Rechercher un fichier" @input="rechercherHistorique" />
          </div>
        </div>

        <UiSkeleton v-if="histo.chargement && !histo.lignes.length" variant="table" :rows="4" :cols="6" />
        <div v-else class="fs-table-wrap ip-table ip-table--flush">
          <table class="fs-table">
            <thead>
              <tr>
                <th>Fichier</th>
                <th>Date</th>
                <th>Statut</th>
                <th class="text-right">Pièces</th>
                <th class="text-right">Lignes</th>
                <th class="text-right">Montant</th>
                <th>Période</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="h in histo.lignes" :key="h.id">
                <tr>
                  <td>
                    <div class="ih-file">
                      <i :class="['bi', /\.xlsx?$/i.test(h.nom_fichier) ? 'bi-file-earmark-excel' : 'bi-filetype-csv']" aria-hidden="true"></i>
                      <div>
                        <strong>{{ h.nom_fichier }}</strong>
                        <span>{{ h.par || '—' }}</span>
                      </div>
                    </div>
                  </td>
                  <td class="ip-nowrap ip-muted">{{ dateHeure(h.date) }}</td>
                  <td>
                    <UiBadge :tone="STATUT[h.statut]?.ton" :icon="STATUT[h.statut]?.icon" size="sm">{{ STATUT[h.statut]?.label || h.statut }}</UiBadge>
                    <span v-if="h.statut === 'réussi' && h.pieces_validees" class="ih-sub">{{ h.pieces_validees }}/{{ h.pieces_restantes }} validée(s)</span>
                    <span v-if="h.statut === 'annulé' && h.annule_par" class="ih-sub">par {{ h.annule_par }}</span>
                  </td>
                  <td class="text-right ip-num">{{ h.nombre_pieces || '—' }}</td>
                  <td class="text-right ip-num">{{ h.nombre_lignes || '—' }}</td>
                  <td class="text-right ip-num">{{ h.total ? montant(h.total) : '—' }}</td>
                  <td class="ip-nowrap ip-muted">{{ h.date_min ? (h.date_min === h.date_max ? dateCourte(h.date_min) : `${dateCourte(h.date_min)} → ${dateCourte(h.date_max)}`) : '—' }}</td>
                  <td class="text-right">
                    <div class="fs-row-actions ih-actions">
                      <UiButton v-if="h.erreurs.length" variant="ghost" size="sm" :icon="ouvert === h.id ? 'bi-chevron-up' : 'bi-exclamation-circle'"
                                @click="ouvert = ouvert === h.id ? null : h.id">{{ h.erreurs.length }} erreur(s)</UiButton>
                      <UiButton v-if="h.tracable" variant="ghost" size="sm" icon="bi-files" :to="{ path: '/pieces', query: { import: h.id } }">Pièces</UiButton>
                      <UiButton v-if="h.annulable" variant="ghost" size="sm" icon="bi-arrow-counterclockwise" :loading="annulation === h.id" @click="annuler(h)">Annuler</UiButton>
                    </div>
                  </td>
                </tr>
                <tr v-if="ouvert === h.id" class="ih-errors">
                  <td colspan="8">
                    <ul>
                      <li v-for="(e, i) in h.erreurs" :key="i">{{ e }}</li>
                    </ul>
                  </td>
                </tr>
              </template>
              <tr v-if="!histo.lignes.length"><td colspan="8" class="fs-empty">Aucun import{{ histo.statut || histo.q ? ' pour ces critères' : '' }}.</td></tr>
            </tbody>
          </table>
        </div>
        <UiPagination
          v-if="histo.dernierePage > 1"
          :model-value="histo.page"
          :total-pages="histo.dernierePage"
          :total="histo.total"
          :per-page="histo.parPage"
          class="ih-pagination"
          @change="chargerHistorique"
        />
      </section>
    </div>

    <transition name="fs-fade">
      <div v-if="message" class="ip-toast" :class="`ip-toast--${message.ton}`" role="status">
        <i :class="['bi', message.ton === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill']"></i>
        {{ message.texte }}
      </div>
    </transition>
  </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import * as XLSX from 'xlsx';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';
import { useParametresStore } from '@/stores/parametres';

// Colonnes attendues et en-têtes reconnus automatiquement
const CHAMPS = [
  { id: 'date', label: 'Date', requis: true, motif: /^(date|jour)/i },
  { id: 'compte', label: 'Sous-compte', requis: true, motif: /^(sous.?compte|compte|n.?compte|code(?!.?(tiers|journal)))/i },
  { id: 'libelle', label: 'Libellé', motif: /^(libell|intitul|d[ée]signation|description)/i },
  { id: 'debit', label: 'Débit', requis: true, motif: /^d[ée]bit/i },
  { id: 'credit', label: 'Crédit', requis: true, motif: /^cr[ée]dit/i },
  { id: 'reference', label: 'Référence', motif: /^(r[ée]f|pi[èe]ce|n.?pi[èe]ce)/i },
  { id: 'journal', label: 'Journal', requis: true, motif: /^(journal|code.?journal|jnl)/i },
  { id: 'tiers', label: 'Code tiers', motif: /^(tiers|code.?tiers|client|fournisseur)/i },
];
const STATUT = {
  'réussi': { label: 'Réussi', ton: 'success', icon: 'bi-check-circle' },
  'échec': { label: 'Échec', ton: 'danger', icon: 'bi-x-circle' },
  'annulé': { label: 'Annulé', ton: 'neutral', icon: 'bi-arrow-counterclockwise' },
};
const FILTRES_HISTO = [
  { id: '', label: 'Tous' }, { id: 'réussi', label: 'Réussis' }, { id: 'échec', label: 'Échecs' }, { id: 'annulé', label: 'Annulés' },
];

const router = useRouter();
const parametresStore = useParametresStore();
parametresStore.charger();
const tailleMax = computed(() => Number(parametresStore.get('import.taille_max')) || 10);

const user = ref(null);
const fichier = ref(null);
const tableau = ref([]);
const avecEntetes = ref(true);
const colonnes = reactive(Object.fromEntries(CHAMPS.map((c, i) => [c.id, c.requis ? i : -1])));
const survol = ref(false);
const erreurLecture = ref('');
const analyse = ref(null);
const analyseEnCours = ref(false);
const importEnCours = ref(false);
const vue = ref('lignes');
const filtreErreurs = ref(false);
const limite = ref(200);
const message = ref(null);
const ouvert = ref(null);
const annulation = ref(null);
const histo = reactive({ lignes: [], page: 1, dernierePage: 1, total: 0, parPage: 25, statut: '', q: '', compteurs: {}, chargement: false });
let minuteur = null;
let minuteurRecherche = null;

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  try { user.value = (await getUser(token)).data; } catch { return router.push('/'); }
  chargerHistorique(1);
});
onBeforeUnmount(() => { clearTimeout(minuteur); clearTimeout(minuteurRecherche); });

function annoncer(texte, ton = 'ok') {
  message.value = { texte, ton };
  clearTimeout(minuteur);
  minuteur = setTimeout(() => { message.value = null; }, 5000);
}

// ---------- Formatage ----------
const montant = v => Number(v || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dateCourte = d => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('fr-FR') : '');
const dateHeure = d => (d ? new Date(d).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '');
const periode = computed(() => {
  const r = analyse.value?.resume;
  if (!r?.date_min) return '—';
  return r.date_min === r.date_max ? dateCourte(r.date_min) : `${dateCourte(r.date_min)} → ${dateCourte(r.date_max)}`;
});

// ---------- Lecture du fichier ----------
const nbColonnes = computed(() => Math.max(0, ...tableau.value.slice(0, 50).map(l => l.length)));
const titresColonnes = computed(() => Array.from({ length: nbColonnes.value }, (_, i) => {
  const lettre = XLSX.utils.encode_col(i);
  const entete = avecEntetes.value ? String(tableau.value[0]?.[i] ?? '').trim() : '';
  return entete ? `${lettre} — ${entete}` : `Colonne ${lettre}`;
}));
const lignesBrutes = computed(() => (avecEntetes.value ? tableau.value.slice(1) : tableau.value)
  .filter(l => l.some(c => String(c ?? '').trim() !== '')));

function choisir(e) {
  const f = e.target.files?.[0];
  e.target.value = '';
  if (f) lire(f);
}
function deposer(e) {
  survol.value = false;
  const f = e.dataTransfer.files?.[0];
  if (f) lire(f);
}
function reinitialiser() {
  fichier.value = null;
  tableau.value = [];
  analyse.value = null;
  erreurLecture.value = '';
}

async function lire(f) {
  erreurLecture.value = '';
  analyse.value = null;
  if (!/\.(csv|txt|xlsx|xls)$/i.test(f.name)) {
    erreurLecture.value = 'Format non pris en charge : choisissez un fichier CSV, XLSX ou XLS.';
    return;
  }
  if (f.size > tailleMax.value * 1024 * 1024) {
    erreurLecture.value = `Le fichier dépasse ${tailleMax.value} Mo.`;
    return;
  }
  try {
    const tampon = await f.arrayBuffer();
    let classeur;
    if (/\.(csv|txt)$/i.test(f.name)) {
      let texte = new TextDecoder('utf-8').decode(tampon);
      if (texte.includes('�')) texte = new TextDecoder('windows-1252').decode(tampon);
      classeur = XLSX.read(texte, { type: 'string', raw: true, FS: detecterSeparateur(texte) });
    } else {
      // Dates Excel converties en JJ/MM/AAAA par le formatage (raw: false)
      classeur = XLSX.read(tampon, { type: 'array', cellDates: true, dateNF: 'dd/mm/yyyy' });
    }
    const feuille = classeur.Sheets[classeur.SheetNames[0]];
    const lignes = XLSX.utils.sheet_to_json(feuille, { header: 1, raw: false, defval: '', dateNF: 'dd/mm/yyyy' });
    if (!lignes.length) throw new Error('vide');
    fichier.value = f;
    tableau.value = lignes;
    detecterColonnes();
    await analyser();
  } catch {
    erreurLecture.value = 'Le fichier est vide ou illisible.';
  }
}

function detecterSeparateur(texte) {
  const premiere = texte.split(/\r?\n/).find(l => l.trim()) || '';
  return [';', '\t', ',', '|'].reduce((m, s) => (premiere.split(s).length > premiere.split(m).length ? s : m), ';');
}

function detecterColonnes() {
  const entetes = (tableau.value[0] || []).map(c => String(c ?? '').trim());
  const trouvees = CHAMPS.map(c => entetes.findIndex(e => c.motif.test(e)));
  avecEntetes.value = trouvees.filter(i => i >= 0).length >= 3;
  CHAMPS.forEach((c, k) => {
    // Sans en-têtes reconnues : ordre du modèle (date, compte, libellé, débit, crédit, référence, journal)
    colonnes[c.id] = avecEntetes.value ? (trouvees[k] >= 0 ? trouvees[k] : (c.requis ? k : -1)) : (k < nbColonnes.value ? k : -1);
  });
}

// ---------- Aperçu et import ----------
const lignesEnvoyees = () => lignesBrutes.value.map(l => Object.fromEntries(
  CHAMPS.map(c => [c.id, colonnes[c.id] >= 0 ? String(l[colonnes[c.id]] ?? '').trim() : ''])
));

async function analyser() {
  if (!lignesBrutes.value.length) {
    analyse.value = null;
    return;
  }
  analyseEnCours.value = true;
  try {
    analyse.value = (await axios.post(`${API_BASE}/ecritures/import/analyse`, { lignes: lignesEnvoyees() })).data;
    filtreErreurs.value = analyse.value.resume.erreurs > 0;
    limite.value = 200;
  } catch (e) {
    erreurLecture.value = e.response?.data?.message || "L'analyse du fichier a échoué.";
  } finally {
    analyseEnCours.value = false;
  }
}

const piecesErreur = computed(() => new Set((analyse.value?.pieces || []).map((p, i) => (p.erreur ? i + 1 : null)).filter(Boolean)));
const pieceEnErreur = n => piecesErreur.value.has(n);
const lignesFiltrees = computed(() => (analyse.value?.lignes || [])
  .filter(l => !filtreErreurs.value || l.erreurs.length || pieceEnErreur(l.piece)));
const piecesNumerotees = computed(() => (analyse.value?.pieces || []).map((p, i) => ({ ...p, num: i + 1 })));
const piecesFiltrees = computed(() => piecesNumerotees.value.filter(p => !filtreErreurs.value || p.erreur));
const lignesAffichees = computed(() => lignesFiltrees.value.slice(0, limite.value));
const piecesAffichees = computed(() => piecesFiltrees.value.slice(0, limite.value));
const resteAAfficher = computed(() => (vue.value === 'lignes' ? lignesFiltrees.value.length : piecesFiltrees.value.length) - limite.value);

async function importer() {
  if (!window.confirm(`Créer ${analyse.value.resume.pieces} pièce(s) en brouillon à partir de « ${fichier.value.name} » ?`)) return;
  importEnCours.value = true;
  try {
    const { data } = await axios.post(`${API_BASE}/ecritures/import/lignes`, { lignes: lignesEnvoyees(), fichier: fichier.value.name });
    annoncer(data.message, 'ok');
    reinitialiser();
  } catch (e) {
    annoncer(e.response?.data?.message || "L'import a échoué : rien n'a été enregistré.", 'erreur');
    if (e.response?.data?.analyse) analyse.value = e.response.data.analyse;
  } finally {
    importEnCours.value = false;
    chargerHistorique(1);
  }
}

// ---------- Historique ----------
async function chargerHistorique(page = histo.page) {
  histo.chargement = true;
  try {
    const params = { page, per_page: histo.parPage };
    if (histo.statut) params.statut = histo.statut;
    if (histo.q.trim()) params.q = histo.q.trim();
    const { data } = await axios.get(`${API_BASE}/ecritures/imports/historique`, { params });
    Object.assign(histo, {
      lignes: data.data, page: data.current_page, dernierePage: data.last_page,
      total: data.total, parPage: data.per_page, compteurs: data.compteurs || {},
    });
  } catch {
    histo.lignes = [];
  } finally {
    histo.chargement = false;
  }
}
function rechercherHistorique() {
  clearTimeout(minuteurRecherche);
  minuteurRecherche = setTimeout(() => chargerHistorique(1), 300);
}

async function annuler(h) {
  if (!window.confirm(`Annuler l'import « ${h.nom_fichier} » ? Ses ${h.pieces_restantes} pièce(s) brouillon seront supprimées.`)) return;
  annulation.value = h.id;
  try {
    const { data } = await axios.post(`${API_BASE}/ecritures/imports/${h.id}/annuler`);
    annoncer(data.message, 'ok');
    chargerHistorique();
  } catch (e) {
    annoncer(Object.values(e.response?.data?.errors || {})[0]?.[0] || e.response?.data?.message || "L'annulation a échoué.", 'erreur');
  } finally {
    annulation.value = null;
  }
}

// ---------- Modèle ----------
function telechargerModele() {
  const ws = XLSX.utils.aoa_to_sheet([
    ['Date', 'Sous-compte', 'Libellé', 'Débit', 'Crédit', 'Référence', 'Journal', 'Code tiers'],
    ['15/06/2025', '601001', 'Achat de fournitures', 150000, '', 'FAC-0012', 'ACH', ''],
    ['15/06/2025', '401001', 'Achat de fournitures', '', 150000, 'FAC-0012', 'ACH', 'F0001'],
    ['20/06/2025', '512001', 'Encaissement client', 450000, '', 'REC-0045', 'BQ', ''],
    ['20/06/2025', '411001', 'Encaissement client', '', 450000, 'REC-0045', 'BQ', 'C0001'],
  ]);
  ws['!cols'] = [{ wch: 12 }, { wch: 12 }, { wch: 34 }, { wch: 14 }, { wch: 14 }, { wch: 12 }, { wch: 9 }, { wch: 11 }];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Écritures');
  XLSX.writeFile(wb, 'modele_import_ecritures.xlsx');
}
</script>

<style scoped>
.ip-stack { display: flex; flex-direction: column; gap: 20px; }
.ip-card { padding: 22px 24px; }
.ip-head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 18px; }
.ip-head > div:not(.ip-vues) { flex: 1; }
.ip-head h2, .ip-final h2 { margin: 0; font-size: var(--fs-text-md); font-weight: 600; color: var(--fs-text); }
.ip-head p, .ip-final p { margin: 3px 0 0; font-size: var(--fs-text-sm); color: var(--fs-muted); }
.ip-head--plain { margin-bottom: 14px; }
.ip-step {
  flex: none; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center;
  background: var(--fs-primary-soft); color: var(--fs-primary-text); font-size: var(--fs-text-sm); font-weight: 700;
}

.ip-file-row { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; }
.ip-drop {
  position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
  min-height: 140px; padding: 20px; text-align: center; cursor: pointer;
  border: 1.5px dashed var(--fs-border-strong); border-radius: var(--fs-radius-sm);
  background: var(--fs-surface-2); color: var(--fs-text-2); font-size: var(--fs-text-sm);
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.ip-drop .bi { font-size: 28px; color: var(--fs-muted); }
.ip-drop:hover, .ip-drop.is-over { border-color: var(--fs-primary); background: var(--fs-primary-soft); }
.ip-drop.has-file { border-style: solid; border-color: var(--fs-success); }
.ip-drop.has-file .bi { color: var(--fs-success); }
.ip-drop__input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.ip-drop:focus-within { outline: 2px solid var(--fs-primary); outline-offset: 2px; }

.ip-regles {
  margin: 0; padding: 14px 16px; list-style: none; border-radius: var(--fs-radius-sm);
  background: var(--fs-surface-2); display: grid; gap: 7px; font-size: var(--fs-text-sm); color: var(--fs-text-2);
}
.ip-regles li { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ip-regles .bi { color: var(--fs-muted); }
.ip-regles__note { margin-top: 4px; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.ip-regles code, .ip-code {
  padding: 1px 6px; border-radius: 5px; background: var(--fs-surface-3);
  font-size: var(--fs-text-xs); font-weight: 600; color: var(--fs-text); font-variant-numeric: tabular-nums;
}
.ip-error { margin: 14px 0 0; display: flex; align-items: center; gap: 8px; color: var(--fs-negative); font-size: var(--fs-text-sm); }

.ip-mapping {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px 16px; align-items: end;
  margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--fs-border);
}
.ip-mapping__full { grid-column: 1 / -1; }
.ip-check { display: flex; align-items: center; gap: 8px; font-size: var(--fs-text-sm); color: var(--fs-text-2); cursor: pointer; }
.ip-check input { width: 16px; height: 16px; accent-color: var(--fs-primary); }

.ip-vues { display: inline-flex; padding: 3px; border-radius: 9px; background: var(--fs-surface-3); }
.ip-vues button {
  height: 28px; padding: 0 12px; border: 0; border-radius: 7px; background: transparent; cursor: pointer;
  font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2);
}
.ip-vues button.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }

.ip-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.ip-stat {
  display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 12px 14px; min-width: 0;
  border: 1px solid var(--fs-border); border-radius: var(--fs-radius-sm); background: var(--fs-surface); text-align: left;
}
button.ip-stat { cursor: pointer; transition: border-color 0.15s ease, background-color 0.15s ease; }
button.ip-stat:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.ip-stat__val { font-size: var(--fs-text-xl); font-weight: 700; line-height: 1.15; color: var(--fs-text); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.ip-stat__val--sm { font-size: var(--fs-text-md); }
.ip-stat__lbl { display: inline-flex; align-items: center; gap: 6px; font-size: var(--fs-text-xs); font-weight: 500; color: var(--fs-muted); }
.ip-stat--danger .ip-stat__val, .ip-stat--danger .bi { color: var(--fs-negative); }
.ip-stat--danger.is-zero { cursor: default; }
.ip-stat--danger.is-zero .ip-stat__val, .ip-stat--danger.is-zero .bi { color: var(--fs-success); }
.ip-stat--danger.is-active { border-color: var(--fs-negative); background: var(--fs-negative-soft); }

.ip-table { margin: 0 -24px; border-top: 1px solid var(--fs-border); max-height: 540px; }
.ip-table--flush { max-height: none; }
.ip-col-num { width: 56px; color: var(--fs-muted); font-variant-numeric: tabular-nums; }
.ip-lib { max-width: 280px; }
.ip-sub { display: block; margin-top: 2px; font-size: var(--fs-text-xs); color: var(--fs-muted); max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ip-muted { color: var(--fs-muted); font-size: var(--fs-text-sm); }
.ip-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.ip-nowrap { white-space: nowrap; }
.ip-row-err td { background: var(--fs-negative-soft); }
.ip-row-msg td { padding-top: 0 !important; background: var(--fs-negative-soft); color: var(--fs-negative); font-size: var(--fs-text-sm); }
.ip-row-avert td { padding-top: 0 !important; background: var(--fs-warning-soft); color: var(--fs-warning); font-size: var(--fs-text-sm); }
.ip-more { display: flex; justify-content: center; padding-top: 12px; }

.ip-final { display: flex; align-items: center; justify-content: space-between; gap: 18px; border-left: 3px solid var(--fs-success); }
.ip-final--ko { border-left-color: var(--fs-negative); }

/* Historique */
.ih-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.ih-filtres { display: flex; flex-wrap: wrap; gap: 4px; padding: 3px; border-radius: 10px; background: var(--fs-surface-3); }
.ih-filtre {
  display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px;
  border: 0; border-radius: 7px; background: transparent; cursor: pointer;
  font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2);
}
.ih-filtre span { font-size: var(--fs-text-xs); color: var(--fs-muted); font-variant-numeric: tabular-nums; }
.ih-filtre.is-active { background: var(--fs-surface); color: var(--fs-text); box-shadow: var(--fs-shadow); }
.ih-filtre:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 1px; }
.ih-search { position: relative; width: 260px; max-width: 100%; }
.ih-search .bi { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--fs-muted); }
.ih-search .fs-input { padding-left: 36px; }
.ih-file { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ih-file .bi { font-size: 20px; color: var(--fs-muted); }
.ih-file strong { display: block; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--fs-text); font-weight: 600; }
.ih-file span, .ih-sub { display: block; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.ih-sub { margin-top: 3px; }
.ih-actions { justify-content: flex-end; flex-wrap: nowrap; }
.ih-errors td { background: var(--fs-surface-2); }
.ih-errors ul { margin: 0; padding: 4px 0 4px 20px; display: grid; gap: 3px; font-size: var(--fs-text-sm); color: var(--fs-negative); max-height: 220px; overflow: auto; }
.ih-pagination { margin-top: 14px; }

.ip-toast {
  position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1100;
  display: inline-flex; align-items: center; gap: 8px; max-width: calc(100vw - 32px);
  padding: 11px 16px; border-radius: 10px; box-shadow: var(--fs-shadow-lg);
  font-size: var(--fs-text-sm); font-weight: 500; color: #fff;
}
.ip-toast--ok { background: var(--fs-success); }
.ip-toast--erreur { background: var(--fs-negative); }

@media (max-width: 1200px) { .ip-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 1000px) {
  .ip-file-row { grid-template-columns: 1fr; }
  .ip-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
