export const PALETTE_FAVORITES_STORAGE_KEY = 'page_designer_palette_favorites_v1';

const normalizeText = (value) => String(value === undefined || value === null ? '' : value)
    .trim()
    .toLowerCase();

const getFirstChild = (item) => {
    const children = item && item.moduleJson && item.moduleJson.children;
    return Array.isArray(children) && children.length > 0 ? children[0] : null;
};

const getItemSignature = (item) => {
    const child = getFirstChild(item);
    const attrs = child && child.attrs ? child.attrs : {};
    return [
        item && item.moduleName,
        item && item.iconBase64,
        child && child.className,
        attrs.name,
        attrs.cat,
        attrs.title,
        attrs.image,
    ].map(normalizeText).join('|');
};

const getSearchText = (item) => {
    const child = getFirstChild(item);
    const attrs = child && child.attrs ? child.attrs : {};
    return [
        item && item.moduleName,
        item && item.iconBase64,
        child && child.className,
        attrs.name,
        attrs.cat,
        attrs.title,
        attrs.text,
        attrs.image,
    ].map(normalizeText).join(' ');
};

const getStorage = (storage) => {
    if (storage) return storage;
    if (typeof window !== 'undefined' && window.localStorage) return window.localStorage;
    return null;
};

export const createPaletteItem = (item, source) => {
    const paletteSource = normalizeText(source);
    const favoriteId = `${paletteSource}:${getItemSignature(item)}`;
    return {
        ...item,
        paletteSource,
        favoriteId,
    };
};

export const filterPaletteItems = (items, query) => {
    const keyword = normalizeText(query);
    if (!keyword) return Array.isArray(items) ? items : [];
    return (Array.isArray(items) ? items : []).filter((item) => getSearchText(item).includes(keyword));
};

export const togglePaletteFavorite = (favorites, item) => {
    const source = Array.isArray(favorites) ? favorites : [];
    if (!item || !item.favoriteId) return source;
    return source.some((favorite) => favorite && favorite.favoriteId === item.favoriteId)
        ? source.filter((favorite) => favorite && favorite.favoriteId !== item.favoriteId)
        : source.concat(item);
};

export const readPaletteFavorites = (storage) => {
    const target = getStorage(storage);
    if (!target || typeof target.getItem !== 'function') return [];
    try {
        const raw = target.getItem(PALETTE_FAVORITES_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.filter((item) => item && item.favoriteId) : [];
    } catch (error) {
        return [];
    }
};

export const writePaletteFavorites = (favorites, storage) => {
    const target = getStorage(storage);
    if (!target || typeof target.setItem !== 'function') return false;
    try {
        target.setItem(PALETTE_FAVORITES_STORAGE_KEY, JSON.stringify(Array.isArray(favorites) ? favorites : []));
        return true;
    } catch (error) {
        return false;
    }
};

export default {
    createPaletteItem,
    filterPaletteItems,
    readPaletteFavorites,
    togglePaletteFavorite,
    writePaletteFavorites,
};
