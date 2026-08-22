import { getSelectAllSelectionState } from './designerSelection';

describe('designer select all selection state', () => {
    test('selects every unlocked element in canvas order', () => {
        const shapes = [
            { id: 'locked', draggable: false },
            { id: 'first' },
            { id: 'group-member-a', groupId: 'group-1' },
            { id: 'group-member-b', groupId: 'group-1' },
            { id: 'duplicate', draggable: true },
            { id: 'duplicate', draggable: true },
            null,
            { id: '' },
        ];

        expect(getSelectAllSelectionState(shapes)).toEqual({
            selectedId: 'first',
            selectedIds: ['first', 'group-member-a', 'group-member-b', 'duplicate'],
        });
    });

    test('uses single-selection state when one unlocked element remains', () => {
        expect(getSelectAllSelectionState([
            { id: 'locked', draggable: false },
            { id: 'only-editable' },
        ])).toEqual({
            selectedId: 'only-editable',
            selectedIds: [],
        });
    });

    test('clears selection when there are no unlocked elements', () => {
        expect(getSelectAllSelectionState([
            { id: 'locked-a', draggable: false },
            { id: 'locked-b', draggable: false },
        ])).toEqual({
            selectedId: null,
            selectedIds: [],
        });
    });
});
