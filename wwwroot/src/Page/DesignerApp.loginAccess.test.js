import fs from 'fs';
import path from 'path';

describe('designer login access integration', () => {
    test('bypasses login only for local development hosts', () => {
        const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

        expect(source).toContain("import { shouldRequireDesignerLogin } from '../designerLoginAccess';");
        expect(source).toContain('!loginState && !isPreview && shouldRequireDesignerLogin(window.location.hostname)');
    });
});
