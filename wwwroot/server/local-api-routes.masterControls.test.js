const assert = require('node:assert/strict');
const test = require('node:test');

const { attachLocalApiRoutes } = require('./local-api-routes');

test('registers the master control save endpoint', () => {
  const routes = {};
  attachLocalApiRoutes({
    post: (routePath, handler) => {
      routes[routePath] = handler;
    },
  });

  assert.equal(typeof routes['/api/local/imgData'], 'function');
  assert.equal(typeof routes['/api/local/saveMasterControl'], 'function');
});
