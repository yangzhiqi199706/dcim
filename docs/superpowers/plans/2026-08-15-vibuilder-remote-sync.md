# VIBuilder Remote Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an authenticated "Pull remote" workflow that backs up local `dcim` and `VIBuilder/Images`, then replaces both from a remote DCIM server with automatic rollback.

**Architecture:** The React designer collects remote SSH and database credentials, performs a preflight request, starts an asynchronous job, and polls status without persisting passwords. Production execution lives in the existing PHP 7.4 application on port 8086; a detached worker stops `dcim.service`, creates local backups, pulls a remote dump and Images archive, validates both, restores the database, atomically switches Images, and restores the prior service state. Only a narrowly scoped sudoers rule permits the PHP user to query, stop, and start `dcim.service`.

**Tech Stack:** React 18, Ant Design 5, Jest, Express development API, PHP 7.4/Flight, OpenSSH/Expect, rsync, tar, MySQL 5.7 command-line tools, systemd.

---

### Task 1: Frontend contract and validation

**Files:**
- Create: `wwwroot/src/Page/remoteSync.js`
- Create: `wwwroot/src/Page/remoteSync.test.js`

- [ ] **Step 1: Write the failing validation tests**

```js
expect(validateRemoteSyncForm(validForm)).toEqual({});
expect(validateRemoteSyncForm({ ...validForm, remoteHost: '127.0.0.1' }).remoteHost).toBeTruthy();
expect(createRemoteSyncPayload(validForm, 'token')).not.toHaveProperty('databaseName');
```

- [ ] **Step 2: Run the focused test and confirm RED**

Run: `npm test -- --runInBand --watch=false src/Page/remoteSync.test.js`

Expected: FAIL because `remoteSync.js` does not exist.

- [ ] **Step 3: Implement the fixed-target request contract**

```js
export const REMOTE_SYNC_TARGET = {
  databaseName: 'dcim',
  imagesPath: '/dcim/admin/localhost_8086/wwwroot/public/VIBuilder/Images/',
};

export function createRemoteSyncPayload(form, token) {
  return {
    token,
    remoteHost: form.remoteHost.trim(),
    sshPort: Number(form.sshPort || 22),
    sshUser: form.sshUser.trim(),
    sshPassword: form.sshPassword,
    dbUser: form.dbUser.trim(),
    dbPassword: form.dbPassword,
  };
}
```

- [ ] **Step 4: Run the focused test and confirm GREEN**

Run: `npm test -- --runInBand --watch=false src/Page/remoteSync.test.js`

Expected: PASS.

### Task 2: Ribbon command and synchronization modal

**Files:**
- Create: `wwwroot/src/Page/RemoteSyncModal.js`
- Create: `wwwroot/src/Page/RemoteSyncModal.test.js`
- Modify: `wwwroot/src/Page/DesignerRibbonToolbar.js`
- Modify: `wwwroot/src/Page/RibbonToolbarModel.js`
- Modify: `wwwroot/src/Page/DesignerApp.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`
- Modify: `wwwroot/src/Assets/designer.css`

- [ ] **Step 1: Write failing tests for the command and dialog contract**

```js
expect(getRibbonToolbarGroups('page').flatMap(group => group.commands)).toContain('remoteSync');
expect(source).toContain("getDataLocal('remoteSyncPreflight'");
expect(source).toContain("getDataLocal('remoteSyncStart'");
expect(source).toContain("getDataLocal('remoteSyncStatus'");
```

- [ ] **Step 2: Run the focused tests and confirm RED**

Run: `npm test -- --runInBand --watch=false src/Page/RibbonToolbarModel.test.js src/Page/RemoteSyncModal.test.js`

Expected: FAIL because the command and modal do not exist.

- [ ] **Step 3: Implement the UI workflow**

```jsx
<RemoteSyncModal
  open={remoteSyncOpen}
  onClose={() => setRemoteSyncOpen(false)}
/>
```

The modal must use labeled password fields, show the fixed database and Images path, require an explicit destructive-action checkbox, expose separate preflight and sync actions, poll only active jobs, clear passwords on close, and never write credentials to storage or logs.

- [ ] **Step 4: Run focused tests and confirm GREEN**

Run: `npm test -- --runInBand --watch=false src/Page/RibbonToolbarModel.test.js src/Page/RemoteSyncModal.test.js src/Page/remoteSync.test.js`

Expected: PASS.

### Task 3: Development API compatibility

**Files:**
- Create: `wwwroot/server/remote-sync-dev.js`
- Create: `wwwroot/server/remote-sync-dev.test.js`
- Modify: `wwwroot/server/local-api-routes.js`

- [ ] **Step 1: Write failing route tests**

```js
expect(routes['/api/local/remoteSyncPreflight']).toBeInstanceOf(Function);
expect(routes['/api/local/remoteSyncStart']).toBeInstanceOf(Function);
expect(routes['/api/local/remoteSyncStatus']).toBeInstanceOf(Function);
```

- [ ] **Step 2: Run the route test and confirm RED**

Run: `npm test -- --runInBand --watch=false server/remote-sync-dev.test.js`

Expected: FAIL because routes are missing.

- [ ] **Step 3: Add safe development handlers**

```js
function createRemoteSyncDevelopmentHandlers() {
  return {
    preflight: (_req, res) => res.json({ code: 400, msg: 'REMOTE_SYNC_PRODUCTION_ONLY', data: [] }),
    start: (_req, res) => res.json({ code: 400, msg: 'REMOTE_SYNC_PRODUCTION_ONLY', data: [] }),
    status: (_req, res) => res.json({ code: 400, msg: 'REMOTE_SYNC_JOB_NOT_FOUND', data: [] }),
  };
}
```

No development handler may execute shell commands or persist credentials.

- [ ] **Step 4: Run the route test and confirm GREEN**

Run: `npm test -- --runInBand --watch=false server/remote-sync-dev.test.js`

Expected: PASS.

### Task 4: Production PHP policy, controller, and worker

**Files:**
- Create: `deploy/remote-sync/src/controllers/RemoteSyncController.php`
- Create: `deploy/remote-sync/src/services/RemoteSyncPolicy.php`
- Create: `deploy/remote-sync/scripts/remote-sync-worker.php`
- Create: `deploy/remote-sync/tests/remote-sync-policy.test.php`
- Create: `deploy/remote-sync/routes.snippet.php`
- Create: `deploy/remote-sync/vibuilder-remote-sync.sudoers`

- [ ] **Step 1: Write failing PHP policy tests**

```php
assert(RemoteSyncPolicy::normalizeHost('192.168.0.60') === '192.168.0.60');
assert(RemoteSyncPolicy::normalizeHost('127.0.0.1') === '');
assert(RemoteSyncPolicy::isSafeArchiveEntry('Images/page/a.txt') === true);
assert(RemoteSyncPolicy::isSafeArchiveEntry('../etc/passwd') === false);
assert(array_key_exists('sshPassword', RemoteSyncPolicy::publicStatus(['sshPassword' => 'secret'])) === false);
```

- [ ] **Step 2: Run the test in PHP 7.4 and confirm RED**

Run in the 0.22 container staging directory: `php tests/remote-sync-policy.test.php`

Expected: FAIL because `RemoteSyncPolicy.php` does not exist.

- [ ] **Step 3: Implement authenticated job control**

```php
Flight::route('POST /api/local/remoteSyncPreflight', ['RemoteSyncController', 'preflight']);
Flight::route('POST /api/local/remoteSyncStart', ['RemoteSyncController', 'start']);
Flight::route('POST /api/local/remoteSyncStatus', ['RemoteSyncController', 'status']);
```

The controller must require a valid super-user token through `dcim_auth_user_by_token`, reject local/unsafe hosts, allow one job at a time, fork without serializing credentials, and return only redacted job state.

- [ ] **Step 4: Implement the worker transaction**

```text
preflight -> prepare remote snapshot -> stop dcim.service -> local backup
-> transfer -> checksum/archive validation -> temporary database import
-> replace dcim -> atomic Images switch -> restore prior service state
```

Any failure after local backup must restore both the database and Images, clean temporary remote/local files, and restore the prior `dcim.service` state. MySQL, Apache, PHP-FPM, and the Docker container must remain running.

- [ ] **Step 5: Run PHP policy and syntax checks**

Run: `php tests/remote-sync-policy.test.php`

Run: `php -l src/controllers/RemoteSyncController.php`

Run: `php -l scripts/remote-sync-worker.php`

Expected: all commands exit 0.

### Task 5: Verification and guarded deployment

**Files:**
- Create: `deploy/remote-sync/install.sh`
- Create: `deploy/remote-sync/uninstall.sh`

- [ ] **Step 1: Run repository verification**

Run: `npm test -- --runInBand --watch=false`

Run: `npm run check:no-cjk`

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: all commands exit 0.

- [ ] **Step 2: Back up production files before replacement**

```text
/dcim/admin/localhost_8086/wwwroot/src/routes.php
/dcim/admin/localhost_8086/wwwroot/src/controllers/RemoteSyncController.php
/dcim/admin/localhost_8086/wwwroot/src/services/RemoteSyncPolicy.php
/dcim/admin/localhost_8086/wwwroot/scripts/remote-sync-worker.php
/dcim/admin/localhost_8086/wwwroot/public/VIBuilder/
/etc/sudoers.d/vibuilder-remote-sync
```

- [ ] **Step 3: Install and validate configuration**

Run in the container: `visudo -cf /etc/sudoers.d/vibuilder-remote-sync`

Run in the container as `www`: `sudo -n /usr/bin/systemctl is-active dcim.service`

Expected: sudoers validates and only the three allowed service commands are available.

- [ ] **Step 4: Verify without executing destructive synchronization**

Open: `http://192.168.0.22:8086/VIBuilder/index.html`

Verify the Ribbon command opens the dialog, invalid/local hosts are rejected, authentication is required, preflight errors are actionable, passwords disappear when the dialog closes, and no database or Images replacement job is started without explicit confirmation.

---

No commits are created during this plan because several target frontend files already contain user-owned uncommitted changes. Integration must be staged by exact paths or reviewed as a patch before any commit.
