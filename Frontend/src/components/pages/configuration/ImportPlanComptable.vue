<!--
  Import / export du plan comptable.
  Le fichier (CSV, XLSX, XLS) est lu dans le navigateur ; seules les colonnes
  « code » et « libellé » sont envoyées au serveur, qui renvoie d'abord un
  aperçu (simulation) puis applique l'import sur confirmation.
-->
<template>
  <AppLayout
    :user="user"
    icon="bi-file-earmark-arrow-up"
    title="Importer un plan comptable"
    subtitle="Classes, rubriques, comptes et sous-comptes depuis un fichier CSV ou Excel"
    section="Structure"
  >
    <template #actions>
      <UiButton icon="bi-download" :loading="exportEnCours" @click="exporterPlan">Exporter le plan actuel</UiButton>
    </template>

    <div class="ip-stack">
      <!-- ===== 1. Fichier ===== -->
      <section class="fs-card ip-card">
        <header class="ip-head">
          <span class="ip-step">1</span>
          <div>
            <h2>Fichier</h2>
            <p>Une ligne par élément : <strong>code</strong> et <strong>libellé</strong>. Le niveau se déduit de la longueur du code.</p>
          </div>
          <UiButton size="sm" variant="ghost" icon="bi-file-earmark-spreadsheet" @click="telechargerModele">Télécharger un modèle</UiButton>
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
            <span v-else><strong>Glissez un fichier ici</strong> ou cliquez pour choisir · CSV, XLSX, XLS</span>
          </label>

          <ul class="ip-regles">
            <li><code>6</code> classe</li>
            <li><code>60</code> rubrique</li>
            <li><code>601</code> compte</li>
            <li><code>601001</code> sous-compte</li>
            <li class="ip-regles__note">4–5 chiffres : complétés par des zéros (<code>6011</code> → <code>601100</code>). Les parents manquants sont créés automatiquement.</li>
          </ul>
        </div>

        <p v-if="erreurLecture" class="ip-error" role="alert"><i class="bi bi-exclamation-circle"></i> {{ erreurLecture }}</p>

        <!-- Colonnes -->
        <div v-if="tableau.length" class="ip-mapping">
          <div class="fs-field">
            <label class="fs-label" for="ip-col-code">Colonne du code</label>
            <select id="ip-col-code" v-model.number="colCode" class="fs-select" @change="analyser">
              <option v-for="(t, i) in titresColonnes" :key="i" :value="i">{{ t }}</option>
            </select>
          </div>
          <div class="fs-field">
            <label class="fs-label" for="ip-col-lib">Colonne du libellé</label>
            <select id="ip-col-lib" v-model.number="colLibelle" class="fs-select" @change="analyser">
              <option v-for="(t, i) in titresColonnes" :key="i" :value="i">{{ t }}</option>
            </select>
          </div>
          <label class="ip-check">
            <input v-model="avecEntetes" type="checkbox" @change="analyser" />
            <span>La première ligne contient les en-têtes</span>
          </label>
          <label class="ip-check">
            <input v-model="majLibelles" type="checkbox" @change="analyser" />
            <span>Mettre à jour les libellés des éléments existants</span>
          </label>
        </div>
      </section>

      <!-- ===== 2. Aperçu ===== -->
      <section v-if="analyse || analyseEnCours" class="fs-card ip-card">
        <header class="ip-head">
          <span class="ip-step">2</span>
          <div>
            <h2>Aperçu</h2>
            <p>Rien n'est encore enregistré. Vérifiez puis lancez l'import.</p>
          </div>
        </header>

        <UiSkeleton v-if="analyseEnCours && !analyse" :rows="4" />
        <template v-else-if="analyse">
          <div class="ip-stats" role="group" aria-label="Filtrer l'aperçu">
            <button v-for="s in STATS" :key="s.id" type="button" class="ip-stat" :class="[`ip-stat--${s.ton}`, { 'is-active': filtre === s.id }]"
                    :aria-pressed="filtre === s.id" @click="filtre = filtre === s.id ? 'tous' : s.id">
              <span class="ip-stat__val">{{ s.valeur(analyse.resume) }}</span>
              <span class="ip-stat__lbl"><i :class="['bi', s.icon]" aria-hidden="true"></i>{{ s.label }}</span>
            </button>
          </div>

          <details v-if="analyse.parents.length" class="ip-parents">
            <summary>
              <i class="bi bi-diagram-3"></i>
              {{ analyse.parents.length }} parent(s) absent(s) seront créés avec un libellé provisoire, à renommer ensuite
            </summary>
            <ul>
              <li v-for="p in analyse.parents" :key="p.code"><code>{{ p.code }}</code> {{ p.libelle }} <span>{{ NIVEAU[p.niveau] }}</span></li>
            </ul>
          </details>

          <div class="fs-table-wrap ip-table">
            <table class="fs-table">
              <thead>
                <tr>
                  <th class="ip-col-num">N°</th>
                  <th>Code</th>
                  <th>Libellé</th>
                  <th>Niveau</th>
                  <th>Action</th>
                  <th>Détail</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in lignesAffichees" :key="l.ligne">
                  <td class="ip-col-num">{{ l.ligne }}</td>
                  <td><code class="ip-code">{{ l.code || l.code_source || '—' }}</code></td>
                  <td class="ip-lib">{{ l.libelle || '—' }}</td>
                  <td class="ip-muted">{{ NIVEAU[l.niveau] || '—' }}</td>
                  <td><UiBadge :tone="ACTION[l.action].ton" :icon="ACTION[l.action].icon" size="sm">{{ ACTION[l.action].label }}</UiBadge></td>
                  <td class="ip-muted">{{ [l.note, l.message].filter(Boolean).join(' · ') }}</td>
                </tr>
                <tr v-if="!lignesFiltrees.length"><td colspan="6" class="fs-empty">Aucune ligne dans ce filtre.</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="lignesFiltrees.length > limite" class="ip-more">
            <UiButton size="sm" variant="ghost" @click="limite += 200">Afficher 200 lignes de plus ({{ lignesFiltrees.length - limite }} restantes)</UiButton>
          </div>
        </template>
      </section>

      <!-- ===== 3. Import ===== -->
      <section v-if="analyse" class="fs-card ip-card ip-final">
        <div>
          <h2>{{ aImporter ? `${aImporter} élément(s) à enregistrer` : 'Rien à importer' }}</h2>
          <p v-if="analyse.resume.erreur">{{ analyse.resume.erreur }} ligne(s) en erreur seront ignorées.</p>
          <p v-else-if="aImporter">Les créations sont tracées dans la piste d'audit.</p>
          <p v-else>Le plan comptable contient déjà toutes les lignes du fichier.</p>
        </div>
        <UiButton variant="primary" icon="bi-check2-circle" :disabled="!aImporter" :loading="importEnCours" @click="importer">
          Importer
        </UiButton>
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
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import * as XLSX from 'xlsx';
import { API_BASE } from '@/config/api';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';

const NIVEAU = { classe: 'Classe', rubrique: 'Rubrique', compte: 'Compte', sous_compte: 'Sous-compte' };
const ACTION = {
  creer: { label: 'À créer', ton: 'success', icon: 'bi-plus-circle' },
  maj: { label: 'Libellé modifié', ton: 'info', icon: 'bi-pencil' },
  inchange: { label: 'Inchangé', ton: 'neutral', icon: 'bi-dash-circle' },
  erreur: { label: 'Erreur', ton: 'danger', icon: 'bi-x-circle' },
};
const STATS = [
  { id: 'creer', label: 'À créer', icon: 'bi-plus-circle', ton: 'success', valeur: r => r.creer },
  { id: 'maj', label: 'Libellés modifiés', icon: 'bi-pencil', ton: 'info', valeur: r => r.maj },
  { id: 'inchange', label: 'Inchangés', icon: 'bi-dash-circle', ton: 'neutral', valeur: r => r.inchange },
  { id: 'erreur', label: 'Erreurs', icon: 'bi-x-circle', ton: 'danger', valeur: r => r.erreur },
];
// En-têtes reconnus automatiquement
const ENTETES_CODE = /^(code|compte|n[°o]?|num(ero|éro)?|numero.?compte|n.?compte|account)/i;
const ENTETES_LIBELLE = /^(libell|intitul|d[ée]signation|nom|label|description)/i;

const router = useRouter();
const user = ref(null);
const fichier = ref(null);
const tableau = ref([]);          // lignes brutes du fichier (tableaux de cellules)
const avecEntetes = ref(true);
const colCode = ref(0);
const colLibelle = ref(1);
const majLibelles = ref(false);
const survol = ref(false);
const erreurLecture = ref('');
const analyse = ref(null);
const analyseEnCours = ref(false);
const importEnCours = ref(false);
const exportEnCours = ref(false);
const filtre = ref('tous');
const limite = ref(200);
const message = ref(null);
let minuteur = null;

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  try { user.value = (await getUser(token)).data; } catch { router.push('/'); }
});
onBeforeUnmount(() => clearTimeout(minuteur));

function annoncer(texte, ton = 'ok') {
  message.value = { texte, ton };
  clearTimeout(minuteur);
  minuteur = setTimeout(() => { message.value = null; }, 5000);
}

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

async function lire(f) {
  erreurLecture.value = '';
  analyse.value = null;
  if (!/\.(csv|txt|xlsx|xls)$/i.test(f.name)) {
    erreurLecture.value = 'Format non pris en charge : choisissez un fichier CSV, XLSX ou XLS.';
    return;
  }
  try {
    const tampon = await f.arrayBuffer();
    // CSV : on décode en UTF-8, ou en Windows-1252 si les accents sont illisibles
    let classeur;
    if (/\.(csv|txt)$/i.test(f.name)) {
      let texte = new TextDecoder('utf-8').decode(tampon);
      if (texte.includes('�')) texte = new TextDecoder('windows-1252').decode(tampon);
      classeur = XLSX.read(texte, { type: 'string', raw: true, FS: detecterSeparateur(texte) });
    } else {
      classeur = XLSX.read(tampon, { type: 'array' });
    }
    const feuille = classeur.Sheets[classeur.SheetNames[0]];
    const lignes = XLSX.utils.sheet_to_json(feuille, { header: 1, raw: false, defval: '' });
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
  const candidats = [';', '\t', ',', '|'];
  return candidats.reduce((m, s) => (premiere.split(s).length > premiere.split(m).length ? s : m), ';');
}

function detecterColonnes() {
  const premiere = (tableau.value[0] || []).map(c => String(c ?? '').trim());
  const iCode = premiere.findIndex(c => ENTETES_CODE.test(c));
  const iLib = premiere.findIndex(c => ENTETES_LIBELLE.test(c));
  // En-têtes présentes si la 1re cellule n'est pas un code numérique
  avecEntetes.value = !/^\d[\d\s.]*$/.test(premiere[iCode >= 0 ? iCode : 0] || '');
  colCode.value = iCode >= 0 ? iCode : 0;
  colLibelle.value = iLib >= 0 ? iLib : (colCode.value === 0 ? 1 : 0);
}

// ---------- Aperçu et import ----------
const lignesEnvoyees = () => lignesBrutes.value.map(l => ({
  code: String(l[colCode.value] ?? '').trim(),
  libelle: String(l[colLibelle.value] ?? '').trim(),
}));

async function appeler(simulation) {
  return (await axios.post(`${API_BASE}/plan-comptable/import`, {
    lignes: lignesEnvoyees(),
    mettre_a_jour_libelles: majLibelles.value,
    simulation,
    fichier: fichier.value?.name,
  })).data;
}

async function analyser() {
  if (!lignesBrutes.value.length) {
    analyse.value = null;
    erreurLecture.value = tableau.value.length ? 'Aucune ligne de données.' : '';
    return;
  }
  analyseEnCours.value = true;
  try {
    analyse.value = await appeler(true);
    filtre.value = analyse.value.resume.erreur ? 'erreur' : 'tous';
    limite.value = 200;
  } catch (e) {
    erreurLecture.value = e.response?.data?.message || "L'analyse du fichier a échoué.";
  } finally {
    analyseEnCours.value = false;
  }
}

const aImporter = computed(() => (analyse.value
  ? analyse.value.resume.creer + analyse.value.resume.maj + analyse.value.resume.parents : 0));
const lignesFiltrees = computed(() => (analyse.value?.lignes || []).filter(l => filtre.value === 'tous' || l.action === filtre.value));
const lignesAffichees = computed(() => lignesFiltrees.value.slice(0, limite.value));

async function importer() {
  if (!window.confirm(`Enregistrer ${aImporter.value} élément(s) dans le plan comptable ?`)) return;
  importEnCours.value = true;
  try {
    const r = await appeler(false);
    annoncer(r.message, 'ok');
    await analyser();          // l'aperçu reflète maintenant l'état enregistré
  } catch (e) {
    annoncer(e.response?.data?.message || "L'import a échoué : rien n'a été enregistré.", 'erreur');
  } finally {
    importEnCours.value = false;
  }
}

// ---------- Modèle et export ----------
function ecrireClasseur(lignes, nom) {
  const ws = XLSX.utils.aoa_to_sheet(lignes);
  ws['!cols'] = [{ wch: 12 }, { wch: 55 }, { wch: 14 }];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Plan comptable');
  XLSX.writeFile(wb, nom);
}

function telechargerModele() {
  ecrireClasseur([
    ['Code', 'Libellé'],
    ['6', 'Comptes de charges'],
    ['60', 'Achats consommés'],
    ['601', 'Matières premières et fournitures'],
    ['601001', 'Papier, cahiers et copies'],
    ['601002', 'Encres et craies'],
    ['512001', 'Banque — compte courant'],
  ], 'modele_plan_comptable.xlsx');
}

async function exporterPlan() {
  exportEnCours.value = true;
  try {
    const { data } = await axios.get(`${API_BASE}/plan-comptable/export`);
    ecrireClasseur([['Code', 'Libellé', 'Niveau'], ...data.map(l => [String(l.code), l.libelle, l.niveau])],
      `plan_comptable_${new Date().toISOString().slice(0, 10)}.xlsx`);
  } catch {
    annoncer("L'export du plan comptable a échoué.", 'erreur');
  } finally {
    exportEnCours.value = false;
  }
}
</script>

<style scoped>
.ip-stack { display: flex; flex-direction: column; gap: 20px; }
.ip-card { padding: 22px 24px; }
.ip-head { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 18px; }
.ip-head > div { flex: 1; }
.ip-head h2, .ip-final h2 { margin: 0; font-size: var(--fs-text-md); font-weight: 600; color: var(--fs-text); }
.ip-head p, .ip-final p { margin: 3px 0 0; font-size: var(--fs-text-sm); color: var(--fs-muted); }
.ip-step {
  flex: none; width: 28px; height: 28px; border-radius: 50%;
  display: grid; place-items: center;
  background: var(--fs-primary-soft); color: var(--fs-primary-text); font-size: var(--fs-text-sm); font-weight: 700;
}

.ip-file-row { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 18px; }
.ip-drop {
  position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
  min-height: 132px; padding: 20px; text-align: center; cursor: pointer;
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

.ip-regles { margin: 0; padding: 14px 16px; list-style: none; border-radius: var(--fs-radius-sm); background: var(--fs-surface-2); display: grid; gap: 6px; font-size: var(--fs-text-sm); color: var(--fs-text-2); }
.ip-regles code, .ip-parents code, .ip-code {
  padding: 1px 6px; border-radius: 5px; background: var(--fs-surface-3);
  font-size: var(--fs-text-xs); font-weight: 600; color: var(--fs-text); font-variant-numeric: tabular-nums;
}
.ip-regles li { display: flex; align-items: center; gap: 8px; }
.ip-regles__note { display: block !important; margin-top: 4px; font-size: var(--fs-text-xs); color: var(--fs-muted); line-height: 1.5; }

.ip-error { margin: 14px 0 0; display: flex; align-items: center; gap: 8px; color: var(--fs-negative); font-size: var(--fs-text-sm); }

.ip-mapping {
  display: grid; grid-template-columns: repeat(2, minmax(0, 220px)) 1fr; gap: 14px 18px; align-items: end;
  margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--fs-border);
}
.ip-check { display: flex; align-items: center; gap: 8px; font-size: var(--fs-text-sm); color: var(--fs-text-2); cursor: pointer; }
.ip-check input { width: 16px; height: 16px; accent-color: var(--fs-primary); }
.ip-mapping .ip-check:last-child { grid-column: 3; }

.ip-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.ip-stat {
  display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 12px 14px;
  border: 1px solid var(--fs-border); border-radius: var(--fs-radius-sm); background: var(--fs-surface);
  cursor: pointer; text-align: left; transition: border-color 0.15s ease, background-color 0.15s ease;
}
.ip-stat:hover { border-color: var(--fs-border-strong); }
.ip-stat:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.ip-stat.is-active { border-color: var(--fs-primary); background: var(--fs-primary-soft); }
.ip-stat__val { font-size: var(--fs-text-xl); font-weight: 700; line-height: 1.1; color: var(--fs-text); font-variant-numeric: tabular-nums; }
.ip-stat__lbl { display: inline-flex; align-items: center; gap: 6px; font-size: var(--fs-text-xs); font-weight: 500; color: var(--fs-muted); }
.ip-stat--success .bi { color: var(--fs-success); }
.ip-stat--info .bi { color: var(--fs-primary-text); }
.ip-stat--danger .bi { color: var(--fs-negative); }
.ip-stat--danger .ip-stat__val { color: var(--fs-negative); }

.ip-parents { margin-bottom: 16px; border: 1px solid var(--fs-border); border-radius: var(--fs-radius-sm); background: var(--fs-warning-soft); }
.ip-parents summary { display: flex; align-items: center; gap: 8px; padding: 10px 14px; cursor: pointer; font-size: var(--fs-text-sm); font-weight: 500; color: var(--fs-text-2); }
.ip-parents ul { margin: 0; padding: 4px 14px 12px 38px; display: grid; gap: 4px; font-size: var(--fs-text-sm); color: var(--fs-text-2); }
.ip-parents li span { margin-left: 6px; font-size: var(--fs-text-xs); color: var(--fs-muted); }

.ip-table { margin: 0 -24px; border-top: 1px solid var(--fs-border); max-height: 520px; }
.ip-col-num { width: 64px; color: var(--fs-muted); font-variant-numeric: tabular-nums; }
.ip-lib { max-width: 360px; }
.ip-muted { color: var(--fs-muted); font-size: var(--fs-text-sm); }
.ip-more { display: flex; justify-content: center; padding-top: 12px; }

.ip-final { display: flex; align-items: center; justify-content: space-between; gap: 18px; }

.ip-toast {
  position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1100;
  display: inline-flex; align-items: center; gap: 8px; max-width: calc(100vw - 32px);
  padding: 11px 16px; border-radius: 10px; box-shadow: var(--fs-shadow-lg);
  font-size: var(--fs-text-sm); font-weight: 500; color: #fff;
}
.ip-toast--ok { background: var(--fs-success); }
.ip-toast--erreur { background: var(--fs-negative); }

@media (max-width: 1000px) {
  .ip-file-row, .ip-mapping { grid-template-columns: 1fr; }
  .ip-mapping .ip-check:last-child { grid-column: auto; }
  .ip-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
