import { normalizeImageAssetSrc } from './imageSource';

describe('image asset source normalization', () => {
    test('converts imported server image URLs to deployment-relative paths', () => {
        expect(normalizeImageAssetSrc('http://192.168.0.22:8086/VIBuilder/Images/uploads/1730173304.png'))
            .toBe('Images/uploads/1730173304.png');
        expect(normalizeImageAssetSrc('http://192.168.0.22:8086/Images/uploads/1730173304.png'))
            .toBe('Images/uploads/1730173304.png');
    });

    test('keeps supported local and external sources usable', () => {
        expect(normalizeImageAssetSrc('VIBuilder/Images/icon/full.png')).toBe('Images/icon/full.png');
        expect(normalizeImageAssetSrc('Images/icon/full.png')).toBe('Images/icon/full.png');
        expect(normalizeImageAssetSrc('https://example.com/custom-image.png')).toBe('https://example.com/custom-image.png');
        expect(normalizeImageAssetSrc(null)).toBe('');
    });
});
