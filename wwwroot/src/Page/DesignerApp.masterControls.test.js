import fs from 'fs';
import path from 'path';

describe('DesignerApp master controls integration', () => {
    test('provides save, naming, and independent drop behavior in the active designer', () => {
        const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

        expect(source).toContain("from './masterControlLibrary'");
        expect(source).toContain('const [showMasterControlBox, setShowMasterControlBox] = useState(false);');
        expect(source).toContain('openMasterControlSaveDialog');
        expect(source).toContain("getDataLocal('saveMasterControl'");
        expect(source).toContain('isMasterControlDefinition(dragAttrs)');
        expect(source).toContain('instantiateMasterControl(');
        expect(source).toContain('id="saveMasterControl"');
    });
});
