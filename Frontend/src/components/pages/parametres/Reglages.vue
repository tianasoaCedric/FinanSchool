<template>
  <AppLayout
    :user="user"
    icon="bi-gear"
    title="Paramètres"
    subtitle="Votre compte, la configuration de l'application et les accès"
    section="Administration"
  >
    <template #actions>
      <template v-if="estAdmin && sectionParametres">
        <UiButton :disabled="!modifie || enregistrement" @click="annuler">Annuler</UiButton>
        <UiButton variant="primary" icon="bi-check-lg" :loading="enregistrement" :disabled="!modifie" @click="enregistrer">
          Enregistrer
        </UiButton>
      </template>
    </template>

    <div class="pr-page">
    <div v-if="!estAdmin && user && sectionParametres" class="pr-banner" role="note">
      <i class="bi bi-lock"></i>
      Consultation seule : seul un administrateur peut modifier les paramètres de l'application.
    </div>

    <div class="pr-layout">
      <!-- Navigation des sections, par groupe -->
      <nav class="fs-card pr-nav" aria-label="Sections des paramètres">
        <template v-for="g in groupesVisibles" :key="g.titre">
          <p class="pr-nav__group">{{ g.titre }}</p>
          <button
            v-for="s in g.sections"
            :key="s.id"
            type="button"
            class="pr-nav__item"
            :class="{ 'is-active': section === s.id }"
            :aria-current="section === s.id ? 'page' : undefined"
            @click="section = s.id"
          >
            <i :class="['bi', s.icon]"></i>
            <span>{{ s.label }}</span>
            <span v-if="modifsParSection[s.id]" class="pr-nav__dot" :title="`${modifsParSection[s.id]} modification(s) non enregistrée(s)`"></span>
          </button>
        </template>
      </nav>

      <div class="pr-content">
        <div v-if="!pret" class="fs-card pr-card"><UiSkeleton :rows="6" /></div>

        <template v-else>
          <!-- ===== Mon profil ===== -->
          <ProfilSection v-if="section === 'profil'" @message="annoncer" @profil="u => Object.assign(user, u)" />

          <!-- ===== Modes de paiement ===== -->
          <ModesPaiementSection v-if="section === 'paiement'" :modifiable="peutModifier" @message="annoncer" />

          <!-- ===== Utilisateurs et rôles ===== -->
          <UtilisateursSection v-if="section === 'utilisateurs' && estAdmin" :moi-id="user?.id" @message="annoncer" />
          <!-- ===== Entreprise ===== -->
          <section v-show="section === 'societe'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Entreprise</h2>
                <p>Identité affichée dans les en-têtes des états financiers, du grand livre et des exports.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('societe')">Valeurs par défaut</UiButton>
            </header>

            <div class="pr-logo">
              <div class="pr-logo__preview">
                <img v-if="form['societe.logo']" :src="form['societe.logo']" alt="Logo de l'entreprise" />
                <i v-else class="bi bi-image"></i>
              </div>
              <div>
                <p class="pr-logo__title">Logo</p>
                <p class="pr-hint">PNG, JPEG, SVG ou WebP · 512 Ko maximum. Utilisé dans les PDF.</p>
                <div v-if="estAdmin" class="pr-logo__actions">
                  <UiButton size="sm" icon="bi-upload" @click="$refs.fichierLogo.click()">Choisir une image</UiButton>
                  <UiButton v-if="form['societe.logo']" size="sm" variant="soft-danger" icon="bi-trash" @click="form['societe.logo'] = ''">Retirer</UiButton>
                  <input ref="fichierLogo" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp" hidden @change="choisirLogo" />
                </div>
                <p v-if="erreurs['societe.logo']" class="pr-error">{{ erreurs['societe.logo'] }}</p>
              </div>
            </div>

            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.societe" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
          </section>

          <!-- ===== Comptabilité ===== -->
          <section v-show="section === 'compta'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Comptabilité</h2>
                <p>Devise de tenue des comptes, arrondis et taxes.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('compta')">Valeurs par défaut</UiButton>
            </header>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.compta" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
          </section>

          <!-- ===== Saisie et import ===== -->
          <section v-show="section === 'saisie'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Saisie et import</h2>
                <p>Numérotation des pièces validées, justificatifs et import de fichiers d'écritures.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('saisie')">Valeurs par défaut</UiButton>
            </header>
            <h3 class="pr-subtitle pr-subtitle--first">Pièces</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.saisie" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
              <div class="fs-field">
                <span class="fs-label">Aperçu du numéro</span>
                <p class="pr-apercu"><code>{{ apercuNumero }}</code></p>
                <p class="pr-hint">Journal, année, séparateur puis séquence propre au journal et à l'année.</p>
              </div>
            </div>
            <div v-if="numerotationModifiee" class="pr-warning pr-warning--spaced">
              <i class="bi bi-exclamation-triangle"></i>
              <span>Changer le séparateur redémarre la séquence à 1 pour l'année en cours : les numéros déjà attribués ne sont pas renumérotés.</span>
            </div>
            <h3 class="pr-subtitle">Tiers</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.tiers" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
            <h3 class="pr-subtitle">Import d'écritures</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.import" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
          </section>

          <!-- ===== Sécurité et affichage ===== -->
          <section v-show="section === 'securite'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Sécurité et affichage</h2>
                <p>Durée des sessions et présentation des listes.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('securite')">Valeurs par défaut</UiButton>
            </header>
            <h3 class="pr-subtitle pr-subtitle--first">Sécurité</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.securite" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
            <h3 class="pr-subtitle">Affichage</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.affichage" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
          </section>

          <!-- ===== Comptes et journaux système ===== -->
          <section v-show="section === 'systeme'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Comptes et journaux système</h2>
                <p>Utilisés par la clôture, les à-nouveaux, l'affectation du résultat et les dotations aux amortissements.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('systeme')">Valeurs par défaut</UiButton>
            </header>
            <div class="pr-warning">
              <i class="bi bi-exclamation-triangle"></i>
              <span>Les écritures déjà passées ne sont pas modifiées : un changement ne vaut que pour les prochains traitements.
                Un compte ou un journal absent est créé automatiquement au premier usage.</span>
            </div>
            <h3 class="pr-subtitle">Comptes (6 chiffres)</h3>
            <div class="pr-grid">
              <Champ v-for="c in CHAMPS.systemeComptes" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
            <h3 class="pr-subtitle">Journaux (code)</h3>
            <div class="pr-grid pr-grid--3">
              <Champ v-for="c in CHAMPS.systemeJournaux" :key="c.cle" :champ="c" v-model="form[c.cle]" :erreur="erreurs[c.cle]" :lecture="!estAdmin" />
            </div>
          </section>

          <!-- ===== Notifications ===== -->
          <section v-show="section === 'notifications'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Notifications</h2>
                <p>Alertes envoyées avant la clôture d'un exercice qui contient encore des écritures à valider.</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('notifications')">Valeurs par défaut</UiButton>
            </header>

            <label class="pr-switch">
              <input v-model="form['notifications.cloture_active']" type="checkbox" :disabled="!estAdmin" />
              <span class="pr-switch__track" aria-hidden="true"></span>
              <span>
                <strong>Alertes de clôture</strong>
                <span class="pr-hint">Notification aux utilisateurs aux jalons ci-dessous.</span>
              </span>
            </label>

            <div class="fs-field pr-jalons" :class="{ 'is-disabled': !form['notifications.cloture_active'] }">
              <span class="fs-label">Jalons (jours avant la date de clôture)</span>
              <div class="pr-chips">
                <span v-for="(j, i) in jalonsTries" :key="j" class="pr-chip">
                  J-{{ j }}
                  <button v-if="estAdmin" type="button" :aria-label="`Retirer le jalon J-${j}`" @click="retirerJalon(j)"><i class="bi bi-x"></i></button>
                </span>
                <form v-if="estAdmin && jalonsTries.length < 6" class="pr-chips__add" @submit.prevent="ajouterJalon">
                  <input v-model.number="nouveauJalon" class="fs-input" type="number" min="1" max="365" placeholder="Jours" aria-label="Nouveau jalon en jours" />
                  <UiButton type="submit" size="sm" icon="bi-plus-lg" :disabled="!nouveauJalon">Ajouter</UiButton>
                </form>
              </div>
              <p v-if="erreurs['notifications.cloture_jalons']" class="pr-error">{{ erreurs['notifications.cloture_jalons'] }}</p>
            </div>
          </section>

          <!-- ===== Tableau de bord ===== -->
          <section v-show="section === 'dashboard'" class="fs-card pr-card">
            <header class="pr-card__head">
              <div>
                <h2>Tableau de bord</h2>
                <p>Seuils d'appréciation des ratios clés (statuts « Bon », « À surveiller », « Faible »).</p>
              </div>
              <UiButton v-if="estAdmin" size="sm" variant="ghost" icon="bi-arrow-counterclockwise" @click="reinitialiser('dashboard')">Valeurs par défaut</UiButton>
            </header>
            <table class="pr-seuils">
              <thead>
                <tr><th>Ratio</th><th>« Bon » à partir de</th><th>« À surveiller » à partir de</th><th>Sinon</th></tr>
              </thead>
              <tbody>
                <tr v-for="r in SEUILS" :key="r.id">
                  <td>
                    <strong>{{ r.label }}</strong>
                    <span class="pr-hint">{{ r.hint }}</span>
                  </td>
                  <td v-for="niveau in ['bon', 'moyen']" :key="niveau">
                    <div class="pr-input-unit">
                      <input
                        v-model.number="form[`dashboard.${r.id}_${niveau}`]"
                        class="fs-input" type="number" :step="r.step" :readonly="!estAdmin"
                        :aria-label="`${r.label} — seuil ${niveau === 'bon' ? 'bon' : 'à surveiller'}`"
                        :aria-invalid="!!erreurs[`dashboard.${r.id}_${niveau}`]"
                      />
                      <span>{{ r.unite }}</span>
                    </div>
                    <p v-if="erreurs[`dashboard.${r.id}_${niveau}`]" class="pr-error">{{ erreurs[`dashboard.${r.id}_${niveau}`] }}</p>
                  </td>
                  <td class="pr-seuils__else">{{ r.inverse ? 'au-delà : Faible' : 'en dessous : Faible' }}</td>
                </tr>
              </tbody>
            </table>
            <p class="pr-hint pr-seuils__note">Pour le délai clients, plus la valeur est basse, meilleur est le ratio : les seuils sont des maximums.</p>
          </section>
        </template>
      </div>
    </div>
    </div>

    <transition name="fs-fade">
      <div v-if="message" class="pr-toast" :class="`pr-toast--${message.ton}`" role="status">
        <i :class="['bi', message.ton === 'ok' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill']"></i>
        {{ message.texte }}
      </div>
    </transition>
  </AppLayout>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import AppLayout from '@/components/template/AppLayout.vue';
import ProfilSection from './ProfilSection.vue';
import ModesPaiementSection from './ModesPaiementSection.vue';
import UtilisateursSection from './UtilisateursSection.vue';
import { getUser } from '@/services/Auth';
import { useParametresStore } from '@/stores/parametres';

/* ---------- Champ de formulaire générique ---------- */
const Champ = defineComponent({
  props: { champ: Object, modelValue: [String, Number, Boolean], erreur: [String, Array], lecture: Boolean },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () => {
      const c = props.champ;
      const id = `pr-${c.cle.replace('.', '-')}`;
      const err = Array.isArray(props.erreur) ? props.erreur[0] : props.erreur;
      const input = c.options ? h('select', {
        id, class: 'fs-select', value: props.modelValue, disabled: props.lecture,
        'aria-describedby': c.aide || err ? `${id}-aide` : undefined,
        onChange: e => {
          const o = c.options.find(x => String(x.value) === e.target.value);
          emit('update:modelValue', o ? o.value : e.target.value);
        },
      }, c.options.map(o => h('option', { value: o.value, selected: o.value === props.modelValue }, o.label))) : h('input', {
        id, class: 'fs-input', type: c.type || 'text', value: props.modelValue ?? '',
        placeholder: c.placeholder, step: c.step, min: c.min, max: c.max, maxlength: c.maxlength,
        readonly: props.lecture, 'aria-invalid': err ? 'true' : undefined,
        'aria-describedby': c.aide || err ? `${id}-aide` : undefined,
        onInput: e => emit('update:modelValue', c.type === 'number' ? (e.target.value === '' ? '' : Number(e.target.value)) : e.target.value),
      });
      return h('div', { class: ['fs-field', { 'pr-field--large': c.large }] }, [
        h('label', { class: 'fs-label', for: id }, c.label + (c.requis ? ' *' : '')),
        c.unite ? h('div', { class: 'pr-input-unit' }, [input, h('span', c.unite)]) : input,
        err ? h('p', { id: `${id}-aide`, class: 'pr-error' }, err)
          : c.aide ? h('p', { id: `${id}-aide`, class: 'pr-hint' }, c.aide) : null,
      ]);
    };
  },
});

// Sections par groupe. « parametres » : sections enregistrées par le bouton
// Enregistrer de l'en-tête ; les autres ont leurs propres actions.
const GROUPES = [
  { titre: 'Mon compte', sections: [
    { id: 'profil', label: 'Mon profil', icon: 'bi-person-circle' },
  ] },
  { titre: 'Application', sections: [
    { id: 'societe', label: 'Entreprise', icon: 'bi-building', parametres: true },
    { id: 'compta', label: 'Comptabilité', icon: 'bi-calculator', parametres: true },
    { id: 'paiement', label: 'Modes de paiement', icon: 'bi-credit-card' },
    { id: 'saisie', label: 'Saisie et import', icon: 'bi-pencil-square', parametres: true },
    { id: 'systeme', label: 'Comptes et journaux', icon: 'bi-diagram-3', parametres: true },
    { id: 'notifications', label: 'Notifications', icon: 'bi-bell', parametres: true },
    { id: 'dashboard', label: 'Tableau de bord', icon: 'bi-speedometer2', parametres: true },
    { id: 'securite', label: 'Sécurité et affichage', icon: 'bi-shield-lock', parametres: true },
  ] },
  { titre: 'Administration', admin: true, sections: [
    { id: 'utilisateurs', label: 'Utilisateurs et rôles', icon: 'bi-people' },
  ] },
];
const SECTIONS = GROUPES.flatMap(g => g.sections.map(s => ({ ...s, admin: !!g.admin })));
// Préfixes de clés regroupés dans une même section
const SECTION_DE = { import: 'saisie', tiers: 'saisie', affichage: 'securite' };
const sectionDe = cle => { const p = cle.split('.')[0]; return SECTION_DE[p] || p; };

const CHAMPS = {
  societe: [
    { cle: 'societe.nom', label: 'Raison sociale', requis: true, large: true, maxlength: 150 },
    { cle: 'societe.forme_juridique', label: 'Forme juridique', placeholder: 'SARL, SA, Association…' },
    { cle: 'societe.activite', label: 'Activité', placeholder: 'Enseignement, commerce…' },
    { cle: 'societe.adresse', label: 'Adresse', large: true },
    { cle: 'societe.ville', label: 'Ville' },
    { cle: 'societe.pays', label: 'Pays' },
    { cle: 'societe.telephone', label: 'Téléphone', type: 'tel', placeholder: '+261 …' },
    { cle: 'societe.email', label: 'E-mail', type: 'email' },
    { cle: 'societe.site_web', label: 'Site web', placeholder: 'www.exemple.mg' },
    { cle: 'societe.nif', label: 'NIF', aide: "Numéro d'identification fiscale" },
    { cle: 'societe.stat', label: 'N° statistique (STAT)' },
    { cle: 'societe.rcs', label: 'RCS' },
  ],
  compta: [
    { cle: 'compta.devise_code', label: 'Code de la devise', requis: true, aide: 'Code ISO, ex. MGA', maxlength: 10 },
    { cle: 'compta.devise_symbole', label: 'Symbole', requis: true, aide: 'Affiché après les montants, ex. Ar', maxlength: 10 },
    { cle: 'compta.decimales', label: 'Décimales des montants', type: 'number', min: 0, max: 4, step: 1, requis: true },
    { cle: 'compta.tolerance_equilibre', label: "Tolérance d'équilibre", type: 'number', min: 0, max: 1, step: 0.01, requis: true, unite: 'Ar', aide: 'Écart débit / crédit toléré pour considérer une pièce équilibrée' },
    { cle: 'compta.taux_tva', label: 'Taux de TVA', type: 'number', min: 0, max: 100, step: 0.1, requis: true, unite: '%', aide: 'Sert au calcul des délais clients et fournisseurs (montants TTC)' },
  ],
  saisie: [
    { cle: 'saisie.numero_separateur', label: 'Séparateur du numéro de pièce', options: [
      { value: '-', label: 'Tiret (VT2026-0001)' }, { value: '/', label: 'Barre oblique (VT2026/0001)' },
      { value: '_', label: 'Souligné (VT2026_0001)' }, { value: '.', label: 'Point (VT2026.0001)' },
    ] },
    { cle: 'saisie.numero_chiffres', label: 'Chiffres du numéro séquentiel', type: 'number', min: 3, max: 8, step: 1, requis: true, aide: '4 → 0001, 6 → 000001' },
    { cle: 'saisie.justificatif_taille_max', label: 'Taille maximale des justificatifs', type: 'number', min: 1, max: 20, step: 1, unite: 'Mo', requis: true },
  ],
  tiers: [
    { cle: 'tiers.obligatoire', label: 'Client / fournisseur obligatoire', aide: 'Une pièce ne peut pas être validée sans tiers sur ces comptes', options: [
      { value: true, label: 'Oui, exigé à la validation' }, { value: false, label: 'Non, facultatif' },
    ] },
    { cle: 'tiers.comptes', label: 'Comptes concernés', requis: true, placeholder: '40,41', aide: 'Préfixes de classe 4 séparés par des virgules (40 fournisseurs, 41 clients)' },
  ],
  import: [
    { cle: 'import.separateur_csv', label: 'Séparateur des fichiers CSV', options: [
      { value: ';', label: 'Point-virgule ( ; )' }, { value: ',', label: 'Virgule ( , )' },
      { value: '|', label: 'Barre verticale ( | )' }, { value: 'tab', label: 'Tabulation' },
    ] },
    { cle: 'import.taille_max', label: 'Taille maximale du fichier', type: 'number', min: 1, max: 50, step: 1, unite: 'Mo', requis: true },
  ],
  securite: [
    { cle: 'securite.inscription_ouverte', label: 'Inscription libre', aide: 'Fermée : seuls les administrateurs créent les comptes', options: [
      { value: true, label: 'Ouverte (page « Créer un compte »)' }, { value: false, label: 'Fermée' },
    ] },
    { cle: 'securite.role_inscription', label: 'Rôle des nouveaux inscrits', aide: 'Attribué aux comptes créés par inscription libre', options: [
      { value: 'comptable', label: 'Comptable' }, { value: 'lecteur', label: 'Lecteur (consultation seule)' },
    ] },
    { cle: 'securite.mot_de_passe_min', label: 'Longueur minimale des mots de passe', type: 'number', min: 6, max: 32, step: 1, unite: 'car.', requis: true },
    { cle: 'securite.duree_session', label: 'Durée de session', type: 'number', min: 0, max: 10080, step: 15, unite: 'min', requis: true, aide: "Déconnexion automatique après ce délai · 0 = jamais. S'applique aux prochaines connexions." },
  ],
  affichage: [
    { cle: 'affichage.lignes_par_page', label: 'Lignes par page', aide: 'Listes des pièces et piste d\'audit', options: [10, 25, 50, 100].map(n => ({ value: n, label: `${n} lignes` })) },
  ],
  systemeComptes: [
    { cle: 'systeme.compte_report_crediteur', label: 'Report à nouveau créditeur', aide: 'Classe 11', maxlength: 6 },
    { cle: 'systeme.compte_report_debiteur', label: 'Report à nouveau débiteur', aide: 'Classe 11', maxlength: 6 },
    { cle: 'systeme.compte_resultat_benefice', label: 'Résultat — bénéfice', aide: 'Classe 12', maxlength: 6 },
    { cle: 'systeme.compte_resultat_perte', label: 'Résultat — perte', aide: 'Classe 12', maxlength: 6 },
    { cle: 'systeme.compte_dotations', label: 'Dotations aux amortissements', aide: 'Classe 68', maxlength: 6 },
  ],
  systemeJournaux: [
    { cle: 'systeme.journal_a_nouveaux', label: 'À-nouveaux', maxlength: 10 },
    { cle: 'systeme.journal_cloture', label: 'Clôture', maxlength: 10 },
    { cle: 'systeme.journal_od', label: 'Opérations diverses', maxlength: 10 },
  ],
};

const SEUILS = [
  { id: 'liquidite', label: 'Liquidité générale', hint: 'Actif courant / dettes à court terme', unite: '×', step: 0.1 },
  { id: 'autonomie', label: 'Autonomie financière', hint: 'Capitaux propres / total bilan', unite: '%', step: 1 },
  { id: 'marge', label: 'Marge nette', hint: "Résultat net / chiffre d'affaires", unite: '%', step: 1 },
  { id: 'delai_clients', label: 'Délai clients', hint: 'Jours de chiffre d\'affaires TTC', unite: 'j', step: 1, inverse: true },
];

const router = useRouter();
const route = useRoute();
const store = useParametresStore();
const user = ref(null);
const estAdmin = computed(() => !!user.value?.is_admin);
const peutModifier = computed(() => (user.value?.role || 'comptable') !== 'lecteur');
const groupesVisibles = computed(() => GROUPES.filter(g => !g.admin || estAdmin.value));

// Section affichée, reflétée dans l'URL (?section=…) pour pouvoir y renvoyer
const section = ref(SECTIONS.some(s => s.id === route.query.section) ? route.query.section : 'profil');
watch(section, s => router.replace({ query: { ...route.query, section: s } }));
const sectionParametres = computed(() => !!SECTIONS.find(s => s.id === section.value)?.parametres);
const pret = ref(false);
const enregistrement = ref(false);
const form = reactive({});
const initial = ref({});
const erreurs = ref({});
const message = ref(null);
const nouveauJalon = ref(null);
let minuteur = null;

const egal = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const modifications = computed(() => {
  const m = {};
  for (const cle of Object.keys(initial.value)) {
    if (!egal(form[cle], initial.value[cle])) m[cle] = form[cle];
  }
  return m;
});
const modifie = computed(() => Object.keys(modifications.value).length > 0);
const modifsParSection = computed(() => {
  const r = {};
  for (const cle of Object.keys(modifications.value)) {
    const s = sectionDe(cle);
    r[s] = (r[s] || 0) + 1;
  }
  return r;
});
const apercuNumero = computed(() => `VT${new Date().getFullYear()}${form['saisie.numero_separateur'] ?? '-'}${'1'.padStart(Number(form['saisie.numero_chiffres']) || 4, '0')}`);
const numerotationModifiee = computed(() => form['saisie.numero_separateur'] !== initial.value['saisie.numero_separateur']);
const jalonsTries = computed(() => [...(form['notifications.cloture_jalons'] || [])].sort((a, b) => b - a));

function remplir(valeurs) {
  const copie = JSON.parse(JSON.stringify(valeurs));
  Object.assign(form, copie);
  initial.value = JSON.parse(JSON.stringify(copie));
}

function annoncer(texte, ton = 'ok') {
  message.value = { texte, ton };
  clearTimeout(minuteur);
  minuteur = setTimeout(() => { message.value = null; }, 3500);
}

onMounted(async () => {
  const token = localStorage.getItem('token');
  if (!token) return router.push('/');
  try {
    user.value = (await getUser(token)).data;
  } catch {
    return router.push('/');
  }
  await store.charger(true);
  remplir(store.valeurs);
  pret.value = true;
});
onBeforeUnmount(() => clearTimeout(minuteur));

// Garde-fou : modifications non enregistrées
onBeforeRouteLeave(() => !modifie.value || window.confirm('Des modifications ne sont pas enregistrées. Quitter quand même ?'));

function annuler() {
  remplir(initial.value);
  erreurs.value = {};
}

function reinitialiser(id) {
  for (const [cle, v] of Object.entries(store.defauts)) {
    if (sectionDe(cle) === id && cle in form) form[cle] = JSON.parse(JSON.stringify(v));
  }
}

async function enregistrer() {
  if (!modifie.value) return;
  enregistrement.value = true;
  erreurs.value = {};
  try {
    const res = await store.enregistrer(modifications.value);
    remplir(store.valeurs);
    annoncer(res.message || 'Paramètres enregistrés.');
  } catch (e) {
    const errs = e.response?.data?.errors || {};
    erreurs.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
    // Affiche la section de la première erreur
    const premiere = Object.keys(errs)[0];
    if (premiere) section.value = sectionDe(premiere);
    annoncer(e.response?.data?.message || "Les paramètres n'ont pas pu être enregistrés.", 'erreur');
  } finally {
    enregistrement.value = false;
  }
}

function choisirLogo(e) {
  const fichier = e.target.files?.[0];
  e.target.value = '';
  if (!fichier) return;
  if (fichier.size > 512 * 1024) {
    erreurs.value = { ...erreurs.value, 'societe.logo': "L'image dépasse 512 Ko." };
    return;
  }
  const lecteur = new FileReader();
  lecteur.onload = () => {
    form['societe.logo'] = lecteur.result;
    const { 'societe.logo': _, ...reste } = erreurs.value;
    erreurs.value = reste;
  };
  lecteur.readAsDataURL(fichier);
}

function ajouterJalon() {
  const j = Math.round(Number(nouveauJalon.value));
  if (!j || j < 1 || j > 365) return;
  const actuels = form['notifications.cloture_jalons'] || [];
  if (!actuels.includes(j)) form['notifications.cloture_jalons'] = [...actuels, j].sort((a, b) => b - a);
  nouveauJalon.value = null;
}
function retirerJalon(j) {
  form['notifications.cloture_jalons'] = (form['notifications.cloture_jalons'] || []).filter(x => x !== j);
}
</script>

<style>
/* Styles partagés par la page et ses sections (ProfilSection, UtilisateursSection…) :
   non « scoped », préfixe pr- réservé à cette page. */
.pr-banner {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; margin-bottom: 20px; border-radius: var(--fs-radius-sm);
  background: var(--fs-surface-3); color: var(--fs-text-2); font-size: var(--fs-text-sm);
}
.pr-layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 20px; align-items: start; }

/* Navigation */
.pr-nav { position: sticky; top: 20px; padding: 8px; display: flex; flex-direction: column; gap: 2px; }
.pr-nav__item {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 10px 12px; border: 0; border-radius: 8px; background: transparent;
  color: var(--fs-text-2); font-size: var(--fs-text-base); font-weight: 500; text-align: left; cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}
.pr-nav__item .bi { font-size: 16px; color: var(--fs-muted); }
.pr-nav__item:hover { background: var(--fs-surface-3); color: var(--fs-text); }
.pr-nav__item:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: -2px; }
.pr-nav__item.is-active { background: var(--fs-primary-soft); color: var(--fs-primary-text); }
.pr-nav__item.is-active .bi { color: var(--fs-primary-text); }
.pr-nav__group {
  margin: 12px 12px 4px; font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.06em; color: var(--fs-muted);
}
.pr-nav__group:first-child { margin-top: 6px; }
.pr-stack { display: flex; flex-direction: column; gap: 20px; }
.pr-form-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
.pr-card__head--flat { padding-bottom: 0; margin-bottom: 0; border-bottom: 0; align-items: center; }
.pr-table { margin: 0 -24px -24px; border-top: 1px solid var(--fs-border); }
.pr-row-actions { justify-content: flex-end; }
.pr-switch--inline { margin: 4px 0 0; align-items: center; }
.pr-nav__dot { margin-left: auto; width: 8px; height: 8px; border-radius: 50%; background: var(--fs-warning); }

/* Cartes */
.pr-content { min-width: 0; }
.pr-card { padding: 24px; }
.pr-card__head {
  display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
  padding-bottom: 18px; margin-bottom: 20px; border-bottom: 1px solid var(--fs-border);
}
.pr-card__head h2 { margin: 0; font-size: var(--fs-text-lg); font-weight: 600; color: var(--fs-text); }
.pr-card__head p { margin: 4px 0 0; font-size: var(--fs-text-sm); color: var(--fs-muted); max-width: 640px; }
.pr-subtitle {
  margin: 24px 0 12px; font-size: var(--fs-text-xs); font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.05em; color: var(--fs-muted);
}
.pr-subtitle--first { margin-top: 0; }
.pr-warning--spaced { margin-top: 18px; }
.pr-apercu { margin: 0; height: 40px; display: flex; align-items: center; }
.pr-apercu code {
  padding: 6px 10px; border-radius: 8px; background: var(--fs-surface-3);
  font-size: var(--fs-text-base); font-weight: 600; color: var(--fs-text); letter-spacing: 0.02em;
}
.pr-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px; }
.pr-grid--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.pr-grid .pr-field--large { grid-column: 1 / -1; }
.pr-hint { display: block; margin: 0; font-size: var(--fs-text-xs); color: var(--fs-muted); font-weight: 400; }
.pr-content .pr-hint { margin: 0; font-size: var(--fs-text-xs); color: var(--fs-muted); }
.pr-content .pr-error, .pr-error { margin: 4px 0 0; font-size: var(--fs-text-xs); color: var(--fs-negative); }
.pr-content .fs-input[aria-invalid="true"] { border-color: var(--fs-negative); }

.pr-content .pr-input-unit { position: relative; }
.pr-content .pr-input-unit .fs-input { padding-right: 40px; }
.pr-content .pr-input-unit > span {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  font-size: var(--fs-text-sm); color: var(--fs-muted); pointer-events: none;
}

.pr-warning {
  display: flex; gap: 10px; align-items: flex-start;
  padding: 12px 14px; border-radius: var(--fs-radius-sm);
  background: var(--fs-warning-soft); color: var(--fs-text-2); font-size: var(--fs-text-sm);
}
.pr-warning .bi { color: var(--fs-warning); font-size: 16px; margin-top: 1px; }

/* Logo */
.pr-logo { display: flex; gap: 18px; align-items: center; margin-bottom: 24px; }
.pr-logo__preview {
  flex: none; width: 88px; height: 88px; border-radius: 12px;
  border: 1px dashed var(--fs-border-strong); background: var(--fs-surface-2);
  display: grid; place-items: center; overflow: hidden;
}
.pr-logo__preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.pr-logo__preview .bi { font-size: 28px; color: var(--fs-muted); }
.pr-logo__title { margin: 0 0 2px; font-size: var(--fs-text-base); font-weight: 600; color: var(--fs-text); }
.pr-logo__actions { display: flex; gap: 8px; margin-top: 10px; }

/* Interrupteur */
.pr-switch { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin-bottom: 22px; }
.pr-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.pr-switch__track {
  flex: none; position: relative; width: 40px; height: 22px; margin-top: 1px; border-radius: 999px;
  background: var(--fs-border-strong); transition: background-color 0.15s ease;
}
.pr-switch__track::after {
  content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2); transition: transform 0.15s ease;
}
.pr-switch input:checked + .pr-switch__track { background: var(--fs-primary); }
.pr-switch input:checked + .pr-switch__track::after { transform: translateX(18px); }
.pr-switch input:focus-visible + .pr-switch__track { outline: 2px solid var(--fs-primary); outline-offset: 2px; }
.pr-switch input:disabled + .pr-switch__track { opacity: 0.6; }
.pr-switch strong { display: block; font-size: var(--fs-text-base); font-weight: 600; color: var(--fs-text); }

/* Jalons */
.pr-jalons.is-disabled { opacity: 0.55; }
.pr-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pr-chip {
  display: inline-flex; align-items: center; gap: 4px; height: 32px; padding: 0 6px 0 12px;
  border-radius: 999px; background: var(--fs-primary-soft); color: var(--fs-primary-text);
  font-size: var(--fs-text-sm); font-weight: 600; font-variant-numeric: tabular-nums;
}
.pr-chip button {
  display: grid; place-items: center; width: 22px; height: 22px; border: 0; border-radius: 50%;
  background: transparent; color: inherit; cursor: pointer;
}
.pr-chip button:hover { background: var(--fs-primary); color: #fff; }
.pr-chips__add { display: inline-flex; gap: 6px; align-items: center; }
.pr-chips__add .fs-input { width: 90px; height: 32px; }

/* Seuils */
.pr-seuils { width: 100%; border-collapse: collapse; font-size: var(--fs-text-sm); }
.pr-seuils th {
  padding: 0 12px 10px; text-align: left; font-size: var(--fs-text-xs); font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--fs-muted); border-bottom: 1px solid var(--fs-border);
}
.pr-seuils td { padding: 12px; border-bottom: 1px solid var(--fs-border); vertical-align: top; }
.pr-seuils tr:last-child td { border-bottom: 0; }
.pr-seuils td strong { display: block; color: var(--fs-text); font-weight: 600; }
.pr-seuils td .pr-input-unit { max-width: 160px; }
.pr-seuils__else { color: var(--fs-muted); white-space: nowrap; padding-top: 22px !important; }
.pr-seuils__note { margin-top: 12px; }

/* Message */
.pr-toast {
  position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1100;
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 16px; border-radius: 10px; box-shadow: var(--fs-shadow-lg);
  font-size: var(--fs-text-sm); font-weight: 500; color: #fff;
}
.pr-toast--ok { background: var(--fs-success); }
.pr-toast--erreur { background: var(--fs-negative); }

@media (max-width: 1000px) {
  .pr-layout { grid-template-columns: 1fr; }
  .pr-nav { position: static; flex-direction: row; overflow-x: auto; }
  .pr-nav__item { width: auto; white-space: nowrap; }
}
@media (max-width: 700px) {
  .pr-grid, .pr-grid--3 { grid-template-columns: 1fr; }
  .pr-card { padding: 18px; }
}
</style>
