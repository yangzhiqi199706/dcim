const fs = require('fs');
const path = require('path');

const RETIREMENT_WORKER_SOURCE = `self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.map(function (cacheKey) {
      return caches.delete(cacheKey);
    }));
    await self.clients.claim();
  })());
});
`;

function patchServiceWorker(buildDirectory = path.resolve(process.cwd(), 'build')) {
  const workerPath = path.join(buildDirectory, 'service-worker.js');
  const source = fs.readFileSync(workerPath, 'utf8');
  if (source !== RETIREMENT_WORKER_SOURCE) {
    fs.writeFileSync(workerPath, RETIREMENT_WORKER_SOURCE, 'utf8');
  }
  return workerPath;
}

if (require.main === module) {
  try {
    patchServiceWorker();
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error(error.message);
    process.exit(1);
  }
}

module.exports = { RETIREMENT_WORKER_SOURCE, patchServiceWorker };
