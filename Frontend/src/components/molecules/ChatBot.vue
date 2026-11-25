<template>
  <section class="as-panel" role="dialog" aria-label="Assistant comptable">
    <!-- En-tête -->
    <header class="as-head">
      <button
        type="button" class="as-icon-btn" :class="{ 'is-actif': vue === 'historique' }"
        :aria-pressed="vue === 'historique'" title="Conversations" aria-label="Conversations"
        @click="basculerHistorique"
      ><i class="bi bi-layout-sidebar"></i></button>
      <div class="as-head__titre">
        <span class="as-avatar" aria-hidden="true"><i class="bi bi-stars"></i></span>
        <div>
          <h2>Assistant</h2>
          <p>{{ vue === 'historique' ? 'Vos conversations' : titreConversation }}</p>
        </div>
      </div>
      <button type="button" class="as-icon-btn" title="Nouvelle conversation" aria-label="Nouvelle conversation" @click="nouvelleConversation">
        <i class="bi bi-pencil-square"></i>
      </button>
    </header>

    <!-- Historique des conversations -->
    <div v-if="vue === 'historique'" class="as-historique">
      <p v-if="chargementHistorique" class="as-vide">Chargement…</p>
      <p v-else-if="!conversations.length" class="as-vide">Aucune conversation pour l'instant.</p>
      <ul v-else class="as-convs">
        <li v-for="c in conversations" :key="c.session_id" :class="{ 'is-courante': c.session_id === sessionId }">
          <button type="button" class="as-conv" @click="ouvrirConversation(c.session_id)">
            <span class="as-conv__titre">{{ c.titre }}</span>
            <span class="as-conv__date">{{ dateRelative(c.date) }}</span>
          </button>
          <button type="button" class="as-icon-btn as-icon-btn--sm" title="Supprimer" :aria-label="`Supprimer ${c.titre}`" @click="supprimerConversation(c)">
            <i class="bi bi-trash3"></i>
          </button>
        </li>
      </ul>
    </div>

    <!-- Conversation -->
    <div v-else ref="fil" class="as-fil" aria-live="polite">
      <!-- Accueil -->
      <div v-if="!messages.length && !envoi" class="as-accueil">
        <span class="as-avatar as-avatar--grand" aria-hidden="true"><i class="bi bi-stars"></i></span>
        <h3>Comment puis-je vous aider ?</h3>
        <p>Je réponds à partir de vos écritures validées : soldes, résultat, trésorerie, pièces à valider, clôture.</p>
        <div class="as-cartes">
          <button v-for="s in SUGGESTIONS_ACCUEIL" :key="s.texte" type="button" class="as-carte" @click="envoyer(s.texte)">
            <i :class="['bi', s.icone]" aria-hidden="true"></i>
            <span>{{ s.texte }}</span>
          </button>
        </div>
      </div>

      <template v-for="m in messages" :key="m.id">
        <!-- Question -->
        <div v-if="m.role === 'user'" class="as-msg as-msg--user">
          <div class="as-bulle">{{ m.texte }}</div>
        </div>
        <!-- Réponse -->
        <div v-else class="as-msg as-msg--bot">
          <span class="as-avatar" aria-hidden="true"><i class="bi bi-stars"></i></span>
          <div class="as-reponse">
            <div class="as-texte" :class="{ 'is-erreur': m.erreur }" v-html="formater(m.texte)"></div>
            <div v-if="m.liens?.length" class="as-liens">
              <router-link v-for="l in m.liens" :key="l.url" :to="l.url" class="as-lien">
                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>{{ l.libelle }}
              </router-link>
            </div>
            <div class="as-actions">
              <button type="button" class="as-action" :title="m.copie ? 'Copié' : 'Copier'" @click="copier(m)">
                <i :class="m.copie ? 'bi bi-check2' : 'bi bi-copy'"></i>
              </button>
              <button type="button" class="as-action" :title="lecture === m.id ? 'Arrêter' : 'Écouter'" @click="ecouter(m)">
                <i :class="lecture === m.id ? 'bi bi-stop-circle' : 'bi bi-volume-up'"></i>
              </button>
              <button v-if="m.erreur && m.question" type="button" class="as-action as-action--texte" @click="reessayer(m)">
                <i class="bi bi-arrow-clockwise"></i> Réessayer
              </button>
            </div>
          </div>
        </div>
      </template>

      <!-- En cours de réponse -->
      <div v-if="envoi" class="as-msg as-msg--bot">
        <span class="as-avatar" aria-hidden="true"><i class="bi bi-stars"></i></span>
        <div class="as-ecrit" aria-label="L'assistant répond"><span></span><span></span><span></span></div>
      </div>

      <!-- Suggestions de suite -->
      <div v-if="suggestions.length && !envoi && messages.length" class="as-suggestions">
        <button v-for="s in suggestions" :key="s" type="button" class="as-chip" @click="envoyer(s)">{{ s }}</button>
      </div>
    </div>

    <!-- Saisie -->
    <form v-if="vue === 'conversation'" class="as-saisie" @submit.prevent="envoyer()">
      <div class="as-saisie__boite">
        <textarea
          ref="zone" v-model="brouillon" rows="1" maxlength="500"
          placeholder="Posez votre question…" aria-label="Votre question"
          @input="ajusterHauteur" @keydown.enter.exact.prevent="envoyer()"
        ></textarea>
        <div class="as-saisie__outils">
          <button
            type="button" class="as-icon-btn as-icon-btn--sm" :class="{ 'is-enregistre': dictee }"
            :title="dictee ? 'Arrêter la dictée' : 'Dicter'" :aria-label="dictee ? 'Arrêter la dictée' : 'Dicter'"
            @click="basculerDictee"
          ><i :class="dictee ? 'bi bi-stop-fill' : 'bi bi-mic'"></i></button>
          <button type="submit" class="as-envoyer" :disabled="!brouillon.trim() || envoi" aria-label="Envoyer">
            <i class="bi bi-arrow-up"></i>
          </button>
        </div>
      </div>
      <p class="as-saisie__aide">Entrée pour envoyer · Maj + Entrée pour aller à la ligne</p>
    </form>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';
import { API_BASE } from '@/config/api';

const CLE_SESSION = 'assistant_session';
const SUGGESTIONS_ACCUEIL = [
  { icone: 'bi-bank', texte: 'Combien en trésorerie ?' },
  { icone: 'bi-graph-up-arrow', texte: "Résultat de l'exercice" },
  { icone: 'bi-hourglass-split', texte: 'Pièces à valider' },
  { icone: 'bi-lock', texte: 'Que faire avant de clôturer ?' },
];

const vue = ref('conversation');
const sessionId = ref(localStorage.getItem(CLE_SESSION) || nouvelId());
const messages = ref([]);
const suggestions = ref([]);
const brouillon = ref('');
const envoi = ref(false);
const conversations = ref([]);
const chargementHistorique = ref(false);
const lecture = ref(null);
const dictee = ref(false);
const fil = ref(null);
const zone = ref(null);
let reconnaissance = null;
let compteur = 0;

const titreConversation = computed(() => {
  const premiere = messages.value.find(m => m.role === 'user');
  return premiere ? tronquer(premiere.texte, 40) : 'Nouvelle conversation';
});

function nouvelId() {
  return 'as_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
}
function tronquer(t, n) { return t.length > n ? t.slice(0, n - 1) + '…' : t; }
function idMessage() { return ++compteur; }

// ---- Mise en forme (texte échappé, puis **gras**, listes « • » et retours à la ligne)
function echapper(t) {
  return String(t ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function formater(texte) {
  const gras = s => s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
  const blocs = [];
  let liste = null;
  for (const ligne of echapper(texte).split('\n')) {
    const puce = ligne.match(/^\s*•\s?(.*)$/);
    if (puce) {
      (liste ??= []).push(`<li>${gras(puce[1])}</li>`);
      continue;
    }
    if (liste) { blocs.push(`<ul>${liste.join('')}</ul>`); liste = null; }
    blocs.push(ligne.trim() === '' ? '<br>' : `<p>${gras(ligne)}</p>`);
  }
  if (liste) blocs.push(`<ul>${liste.join('')}</ul>`);
  return blocs.join('').replace(/(<br>)+/g, '');
}

function dateRelative(d) {
  const date = new Date(d);
  const jours = Math.floor((Date.now() - date.getTime()) / 86400000);
  if (jours <= 0) return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  if (jours === 1) return 'Hier';
  if (jours < 7) return `Il y a ${jours} jours`;
  return date.toLocaleDateString('fr-FR');
}

async function defiler() {
  await nextTick();
  if (fil.value) fil.value.scrollTop = fil.value.scrollHeight;
}
function ajusterHauteur() {
  const z = zone.value;
  if (!z) return;
  z.style.height = 'auto';
  z.style.height = Math.min(z.scrollHeight, 140) + 'px';
}

// ---- Liens : uniquement vers les écrans de l'application
const liensInternes = liens => (liens || []).filter(l => typeof l?.url === 'string' && l.url.startsWith('/'));

// ---- Envoi
async function envoyer(texte) {
  const question = (texte ?? brouillon.value).trim();
  if (!question || envoi.value) return;
  brouillon.value = '';
  ajusterHauteur();
  suggestions.value = [];
  messages.value.push({ id: idMessage(), role: 'user', texte: question });
  envoi.value = true;
  defiler();
  try {
    const { data } = await axios.post(`${API_BASE}/chat/sending`, { message: question, session_id: sessionId.value });
    localStorage.setItem(CLE_SESSION, sessionId.value);
    messages.value.push({ id: idMessage(), role: 'bot', texte: data.response, liens: liensInternes(data.liens) });
    suggestions.value = data.suggestions || [];
  } catch (e) {
    messages.value.push({
      id: idMessage(), role: 'bot', erreur: true, question,
      texte: e.response?.status === 401 ? 'Votre session a expiré : reconnectez-vous.' : "Je n'ai pas pu obtenir de réponse. Vérifiez la connexion puis réessayez.",
    });
  } finally {
    envoi.value = false;
    defiler();
    zone.value?.focus();
  }
}
function reessayer(m) {
  messages.value = messages.value.filter(x => x.id !== m.id);
  const derniere = messages.value.at(-1);
  if (derniere?.role === 'user' && derniere.texte === m.question) messages.value.pop();
  envoyer(m.question);
}

// ---- Conversations
async function chargerConversation(id) {
  try {
    const { data } = await axios.get(`${API_BASE}/chat/history/${encodeURIComponent(id)}`);
    messages.value = data.flatMap(m => [
      { id: idMessage(), role: 'user', texte: m.message },
      ...(m.response ? [{ id: idMessage(), role: 'bot', texte: m.response, liens: liensInternes(m.liens) }] : []),
    ]);
  } catch {
    messages.value = [];
  }
  suggestions.value = [];
  defiler();
}
function nouvelleConversation() {
  arreterLecture();
  sessionId.value = nouvelId();
  localStorage.removeItem(CLE_SESSION);
  messages.value = [];
  suggestions.value = [];
  vue.value = 'conversation';
  nextTick(() => zone.value?.focus());
}
async function basculerHistorique() {
  if (vue.value === 'historique') { vue.value = 'conversation'; return; }
  vue.value = 'historique';
  chargementHistorique.value = true;
  try {
    conversations.value = (await axios.get(`${API_BASE}/chat/conversations`)).data;
  } catch {
    conversations.value = [];
  } finally {
    chargementHistorique.value = false;
  }
}
async function ouvrirConversation(id) {
  arreterLecture();
  sessionId.value = id;
  localStorage.setItem(CLE_SESSION, id);
  vue.value = 'conversation';
  await chargerConversation(id);
}
async function supprimerConversation(c) {
  if (!confirm(`Supprimer la conversation « ${c.titre} » ?`)) return;
  await axios.delete(`${API_BASE}/chat/conversations/${encodeURIComponent(c.session_id)}`).catch(() => {});
  conversations.value = conversations.value.filter(x => x.session_id !== c.session_id);
  if (c.session_id === sessionId.value) {
    sessionId.value = nouvelId();
    localStorage.removeItem(CLE_SESSION);
    messages.value = [];
  }
}

// ---- Copier / écouter
async function copier(m) {
  const texte = m.texte.replace(/\*\*/g, '');
  try {
    await navigator.clipboard.writeText(texte);
    m.copie = true;
    setTimeout(() => { m.copie = false; }, 1500);
  } catch { /* presse-papiers indisponible */ }
}
function arreterLecture() {
  if ('speechSynthesis' in window) window.speechSynthesis.cancel();
  lecture.value = null;
}
function ecouter(m) {
  if (!('speechSynthesis' in window)) return;
  if (lecture.value === m.id) { arreterLecture(); return; }
  window.speechSynthesis.cancel();
  const texte = m.texte.replace(/\*\*/g, '').replace(/•/g, '').replace(/\s+/g, ' ');
  const u = new SpeechSynthesisUtterance(texte);
  u.lang = 'fr-FR';
  u.onend = () => { if (lecture.value === m.id) lecture.value = null; };
  lecture.value = m.id;
  window.speechSynthesis.speak(u);
}

// ---- Dictée
function basculerDictee() {
  if (dictee.value) { reconnaissance?.stop(); dictee.value = false; return; }
  const Reco = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!Reco) { alert('La dictée vocale n\'est pas disponible dans ce navigateur.'); return; }
  reconnaissance = new Reco();
  reconnaissance.lang = 'fr-FR';
  reconnaissance.interimResults = false;
  reconnaissance.onresult = e => { brouillon.value = e.results[0][0].transcript; nextTick(ajusterHauteur); };
  reconnaissance.onend = () => { dictee.value = false; };
  reconnaissance.onerror = () => { dictee.value = false; };
  dictee.value = true;
  reconnaissance.start();
}

onMounted(() => {
  // La conversation en cours est conservée d'une page à l'autre
  if (localStorage.getItem(CLE_SESSION)) chargerConversation(sessionId.value);
  nextTick(() => zone.value?.focus());
});
onBeforeUnmount(() => {
  arreterLecture();
  reconnaissance?.stop();
});
</script>

<style scoped>
.as-panel {
  /* Au-dessus du bouton flottant (.fs-chat-btn : 52 px à 32 px du bord) */
  position: fixed; right: 32px; bottom: 96px; z-index: 1000;
  width: min(440px, calc(100vw - 32px)); height: min(680px, calc(100vh - 128px));
  display: flex; flex-direction: column;
  background: var(--fs-surface); color: var(--fs-text);
  border: 1px solid var(--fs-border); border-radius: 18px;
  box-shadow: var(--fs-shadow-xl, 0 24px 48px rgba(0, 0, 0, .18));
  font-family: var(--fs-font); overflow: hidden;
}

/* En-tête */
.as-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--fs-border); }
.as-head__titre { flex: 1; display: flex; align-items: center; gap: 10px; min-width: 0; }
.as-head__titre h2 { margin: 0; font-size: 14.5px; font-weight: 600; }
.as-head__titre p { margin: 0; font-size: 12px; color: var(--fs-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.as-avatar {
  flex: none; display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 50%;
  background: var(--fs-primary-soft); color: var(--fs-primary-text, var(--fs-primary)); font-size: 14px;
}
.as-avatar--grand { width: 48px; height: 48px; font-size: 22px; }
.as-icon-btn {
  flex: none; display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 9px;
  border: 0; background: transparent; color: var(--fs-text-2); cursor: pointer; font-size: 16px;
}
.as-icon-btn:hover, .as-icon-btn.is-actif { background: var(--fs-surface-3); color: var(--fs-text); }
.as-icon-btn:focus-visible, .as-action:focus-visible, .as-envoyer:focus-visible { outline: 2px solid var(--fs-primary); outline-offset: 1px; }
.as-icon-btn--sm { width: 30px; height: 30px; font-size: 14px; }
.as-icon-btn.is-enregistre { color: var(--fs-negative, #dc2626); background: var(--fs-negative-soft, #fee2e2); }

/* Historique */
.as-historique { flex: 1; overflow-y: auto; padding: 8px; }
.as-convs { list-style: none; margin: 0; padding: 0; }
.as-convs li { display: flex; align-items: center; gap: 4px; border-radius: 10px; }
.as-convs li:hover, .as-convs li.is-courante { background: var(--fs-surface-3); }
.as-conv { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; padding: 9px 10px; border: 0; background: transparent; text-align: left; cursor: pointer; color: var(--fs-text); font: inherit; }
.as-conv__titre { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.as-conv__date { font-size: 11.5px; color: var(--fs-muted); }
.as-vide { margin: 24px 12px; text-align: center; font-size: 13px; color: var(--fs-muted); }

/* Fil de conversation */
.as-fil { flex: 1; overflow-y: auto; padding: 16px 14px 8px; display: flex; flex-direction: column; gap: 14px; scroll-behavior: smooth; }
.as-accueil { margin: auto 0; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 8px; }
.as-accueil h3 { margin: 6px 0 0; font-size: 18px; font-weight: 600; }
.as-accueil p { margin: 0 0 8px; font-size: 13px; color: var(--fs-muted); max-width: 320px; }
.as-cartes { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; width: 100%; }
.as-carte {
  display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px; text-align: left;
  border: 1px solid var(--fs-border); border-radius: 12px; background: var(--fs-surface); color: var(--fs-text);
  font: 500 13px var(--fs-font); cursor: pointer; transition: background .15s, border-color .15s;
}
.as-carte i { color: var(--fs-primary); font-size: 16px; }
.as-carte:hover { background: var(--fs-surface-2, var(--fs-surface-3)); border-color: var(--fs-border-strong, var(--fs-border)); }

.as-msg { display: flex; gap: 10px; }
.as-msg--user { justify-content: flex-end; }
.as-bulle {
  max-width: 85%; padding: 9px 13px; border-radius: 16px 16px 4px 16px;
  background: var(--fs-primary); color: var(--fs-on-primary, #fff); font-size: 14px; line-height: 1.45;
  white-space: pre-wrap; word-break: break-word;
}
.as-reponse { flex: 1; min-width: 0; }
.as-texte { font-size: 14px; line-height: 1.55; word-break: break-word; }
.as-texte :deep(p) { margin: 0 0 6px; }
.as-texte :deep(ul) { margin: 2px 0 8px; padding-left: 18px; }
.as-texte :deep(li) { margin: 2px 0; }
.as-texte :deep(strong) { font-weight: 600; }
.as-texte.is-erreur { color: var(--fs-negative, #dc2626); }
.as-liens { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.as-lien {
  display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px;
  border: 1px solid var(--fs-border); font-size: 12.5px; color: var(--fs-primary); text-decoration: none; background: var(--fs-surface);
}
.as-lien:hover { background: var(--fs-primary-soft); }
.as-actions { display: flex; gap: 2px; margin-top: 4px; opacity: .55; transition: opacity .15s; }
.as-msg--bot:hover .as-actions, .as-actions:focus-within { opacity: 1; }
.as-action {
  display: inline-flex; align-items: center; gap: 4px; height: 26px; min-width: 26px; padding: 0 6px; border-radius: 7px;
  border: 0; background: transparent; color: var(--fs-muted); cursor: pointer; font-size: 13px;
}
.as-action:hover { background: var(--fs-surface-3); color: var(--fs-text); }
.as-action--texte { font: 500 12px var(--fs-font); }

.as-ecrit { display: inline-flex; gap: 4px; align-items: center; padding: 10px 2px; }
.as-ecrit span { width: 7px; height: 7px; border-radius: 50%; background: var(--fs-muted); animation: as-pulse 1.2s infinite ease-in-out; }
.as-ecrit span:nth-child(2) { animation-delay: .15s; }
.as-ecrit span:nth-child(3) { animation-delay: .3s; }
@keyframes as-pulse { 0%, 80%, 100% { opacity: .25; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-3px); } }

.as-suggestions { display: flex; flex-wrap: wrap; gap: 6px; padding-left: 38px; }
.as-chip {
  padding: 6px 11px; border-radius: 999px; border: 1px solid var(--fs-border); background: var(--fs-surface);
  color: var(--fs-text-2); font: 500 12.5px var(--fs-font); cursor: pointer;
}
.as-chip:hover { border-color: var(--fs-primary); color: var(--fs-primary); }

/* Saisie */
.as-saisie { padding: 8px 12px 10px; }
.as-saisie__boite {
  display: flex; align-items: flex-end; gap: 6px; padding: 6px 6px 6px 12px;
  border: 1px solid var(--fs-border); border-radius: 14px; background: var(--fs-surface);
  transition: border-color .15s, box-shadow .15s;
}
.as-saisie__boite:focus-within { border-color: var(--fs-primary); box-shadow: 0 0 0 3px var(--fs-primary-soft); }
.as-saisie textarea {
  flex: 1; min-height: 24px; max-height: 140px; padding: 6px 0; resize: none; border: 0; outline: 0;
  background: transparent; color: var(--fs-text); font: 14px/1.45 var(--fs-font);
}
.as-saisie textarea::placeholder { color: var(--fs-muted); }
.as-saisie__outils { display: flex; align-items: center; gap: 4px; }
.as-envoyer {
  display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; border: 0;
  background: var(--fs-primary); color: var(--fs-on-primary, #fff); cursor: pointer; font-size: 16px;
}
.as-envoyer:disabled { background: var(--fs-surface-3); color: var(--fs-muted); cursor: default; }
.as-saisie__aide { margin: 5px 2px 0; font-size: 11px; color: var(--fs-muted); text-align: center; }

@media (max-width: 520px) {
  .as-panel { right: 8px; left: 8px; width: auto; bottom: 88px; height: calc(100vh - 110px); }
}
@media (prefers-reduced-motion: reduce) {
  .as-ecrit span { animation: none; opacity: .6; }
  .as-fil { scroll-behavior: auto; }
}
</style>
