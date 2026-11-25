<template>
  <AppLayout
    :user="user"
    icon="bi-lock"
    title="Clôtures"
    subtitle="Clôture mensuelle, travaux d'inventaire, clôture de l'exercice et affectation du résultat"
    section="Saisie"
  >
    <template #actions>
      <select v-model="exerciceId" class="fs-select ce-exercice" aria-label="Exercice">
        <option v-for="e in exercices" :key="e.Id_Exercice_comptable" :value="e.Id_Exercice_comptable">
          Exercice {{ e.Annee_fiscale }}{{ e.Statut === 'OUVERT' ? ' (ouvert)' : (e.cloture_le ? ' (clôturé)' : '') }}
        </option>
      </select>
      <UiButton icon="bi-arrow-clockwise" :loading="chargement" @click="charger">Actualiser</UiButton>
    </template>

    <div v-if="message" class="ce-alerte" :class="`ce-alerte--${message.ton}`" role="status">
      <i :class="['bi', message.ton === 'succes' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill']" aria-hidden="true"></i>
      <span>{{ message.texte }}</span>
      <UiButton variant="ghost" size="sm" icon="bi-x-lg" icon-only aria-label="Fermer le message" @click="message = null" />
    </div>

    <div v-if="chargement && !prep" class="fs-card fs-skeleton"><div v-for="n in 6" :key="n" class="fs-skeleton__row"></div></div>

    <template v-else-if="prep">
      <!-- Synthèse -->
      <section class="ce-synthese">
        <div class="fs-card ce-tuile">
          <span class="ce-tuile__label">Exercice {{ ex.annee }}</span>
          <span class="ce-tuile__valeur ce-tuile__valeur--petit">{{ formatDate(ex.date_debut) }} → {{ formatDate(ex.date_fin) }}</span>
          <span>
            <UiBadge v-if="ex.cloture_definitive" tone="neutral" dot>Clôturé le {{ formatDate(ex.cloture_le) }}</UiBadge>
            <UiBadge v-else-if="ex.statut === 'OUVERT'" tone="success" dot>Ouvert</UiBadge>
            <UiBadge v-else tone="warning" dot>Fermé (non clôturé)</UiBadge>
          </span>
        </div>
        <div class="fs-card ce-tuile">
          <span class="ce-tuile__label">Résultat net de l'exercice</span>
          <span class="ce-tuile__valeur" :class="resultat >= 0 ? 'is-positif' : 'is-negatif'">{{ formatMontant(resultat) }} Ar</span>
          <span class="ce-tuile__aide">{{ resultat >= 0 ? 'Bénéfice → compte 120' : 'Perte → compte 129' }}</span>
        </div>
        <div class="fs-card ce-tuile">
          <span class="ce-tuile__label">Exercice suivant</span>
          <span class="ce-tuile__valeur ce-tuile__valeur--petit">{{ suivant.annee }}</span>
          <span class="ce-tuile__aide">
            <template v-if="suivant.a_creer">Pas encore créé</template>
            <template v-else-if="suivant.statut === 'OUVERT'">Ouvert{{ suivant.ouvert_par_a_nouveaux ? ' sur les à-nouveaux' : ' (saisie courante)' }}</template>
            <template v-else>Fermé</template>
          </span>
          <UiButton
            v-if="ex.statut === 'OUVERT' && suivant.statut !== 'OUVERT'"
            size="sm" variant="soft-primary" icon="bi-calendar-plus" :loading="action === 'suivant'" @click="ouvrirSuivant"
          >Ouvrir {{ suivant.annee }} pour la saisie courante</UiButton>
        </div>
      </section>

      <!-- Clôture mensuelle -->
      <section v-if="periodes && !ex.cloture_definitive" class="fs-card ce-section">
        <div class="ce-section__entete">
          <h2 class="ce-section__titre"><i class="bi bi-calendar3" aria-hidden="true"></i> Clôture mensuelle</h2>
          <span v-if="periodes.periode_cloturee_au" class="ce-aide ce-aide--ligne">Écritures figées jusqu'au {{ formatDate(periodes.periode_cloturee_au) }}</span>
        </div>
        <p class="ce-aide">
          Chaque mois clôturé fige définitivement la chronologie : plus aucune écriture ne peut y être datée.
          Le dernier mois reste ouvert pour les travaux d'inventaire et se verrouille avec la clôture de l'exercice.
        </p>
        <p v-if="periodes.motif_blocage" class="ce-bloque ce-bloque--bloc"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ periodes.motif_blocage }}</p>
        <ul class="ce-mois">
          <li v-for="m in periodes.mois" :key="m.mois" :class="`is-${m.statut}`">
            <span class="ce-mois__nom">{{ m.libelle }}</span>
            <span class="ce-mois__info">{{ m.pieces }} pièce(s)<template v-if="m.brouillons"> · <strong>{{ m.brouillons }} brouillon(s)</strong></template></span>
            <UiBadge v-if="m.statut === 'cloture'" tone="neutral" icon="bi-lock-fill">Clôturé</UiBadge>
            <UiBadge v-else-if="m.statut === 'inventaire'" tone="info">Inventaire</UiBadge>
            <UiButton
              v-else-if="m.prochain && !periodes.motif_blocage"
              size="sm" variant="soft-primary" icon="bi-lock" :loading="action === `mois-${m.mois}`" :disabled="m.brouillons > 0"
              :title="m.brouillons ? 'Validez ou supprimez d\'abord les brouillons du mois' : ''"
              @click="cloturerMois(m)"
            >Clôturer</UiButton>
            <UiBadge v-else tone="success" dot>Ouvert</UiBadge>
          </li>
        </ul>
      </section>

      <!-- Exercice clôturé -->
      <template v-if="ex.cloture_definitive">
        <!-- Affectation du résultat -->
        <section v-if="affectation" class="fs-card ce-section">
          <div class="ce-section__entete">
            <h2 class="ce-section__titre"><i class="bi bi-diagram-2" aria-hidden="true"></i> Affectation du résultat {{ ex.annee }}</h2>
            <UiBadge v-if="affectation.piece?.validee" tone="success" dot>Affecté — {{ affectation.piece.numero }}</UiBadge>
            <UiBadge v-else-if="affectation.piece" tone="warning" dot>Brouillon {{ affectation.piece.numero }}</UiBadge>
            <UiBadge v-else-if="!affectation.motif_blocage" tone="warning" dot>À affecter</UiBadge>
          </div>

          <p v-if="affectation.motif_blocage" class="ce-aide">{{ affectation.motif_blocage }}</p>
          <template v-else-if="affectation.piece?.validee">
            <p class="ce-texte">
              Le résultat de {{ formatMontant(Math.abs(affectation.resultat)) }} Ar a été affecté selon la décision de l'assemblée.
              Pour corriger, contre-passez la pièce puis saisissez une nouvelle affectation.
            </p>
            <div class="ce-liens"><UiButton :to="`/ecriture/${affectation.piece.id}`" icon="bi-file-earmark-text">Voir la pièce d'affectation</UiButton></div>
          </template>
          <form v-else class="ce-affectation" @submit.prevent="affecter(true)">
            <p class="ce-aide">
              Répartissez le {{ affectation.resultat >= 0 ? 'bénéfice' : 'déficit' }} de
              <strong>{{ formatMontant(Math.abs(affectation.resultat)) }} Ar</strong> selon la décision de l'assemblée
              (passée dans {{ affectation.exercice_suivant?.annee }}).
              <template v-if="affectation.resultat >= 0">Sociétés : pensez à la dotation de la réserve légale.</template>
            </p>
            <div class="ce-affectation__entete">
              <div class="fs-field">
                <label class="fs-label" for="aff-date">Date de la décision</label>
                <input id="aff-date" v-model="formAff.date" type="date" class="fs-input" required
                  :min="affectation.exercice_suivant?.date_debut" :max="affectation.exercice_suivant?.date_fin" />
              </div>
              <div class="fs-field">
                <label class="fs-label" for="aff-ref">Référence (PV d'assemblée) <span class="ce-req">*</span></label>
                <input id="aff-ref" v-model="formAff.reference" type="text" maxlength="100" class="fs-input" placeholder="PV de l'AG du …" required />
              </div>
            </div>
            <table class="fs-table">
              <thead><tr><th>Destination</th><th class="text-right ce-col-montant">Montant</th></tr></thead>
              <tbody>
                <tr v-for="d in affectation.destinations" :key="d.code">
                  <td>{{ d.code }} — {{ d.libelle }}</td>
                  <td class="text-right">
                    <input v-model.number="formAff.montants[d.code]" type="number" min="0" step="0.01" class="fs-input ce-montant" :aria-label="`Montant affecté à ${d.libelle}`" />
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total réparti <span v-if="resteAff" class="ce-ecart">(reste {{ formatMontant(resteAff) }})</span></td>
                  <td class="text-right num ce-fort">{{ formatMontant(totalAff) }}</td>
                </tr>
              </tfoot>
            </table>
            <div class="ce-pied">
              <UiButton :disabled="!!resteAff" :loading="action === 'affecter-brouillon'" @click="affecter(false)">Enregistrer en brouillon</UiButton>
              <UiButton variant="primary" type="submit" icon="bi-check2-circle" :disabled="!!resteAff" :loading="action === 'affecter'">Passer et valider l'affectation</UiButton>
            </div>
          </form>
        </section>

        <section class="fs-card ce-section">
          <h2 class="ce-section__titre"><i class="bi bi-lock-fill" aria-hidden="true"></i> Exercice clôturé</h2>
          <p class="ce-texte">
            Les comptes de gestion ont été soldés au {{ formatDate(ex.date_fin) }} et le résultat porté au compte 12.
            Les soldes des comptes de bilan ont été repris au {{ formatDate(prep.apercu.date_a_nouveaux) }} dans l'exercice {{ suivant.annee }}.
            Le compte de résultat, le bilan et les livres de {{ ex.annee }} restent consultables tels qu'avant la clôture.
          </p>
          <div class="ce-liens">
            <UiButton v-if="ex.id_piece_cloture" :to="`/ecriture/${ex.id_piece_cloture}`" icon="bi-file-earmark-text">Écriture de clôture</UiButton>
            <UiButton v-if="ex.id_piece_an" :to="`/ecriture/${ex.id_piece_an}`" icon="bi-file-earmark-arrow-down">Pièce d'à-nouveaux</UiButton>
          </div>
          <div class="ce-pied">
            <p class="ce-aide">
              L'annulation supprime l'écriture de clôture, les à-nouveaux et les extournes automatiques, puis rouvre {{ ex.annee }}.
              Les écritures déjà saisies dans {{ suivant.annee }} sont conservées. Impossible une fois un mois de {{ suivant.annee }} clôturé
              ou le résultat affecté.
            </p>
            <UiButton v-if="estAdmin" variant="soft-danger" icon="bi-unlock" :loading="action === 'annuler'" @click="ouvrirAnnulation">Annuler la clôture</UiButton>
            <span v-else class="ce-bloque"><i class="bi bi-shield-lock" aria-hidden="true"></i> Clôture définitive : seul un administrateur peut l'annuler.</span>
          </div>
        </section>
      </template>

      <template v-else-if="ex.statut === 'OUVERT'">
        <!-- Étape 1 : dotations -->
        <section class="fs-card ce-section">
          <div class="ce-section__entete">
            <h2 class="ce-section__titre"><span class="ce-etape">1</span> Dotations aux amortissements</h2>
            <UiBadge v-if="!dotations?.total" tone="neutral">Aucune dotation</UiBadge>
            <UiBadge v-else-if="dotations.piece?.validee" tone="success" dot>Passées — {{ dotations.piece.numero }}</UiBadge>
            <UiBadge v-else-if="dotations.piece" tone="warning" dot>Brouillon {{ dotations.piece.numero }}</UiBadge>
            <UiBadge v-else tone="warning" dot>À passer</UiBadge>
          </div>
          <p class="ce-aide">Amortissement linéaire au prorata temporis (jours d'utilisation) : débit 681 / crédit 28x, au {{ formatDate(ex.date_fin) }} dans le journal OD.</p>

          <div v-if="dotations?.immobilisations?.length" class="fs-table-wrap">
            <table class="fs-table">
              <thead>
                <tr>
                  <th>Immobilisation</th>
                  <th>Compte</th>
                  <th class="text-right">Valeur brute</th>
                  <th class="text-right">Taux</th>
                  <th>Début</th>
                  <th class="text-right">Cumul antérieur</th>
                  <th class="text-right">Dotation {{ ex.annee }}</th>
                  <th class="text-right">VNC fin</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="i in dotations.immobilisations" :key="i.id">
                  <td>{{ i.libelle }}</td>
                  <td class="ce-compte">{{ i.compte }}</td>
                  <td class="text-right num">{{ formatMontant(i.valeur_brute) }}</td>
                  <td class="text-right num">{{ i.taux }} %</td>
                  <td class="num">{{ formatDate(i.debut_amortissement) }}</td>
                  <td class="text-right num">{{ formatMontant(i.cumul_anterieur) }}</td>
                  <td class="text-right num ce-fort">{{ formatMontant(i.dotation) }}</td>
                  <td class="text-right num">{{ formatMontant(i.vnc_fin) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="6">Total des dotations</td>
                  <td class="text-right num ce-fort">{{ formatMontant(dotations.total) }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-else class="fs-empty">Aucune immobilisation enregistrée (Structure › Référentiels › Amortissements).</p>

          <div v-if="dotations?.total > 0 && !dotations.piece?.validee" class="ce-pied">
            <UiButton v-if="dotations.piece" variant="ghost" :to="`/ecriture/${dotations.piece.id}`" icon="bi-eye">Relire la pièce</UiButton>
            <UiButton :loading="action === 'dotations'" icon="bi-file-earmark-plus" @click="genererDotations(false)">
              {{ dotations.piece ? 'Régénérer en brouillon' : 'Générer en brouillon' }}
            </UiButton>
            <UiButton variant="primary" :loading="action === 'dotations-valider'" icon="bi-check2-circle" @click="genererDotations(true)">
              Générer et valider
            </UiButton>
          </div>
        </section>

        <!-- Étape 2 : autres travaux d'inventaire -->
        <section class="fs-card ce-section">
          <h2 class="ce-section__titre"><span class="ce-etape">2</span> Autres travaux d'inventaire</h2>
          <ul class="ce-inventaire">
            <li><strong>Régularisations</strong> : charges à payer, produits à recevoir, charges et produits constatés d'avance, datés du {{ formatDate(ex.date_fin) }}.
              Cochez « Régularisation à extourner » sur la pièce : elle sera extournée automatiquement au {{ formatDate(prep.apercu.date_a_nouveaux) }}.
              <span class="ce-compteur">{{ prep.apercu.regularisations_a_extourner }} régularisation(s) à extourner à ce jour.</span></li>
            <li><strong>Provisions et dépréciations</strong> (clients douteux, stocks, risques) : dotations 68x / 15x, 39x, 49x.</li>
            <li><strong>Impôt sur le résultat</strong> (IR, minimum de perception) : débit 695 / crédit 444.</li>
          </ul>
          <div class="ce-liens"><UiButton to="/ecriture" icon="bi-plus-lg">Saisir une écriture d'inventaire</UiButton></div>
        </section>

        <!-- Étape 3 : contrôles -->
        <section class="fs-card ce-section">
          <h2 class="ce-section__titre"><span class="ce-etape">3</span> Contrôles avant clôture</h2>
          <ul class="ce-controles">
            <li v-for="c in prep.controles" :key="c.cle" :class="c.ok ? 'is-ok' : (c.bloquant ? 'is-ko' : 'is-alerte')">
              <i :class="['bi', c.ok ? 'bi-check-circle-fill' : (c.bloquant ? 'bi-x-circle-fill' : 'bi-exclamation-circle-fill')]" aria-hidden="true"></i>
              <div>
                <strong>{{ c.libelle }}</strong>
                <span v-if="!c.ok && c.detail" class="ce-controles__detail">
                  {{ c.detail }}<template v-if="!c.bloquant"> (non bloquant)</template>
                </span>
              </div>
              <UiButton v-if="!c.ok && c.lien" variant="link" size="sm" :to="c.lien" icon-right="bi-arrow-right">Corriger</UiButton>
            </li>
          </ul>
        </section>

        <!-- Étape 4 : clôture -->
        <section class="fs-card ce-section">
          <h2 class="ce-section__titre"><span class="ce-etape">4</span> Clôturer l'exercice {{ ex.annee }}</h2>
          <ol class="ce-deroule">
            <li>
              <strong>Écriture de clôture au {{ formatDate(prep.apercu.date_cloture) }}</strong> (journal CL) :
              {{ prep.apercu.comptes_gestion_soldes }} compte(s) de charges et de produits soldé(s),
              résultat de {{ formatMontant(Math.abs(resultat)) }} Ar porté au
              {{ prep.apercu.compte_resultat || 'compte 12 (résultat nul)' }}.
            </li>
            <li>
              <strong>À-nouveaux au {{ formatDate(prep.apercu.date_a_nouveaux) }}</strong> (journal AN) : reprise des soldes
              des comptes de bilan (classes 1 à 5), dont le résultat {{ ex.annee }} au compte 12, en attente d'affectation.
            </li>
            <li v-if="prep.apercu.regularisations_a_extourner">
              <strong>Extourne de {{ prep.apercu.regularisations_a_extourner }} régularisation(s)</strong> au {{ formatDate(prep.apercu.date_a_nouveaux) }}.
            </li>
            <li>
              <strong>Verrouillage</strong> de {{ ex.annee }} (y compris le dernier mois) ;
              l'exercice {{ suivant.annee }} est ouvert s'il ne l'est pas déjà.
            </li>
          </ol>
          <p class="ce-aide">Ensuite : affectation du résultat dans {{ suivant.annee }}, sur cette même page.</p>

          <label v-if="dotationsEnAttente" class="ce-case">
            <input v-model="ignorerDotations" type="checkbox" />
            Clôturer sans passer les dotations aux amortissements
          </label>

          <div class="ce-pied">
            <span v-if="!prep.peut_cloturer" class="ce-bloque"><i class="bi bi-slash-circle" aria-hidden="true"></i> Corrigez les contrôles bloquants pour clôturer.</span>
            <UiButton
              variant="primary" icon="bi-lock-fill" :loading="action === 'cloturer'"
              :disabled="!prep.peut_cloturer || (dotationsEnAttente && !ignorerDotations)"
              @click="cloturer"
            >Clôturer l'exercice {{ ex.annee }}</UiButton>
          </div>
        </section>
      </template>

      <section v-else class="fs-card ce-section">
        <p class="ce-texte">
          L'exercice {{ ex.annee }} est fermé sans avoir été clôturé par la procédure (données antérieures).
          <span v-if="prep.controles.find(c => c.cle === 'ouvert')?.detail"> {{ prep.controles.find(c => c.cle === 'ouvert').detail }}.</span>
        </p>
      </section>
    </template>

    <!-- Annulation de clôture : administrateur, motif obligatoire, confirmation -->
    <div v-if="modaleAnnulation" class="modal-overlay" @click.self="modaleAnnulation = false">
      <div class="modal" role="dialog" aria-labelledby="ce-annul-titre">
        <div class="modal-header">
          <h2 id="ce-annul-titre" class="modal-title">Annuler la clôture de {{ ex.annee }}</h2>
          <UiButton variant="ghost" icon="bi-x-lg" icon-only aria-label="Fermer" @click="modaleAnnulation = false" />
        </div>
        <form class="modal-body ce-annul" @submit.prevent="annuler">
          <p class="ce-aide">
            L'écriture de clôture, les à-nouveaux et les extournes automatiques seront supprimés et l'exercice {{ ex.annee }} rouvert.
            Cette action exceptionnelle est inscrite dans la piste d'audit avec votre nom et le motif.
          </p>
          <label class="ce-annul__label" for="ce-annul-motif">Motif de l'annulation</label>
          <textarea
            id="ce-annul-motif" v-model="motifAnnulation" class="fs-input ce-annul__motif" rows="3" maxlength="500" required
            placeholder="Ex. : écriture de régularisation oubliée, à passer avant le dépôt des états financiers"
          ></textarea>
          <small class="ce-annul__aide">10 caractères minimum ({{ motifAnnulation.trim().length }}/500)</small>
          <label class="ce-annul__label" for="ce-annul-annee">Pour confirmer, saisissez l'année {{ ex.annee }}</label>
          <input id="ce-annul-annee" v-model="confirmationAnnee" class="fs-input" inputmode="numeric" autocomplete="off" required />
          <div class="modal-footer">
            <UiButton variant="ghost" @click="modaleAnnulation = false">Retour</UiButton>
            <UiButton type="submit" variant="danger" icon="bi-unlock" :loading="action === 'annuler'" :disabled="!annulationPossible">Annuler la clôture</UiButton>
          </div>
        </form>
      </div>
    </div>
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
const exercices = ref([]);
const exerciceId = ref(null);
const prep = ref(null);
const dotations = ref(null);
const periodes = ref(null);
const affectation = ref(null);
const chargement = ref(false);
const action = ref(null);
const message = ref(null);
const ignorerDotations = ref(false);
const formAff = reactive({ date: '', reference: '', montants: {} });

const ex = computed(() => prep.value?.exercice || {});
const suivant = computed(() => prep.value?.exercice_suivant || {});
const resultat = computed(() => Number(prep.value?.resultat || 0));
const dotationsEnAttente = computed(() => prep.value?.controles?.some(c => c.cle === 'dotations' && !c.ok));
const totalAff = computed(() => Object.values(formAff.montants).reduce((s, v) => s + (Number(v) || 0), 0));
const resteAff = computed(() => {
  const r = Math.round((Math.abs(affectation.value?.resultat || 0) - totalAff.value) * 100) / 100;
  return Math.abs(r) < 0.01 ? 0 : r;
});

const formatMontant = n => Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatDate = d => (d ? new Date(d).toLocaleDateString('fr-FR') : '');
const erreur = (e, defaut) => e.response?.data?.message || defaut;

async function executer(cle, appel, defautErreur) {
  action.value = cle;
  try {
    const { data } = await appel();
    message.value = { ton: 'succes', texte: data.message };
    return data;
  } catch (e) {
    message.value = { ton: 'erreur', texte: erreur(e, defautErreur) };
    return null;
  } finally {
    action.value = null;
  }
}

async function charger() {
  if (!exerciceId.value) return;
  chargement.value = true;
  const id = exerciceId.value;
  try {
    const [p, d, per] = await Promise.all([
      axios.get(`${API_BASE}/exercices/${id}/cloture`),
      axios.get(`${API_BASE}/exercices/${id}/dotations`),
      axios.get(`${API_BASE}/exercices/${id}/periodes`),
    ]);
    prep.value = p.data;
    dotations.value = d.data;
    periodes.value = per.data;
    affectation.value = null;
    if (p.data.exercice.cloture_definitive) {
      const { data } = await axios.get(`${API_BASE}/exercices/${id}/affectation`);
      affectation.value = data;
      preparerAffectation(data);
    }
  } catch (e) {
    message.value = { ton: 'erreur', texte: erreur(e, "Impossible de charger l'exercice.") };
  } finally {
    chargement.value = false;
  }
}

function preparerAffectation(a) {
  if (!a.exercice_suivant || !a.destinations?.length) return;
  // Par défaut tout en report à nouveau (110 créditeur / 119 débiteur), à ajuster selon le PV
  const report = a.resultat >= 0 ? '110001' : '119001';
  formAff.montants = Object.fromEntries(a.destinations.map(d => [d.code, d.code === report ? Math.abs(a.resultat) : null]));
  const aujourdhui = new Date().toISOString().slice(0, 10);
  const { date_debut: debut, date_fin: fin } = a.exercice_suivant;
  formAff.date = aujourdhui < debut ? debut : (aujourdhui > fin ? fin : aujourdhui);
  formAff.reference = '';
}

async function chargerExercices(selection = null) {
  const { data } = await axios.get(`${API_BASE}/exercices`);
  exercices.value = data;
  // Par défaut : le plus ancien exercice ouvert (celui à clôturer)
  const ouverts = data.filter(e => e.Statut === 'OUVERT').sort((a, b) => a.Annee_fiscale - b.Annee_fiscale);
  exerciceId.value = selection ?? ouverts[0]?.Id_Exercice_comptable ?? data[0]?.Id_Exercice_comptable ?? null;
}

async function recharger() {
  await chargerExercices(exerciceId.value);
  await charger();
}

async function ouvrirSuivant() {
  if (!confirm(`Ouvrir l'exercice ${suivant.value.annee} ? La saisie courante pourra s'y faire pendant que ${ex.value.annee} reste ouvert pour les travaux de clôture.`)) return;
  if (await executer('suivant', () => axios.post(`${API_BASE}/exercices/ouvrir-suivant`), "Ouverture impossible.")) await recharger();
}

async function cloturerMois(m) {
  if (!confirm(`Clôturer définitivement ${m.libelle} ?\n\nPlus aucune écriture ne pourra être datée du ${formatDate(m.fin)} ou avant.`)) return;
  const data = await executer(`mois-${m.mois}`, () => axios.post(`${API_BASE}/exercices/${exerciceId.value}/periodes/cloturer`, { mois: m.mois }), 'Clôture du mois impossible.');
  if (data) periodes.value = data.periodes;
}

async function genererDotations(valider) {
  if (valider && !confirm(`Passer et valider les dotations ${ex.value.annee} (${formatMontant(dotations.value.total)} Ar) ?`)) return;
  if (await executer(valider ? 'dotations-valider' : 'dotations',
    () => axios.post(`${API_BASE}/exercices/${exerciceId.value}/dotations`, { valider }), 'Génération des dotations impossible.')) await charger();
}

async function cloturer() {
  const sens = resultat.value >= 0 ? 'bénéfice' : 'perte';
  if (!confirm(
    `Clôturer définitivement l'exercice ${ex.value.annee} ?\n\n`
    + `• ${sens} de ${formatMontant(Math.abs(resultat.value))} Ar porté au compte 12\n`
    + `• à-nouveaux générés dans ${suivant.value.annee}\n`
    + `• plus aucune saisie possible sur ${ex.value.annee}`
  )) return;
  if (await executer('cloturer', () => axios.post(`${API_BASE}/exercices/${exerciceId.value}/cloturer`, { ignorer_dotations: ignorerDotations.value }), 'Clôture impossible.')) await recharger();
}

// Annulation de clôture : réservée à un administrateur, motif écrit + saisie de l'année
const modaleAnnulation = ref(false);
const motifAnnulation = ref('');
const confirmationAnnee = ref('');
const estAdmin = computed(() => !!user.value?.is_admin);
const annulationPossible = computed(() =>
  motifAnnulation.value.trim().length >= 10 && confirmationAnnee.value.trim() === String(ex.value.annee));

function ouvrirAnnulation() {
  motifAnnulation.value = '';
  confirmationAnnee.value = '';
  modaleAnnulation.value = true;
}

async function annuler() {
  if (!annulationPossible.value) return;
  const ok = await executer('annuler',
    () => axios.post(`${API_BASE}/exercices/${exerciceId.value}/annuler-cloture`, { motif: motifAnnulation.value.trim() }),
    'Annulation impossible.');
  modaleAnnulation.value = false;
  if (ok) await recharger();
}

async function affecter(valider) {
  if (valider && !confirm(`Passer et valider l'affectation du résultat ${ex.value.annee} ? Elle ne pourra plus être modifiée (seulement contre-passée).`)) return;
  const repartition = Object.entries(formAff.montants)
    .filter(([, v]) => Number(v) > 0)
    .map(([code, montant]) => ({ code, montant: Number(montant) }));
  const data = await executer(valider ? 'affecter' : 'affecter-brouillon',
    () => axios.post(`${API_BASE}/exercices/${exerciceId.value}/affectation`, { date: formAff.date, reference: formAff.reference, repartition, valider }),
    'Affectation impossible.');
  if (data) affectation.value = data.affectation;
}

watch(exerciceId, () => { ignorerDotations.value = false; charger(); });

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  getUser(token).then(r => { user.value = r.data; }).catch(() => {});
  try {
    await chargerExercices();
  } catch (e) {
    message.value = { ton: 'erreur', texte: erreur(e, 'Impossible de charger les exercices.') };
  }
});
</script>

<style scoped>
.ce-exercice { width: auto; min-width: 200px; }

.ce-alerte {
  display: flex; align-items: center; gap: 10px; padding: 12px 16px; margin-bottom: 20px;
  border-radius: 10px; border: 1px solid var(--fs-border); font-size: 14px; color: var(--fs-text);
}
.ce-alerte span { flex: 1; }
.ce-alerte--succes { background: color-mix(in srgb, var(--fs-success, #16a34a) 10%, var(--fs-surface)); }
.ce-alerte--succes .bi:first-child { color: var(--fs-success, #16a34a); }
.ce-alerte--erreur { background: color-mix(in srgb, var(--fs-danger, #dc2626) 10%, var(--fs-surface)); }
.ce-alerte--erreur .bi:first-child { color: var(--fs-danger, #dc2626); }

.ce-synthese { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 20px; }
.ce-tuile { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 18px 20px; }
.ce-tuile__label { font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--fs-muted); }
.ce-tuile__valeur { font-size: 24px; font-weight: 700; color: var(--fs-text); font-variant-numeric: tabular-nums; }
.ce-tuile__valeur--petit { font-size: 17px; }
.ce-tuile__valeur.is-positif { color: var(--fs-success, #16a34a); }
.ce-tuile__valeur.is-negatif { color: var(--fs-danger, #dc2626); }
.ce-tuile__aide { font-size: 13px; color: var(--fs-text-2); }

.ce-section { padding: 20px 22px; margin-bottom: 20px; }
.ce-section__entete { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.ce-section__titre { display: flex; align-items: center; gap: 10px; margin: 0 0 8px; font-size: 16px; font-weight: 700; color: var(--fs-text); }
.ce-etape {
  display: inline-grid; place-items: center; width: 24px; height: 24px; border-radius: 50%;
  background: var(--fs-primary); color: #fff; font-size: 13px; font-weight: 700;
}
.ce-aide, .ce-texte { margin: 0 0 14px; font-size: 13.5px; color: var(--fs-text-2); line-height: 1.55; }
.ce-aide--ligne { margin: 0; }
.ce-texte { color: var(--fs-text); }
.ce-req { color: var(--fs-danger, #dc2626); }
.ce-compte { font-size: 13px; color: var(--fs-text-2); }
.ce-fort { font-weight: 700; color: var(--fs-text); }
.num { font-variant-numeric: tabular-nums; }
.fs-table tfoot td { font-weight: 600; border-top: 2px solid var(--fs-border); }

.ce-pied { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.ce-pied .ce-aide { flex: 1; margin: 0; min-width: 240px; }
.ce-liens { display: flex; gap: 10px; flex-wrap: wrap; }
.ce-bloque { margin-right: auto; font-size: 13.5px; color: var(--fs-danger, #dc2626); }
.ce-bloque--bloc { margin: 0 0 12px; }
.ce-annul { display: flex; flex-direction: column; gap: 8px; }
.ce-annul__label { font-size: 13px; font-weight: 600; color: var(--fs-text); margin-top: 6px; }
.ce-annul__motif { resize: vertical; min-height: 72px; font-family: inherit; }
.ce-annul__aide { font-size: 12px; color: var(--fs-muted); }

.ce-mois { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }
.ce-mois li {
  display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 10px 12px;
  border: 1px solid var(--fs-border); border-radius: 10px; background: var(--fs-surface);
}
.ce-mois li.is-cloture { background: var(--fs-surface-2); }
.ce-mois__nom { font-weight: 600; font-size: 14px; color: var(--fs-text); }
.ce-mois__info { font-size: 12.5px; color: var(--fs-text-2); }

.ce-controles { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.ce-controles li {
  display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px;
  border: 1px solid var(--fs-border); background: var(--fs-surface-2);
}
.ce-controles li > div { flex: 1; display: flex; flex-direction: column; gap: 2px; font-size: 14px; color: var(--fs-text); }
.ce-controles li .bi:first-child { font-size: 18px; }
.ce-controles .is-ok .bi:first-child { color: var(--fs-success, #16a34a); }
.ce-controles .is-ko .bi:first-child { color: var(--fs-danger, #dc2626); }
.ce-controles .is-alerte .bi:first-child { color: var(--fs-warning, #d97706); }
.ce-controles__detail { font-size: 13px; color: var(--fs-text-2); }

.ce-inventaire { margin: 0 0 14px; padding-left: 20px; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: var(--fs-text); line-height: 1.55; }
.ce-compteur { display: block; font-size: 13px; color: var(--fs-text-2); }
.ce-deroule { margin: 0 0 14px; padding-left: 20px; display: flex; flex-direction: column; gap: 8px; font-size: 14px; color: var(--fs-text); line-height: 1.55; }
.ce-case { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--fs-text); cursor: pointer; }

.ce-affectation__entete { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 16px; margin-bottom: 14px; }
.ce-col-montant { width: 220px; }
.ce-montant { text-align: right; font-variant-numeric: tabular-nums; }
.ce-ecart { color: var(--fs-danger, #dc2626); font-weight: 500; }

@media (max-width: 900px) {
  .ce-synthese { grid-template-columns: 1fr; }
  .ce-affectation__entete { grid-template-columns: 1fr; }
}
</style>
