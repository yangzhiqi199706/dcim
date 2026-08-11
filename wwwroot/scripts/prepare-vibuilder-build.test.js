const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const buildDir = path.join(os.tmpdir(), 'vibuilder-build');

assert.ok(fs.existsSync(buildDir), 'VIBuilder deployment build must be prepared');

const index = fs.readFileSync(path.join(buildDir, 'index.html'), 'utf8');
assert.match(index, /src="\/VIBuilder\/runtime-endpoints\.js\?v=[a-f0-9]{12}"/);
assert.match(index, /p\.p="\/VIBuilder\/"/);
assert.doesNotMatch(index, /src="\/static\//);

const runtimeEndpoints = fs.readFileSync(path.join(buildDir, 'runtime-endpoints.js'), 'utf8');
assert.match(runtimeEndpoints, /appPort:\s*'8086\/VIBuilder'/);

const manifest = JSON.parse(fs.readFileSync(path.join(buildDir, 'asset-manifest.json'), 'utf8'));
assert.match(manifest['main.js'], /^\/VIBuilder\/static\//);
assert.strictEqual(manifest['index.html'], '/VIBuilder/index.html');

const precache = fs.readFileSync(
    path.join(buildDir, fs.readdirSync(buildDir).find((file) => file.startsWith('precache-manifest.') && file.endsWith('.js'))),
    'utf8'
);
assert.match(precache, /"url": "\/VIBuilder\/static\//);
assert.doesNotMatch(precache, /"url": "\/static\//);
const indexRevision = crypto.createHash('sha256').update(index).digest('hex');
const precacheIndexEntry = precache.match(/"revision": "([^"]+)",\s*"url": "\/VIBuilder\/index\.html"/);
assert.ok(precacheIndexEntry, 'VIBuilder index entry must be precached');
assert.strictEqual(precacheIndexEntry[1], indexRevision);

const serviceWorker = fs.readFileSync(path.join(buildDir, 'service-worker.js'), 'utf8');
assert.match(serviceWorker, /\/VIBuilder\/precache-manifest\./);
assert.match(serviceWorker, /registerNavigationRoute\("\/VIBuilder\/index\.html"/);
assert.match(serviceWorker, new RegExp(`VIBuilder deployment revision: ${indexRevision}`));

console.log('VIBuilder build path test passed');
