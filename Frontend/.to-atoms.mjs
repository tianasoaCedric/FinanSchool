// Remplace boutons et badges des templates par <UiButton> / <UiBadge>.
// Usage (conteneur frontend) : node to-atoms.mjs [--dry] fichier.vue ...
import fs from 'node:fs';
import { parse } from '@vue/compiler-dom';

const args = process.argv.slice(2);
const dry = args.includes('--dry');
const files = args.filter(a => a !== '--dry');

const SPECIAL_BTN = ['modal-close', 'modal-close-btn', 'doc-close-btn', 'doc-action-btn', 'sim-action-btn', 'alert-dismiss', 'fs-link'];
const DROP_BTN_CLASS = /^(btn(-[\w-]+)?|fs-btn(--[\w-]+)?|modal-close(-btn)?|doc-(action|close)-btn|sim-action-btn|view|delete|alert-dismiss|fs-link|text-(base|sm|xs|xl|error|primary|gray-\d+)|hover:[\w:-]+|font-(semibold|medium|bold)|h-\d+|px-\d+|py-\d+|flex|items-center|gap-\d|shadow-sm|w-full|ml-2|mt-2)$/;
const DROP_BADGE_CLASS = /^(badge(-[\w-]+)?|fs-badge(--[\w-]+)?|nature-badge|type-badge)$/;
const isClose = s => /^\s*(×|&times;|✖|x)\s*$/i.test(s);

const staticAttr = (n, name) => n.props.find(p => p.type === 6 && p.name === name);
const tokensOf = n => (staticAttr(n, 'class')?.value?.content || '').split(/\s+/).filter(Boolean);
const textOf = n => (n.type === 2 ? n.content : n.type === 5 ? '{{}}' : (n.children || []).map(textOf).join(''));

function startTagEnd(src, from) {
  let q = null;
  for (let i = from + 1; i < src.length; i++) {
    const c = src[i];
    if (q) { if (c === q) q = null; continue; }
    if (c === '"' || c === "'") q = c;
    else if (c === '>') return i + 1;
  }
  throw new Error('fin de balise introuvable');
}

function keepMargins(style) {
  const kept = style.split(';').map(s => s.trim()).filter(s => /^margin(-(left|right))?\s*:/i.test(s));
  return kept.length ? kept.join('; ') + ';' : '';
}

let totalB = 0, totalG = 0;
for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const open = src.indexOf('<template>');
  const scriptIdx = src.search(/^<script\b/m);
  const tplEnd = scriptIdx > open ? src.lastIndexOf('</template>', scriptIdx) : src.lastIndexOf('</template>');
  const base = open + '<template>'.length;
  const inner = src.slice(base, tplEnd);
  const ast = parse(inner);
  const edits = []; // [start, end, text] relatifs à inner
  let nb = 0, ng = 0;

  const attrsExcept = (n, skip) => n.props
    .filter(p => !(p.type === 6 && skip.includes(p.name)))
    .map(p => p.loc.source);

  const replaceTags = (n, newOpen, newTag, innerOverride) => {
    const s = n.loc.start.offset;
    const tagEnd = startTagEnd(inner, s);
    const closeTag = `</${n.tag}>`;
    const e = n.loc.end.offset;
    if (inner.slice(e - closeTag.length, e) !== closeTag) throw new Error(`balise fermante inattendue (${n.tag}) dans ${file}`);
    if (innerOverride != null) {
      edits.push([s, e, `${newOpen}${innerOverride}</${newTag}>`]);
    } else {
      edits.push([s, tagEnd, newOpen]);
      edits.push([e - closeTag.length, e, `</${newTag}>`]);
    }
  };

  const walk = (node, anc) => {
    for (const n of node.children || []) {
      if (n.type !== 1) continue;
      const t = tokensOf(n);
      const inTable = anc.some(a => a.tag === 'td' || a.tag === 'th');
      const inForm = anc.some(a => a.tag === 'form');
      const content = textOf(n);

      // ---------- Boutons ----------
      const isBtnTag = ['button', 'router-link', 'a'].includes(n.tag);
      const btnLike = isBtnTag && (t.includes('btn') || t.some(x => /^fs-btn/.test(x) && x !== 'fs-chat-btn') || t.some(x => SPECIAL_BTN.includes(x))
        || (n.tag === 'button' && t.includes('text-xl') && isClose(content)));
      if (btnLike && !t.includes('fs-chat-btn')) {
        let variant = 'secondary', size = 'md', block = false, iconOnly = false;
        if (t.some(x => ['btn-primary', 'fs-btn--primary'].includes(x))) variant = 'primary';
        else if (t.includes('btn-success')) variant = 'success';
        else if (t.includes('btn-warning')) variant = 'warning';
        else if (t.some(x => ['btn-error', 'btn-danger'].includes(x))) variant = 'danger';
        else if (t.some(x => ['btn-ghost', 'fs-btn--ghost'].includes(x))) variant = t.includes('text-error') ? 'soft-danger' : 'ghost';
        else if (t.includes('fs-link')) variant = 'link';
        if (t.some(x => ['modal-close', 'modal-close-btn', 'doc-close-btn', 'alert-dismiss'].includes(x)) || (t.includes('text-xl') && isClose(content))) {
          variant = anc.some(a => tokensOf(a).includes('premium-header')) ? 'inverse' : 'ghost';
          iconOnly = true;
          if (t.includes('alert-dismiss')) size = 'sm';
        }
        if (t.includes('doc-action-btn')) { variant = 'secondary'; size = 'sm'; }
        if (t.includes('sim-action-btn')) { variant = t.includes('delete') ? 'soft-danger' : 'soft-primary'; size = 'sm'; iconOnly = true; }
        if (t.some(x => ['btn-sm', 'btn-xs'].includes(x))) size = 'sm';
        if (t.includes('btn-lg')) size = 'lg';
        if (t.some(x => ['fs-btn--block', 'w-full'].includes(x))) block = true;
        if (t.includes('fs-btn--icon')) iconOnly = true;
        if (inTable) {
          size = 'sm';
          if (variant === 'primary') variant = 'soft-primary';
          else if (variant === 'danger') variant = 'soft-danger';
          else if (variant === 'success') variant = 'soft-success';
        }

        const keepCls = t.filter(x => !DROP_BTN_CLASS.test(x));
        const attrs = attrsExcept(n, ['class', 'style', 'type']);
        if (variant !== 'secondary') attrs.unshift(`variant="${variant}"`);
        if (size !== 'md') attrs.push(`size="${size}"`);
        if (block) attrs.push('block');
        if (keepCls.length) attrs.push(`class="${keepCls.join(' ')}"`);
        const style = staticAttr(n, 'style')?.value?.content;
        const kept = style ? keepMargins(style) : '';
        if (kept) attrs.push(`style="${kept}"`);
        const type = staticAttr(n, 'type')?.value?.content;
        if (n.tag === 'button') {
          if (type && type !== 'button') attrs.push(`type="${type}"`);
          else if (!type && inForm) attrs.push('type="submit"');
        }
        let override = null;
        if (iconOnly && isClose(content)) {
          override = '<i class="bi bi-x-lg"></i>';
          if (!n.props.some(p => p.name === 'aria-label')) attrs.push('aria-label="Fermer"');
        }
        if (iconOnly && !n.props.some(p => p.name === 'aria-label' || p.name === 'title') && !attrs.some(a => a.startsWith('aria-label'))) {
          // libellé accessible à partir du title éventuel, sinon générique
          attrs.push('aria-label="Action"');
        }
        if (iconOnly) attrs.push('icon-only');
        replaceTags(n, `<UiButton ${attrs.join(' ')}>`, 'UiButton', override);
        nb++;
        continue; // pas de bouton imbriqué
      }

      // ---------- Badges ----------
      const dynClass = n.props.find(p => p.type === 7 && p.name === 'bind' && p.arg?.content === 'class');
      const isBadge = (t.some(x => x === 'badge' || x === 'fs-badge' || x === 'nature-badge'))
        || (dynClass && /'type-badge'/.test(dynClass.exp.content));
      if (isBadge && ['span', 'div'].includes(n.tag)) {
        let tone = 'neutral';
        if (t.some(x => ['badge-success', 'fs-badge--success'].includes(x))) tone = 'success';
        else if (t.some(x => ['badge-error', 'badge-danger'].includes(x))) tone = 'danger';
        else if (t.includes('badge-warning')) tone = 'warning';
        else if (t.includes('badge-primary')) tone = 'primary';
        else if (t.includes('badge-info')) tone = 'info';
        const attrs = attrsExcept(n, ['class', 'style']).filter(a => !a.startsWith(':class'));
        let toneAttr = `tone="${tone}"`;
        if (dynClass) {
          const exp = dynClass.exp.content;
          const m = exp.match(/(['"])type-badge\1\s*,\s*(.+?)\s*\?\s*'type-produit'\s*:\s*'type-charge'/);
          const m2 = exp.match(/\{\s*'fs-badge--success'\s*:\s*(.+?)\s*\}/);
          if (m) toneAttr = `:tone="${m[2]} ? 'success' : 'danger'"`;
          else if (m2) toneAttr = `:tone="${m2[1].replace(/"/g, "'")} ? 'success' : 'neutral'"`;
          else { console.log(`  ! classe dynamique non gérée, badge ignoré : ${exp}`); walk(n, [...anc, n]); continue; }
        }
        attrs.unshift(toneAttr);
        if (t.includes('stat-value')) attrs.push('size="lg"');
        if (t.includes('fs-badge')) attrs.push('dot');
        const keepCls = t.filter(x => !DROP_BADGE_CLASS.test(x));
        if (keepCls.length) attrs.push(`class="${keepCls.join(' ')}"`);
        const style = staticAttr(n, 'style')?.value?.content;
        const kept = style ? keepMargins(style) : '';
        if (kept) attrs.push(`style="${kept}"`);
        replaceTags(n, `<UiBadge ${attrs.join(' ')}>`, 'UiBadge');
        ng++;
        walk(n, [...anc, n]);
        continue;
      }
      walk(n, [...anc, n]);
    }
  };
  walk(ast, []);

  edits.sort((a, b) => b[0] - a[0]);
  let out = inner;
  let last = Infinity;
  for (const [s, e, txt] of edits) {
    if (e > last) throw new Error(`modifications qui se chevauchent dans ${file}`);
    out = out.slice(0, s) + txt + out.slice(e);
    last = s;
  }
  if (!dry && (nb || ng)) fs.writeFileSync(file, src.slice(0, base) + out + src.slice(tplEnd), 'utf8');
  totalB += nb; totalG += ng;
  console.log(`${String(nb).padStart(3)} boutons ${String(ng).padStart(3)} badges  ${file.split('/src/')[1]}`);
}
console.log(`\nTotal : ${totalB} boutons, ${totalG} badges${dry ? ' (simulation)' : ''}`);
