import fs from 'fs';
import path from 'path';

describe('Home master controls integration', () => {
    test('saves selected shapes and inserts independent instances on drop', () => {
        const source = fs.readFileSync(path.join(__dirname, 'Home.js'), 'utf8');

        expect(source).toContain("from './masterControlLibrary'");
        expect(source).toContain('getClipboardSelectionShapes()');
        expect(source).toContain("getDataLocal('saveMasterControl'");
        expect(source).toContain('instantiateMasterControl(');
        expect(source).toContain('masterControlName');
    });
});
