import { KEYBOARD_SHORTCUT_GROUPS } from './keyboardShortcuts';
import zhCN from '../i18n/dictionaries/zh-CN';
import enUS from '../i18n/dictionaries/en-US';

const expectedGroups = [
    {
        name: 'selection',
        keys: ['Ctrl/Cmd + A', 'Ctrl/Cmd + Click', 'Arrow keys', 'Shift + Arrow keys'],
        actions: ['selectAll', 'multiSelect', 'moveOnePixel', 'moveTenPixels'],
    },
    {
        name: 'grouping',
        keys: ['Ctrl + G', 'Ctrl + Shift + J'],
        actions: ['group', 'ungroup'],
    },
    {
        name: 'editing',
        keys: ['Ctrl + C', 'Ctrl + X', 'Ctrl + V', 'Ctrl + Z', 'Ctrl + H', 'Delete'],
        actions: ['copy', 'cut', 'paste', 'undo', 'replaceText', 'remove'],
    },
    {
        name: 'layerAndLock',
        keys: [
            'Ctrl + Arrow Up', 'Ctrl + Arrow Down', 'Ctrl + E', 'Ctrl + B',
            'Ctrl + K', 'Ctrl + Shift + K', 'Ctrl + L', 'Ctrl + N',
        ],
        actions: [
            'layerUp', 'layerDown', 'toTop', 'toBottom',
            'lock', 'unlock', 'lockAlternative', 'unlockAlternative',
        ],
    },
    {
        name: 'page',
        keys: ['Ctrl + S'],
        actions: ['save'],
    },
];

const getKeyPaths = (value, prefix = '') => Object.keys(value).flatMap((key) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return value[key] && typeof value[key] === 'object'
        ? getKeyPaths(value[key], path)
        : [path];
});

describe('keyboard shortcut catalog', () => {
    test('documents all implemented keyboard and multi-select commands in five groups', () => {
        expect(KEYBOARD_SHORTCUT_GROUPS).toHaveLength(5);
        expect(KEYBOARD_SHORTCUT_GROUPS.map((group) => group.titleKey)).toEqual(
            expectedGroups.map((group) => `designer.shortcuts.groups.${group.name}`),
        );
        expect(KEYBOARD_SHORTCUT_GROUPS.map((group) => group.items.map((item) => item.keys))).toEqual(
            expectedGroups.map((group) => group.keys),
        );
        expect(KEYBOARD_SHORTCUT_GROUPS.flatMap((group) => group.items.map((item) => item.actionKey))).toEqual(
            expectedGroups.flatMap((group) => group.actions.map((action) => `designer.shortcuts.actions.${action}`)),
        );
    });

    test('uses matching localized shortcut keys in both dictionaries', () => {
        expect(getKeyPaths(zhCN.designer.shortcuts)).toEqual(getKeyPaths(enUS.designer.shortcuts));

        KEYBOARD_SHORTCUT_GROUPS.forEach((group) => {
            const groupName = group.titleKey.split('.').pop();
            expect(zhCN.designer.shortcuts.groups[groupName]).toBeTruthy();
            expect(enUS.designer.shortcuts.groups[groupName]).toBeTruthy();

            group.items.forEach((item) => {
                const actionName = item.actionKey.split('.').pop();
                expect(zhCN.designer.shortcuts.actions[actionName]).toBeTruthy();
                expect(enUS.designer.shortcuts.actions[actionName]).toBeTruthy();
            });
        });
    });
});
