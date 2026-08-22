import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(path.join(__dirname, 'PreviewElement.js'), 'utf8');

describe('preview image paths', () => {
    test('keeps leak alarm assets inside the deployed VIBuilder directory', () => {
        expect(source).toContain('src="Images/icon/water.gif"');
        expect(source).not.toContain('src="../Images/icon/water.gif"');
    });
});
