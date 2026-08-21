# Global Remote Data Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace per-element data-source controls with one global ribbon command that configures a remote DCIM API host for all device data reads without writing the local database.

**Architecture:** Persist one normalized optional host in browser storage. Designer binding dialogs and preview rendering resolve this host first, while existing per-binding `sourceHost` values remain the fallback when global mode is disabled. A ribbon modal tests the remote API, saves or disables the global setting, and never invokes database or file synchronization routes.

**Tech Stack:** React 18, Ant Design 5, Axios, Jest/react-scripts, existing DCIM POST APIs.

---

### Task 1: Global data-source configuration module

**Files:**
- Create: `wwwroot/src/Assets/globalDataSource.js`
- Create: `wwwroot/src/Assets/globalDataSource.test.js`

- [ ] **Step 1: Write failing storage and binding-overlay tests**

```js
expect(saveGlobalDataSource({ enabled: true, host: '192.168.0.60' }, storage))
  .toEqual({ enabled: true, host: '192.168.0.60:8086' });
expect(readGlobalDataSource(storage).host).toBe('192.168.0.60:8086');
expect(applyGlobalDataSourceToShapes(shapes, '192.168.0.60')[0]
  .moduleJson.attrs.dataKey[0].sourceHost).toBe('192.168.0.60:8086');
```

- [ ] **Step 2: Run the test and confirm the module is missing**

Run: `npm test -- --runInBand --watchAll=false src/Assets/globalDataSource.test.js`

Expected: FAIL because `globalDataSource.js` does not exist.

- [ ] **Step 3: Implement normalized persistence and non-mutating binding overlay**

```js
export const GLOBAL_DATA_SOURCE_STORAGE_KEY = 'vibuilder_global_data_source_v1';
export function saveGlobalDataSource(config, storage = localStorage) { /* normalize and store */ }
export function readGlobalDataSource(storage = localStorage) { /* return disabled on invalid data */ }
export function applyGlobalDataSourceToShapes(shapes, host) { /* clone only when enabled */ }
```

- [ ] **Step 4: Run the focused test and confirm it passes**

Run: `npm test -- --runInBand --watchAll=false src/Assets/globalDataSource.test.js`

Expected: PASS.

### Task 2: Ribbon command and configuration modal

**Files:**
- Create: `wwwroot/src/Page/GlobalDataSourceModal.js`
- Create: `wwwroot/src/Page/GlobalDataSourceModal.test.js`
- Modify: `wwwroot/src/Page/RibbonToolbarModel.js`
- Modify: `wwwroot/src/Page/RibbonToolbarModel.test.js`
- Modify: `wwwroot/src/Page/DesignerRibbonToolbar.js`
- Modify: `wwwroot/src/Page/DesignerApp.js`
- Modify: `wwwroot/src/Assets/designer.css`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [ ] **Step 1: Write failing command and modal contract tests**

```js
expect(pageCommands).toContain('globalDataSource');
expect(modalSource).toContain("getDataFrom(candidateHost, 'GetDeviceListKey'");
expect(modalSource).toContain('saveGlobalDataSource');
expect(modalSource).not.toContain('remoteSyncStart');
```

- [ ] **Step 2: Run focused tests and confirm the command/modal are missing**

Run: `npm test -- --runInBand --watchAll=false src/Page/RibbonToolbarModel.test.js src/Page/GlobalDataSourceModal.test.js`

Expected: FAIL because the command and modal do not exist.

- [ ] **Step 3: Add the system command and modal workflow**

The modal accepts `IP` or `IP:port`, defaults the port to 8086, tests `GetDeviceListKey`, persists only the normalized host, supports disabling remote mode, and reports the number of devices returned.

- [ ] **Step 4: Run focused tests and confirm they pass**

Run: `npm test -- --runInBand --watchAll=false src/Page/RibbonToolbarModel.test.js src/Page/GlobalDataSourceModal.test.js src/Assets/globalDataSource.test.js`

Expected: PASS.

### Task 3: Remove per-element controls and use the global host for binding dialogs

**Files:**
- Modify: `wwwroot/src/Page/ElementAttr.js`
- Modify: `wwwroot/src/Page/ElementAttr.dataSource.test.js`
- Modify: `wwwroot/src/Assets/style.css`
- Modify: `wwwroot/src/Assets/designer.css`

- [ ] **Step 1: Change the integration test to require global-host usage and absence of property controls**

```js
expect(source).toContain('const dataSourceHost = globalDataSourceHost || initialDataSourceHost;');
expect(source).not.toContain('dataSourcePropertyEntry');
expect(source).not.toContain('chooseDataSource');
```

- [ ] **Step 2: Run the focused test and confirm it fails against the current property UI**

Run: `npm test -- --runInBand --watchAll=false src/Page/ElementAttr.dataSource.test.js`

Expected: FAIL because the direct settings row and per-element modal still exist.

- [ ] **Step 3: Pass `globalDataSourceHost` from `DesignerApp`, remove the property/modal controls, and keep legacy binding fallback**

Binding requests use the global host when enabled. New bindings persist the effective host so saved pages continue to preview correctly on another workstation; old per-binding hosts remain readable when global mode is disabled.

- [ ] **Step 4: Run ElementAttr and neighboring tests**

Run: `npm test -- --runInBand --watchAll=false src/Page/ElementAttr.dataSource.test.js src/Page/ElementAttr.batchHostBinding.test.js src/Page/ElementAttr.batchCommonAttributes.test.js`

Expected: PASS.

### Task 4: Apply the global host to preview data reads

**Files:**
- Modify: `wwwroot/src/Page/PreviewApp.js`
- Modify: `wwwroot/src/Page/PreviewApp.dataSource.test.js`

- [ ] **Step 1: Write a failing preview integration assertion**

```js
expect(source).toContain('readGlobalDataSource');
expect(source).toContain('applyGlobalDataSourceToShapes');
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run: `npm test -- --runInBand --watchAll=false src/Page/PreviewApp.dataSource.test.js`

Expected: FAIL because preview does not read the global setting.

- [ ] **Step 3: Overlay the global host on parsed preview shapes before grouping and requests**

The overlay is in-memory only and does not modify saved page text. Disabling global mode restores existing local/per-binding behavior.

- [ ] **Step 4: Run preview and data-source tests**

Run: `npm test -- --runInBand --watchAll=false src/Assets/globalDataSource.test.js src/Assets/dataSource.test.js src/Page/PreviewApp.dataSource.test.js src/Page/PreviewDeal.test.js`

Expected: PASS.

### Task 5: Full verification and browser QA

**Files:**
- Verify all modified files.

- [ ] **Step 1: Run the full Jest suite**

Run: `npm test -- --runInBand --watchAll=false`

Expected: zero failed suites and tests.

- [ ] **Step 2: Run source character validation**

Run: `npm run check:no-cjk`

Expected: PASS.

- [ ] **Step 3: Build production assets**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: exit code 0; only the two existing `no-control-regex` warnings are acceptable.

- [ ] **Step 4: Verify the actual interface**

Confirm the Page/System ribbon shows both “Remote Data Source” and “Pull Remote”, the global dialog accepts IP or IP:port, the property panel no longer shows data-source settings, and no database or Images synchronization route is called.
