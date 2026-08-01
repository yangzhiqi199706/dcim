import {
    createPaletteItem,
    filterPaletteItems,
    readPaletteFavorites,
    togglePaletteFavorite,
    writePaletteFavorites,
} from './paletteLibrary';

const createMemoryStorage = () => {
    const values = {};
    return {
        getItem: (key) => (Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null),
        setItem: (key, value) => {
            values[key] = String(value);
        },
    };
};

const rawItem = {
    moduleName: 'Load summary',
    iconBase64: 'Images/icon/chart.png',
    moduleJson: {
        children: [{
            className: 'Echart',
            attrs: { title: 'Main load', cat: 'bar' },
        }],
    },
};

describe('palette library', () => {
    test('creates a stable source-aware identity without changing the drag model', () => {
        const first = createPaletteItem(rawItem, 'chart');
        const second = createPaletteItem(rawItem, 'chart');
        const differentSource = createPaletteItem(rawItem, 'basic');

        expect(first.favoriteId).toBe(second.favoriteId);
        expect(first.favoriteId).not.toBe(differentSource.favoriteId);
        expect(first.paletteSource).toBe('chart');
        expect(first.moduleJson).toBe(rawItem.moduleJson);
    });

    test('filters names and chart categories without removing the original items', () => {
        const item = createPaletteItem(rawItem, 'chart');
        const other = createPaletteItem({
            moduleName: 'Temperature',
            moduleJson: { children: [{ attrs: { cat: 'line' } }] },
        }, 'chart');

        expect(filterPaletteItems([item, other], ' BAR ')).toEqual([item]);
        expect(filterPaletteItems([item, other], 'temperature')).toEqual([other]);
        expect(filterPaletteItems([item, other], '')).toEqual([item, other]);
    });

    test('toggles a favorite and restores it from storage', () => {
        const storage = createMemoryStorage();
        const item = createPaletteItem(rawItem, 'chart');
        const favorites = togglePaletteFavorite([], item);

        expect(favorites).toEqual([item]);
        expect(writePaletteFavorites(favorites, storage)).toBe(true);
        expect(readPaletteFavorites(storage)).toEqual([item]);
        expect(togglePaletteFavorite(favorites, item)).toEqual([]);
    });

    test('ignores malformed stored favorites', () => {
        const storage = {
            getItem: () => '{not-json',
            setItem: () => {},
        };

        expect(readPaletteFavorites(storage)).toEqual([]);
    });
});
