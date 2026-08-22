import fs from 'fs';
import path from 'path';

describe('ElementAttr chart control rendering', () => {
    test('renders chart style, animation, and bar style controls as select fields', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ElementAttr.js'), 'utf8');
        const chartControlBlock = source.match(/if \(a\.attrType === 'chartStyleSelect'[\s\S]*?if \(a\.attrType === 'sortOrderSelect'\)/);

        expect(chartControlBlock).toBeTruthy();
        expect(chartControlBlock[0]).toContain('<select');
        expect(chartControlBlock[0]).toContain('onChange={handleValChange}');
        expect(chartControlBlock[0]).toContain('data-attrcode={a.attrCode}');
        expect(chartControlBlock[0]).toContain("a.attrType === 'chartBarStyleSelect'");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleRounded')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleCylinder')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleDiamond')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleHexagon')");
        expect(chartControlBlock[0]).toContain("t('chart.barStylePrism')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleTrapezoid')");
        expect(chartControlBlock[0]).toContain("t('chart.barStylePyramid')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleBattery')");
        expect(chartControlBlock[0]).toContain("t('chart.barStyleStereoGroup')");
        expect(chartControlBlock[0]).not.toContain('chartStyleButtons');
    });

    test('renders water ball shape control as a select field', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ElementAttr.js'), 'utf8');
        const waterBallShapeBlock = source.match(/if \(a\.attrType === 'waterBallShapeSelect'[\s\S]*?if \(a\.attrType === 'sortOrderSelect'\)/);

        expect(waterBallShapeBlock).toBeTruthy();
        expect(waterBallShapeBlock[0]).toContain('<select');
        expect(waterBallShapeBlock[0]).toContain("value: 'circle'");
        expect(waterBallShapeBlock[0]).toContain("value: 'rect'");
        expect(waterBallShapeBlock[0]).toContain("value: 'roundedRect'");
        expect(waterBallShapeBlock[0]).toContain("value: 'triangle'");
        expect(waterBallShapeBlock[0]).toContain("value: 'diamond'");
        expect(waterBallShapeBlock[0]).toContain("value: 'drop'");
        expect(waterBallShapeBlock[0]).toContain("value: 'arrow'");
    });
});
