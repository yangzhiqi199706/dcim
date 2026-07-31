import fs from 'fs';
import path from 'path';

describe('designer diagnostics integration', () => {
    const file = path.join(__dirname, 'DesignerApp.js');

    test('keeps preflight validation and simulation rendering on separate models', () => {
        const source = fs.readFileSync(file, 'utf8');

        expect(source).toContain("import { validatePageElements } from './pageValidation';");
        expect(source).toContain("import { applySimulationOverrides, getSimulatableElements } from './simulationOverrides';");
        expect(source).toContain('const renderImages = useMemo(');
        expect(source).toContain('validatePageElements(imagesRef.current');
        expect(source).toContain('setChart(renderImages');
        expect(source).toContain('<PreflightModal');
        expect(source).toContain('<DataSimulationModal');
    });
});
