/**
 * Regression: Sales Call photo wizard must survive a Livewire-like parent morph.
 *
 * Mirrors the durable pattern in salescall-page.blade.php:
 * - photo list / parent Alpine outside wire:ignore
 * - nested Alpine wizard under wire:ignore
 *
 * Run: node tests/Browser/salescall-photo-wizard-morph.mjs
 * Requires: playwright (devDependency or /tmp/node_modules/playwright from Gate 0).
 */
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const require = createRequire(import.meta.url);

function loadPlaywright() {
    const candidates = [
        'playwright',
        '/tmp/node_modules/playwright',
        join(process.cwd(), 'node_modules/playwright'),
    ];

    for (const candidate of candidates) {
        try {
            return require(candidate);
        } catch {
            // try next
        }
    }

    throw new Error('playwright is not installed. Run: npm install -D playwright && npx playwright install webkit');
}

const html = `<!DOCTYPE html>
<html>
<head>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
</head>
<body>
<div id="salescall-page-root" x-data="{
  selected: 99,
  callPhotos: [],
  init() {
    if (!Alpine.store('salescallPhotoWizard')) {
      Alpine.store('salescallPhotoWizard', { photoStep: 0, photoCategory: null, photoType: null });
    }
  },
  resetPhotoWizard() {
    window.dispatchEvent(new CustomEvent('salescall-photo-wizard-reset'));
  },
  async loadPhotos() {
    // Simulate a Livewire round-trip that remorphs the parent Alpine host
    // while skipping the wire:ignore wizard island (same contract as Livewire).
    this.callPhotos = [...this.callPhotos];
    await new Promise(r => setTimeout(r, 20));
    const root = document.getElementById('salescall-page-root');
    const wizard = document.getElementById('salescall-photo-wizard');
    const placeholder = document.createComment('wire-ignore-placeholder');
    wizard.replaceWith(placeholder);
    const html = root.outerHTML;
    const host = root.parentElement;
    host.innerHTML = html;
    const newRoot = host.querySelector('#salescall-page-root');
    const newPlaceholder = [...newRoot.childNodes].find(n => n.nodeType === Node.COMMENT_NODE);
    newPlaceholder.replaceWith(wizard);
    Alpine.initTree(newRoot);
  }
}">
  <div x-show="($store.salescallPhotoWizard?.photoStep ?? 0) === 0" data-photo-list>
    Photo List
  </div>

  <div wire:ignore id="salescall-photo-wizard">
    <div x-data="{
      photoStep: 0,
      photoCategory: null,
      photoType: null,
      imageCategories: [{ id: 1, name: 'Store', types: [{ id: 10, name: 'Facade' }, { id: 11, name: 'Neighbor Store' }] }],
      syncWizardStore() {
        let store = Alpine.store('salescallPhotoWizard');
        if (!store) {
          Alpine.store('salescallPhotoWizard', { photoStep: 0, photoCategory: null, photoType: null });
          store = Alpine.store('salescallPhotoWizard');
        }
        store.photoStep = this.photoStep;
        store.photoCategory = this.photoCategory;
        store.photoType = this.photoType;
      },
      init() {
        this.syncWizardStore();
        this.$watch('photoStep', () => this.syncWizardStore());
        this.$watch('photoCategory', () => this.syncWizardStore());
        this.$watch('photoType', () => this.syncWizardStore());
      },
      startPhotoFlow() { this.photoStep = 1; },
      selectPhotoCategory(cat) { this.photoCategory = cat; this.photoStep = 2; },
      selectPhotoType(type) { this.photoType = type; this.photoStep = 3; },
      cancelPhoto() { this.photoStep = 0; this.photoCategory = null; this.photoType = null; },
    }" @salescall-photo-wizard-reset.window="cancelPhoto()">
      <div x-show="photoStep === 0">
        <button type="button" id="add" @click="startPhotoFlow()">Add Photo</button>
      </div>
      <div x-show="photoStep === 1" x-transition>
        <button type="button" class="cat" @click="selectPhotoCategory(imageCategories[0])">Store</button>
      </div>
      <div x-show="photoStep === 2" x-transition data-step2>
        <button type="button" class="type" @click="selectPhotoType(imageCategories[0].types[0])">Facade</button>
      </div>
      <div x-show="photoStep === 3" x-transition data-photo-step3>Capture Photo</div>
    </div>
  </div>

  <button type="button" id="morph" @click="loadPhotos()">loadPhotos</button>
</div>
</body>
</html>`;

const { webkit } = loadPlaywright();
const dir = mkdtempSync(join(tmpdir(), 'salescall-photo-wizard-'));
const file = join(dir, 'wizard.html');
writeFileSync(file, html);

const browser = await webkit.launch();
const page = await browser.newPage();
await page.goto(pathToFileURL(file).href);
await page.waitForFunction(() => window.Alpine);

await page.click('#add');
await page.waitForTimeout(200);
await page.click('.cat');
await page.waitForTimeout(200);
await page.click('.type');
await page.waitForTimeout(500);

const before = await page.evaluate(() => {
    const wizard = Alpine.$data(document.querySelector('#salescall-photo-wizard [x-data]'));
    const step3 = document.querySelector('[data-photo-step3]');
    return {
        photoStep: wizard.photoStep,
        category: wizard.photoCategory?.name,
        type: wizard.photoType?.name,
        step3Display: getComputedStyle(step3).display,
    };
});

if (before.photoStep !== 3 || before.step3Display === 'none') {
    console.error('FAIL before morph', before);
    process.exit(1);
}

await page.click('#morph');
await page.waitForTimeout(400);

const after = await page.evaluate(() => {
    const wizard = Alpine.$data(document.querySelector('#salescall-photo-wizard [x-data]'));
    const step3 = document.querySelector('[data-photo-step3]');
    return {
        photoStep: wizard.photoStep,
        category: wizard.photoCategory?.name,
        type: wizard.photoType?.name,
        step3Display: getComputedStyle(step3).display,
        step3Text: step3?.innerText?.trim(),
    };
});

await browser.close();

if (
    after.photoStep !== 3
    || after.category !== 'Store'
    || after.type !== 'Facade'
    || after.step3Display === 'none'
    || after.step3Text !== 'Capture Photo'
) {
    console.error('FAIL after morph', { before, after });
    process.exit(1);
}

console.log('PASS', { before, after });
