jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
    postData: jest.fn()
}));

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
});
