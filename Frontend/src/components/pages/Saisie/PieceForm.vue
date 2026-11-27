<template>
  <AppLayout
    :user="user"
    icon="bi-pencil-square"
    :title="titre"
    :subtitle="sousTitre"
    section="Saisie"
    wide
  >
    <template #actions>
      <UiButton to="/pieces" icon="bi-list-ul">Toutes les pièces</UiButton>
      <UiButton v-if="piece" :to="`/audit?objet_type=piece&objet_id=${piece.id}`" icon="bi-clock-history">Historique</UiButton>
      <div v-if="!lectureSeule" class="pf-menu" @keydown.esc="menuModeles = false">
        <UiButton icon="bi-collection" icon-right="bi-chevron-down" :aria-expanded="menuModeles" @click="menuModeles = !menuModeles">
          Modèles
        </UiButton>
        <div v-if="menuModeles" class="pf-menu__liste" role="menu">
          <p v-if="!modeles.length" class="pf-menu__vide">Aucun modèle enregistré</p>
          <div v-for="m in modeles" :key="m.id" class="pf-menu__item">
            <button type="button" role="menuitem" class="pf-menu__choix" @click="appliquerModele(m)">
              <strong>{{ m.nom }}</strong>
              <span>{{ m.lignes.length }} lignes{{ m.journal ? ` · ${m.journal.Code}` : '' }}</span>
            </button>
            <UiButton variant="ghost" size="sm" icon="bi-trash3" icon-only :aria-label="`Supprimer le modèle ${m.nom}`" @click="supprimerModele(m)" />
          </div>
          <hr />
          <button type="button" role="menuitem" class="pf-menu__choix" :disabled="!lignesSaisies.length" @click="ouvrirEnregistrementModele">
            <strong><i class="bi bi-plus-lg"></i> Enregistrer cette pièce comme modèle</strong>
          </button>
        </div>
      </div>
      <UiButton v-if="piece" icon="bi-files" :to="`/ecriture?depuis=${piece.id}`">Dupliquer</UiButton>
      <UiButton v-if="piece" variant="primary" icon="bi-plus-lg" to="/ecriture">Nouvelle pièce</UiButton>
    </template>

    <div v-if="chargement" class="pf-sk"><UiSkeleton variant="cards" :rows="4" /><div class="fs-card"><UiSkeleton variant="table" :rows="5" :cols="6" /></div></div>

    <form v-else class="pf" @submit.prevent="enregistrer('brouillon')" @keydown.ctrl.s.prevent="enregistrer('brouillon')" @keydown.ctrl.enter.prevent="peutValider && enregistrer('valider')">
      <!-- Messages -->
      <div v-if="erreurs.length" class="fs-alert fs-alert--error" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <div><p v-for="(e, i) in erreurs" :key="i" class="pf-msg">{{ e }}</p></div>
      </div>
      <div v-if="succes" class="fs-alert fs-alert--success" role="status">
        <i class="bi bi-check-circle"></i> <span>{{ succes }}</span>
      </div>
      <div v-if="lectureSeule" class="pf-lecture">
        <UiBadge tone="success" dot>Validée</UiBadge>
        <UiBadge v-if="typeLibelle" tone="info">{{ typeLibelle }}</UiBadge>
        <span class="pf-lecture__texte">
          Pièce figée — validée<span v-if="piece.valide_par"> par <strong>{{ piece.valide_par }}</strong></span>
          <span v-if="piece.date_validation"> le {{ formatDate(piece.date_validation) }}</span>.
          <template v-if="piece.contre_passee_par">
            Contre-passée par <router-link :to="`/ecriture/${piece.contre_passee_par.id}`">{{ piece.contre_passee_par.numero }}</router-link>.
          </template>
          <template v-else-if="piece.origine">
            Contre-passation de <router-link :to="`/ecriture/${piece.origine.id}`">{{ piece.origine.numero }}</router-link>.
          </template>
          <template v-else-if="!piece.motif_non_contrepassable">
            Une écriture validée ne se modifie pas : pour la corriger, contre-passez-la puis saisissez l'écriture exacte.
          </template>
          <template v-else>{{ piece.motif_non_contrepassable }}</template>
        </span>
        <UiButton v-if="!piece.motif_non_contrepassable" variant="soft-primary" size="sm" icon="bi-arrow-left-right" @click="ouvrirContrePassation">Contre-passer</UiButton>
      </div>

      <!-- En-tête de la pièce -->
      <section class="fs-card pf-entete" aria-label="En-tête de la pièce">
        <div class="fs-field">
          <label class="pf-label" for="pf-journal">Journal <span class="pf-req">*</span></label>
          <select id="pf-journal" v-model="form.Id_Journal" class="fs-select" :disabled="lectureSeule" required>
            <option value="" disabled>Choisir…</option>
            <option v-for="j in journaux" :key="j.Id_Journal" :value="j.Id_Journal">{{ j.Code }} — {{ j.Libelle }}</option>
          </select>
        </div>
        <div class="fs-field">
          <label class="pf-label" for="pf-date">Date <span class="pf-req">*</span></label>
          <input id="pf-date" v-model="form.Date_mouvement" type="date" class="fs-input" :min="bornesDates.min" :max="bornesDates.max" :disabled="lectureSeule" required />
          <small v-if="exercicesOuverts.length && !lectureSeule" class="pf-aide">{{ aideExercices }}</small>
        </div>
        <div class="fs-field">
          <label class="pf-label" for="pf-ref">Réf. pièce justificative <span class="pf-req">*</span></label>
          <input id="pf-ref" v-model="form.Reference" type="text" maxlength="100" class="fs-input" :placeholder="lectureSeule ? '' : 'N° de facture, reçu, relevé…'" :disabled="lectureSeule" />
          <small v-if="!lectureSeule" class="pf-aide">Obligatoire pour valider</small>
        </div>
        <div class="fs-field pf-entete__libelle">
          <label class="pf-label" for="pf-libelle">Libellé de la pièce</label>
          <input id="pf-libelle" ref="champLibelle" v-model="form.Libelle" type="text" maxlength="255" class="fs-input" :placeholder="lectureSeule ? '' : 'Ex. Paiement facture Jirama de juin'" :disabled="lectureSeule" />
          <small v-if="!lectureSeule" class="pf-aide">Repris sur les lignes sans libellé</small>
        </div>
        <div class="fs-field">
          <label class="pf-label" for="pf-mode">Mode de paiement</label>
          <select id="pf-mode" v-model="form.Id_Mode_paiement" class="fs-select" :disabled="lectureSeule">
            <option value="">—</option>
            <option v-for="m in modesPaiement" :key="m.Id_Mode_paiement" :value="m.Id_Mode_paiement">{{ m.Libelle }}</option>
          </select>
        </div>
        <div class="fs-field">
          <span class="pf-label">Justificatif</span>
          <div class="pf-justif">
            <template v-if="justificatif.fichier">
              <UiBadge tone="primary" icon="bi-paperclip">{{ justificatif.fichier.name }}</UiBadge>
              <UiButton v-if="!lectureSeule" variant="ghost" size="sm" icon="bi-x-lg" icon-only aria-label="Retirer le fichier" @click="justificatif.fichier = null" />
            </template>
            <template v-else-if="justificatif.nomExistant && !justificatif.supprimer">
              <UiButton variant="soft-primary" size="sm" icon="bi-paperclip" @click="telechargerJustificatif">{{ justificatif.nomExistant }}</UiButton>
              <UiButton v-if="!lectureSeule" variant="ghost" size="sm" icon="bi-trash3" icon-only aria-label="Supprimer le justificatif" @click="justificatif.supprimer = true" />
            </template>
            <label v-else-if="!lectureSeule" class="pf-upload">
              <i class="bi bi-cloud-arrow-up"></i> Joindre (PDF, image · {{ tailleMaxJustificatif }} Mo max)
              <input type="file" accept=".pdf,image/png,image/jpeg,image/webp" @change="choisirFichier" />
            </label>
            <span v-else class="pf-aide">Aucun</span>
          </div>
        </div>
        <label class="pf-switch pf-entete__extourne" :class="{ 'is-lecture': lectureSeule }">
          <input v-model="form.a_extourner" type="checkbox" :disabled="lectureSeule" />
          <span>
            <strong>Régularisation à extourner</strong> au 1er jour de l'exercice suivant
            <small class="pf-aide">(charges à payer, produits à recevoir, charges ou produits constatés d'avance)</small>
          </span>
        </label>
      </section>

      <!-- Lignes -->
      <section class="fs-card pf-lignes" aria-label="Lignes de la pièce">
        <table class="fs-table pf-table">
          <thead>
            <tr>
              <th class="pf-col-n">#</th>
              <th class="pf-col-compte">Compte</th>
              <th class="pf-col-tiers">Tiers</th>
              <th>Libellé</th>
              <th class="text-right pf-col-montant">Débit</th>
              <th class="text-right pf-col-montant">Crédit</th>
              <th class="pf-col-action"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in lignes" :key="l.cle" :class="{ 'pf-ligne--erreur': ligneEnErreur(l) }">
              <td class="pf-col-n">{{ i + 1 }}</td>
              <td>
                <CompteSelect
                  :ref="el => (refsCompte[l.cle] = el)"
                  v-model="l.Id_Sous_compte"
                  :comptes="comptes"
                  :disabled="lectureSeule"
                  :invalide="!l.Id_Sous_compte && montantDe(l) > 0"
                  :aria-label="`Compte de la ligne ${i + 1}`"
                  @choisi="focusChamp(l.cle, 'debit')"
                />
              </td>
              <td>
                <template v-if="lectureSeule">
                  <router-link v-if="l.tiers" :to="`/tiers/${l.tiers.id}`" class="pf-tiers-lien">{{ l.tiers.nom }}</router-link>
                  <UiBadge v-if="l.lettrage" tone="success" size="sm" :title="`Lettrage ${l.lettrage}`">{{ l.lettrage }}</UiBadge>
                </template>
                <select
                  v-else-if="accepteTiers(l)"
                  :value="l.id_tiers ?? ''"
                  class="fs-select pf-input"
                  :class="{ 'is-invalide': tiersManquant(l) }"
                  :aria-label="`Tiers de la ligne ${i + 1}`"
                  @change="choisirTiers(l, $event.target)"
                >
                  <option value="">{{ exigeTiers(l) ? 'Choisir…' : '—' }}</option>
                  <option v-for="t in tiersPour(l)" :key="t.id" :value="t.id">{{ t.nom }} ({{ t.code }})</option>
                  <option value="__nouveau">＋ Nouveau tiers…</option>
                </select>
              </td>
              <td>
                <input v-model="l.Libelle" type="text" class="fs-input pf-input" :placeholder="lectureSeule ? '' : (form.Libelle || 'Libellé')" :disabled="lectureSeule" :aria-label="`Libellé de la ligne ${i + 1}`" />
              </td>
              <td>
                <input
                  :ref="el => (refsMontant[`${l.cle}-debit`] = el)"
                  v-model.number="l.Debit" type="number" min="0" step="0.01" inputmode="decimal"
                  class="fs-input pf-input pf-montant" :placeholder="lectureSeule ? '' : '0,00'" :disabled="lectureSeule"
                  :aria-label="`Débit de la ligne ${i + 1}`"
                  @input="l.Debit > 0 && (l.Credit = null)"
                  @keydown.enter.prevent="apresMontant(i)"
                />
              </td>
              <td>
                <input
                  :ref="el => (refsMontant[`${l.cle}-credit`] = el)"
                  v-model.number="l.Credit" type="number" min="0" step="0.01" inputmode="decimal"
                  class="fs-input pf-input pf-montant" :placeholder="lectureSeule ? '' : '0,00'" :disabled="lectureSeule"
                  :aria-label="`Crédit de la ligne ${i + 1}`"
                  @input="l.Credit > 0 && (l.Debit = null)"
                  @keydown.enter.prevent="apresMontant(i)"
                />
              </td>
              <td class="pf-col-action">
                <UiButton v-if="!lectureSeule && lignes.length > 1" variant="ghost" size="sm" icon="bi-trash3" icon-only :aria-label="`Supprimer la ligne ${i + 1}`" @click="supprimerLigne(i)" />
              </td>
            </tr>

            <!-- Contrepartie automatique (journal de trésorerie) -->
            <tr v-if="ligneContrepartie" class="pf-contrepartie">
              <td class="pf-col-n"><i class="bi bi-magic" title="Calculée automatiquement"></i></td>
              <td><span class="pf-cp-compte">{{ libelleCompte(compteJournal) }}</span></td>
              <td></td>
              <td class="pf-aide">Contrepartie automatique ({{ journal?.Code }})</td>
              <td class="text-right num">{{ ligneContrepartie.Debit ? formatMontant(ligneContrepartie.Debit) : '' }}</td>
              <td class="text-right num">{{ ligneContrepartie.Credit ? formatMontant(ligneContrepartie.Credit) : '' }}</td>
              <td></td>
            </tr>
          </tbody>
        </table>

        <div v-if="!lectureSeule" class="pf-lignes__pied">
          <UiButton variant="ghost" icon="bi-plus-lg" @click="ajouterLigne()">Ajouter une ligne</UiButton>
          <label v-if="compteJournal" class="pf-switch">
            <input v-model="contrepartieAuto" type="checkbox" />
            <span>Contrepartie automatique sur <strong>{{ libelleCompte(compteJournal) }}</strong></span>
          </label>
          <span class="pf-raccourcis"><kbd>Entrée</kbd> ligne suivante · <kbd>Ctrl</kbd>+<kbd>S</kbd> brouillon · <kbd>Ctrl</kbd>+<kbd>Entrée</kbd> valider</span>
        </div>
      </section>

      <!-- Totaux et actions (toujours visibles) -->
      <div class="pf-barre" :class="{ 'is-ok': equilibre && totalDebit > 0 }">
        <div class="pf-totaux">
          <div><span class="fs-label">Total débit</span><strong class="num">{{ formatMontant(totalDebit) }}</strong></div>
          <div><span class="fs-label">Total crédit</span><strong class="num">{{ formatMontant(totalCredit) }}</strong></div>
          <div class="pf-ecart">
            <span class="fs-label">Écart</span>
            <strong class="num">{{ equilibre ? '0,00' : formatMontant(Math.abs(ecart)) }}</strong>
            <UiBadge v-if="totalDebit > 0" :tone="equilibre ? 'success' : 'danger'" size="sm" dot>{{ equilibre ? 'Équilibrée' : (ecart > 0 ? 'Débit > crédit' : 'Crédit > débit') }}</UiBadge>
          </div>
        </div>
        <div v-if="!lectureSeule" class="pf-actions">
          <UiButton v-if="piece" variant="soft-danger" icon="bi-trash3" :disabled="enregistrement" @click="supprimerPiece">Supprimer</UiButton>
          <UiButton type="submit" :loading="enregistrement === 'brouillon'" :disabled="!peutEnregistrer || !!enregistrement">Enregistrer en brouillon</UiButton>
          <UiButton
            variant="primary"
            icon="bi-check2-circle"
            :loading="enregistrement === 'valider'"
            :disabled="!peutValider || !!enregistrement"
            :title="peutValider ? '' : raisonNonValidable"
            @click="enregistrer('valider')"
          >
            Enregistrer et valider
          </UiButton>
        </div>
      </div>
      <p v-if="!lectureSeule && !peutValider && raisonNonValidable" class="pf-aide pf-raison">
        <i class="bi bi-info-circle"></i> {{ raisonNonValidable }}
      </p>
    </form>

    <!-- Contre-passation -->
    <UiModal v-model="modaleCp" title="Contre-passer la pièce" :subtitle="piece?.numero" icon="bi-arrow-left-right" tone="warning">
      <p class="pf-aide pf-cp-texte">
        Une pièce inverse (débits et crédits permutés) sera passée et validée dans le même journal.
        Saisissez ensuite l'écriture correcte.
      </p>
      <form id="pf-form-cp" class="ui-form ui-form--1" @submit.prevent="contrePasser">
        <div class="ui-field">
          <label for="pf-cp-date" class="required">Date de la contre-passation</label>
          <input id="pf-cp-date" v-model="dateCp" type="date" :min="String(piece?.Date_mouvement || '').slice(0, 10)" required />
        </div>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="modaleCp = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="pf-form-cp" icon="bi-arrow-left-right" :loading="enregistrement === 'contrepasser'">Contre-passer</UiButton>
      </template>
    </UiModal>

    <!-- Création rapide d'un tiers depuis une ligne -->
    <TiersFormModal v-model="modaleTiers.ouverte" :initial="modaleTiers.initial" @cree="tiersCree" />

    <!-- Enregistrer comme modèle -->
    <UiModal v-model="modaleModele" title="Enregistrer comme modèle" icon="bi-bookmark-plus" size="sm">
      <form id="pf-form-modele" class="ui-form ui-form--1" @submit.prevent="enregistrerModele">
        <div class="ui-field">
          <label for="pf-modele-nom" class="required">Nom du modèle</label>
          <input id="pf-modele-nom" v-model="nomModele" type="text" maxlength="150" placeholder="Ex. Paie du mois" required />
        </div>
        <label class="pf-switch">
          <input v-model="modeleAvecMontants" type="checkbox" />
          <span>Mémoriser aussi les montants</span>
        </label>
      </form>
      <template #footer>
        <UiButton variant="ghost" @click="modaleModele = false">Annuler</UiButton>
        <UiButton variant="primary" type="submit" form="pf-form-modele" :disabled="!nomModele.trim()">Enregistrer</UiButton>
      </template>
    </UiModal>
  </AppLayout>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { API_BASE } from '@/config/api';
import { useParametresStore } from '@/stores/parametres';
import { getUser } from '@/services/Auth';
import AppLayout from '@/components/template/AppLayout.vue';

const parametresStore = useParametresStore();
parametresStore.charger();
const tailleMaxJustificatif = computed(() => Number(parametresStore.get('saisie.justificatif_taille_max')) || 5);
import CompteSelect from '@/components/molecules/saisie/CompteSelect.vue';
import TiersFormModal from '@/components/molecules/tiers/TiersFormModal.vue';
import { chargerTousLesTiers, typePourCompte } from '@/composables/useTiers';

const route = useRoute();
const router = useRouter();

const user = ref(null);
const chargement = ref(true);
const enregistrement = ref(null); // 'brouillon' | 'valider' | null
const erreurs = ref([]);
const succes = ref('');

const journaux = ref([]);
const comptes = ref([]);
const modesPaiement = ref([]);
const modeles = ref([]);
const tiersListe = ref([]);
const modaleTiers = reactive({ ouverte: false, initial: {}, ligne: null });
const exercice = ref(null);         // exercice ouvert le plus récent (date par défaut)
const exercicesOuverts = ref([]);    // N et N+1 peuvent être ouverts ensemble

const piece = ref(null); // pièce existante (modification / consultation)
const form = reactive({ Id_Journal: '', Date_mouvement: '', Reference: '', Libelle: '', Id_Mode_paiement: '', a_extourner: false });
const modaleCp = ref(false);
const dateCp = ref('');
const lignes = ref([]);
const contrepartieAuto = ref(false);
const justificatif = reactive({ fichier: null, nomExistant: '', supprimer: false });

const menuModeles = ref(false);
const modaleModele = ref(false);
const nomModele = ref('');
const modeleAvecMontants = ref(true);

const refsCompte = {};
const refsMontant = {};
const champLibelle = ref(null);
let cleSuivante = 1;

// ---------- Calculs ----------
const lectureSeule = computed(() => !!piece.value && !piece.value.modifiable);
const journal = computed(() => journaux.value.find(j => j.Id_Journal == form.Id_Journal));
const compteJournal = computed(() => journal.value?.Id_Sous_compte || null);
const montantDe = l => Number(l.Debit || 0) + Number(l.Credit || 0);
const lignesSaisies = computed(() => lignes.value.filter(l => l.Id_Sous_compte || montantDe(l) > 0));

const sommeSaisie = computed(() => lignesSaisies.value.reduce(
  (s, l) => ({ d: s.d + Number(l.Debit || 0), c: s.c + Number(l.Credit || 0) }), { d: 0, c: 0 }));
const ecartSaisie = computed(() => arrondi(sommeSaisie.value.d - sommeSaisie.value.c));

const ligneContrepartie = computed(() => {
  if (!contrepartieAuto.value || !compteJournal.value || Math.abs(ecartSaisie.value) < 0.01) return null;
  return {
    Id_Sous_compte: compteJournal.value,
    Libelle: form.Libelle || '',
    Debit: ecartSaisie.value < 0 ? -ecartSaisie.value : 0,
    Credit: ecartSaisie.value > 0 ? ecartSaisie.value : 0,
  };
});

const totalDebit = computed(() => arrondi(sommeSaisie.value.d + (ligneContrepartie.value?.Debit || 0)));
const totalCredit = computed(() => arrondi(sommeSaisie.value.c + (ligneContrepartie.value?.Credit || 0)));
const ecart = computed(() => arrondi(totalDebit.value - totalCredit.value));
const equilibre = computed(() => Math.abs(ecart.value) < 0.01);

const ligneEnErreur = l => (montantDe(l) > 0 && !l.Id_Sous_compte) || (Number(l.Debit) > 0 && Number(l.Credit) > 0);

// ---------- Tiers (comptes de classe 4) ----------
const codeCompte = l => comptes.value.find(c => c.Id_Sous_compte == l.Id_Sous_compte)?.Code_sous_compte || '';
const accepteTiers = l => codeCompte(l).startsWith('4');
const prefixesTiers = computed(() => parametresStore.get('tiers.obligatoire')
  ? String(parametresStore.get('tiers.comptes') || '').split(',').map(s => s.trim()).filter(Boolean) : []);
const exigeTiers = l => prefixesTiers.value.some(p => codeCompte(l).startsWith(p));
const tiersManquant = l => exigeTiers(l) && !l.id_tiers && montantDe(l) > 0;
function tiersPour(l) {
  const type = typePourCompte(codeCompte(l));
  const liste = type ? tiersListe.value.filter(t => t.type === type) : tiersListe.value;
  // Tiers déjà choisi mais absent de la liste (désactivé, autre type) : gardé visible
  if (l.id_tiers && !liste.some(t => t.id == l.id_tiers)) {
    const t = tiersListe.value.find(x => x.id == l.id_tiers) || l.tiers;
    if (t) return [t, ...liste];
  }
  return liste;
}
function choisirTiers(l, champ) {
  const valeur = champ.value;
  if (valeur === '__nouveau') {
    champ.value = l.id_tiers ?? '';
    modaleTiers.ligne = l;
    modaleTiers.initial = { type: typePourCompte(codeCompte(l)) || 'client', Id_Sous_compte: l.Id_Sous_compte };
    modaleTiers.ouverte = true;
    return;
  }
  l.id_tiers = valeur ? Number(valeur) : null;
}
function tiersCree(t) {
  tiersListe.value = [...tiersListe.value, t].sort((a, b) => a.nom.localeCompare(b.nom));
  if (modaleTiers.ligne) modaleTiers.ligne.id_tiers = t.id;
  succes.value = `Tiers ${t.code} — ${t.nom} créé.`;
}

const peutEnregistrer = computed(() =>
  !!form.Id_Journal && !!form.Date_mouvement && lignesSaisies.value.length > 0
  && lignesSaisies.value.every(l => l.Id_Sous_compte && !(Number(l.Debit) > 0 && Number(l.Credit) > 0)));

const raisonNonValidable = computed(() => {
  if (!form.Id_Journal || !form.Date_mouvement) return 'Choisissez un journal et une date.';
  const n = lignesSaisies.value.length + (ligneContrepartie.value ? 1 : 0);
  if (n < 2) return 'Une pièce doit comporter au moins deux lignes.';
  if (lignesSaisies.value.some(l => !l.Id_Sous_compte)) return 'Chaque ligne doit avoir un compte.';
  if (lignesSaisies.value.some(l => montantDe(l) <= 0)) return 'Chaque ligne doit avoir un montant au débit ou au crédit.';
  if (!equilibre.value) return `La pièce n'est pas équilibrée (écart de ${formatMontant(Math.abs(ecart.value))}).`;
  const sansTiers = lignesSaisies.value.findIndex(tiersManquant);
  if (sansTiers >= 0) return `Ligne ${sansTiers + 1} : indiquez le client ou le fournisseur du compte ${codeCompte(lignesSaisies.value[sansTiers])}.`;
  if (!String(form.Reference || '').trim()) return 'Indiquez la référence de la pièce justificative (n° de facture, reçu, relevé…).';
  if (exercicesOuverts.value.length && !exercicesOuverts.value.some(e => form.Date_mouvement >= e.saisieDes && form.Date_mouvement <= e.fin)) {
    return `La date doit tomber dans une période ouverte : ${aideExercices.value}.`;
  }
  return '';
});

// Dates saisissables : exercices ouverts, après le dernier mois clôturé
const bornesDates = computed(() => {
  if (!exercicesOuverts.value.length) return { min: undefined, max: undefined };
  return {
    min: exercicesOuverts.value.map(e => e.saisieDes).sort()[0],
    max: exercicesOuverts.value.map(e => e.fin).sort().at(-1),
  };
});
const aideExercices = computed(() => exercicesOuverts.value
  .map(e => `${e.annee} à partir du ${formatDate(e.saisieDes)}`).join(' · '));

const TYPES = { a_nouveau: 'À-nouveaux', cloture: 'Écriture de clôture', dotation: 'Dotations', extourne: 'Contre-passation', extourne_auto: 'Extourne automatique', affectation: 'Affectation du résultat' };
const typeLibelle = computed(() => TYPES[piece.value?.type_piece] || (piece.value?.a_extourner ? 'Régularisation' : ''));
const peutValider = computed(() => peutEnregistrer.value && !raisonNonValidable.value);

const titre = computed(() => {
  if (!piece.value) return 'Nouvelle pièce';
  return `Pièce ${piece.value.numero}`;
});
const sousTitre = computed(() => {
  if (!piece.value) return 'Saisissez l\'en-tête puis les lignes ; la pièce doit être équilibrée pour être validée';
  const auteur = piece.value.saisi_par ? ` — saisie par ${piece.value.saisi_par}` : '';
  return (piece.value.statut === 'valide' ? 'Pièce validée' : 'Brouillon') + auteur;
});

// ---------- Utilitaires ----------
function arrondi(n) { return Math.round(n * 100) / 100; }
function formatMontant(n) { return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function formatDate(d) { return d ? new Date(d).toLocaleDateString('fr-FR') : ''; }
function libelleCompte(id) {
  const c = comptes.value.find(x => x.Id_Sous_compte == id);
  return c ? `${c.Code_sous_compte} — ${c.Libelle}` : '';
}
function nouvelleLigne(donnees = {}) {
  return { cle: cleSuivante++, Id_Sous_compte: null, id_tiers: null, tiers: null, lettrage: null, Libelle: '', Debit: null, Credit: null, ...donnees };
}
function messagesErreur(e) {
  const d = e?.response?.data;
  if (d?.errors) return Object.values(d.errors).flat();
  return [d?.message || d?.error || 'Une erreur est survenue.'];
}

// ---------- Lignes ----------
function ajouterLigne(focus = true) {
  // Propose le montant restant pour équilibrer (sauf contrepartie automatique)
  const reste = contrepartieAuto.value && compteJournal.value ? 0 : ecartSaisie.value;
  lignes.value.push(nouvelleLigne({
    Debit: reste < 0 ? -reste : null,
    Credit: reste > 0 ? reste : null,
  }));
  if (focus) {
    const cle = lignes.value[lignes.value.length - 1].cle;
    nextTick(() => refsCompte[cle]?.focus());
  }
}
function supprimerLigne(i) { lignes.value.splice(i, 1); }
function focusChamp(cle, sens) {
  nextTick(() => {
    const ligne = lignes.value.find(l => l.cle === cle);
    const cible = sens === 'debit' && ligne && Number(ligne.Credit) > 0 ? 'credit' : sens;
    refsMontant[`${cle}-${cible}`]?.focus();
    refsMontant[`${cle}-${cible}`]?.select?.();
  });
}
function apresMontant(i) {
  if (i === lignes.value.length - 1) {
    if (!equilibre.value || !lignesSaisies.value.length) ajouterLigne();
  } else {
    nextTick(() => refsCompte[lignes.value[i + 1].cle]?.focus());
  }
}

// ---------- Chargement ----------
async function chargerReferentiels() {
  const [j, c, m, e, mod, t] = await Promise.all([
    axios.get(`${API_BASE}/journals`),
    axios.get(`${API_BASE}/sous-comptes`),
    axios.get(`${API_BASE}/mode-paiements`).catch(() => ({ data: [] })),
    axios.get(`${API_BASE}/exercices`).catch(() => ({ data: [] })),
    axios.get(`${API_BASE}/modeles-ecriture`).catch(() => ({ data: [] })),
    chargerTousLesTiers().catch(() => []),
  ]);
  tiersListe.value = t;
  journaux.value = j.data.data || j.data;
  comptes.value = (c.data.data || c.data).slice().sort((a, b) => a.Code_sous_compte.localeCompare(b.Code_sous_compte));
  modesPaiement.value = m.data.data || m.data || [];
  exercicesOuverts.value = (e.data || [])
    .filter(x => x.Statut === 'OUVERT')
    .map(x => {
      const debut = x.Date_debut.slice(0, 10);
      // Lendemain du dernier mois clôturé
      const verrou = x.periode_cloturee_au ? String(x.periode_cloturee_au).slice(0, 10) : null;
      const lendemain = verrou ? new Date(new Date(`${verrou}T00:00:00Z`).getTime() + 86400000).toISOString().slice(0, 10) : debut;
      return { annee: x.Annee_fiscale, debut, fin: x.Date_fin.slice(0, 10), saisieDes: lendemain > debut ? lendemain : debut };
    })
    .sort((a, b) => a.annee - b.annee);
  const dernier = exercicesOuverts.value.at(-1);
  exercice.value = dernier ? { annee: dernier.annee, debut: dernier.saisieDes, fin: dernier.fin } : null;
  modeles.value = mod.data || [];
}

function dateParDefaut() {
  const aujourdhui = new Date().toISOString().slice(0, 10);
  if (!exercice.value) return aujourdhui;
  if (aujourdhui < exercice.value.debut) return exercice.value.debut;
  if (aujourdhui > exercice.value.fin) return exercice.value.fin;
  return aujourdhui;
}

function remplirDepuisPiece(p, { copie = false } = {}) {
  form.Id_Journal = p.Id_Journal;
  form.Date_mouvement = copie ? dateParDefaut() : String(p.Date_mouvement).slice(0, 10);
  form.Reference = copie ? '' : (p.Reference || '');
  form.Libelle = p.Libelle || '';
  form.a_extourner = copie ? false : !!p.a_extourner;
  form.Id_Mode_paiement = p.lignes.find(l => l.Id_Mode_paiement)?.Id_Mode_paiement || '';
  lignes.value = p.lignes.map(l => nouvelleLigne({
    Id_Sous_compte: l.Id_Sous_compte,
    id_tiers: l.id_tiers || null,
    tiers: l.tiers || null,
    lettrage: copie ? null : (l.lettrage || null),
    Libelle: l.Libelle === p.Libelle ? '' : l.Libelle,
    Debit: l.Debit || null,
    Credit: l.Credit || null,
  }));
  // Les lignes existantes contiennent déjà la contrepartie
  contrepartieAuto.value = false;
  justificatif.fichier = null;
  justificatif.supprimer = false;
  justificatif.nomExistant = copie ? '' : (p.justificatif_nom || '');
}

function reinitialiser() {
  piece.value = null;
  Object.assign(form, { Id_Journal: journaux.value.length === 1 ? journaux.value[0].Id_Journal : '', Date_mouvement: dateParDefaut(), Reference: '', Libelle: '', Id_Mode_paiement: '', a_extourner: false });
  lignes.value = [nouvelleLigne(), nouvelleLigne()];
  contrepartieAuto.value = !!compteJournal.value;
  Object.assign(justificatif, { fichier: null, nomExistant: '', supprimer: false });
}

async function chargerPage() {
  erreurs.value = [];
  chargement.value = true;
  try {
    if (!comptes.value.length) await chargerReferentiels();
    const id = route.params.id;
    const depuis = route.query.depuis;
    if (id) {
      const { data } = await axios.get(`${API_BASE}/pieces/${id}`);
      piece.value = data;
      remplirDepuisPiece(data);
    } else if (depuis) {
      const { data } = await axios.get(`${API_BASE}/pieces/${depuis}`);
      piece.value = null;
      remplirDepuisPiece(data, { copie: true });
      succes.value = `Copie de la pièce ${data.numero} : vérifiez la date et les montants avant d'enregistrer.`;
    } else {
      reinitialiser();
    }
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    chargement.value = false;
  }
  if (!piece.value) nextTick(() => champLibelle.value?.focus());
}

// La contrepartie automatique s'active par défaut pour un journal de trésorerie (nouvelle pièce)
watch(() => form.Id_Journal, () => { if (!piece.value && !route.query.depuis) contrepartieAuto.value = !!compteJournal.value; });
watch(() => [route.params.id, route.query.depuis], () => { succes.value = ''; chargerPage(); });

// ---------- Enregistrement ----------
async function enregistrer(action) {
  if (lectureSeule.value || enregistrement.value) return;
  if (action === 'valider' ? !peutValider.value : !peutEnregistrer.value) return;
  erreurs.value = [];
  succes.value = '';
  enregistrement.value = action;

  const lignesEnvoyees = [...lignesSaisies.value, ...(ligneContrepartie.value ? [ligneContrepartie.value] : [])]
    .map(l => ({
      Id_Sous_compte: l.Id_Sous_compte,
      id_tiers: accepteTiers(l) ? (l.id_tiers || null) : null,
      Libelle: l.Libelle || null,
      Debit: Number(l.Debit || 0),
      Credit: Number(l.Credit || 0),
      Id_Mode_paiement: form.Id_Mode_paiement || null,
    }));
  const entete = {
    Id_Journal: form.Id_Journal,
    Date_mouvement: form.Date_mouvement,
    Reference: form.Reference || null,
    Libelle: form.Libelle || null,
    a_extourner: !!form.a_extourner,
    action,
  };

  try {
    let reponse;
    const avecFichier = !!justificatif.fichier;
    if (avecFichier || justificatif.supprimer) {
      const fd = new FormData();
      Object.entries(entete).forEach(([k, v]) => v !== null && fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : v));
      fd.append('lignes', JSON.stringify(lignesEnvoyees));
      if (avecFichier) fd.append('justificatif', justificatif.fichier);
      if (justificatif.supprimer) fd.append('supprimer_justificatif', '1');
      if (piece.value) fd.append('_method', 'PUT');
      reponse = await axios.post(piece.value ? `${API_BASE}/pieces/${piece.value.id}` : `${API_BASE}/pieces`, fd);
    } else {
      const corps = { ...entete, lignes: lignesEnvoyees };
      reponse = piece.value
        ? await axios.put(`${API_BASE}/pieces/${piece.value.id}`, corps)
        : await axios.post(`${API_BASE}/pieces`, corps);
    }
    const enregistree = reponse.data.piece;
    const message = reponse.data.message;
    if (!piece.value || piece.value.id !== enregistree.id) {
      await router.replace(`/ecriture/${enregistree.id}`);
    } else {
      piece.value = enregistree;
      remplirDepuisPiece(enregistree);
    }
    succes.value = message;
  } catch (e) {
    erreurs.value = messagesErreur(e);
  } finally {
    enregistrement.value = null;
  }
}

async function supprimerPiece() {
  if (!piece.value || !confirm(`Supprimer définitivement la pièce ${piece.value.numero} ?`)) return;
  try {
    await axios.delete(`${API_BASE}/pieces/${piece.value.id}`);
    router.push('/pieces');
  } catch (e) {
    erreurs.value = messagesErreur(e);
  }
}

// ---------- Contre-passation ----------
function ouvrirContrePassation() {
  const origine = String(piece.value.Date_mouvement).slice(0, 10);
  const defaut = dateParDefaut();
  dateCp.value = defaut < origine ? origine : defaut;
  modaleCp.value = true;
}
async function contrePasser() {
  enregistrement.value = 'contrepasser';
  erreurs.value = [];
  try {
    const { data } = await axios.post(`${API_BASE}/pieces/${piece.value.id}/contrepasser`, { Date_mouvement: dateCp.value });
    modaleCp.value = false;
    await router.push(`/ecriture/${data.piece.id}`);
    await nextTick(); // après le rechargement déclenché par le changement d'URL
    succes.value = data.message;
  } catch (e) {
    modaleCp.value = false;
    erreurs.value = messagesErreur(e);
  } finally {
    enregistrement.value = null;
  }
}

// ---------- Justificatif ----------
function choisirFichier(e) {
  const f = e.target.files?.[0];
  if (!f) return;
  if (f.size > tailleMaxJustificatif.value * 1024 * 1024) {
    erreurs.value = [`Le justificatif ne doit pas dépasser ${tailleMaxJustificatif.value} Mo.`];
    e.target.value = '';
    return;
  }
  justificatif.fichier = f;
  justificatif.supprimer = false;
}
async function telechargerJustificatif() {
  try {
    const { data } = await axios.get(`${API_BASE}/pieces/${piece.value.id}/justificatif`, { responseType: 'blob' });
    const url = URL.createObjectURL(data);
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  } catch (e) {
    erreurs.value = ['Justificatif introuvable.'];
  }
}

// ---------- Modèles ----------
function appliquerModele(m) {
  menuModeles.value = false;
  if (m.Id_Journal) form.Id_Journal = m.Id_Journal;
  if (m.libelle) form.Libelle = m.libelle;
  lignes.value = m.lignes.map(l => nouvelleLigne({
    Id_Sous_compte: l.Id_Sous_compte,
    Libelle: l.Libelle || '',
    Debit: l.sens === 'debit' ? (l.montant ?? null) : null,
    Credit: l.sens === 'credit' ? (l.montant ?? null) : null,
  }));
  nextTick(() => { contrepartieAuto.value = false; });
  succes.value = `Modèle « ${m.nom} » appliqué : complétez les montants puis enregistrez.`;
}
function ouvrirEnregistrementModele() {
  menuModeles.value = false;
  nomModele.value = form.Libelle || '';
  modaleModele.value = true;
}
async function enregistrerModele() {
  const source = [...lignesSaisies.value, ...(ligneContrepartie.value ? [ligneContrepartie.value] : [])];
  try {
    const { data } = await axios.post(`${API_BASE}/modeles-ecriture`, {
      nom: nomModele.value.trim(),
      Id_Journal: form.Id_Journal || null,
      libelle: form.Libelle || null,
      lignes: source.map(l => ({
        Id_Sous_compte: l.Id_Sous_compte,
        Libelle: l.Libelle || null,
        sens: Number(l.Credit) > 0 ? 'credit' : 'debit',
        montant: modeleAvecMontants.value ? (Number(l.Debit) || Number(l.Credit) || null) : null,
      })),
    });
    modeles.value = [...modeles.value, data.modele].sort((a, b) => a.nom.localeCompare(b.nom));
    modaleModele.value = false;
    succes.value = data.message;
  } catch (e) {
    erreurs.value = messagesErreur(e);
    modaleModele.value = false;
  }
}
async function supprimerModele(m) {
  if (!confirm(`Supprimer le modèle « ${m.nom} » ?`)) return;
  try {
    await axios.delete(`${API_BASE}/modeles-ecriture/${m.id}`);
    modeles.value = modeles.value.filter(x => x.id !== m.id);
  } catch (e) {
    erreurs.value = messagesErreur(e);
  }
}

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  document.addEventListener('click', fermerMenu);
  await chargerPage();
});
function fermerMenu(e) {
  if (!e.target.closest?.('.pf-menu')) menuModeles.value = false;
}
</script>

<style scoped>
.pf { display: flex; flex-direction: column; gap: 16px; }
.pf-msg { margin: 0; }
.pf-msg + .pf-msg { margin-top: 4px; }
.pf-label { font-size: 13px; font-weight: 500; color: var(--fs-text-2); }
.pf-req { color: var(--fs-negative); }
.pf-aide { font-size: 12px; color: var(--fs-muted); }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

.pf-lecture {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 12px 16px; border-radius: var(--fs-radius-sm);
  background: var(--fs-success-soft); color: var(--fs-text-2); font-size: 14px;
}

/* En-tête */
.pf-entete {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px 20px;
  padding: 20px;
}
.pf-entete__libelle { grid-column: span 2; }
.pf-entete__extourne { grid-column: 1 / -1; align-items: flex-start; }
.pf-entete__extourne.is-lecture { cursor: default; }
.pf-entete__extourne small { display: block; margin-top: 2px; }
.pf-lecture__texte { flex: 1; min-width: 240px; }
.pf-lecture a { color: var(--fs-primary-text); font-weight: 600; }
.pf-cp-texte { margin: 0 0 16px; font-size: 13.5px; line-height: 1.5; }
.pf-justif { display: flex; align-items: center; gap: 8px; min-height: 40px; flex-wrap: wrap; }
.pf-upload {
  display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 14px;
  border: 1px dashed var(--fs-border-strong); border-radius: var(--fs-radius-sm);
  color: var(--fs-muted); font-size: 13.5px; cursor: pointer;
}
.pf-upload:hover { border-color: var(--fs-primary); color: var(--fs-primary-text); }
.pf-upload input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.pf-upload:focus-within { outline: 2px solid var(--fs-primary); outline-offset: 2px; }

/* Lignes */
.pf-lignes { overflow: visible; }
.pf-table tbody td { padding: 8px 10px; }
.pf-table thead th { padding: 10px; }
.pf-col-n { width: 40px; text-align: center; color: var(--fs-muted); font-size: 12.5px; }
.pf-col-compte { width: 28%; }
.pf-col-tiers { width: 18%; }
.pf-input.is-invalide { border-color: var(--fs-negative); }
.pf-tiers-lien { margin-right: 6px; color: var(--fs-primary-text); font-size: 13.5px; font-weight: 500; text-decoration: none; }
.pf-tiers-lien:hover { text-decoration: underline; }
.pf-col-montant { width: 15%; }
.pf-col-action { width: 48px; text-align: center; }
.pf-input { height: 36px; font-size: 13.5px; padding: 0 10px; }
.pf-montant { text-align: right; font-variant-numeric: tabular-nums; }
.pf-montant::-webkit-inner-spin-button, .pf-montant::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
.pf-montant { -moz-appearance: textfield; }
.pf-ligne--erreur td { background: var(--fs-negative-soft); }
.pf-contrepartie td { background: var(--fs-row-subtitle); color: var(--fs-text-2); font-style: normal; }
.pf-contrepartie .bi { color: var(--fs-primary-text); }
.pf-cp-compte { font-size: 13.5px; font-weight: 500; }
.pf-lignes__pied {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 10px 12px; border-top: 1px solid var(--fs-border);
}
.pf-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--fs-text-2); cursor: pointer; }
.pf-switch input { width: 16px; height: 16px; accent-color: var(--fs-primary); }
.pf-raccourcis { margin-left: auto; font-size: 12px; color: var(--fs-muted); }
kbd {
  display: inline-block; padding: 1px 5px; border: 1px solid var(--fs-border-strong); border-bottom-width: 2px;
  border-radius: 4px; font: 600 11px var(--fs-font); background: var(--fs-surface); color: var(--fs-text-2);
}

/* Barre des totaux */
.pf-barre {
  position: sticky;
  bottom: 16px;
  z-index: 20;
  display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
  padding: 14px 20px;
  background: var(--fs-surface);
  border: 1px solid var(--fs-border);
  border-left: 4px solid var(--fs-negative);
  border-radius: var(--fs-radius);
  box-shadow: var(--fs-shadow-lg);
}
.pf-barre.is-ok { border-left-color: var(--fs-success); }
/* Laisse la place au bouton flottant de l'assistant (en bas à droite) */
@media (min-width: 769px) { .pf-barre { margin-right: 64px; } }
.pf-totaux { display: flex; gap: 32px; flex-wrap: wrap; }
.pf-totaux > div { display: flex; flex-direction: column; gap: 4px; }
.pf-totaux strong { font-size: 18px; color: var(--fs-text); }
.pf-ecart { flex-direction: row !important; align-items: flex-end; gap: 10px !important; }
.pf-ecart > .fs-label { align-self: flex-start; }
.pf-ecart { display: grid !important; grid-template-columns: auto auto; grid-template-rows: auto auto; column-gap: 10px; }
.pf-ecart > .fs-label { grid-column: 1 / -1; }
.num { font-variant-numeric: tabular-nums; }
.pf-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.pf-raison { margin: -6px 4px 0; display: flex; gap: 6px; align-items: center; }

/* Menu des modèles */
.pf-menu { position: relative; }
.pf-menu__liste {
  position: absolute; right: 0; top: calc(100% + 6px); z-index: 40;
  width: 320px; padding: 6px;
  background: var(--fs-surface); border: 1px solid var(--fs-border); border-radius: 12px; box-shadow: var(--fs-shadow-lg);
}
.pf-menu__liste hr { border: 0; border-top: 1px solid var(--fs-border); margin: 6px 0; }
.pf-menu__vide { margin: 0; padding: 10px; font-size: 13px; color: var(--fs-muted); }
.pf-menu__item { display: flex; align-items: center; gap: 4px; }
.pf-menu__choix {
  flex: 1; display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
  padding: 8px 10px; border: 0; border-radius: 8px; background: transparent; cursor: pointer;
  font: inherit; text-align: left; color: var(--fs-text);
}
.pf-menu__choix:hover:not(:disabled) { background: var(--fs-surface-2); }
.pf-menu__choix:disabled { opacity: 0.5; cursor: not-allowed; }
.pf-menu__choix strong { font-size: 13.5px; font-weight: 600; }
.pf-menu__choix span { font-size: 12px; color: var(--fs-muted); }
.pf-sk { display: flex; flex-direction: column; gap: 20px; }

@media (max-width: 1100px) {
  .pf-entete { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .pf-entete__libelle { grid-column: span 2; }
}
@media (max-width: 700px) {
  .pf-entete { grid-template-columns: 1fr; }
  .pf-entete__libelle { grid-column: auto; }
  .pf-raccourcis { display: none; }
}
</style>
