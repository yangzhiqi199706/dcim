import * as previewElementMemo from './previewElementMemo';
import { arePreviewElementPropsEqual } from './previewElementMemo';

describe('arePreviewElementPropsEqual', () => {
    const shapeProps = { id: 'shape-1' };
    const createProps = (overrides = {}) => ({
        shapeProps,
        id: 'shape-1',
        wheight: 1080,
        wwidth: 1920,
        wscale: 1,
        onhandleResize: () => {},
        isSwiper: false,
        useSlaveId: false,
        ...overrides
    });

    test('ignores callback identity when render inputs are unchanged', () => {
        expect(arePreviewElementPropsEqual(createProps(), createProps())).toBe(true);
    });

    test('re-renders when the element data reference changes', () => {
        expect(arePreviewElementPropsEqual(createProps(), createProps({ shapeProps: { id: 'shape-1' } }))).toBe(false);
    });

    test('normalizes a missing alarm list payload to an empty view state', () => {
        expect(typeof previewElementMemo.getAlarmListRows).toBe('function');
        expect(previewElementMemo.getAlarmListRows(undefined)).toEqual([]);
    });
});
