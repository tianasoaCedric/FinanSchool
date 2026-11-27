/* =====================================================================
   Export PDF commun des états financiers (pages/general).
   Un seul gabarit : même en-tête, même typographie, mêmes styles de
   lignes (section / titre / sous-titre / sous-total / total / détail),
   même pied de page, en portrait comme en paysage.

   Usage :
     await exporterEtatPdf({
       titre: 'Bilan — Actif',
       infos: [['Exercice', '2025'], ['Période', 'Du … au …']],
       entetes: [[{ label: 'Actif', rowspan: 2 }, { label: 'Exercice N', colspan: 3 }], [...]],
       lignes: [{ type: 'title', cellules: ['Actif immobilisé', '', 1200] }, ...],
       alignements: ['left', 'center', 'right', ...],   // par colonne
       orientation: 'portrait' | 'landscape',
       fichier: 'Bilan_Actif',
     });
   Une cellule peut être une valeur brute ou { v, negatif, gras }.
   ===================================================================== */
import html2pdf from 'html2pdf.js';
import { parametres } from '@/stores/parametres';

// Coordonnées, logo et devise : écran « Paramètres » (store parametres)
let ETABLISSEMENT = { nom: "", adresse: "", tel: "", email: "", identifiants: "" };
let DEVISE = { code: "MGA", symbole: "Ar" };

// Palette d'impression (fond blanc, contraste suffisant en N&B)
const C = {
  primaire: '#1c45bd',
  texte: '#0f172a',
  texte2: '#334155',
  discret: '#64748b',
  bordure: '#e2e8f0',
  negatif: '#dc2626',
};

// Styles de ligne : un seul jeu pour tous les états
const STYLE_LIGNE = {
  section:  `background:${C.primaire};color:#fff;font-weight:700;`,
  title:    `background:#e8eefb;color:#1e3a8a;font-weight:700;`,
  subtitle: `background:#f5f7fc;color:${C.texte2};font-weight:600;`,
  subtotal: `background:#f1f4f9;color:${C.texte};font-weight:600;`,
  total:    `background:#dde5f8;color:#1e3a8a;font-weight:700;border-top:1.5px solid ${C.primaire};`,
  detail:   `color:${C.texte2};`,
};
const RETRAIT = { section: 0, title: 0, subtitle: 12, subtotal: 0, total: 0, detail: 24 };

// Largeur utile en px (A4, marges 10 mm) : 190 mm / 277 mm à 96 dpi
const LARGEUR = { portrait: 718, landscape: 1047 };

let logoCache = null;
let logoSource = null;
async function chargerLogo(url) {
  if (url === logoSource && logoCache !== null) return logoCache;
  logoSource = url;
  if (!url || url.startsWith("data:")) return (logoCache = url || "");
  try {
    const blob = await (await fetch(url)).blob();
    logoCache = await new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onloadend = () => resolve(r.result);
      r.onerror = reject;
      r.readAsDataURL(blob);
    });
  } catch {
    logoCache = '';
  }
  return logoCache;
}

const esc = v => String(v ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function cellule(c, align, type, idx) {
  const o = c !== null && typeof c === 'object' ? c : { v: c };
  const pad = idx === 0 ? `padding-left:${8 + (RETRAIT[type] ?? 0)}px;` : '';
  const neg = o.negatif && type !== 'section' ? `color:${C.negatif};` : '';
  const gras = o.gras ? 'font-weight:700;' : '';
  const num = align === 'right' ? 'white-space:nowrap;font-variant-numeric:tabular-nums;' : '';
  return `<td style="padding:5px 8px;${pad}text-align:${align};border-bottom:1px solid ${C.bordure};${num}${neg}${gras}">${esc(o.v)}</td>`;
}

function entete(titre, sousTitre, infos, now) {
  const meta = infos
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `<span style="white-space:nowrap;"><span style="color:${C.discret};">${esc(k)} :</span> ${esc(v)}</span>`)
    .join(`<span style="color:${C.bordure};margin:0 6px;">|</span>`);
  return `
    <div style="display:flex;align-items:center;gap:14px;padding-bottom:10px;border-bottom:2px solid ${C.primaire};">
      <div style="flex:0 0 auto;display:flex;align-items:center;gap:10px;min-width:150px;">
        ${logoCache ? `<img src="${logoCache}" alt="" style="width:46px;height:auto;" />` : ''}
        <div style="font-size:8px;line-height:1.4;color:${C.discret};">
          <div style="font-size:10px;font-weight:700;color:${C.texte};">${esc(ETABLISSEMENT.nom)}</div>
          <div>${esc(ETABLISSEMENT.adresse)}</div>
          ${ETABLISSEMENT.tel ? `<div>${esc(ETABLISSEMENT.tel)}</div>` : ''}
          ${ETABLISSEMENT.identifiants ? `<div>${esc(ETABLISSEMENT.identifiants)}</div>` : ''}
        </div>
      </div>
      <div style="flex:1;text-align:center;">
        <div style="font-size:16px;font-weight:700;color:${C.primaire};letter-spacing:-0.01em;">${esc(titre)}</div>
        ${sousTitre ? `<div style="font-size:9px;color:${C.discret};margin-top:2px;">${esc(sousTitre)}</div>` : ''}
      </div>
      <div style="flex:0 0 auto;min-width:150px;text-align:right;font-size:8px;line-height:1.4;color:${C.discret};">
        <div>Édité le</div>
        <div style="color:${C.texte};font-weight:600;">${now.toLocaleDateString('fr-FR')} à ${now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
      </div>
    </div>
    ${meta ? `<div style="font-size:8.5px;color:${C.texte2};padding:7px 0 0;text-align:center;">${meta}</div>` : ''}`;
}

export async function exporterEtatPdf({
  titre,
  sousTitre = '',
  infos = [],
  entetes,
  lignes,
  alignements = [],
  orientation = 'portrait',
  fichier,
}) {
  const st = await parametres();
  ETABLISSEMENT = st.societe;
  DEVISE = st.devise;
  await chargerLogo(st.societe.logo);
  const now = new Date();
  const largeur = LARGEUR[orientation] || LARGEUR.portrait;

  const thead = entetes.map(rang => `<tr>${rang.map(h => `
      <th ${h.colspan ? `colspan="${h.colspan}"` : ''} ${h.rowspan ? `rowspan="${h.rowspan}"` : ''}
        style="padding:6px 8px;background:#f1f4f9;color:${C.texte2};font-size:7.5px;font-weight:700;
               text-transform:uppercase;letter-spacing:0.04em;text-align:${h.align || (h.colspan ? 'center' : 'left')};
               border-bottom:1px solid #cbd5e1;border-top:1px solid #cbd5e1;">${esc(h.label)}</th>`).join('')}</tr>`).join('');

  const tbody = lignes.map(l => {
    const type = l.type || 'detail';
    return `<tr style="page-break-inside:avoid;${STYLE_LIGNE[type] || STYLE_LIGNE.detail}">${
      l.cellules.map((c, i) => cellule(c, alignements[i] || (i === 0 ? 'left' : 'right'), type, i)).join('')
    }</tr>`;
  }).join('');

  const html = `
    <div style="font-family:'Inter Variable','Inter',Arial,sans-serif;color:${C.texte};width:${largeur}px;font-size:8.5px;line-height:1.35;">
      ${entete(titre, sousTitre, infos, now)}
      <table style="width:100%;border-collapse:collapse;margin-top:10px;">
        <thead>${thead}</thead>
        <tbody>${tbody}</tbody>
      </table>
      <div style="margin-top:12px;padding-top:6px;border-top:1px solid ${C.bordure};display:flex;justify-content:space-between;font-size:7.5px;color:${C.discret};">
        <span>${esc(ETABLISSEMENT.nom)} © ${now.getFullYear()}</span>
        <span>${esc(titre)} — montants en ${esc(DEVISE.code)} (${esc(DEVISE.symbole)})</span>
      </div>
    </div>`;

  const el = document.createElement('div');
  el.innerHTML = html;
  document.body.appendChild(el);
  try {
    await html2pdf()
      .set({
        margin: [10, 10, 12, 10],
        filename: `${fichier}_${now.toISOString().split('T')[0]}.pdf`,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF: { unit: 'mm', format: 'a4', orientation },
        pagebreak: { mode: ['css', 'legacy'] },
      })
      .from(el.firstElementChild)
      .save();
  } catch (err) {
    console.error('Erreur PDF :', err);
    alert('Erreur lors de la génération du PDF.');
  } finally {
    document.body.removeChild(el);
  }
}

/** Type de ligne à partir des drapeaux renvoyés par l'API (isSection, isTitle…). */
export function typeLigne(l) {
  if (l.isSection) return 'section';
  if (l.isTitle) return 'title';
  if (l.isSubtitle) return 'subtitle';
  if (l.isTotal) return 'total';
  if (l.isSubtotal) return 'subtotal';
  return 'detail';
}

/** Libellés communs « Exercice / Période / Statut » à partir d'exerciceInfo. */
export function infosExercice(info, formatDate) {
  return [
    ['Exercice', info?.annee_fiscale],
    ['Période', info?.date_debut ? `du ${formatDate(info.date_debut)} au ${formatDate(info.date_fin)}` : ''],
    ['Statut', info?.statut],
  ];
}
