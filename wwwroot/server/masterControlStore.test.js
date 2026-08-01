const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const { createMasterControlStore } = require('./masterControlStore');

const createDefinition = (name = 'Pump panel') => ({
  kind: 'master-control',
  version: 1,
  name,
  shapes: [{ id: 'source-shape', x: 0, y: 0, moduleJson: { children: [] } }],
});

const withStore = (run) => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'master-control-store-'));
  try {
    run(createMasterControlStore(directory), directory);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
};

test('saves, lists, and removes a master control', () => {
  withStore((store) => {
    assert.equal(store.save('Pump panel', createDefinition()).ok, true);
    assert.deepEqual(store.list(), [{
      moduleName: 'Pump panel',
      iconBase64: 'Images/icon/tpl.png',
      moduleJson: createDefinition(),
    }]);
    assert.equal(store.remove('Pump panel').ok, true);
    assert.deepEqual(store.list(), []);
  });
});

test('keeps filenames within the master control directory', () => {
  withStore((store, directory) => {
    assert.equal(store.save('../outside', createDefinition('../outside')).ok, true);
    assert.deepEqual(fs.readdirSync(directory), ['outside.json']);
  });
});

test('does not overwrite an existing master control', () => {
  withStore((store) => {
    assert.equal(store.save('Pump panel', createDefinition('First')).ok, true);
    assert.equal(store.save('Pump panel', createDefinition('Second')).ok, false);
    assert.equal(store.list()[0].moduleJson.name, 'First');
  });
});

test('ignores malformed files while listing master controls', () => {
  withStore((store, directory) => {
    fs.writeFileSync(path.join(directory, 'broken.json'), '{bad json', 'utf8');
    assert.deepEqual(store.list(), []);
  });
});

test('creates the storage directory while saving', () => {
  const parentDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'master-control-parent-'));
  const controlDirectory = path.join(parentDirectory, 'master-controls');
  try {
    const store = createMasterControlStore(controlDirectory);
    assert.equal(store.save('Pump panel', createDefinition()).ok, true);
    assert.equal(fs.existsSync(path.join(controlDirectory, 'Pump panel.json')), true);
  } finally {
    fs.rmSync(parentDirectory, { recursive: true, force: true });
  }
});
