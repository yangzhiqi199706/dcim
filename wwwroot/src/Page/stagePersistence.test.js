import * as stagePersistence from './stagePersistence';

const { normalizeStageForPersistence } = stagePersistence;

describe('normalizeStageForPersistence', () => {
    test('removes view zoom from the saved stage while retaining logical dimensions', () => {
        const stage = {
            className: 'Stage',
            attrs: {
                width: 960,
                height: 540,
                scaleX: 0.5,
                scaleY: 0.5,
                draggable: false,
            },
            children: [{ className: 'Layer', children: [{ className: 'Rect', attrs: { id: 'canvasBackground' } }] }],
        };

        const normalized = normalizeStageForPersistence(stage, 1920, 1080);

        expect(normalized.attrs).toEqual({
            width: 1920,
            height: 1080,
            scaleX: 1,
            scaleY: 1,
            draggable: false,
        });
        expect(normalized.children).toEqual(stage.children);
        expect(stage.attrs.width).toBe(960);
        expect(stage.attrs.scaleX).toBe(0.5);
    });

    test('recovers logical dimensions from a stage saved with view zoom', () => {
        const stage = {
            attrs: {
                width: 960,
                height: 540,
                scaleX: 0.5,
                scaleY: 0.5,
            },
        };

        expect(stagePersistence.resolveLogicalStageSize(stage)).toEqual({
            width: 1920,
            height: 1080,
        });
    });
});
