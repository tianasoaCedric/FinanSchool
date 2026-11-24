// Captures d'écran des pages de l'app (outil de vérification visuelle, dev only).
// Lancé dans le conteneur Playwright partageant le réseau du conteneur frontend :
//   localhost:5173 -> Vite ; les appels à localhost:8000 sont redirigés vers backend:8000.
// Usage : node shot.mjs <outDir> <theme light|dark> <path> [<path> ...]
import { chromium } from 'playwright';

const [outDir, theme, ...paths] = process.argv.slice(2);
const EMAIL = process.env.SHOT_EMAIL;
const PASSWORD = process.env.SHOT_PASSWORD;

const res = await fetch('http://backend:8000/api/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
});
const { token } = await res.json();
if (!token) throw new Error('Connexion impossible : pas de token');

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await context.addInitScript(([t, th]) => {
  localStorage.setItem('token', t);
  localStorage.setItem('fs-theme', th);
}, [token, theme]);
// SHOT_MOCK_SIM=1 : renvoie des simulations fictives (aucune écriture en base),
// pour pouvoir capturer la liste et le rapport même si la base n'en contient pas.
const MOCK_SIM = {
  id_simulation: 1,
  nom_simulation: 'Budget 2027 — croissance modérée',
  description: 'Hausse de 5 % des effectifs, revalorisation des salaires de 3 %.',
  date_simulation: '2026-11-20',
  lignes: [
    { libelle: 'Frais de scolarité', type: 'produit', nature_charge: 'variable', moyenne_historique: '6530833.33', coefficient: 1.05, montant_simule: '6857375' },
    { libelle: 'Salaire maîtresse', type: 'charge', nature_charge: 'fixe', moyenne_historique: '-1000000', coefficient: 1.03, montant_simule: '-1030000' },
    { libelle: 'Salaire gardien', type: 'charge', nature_charge: 'fixe', moyenne_historique: '-720000', coefficient: 1.03, montant_simule: '-741600' },
    { libelle: 'Fournitures pédagogiques', type: 'charge', nature_charge: 'variable', moyenne_historique: '-147250', coefficient: 1.05, montant_simule: '-154612' },
  ],
};
await context.route('http://localhost:8000/**', route => {
  const url = route.request().url();
  if (process.env.SHOT_MOCK_SIM === '1' && route.request().method() === 'GET') {
    if (/\/api\/simulations\/?(\?.*)?$/.test(url)) return route.fulfill({ json: [MOCK_SIM] });
    if (/\/api\/simulations\/1$/.test(url)) return route.fulfill({ json: MOCK_SIM });
  }
  return route.continue({ url: url.replace('http://localhost:8000', 'http://backend:8000') });
});

// Un chemin peut enchaîner des clics : "/simulations>>.fs-btn--primary" clique sur
// l'élément avant la capture (utile pour les fenêtres modales).
for (const spec of paths) {
  const [p, ...clicks] = spec.split('>>');
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(`http://localhost:5173${p}`, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => errors.push(String(e)));
  await page.waitForTimeout(1200);
  for (const sel of clicks) {
    await page.locator(sel).first().click({ timeout: 10000 }).catch(e => errors.push(`clic ${sel}: ${e.message.split('\n')[0]}`));
    await page.waitForTimeout(1500);
  }
  const name = (spec.replace(/[^\w-]+/g, '_').replace(/^_|_$/g, '') || 'root').slice(0, 80) + `.${theme}.png`;
  await page.screenshot({ path: `${outDir}/${name}`, fullPage: false });
  console.log(`${name}${errors.length ? '  ERREURS: ' + [...new Set(errors)].slice(0, 4).join(' | ') : ''}`);
  await page.close();
}
await browser.close();
