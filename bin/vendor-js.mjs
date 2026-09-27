#!/usr/bin/env node
/**
 * Copies browser builds of front-end libraries into public/assets/vendor/,
 * copies first-party scripts resources/js/*.js into public/assets/js/,
 * and exports the Lucide icons listed in ICONS as standalone SVG files into
 * resources/icons/ (inlined server-side by the PHP icon() helper).
 *
 * Run with: npm run vendor:js   (output is committed; production needs no Node)
 * To add an icon: append its kebab-case Lucide name to ICONS and re-run.
 */
import { copyFileSync, mkdirSync, writeFileSync, existsSync, readdirSync, unlinkSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const nm = join(root, 'node_modules');
const vendorDir = join(root, 'public/assets/vendor');
const iconDir = join(root, 'resources/icons');

const FILES = {
  'alpine.min.js': 'alpinejs/dist/cdn.min.js',
  'panzoom.min.js': '@panzoom/panzoom/dist/panzoom.min.js',
  'chart.umd.min.js': 'chart.js/dist/chart.umd.min.js',
};

const ICONS = [
  'armchair', 'arrow-right', 'arrow-up-right', 'arrow-left', 'arrow-down', 'bell', 'briefcase', 'building', 'building-2',
  'calendar', 'calendar-check', 'calendar-days', 'check', 'chevron-down', 'chevron-left',
  'chevron-right', 'chevrons-left', 'circle-alert', 'circle-check', 'circle-help', 'clock', 'coffee',
  'credit-card', 'door-open', 'download', 'eye', 'eye-off', 'file-text', 'file-spreadsheet', 'fingerprint',
  'hand-coins', 'house', 'id-card', 'info', 'key-round', 'landmark', 'layers', 'layout-dashboard', 'layout-grid',
  'lock', 'log-in', 'log-out', 'mail', 'map', 'map-pin', 'menu', 'monitor', 'package', 'parking-meter',
  'pen-tool', 'phone', 'plug', 'plus', 'printer', 'projector', 'receipt', 'receipt-indian-rupee', 'search',
  'settings', 'shield-check', 'snowflake', 'sparkles', 'square-user', 'triangle-alert', 'upload', 'user',
  'user-check', 'user-plus', 'user-round', 'users', 'users-round', 'wallet', 'wifi', 'x', 'zap', 'chart-column',
  'chart-pie', 'badge-indian-rupee', 'indian-rupee', 'scan-line', 'sofa', 'presentation', 'utensils',
  'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'globe', 'heart-handshake', 'rocket', 'star',
  'moon', 'sun', 'toilet', 'circle-parking', 'square-parking', 'mailbox', 'lamp-desk', 'door-closed', 'panel-left', 'list', 'filter', 'ellipsis', 'external-link', 'accessibility', 'fire-extinguisher',
  'arrow-up-down', 'trending-up', 'inbox', 'history', 'hourglass', 'circle-user-round', 'shield', 'badge-check',
  'camera', 'trash-2', 'file-check', 'file-up', 'qr-code', 'refresh-cw', 'image', 'pencil', 'send', 'user-search', 'circle-x',
  'circle-dashed', 'mail-check', 'scan-face', 'file-x', 'video', 'clipboard-check', 'hand', 'circle-dot', 'shield-alert', 'book-open-text', 'file-image',
  // batch 3 — Space Explorer
  'ban', 'zoom-in', 'zoom-out', 'minus', 'timer', 'wand-sparkles', 'calendar-range', 'mouse-pointer-click', 'grip-horizontal',
  'chevron-up', 'party-popper', 'calendar-clock', 'rotate-ccw', 'locate-fixed', 'ticket', 'move', 'sparkle',
  // batch 4 — Layout & Pricing Designer (tools) + more facility icons for the facility master
  'mouse-pointer-2', 'hand', 'square-dashed', 'pentagon', 'undo-2', 'redo-2', 'rotate-cw', 'copy', 'magnet', 'grid-3x3',
  'grid-2x2', 'align-start-vertical', 'align-center-vertical', 'align-end-vertical', 'align-start-horizontal',
  'align-center-horizontal', 'align-end-horizontal', 'align-horizontal-distribute-center', 'align-vertical-distribute-center',
  'rows-3', 'list-ordered', 'maximize', 'save', 'wrench', 'tag', 'palette', 'hash', 'loader-circle', 'keyboard', 'cloud-upload',
  'cloud-off', 'square-mouse-pointer', 'layout-template', 'shapes',
  'bike', 'car', 'shower-head', 'microwave', 'refrigerator', 'headphones', 'tv', 'fan', 'cctv', 'dumbbell', 'baby', 'cup-soda',
  'utensils-crossed', 'heart-pulse', 'droplets', 'cigarette-off', 'lamp', 'phone-call', 'router', 'battery-charging', 'sofa',
];

mkdirSync(vendorDir, { recursive: true });
for (const [target, src] of Object.entries(FILES)) {
  copyFileSync(join(nm, src), join(vendorDir, target));
  console.log(`vendor  ${target}  <-  ${src}`);
}

// First-party scripts: resources/js/*.js -> public/assets/js/
const jsSrc = join(root, 'resources/js');
const jsOut = join(root, 'public/assets/js');
mkdirSync(jsOut, { recursive: true });
for (const f of readdirSync(jsSrc)) {
  if (f.endsWith('.js')) {
    copyFileSync(join(jsSrc, f), join(jsOut, f));
    console.log(`app     ${f}  ->  public/assets/js/`);
  }
}

const attrsToString = (attrs) =>
  Object.entries(attrs).map(([k, v]) => `${k}="${String(v).replace(/"/g, '&quot;')}"`).join(' ');

mkdirSync(iconDir, { recursive: true });
for (const f of readdirSync(iconDir)) if (f.endsWith('.svg')) unlinkSync(join(iconDir, f));
let count = 0;
for (const name of ICONS) {
  const file = join(nm, 'lucide/dist/esm/icons', `${name}.js`);
  if (!existsSync(file)) { console.warn(`icon   MISSING ${name}`); continue; }
  const mod = await import(pathToFileURL(file).href);
  const [, , children] = mod.default;
  const inner = children.map(([tag, attrs]) => `<${tag} ${attrsToString(attrs)}/>`).join('');
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>\n`;
  writeFileSync(join(iconDir, `${name}.svg`), svg);
  count++;
}
console.log(`icons   ${count} Lucide icons -> resources/icons/`);
