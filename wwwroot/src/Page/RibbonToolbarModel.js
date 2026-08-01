export const RIBBON_TOOLBAR_TABS = [
    { id: 'edit', labelKey: 'ribbonToolbar.tabs.edit' },
    { id: 'arrange', labelKey: 'ribbonToolbar.tabs.arrange' },
    { id: 'data', labelKey: 'ribbonToolbar.tabs.data' },
    { id: 'page', labelKey: 'ribbonToolbar.tabs.page' },
];

const TAB_GROUPS = {
    edit: [
        { id: 'history', labelKey: 'ribbonToolbar.groups.history', commands: ['undo'] },
        { id: 'selection', labelKey: 'ribbonToolbar.groups.selection', commands: ['copy', 'lock', 'unlock', 'delete'] },
        { id: 'composition', labelKey: 'ribbonToolbar.groups.composition', commands: ['group', 'ungroup', 'saveMasterControl'] },
    ],
    arrange: [
        { id: 'layer', labelKey: 'ribbonToolbar.groups.layer', commands: ['layerUp', 'layerDown', 'layerTop', 'layerBottom'] },
        { id: 'align', labelKey: 'ribbonToolbar.groups.align', commands: ['alignLeft', 'alignRight', 'alignTop', 'alignBottom', 'alignCenter', 'alignMiddle'] },
        { id: 'distribute', labelKey: 'ribbonToolbar.groups.distribute', commands: ['equalHeight', 'equalWidth', 'equalGrid', 'equalVertical', 'equalHorizontal'] },
    ],
    data: [
        { id: 'quality', labelKey: 'ribbonToolbar.groups.quality', commands: ['preflight', 'dataHealth', 'simulation'] },
        { id: 'replace', labelKey: 'ribbonToolbar.groups.replace', commands: ['replaceDevice', 'replaceParameter', 'replaceText'] },
    ],
    page: [
        { id: 'workspace', labelKey: 'ribbonToolbar.groups.workspace', commands: ['newPage'] },
        { id: 'save', labelKey: 'ribbonToolbar.groups.save', commands: ['preview', 'saveTemplate', 'savePage'] },
    ],
};

export function getRibbonToolbarGroups(tabId) {
    return TAB_GROUPS[tabId] || TAB_GROUPS.edit;
}
