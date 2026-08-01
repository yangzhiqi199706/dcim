# Palette Search and Favorites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let designers search the active material category immediately and save reusable palette items to a local favorites view.

**Architecture:** A pure `paletteLibrary` module gives every draggable palette item a stable source-aware identifier, filters human-readable fields, and safely persists a serializable favorite list in browser storage. `ItemBox` decorates its existing component, template, and gallery data with that metadata; it owns the query and favorite-view state while preserving existing drag, upload, and delete flows.

**Tech Stack:** React 18, Ant Design buttons/icons, Material UI nav icons, browser `localStorage`, existing i18n dictionaries, Jest via react-scripts.

---

### Task 1: Palette Library Core

**Files:**
- Create: `wwwroot/src/Page/paletteLibrary.js`
- Create: `wwwroot/src/Page/paletteLibrary.test.js`

- [x] **Step 1: Write failing tests for stable ids, keyword filtering, and favorite toggling**

```js
import { createPaletteItem, filterPaletteItems, togglePaletteFavorite } from './paletteLibrary';

test('filters items by name and chart category without changing their drag model', () => {
    const item = createPaletteItem({ moduleName: 'Load chart', moduleJson: { children: [{ attrs: { cat: 'bar' } }] } }, 'chart');
    expect(filterPaletteItems([item], 'BAR')).toEqual([item]);
    expect(togglePaletteFavorite([], item)).toEqual([item]);
});
```

- [x] **Step 2: Run the focused test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test paletteLibrary.test.js --watchAll=false`

Expected: FAIL because `./paletteLibrary` does not exist.

- [x] **Step 3: Implement the minimal pure helpers**

```js
export const createPaletteItem = (item, source) => ({
    ...item,
    paletteSource: source,
    favoriteId: createFavoriteId(source, item),
});

export const togglePaletteFavorite = (favorites, item) => (
    favorites.some((favorite) => favorite.favoriteId === item.favoriteId)
        ? favorites.filter((favorite) => favorite.favoriteId !== item.favoriteId)
        : favorites.concat(item)
);
```

Implement safe read/write helpers around `localStorage`; invalid stored JSON returns an empty favorite list, and unavailable storage does not throw.

- [x] **Step 4: Run the focused core tests and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test paletteLibrary.test.js --watchAll=false`

Expected: PASS.

### Task 2: ItemBox Search and Favorite Controls

**Files:**
- Modify: `wwwroot/src/Page/ItemBox.js`
- Modify: `wwwroot/src/Page/ItemNav.js`
- Modify: `wwwroot/src/Page/ItemBox.test.js`
- Modify: `wwwroot/src/Assets/style.css`

- [x] **Step 1: Add a failing ItemBox integration assertion**

```js
const source = fs.readFileSync(path.join(__dirname, 'ItemBox.js'), 'utf8');
expect(source).toContain("import { createPaletteItem, filterPaletteItems");
expect(source).toContain('data-palette-search');
expect(source).toContain('data-palette-favorite');
expect(source).toContain('selectedNav === 7');
```

- [x] **Step 2: Run the focused ItemBox test and verify RED**

Run: `node node_modules/react-scripts/bin/react-scripts.js test ItemBox.test.js --watchAll=false`

Expected: FAIL because the search and favorite integration is absent.

- [x] **Step 3: Decorate material sources and render controls**

```jsx
<input
    type="search"
    data-palette-search
    value={paletteQuery}
    onChange={(event) => setPaletteQuery(event.target.value)}
    placeholder={t('itemBox.searchPlaceholder')}
/>
<button
    type="button"
    data-palette-favorite={item.favoriteId}
    onClick={() => toggleFavorite(item)}
>
    {isFavorite ? <StarFilled /> : <StarOutlined />}
</button>
```

Add a favorite nav item at index `7`. Decorate basic components, charts, templates, system images, and uploaded images using their source-specific ids. Filter only the active palette source or the favorites view; preserve the page tree unchanged. Use the filtered collection in every draggable material renderer.

- [x] **Step 4: Keep favorites consistent after deletion**

Pass the favorite id when deleting an uploaded image or page template and remove it from storage only after the existing delete operation reports success.

- [x] **Step 5: Run ItemBox and core tests and verify GREEN**

Run: `node node_modules/react-scripts/bin/react-scripts.js test ItemBox.test.js paletteLibrary.test.js --watchAll=false`

Expected: PASS.

### Task 3: Localization and Verification

**Files:**
- Modify: `wwwroot/src/i18n/dictionaries/zh-CN.js`
- Modify: `wwwroot/src/i18n/dictionaries/en-US.js`
- Modify: `docs/superpowers/plans/2026-08-01-palette-search-and-favorites.md`

- [x] **Step 1: Add visible strings to both dictionaries**

```js
itemBox: {
  favorites: '...',
  searchPlaceholder: '...',
  noSearchResults: '...',
  addFavorite: '...',
  removeFavorite: '...',
}
```

- [x] **Step 2: Verify source-language policy**

Run: `npm run check:no-cjk`

Expected: PASS; only `zh-CN.js` contains new Chinese copy.

- [x] **Step 3: Run the full Jest suite**

Run: `node node_modules/react-scripts/bin/react-scripts.js test --watchAll=false`

Expected: all suites pass.

- [x] **Step 4: Build the production bundle**

Run: `$env:NODE_OPTIONS='--openssl-legacy-provider'; npm run build`

Expected: exit code 0.

- [x] **Step 5: Commit the feature**

```powershell
git add docs/superpowers/plans/2026-08-01-palette-search-and-favorites.md wwwroot/src/Page/paletteLibrary.js wwwroot/src/Page/paletteLibrary.test.js wwwroot/src/Page/ItemBox.js wwwroot/src/Page/ItemNav.js wwwroot/src/Page/ItemBox.test.js wwwroot/src/Assets/style.css wwwroot/src/i18n/dictionaries/zh-CN.js wwwroot/src/i18n/dictionaries/en-US.js
git -c core.hooksPath=/dev/null commit -m "feat(designer): add palette search and favorites"
```

Expected: one focused commit on the existing isolated feature branch.

## Self-Review

The plan covers current-category search, a persistent favorites view, source-aware item identities, drag compatibility, deletion consistency, localization, and no-backend storage. Page navigation remains intentionally excluded because it is not a reusable palette item. Function names and the nav index remain consistent across all tasks.
