// Parcours de saisie de bout en bout (outil dev). La pièce créée est supprimée à la fin.
import { chromium } from 'playwright';

const res = await fetch('http://backend:8000/api/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: process.env.SHOT_EMAIL, password: process.env.SHOT_PASSWORD }),
});
const { token } = await res.json();

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fr-FR' });
await context.addInitScript(t => { localStorage.setItem('token', t); localStorage.setItem('fs-theme', 'light'); }, token);
await context.route('http://localhost:8000/**', r => r.continue({ url: r.request().url().replace('http://localhost:8000', 'http://backend:8000') }));
const page = await context.newPage();
const erreurs = [];
page.on('pageerror', e => erreurs.push(e.message));

const etape = async (nom, fn) => { try { await fn(); console.log(`OK   ${nom}`); } catch (e) { console.log(`ECHEC ${nom} : ${e.message.split('\n')[0]}`); } };

await page.goto('http://localhost:5173/ecriture', { waitUntil: 'networkidle' });
await etape('Libellé de pièce', async () => { await page.fill('#pf-libelle', 'Test E2E — achat fournitures'); });
await etape('Contrepartie automatique cochée par défaut (journal banque)', async () => {
  if (!(await page.locator('.pf-switch input').first().isChecked())) throw new Error('non cochée');
});
await etape('Recherche du compte 6 au clavier + Entrée', async () => {
  const compte = page.locator('.cs-input').first();
  await compte.click();
  await compte.fill('fourniture');
  await page.waitForTimeout(200);
  await compte.press('Enter');
  const v = await compte.inputValue();
  if (!v.includes('—')) throw new Error(`valeur « ${v} »`);
});
await etape('Montant au débit', async () => {
  const debit = page.locator('.pf-montant').first();
  await debit.fill('75000');
});
await etape('Contrepartie calculée et écart nul', async () => {
  await page.waitForSelector('.pf-contrepartie');
  const ecart = (await page.locator('.pf-ecart strong').innerText()).trim();
  if (ecart !== '0,00') throw new Error(`écart ${ecart}`);
});
await page.screenshot({ path: '/work/out/e2e-saisie-1-formulaire.png' });

let idPiece = null;
await etape('Enregistrer en brouillon (Ctrl+S)', async () => {
  await page.locator('#pf-libelle').press('Control+s');
  await page.waitForURL(/\/ecriture\/\d+/, { timeout: 10000 });
  idPiece = page.url().match(/ecriture\/(\d+)/)[1];
  await page.waitForSelector('.fs-alert--success');
});
await page.waitForTimeout(800);
await page.screenshot({ path: '/work/out/e2e-saisie-2-enregistree.png' });

await etape('La pièce rechargée contient 2 lignes', async () => {
  const n = await page.locator('.pf-table tbody tr:not(.pf-contrepartie)').count();
  if (n !== 2) throw new Error(`${n} lignes`);
});

// Nettoyage : suppression de la pièce de test
if (idPiece) {
  const del = await fetch(`http://backend:8000/api/pieces/${idPiece}`, { method: 'DELETE', headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  console.log(`Nettoyage pièce ${idPiece} : HTTP ${del.status}`);
}
console.log(erreurs.length ? `ERREURS JS : ${erreurs.join(' | ')}` : 'Aucune erreur JS');
await browser.close();
