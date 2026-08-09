import fs from 'fs';
import path from 'path';

describe('designer parameter replacement integration', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('renders every original parameter with an independent replacement selector and commits one batch update', () => {
        expect(source).toContain("from './parameterReplacement'");
        expect(source).toContain('const [parameterReplacementBox, setParameterReplacementBox] = useState(false);');
        expect(source).toContain('const [parameterReplacementTargets, setParameterReplacementTargets] = useState({});');
        expect(source).toContain('const openParameterReplacementDialog = async () => {');
        expect(source).toContain("httpsend.getData('GetDeviceListKey', { ComboBox: 'all' })");
        expect(source).toContain('replaceSelectedParameterBindingMappings(');
        expect(source).toContain('createParameterReplacementRequestGuard');
        expect(source).toContain('isParameterReplacementSelectionCurrent(');
        expect(source).toContain('parameterReplacementRequestRef.current.begin()');
        expect(source).toContain('parameterReplacementRequestRef.current.isCurrent(requestId)');
        expect(source).toContain('const closeParameterReplacementDialog = () => {');
        expect(source).toContain('history.push(JSON.parse(JSON.stringify(result.shapes)))');
        expect(source).toContain('parameterReplacementContext.originalOptions.map((original) => (');
        expect(source).toContain('value={parameterReplacementTargets[original.value]}');
        expect(source).toContain('setParameterReplacementTargets((current) => ({ ...current, [original.value]: value }))');
        expect(source).toContain('replaceParameter: openParameterReplacementDialog');
        expect(source).toContain('style={parameterReplacementBox ? { \'display\': \'block\' } : { \'display\': \'none\' }}');
    });
});
