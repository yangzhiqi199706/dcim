# Parameter Replacement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a designer replace one protocol parameter with another across a multi-selection bound to the same device.

**Architecture:** A pure `parameterReplacement` module validates the selection, derives device parameter options from existing device payloads, and produces immutable shape updates. `DesignerApp` owns the dialog state, uses the existing `GetDeviceListKey` endpoint to load replacement candidates, and commits one history entry for the completed batch change.

**Tech Stack:** React 18, Ant Design Select/Button, Jest via react-scripts, existing `httpsend` client and i18n dictionaries.

---

### Task 1: Add selection and replacement data helpers

**Files:**
- Create: `wwwroot/src/Page/parameterReplacement.js`
- Test: `wwwroot/src/Page/parameterReplacement.test.js`

- [ ] **Step 1: Write the failing test**

```js
import {
    createParameterReplacementContext,
    replaceSelectedParameterBindings,
} from './parameterReplacement';

test('replaces only selected same-device parameter bindings', () => {
    const shapes = [/* selected device bindings plus an unselected binding */];
    const context = createParameterReplacementContext(shapes, ['one', 'two']);
    const result = replaceSelectedParameterBindings(
        shapes,
        context,
        'temperature',
        { name: 'pressure', cmdtype: 'read' },
    );
    expect(result.changedCount).toBe(2);
    expect(result.shapes[2]).toBe(shapes[2]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node node_modules/react-scripts/bin/react-scripts.js test src/Page/parameterReplacement.test.js --watchAll=false`

Expected: FAIL because `parameterReplacement` has not been created.

- [ ] **Step 3: Write minimal implementation**

```js
export const createParameterReplacementContext = (shapes, ids) => {
    // Require at least two selected entries with one `dataKey` protocol binding.
    // Return `{ valid, deviceId, selectedIds, originalOptions }`.
};

export const replaceSelectedParameterBindings = (shapes, context, originalName, replacement) => {
    // Clone only selected matching bindings, preserving all unselected references.
    // Return `{ shapes, changedCount }`.
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `node node_modules/react-scripts/bin/react-scripts.js test src/Page/parameterReplacement.test.js --watchAll=false`

Expected: PASS.

### Task 2: Add designer dialog and batch commit

**Files:**
- Modify: `wwwroot/src/Page/DesignerApp.js:20-35`
- Modify: `wwwroot/src/Page/DesignerApp.js:160-330`
- Modify: `wwwroot/src/Page/DesignerApp.js:2628-2649`
- Modify: `wwwroot/src/Page/DesignerApp.js:3305-3348`
- Test: `wwwroot/src/Page/DesignerApp.parameterReplacement.test.js`

- [ ] **Step 1: Write the failing integration test**

```js
expect(source).toContain("from './parameterReplacement'");
expect(source).toContain('const openParameterReplacementDialog = async () => {');
expect(source).toContain('replaceSelectedParameterBindings(');
expect(source).toContain('parameterReplacementBox');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node node_modules/react-scripts/bin/react-scripts.js test src/Page/DesignerApp.parameterReplacement.test.js --watchAll=false`

Expected: FAIL because the active designer has no parameter replacement dialog.

- [ ] **Step 3: Write minimal implementation**

```js
const openParameterReplacementDialog = async () => {
    const context = createParameterReplacementContext(imagesRef.current, selectedIdsRef.current);
    if (!context.valid) {
        message.warning(t(context.reasonKey));
        return;
    }
    const response = await httpsend.getData('GetDeviceListKey', { ComboBox: 'all' });
    setParameterReplacementOptions(createDeviceParameterOptions(response.data, context));
    setParameterReplacementBox(true);
};
```

The top action is disabled unless the selection has at least two elements. The confirmation handler replaces the selected original binding only, updates `imagesRef`, calls `setImages`, pushes exactly one history snapshot, rerenders charts, and closes the dialog.

- [ ] **Step 4: Run test to verify it passes**

Run: `node node_modules/react-scripts/bin/react-scripts.js test src/Page/DesignerApp.parameterReplacement.test.js --watchAll=false`

Expected: PASS.

### Task 3: Add localized text and verify the feature

**Files:**
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`
- Test: `wwwroot/src/Page/parameterReplacement.test.js`

- [ ] **Step 1: Add dictionary entries**

```js
parameterReplacement: {
    triggerLabel: '...',
    title: '...',
    original: '...',
    replacement: '...',
    invalidSelection: '...',
    unavailable: '...',
    replacedCount: '... {count} ...',
},
```

- [ ] **Step 2: Verify focused tests, all tests, source policy, and build**

Run:

```powershell
$env:CI = 'true'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false
npm run check:no-cjk
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js build
```

Expected: All tests pass, no CJK check violations outside dictionaries, and production build exits 0.
