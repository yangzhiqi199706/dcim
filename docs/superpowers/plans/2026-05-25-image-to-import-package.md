# Image To Import Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a script that converts one image into a zip package that the existing page import flow can import, with the image as a background and editable Text, Rect, and Line overlay elements.

**Architecture:** Add focused CommonJS modules under `wwwroot/scripts/image-package/`, plus a CLI wrapper at `wwwroot/scripts/image-to-import-package.js`. The first version uses deterministic local image analysis and optional OCR JSON input, generates the existing double-encoded Konva Stage txt format, then writes a zip compatible with `server/local-api-routes.js`.

**Tech Stack:** Node.js CommonJS, built-in `fs/path/zlib/assert`, existing local zip format conventions, no new npm dependencies.

---

## File Structure

- Create: `wwwroot/scripts/image-package/imageInfo.js`
  - Reads PNG dimensions without external dependencies.

- Create: `wwwroot/scripts/image-package/detection.js`
  - Normalizes OCR JSON input, maps coordinates to 1920x1080, and creates conservative rectangle/line candidates.

- Create: `wwwroot/scripts/image-package/konvaFactory.js`
  - Builds Stage JSON and project-compatible `Group + moduleJson + children` elements.

- Create: `wwwroot/scripts/image-package/zipWriter.js`
  - Writes UTF-8 zip files using the same zip structure as the local export API.

- Create: `wwwroot/scripts/image-package/generator.js`
  - Orchestrates image copy, detection, Stage txt generation, and zip output.

- Create: `wwwroot/scripts/image-to-import-package.js`
  - CLI entry point.

- Create: `wwwroot/scripts/image-package/__tests__/run-tests.js`
  - Node `assert` tests for image parsing, coordinate mapping, Stage output, and zip output.

- Modify: `wwwroot/package.json`
  - Add `image:package` and `test:image-package` scripts.

---

### Task 1: PNG Dimension Reader

**Files:**
- Create: `wwwroot/scripts/image-package/imageInfo.js`
- Create: `wwwroot/scripts/image-package/__tests__/run-tests.js`

- [ ] **Step 1: Create failing tests for PNG dimension parsing**

Create `wwwroot/scripts/image-package/__tests__/run-tests.js`:

```js
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const { readPngSize, getImageInfo } = require('../imageInfo');

const repoRoot = path.resolve(__dirname, '../../..');
const samplePng = path.resolve(repoRoot, '../preview_import_original/img_uploads_1727243565.png');

function testReadPngSize() {
  const size = readPngSize(samplePng);
  assert.deepStrictEqual(size, { width: 1920, height: 1080 });
}

function testGetImageInfo() {
  const info = getImageInfo(samplePng);
  assert.strictEqual(info.width, 1920);
  assert.strictEqual(info.height, 1080);
  assert.strictEqual(info.ext, '.png');
  assert.strictEqual(info.mime, 'image/png');
}

function run() {
  assert.ok(fs.existsSync(samplePng), `Missing sample image: ${samplePng}`);
  testReadPngSize();
  testGetImageInfo();
  console.log('image-package tests passed');
}

run();
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: FAIL with `Cannot find module '../imageInfo'`.

- [ ] **Step 3: Implement PNG dimension reader**

Create `wwwroot/scripts/image-package/imageInfo.js`:

```js
const fs = require('fs');
const path = require('path');

const PNG_SIGNATURE_HEX = '89504e470d0a1a0a';

function readPngSize(filePath) {
  const buffer = fs.readFileSync(filePath);
  if (buffer.length < 24 || buffer.slice(0, 8).toString('hex') !== PNG_SIGNATURE_HEX) {
    throw new Error('unsupported image format: expected png');
  }
  return {
    width: buffer.readUInt32BE(16),
    height: buffer.readUInt32BE(20),
  };
}

function getImageInfo(filePath) {
  const absPath = path.resolve(filePath);
  const ext = path.extname(absPath).toLowerCase();
  if (ext !== '.png') {
    throw new Error('unsupported image format: only png is supported in v1');
  }
  const size = readPngSize(absPath);
  return {
    path: absPath,
    ext,
    mime: 'image/png',
    width: size.width,
    height: size.height,
  };
}

module.exports = {
  readPngSize,
  getImageInfo,
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: PASS with `image-package tests passed`.

---

### Task 2: Detection Normalization

**Files:**
- Create: `wwwroot/scripts/image-package/detection.js`
- Modify: `wwwroot/scripts/image-package/__tests__/run-tests.js`

- [ ] **Step 1: Add failing tests for coordinate mapping and OCR normalization**

Append this code before `run()` in `wwwroot/scripts/image-package/__tests__/run-tests.js`:

```js
const {
  createCoordinateMapper,
  normalizeOcrItems,
  createRectCandidates,
  createLineCandidates,
} = require('../detection');

function testCoordinateMapper() {
  const map = createCoordinateMapper({ width: 960, height: 540 }, { width: 1920, height: 1080 });
  assert.deepStrictEqual(map.box({ x: 10, y: 20, width: 30, height: 40 }), {
    x: 20,
    y: 40,
    width: 60,
    height: 80,
  });
}

function testNormalizeOcrItems() {
  const items = normalizeOcrItems([
    { text: ' A ', x: 10, y: 20, width: 30, height: 40, confidence: 0.9 },
    { text: ' ', x: 1, y: 2, width: 3, height: 4 },
    { text: 'B', box: { x: 5, y: 6, width: 7, height: 8 } },
  ]);
  assert.deepStrictEqual(items, [
    { text: 'A', x: 10, y: 20, width: 30, height: 40, confidence: 0.9 },
    { text: 'B', x: 5, y: 6, width: 7, height: 8, confidence: 1 },
  ]);
}

function testShapeCandidates() {
  const rects = createRectCandidates([{ x: 10, y: 20, width: 30, height: 40 }]);
  assert.strictEqual(rects.length, 1);
  assert.strictEqual(rects[0].kind, 'rect');
  const lines = createLineCandidates([{ x1: 0, y1: 0, x2: 100, y2: 0 }]);
  assert.strictEqual(lines.length, 1);
  assert.strictEqual(lines[0].kind, 'line');
}
```

Then add calls inside `run()` after `testGetImageInfo();`:

```js
  testCoordinateMapper();
  testNormalizeOcrItems();
  testShapeCandidates();
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: FAIL with `Cannot find module '../detection'`.

- [ ] **Step 3: Implement detection helpers**

Create `wwwroot/scripts/image-package/detection.js`:

```js
function round(value) {
  return Math.round(Number(value) * 100) / 100;
}

function positiveNumber(value, fallback = 0) {
  const num = Number(value);
  return Number.isFinite(num) && num > 0 ? num : fallback;
}

function createCoordinateMapper(sourceSize, targetSize = { width: 1920, height: 1080 }) {
  const scaleX = targetSize.width / positiveNumber(sourceSize.width, targetSize.width);
  const scaleY = targetSize.height / positiveNumber(sourceSize.height, targetSize.height);
  return {
    box(box) {
      return {
        x: round(Number(box.x || 0) * scaleX),
        y: round(Number(box.y || 0) * scaleY),
        width: round(positiveNumber(box.width) * scaleX),
        height: round(positiveNumber(box.height) * scaleY),
      };
    },
    point(point) {
      return {
        x: round(Number(point.x || 0) * scaleX),
        y: round(Number(point.y || 0) * scaleY),
      };
    },
  };
}

function normalizeOcrItems(rawItems) {
  if (!Array.isArray(rawItems)) return [];
  return rawItems
    .map((item) => {
      const box = item.box || item;
      const text = String(item.text || '').trim();
      const width = positiveNumber(box.width);
      const height = positiveNumber(box.height);
      if (!text || !width || !height) return null;
      return {
        text,
        x: Number(box.x || 0),
        y: Number(box.y || 0),
        width,
        height,
        confidence: Number.isFinite(Number(item.confidence)) ? Number(item.confidence) : 1,
      };
    })
    .filter(Boolean);
}

function createRectCandidates(rawRects) {
  if (!Array.isArray(rawRects)) return [];
  return rawRects
    .map((rect) => ({
      kind: 'rect',
      x: Number(rect.x || 0),
      y: Number(rect.y || 0),
      width: positiveNumber(rect.width),
      height: positiveNumber(rect.height),
      fill: rect.fill || 'rgba(22,50,107,0.25)',
      stroke: rect.stroke || '#00ffff',
      strokeWidth: Number.isFinite(Number(rect.strokeWidth)) ? Number(rect.strokeWidth) : 1,
      opacity: Number.isFinite(Number(rect.opacity)) ? Number(rect.opacity) : 1,
    }))
    .filter((rect) => rect.width >= 4 && rect.height >= 4);
}

function createLineCandidates(rawLines) {
  if (!Array.isArray(rawLines)) return [];
  return rawLines
    .map((line) => ({
      kind: 'line',
      points: [
        Number(line.x1 || 0),
        Number(line.y1 || 0),
        Number(line.x2 || 0),
        Number(line.y2 || 0),
      ],
      stroke: line.stroke || '#00ffff',
      strokeWidth: Number.isFinite(Number(line.strokeWidth)) ? Number(line.strokeWidth) : 1,
      opacity: Number.isFinite(Number(line.opacity)) ? Number(line.opacity) : 1,
    }))
    .filter((line) => line.points.some((value) => value !== 0));
}

module.exports = {
  createCoordinateMapper,
  normalizeOcrItems,
  createRectCandidates,
  createLineCandidates,
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: PASS with `image-package tests passed`.

---

### Task 3: Konva Stage Factory

**Files:**
- Create: `wwwroot/scripts/image-package/konvaFactory.js`
- Modify: `wwwroot/scripts/image-package/__tests__/run-tests.js`

- [ ] **Step 1: Add failing tests for Stage JSON generation**

Append this code before `run()` in `wwwroot/scripts/image-package/__tests__/run-tests.js`:

```js
const {
  createStage,
  encodePageText,
  createTextElement,
  createRectElement,
  createLineElement,
} = require('../konvaFactory');

function testKonvaFactory() {
  const textElement = createTextElement({ text: 'Title', x: 10, y: 20, width: 100, height: 30 });
  const rectElement = createRectElement({ x: 1, y: 2, width: 3, height: 4 });
  const lineElement = createLineElement({ points: [0, 0, 10, 0] });
  const stage = createStage({
    backgroundImage: '../Images/uploads/source.png',
    elements: [textElement, rectElement, lineElement],
  });
  assert.strictEqual(stage.attrs.width, 1920);
  assert.strictEqual(stage.children[0].children.length, 4);
  assert.strictEqual(stage.children[0].children[0].attrs.id, 'canvasBackground');
  assert.strictEqual(textElement.attrs.moduleJson.children[0].className, 'Text');
  assert.strictEqual(rectElement.attrs.moduleJson.children[0].className, 'Rect');
  assert.strictEqual(lineElement.attrs.moduleJson.children[0].className, 'Line');
  const encoded = encodePageText(stage);
  assert.strictEqual(typeof JSON.parse(encoded), 'string');
  assert.strictEqual(JSON.parse(JSON.parse(encoded)).className, 'Stage');
}
```

Then add this call inside `run()`:

```js
  testKonvaFactory();
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: FAIL with `Cannot find module '../konvaFactory'`.

- [ ] **Step 3: Implement Konva factory**

Create `wwwroot/scripts/image-package/konvaFactory.js`:

```js
let idCounter = Date.now();

function nextId() {
  idCounter += 1;
  return String(idCounter);
}

function createModuleAttrForText() {
  return [
    {
      attrGroupName: 'Appearance',
      attrGroupContent: [
        { attrName: 'Text', attrCode: 'text', attrType: 'textarea', attrWhere: 'description' },
        { attrName: 'Font Size', attrCode: 'fontSize', attrType: 'number', attrWhere: 'description' },
        { attrName: 'Font Color', attrCode: 'fill', attrType: 'color', attrWhere: 'description' },
      ],
    },
  ];
}

function createGroup(moduleJson, attrs) {
  const child = moduleJson.children[0];
  return {
    attrs: {
      id: attrs.id || nextId(),
      handleTool: false,
      x: attrs.x || 0,
      y: attrs.y || 0,
      src: attrs.src || '',
      moduleJson,
      draggable: attrs.draggable !== false,
      time: new Date().toLocaleString(),
      width: attrs.width || moduleJson.width || 50,
      height: attrs.height || moduleJson.height || 50,
      name: 'group',
    },
    className: 'Group',
    children: [
      {
        attrs: { ...child.attrs },
        className: child.className,
      },
    ],
  };
}

function createTextElement(item) {
  const fontSize = Math.max(10, Math.round((item.fontSize || item.height || 20) * 0.7));
  const child = {
    attrs: {
      text: item.text,
      fontSize,
      lineHeight: 1,
      fontFamily: 'Arial',
      fill: item.fill || '#00ffff',
      padding: 0,
      align: 'left',
      fontStyle: 'normal',
      verticalAlign: 'middle',
      name: 'description',
      width: item.width,
      height: item.height,
    },
    className: 'Text',
  };
  const moduleJson = {
    attrs: {
      dataKey: [],
      moduleAttr: createModuleAttrForText(),
    },
    children: [child],
    width: item.width,
    height: item.height,
  };
  return createGroup(moduleJson, item);
}

function createRectElement(item) {
  const child = {
    attrs: {
      name: 'myShape',
      width: item.width,
      height: item.height,
      stroke: item.stroke || '#00ffff',
      strokeWidth: item.strokeWidth == null ? 1 : item.strokeWidth,
      fill: item.fill || 'rgba(0,0,0,0)',
      opacity: item.opacity == null ? 1 : item.opacity,
    },
    className: 'Rect',
  };
  const moduleJson = {
    attrs: {
      moduleAttr: [
        {
          attrGroupName: 'Appearance',
          attrGroupContent: [
            { attrName: 'Fill', attrCode: 'fill', attrType: 'color', attrWhere: 'myShape' },
            { attrName: 'Stroke', attrCode: 'stroke', attrType: 'color', attrWhere: 'myShape' },
          ],
        },
      ],
    },
    children: [child],
    width: item.width,
    height: item.height,
  };
  return createGroup(moduleJson, item);
}

function createLineElement(item) {
  const child = {
    attrs: {
      points: item.points,
      stroke: item.stroke || '#00ffff',
      strokeWidth: item.strokeWidth == null ? 1 : item.strokeWidth,
      opacity: item.opacity == null ? 1 : item.opacity,
      name: 'myLine',
    },
    className: 'Line',
  };
  const moduleJson = {
    attrs: { moduleAttr: [] },
    children: [child],
    width: 50,
    height: 50,
  };
  return createGroup(moduleJson, { ...item, x: 0, y: 0, width: 50, height: 50 });
}

function createStage(options) {
  return {
    attrs: {
      width: 1920,
      height: 1080,
      className: 'canvasStage canvasStage2',
    },
    className: 'Stage',
    children: [
      {
        attrs: {
          style: { backgroundColor: '#fff' },
        },
        className: 'Layer',
        children: [
          {
            attrs: {
              width: 1920,
              height: 1080,
              fillPatternRepeat: 'no-repeat',
              id: 'canvasBackground',
              fillPatternImage: options.backgroundImage,
              alarmCatch: '1',
            },
            className: 'Rect',
          },
          ...(options.elements || []),
        ],
      },
    ],
  };
}

function encodePageText(stage) {
  return JSON.stringify(JSON.stringify(stage));
}

module.exports = {
  createStage,
  encodePageText,
  createTextElement,
  createRectElement,
  createLineElement,
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: PASS with `image-package tests passed`.

---

### Task 4: Zip Writer

**Files:**
- Create: `wwwroot/scripts/image-package/zipWriter.js`
- Modify: `wwwroot/scripts/image-package/__tests__/run-tests.js`

- [ ] **Step 1: Add failing tests for zip output**

Append this code before `run()` in `wwwroot/scripts/image-package/__tests__/run-tests.js`:

```js
const { createZipBuffer } = require('../zipWriter');

function testZipWriter() {
  const zipBuffer = createZipBuffer([
    { name: 'page.txt', data: Buffer.from('hello', 'utf8') },
    { name: 'img/' },
    { name: 'img/uploads/source.png', data: Buffer.from([1, 2, 3]) },
  ]);
  assert.ok(Buffer.isBuffer(zipBuffer));
  assert.strictEqual(zipBuffer.readUInt32LE(0), 0x04034b50);
  assert.ok(zipBuffer.includes(Buffer.from('page.txt')));
  assert.ok(zipBuffer.includes(Buffer.from('img/uploads/source.png')));
}
```

Then add this call inside `run()`:

```js
  testZipWriter();
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: FAIL with `Cannot find module '../zipWriter'`.

- [ ] **Step 3: Implement zip writer**

Create `wwwroot/scripts/image-package/zipWriter.js`:

```js
const zlib = require('zlib');

const CRC32_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i += 1) {
    let crc = i;
    for (let j = 0; j < 8; j += 1) {
      crc = (crc & 1) ? (0xedb88320 ^ (crc >>> 1)) : (crc >>> 1);
    }
    table[i] = crc >>> 0;
  }
  return table;
})();

function crc32(buffer) {
  let crc = 0xffffffff;
  for (let i = 0; i < buffer.length; i += 1) {
    crc = CRC32_TABLE[(crc ^ buffer[i]) & 0xff] ^ (crc >>> 8);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function toDosDateTime(date = new Date()) {
  const year = Math.min(Math.max(date.getFullYear(), 1980), 2107);
  const month = date.getMonth() + 1;
  const day = date.getDate();
  const hours = date.getHours();
  const minutes = date.getMinutes();
  const seconds = Math.floor(date.getSeconds() / 2);
  return {
    dosTime: (hours << 11) | (minutes << 5) | seconds,
    dosDate: ((year - 1980) << 9) | (month << 5) | day,
  };
}

function createZipBuffer(entries) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;
  const utf8Flag = 0x0800;

  entries.forEach((entry) => {
    const name = String(entry.name || '').replace(/^\/+/, '').replace(/\\/g, '/');
    if (!name) return;

    const isDirectory = name.endsWith('/');
    const nameBuffer = Buffer.from(name, 'utf8');
    const dataBuffer = isDirectory ? Buffer.alloc(0) : Buffer.from(entry.data || Buffer.alloc(0));
    const compressedBuffer = isDirectory ? Buffer.alloc(0) : zlib.deflateRawSync(dataBuffer);
    const compressionMethod = isDirectory ? 0 : 8;
    const checksum = isDirectory ? 0 : crc32(dataBuffer);
    const { dosTime, dosDate } = toDosDateTime(entry.mtime || new Date());

    const localHeader = Buffer.alloc(30);
    localHeader.writeUInt32LE(0x04034b50, 0);
    localHeader.writeUInt16LE(20, 4);
    localHeader.writeUInt16LE(utf8Flag, 6);
    localHeader.writeUInt16LE(compressionMethod, 8);
    localHeader.writeUInt16LE(dosTime, 10);
    localHeader.writeUInt16LE(dosDate, 12);
    localHeader.writeUInt32LE(checksum >>> 0, 14);
    localHeader.writeUInt32LE(compressedBuffer.length >>> 0, 18);
    localHeader.writeUInt32LE(dataBuffer.length >>> 0, 22);
    localHeader.writeUInt16LE(nameBuffer.length, 26);
    localHeader.writeUInt16LE(0, 28);

    localParts.push(localHeader, nameBuffer, compressedBuffer);

    const centralHeader = Buffer.alloc(46);
    centralHeader.writeUInt32LE(0x02014b50, 0);
    centralHeader.writeUInt16LE(20, 4);
    centralHeader.writeUInt16LE(20, 6);
    centralHeader.writeUInt16LE(utf8Flag, 8);
    centralHeader.writeUInt16LE(compressionMethod, 10);
    centralHeader.writeUInt16LE(dosTime, 12);
    centralHeader.writeUInt16LE(dosDate, 14);
    centralHeader.writeUInt32LE(checksum >>> 0, 16);
    centralHeader.writeUInt32LE(compressedBuffer.length >>> 0, 20);
    centralHeader.writeUInt32LE(dataBuffer.length >>> 0, 24);
    centralHeader.writeUInt16LE(nameBuffer.length, 28);
    centralHeader.writeUInt16LE(0, 30);
    centralHeader.writeUInt16LE(0, 32);
    centralHeader.writeUInt16LE(0, 34);
    centralHeader.writeUInt16LE(0, 36);
    centralHeader.writeUInt32LE(isDirectory ? 0x10 : 0, 38);
    centralHeader.writeUInt32LE(offset >>> 0, 42);

    centralParts.push(centralHeader, nameBuffer);
    offset += localHeader.length + nameBuffer.length + compressedBuffer.length;
  });

  const centralDir = Buffer.concat(centralParts);
  const localData = Buffer.concat(localParts);
  const endRecord = Buffer.alloc(22);
  const entryCount = Math.floor(centralParts.length / 2);

  endRecord.writeUInt32LE(0x06054b50, 0);
  endRecord.writeUInt16LE(0, 4);
  endRecord.writeUInt16LE(0, 6);
  endRecord.writeUInt16LE(entryCount, 8);
  endRecord.writeUInt16LE(entryCount, 10);
  endRecord.writeUInt32LE(centralDir.length >>> 0, 12);
  endRecord.writeUInt32LE(localData.length >>> 0, 16);
  endRecord.writeUInt16LE(0, 20);

  return Buffer.concat([localData, centralDir, endRecord]);
}

module.exports = {
  createZipBuffer,
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: PASS with `image-package tests passed`.

---

### Task 5: Package Generator

**Files:**
- Create: `wwwroot/scripts/image-package/generator.js`
- Modify: `wwwroot/scripts/image-package/__tests__/run-tests.js`

- [ ] **Step 1: Add failing integration test for package generation**

Append this code before `run()` in `wwwroot/scripts/image-package/__tests__/run-tests.js`:

```js
const { generateImagePackage } = require('../generator');

function testGenerateImagePackage() {
  const outputDir = path.resolve(repoRoot, 'tmp-image-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'sample-page',
    pageIndex: '79',
    outputDir,
    timestamp: 1770000000000,
    ocrItems: [{ text: 'Title', x: 100, y: 50, width: 200, height: 40 }],
    rects: [{ x: 10, y: 20, width: 300, height: 200 }],
    lines: [{ x1: 0, y1: 0, x2: 100, y2: 0 }],
  });
  assert.ok(fs.existsSync(result.zipPath));
  assert.strictEqual(result.elementCounts.text, 1);
  assert.strictEqual(result.elementCounts.rect, 1);
  assert.strictEqual(result.elementCounts.line, 1);
  assert.ok(result.zipPath.endsWith('sample-page_1770000000000.zip'));
  fs.rmSync(outputDir, { recursive: true, force: true });
}
```

Then add this call inside `run()`:

```js
  testGenerateImagePackage();
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: FAIL with `Cannot find module '../generator'`.

- [ ] **Step 3: Implement generator**

Create `wwwroot/scripts/image-package/generator.js`:

```js
const fs = require('fs');
const path = require('path');

const { getImageInfo } = require('./imageInfo');
const {
  createCoordinateMapper,
  normalizeOcrItems,
  createRectCandidates,
  createLineCandidates,
} = require('./detection');
const {
  createStage,
  encodePageText,
  createTextElement,
  createRectElement,
  createLineElement,
} = require('./konvaFactory');
const { createZipBuffer } = require('./zipWriter');

function sanitizeName(name, fallback = 'page') {
  const src = String(name || '').trim();
  const clean = path.basename(src).replace(/[\\/:*?"<>|]/g, '_').replace(/\s+/g, ' ').replace(/^\.+|\.+$/g, '').trim();
  return clean || fallback;
}

function ensureDirectory(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function mapTextItem(mapper, item) {
  return {
    ...mapper.box(item),
    text: item.text,
    fill: item.fill || '#00ffff',
  };
}

function mapRectItem(mapper, item) {
  return {
    ...item,
    ...mapper.box(item),
  };
}

function mapLineItem(mapper, item) {
  const p1 = mapper.point({ x: item.points[0], y: item.points[1] });
  const p2 = mapper.point({ x: item.points[2], y: item.points[3] });
  return {
    ...item,
    points: [p1.x, p1.y, p2.x, p2.y],
  };
}

function generateImagePackage(options) {
  const imageInfo = getImageInfo(options.imagePath);
  const timestamp = options.timestamp || Date.now();
  const pageName = sanitizeName(options.pageName, 'generated_page');
  const pageIndex = String(options.pageIndex || 1);
  const outputDir = path.resolve(options.outputDir || path.join(__dirname, '../../public/Images/exports'));
  const imageFileName = `${sanitizeName(path.parse(imageInfo.path).name, 'source')}_${timestamp}${imageInfo.ext}`;
  const pageTxtName = `${pageName}[${pageIndex}].txt`;

  ensureDirectory(outputDir);

  const mapper = createCoordinateMapper(imageInfo, { width: 1920, height: 1080 });
  const textElements = normalizeOcrItems(options.ocrItems)
    .map((item) => createTextElement(mapTextItem(mapper, item)));
  const rectElements = createRectCandidates(options.rects)
    .map((item) => createRectElement(mapRectItem(mapper, item)));
  const lineElements = createLineCandidates(options.lines)
    .map((item) => createLineElement(mapLineItem(mapper, item)));

  const stage = createStage({
    backgroundImage: `../Images/uploads/${imageFileName}`,
    elements: [...rectElements, ...lineElements, ...textElements],
  });
  const pageText = encodePageText(stage);
  const imageBuffer = fs.readFileSync(imageInfo.path);
  const zipBuffer = createZipBuffer([
    { name: pageTxtName, data: Buffer.from(pageText, 'utf8') },
    { name: 'img/' },
    { name: `img/uploads/${imageFileName}`, data: imageBuffer },
  ]);

  const zipPath = path.join(outputDir, `${pageName}_${timestamp}.zip`);
  fs.writeFileSync(zipPath, zipBuffer);

  return {
    zipPath,
    pageTxtName,
    imageFileName,
    elementCounts: {
      text: textElements.length,
      rect: rectElements.length,
      line: lineElements.length,
    },
  };
}

module.exports = {
  generateImagePackage,
  sanitizeName,
};
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd wwwroot
node scripts/image-package/__tests__/run-tests.js
```

Expected: PASS with `image-package tests passed`.

---

### Task 6: CLI Wrapper And Package Scripts

**Files:**
- Create: `wwwroot/scripts/image-to-import-package.js`
- Modify: `wwwroot/package.json`

- [ ] **Step 1: Add CLI wrapper**

Create `wwwroot/scripts/image-to-import-package.js`:

```js
#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const { generateImagePackage } = require('./image-package/generator');

function parseArgs(argv) {
  const result = {};
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (!arg.startsWith('--')) continue;
    const key = arg.slice(2);
    const value = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : 'true';
    result[key] = value;
    if (value !== 'true') i += 1;
  }
  return result;
}

function readJsonArray(filePath, fallback = []) {
  if (!filePath) return fallback;
  const absPath = path.resolve(filePath);
  if (!fs.existsSync(absPath)) return fallback;
  const parsed = JSON.parse(fs.readFileSync(absPath, 'utf8'));
  return Array.isArray(parsed) ? parsed : fallback;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!args.image || !args.name) {
    console.error('Usage: node scripts/image-to-import-package.js --image <png> --name <page-name> --index <page-index> [--ocr ocr.json] [--rects rects.json] [--lines lines.json] [--out output-dir]');
    process.exit(1);
  }

  const result = generateImagePackage({
    imagePath: args.image,
    pageName: args.name,
    pageIndex: args.index || 1,
    outputDir: args.out,
    ocrItems: readJsonArray(args.ocr),
    rects: readJsonArray(args.rects),
    lines: readJsonArray(args.lines),
  });

  console.log(JSON.stringify(result, null, 2));
}

main();
```

- [ ] **Step 2: Modify package scripts**

Update `wwwroot/package.json` scripts block to include:

```json
"image:package": "node scripts/image-to-import-package.js",
"test:image-package": "node scripts/image-package/__tests__/run-tests.js"
```

The full scripts block should become:

```json
"scripts": {
    "start": "react-scripts start",
    "start:local-api": "node server/local-api.js",
    "build": "react-scripts build",
    "test": "react-scripts test",
    "eject": "react-scripts eject",
    "check:no-cjk": "node scripts/check-no-cjk.js",
    "image:package": "node scripts/image-to-import-package.js",
    "test:image-package": "node scripts/image-package/__tests__/run-tests.js"
}
```

- [ ] **Step 3: Run script usage check**

Run:

```bash
cd wwwroot
node scripts/image-to-import-package.js
```

Expected: FAIL exit with usage text beginning `Usage: node scripts/image-to-import-package.js`.

- [ ] **Step 4: Run image-package tests**

Run:

```bash
cd wwwroot
npm run test:image-package
```

Expected: PASS with `image-package tests passed`.

---

### Task 7: End-To-End Sample Package

**Files:**
- Generated: `wwwroot/public/Images/exports/sample-temperature-page_<timestamp>.zip`

- [ ] **Step 1: Generate a sample zip from the extracted original image**

Run:

```bash
cd wwwroot
npm run image:package -- --image ..\preview_import_original\img_uploads_1727243565.png --name sample-temperature-page --index 79
```

Expected: PASS and JSON output with:

```json
{
  "pageTxtName": "sample-temperature-page[79].txt",
  "elementCounts": {
    "text": 0,
    "rect": 0,
    "line": 0
  }
}
```

- [ ] **Step 2: Verify generated zip exists**

Run:

```bash
cd wwwroot
Get-ChildItem public\Images\exports\sample-temperature-page_*.zip | Select-Object Name,Length
```

Expected: at least one zip file with non-zero length.

- [ ] **Step 3: Verify existing import parser accepts the generated zip**

Run:

```bash
cd wwwroot
node -e "const fs=require('fs');const p=require('path');const f=fs.readdirSync('public/Images/exports').filter(x=>/^sample-temperature-page_.*\\.zip$/.test(x)).pop();if(!f)throw new Error('zip missing');const b=fs.readFileSync(p.join('public/Images/exports',f));if(b.readUInt32LE(0)!==0x04034b50)throw new Error('bad zip');console.log(f,b.length);"
```

Expected: prints zip filename and byte length.

- [ ] **Step 4: Run no-CJK check**

Run:

```bash
cd wwwroot
npm run check:no-cjk
```

Expected: PASS with `Check passed: no CJK or mojibake-like characters found outside dictionaries.`

---

## Self-Review Notes

- Spec coverage:
  - Zip output format is covered by Tasks 4, 5, and 7.
  - Background image as fallback is covered by Task 3 and Task 5.
  - Text, Rect, and Line overlay structures are covered by Tasks 2 and 3.
  - CLI-first implementation is covered by Task 6.
  - Existing import flow remains untouched because no `src/` or `server/` files are modified.

- Placeholder scan:
  - No `TBD`, `TODO`, or undefined follow-up steps are intentionally left in this plan.

- Type consistency:
  - `generateImagePackage`, `createStage`, `encodePageText`, and detection helper names are defined before use.
