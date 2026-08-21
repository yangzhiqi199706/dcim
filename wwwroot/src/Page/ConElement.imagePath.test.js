import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(path.join(__dirname, 'ConElement.js'), 'utf8');

describe('designer image paths', () => {
    test('normalizes imported image sources and keeps fallbacks inside VIBuilder', () => {
        expect(source).toContain("import { normalizeImageAssetSrc } from '../Assets/imageSource';");
        expect(source).toContain('normalizeImageAssetSrc(imageSource)');
        expect(source).toContain('src="Images/icon/error.png"');
        expect(source).not.toContain('src="../Images/icon/error.png"');
        expect(source).not.toContain('src="../Images/icon/water.gif"');
    });
});
