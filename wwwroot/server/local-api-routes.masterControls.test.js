const assert = require('node:assert/strict');
const test = require('node:test');

const { attachLocalApiRoutes, rewriteImportedPageImageRefs } = require('./local-api-routes');

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

test('rewrites image references inside an imported page without replacing the page JSON', () => {
  const page = {
    image: 'https://example.test/Images/dcim/a.png?v=1',
    nested: JSON.stringify({ icon: 'Images/dcim/b.svg' }),
    plain: 'unchanged',
  };

  const result = JSON.parse(rewriteImportedPageImageRefs(JSON.stringify(page)));

  assert.equal(result.image, 'Images/dcim/a.png');
  assert.equal(JSON.parse(result.nested).icon, 'Images/dcim/b.svg');
  assert.equal(result.plain, 'unchanged');
});
