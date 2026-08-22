import {
    createMasterControlDefinition,
    instantiateMasterControl,
} from './masterControlLibrary';

describe('master control library', () => {
    test('normalizes selected shapes to the top-left origin', () => {
        const definition = createMasterControlDefinition('Pump panel', [
            { id: 'a', x: 80, y: 120, moduleJson: { children: [] } },
            { id: 'b', x: 130, y: 150, moduleJson: { children: [] } },
        ]);

        expect(definition.shapes.map((shape) => [shape.x, shape.y])).toEqual([[0, 0], [50, 30]]);
    });

    test('instantiates independent shapes with fresh IDs and remapped groups', () => {
        const definition = createMasterControlDefinition('Pair', [
            { id: 'a', groupId: 'old-group', x: 20, y: 20, moduleJson: { children: [] } },
            { id: 'b', groupId: 'old-group', x: 60, y: 20, moduleJson: { children: [] } },
        ]);
        const instance = instantiateMasterControl(definition, { x: 300, y: 400 }, (index) => `new-${index}`);

        expect(instance.shapes.map((shape) => [shape.id, shape.x, shape.y])).toEqual([
            ['new-0', 300, 400],
            ['new-1', 340, 400],
        ]);
        expect(new Set(instance.shapes.map((shape) => shape.groupId)).size).toBe(1);
        expect(instance.shapes[0].groupId).not.toBe('old-group');
        expect(instance.shapes[0]).not.toBe(definition.shapes[0]);
    });
});
