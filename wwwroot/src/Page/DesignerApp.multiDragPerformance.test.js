import fs from 'fs';
import path from 'path';

describe('designer multi drag performance integration', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('uses a cached drag session instead of resolving selected canvas nodes on every drag frame', () => {
        expect(source).toContain("from './multiDragRuntime'");
        expect(source).toContain('const createMultiDragSessionForSelection = (stage, ids, draggedId) => {');
        expect(source).toContain('calculateMultiDragFrame(dragSession, { x: e.target.x(), y: e.target.y() })');
        expect(source).toContain('applyMultiDragPositions(nextPositions, dragSession.nodesById)');
        expect(source).not.toContain('buildGroupMetricsFromIds(dragSelectedIds, nextPositions)');
    });
});
