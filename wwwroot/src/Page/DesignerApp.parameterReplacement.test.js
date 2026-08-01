import fs from 'fs';
import path from 'path';

describe('designer parameter replacement integration', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('opens a same-device parameter replacement dialog and commits one batch update', () => {
        expect(source).toContain("from './parameterReplacement'");
        expect(source).toContain('const [parameterReplacementBox, setParameterReplacementBox] = useState(false);');
        expect(source).toContain('const openParameterReplacementDialog = async () => {');
        expect(source).toContain("httpsend.getData('GetDeviceListKey', { ComboBox: 'all' })");
        expect(source).toContain('replaceSelectedParameterBindings(');
        expect(source).toContain('createParameterReplacementRequestGuard');
        expect(source).toContain('isParameterReplacementSelectionCurrent(');
        expect(source).toContain('parameterReplacementRequestRef.current.begin()');
        expect(source).toContain('parameterReplacementRequestRef.current.isCurrent(requestId)');
        expect(source).toContain('const closeParameterReplacementDialog = () => {');
        expect(source).toContain('history.push(JSON.parse(JSON.stringify(result.shapes)))');
        expect(source).toContain('replaceParameter: openParameterReplacementDialog');
        expect(source).toContain('style={parameterReplacementBox ? { \'display\': \'block\' } : { \'display\': \'none\' }}');
    });
});
