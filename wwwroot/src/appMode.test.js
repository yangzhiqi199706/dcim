import { APP_MODE_DESIGNER, APP_MODE_PREVIEW, resolveAppMode } from './appMode';

describe('resolveAppMode', () => {
    test('selects preview when the type query parameter has a value', () => {
        expect(resolveAppMode('?type=preview&title=page-1')).toBe(APP_MODE_PREVIEW);
    });

    test('selects preview when the swiper query parameter has a value', () => {
        expect(resolveAppMode('?swiper=1')).toBe(APP_MODE_PREVIEW);
    });

    test('selects designer for ordinary and empty query strings', () => {
        expect(resolveAppMode('')).toBe(APP_MODE_DESIGNER);
        expect(resolveAppMode('?title=page-1')).toBe(APP_MODE_DESIGNER);
    });
});
