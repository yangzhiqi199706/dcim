import {
    calculateMultiDragFrame,
    createMultiDragSession,
} from './multiDragRuntime';

describe('multi drag runtime', () => {
    test('moves cached selected nodes from their drag-start positions without resolving the canvas again', () => {
        const nodesById = {
            title: { id: 'title' },
            chart: { id: 'chart' },
            status: { id: 'status' },
        };
        const session = createMultiDragSession({
            ids: ['title', 'chart', 'status'],
            draggedId: 'chart',
            startPositions: {
                title: { x: 40, y: 20 },
                chart: { x: 100, y: 80 },
                status: { x: 260, y: 160 },
            },
            nodesById,
            groupMetrics: {
                x: 40,
                y: 20,
                left: 40,
                top: 20,
                right: 360,
                bottom: 220,
                width: 320,
                height: 200,
                centerX: 200,
                centerY: 120,
            },
            guideCandidates: { vertical: [], horizontal: [] },
        });

        const frame = calculateMultiDragFrame(session, { x: 130, y: 105 });

        expect(Object.keys(session.nodesById)).toEqual(['title', 'chart', 'status']);
        expect(frame.delta).toEqual({ x: 30, y: 25 });
        expect(frame.positions).toEqual({
            title: { x: 70, y: 45 },
            chart: { x: 130, y: 105 },
            status: { x: 290, y: 185 },
        });
        expect(frame.groupMetrics).toMatchObject({
            x: 70,
            y: 45,
            right: 390,
            bottom: 245,
            centerX: 230,
            centerY: 145,
        });
    });
});
