# Batch Page Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-click export that creates one outer ZIP containing one compatible ZIP package for each page in My Pages.

**Architecture:** The ItemBox passes the currently loaded page metadata to a new local API endpoint. The local API uses one shared page-package builder for both single-page and batch export, so every nested package includes its page text and referenced uploaded images. The browser downloads only the outer ZIP returned by the API.

**Tech Stack:** React 18, Ant Design, Express, Node.js fs/path/zlib, custom ZIP writer.

---

### Task 1: Define the batch export regression test

**Files:**
- Create: `wwwroot/server/local-api-routes.export-all.test.js`
- Modify: `wwwroot/server/local-api-routes.js`

- [x] **Step 1: Write the failing test**

Create two temporary page files and one uploaded image, call the future `/api/local/exportAll` handler with both page metadata records, then assert a successful response, `exportedCount === 2`, and an outer ZIP containing two nested ZIP filenames.

- [x] **Step 2: Run test to verify it fails**

Run: `node server/local-api-routes.export-all.test.js`

Expected: fail because `/api/local/exportAll` has not been registered.

- [x] **Step 3: Implement the shared package builder and batch route**

Extract the existing single-page page-text/image collection into `createPageExportPackage(sourceFile, pageName)`. Register `exportAll`, produce unique nested `<page-name>.zip` entries, skip missing source files, and save the outer `pages_<timestamp>.zip` in `Images/exports`.

- [x] **Step 4: Run the new regression test**

Run: `node server/local-api-routes.export-all.test.js`

Expected: pass and clean up all test page, image, and export files.

### Task 2: Add the My Pages command

**Files:**
- Modify: `wwwroot/src/Page/ItemBox.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [x] **Step 1: Add localized labels**

Add `itemBox.exportAll`, `itemBox.noPagesToExport`, `itemBox.exportAllSuccess`, and `itemBox.exportAllPartial` in both language dictionaries.

- [x] **Step 2: Implement the browser command**

Map loaded `pagedata` records with a `PageTxt` into `{ pageName, pageTxt, pageIndex }`, post them to `exportAll`, download the returned `fileUrl`, and report skipped pages without blocking a partial successful export.

- [x] **Step 3: Add the toolbar button**

Render the localized batch-export button beside Import and the selected-page Export command. Disable it only when there are no page files to export.

### Task 3: Verify

**Files:**
- Test: `wwwroot/server/local-api-routes.export-all.test.js`

- [x] **Step 1: Run local API regression tests**

Run: `node server/local-api-routes.export-all.test.js`; `node server/local-api-routes.test.js`; `node server/local-api-routes.masterControls.test.js`; `node server/masterControlStore.test.js`.

- [x] **Step 2: Run source validation and build**

Run: `npm run check:no-cjk`; `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`.

- [x] **Step 3: Inspect the diff**

Run: `git diff --check` and confirm that only the planned server, UI, dictionary, test, and plan files changed.
