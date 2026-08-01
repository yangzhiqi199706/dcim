# Preflight Validation and Data Simulation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a designer identify invalid canvas configuration before publishing and preview safe local values for data-bound elements without saving those values to the page.

**Architecture:** Add two pure modules: one produces stable validation findings from the page element model, the other derives a transient simulated element model. Small modal components render the findings and editable simulated values. `DesignerApp` owns modal state and always serializes the original `imagesRef` data, never the simulated model.

**Tech Stack:** React 18, react-konva, Ant Design, Jest via react-scripts, existing i18n dictionaries.

---

### Task 1: Page Validation Core

**Files:**
- Create: `wwwroot/src/Page/pageValidation.js`
- Create: `wwwroot/src/Page/pageValidation.test.js`

- [ ] **Step 1: Write the failing tests**

```js
import { validatePageElements } from './pageValidation';

test('reports duplicate ids and elements outside the stage', () => {
  const findings = validatePageElements([
    { id: 'meter', x: 1900, y: 0, width: 80, height: 80, moduleJson: { children: [] } },
    { id: 'meter', x: 0, y: 0, width: 40, height: 40, moduleJson: { children: [] } },
  ], { stageWidth: 1920, stageHeight: 1080 });

  expect(findings.map(item => item.code)).toEqual(['duplicate-id', 'out-of-bounds']);
});
```

- [ ] **Step 2: Run the test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test pageValidation.test.js --watchAll=false`

Expected: FAIL because `./pageValidation` does not exist.

- [ ] **Step 3: Implement the pure validator**

```js
export const validatePageElements = (elements, { stageWidth, stageHeight } = {}) => {
  const findings = [];
  const seen = new Set();
  (Array.isArray(elements) ? elements : []).forEach((element, index) => {
    const elementId = String(element && element.id || '');
    if (!elementId || seen.has(elementId)) {
      findings.push({ code: elementId ? 'duplicate-id' : 'missing-id', severity: 'error', elementId, index });
    }
    seen.add(elementId);
    // Add invalid geometry, canvas overflow, malformed dataKey and chart data checks here.
  });
  return findings;
};
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test pageValidation.test.js --watchAll=false`

Expected: PASS.

- [ ] **Step 5: Extend the test table**

Add tests for invalid geometry, incomplete `dataKey`, a chart with mismatched categories/data, and a well-formed element that creates no findings. Keep every finding shape `{ code, severity, elementId, index }`.

### Task 2: Data Simulation Core

**Files:**
- Create: `wwwroot/src/Page/simulationOverrides.js`
- Create: `wwwroot/src/Page/simulationOverrides.test.js`

- [ ] **Step 1: Write failing derivation tests**

```js
import { applySimulationOverrides, getSimulatableElements } from './simulationOverrides';

test('derives a text and chart model without mutating the persisted elements', () => {
  const source = [{
    id: 'load',
    moduleJson: { attrs: { dataKey: [{ key: '1', name: 'Load' }] }, children: [
      { className: 'Text', attrs: { text: '--' } },
    ] },
  }];
  const result = applySimulationOverrides(source, { load: '42.5' });

  expect(getSimulatableElements(source)).toHaveLength(1);
  expect(result[0].moduleJson.children[0].attrs.text).toBe('42.5');
  expect(source[0].moduleJson.children[0].attrs.text).toBe('--');
});
```

- [ ] **Step 2: Run the test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test simulationOverrides.test.js --watchAll=false`

Expected: FAIL because `./simulationOverrides` does not exist.

- [ ] **Step 3: Implement the minimal derivation functions**

```js
export const getSimulatableElements = (elements) => (Array.isArray(elements) ? elements : [])
  .filter((element) => element && element.id && element.moduleJson && element.moduleJson.attrs
    && Array.isArray(element.moduleJson.attrs.dataKey) && element.moduleJson.attrs.dataKey.length > 0);

export const applySimulationOverrides = (elements, overrides) => {
  // Clone only when an override exists, set Text.attrs.text and chart-like attrs.data,
  // and return original objects for every untouched element.
};
```

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test simulationOverrides.test.js --watchAll=false`

Expected: PASS.

- [ ] **Step 5: Cover chart value parsing**

Add tests that number strings become numeric chart values, JSON arrays remain arrays, and invalid JSON remains a text simulation value. Do not evaluate expressions.

### Task 3: Diagnostics and Simulation UI

**Files:**
- Create: `wwwroot/src/Page/PreflightModal.js`
- Create: `wwwroot/src/Page/DataSimulationModal.js`
- Create: `wwwroot/src/Page/PreflightModal.test.js`
- Create: `wwwroot/src/Page/DataSimulationModal.test.js`
- Modify: `wwwroot/src/Page/DesignerApp.js`
- Modify: `wwwroot/src/Assets/designer.css`

- [ ] **Step 1: Write failing component tests**

```js
render(<PreflightModal open findings={[{ code: 'out-of-bounds', severity: 'warning', elementId: 'meter' }]} onLocate={onLocate} onClose={() => {}} />);
fireEvent.click(screen.getByRole('button', { name: /meter/i }));
expect(onLocate).toHaveBeenCalledWith('meter');
```

```js
render(<DataSimulationModal open elements={elements} values={{}} onValuesChange={onValuesChange} onClose={() => {}} />);
fireEvent.change(screen.getByLabelText(/load/i), { target: { value: '42.5' } });
expect(onValuesChange).toHaveBeenCalledWith({ load: '42.5' });
```

- [ ] **Step 2: Run the modal tests and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test PreflightModal.test.js DataSimulationModal.test.js --watchAll=false`

Expected: FAIL because the components do not exist.

- [ ] **Step 3: Implement modal interfaces**

```js
<Modal open={open} footer={null} onCancel={onClose}>
  {findings.map((finding) => (
    <button key={`${finding.code}-${finding.index}`} onClick={() => onLocate(finding.elementId)}>
      {formatFinding(finding)}
    </button>
  ))}
</Modal>
```

The simulation modal must include an enable switch, one labelled input per data-bound element, Reset and Close controls, and an explicit notice that values are local-only.

- [ ] **Step 4: Integrate with `DesignerApp`**

```js
const [preflightOpen, setPreflightOpen] = useState(false);
const [simulationOpen, setSimulationOpen] = useState(false);
const [simulationEnabled, setSimulationEnabled] = useState(false);
const [simulationValues, setSimulationValues] = useState({});
const renderImages = useMemo(
  () => simulationEnabled ? applySimulationOverrides(images, simulationValues) : images,
  [images, simulationEnabled, simulationValues]
);
```

Use `renderImages` only for `ConElement` and `setChart`. Continue using `imagesRef.current` for save, export, clipboard, history, selection and all mutation handlers. Add top-bar buttons, focus/selection behaviour for `onLocate`, and compact modal CSS.

- [ ] **Step 5: Run UI tests and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test PreflightModal.test.js DataSimulationModal.test.js --watchAll=false`

Expected: PASS.

### Task 4: Internationalization and Regression Verification

**Files:**
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`
- Modify: `wwwroot/src/Page/DesignerApp.keyboardShortcuts.test.js` only if the controls affect current keyboard coverage

- [ ] **Step 1: Add all visible labels to both dictionaries**

```js
preflight: {
  trigger: '...',
  title: '...',
  noIssues: '...',
},
simulation: {
  trigger: '...',
  title: '...',
  localOnly: '...',
  enable: '...',
  reset: '...',
}
```

- [ ] **Step 2: Verify source language policy**

Run: `npm run check:no-cjk`

Expected: PASS; only dictionary files contain Chinese text.

- [ ] **Step 3: Run focused and full tests**

Run: `node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false`

Expected: all existing tests plus the new validation, simulation and modal tests pass.

- [ ] **Step 4: Build the production bundle**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: build exits with code 0 and emits the patched production bundle.

- [ ] **Step 5: Commit the feature**

```bash
git add docs/superpowers/plans/2026-07-31-preflight-validation-data-simulation.md wwwroot/src/Page wwwroot/src/Assets/designer.css wwwroot/src/i18n/dictionaries
git commit -m "feat(designer): add preflight validation and data simulation"
```
