# Reusable Master Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a designer to save selected canvas elements as a server-persisted master control and reuse independent copies from the material library.

**Architecture:** A master control is a JSON definition under `public/Images/master-controls/`. A pure frontend module normalizes saved positions and makes fresh instances. The local API stores and lists definitions; `ItemBox` displays them; `Home` saves selections and expands a definition when dropped.

**Tech Stack:** React 18, React Konva, Ant Design, Node.js/Express, Jest, Node built-in test runner.

---

### Task 1: Master-control browser model

**Files:**
- Create: `wwwroot/src/Page/masterControlLibrary.js`
- Create: `wwwroot/src/Page/masterControlLibrary.test.js`

- [ ] **Step 1: Write the failing tests**

```js
import { createMasterControlDefinition, instantiateMasterControl } from './masterControlLibrary';

test('normalizes selected shapes to the top-left origin', () => {
    const definition = createMasterControlDefinition('Pump', [
        { id: 'a', x: 80, y: 120, moduleJson: { children: [] } },
        { id: 'b', x: 130, y: 150, moduleJson: { children: [] } },
    ]);
    expect(definition.shapes.map((shape) => [shape.x, shape.y])).toEqual([[0, 0], [50, 30]]);
});

test('creates fresh IDs and group IDs at a drop point', () => {
    const definition = createMasterControlDefinition('Pair', [
        { id: 'a', groupId: 'old', x: 20, y: 20, moduleJson: { children: [] } },
        { id: 'b', groupId: 'old', x: 60, y: 20, moduleJson: { children: [] } },
    ]);
    const instance = instantiateMasterControl(definition, { x: 300, y: 400 }, (index) => `new-${index}`);
    expect(instance.shapes.map((shape) => [shape.id, shape.x, shape.y])).toEqual([
        ['new-0', 300, 400], ['new-1', 340, 400],
    ]);
    expect(new Set(instance.shapes.map((shape) => shape.groupId)).size).toBe(1);
    expect(instance.shapes[0].groupId).not.toBe('old');
});
```

- [ ] **Step 2: Verify RED**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/masterControlLibrary.test.js`

Expected: `FAIL` because `masterControlLibrary` is missing.

- [ ] **Step 3: Implement the smallest model**

```js
export const MASTER_CONTROL_KIND = 'master-control';
export const createMasterControlDefinition = (name, shapes) => ({
    kind: MASTER_CONTROL_KIND,
    version: 1,
    name: String(name || '').trim(),
    shapes: normalizeToOrigin(deepClone(Array.isArray(shapes) ? shapes : [])),
});
```

`instantiateMasterControl` must deep clone the shapes, add the drop point, create outer IDs with the supplied factory, and map every source `groupId` to one new group ID.

- [ ] **Step 4: Verify GREEN**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/masterControlLibrary.test.js`

Expected: `PASS`.

- [ ] **Step 5: Commit the model**

Run: `git add wwwroot/src/Page/masterControlLibrary.js wwwroot/src/Page/masterControlLibrary.test.js && git commit -m "feat(master-controls): add reusable control model"`

### Task 2: Server persistence

**Files:**
- Create: `wwwroot/server/masterControlStore.js`
- Create: `wwwroot/server/masterControlStore.test.js`
- Modify: `wwwroot/server/local-api-routes.js`

- [ ] **Step 1: Write failing temporary-directory store tests**

```js
const store = createMasterControlStore(tempDir);
expect(store.save('Pump', { kind: 'master-control', version: 1, shapes: [{ id: 'a' }] }).ok).toBe(true);
expect(store.list()).toEqual([expect.objectContaining({ moduleName: 'Pump' })]);
expect(store.remove('Pump').ok).toBe(true);
expect(store.list()).toEqual([]);
```

Also assert path sanitization, malformed JSON ignored by `list`, and duplicate names rejected without overwriting files.

- [ ] **Step 2: Verify RED**

Run: `node --test server/masterControlStore.test.js`

Expected: `FAIL` because the store module is missing.

- [ ] **Step 3: Implement storage and routes**

```js
const MASTER_CONTROL_DIR = path.join(IMAGES_DIR, 'master-controls');
const masterControlStore = createMasterControlStore(MASTER_CONTROL_DIR);

if (action === 'master-control') return ok(res, masterControlStore.list());
if (action === 'delmastercontrol') {
    const result = masterControlStore.remove(payload.name);
    return result.ok ? ok(res, [], 'deleted') : fail(res, result.message);
}

app.post(routePath(basePath, 'saveMasterControl'), (req, res) => {
    const result = masterControlStore.save(req.body && req.body.name, req.body && req.body.definition);
    return result.ok ? ok(res, result.data, 'saved') : fail(res, result.message);
});
```

The store returns parsed definitions as `moduleJson` objects with the local `Images/icon/tpl.png` icon and never writes outside its assigned directory.

- [ ] **Step 4: Verify GREEN**

Run: `node --test server/masterControlStore.test.js`

Expected: `PASS`, including test cleanup.

- [ ] **Step 5: Commit persistence**

Run: `git add wwwroot/server/masterControlStore.js wwwroot/server/masterControlStore.test.js wwwroot/server/local-api-routes.js && git commit -m "feat(master-controls): persist reusable definitions"`

### Task 3: Material-library category

**Files:**
- Modify: `wwwroot/src/Page/ItemNav.js`
- Modify: `wwwroot/src/Page/ItemBox.js`
- Modify: `wwwroot/src/Page/ItemBox.test.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [ ] **Step 1: Add failing integration assertions**

```js
expect(source).toContain("getImgData('master-control')");
expect(source).toContain("action: 'delmastercontrol'");
expect(source).toContain('selectedNav === 7');
expect(navSource).toContain("t('itemBox.masterControls')");
```

- [ ] **Step 2: Verify RED**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/ItemBox.test.js`

Expected: `FAIL` because no master-control tab exists.

- [ ] **Step 3: Implement the category**

Add a MUI `Widgets` icon and `itemBox.masterControls` immediately before favorites. The tab calls `getImgData('master-control')`, preserves existing search/favorite/drag behavior, and provides deletion through `delmastercontrol`. Update the favorites index without changing its storage key. Deleting a master control removes its `favoriteId` too.

```js
if (type === 'tpl' || type === 'master-control') {
    imgData = Array.isArray(res.data) ? localizeDeep(res.data) : [];
    setPaletteData(imgData, type);
    return;
}
```

- [ ] **Step 4: Verify GREEN**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/ItemBox.test.js`

Expected: `PASS`.

- [ ] **Step 5: Commit the library UI**

Run: `git add wwwroot/src/Page/ItemNav.js wwwroot/src/Page/ItemBox.js wwwroot/src/Page/ItemBox.test.js wwwroot/src/i18n/dictionaries/zh-CN.js wwwroot/src/i18n/dictionaries/en-US.js && git commit -m "feat(master-controls): add material library category"`

### Task 4: Editor save and drop workflow

**Files:**
- Modify: `wwwroot/src/Page/Home.js`
- Create: `wwwroot/src/Page/Home.masterControls.test.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [ ] **Step 1: Write failing Home integration assertions**

```js
expect(source).toContain("from './masterControlLibrary'");
expect(source).toContain('getClipboardSelectionShapes()');
expect(source).toContain("getDataLocal('saveMasterControl'");
expect(source).toContain('instantiateMasterControl(');
expect(source).toContain('masterControlName');
```

- [ ] **Step 2: Verify RED**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/Home.masterControls.test.js`

Expected: `FAIL` because the editor workflow is absent.

- [ ] **Step 3: Implement save and instantiate behavior**

Add a top-bar command disabled without selection. It synchronizes Konva positions, captures `getClipboardSelectionShapes`, validates a non-empty name, generates a definition, and sends it to `saveMasterControl`. Add a named modal using the existing `layui-layer` pattern.

Before normal one-element creation in `handleOnDrop`, create an independent instance:

```js
if (isMasterControlDefinition(dragAttrs)) {
    const instance = instantiateMasterControl(dragAttrs, getCanvasDropPoint(), createCanvasShapeId);
    const nextImages = imagesRef.current.concat(instance.shapes);
    imagesRef.current = nextImages;
    setImages(nextImages);
    history.push(JSON.parse(JSON.stringify(nextImages)));
    selectShapes(instance.ids);
    return;
}
```

The instance preserves visual/data configuration but never points to its source definition and must not replace page background or page metadata.

- [ ] **Step 4: Verify GREEN**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/Home.masterControls.test.js src/Page/masterControlLibrary.test.js`

Expected: `PASS`.

- [ ] **Step 5: Commit the editor workflow**

Run: `git add wwwroot/src/Page/Home.js wwwroot/src/Page/Home.masterControls.test.js wwwroot/src/Page/masterControlLibrary.js wwwroot/src/Page/masterControlLibrary.test.js wwwroot/src/i18n/dictionaries/zh-CN.js wwwroot/src/i18n/dictionaries/en-US.js && git commit -m "feat(master-controls): save and reuse canvas controls"`

### Task 5: Release verification

**Files:**
- Verify only; do not change unrelated files.

- [ ] **Step 1: Run browser tests**

Run: `CI=true node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand`

Expected: all suites pass.

- [ ] **Step 2: Run server-store tests**

Run: `node --test server/masterControlStore.test.js`

Expected: all tests pass.

- [ ] **Step 3: Run project checks**

Run: `npm run check:no-cjk && git diff --check`

Expected: both commands exit 0.

- [ ] **Step 4: Build production assets**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: exit 0; record pre-existing warnings separately.

- [ ] **Step 5: Commit the plan**

Run: `git add docs/superpowers/plans/2026-08-01-reusable-master-controls.md && git commit -m "docs(master-controls): add implementation plan"`
