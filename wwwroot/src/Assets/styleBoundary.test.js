import fs from 'fs';
import path from 'path';

const readSource = (name) => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

describe('application CSS boundaries', () => {
    test('loads only base and preview styles from PreviewApp', () => {
        const source = readSource('Page/PreviewApp.js');
        expect(source).toContain("import '../Assets/base.css';");
        expect(source).toContain("import '../Assets/preview.css';");
        expect(source).not.toContain('designer.css');
    });

    test('loads only base and designer styles from DesignerApp', () => {
        const source = readSource('Page/DesignerApp.js');
        expect(source).toContain("import '../Assets/base.css';");
        expect(source).toContain("import '../Assets/designer.css';");
        expect(source).not.toContain('preview.css');
    });
});
