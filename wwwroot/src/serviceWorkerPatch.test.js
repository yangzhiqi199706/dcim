import fs from 'fs';
import os from 'os';
import path from 'path';

const patchModulePath = path.join(__dirname, '..', 'scripts', 'patch-service-worker.js');
const { patchServiceWorker } = require(patchModulePath);

describe('patchServiceWorker', () => {
    test('replaces the generated worker with a self-contained cache retirement worker', () => {
        const buildDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'worker-patch-'));
        const workerPath = path.join(buildDirectory, 'service-worker.js');
        fs.writeFileSync(workerPath, 'importScripts("https://storage.googleapis.com/workbox-cdn/releases/3.6.3/workbox-sw.js");\nworkbox.clientsClaim();', 'utf8');

        patchServiceWorker(buildDirectory);

        const patched = fs.readFileSync(workerPath, 'utf8');
        expect(patched).toContain('self.skipWaiting()');
        expect(patched).toContain('caches.keys()');
        expect(patched).not.toContain('importScripts(');

        fs.rmSync(buildDirectory, { recursive: true, force: true });
    });
});
