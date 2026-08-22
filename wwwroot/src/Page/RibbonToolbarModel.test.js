import { getRibbonToolbarGroups, RIBBON_TOOLBAR_TABS } from './RibbonToolbarModel';

describe('RibbonToolbarModel', () => {
    test('organizes designer commands into four contextual tabs', () => {
        expect(RIBBON_TOOLBAR_TABS.map((tab) => tab.id)).toEqual([
            'edit',
            'arrange',
            'data',
            'page',
        ]);
    });

    test('keeps alignment and distribution commands together in the arrange tab', () => {
        const groups = getRibbonToolbarGroups('arrange');

        expect(groups.map((group) => group.id)).toEqual([
            'layer',
            'align',
            'distribute',
        ]);
        expect(groups[1].commands).toContain('alignLeft');
        expect(groups[2].commands).toContain('equalVertical');
    });

    test('keeps the master control save command with editing commands', () => {
        const groups = getRibbonToolbarGroups('edit');
        const commands = groups.reduce((all, group) => all.concat(group.commands), []);

        expect(commands).toContain('saveMasterControl');
    });

    test('places remote synchronization in the page operations tab', () => {
        const groups = getRibbonToolbarGroups('page');
        const commands = groups.reduce((all, group) => all.concat(group.commands), []);

        expect(commands).toContain('remoteSync');
        expect(commands).toContain('globalDataSource');
    });

    test('falls back to the editing tools for an unknown tab', () => {
        expect(getRibbonToolbarGroups('unknown')).toEqual(getRibbonToolbarGroups('edit'));
    });
});
