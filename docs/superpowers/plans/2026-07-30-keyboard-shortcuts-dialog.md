# Keyboard Shortcuts Dialog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a toolbar help button that opens a localized, complete keyboard-shortcuts dialog in the designer.

**Architecture:** Keep the shortcut catalog in a small data module, render that catalog through a focused modal component, and keep only the open/close state in `DesignerApp`. This preserves the existing keyboard handlers and leaves preview mode unchanged because `DesignerApp` is never rendered there.

**Tech Stack:** React 18, Ant Design 5 Modal and Button, `@ant-design/icons`, Jest through `react-scripts`, project i18n helper.

---

## File Structure

- Create: `wwwroot/src/Page/keyboardShortcuts.js` - canonical groups, visible key labels, and i18n action keys.
- Create: `wwwroot/src/Page/keyboardShortcuts.test.js` - catalog coverage tests for every implemented command shown to users.
- Create: `wwwroot/src/Page/KeyboardShortcutsModal.js` - read-only modal and shortcut-row rendering.
- Create: `wwwroot/src/Page/KeyboardShortcutsModal.test.js` - modal rendering contract tests.
- Create: `wwwroot/src/Page/DesignerApp.keyboardShortcuts.test.js` - toolbar trigger and state wiring tests.
- Modify: `wwwroot/src/Page/DesignerApp.js` - import modal and keyboard icon, add local state, render trigger and dialog.
- Modify: `wwwroot/src/Assets/designer.css` - responsive shortcut-list layout and `kbd` appearance.
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js` - Chinese shortcut titles and actions.
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js` - matching English shortcut titles and actions.

### Task 1: Define The Shortcut Catalog And Localized Copy

**Files:**
- Create: `wwwroot/src/Page/keyboardShortcuts.test.js`
- Create: `wwwroot/src/Page/keyboardShortcuts.js`
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`

- [ ] **Step 1: Write the failing catalog test**

```js
import { KEYBOARD_SHORTCUT_GROUPS } from './keyboardShortcuts';

const visibleKeys = KEYBOARD_SHORTCUT_GROUPS
    .flatMap((group) => group.items)
    .map((item) => item.keys);

describe('keyboard shortcut catalog', () => {
    test('documents all implemented keyboard and multi-select commands', () => {
        expect(visibleKeys).toEqual(expect.arrayContaining([
            'Ctrl/Cmd + Click', 'Arrow keys', 'Shift + Arrow keys',
            'Ctrl + G', 'Ctrl + Shift + J', 'Ctrl + C', 'Ctrl + X',
            'Ctrl + V', 'Ctrl + Z', 'Ctrl + H', 'Delete', 'Ctrl + Arrow Up',
            'Ctrl + Arrow Down', 'Ctrl + E', 'Ctrl + B', 'Ctrl + K',
            'Ctrl + Shift + K', 'Ctrl + L', 'Ctrl + N', 'Ctrl + S',
        ]));
        expect(KEYBOARD_SHORTCUT_GROUPS).toHaveLength(5);
    });
});
```

- [ ] **Step 2: Run the catalog test and verify it fails**

Run:

```powershell
$env:CI = 'true'
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/keyboardShortcuts.test.js
```

Expected: FAIL because `./keyboardShortcuts` does not yet exist.

- [ ] **Step 3: Implement the catalog and dictionary entries**

Create `wwwroot/src/Page/keyboardShortcuts.js`:

```js
export const KEYBOARD_SHORTCUT_GROUPS = [
    {
        titleKey: 'designer.shortcuts.groups.selection',
        items: [
            { keys: 'Ctrl/Cmd + Click', actionKey: 'designer.shortcuts.actions.multiSelect' },
            { keys: 'Arrow keys', actionKey: 'designer.shortcuts.actions.moveOnePixel' },
            { keys: 'Shift + Arrow keys', actionKey: 'designer.shortcuts.actions.moveTenPixels' },
        ],
    },
    {
        titleKey: 'designer.shortcuts.groups.grouping',
        items: [
            { keys: 'Ctrl + G', actionKey: 'designer.shortcuts.actions.group' },
            { keys: 'Ctrl + Shift + J', actionKey: 'designer.shortcuts.actions.ungroup' },
        ],
    },
    {
        titleKey: 'designer.shortcuts.groups.editing',
        items: [
            { keys: 'Ctrl + C', actionKey: 'designer.shortcuts.actions.copy' },
            { keys: 'Ctrl + X', actionKey: 'designer.shortcuts.actions.cut' },
            { keys: 'Ctrl + V', actionKey: 'designer.shortcuts.actions.paste' },
            { keys: 'Ctrl + Z', actionKey: 'designer.shortcuts.actions.undo' },
            { keys: 'Ctrl + H', actionKey: 'designer.shortcuts.actions.replaceText' },
            { keys: 'Delete', actionKey: 'designer.shortcuts.actions.remove' },
        ],
    },
    {
        titleKey: 'designer.shortcuts.groups.layerAndLock',
        items: [
            { keys: 'Ctrl + Arrow Up', actionKey: 'designer.shortcuts.actions.layerUp' },
            { keys: 'Ctrl + Arrow Down', actionKey: 'designer.shortcuts.actions.layerDown' },
            { keys: 'Ctrl + E', actionKey: 'designer.shortcuts.actions.toTop' },
            { keys: 'Ctrl + B', actionKey: 'designer.shortcuts.actions.toBottom' },
            { keys: 'Ctrl + K', actionKey: 'designer.shortcuts.actions.lock' },
            { keys: 'Ctrl + Shift + K', actionKey: 'designer.shortcuts.actions.unlock' },
            { keys: 'Ctrl + L', actionKey: 'designer.shortcuts.actions.lockAlternative' },
            { keys: 'Ctrl + N', actionKey: 'designer.shortcuts.actions.unlockAlternative' },
        ],
    },
    {
        titleKey: 'designer.shortcuts.groups.page',
        items: [
            { keys: 'Ctrl + S', actionKey: 'designer.shortcuts.actions.save' },
        ],
    },
];
```

Add this exact `shortcuts` object inside the existing `designer` object in `zh-CN.js`:

```js
shortcuts: {
    title: '快捷键说明',
    trigger: '快捷键',
    groups: {
        selection: '选择与移动',
        grouping: '组合',
        editing: '编辑',
        layerAndLock: '层级与锁定',
        page: '页面操作',
    },
    actions: {
        multiSelect: '多选或取消选择元素', moveOnePixel: '移动选中元素 1px',
        moveTenPixels: '移动选中元素 10px', group: '组合选中元素',
        ungroup: '取消组合选中元素', copy: '复制选中元素', cut: '剪切选中元素',
        paste: '粘贴元素', undo: '撤销上一步操作', replaceText: '替换选中元素中的文本',
        remove: '删除选中元素', layerUp: '上移一层', layerDown: '下移一层',
        toTop: '置于顶层', toBottom: '置于底层', lock: '锁定选中元素',
        unlock: '解除锁定选中元素', lockAlternative: '锁定选中元素（备用快捷键）',
        unlockAlternative: '解除锁定选中元素（备用快捷键）', save: '保存页面',
    },
},
```

Add the matching `shortcuts` object in `en-US.js`, with these action strings: `Select or deselect elements`, `Move selected elements by 1px`, `Move selected elements by 10px`, `Group selected elements`, `Ungroup selected elements`, `Copy selected elements`, `Cut selected elements`, `Paste elements`, `Undo the previous action`, `Replace text in selected elements`, `Delete selected elements`, `Move forward one layer`, `Move backward one layer`, `Move to the top layer`, `Move to the bottom layer`, `Lock selected elements`, `Unlock selected elements`, `Lock selected elements (alternative shortcut)`, `Unlock selected elements (alternative shortcut)`, and `Save the page`.

- [ ] **Step 4: Run the catalog test and verify it passes**

Run the Step 2 command again.

Expected: PASS with one suite and one passing test.

- [ ] **Step 5: Commit the catalog slice**

```powershell
git add wwwroot/src/Page/keyboardShortcuts.js wwwroot/src/Page/keyboardShortcuts.test.js wwwroot/src/i18n/dictionaries/zh-CN.js wwwroot/src/i18n/dictionaries/en-US.js
git commit -m "feat(designer): add shortcut help catalog"
```

### Task 2: Build The Accessible Shortcut Dialog

**Files:**
- Create: `wwwroot/src/Page/KeyboardShortcutsModal.test.js`
- Create: `wwwroot/src/Page/KeyboardShortcutsModal.js`
- Modify: `wwwroot/src/Assets/designer.css`

- [ ] **Step 1: Write the failing modal contract test**

```js
import fs from 'fs';
import path from 'path';

describe('keyboard shortcuts modal', () => {
    const file = path.join(__dirname, 'KeyboardShortcutsModal.js');

    test('renders catalog groups in a closable modal', () => {
        const source = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '';
        expect(source).toContain("import { Modal } from 'antd';");
        expect(source).toContain('KEYBOARD_SHORTCUT_GROUPS.map');
        expect(source).toContain('onCancel={onClose}');
        expect(source).toContain('footer={null}');
        expect(source).toContain('<kbd>{item.keys}</kbd>');
    });
});
```

- [ ] **Step 2: Run the modal test and verify it fails**

Run:

```powershell
$env:CI = 'true'
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/KeyboardShortcutsModal.test.js
```

Expected: FAIL because the component file is absent.

- [ ] **Step 3: Implement the modal and its responsive styles**

Create `wwwroot/src/Page/KeyboardShortcutsModal.js`:

```jsx
import React from 'react';
import { Modal } from 'antd';
import { t } from '../i18n';
import { KEYBOARD_SHORTCUT_GROUPS } from './keyboardShortcuts';

function KeyboardShortcutsModal({ open, onClose }) {
    return (
        <Modal
            title={t('designer.shortcuts.title')}
            open={open}
            onCancel={onClose}
            footer={null}
            width={680}
            className="keyboardShortcutsModal"
        >
            <div className="keyboardShortcutsList">
                {KEYBOARD_SHORTCUT_GROUPS.map((group) => (
                    <section className="keyboardShortcutsGroup" key={group.titleKey}>
                        <h3>{t(group.titleKey)}</h3>
                        {group.items.map((item) => (
                            <div className="keyboardShortcutsRow" key={item.keys}>
                                <kbd>{item.keys}</kbd>
                                <span>{t(item.actionKey)}</span>
                            </div>
                        ))}
                    </section>
                ))}
            </div>
        </Modal>
    );
}

export default KeyboardShortcutsModal;
```

Append to `wwwroot/src/Assets/designer.css`:

```css
.keyboardShortcutsModal .ant-modal-body { max-height: 65vh; overflow-y: auto; }
.keyboardShortcutsGroup { padding: 0 0 12px; border-bottom: 1px solid #e5e7eb; }
.keyboardShortcutsGroup + .keyboardShortcutsGroup { padding-top: 14px; }
.keyboardShortcutsGroup:last-child { border-bottom: 0; }
.keyboardShortcutsGroup h3 { margin: 0 0 8px; font-size: 14px; font-weight: 600; }
.keyboardShortcutsRow { display: grid; grid-template-columns: minmax(150px, 220px) minmax(0, 1fr); gap: 12px; align-items: center; min-height: 30px; }
.keyboardShortcutsRow kbd { width: fit-content; padding: 2px 7px; color: #1f2937; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 3px; font-family: inherit; font-size: 12px; }
@media (max-width: 640px) { .keyboardShortcutsRow { grid-template-columns: 1fr; gap: 4px; padding: 5px 0; } }
```

- [ ] **Step 4: Run the modal test and verify it passes**

Run the Step 2 command again.

Expected: PASS with one suite and one passing test.

- [ ] **Step 5: Commit the dialog slice**

```powershell
git add wwwroot/src/Page/KeyboardShortcutsModal.js wwwroot/src/Page/KeyboardShortcutsModal.test.js wwwroot/src/Assets/designer.css
git commit -m "feat(designer): add keyboard shortcuts dialog"
```

### Task 3: Wire The Toolbar Help Button

**Files:**
- Create: `wwwroot/src/Page/DesignerApp.keyboardShortcuts.test.js`
- Modify: `wwwroot/src/Page/DesignerApp.js`

- [ ] **Step 1: Write the failing designer integration test**

```js
import fs from 'fs';
import path from 'path';

describe('designer keyboard shortcuts help', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('opens and closes the shortcut dialog from the top controls', () => {
        expect(source).toContain("import { KeyboardOutlined } from '@ant-design/icons';");
        expect(source).toContain("import KeyboardShortcutsModal from './KeyboardShortcutsModal';");
        expect(source).toContain('const [keyboardShortcutsOpen, setKeyboardShortcutsOpen] = useState(false);');
        expect(source).toContain('onClick={() => setKeyboardShortcutsOpen(true)}');
        expect(source).toContain('<KeyboardShortcutsModal');
        expect(source).toContain('open={keyboardShortcutsOpen}');
        expect(source).toContain('onClose={() => setKeyboardShortcutsOpen(false)}');
    });
});
```

- [ ] **Step 2: Run the designer integration test and verify it fails**

Run:

```powershell
$env:CI = 'true'
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/DesignerApp.keyboardShortcuts.test.js
```

Expected: FAIL because the trigger state, button, and modal wiring are absent.

- [ ] **Step 3: Wire the button and modal into `DesignerApp`**

Add these imports beside the current Ant Design imports in `wwwroot/src/Page/DesignerApp.js`:

```jsx
import { KeyboardOutlined } from '@ant-design/icons';
import KeyboardShortcutsModal from './KeyboardShortcutsModal';
```

Add the local state alongside the existing dialog states:

```jsx
const [keyboardShortcutsOpen, setKeyboardShortcutsOpen] = useState(false);
```

Add this button as the first control in the existing `topControls` group, before the snap button:

```jsx
<Button
    type="default"
    icon={<KeyboardOutlined />}
    aria-label={t('designer.shortcuts.title')}
    title={t('designer.shortcuts.title')}
    onClick={() => setKeyboardShortcutsOpen(true)}
>{t('designer.shortcuts.trigger')}</Button>
```

Render this modal inside the design-mode branch, immediately before the existing save-template dialog:

```jsx
<KeyboardShortcutsModal
    open={keyboardShortcutsOpen}
    onClose={() => setKeyboardShortcutsOpen(false)}
/>
```

Do not alter `onKeyDown`, grouping, lock, selection, save, or preview code.

- [ ] **Step 4: Run the designer integration test and verify it passes**

Run the Step 2 command again.

Expected: PASS with one suite and one passing test.

- [ ] **Step 5: Run focused regression tests and commit the integration**

Run:

```powershell
$env:CI = 'true'
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand src/Page/keyboardShortcuts.test.js src/Page/KeyboardShortcutsModal.test.js src/Page/DesignerApp.keyboardShortcuts.test.js src/appEntry.test.js
```

Expected: all four suites PASS.

```powershell
git add wwwroot/src/Page/DesignerApp.js wwwroot/src/Page/DesignerApp.keyboardShortcuts.test.js
git commit -m "feat(designer): expose keyboard shortcuts help"
```

### Task 4: Perform Full Validation

**Files:**
- Verify: `wwwroot/src/Page/keyboardShortcuts.js`
- Verify: `wwwroot/src/Page/KeyboardShortcutsModal.js`
- Verify: `wwwroot/src/Page/DesignerApp.js`

- [ ] **Step 1: Run the full unit suite**

```powershell
$env:CI = 'true'
$env:NODE_OPTIONS = '--openssl-legacy-provider'
node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false --runInBand
```

Expected: all suites PASS with no failures.

- [ ] **Step 2: Run the source-character check**

```powershell
npm run check:no-cjk
```

Expected: `Check passed: no CJK or mojibake-like characters found outside dictionaries.`

- [ ] **Step 3: Build the production bundle**

```powershell
$env:NODE_OPTIONS = '--openssl-legacy-provider'
npm run build
```

Expected: optimized build and Service Worker patch both complete with exit code 0.

- [ ] **Step 4: Manually validate design mode**

Open the designer without `type=preview`, select the keyboard help button, verify all five groups are visible, press Escape, reopen it, then close through the dialog close control. Open a preview URL with `type=preview` and verify neither the toolbar nor the help button is present.

- [ ] **Step 5: Inspect the final diff**

```powershell
git diff --check
git status --short
```

Expected: no whitespace errors and only the planned files in the feature commit.
