# Remote Data Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a designer to select device data points from another DCIM server and keep those bindings live in preview mode.

**Architecture:** Store an optional normalized `sourceHost` on each data binding; an empty value means the current server. A focused data-source module validates hosts, builds endpoint URLs, groups bindings per server, tags returned rows with their source, and merges partial responses without mixing equal device IDs from different servers. The parameter dialog and preview pipeline consume this module while legacy pages continue to use the local server.

**Tech Stack:** React 18, Ant Design, Axios, Jest/react-scripts, existing DCIM POST APIs.

---

### Task 1: Data source domain module

**Files:**
- Create: `wwwroot/src/Assets/dataSource.js`
- Create: `wwwroot/src/Assets/dataSource.test.js`

- [ ] **Step 1: Write failing normalization and grouping tests**

```js
expect(normalizeDataSourceHost('192.168.0.60')).toBe('192.168.0.60:8086');
expect(normalizeDataSourceHost('192.168.0.60:9000')).toBe('192.168.0.60:9000');
expect(() => normalizeDataSourceHost('192.168.0.60/path')).toThrow();
expect(isDataSourceRecordMatch({ DevID: 1, __sourceHost: 'a:8086' }, { key: 1, sourceHost: 'b:8086' }, 'DevID', 'key')).toBe(false);
```

- [ ] **Step 2: Run test and verify the module-not-found failure**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js`
Expected: FAIL because `./dataSource` does not exist.

- [ ] **Step 3: Implement normalization, URL building, response tagging, grouping, and partial merge**

```js
export const normalizeDataSourceHost = (value) => { /* validate host and default port */ };
export const buildDataSourceApiUrl = (host, path) => { /* current protocol + normalized host */ };
export const collectPreviewDataSourceGroups = (shapes) => { /* group binding IDs by sourceHost */ };
export const requestDataSourceGroups = async (groups, path, payloadFactory, requester) => { /* allSettled + tagged merge */ };
```

- [ ] **Step 4: Run tests and verify they pass**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js`
Expected: PASS.

### Task 2: Dynamic DCIM request client

**Files:**
- Modify: `wwwroot/src/Assets/httpsend.js`
- Test: `wwwroot/src/Assets/dataSource.test.js`

- [ ] **Step 1: Add a failing test for local and remote requester selection**

```js
expect(buildDataSourceApiUrl('', 'GetDeviceListKey')).toContain('/GetDeviceListKey');
expect(buildDataSourceApiUrl('192.168.0.60', 'GetDeviceListKey')).toContain('192.168.0.60:8086/GetDeviceListKey');
```

- [ ] **Step 2: Run the focused test and verify the expected failure**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js`
Expected: FAIL before URL/request support is implemented.

- [ ] **Step 3: Add `httpsend.getDataFrom(sourceHost, path, data)`**

```js
getDataFrom(sourceHost, url, data) {
  if (!sourceHost) return this.getData(url, data);
  return requestDataSource(sourceHost, url, data);
}
```

- [ ] **Step 4: Re-run the focused tests**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js`
Expected: PASS.

### Task 3: Parameter dialog data-source controls

**Files:**
- Modify: `wwwroot/src/Page/ElementAttr.js`
- Modify: `wwwroot/src/Assets/style.css`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`
- Create: `wwwroot/src/Page/ElementAttr.dataSource.test.js`

- [ ] **Step 1: Write a failing integration source test**

```js
expect(source).toContain("sourceHost: dataSourceHost");
expect(source).toContain("httpsend.getDataFrom(dataSourceHost");
expect(source).toContain("t('dataSource.testConnection')");
```

- [ ] **Step 2: Run test and verify missing controls fail**

Run: `npm test -- --watch=false --runInBand src/Page/ElementAttr.dataSource.test.js`
Expected: FAIL because the data-source states and controls are absent.

- [ ] **Step 3: Add source selector, IP input, connection test, and binding persistence**

```js
const [dataSourceHost, setDataSourceHost] = useState(initialSourceHost);
const response = await httpsend.getDataFrom(candidateHost, 'GetDeviceListKey', { ComboBox: 'all' });
const desc = { key, name, type, cmdtype, src, sourceHost: dataSourceHost };
```

- [ ] **Step 4: Run dialog and existing attribute tests**

Run: `npm test -- --watch=false --runInBand src/Page/ElementAttr.dataSource.test.js src/Page/ElementAttr.batchCommonAttributes.test.js`
Expected: PASS.

### Task 4: Source-aware preview aggregation

**Files:**
- Modify: `wwwroot/src/Page/PreviewApp.js`
- Modify: `wwwroot/src/Page/PreviewDeal.js`
- Modify: `wwwroot/src/Page/PreviewDeal.test.js`
- Create: `wwwroot/src/Page/PreviewApp.dataSource.test.js`

- [ ] **Step 1: Write failing cross-server ID-isolation tests**

```js
expect(isDataSourceRecordMatch(remoteRow, localBinding, 'DevID', 'key')).toBe(false);
expect(isDataSourceRecordMatch(remoteRow, remoteBinding, 'DevID', 'key')).toBe(true);
```

- [ ] **Step 2: Run tests and verify PreviewApp is not source-aware yet**

Run: `npm test -- --watch=false --runInBand src/Page/PreviewApp.dataSource.test.js src/Page/PreviewDeal.test.js`
Expected: FAIL on missing grouping calls/source matching.

- [ ] **Step 3: Group preview API calls and require source matches during rendering**

```js
const groups = collectPreviewDataSourceGroups(imagesdata);
const realtime = await requestDataSourceGroups(groups, 'GetDevCommandListKey', payloadForRealtime, httpsend.getDataFrom);
const match = isDataSourceRecordMatch(row, binding, 'DevID', 'key');
```

- [ ] **Step 4: Run preview tests**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js src/Page/PreviewApp.dataSource.test.js src/Page/PreviewDeal.test.js`
Expected: PASS.

### Task 5: Full verification

**Files:**
- Verify all modified files.

- [ ] **Step 1: Run focused and neighboring tests**

Run: `npm test -- --watch=false --runInBand src/Assets/dataSource.test.js src/Page/ElementAttr.dataSource.test.js src/Page/PreviewApp.dataSource.test.js src/Page/PreviewDeal.test.js src/Page/previewDataBatch.test.js src/Page/previewIncrementalRender.test.js`
Expected: PASS with zero failed tests.

- [ ] **Step 2: Check source character policy**

Run: `npm run check:no-cjk`
Expected: PASS.

- [ ] **Step 3: Build production assets**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`
Expected: exit code 0; only documented pre-existing regex warnings are acceptable.
