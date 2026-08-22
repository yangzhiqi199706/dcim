import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(path.join(__dirname, 'SvgBackground.js'), 'utf8');

describe('canvas background image paths', () => {
    test('normalizes legacy image paths before loading a background', () => {
        expect(source).toContain("import { normalizeImageAssetSrc } from '../Assets/imageSource';");
        expect(source).toContain('const normalizedBackgroundUrl = normalizeImageAssetSrc(backgroundUrl);');
        expect(source).toContain('useImage(normalizedBackgroundUrl)');
    });
});
