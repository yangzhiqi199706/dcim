const assert = require('node:assert/strict');
const test = require('node:test');

const { attachLocalApiRoutes } = require('./local-api-routes');

test('registers remote sync compatibility endpoints', () => {
  const routes = {};
  attachLocalApiRoutes({
    post: (routePath, handler) => {
      routes[routePath] = handler;
    },
  });

  assert.equal(typeof routes['/api/local/remoteSyncPreflight'], 'function');
  assert.equal(typeof routes['/api/local/remoteSyncStart'], 'function');
  assert.equal(typeof routes['/api/local/remoteSyncStatus'], 'function');
});
