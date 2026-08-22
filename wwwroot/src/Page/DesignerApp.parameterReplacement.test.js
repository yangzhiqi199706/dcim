import fs from 'fs';
import path from 'path';

describe('designer parameter replacement integration', () => {
    const source = fs.readFileSync(path.join(__dirname, 'DesignerApp.js'), 'utf8');

    test('renders every original parameter with an independent replacement selector and commits one batch update', () => {
        expect(source).toContain("from './parameterReplacement'");
        expect(source).toContain('const [parameterReplacementBox, setParameterReplacementBox] = useState(false);');
        expect(source).toContain('const [parameterReplacementTargets, setParameterReplacementTargets] = useState({});');
        expect(source).toContain("const [parameterReplacementKeywordFrom, setParameterReplacementKeywordFrom] = useState('');");
        expect(source).toContain("const [parameterReplacementKeywordTo, setParameterReplacementKeywordTo] = useState('');");
        expect(source).toContain('const openParameterReplacementDialog = async () => {');
        expect(source).toContain("getDataFromActiveSource('GetDeviceListKey', { ComboBox: 'all' }, context.sourceHost)");
        expect(source).toContain('replaceSelectedParameterBindingMappings(');
        expect(source).toContain('createParameterReplacementMappingPlan(');
        expect(source).toContain('createParameterReplacementRequestGuard');
        expect(source).toContain('isParameterReplacementSelectionCurrent(');
        expect(source).toContain('parameterReplacementRequestRef.current.begin()');
        expect(source).toContain('parameterReplacementRequestRef.current.isCurrent(requestId)');
        expect(source).toContain('const closeParameterReplacementDialog = () => {');
        expect(source).toContain('const prepareKeywordParameterReplacement = () => {');
        expect(source).toContain('history.push(JSON.parse(JSON.stringify(result.shapes)))');
        expect(source).toContain('parameterReplacementContext.originalOptions.map((original) => (');
        expect(source).toContain('value={parameterReplacementTargets[original.value]}');
        expect(source).toContain('setParameterReplacementTargets((current) => ({ ...current, [original.value]: value }))');
        expect(source).toContain('showSearch');
        expect(source).toContain('optionFilterProp="label"');
        expect(source).toContain('parameterReplacementKeywordFrom');
        expect(source).toContain('parameterReplacement.parameterNotFound');
        expect(source).toContain('parameterReplacement.keywordApply');
        expect(source).toContain('onClick={prepareKeywordParameterReplacement}');
        expect(source).toContain('replaceParameter: openParameterReplacementDialog');
        expect(source).toContain('style={parameterReplacementBox ? { \'display\': \'block\' } : { \'display\': \'none\' }}');
    });

    test('prefills valid keyword mappings and defers missing-parameter validation to confirmation', () => {
        const preparationStart = source.indexOf('const prepareKeywordParameterReplacement = () => {');
        const preparationEnd = source.indexOf('\n    useEffect(() => {', preparationStart);
        const preparationSource = source.slice(preparationStart, preparationEnd);
        const confirmationStart = source.indexOf('const applyParameterReplacement = () => {');
        const confirmationEnd = source.indexOf('const prepareKeywordParameterReplacement = () => {', confirmationStart);
        const confirmationSource = source.slice(confirmationStart, confirmationEnd);

        expect(preparationSource).toContain('plan.mappings.forEach((mapping) => {');
        expect(preparationSource).not.toContain('parameterReplacement.parameterNotFound');
        expect(confirmationSource).toContain('parameterReplacement.parameterNotFound');
    });
});
