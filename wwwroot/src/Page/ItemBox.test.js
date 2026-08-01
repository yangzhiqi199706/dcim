jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
    postData: jest.fn()
}));

import fs from 'fs';
import path from 'path';

import { isBasicPaletteComponent, isChartPaletteComponent } from './ItemBox';

describe('ItemBox palette classification', () => {
    test('places water ball in basic palette while keeping Echart rendering class', () => {
        const waterBall = {
            moduleJson: {
                children: [{
                    className: 'Echart',
                    attrs: { cat: 'waterBall' }
                }]
            }
        };

        expect(isBasicPaletteComponent(waterBall)).toBe(true);
        expect(isChartPaletteComponent(waterBall)).toBe(false);
    });

    test('keeps normal Echart components in chart palette', () => {
        const barChart = {
            moduleJson: {
                children: [{
                    className: 'Echart',
                    attrs: { cat: 'bar' }
                }]
            }
        };

        expect(isBasicPaletteComponent(barChart)).toBe(false);
        expect(isChartPaletteComponent(barChart)).toBe(true);
    });

    test('integrates palette search and favorites into the material library', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');
        const navSource = fs.readFileSync(path.join(__dirname, 'ItemNav.js'), 'utf8');

        expect(source).toContain("from './paletteLibrary'");
        expect(source).toContain('createPaletteItem,');
        expect(source).toContain('filterPaletteItems,');
        expect(source).toContain('data-palette-search');
        expect(source).toContain('data-palette-favorite');
        expect(source).toContain('selectedNav === 7');
        expect(navSource).toContain("t('itemBox.favorites')");
    });
});
