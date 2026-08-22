export const KEYBOARD_SHORTCUT_GROUPS = [
    {
        titleKey: 'designer.shortcuts.groups.selection',
        items: [
            { keys: 'Ctrl/Cmd + A', actionKey: 'designer.shortcuts.actions.selectAll' },
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
