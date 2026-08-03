jest.mock('../Assets/httpsend', () => ({
    getData: jest.fn(),
    postData: jest.fn()
}));

import fs from 'fs';
import path from 'path';

import { getImageAssetName, isBasicPaletteComponent, isChartPaletteComponent } from './ItemBox';

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

    test('derives an image asset name from a gallery URL', () => {
        expect(getImageAssetName('Images/uploads/room%20overview.png?version=3')).toBe('room overview');
        expect(getImageAssetName('Images/dcim/air-conditioner.svg')).toBe('air-conditioner');
        expect(getImageAssetName('')).toBe('');
    });

    test('renders default and personal galleries as single-column named asset rows', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');
        const style = fs.readFileSync(path.join(__dirname, '..', 'Assets', 'style.css'), 'utf8');

        expect(source).toContain('className="paletteAssetList galleryListOnly"');
        expect(source).toContain('className="paletteAssetList defaultGalleryList"');
        expect(source).toContain('className="paletteAssetRow itmeOne"');
        expect(source).toContain('className="paletteAssetName"');
        expect(style).toContain('.paletteAssetList{display:flex;flex-direction:column;');
        expect(style).toContain('.left .paletteAssetRow.itmeOne{');
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

    test('keeps my-page tree hover and selected states readable in the graphite theme', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');
        const style = fs.readFileSync(path.join(__dirname, '..', 'Assets', 'designer.css'), 'utf8');

        expect(source).toContain('className="pageTree"');
        expect(style).toContain('.designerShell .left .pageTree .ant-tree-node-content-wrapper:hover');
        expect(style).toContain('.designerShell .left .pageTree .ant-tree-treenode-selected .ant-tree-node-content-wrapper');
        expect(style).toContain('.designerShell .left .pageTree .ant-tree-switcher');
    });

    test('integrates server-persisted master controls into the material library', () => {
        const source = fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');
        const navSource = fs.readFileSync(path.join(__dirname, 'ItemNav.js'), 'utf8');

        expect(source).toContain("getImgData('master-control')");
        expect(source).toContain("action: 'delmastercontrol'");
        expect(source).toContain('selectedNav === 7');
        expect(source).toContain('selectedNav === 8');
        expect(navSource).toContain("t('itemBox.masterControls')");
    });
});
