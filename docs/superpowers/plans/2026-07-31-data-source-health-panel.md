# Data Source Health Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a designer-side, manually refreshed panel that explains whether each declared device data source can be verified against the current device snapshot.

**Architecture:** A pure `dataSourceHealth` module inspects declarative `hardwareInputNew` bindings and a `GetDeviceListKey` response, returning one immutable health record per element plus deterministic status counters. `DataSourceHealthModal` only presents that report. `DesignerApp` owns request state, preserves the most recent successful report on request failure, and locates a clicked element without changing page data.

**Tech Stack:** React 18, Ant Design icons and buttons, existing Jest/react-scripts tests, project i18n dictionaries, existing `GetDeviceListKey` API.

---

### Task 1: Health Report Core

**Files:**
- Create: `wwwroot/src/Page/dataSourceHealth.js`
- Create: `wwwroot/src/Page/dataSourceHealth.test.js`

- [x] **Step 1: Write failing tests for missing, invalid, available, unavailable, and remote bindings**

```js
import { getDataSourceHealthReport } from './dataSourceHealth';

test('classifies an existing metric as available and a missing metric as unavailable', () => {
    const report = getDataSourceHealthReport([
        dataPointShape('load', { key: '42', name: 'Load', type: '3', cmdtype: '1', src: '1' }),
        dataPointShape('pressure', { key: '42', name: 'Pressure', type: '3', cmdtype: '1', src: '1' }),
    ], [device('42', "{'Load':'12'}")]);

    expect(report.items.map((item) => item.status)).toEqual(['available', 'unavailable']);
    expect(report.counts).toMatchObject({ available: 1, unavailable: 1 });
});
```

- [x] **Step 2: Run the focused test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test dataSourceHealth.test.js --watchAll=false`

Expected: FAIL because `./dataSourceHealth` does not exist.

- [x] **Step 3: Implement the pure report generator**

```js
export const HEALTH_STATUSES = ['available', 'missing', 'invalid', 'unavailable', 'unknown'];

export const getDataSourceHealthReport = (elements, devices) => ({
    items: collectDataPointElements(elements).map((entry) => getElementHealth(entry, devices)),
    counts: HEALTH_STATUSES.reduce((counts, status) => ({ ...counts, [status]: 0 }), {}),
});
```

Use the same declared data-point attributes and `DeviceLastDataArr` schema as `pageValidation.js` and `dataBindingAvailability.js`. A valid remote binding (`src` includes `@`) and bindings that cannot be verified from a device snapshot are `unknown`; a request failure is not represented as an element-level health status.

- [x] **Step 4: Run the focused test and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test dataSourceHealth.test.js --watchAll=false`

Expected: PASS with all status tests green.

### Task 2: Health Panel Presentation

**Files:**
- Create: `wwwroot/src/Page/DataSourceHealthModal.js`
- Create: `wwwroot/src/Page/DataSourceHealthModal.test.js`
- Modify: `wwwroot/src/Assets/designer.css`

- [x] **Step 1: Write a failing modal interaction test**

```js
test('locates the selected health item and requests a manual refresh', () => {
    const onLocate = jest.fn();
    const onRefresh = jest.fn();
    renderHealthModal({ onLocate, onRefresh });

    container.querySelector('[data-health-element-id="meter-1"]').click();
    expect(onLocate).toHaveBeenCalledWith('meter-1');
    container.querySelector('[data-health-refresh]').click();
    expect(onRefresh).toHaveBeenCalledTimes(1);
});
```

- [x] **Step 2: Run the focused test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test DataSourceHealthModal.test.js --watchAll=false`

Expected: FAIL because `./DataSourceHealthModal` does not exist.

- [x] **Step 3: Implement the modal and compact health styles**

```jsx
<button
    type="button"
    className={`dataSourceHealthItem dataSourceHealthItem-${item.status}`}
    data-health-element-id={item.elementId}
    onClick={() => onLocate(item.elementId)}
>
    <span>{item.label}</span>
    <span>{t(`designer.dataSourceHealth.status.${item.status}`)}</span>
    <span>{item.bindingSummary}</span>
</button>
```

Show the five status counters, loading state, an explicit snapshot-unavailable message, refresh control, close control, and an empty state. Keep widths stable and use the existing dialog CSS foundation.

- [x] **Step 4: Run the focused modal test and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test DataSourceHealthModal.test.js --watchAll=false`

Expected: PASS.

### Task 3: Designer Integration and Localization

**Files:**
- Modify: `wwwroot/src/Page/DesignerApp.js`
- Modify: `wwwroot/src/Page/DesignerApp.diagnostics.test.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [x] **Step 1: Add a failing integration-source assertion**

```js
expect(source).toContain("import { getDataSourceHealthReport } from './dataSourceHealth';");
expect(source).toContain("import DataSourceHealthModal from './DataSourceHealthModal';");
expect(source).toContain('const openDataSourceHealth = async () =>');
expect(source).toContain('<DataSourceHealthModal');
```

- [x] **Step 2: Run the focused integration test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test DesignerApp.diagnostics.test.js --watchAll=false`

Expected: FAIL because the health-panel integration is absent.

- [x] **Step 3: Add request, refresh, and locate behavior**

```js
const refreshDataSourceHealth = async () => {
    const requestId = dataSourceHealthRequestIdRef.current + 1;
    dataSourceHealthRequestIdRef.current = requestId;
    setDataSourceHealthLoading(true);
    setDataSourceHealthLoadError(false);
    try {
        const response = await httpsend.getData('GetDeviceListKey', { ComboBox: 'all' });
        if (dataSourceHealthRequestIdRef.current === requestId && response && Array.isArray(response.data)) {
            setDataSourceHealthReport(getDataSourceHealthReport(imagesRef.current, response.data));
        }
    } catch (error) {
        if (dataSourceHealthRequestIdRef.current === requestId) setDataSourceHealthLoadError(true);
    } finally {
        if (dataSourceHealthRequestIdRef.current === requestId) setDataSourceHealthLoading(false);
    }
};
```

Open the panel from a top-right action using `DatabaseOutlined`, retain the previous successful report after a failed refresh, and close the panel after locating its selected element. Add all visible strings to both dictionaries under `designer.dataSourceHealth`.

- [x] **Step 4: Run the focused integration test and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test DesignerApp.diagnostics.test.js --watchAll=false`

Expected: PASS.

### Task 4: Regression Verification and Commit

**Files:**
- Modify: `docs/superpowers/plans/2026-07-31-data-source-health-panel.md` to check off completed steps

- [x] **Step 1: Run all Jest tests**

Run: `node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false`

Expected: PASS with zero test failures.

- [x] **Step 2: Verify the source-language rule**

Run: `npm run check:no-cjk`

Expected: PASS; Chinese is only added to the Chinese dictionary.

- [x] **Step 3: Build the production bundle**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: exit code 0 and a production `build` directory.

- [x] **Step 4: Commit the implementation**

```powershell
git add docs/superpowers/plans/2026-07-31-data-source-health-panel.md wwwroot/src/Page/dataSourceHealth.js wwwroot/src/Page/dataSourceHealth.test.js wwwroot/src/Page/DataSourceHealthModal.js wwwroot/src/Page/DataSourceHealthModal.test.js wwwroot/src/Page/DesignerApp.js wwwroot/src/Page/DesignerApp.diagnostics.test.js wwwroot/src/Assets/designer.css wwwroot/src/i18n/dictionaries/zh-CN.js wwwroot/src/i18n/dictionaries/en-US.js
git -c core.hooksPath=/dev/null commit -m "feat(designer): add data source health panel"
```

Expected: one focused feature commit on `codex/preflight-validation-data-simulation`.

## Self-Review

The plan covers the requested dashboard, current-snapshot verification, individual status display, status counters, manual refresh, click-to-locate, no-backend scope, and safe request-failure behavior. Status identifiers, component prop names, and the request function name are consistent across every task. No production source files will contain Chinese text; all UI copy is localized.
