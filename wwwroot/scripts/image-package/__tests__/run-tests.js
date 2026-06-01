const assert = require('assert');
const fs = require('fs');
const path = require('path');

const { readPngSize, getImageInfo } = require('../imageInfo');
const {
  createCoordinateMapper,
  normalizeOcrItems,
  createRectCandidates,
  createLineCandidates,
} = require('../detection');
const { detectSimpleShapesFromPng, readPngRgba } = require('../shapeDetector');
const { detectChartRegionsFromPng } = require('../chartDetector');
const {
  extractTemplateElementsFromPageText,
  createElementFromTemplateIndex,
  extractComponentTemplatesFromZip,
  loadTemplateElementsFromZip,
  parseZipEntries,
} = require('../templateImporter');
const {
  createStage,
  encodePageText,
  createTextElement,
  createRectElement,
  createLineElement,
  createImageElement,
} = require('../konvaFactory');
const { createZipBuffer } = require('../zipWriter');
const { generateImagePackage } = require('../generator');
const { validateImagePackage } = require('../validator');
const {
  loadBasicComponentTemplates,
  createBasicChartElement,
} = require('../componentTemplateLibrary');
const { mergeRecognitionOptions } = require('../recognitionConfig');
const { createImageRecognition } = require('../recognizer');
const {
  runImagePackageBatch,
  runImagePackagePipeline,
} = require('../pipeline');
const {
  parseArgs,
  parseChart,
  parseRepeated,
  parseTemplateComponent,
  readJsonObject,
  mergeConfigArgs,
  resolveConfigPaths,
  validatePipelineArgs,
} = require('../cliOptions');

const repoRoot = path.resolve(__dirname, '../../..');
const samplePng = path.resolve(repoRoot, '../preview_import_original/img_uploads_1727243565.png');
const sampleZip = path.resolve(repoRoot, '../机房环境温湿度监测系统 (1)[79] (1).zip');

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

function testKonvaFactory() {
  const textElement = createTextElement({ text: 'Title', x: 10, y: 20, width: 100, height: 30 });
  const rectElement = createRectElement({ x: 1, y: 2, width: 3, height: 4 });
  const lineElement = createLineElement({ points: [0, 0, 10, 0] });
  const imageElement = createImageElement({
    image: 'Images/uploads/overlay.png',
    x: 5,
    y: 6,
    width: 300,
    height: 200,
  });
  const stage = createStage({
    backgroundImage: '../Images/uploads/source.png',
    elements: [textElement, rectElement, lineElement, imageElement],
  });
  assert.strictEqual(stage.attrs.width, 1920);
  assert.strictEqual(stage.children[0].children.length, 5);
  assert.strictEqual(stage.children[0].children[0].attrs.id, 'canvasBackground');
  assert.strictEqual(textElement.attrs.moduleJson.children[0].className, 'Text');
  assert.strictEqual(rectElement.attrs.moduleJson.children[0].className, 'Rect');
  assert.strictEqual(lineElement.attrs.moduleJson.children[0].className, 'Line');
  assert.strictEqual(imageElement.attrs.moduleJson.children[0].className, 'Image');
  const encoded = encodePageText(stage);
  assert.strictEqual(typeof JSON.parse(encoded), 'string');
  assert.strictEqual(JSON.parse(JSON.parse(encoded)).className, 'Stage');
}

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
  assert.strictEqual(result.validation.status, 'PASS');
  assert.ok(result.zipPath.endsWith('sample-page_1770000000000.zip'));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function makeTestPng(width, height, pixels) {
  const zlib = require('zlib');
  const rows = [];
  for (let y = 0; y < height; y += 1) {
    const row = Buffer.alloc(1 + width * 4);
    row[0] = 0;
    for (let x = 0; x < width; x += 1) {
      const offset = 1 + x * 4;
      const color = pixels(x, y);
      row[offset] = color[0];
      row[offset + 1] = color[1];
      row[offset + 2] = color[2];
      row[offset + 3] = color[3] == null ? 255 : color[3];
    }
    rows.push(row);
  }

  function chunk(type, data) {
    const { crc32 } = require('../zipWriter');
    const typeBuffer = Buffer.from(type, 'ascii');
    const body = Buffer.concat([typeBuffer, data]);
    const out = Buffer.alloc(12 + data.length);
    out.writeUInt32BE(data.length, 0);
    typeBuffer.copy(out, 4);
    data.copy(out, 8);
    out.writeUInt32BE(crc32(body), 8 + data.length);
    return out;
  }

  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8;
  ihdr[9] = 6;
  ihdr[10] = 0;
  ihdr[11] = 0;
  ihdr[12] = 0;

  return Buffer.concat([
    Buffer.from('89504e470d0a1a0a', 'hex'),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(Buffer.concat(rows))),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

function testDetectSimpleShapesFromPng() {
  const outputDir = path.resolve(repoRoot, 'tmp-shape-detector-test');
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'shape.png');
  const png = makeTestPng(20, 20, (x, y) => {
    if (y === 5 && x >= 2 && x <= 17) return [0, 255, 255, 255];
    if (x === 10 && y >= 8 && y <= 18) return [0, 255, 255, 255];
    return [0, 0, 20, 255];
  });
  fs.writeFileSync(imagePath, png);
  const detected = detectSimpleShapesFromPng(imagePath, { minLineLength: 8, colorThreshold: 120 });
  assert.ok(detected.lines.some((line) => line.x1 === 2 && line.y1 === 5 && line.x2 === 17 && line.y2 === 5));
  assert.ok(detected.lines.some((line) => line.x1 === 10 && line.y1 === 8 && line.x2 === 10 && line.y2 === 18));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testReadPngRgbaSupportsRealSample() {
  const image = readPngRgba(samplePng);
  assert.strictEqual(image.width, 1920);
  assert.strictEqual(image.height, 1080);
  assert.strictEqual(image.pixels.length, 1920 * 1080 * 4);
}

function testGenerateImagePackageAutoDetectsLines() {
  const outputDir = path.resolve(repoRoot, 'tmp-auto-detect-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'shape.png');
  fs.writeFileSync(imagePath, makeTestPng(20, 20, (x, y) => {
    if (y === 5 && x >= 2 && x <= 17) return [0, 255, 255, 255];
    return [0, 0, 20, 255];
  }));
  const result = generateImagePackage({
    imagePath,
    pageName: 'auto-lines',
    pageIndex: '1',
    outputDir,
    timestamp: 1770000000001,
    detectShapes: true,
    shapeDetection: { minLineLength: 8, colorThreshold: 120 },
  });
  assert.ok(fs.existsSync(result.zipPath));
  assert.ok(result.elementCounts.line >= 1);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testDetectBarChartRegionFromPng() {
  const outputDir = path.resolve(repoRoot, 'tmp-chart-detector-bar-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'bar-chart.png');
  fs.writeFileSync(imagePath, makeTestPng(120, 80, (x, y) => {
    const inBar = (
      (x >= 20 && x <= 29 && y >= 35 && y <= 65)
      || (x >= 42 && x <= 51 && y >= 20 && y <= 65)
      || (x >= 64 && x <= 73 && y >= 28 && y <= 65)
      || (x >= 86 && x <= 95 && y >= 12 && y <= 65)
    );
    return inBar ? [0, 255, 255, 255] : [0, 0, 20, 255];
  }));
  const result = detectChartRegionsFromPng(imagePath, { colorThreshold: 120 });
  assert.ok(result.charts.some((chart) => chart.cat === 'bar' && chart.x <= 20 && chart.width >= 75));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testDetectLineChartRegionFromPng() {
  const outputDir = path.resolve(repoRoot, 'tmp-chart-detector-line-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'line-chart.png');
  fs.writeFileSync(imagePath, makeTestPng(120, 80, (x, y) => {
    const targetY = Math.round(60 - (x - 15) * 0.42 + Math.sin(x / 8) * 4);
    const inLine = x >= 15 && x <= 100 && Math.abs(y - targetY) <= 1;
    return inLine ? [0, 255, 255, 255] : [0, 0, 20, 255];
  }));
  const result = detectChartRegionsFromPng(imagePath, { colorThreshold: 120 });
  assert.ok(result.charts.some((chart) => chart.cat === 'line' && chart.x <= 15 && chart.width >= 80));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageWithOverlayImage() {
  const outputDir = path.resolve(repoRoot, 'tmp-overlay-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'overlay-page',
    pageIndex: '2',
    outputDir,
    timestamp: 1770000000002,
    overlayImages: [{
      path: samplePng,
      x: 100,
      y: 200,
      width: 300,
      height: 150,
    }],
    detectShapes: false,
  });
  assert.ok(fs.existsSync(result.zipPath));
  assert.strictEqual(result.elementCounts.image, 1);
  const zipBuffer = fs.readFileSync(result.zipPath);
  assert.ok(zipBuffer.includes(Buffer.from('img/uploads/img_uploads_1727243565_1770000000002_overlay_1.png')));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function readStageFromGeneratedZip(zipPath) {
  const entries = parseZipEntries(fs.readFileSync(zipPath));
  const txtEntry = entries.find((entry) => path.extname(entry.name).toLowerCase() === '.txt');
  assert.ok(txtEntry, 'generated zip should include a txt page');
  return JSON.parse(JSON.parse(txtEntry.data.toString('utf8')));
}

function testGenerateImagePackageWithTemplateElements() {
  const outputDir = path.resolve(repoRoot, 'tmp-template-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const templateElements = [
    createTextElement({ text: 'Room', x: 1, y: 2, width: 30, height: 10 }),
    createRectElement({ x: 3, y: 4, width: 50, height: 20 }),
  ];
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'template-page',
    pageIndex: '3',
    outputDir,
    timestamp: 1770000000003,
    templateElements,
    detectShapes: false,
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const children = stage.children[0].children;
  assert.strictEqual(result.elementCounts.template, 2);
  assert.strictEqual(children.length, 3);
  assert.strictEqual(children[1].attrs.moduleJson.children[0].className, 'Text');
  assert.strictEqual(children[2].attrs.moduleJson.children[0].className, 'Rect');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageWithTemplateZipAssets() {
  assert.ok(fs.existsSync(sampleZip), `Missing sample zip: ${sampleZip}`);
  const outputDir = path.resolve(repoRoot, 'tmp-template-assets-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'template-assets-page',
    pageIndex: '4',
    outputDir,
    timestamp: 1770000000004,
    templateZip: sampleZip,
    detectShapes: false,
  });
  const entries = parseZipEntries(fs.readFileSync(result.zipPath));
  const names = entries.map((entry) => entry.name);
  assert.strictEqual(result.elementCounts.template, 168);
  assert.ok(names.includes('img/uploads/1716544129.png'));
  assert.ok(names.includes('img/uploads/19294_1778406608792.png'));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testLoadBasicComponentChartTemplates() {
  const templates = loadBasicComponentTemplates();
  assert.ok(templates.charts.line);
  assert.ok(templates.charts.bar);
  assert.ok(templates.charts.pie);
  assert.strictEqual(templates.charts.line.attrs.moduleJson.children[0].className, 'Echart');
  assert.strictEqual(templates.charts.bar.attrs.moduleJson.children[0].attrs.cat, 'bar');
  assert.strictEqual(templates.charts.bar.attrs.moduleJson.attrs.moduleAttr[0].attrGroupName, '数据');
  assert.strictEqual(templates.charts.bar.attrs.moduleJson.attrs.moduleAttr[1].attrGroupName, '外观');
  assert.ok(!JSON.stringify(templates.charts.bar.attrs.moduleJson.attrs.moduleAttr).includes('__i18n__'));
}

function testGenerateImagePackageWithBasicChartTemplate() {
  const outputDir = path.resolve(repoRoot, 'tmp-basic-chart-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const chart = createBasicChartElement('bar', {
    x: 120,
    y: 220,
    width: 420,
    height: 260,
    title: 'Capacity',
    xdata: ['A', 'B'],
    data: [{ name: 'Usage', type: 'bar', data: [12, 20] }],
  });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'basic-chart-page',
    pageIndex: '5',
    outputDir,
    timestamp: 1770000000005,
    templateElements: [chart],
    detectShapes: false,
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const echart = stage.children[0].children[1].attrs.moduleJson.children[0];
  assert.strictEqual(echart.className, 'Echart');
  assert.strictEqual(echart.attrs.cat, 'bar');
  assert.strictEqual(echart.attrs.title, 'Capacity');
  assert.deepStrictEqual(echart.attrs.xdata, ['A', 'B']);
  assert.strictEqual(stage.children[0].children[1].attrs.x, 120);
  assert.strictEqual(stage.children[0].children[1].attrs.width, 420);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageWithChartOptions() {
  const outputDir = path.resolve(repoRoot, 'tmp-chart-options-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'chart-options-page',
    pageIndex: '6',
    outputDir,
    timestamp: 1770000000006,
    chartElements: [{
      cat: 'line',
      x: 50,
      y: 60,
      width: 500,
      height: 280,
      title: 'Trend',
    }],
    detectShapes: false,
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const echart = stage.children[0].children[1].attrs.moduleJson.children[0];
  assert.strictEqual(result.elementCounts.chart, 1);
  assert.strictEqual(echart.className, 'Echart');
  assert.strictEqual(echart.attrs.cat, 'line');
  assert.strictEqual(echart.attrs.title, 'Trend');
  assert.ok(!JSON.stringify(stage).includes('__i18n__'));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageAutoDetectsCharts() {
  const outputDir = path.resolve(repoRoot, 'tmp-auto-chart-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'auto-bar-chart.png');
  fs.writeFileSync(imagePath, makeTestPng(120, 80, (x, y) => {
    const inBar = (
      (x >= 20 && x <= 29 && y >= 35 && y <= 65)
      || (x >= 42 && x <= 51 && y >= 20 && y <= 65)
      || (x >= 64 && x <= 73 && y >= 28 && y <= 65)
      || (x >= 86 && x <= 95 && y >= 12 && y <= 65)
    );
    return inBar ? [0, 255, 255, 255] : [0, 0, 20, 255];
  }));
  const result = generateImagePackage({
    imagePath,
    pageName: 'auto-chart-page',
    pageIndex: '7',
    outputDir,
    timestamp: 1770000000007,
    detectShapes: false,
    detectCharts: true,
    chartDetection: { colorThreshold: 120 },
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const echart = stage.children[0].children[1].attrs.moduleJson.children[0];
  assert.strictEqual(result.elementCounts.chart, 1);
  assert.strictEqual(echart.className, 'Echart');
  assert.strictEqual(echart.attrs.cat, 'bar');
  assert.strictEqual(stage.children[0].children[1].attrs.x, 192);
  assert.strictEqual(stage.children[0].children[1].attrs.y, 54);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testValidateImagePackageReportsMissingImages() {
  const stage = createStage({
    backgroundImage: '../Images/uploads/missing-bg.png',
    elements: [
      createImageElement({
        image: 'Images/uploads/missing-overlay.png',
        x: 1,
        y: 2,
        width: 30,
        height: 20,
      }),
    ],
  });
  const zipBuffer = createZipBuffer([
    { name: 'bad-page[1].txt', data: Buffer.from(encodePageText(stage), 'utf8') },
    { name: 'img/' },
    { name: 'img/uploads/other.png', data: Buffer.from([1, 2, 3]) },
  ]);
  const validation = validateImagePackage(zipBuffer);
  assert.strictEqual(validation.status, 'FAIL');
  assert.ok(validation.missingImages.includes('img/uploads/missing-bg.png'));
  assert.ok(validation.missingImages.includes('img/uploads/missing-overlay.png'));
}

function testExtractTemplateElementsFromPageText() {
  const stage = createStage({
    backgroundImage: '../Images/uploads/bg.png',
    elements: [
      createTextElement({ text: 'Room', x: 1, y: 2, width: 30, height: 10 }),
      createRectElement({ x: 3, y: 4, width: 50, height: 20 }),
    ],
  });
  const encoded = encodePageText(stage);
  const elements = extractTemplateElementsFromPageText(encoded);
  assert.strictEqual(elements.length, 2);
  assert.strictEqual(elements[0].attrs.moduleJson.children[0].className, 'Text');
  assert.strictEqual(elements[1].attrs.moduleJson.children[0].className, 'Rect');
}

function testLoadTemplateElementsFromZip() {
  assert.ok(fs.existsSync(sampleZip), `Missing sample zip: ${sampleZip}`);
  const result = loadTemplateElementsFromZip(sampleZip);
  assert.ok(result.txtName.endsWith('.txt'));
  assert.ok(result.elements.length > 100);
  assert.ok(result.elements.some((item) => item.attrs && item.attrs.moduleJson && item.attrs.moduleJson.children[0].className === 'wetHtml'));
}

function testExtractComponentTemplatesFromZip() {
  assert.ok(fs.existsSync(sampleZip), `Missing sample zip: ${sampleZip}`);
  const templates = extractComponentTemplatesFromZip(sampleZip);
  assert.ok(templates.byClass.wetHtml.length > 0);
  assert.strictEqual(templates.byClass.leakWater.length, 3);
  assert.strictEqual(templates.byName.wetHtml.attrs.moduleJson.children[0].className, 'wetHtml');
  assert.strictEqual(templates.byName.leakWater.attrs.moduleJson.children[0].className, 'leakWater');
}

function testCreateElementFromTemplateIndex() {
  const templates = extractComponentTemplatesFromZip(sampleZip);
  const wet = createElementFromTemplateIndex(templates, { className: 'wetHtml' }, {
    x: 10,
    y: 20,
    width: 100,
    height: 60,
    attrs: {
      text: 'A1',
      dataWen: 28.5,
      dataWet: 61.2,
    },
  });
  const child = wet.attrs.moduleJson.children[0];
  assert.strictEqual(wet.attrs.x, 10);
  assert.strictEqual(wet.attrs.y, 20);
  assert.strictEqual(wet.attrs.width, 78);
  assert.strictEqual(wet.attrs.height, 48);
  assert.strictEqual(child.className, 'wetHtml');
  assert.strictEqual(child.attrs.width, 78);
  assert.strictEqual(child.attrs.height, 48);
  assert.strictEqual(wet.children[1].attrs.width, 78);
  assert.strictEqual(wet.children[1].attrs.height, 48);
  assert.strictEqual(child.attrs.text, 'A1');
  assert.strictEqual(child.attrs.dataWen, 28.5);
  assert.strictEqual(child.attrs.dataWet, 61.2);
}

function testCreateElementFromTemplateIndexCanResizeWetHtmlWhenExplicit() {
  const templates = extractComponentTemplatesFromZip(sampleZip);
  const wet = createElementFromTemplateIndex(templates, { className: 'wetHtml' }, {
    x: 10,
    y: 20,
    width: 100,
    height: 60,
    resize: true,
    attrs: { text: 'A1' },
  });
  const child = wet.attrs.moduleJson.children[0];
  assert.strictEqual(wet.attrs.width, 100);
  assert.strictEqual(wet.attrs.height, 60);
  assert.strictEqual(child.attrs.width, 100);
  assert.strictEqual(child.attrs.height, 60);
  assert.strictEqual(wet.children[1].attrs.width, 100);
  assert.strictEqual(wet.children[1].attrs.height, 60);
}

function testGenerateImagePackageWithTemplateComponentOptions() {
  const outputDir = path.resolve(repoRoot, 'tmp-template-component-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'template-component-page',
    pageIndex: '8',
    outputDir,
    timestamp: 1770000000008,
    templateZip: sampleZip,
    templateComponents: [{
      selector: { className: 'wetHtml' },
      x: 200,
      y: 300,
      width: 90,
      height: 55,
      attrs: { text: 'T1', dataWen: 25.1, dataWet: 50.2 },
    }],
    includeTemplateElements: false,
    detectShapes: false,
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const wet = stage.children[0].children[1].attrs.moduleJson.children[0];
  assert.strictEqual(result.elementCounts.template, 0);
  assert.strictEqual(result.elementCounts.templateComponent, 1);
  assert.strictEqual(wet.className, 'wetHtml');
  assert.strictEqual(wet.attrs.text, 'T1');
  assert.strictEqual(wet.attrs.dataWen, 25.1);
  assert.strictEqual(result.validation.status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageDetectsWetHtmlFromOcr() {
  const outputDir = path.resolve(repoRoot, 'tmp-detect-wet-html-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'detect-wet-html-page',
    pageIndex: '9',
    outputDir,
    timestamp: 1770000000009,
    templateZip: sampleZip,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
    ocrItems: [
      { text: '6#', x: 320, y: 420, width: 24, height: 18 },
      { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
      { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
    ],
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const wetElement = stage.children[0].children.find((item) => (
    item.attrs
    && item.attrs.moduleJson
    && item.attrs.moduleJson.children[0].className === 'wetHtml'
  ));
  const wet = wetElement.attrs.moduleJson.children[0];
  assert.strictEqual(result.elementCounts.text, 0);
  assert.strictEqual(result.elementCounts.templateComponent, 1);
  assert.strictEqual(wetElement.attrs.x, 320);
  assert.strictEqual(wetElement.attrs.y, 420);
  assert.strictEqual(wetElement.attrs.width, 78);
  assert.strictEqual(wetElement.attrs.height, 48);
  assert.strictEqual(wet.attrs.text, '6#');
  assert.strictEqual(wet.attrs.dataWen, 24.8);
  assert.strictEqual(wet.attrs.dataWet, 51.6);
  assert.strictEqual(result.validation.status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageDetectsMultipleWetHtmlFromOcr() {
  const outputDir = path.resolve(repoRoot, 'tmp-detect-multiple-wet-html-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'detect-multiple-wet-html-page',
    pageIndex: '12',
    outputDir,
    timestamp: 1770000000012,
    templateZip: sampleZip,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
    ocrItems: [
      { text: '6#', x: 320, y: 420, width: 24, height: 18 },
      { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
      { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
      { text: '7#', x: 390, y: 420, width: 24, height: 18 },
      { text: '26.1', x: 432, y: 417, width: 42, height: 18 },
      { text: '49.3', x: 432, y: 445, width: 42, height: 18 },
    ],
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const wetItems = stage.children[0].children
    .filter((item) => (
      item.attrs
      && item.attrs.moduleJson
      && item.attrs.moduleJson.children[0].className === 'wetHtml'
    ))
    .map((item) => item.attrs.moduleJson.children[0].attrs)
    .sort((a, b) => String(a.text).localeCompare(String(b.text)));
  assert.strictEqual(result.elementCounts.text, 0);
  assert.strictEqual(result.elementCounts.templateComponent, 2);
  assert.strictEqual(wetItems[0].text, '6#');
  assert.strictEqual(wetItems[0].dataWen, 24.8);
  assert.strictEqual(wetItems[0].dataWet, 51.6);
  assert.strictEqual(wetItems[1].text, '7#');
  assert.strictEqual(wetItems[1].dataWen, 26.1);
  assert.strictEqual(wetItems[1].dataWet, 49.3);
  assert.strictEqual(result.validation.status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testGenerateImagePackageDetectsLeakWaterFromOcr() {
  const outputDir = path.resolve(repoRoot, 'tmp-detect-leak-water-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = generateImagePackage({
    imagePath: samplePng,
    pageName: 'detect-leak-water-page',
    pageIndex: '13',
    outputDir,
    timestamp: 1770000000013,
    templateZip: sampleZip,
    includeTemplateElements: false,
    detectShapes: false,
    detectBusinessComponents: true,
    ocrItems: [
      { text: 'leakWater', x: 820, y: 520, width: 86, height: 24 },
    ],
  });
  const stage = readStageFromGeneratedZip(result.zipPath);
  const leakElement = stage.children[0].children.find((item) => (
    item.attrs
    && item.attrs.moduleJson
    && item.attrs.moduleJson.children[0].className === 'leakWater'
  ));
  const leak = leakElement.attrs.moduleJson.children[0];
  assert.strictEqual(result.elementCounts.text, 0);
  assert.strictEqual(result.elementCounts.templateComponent, 1);
  assert.strictEqual(leakElement.attrs.x, 820);
  assert.strictEqual(leakElement.attrs.y, 520);
  assert.strictEqual(leak.className, 'leakWater');
  assert.strictEqual(result.validation.status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testMergeRecognitionOptions() {
  const merged = mergeRecognitionOptions({
    ocrItems: [{ text: 'manual', x: 1, y: 2, width: 3, height: 4 }],
    chartElements: [{ cat: 'line', x: 10, y: 20, width: 300, height: 160 }],
    detectWetHtml: false,
    detectCharts: false,
  }, {
    ocrItems: [{ text: '6#', x: 320, y: 420, width: 24, height: 18 }],
    rects: [{ x: 1, y: 1, width: 10, height: 10 }],
    detectWetHtml: true,
    detectCharts: true,
  });
  assert.strictEqual(merged.ocrItems.length, 2);
  assert.strictEqual(merged.chartElements.length, 1);
  assert.strictEqual(merged.rects.length, 1);
  assert.strictEqual(merged.detectWetHtml, false);
  assert.strictEqual(merged.detectCharts, false);
}

function testGenerateImagePackageWithRecognitionOptions() {
  const outputDir = path.resolve(repoRoot, 'tmp-recognition-package-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const options = mergeRecognitionOptions({
    imagePath: samplePng,
    pageName: 'recognition-page',
    pageIndex: '10',
    outputDir,
    timestamp: 1770000000010,
    templateZip: sampleZip,
    includeTemplateElements: false,
    detectShapes: false,
  }, {
    detectWetHtml: true,
    chartElements: [{ cat: 'bar', x: 600, y: 200, width: 320, height: 180, title: 'Load' }],
    ocrItems: [
      { text: '6#', x: 320, y: 420, width: 24, height: 18 },
      { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
      { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
    ],
  });
  const result = generateImagePackage(options);
  const stage = readStageFromGeneratedZip(result.zipPath);
  const children = stage.children[0].children;
  assert.strictEqual(result.elementCounts.templateComponent, 1);
  assert.strictEqual(result.elementCounts.chart, 1);
  assert.strictEqual(result.elementCounts.text, 0);
  assert.ok(children.some((item) => (
    item.attrs
    && item.attrs.moduleJson
    && item.attrs.moduleJson.children[0].className === 'wetHtml'
  )));
  assert.ok(children.some((item) => (
    item.attrs
    && item.attrs.moduleJson
    && item.attrs.moduleJson.children[0].className === 'Echart'
  )));
  assert.strictEqual(result.validation.status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testCreateImageRecognition() {
  const outputDir = path.resolve(repoRoot, 'tmp-recognizer-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const imagePath = path.join(outputDir, 'recognizer-bar-chart.png');
  fs.writeFileSync(imagePath, makeTestPng(120, 80, (x, y) => {
    const inBar = (
      (x >= 20 && x <= 29 && y >= 35 && y <= 65)
      || (x >= 42 && x <= 51 && y >= 20 && y <= 65)
      || (x >= 64 && x <= 73 && y >= 28 && y <= 65)
      || (x >= 86 && x <= 95 && y >= 12 && y <= 65)
    );
    return inBar ? [0, 255, 255, 255] : [0, 0, 20, 255];
  }));
  const recognition = createImageRecognition({
    imagePath,
    detectWetHtml: true,
    detectCharts: true,
    chartDetection: { colorThreshold: 120 },
    ocrItems: [
      { text: '6#', x: 20, y: 30, width: 16, height: 10 },
      { text: '24.8', x: 42, y: 30, width: 24, height: 10 },
      { text: '51.6', x: 42, y: 48, width: 24, height: 10 },
    ],
  });
  assert.strictEqual(recognition.sourceSize.width, 120);
  assert.strictEqual(recognition.targetSize.width, 1920);
  assert.strictEqual(recognition.detectWetHtml, true);
  assert.strictEqual(recognition.ocrItems.length, 3);
  assert.strictEqual(recognition.chartElements.length, 1);
  assert.strictEqual(recognition.chartElements[0].cat, 'bar');
  assert.strictEqual(recognition.chartElements[0].x, 192);
  assert.strictEqual(recognition.chartElements[0].y, 54);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testCreateImageRecognitionFromTemplateComponents() {
  const recognition = createImageRecognition({
    imagePath: samplePng,
    templateZip: sampleZip,
    recognizeTemplateComponents: true,
    templateComponentClasses: ['wetHtml', 'leakWater'],
  });
  const classes = recognition.templateComponents.map((item) => item.selector.className);
  assert.ok(classes.filter((item) => item === 'wetHtml').length > 10);
  assert.ok(classes.includes('leakWater'));
  assert.strictEqual(recognition.ocrItems.length, 0);
}

function testRunImagePackagePipeline() {
  const outputDir = path.resolve(repoRoot, 'tmp-pipeline-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'pipeline-page',
    pageIndex: '11',
    outputDir,
    timestamp: 1770000000011,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
    ocrItems: [
      { text: '6#', x: 320, y: 420, width: 24, height: 18 },
      { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
      { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
    ],
  });
  assert.ok(fs.existsSync(result.recognitionPath));
  assert.ok(fs.existsSync(result.manifestPath));
  assert.ok(fs.existsSync(result.package.zipPath));
  assert.strictEqual(result.package.validation.status, 'PASS');
  assert.strictEqual(result.package.elementCounts.templateComponent, 1);
  assert.strictEqual(result.package.elementCounts.text, 0);
  const recognition = JSON.parse(fs.readFileSync(result.recognitionPath, 'utf8'));
  const manifest = JSON.parse(fs.readFileSync(result.manifestPath, 'utf8'));
  assert.strictEqual(recognition.detectWetHtml, true);
  assert.strictEqual(recognition.ocrItems.length, 3);
  assert.strictEqual(manifest.status, 'PASS');
  assert.strictEqual(manifest.zipPath, result.package.zipPath);
  assert.strictEqual(manifest.elementCounts.templateComponent, 1);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testRunImagePackagePipelineFromTemplateRecognition() {
  const outputDir = path.resolve(repoRoot, 'tmp-template-recognition-pipeline-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'template-recognition-page',
    pageIndex: '18',
    outputDir,
    timestamp: 1770000000018,
    includeTemplateElements: false,
    detectShapes: false,
    recognizeTemplateComponents: true,
    templateComponentClasses: ['wetHtml', 'leakWater'],
  });
  const recognition = JSON.parse(fs.readFileSync(result.recognitionPath, 'utf8'));
  assert.strictEqual(result.package.validation.status, 'PASS');
  assert.ok(recognition.templateComponents.length > 10);
  assert.ok(result.package.elementCounts.templateComponent > 10);
  assert.strictEqual(result.package.elementCounts.text, 0);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testRunImagePackagePipelineDetectsBusinessComponents() {
  const outputDir = path.resolve(repoRoot, 'tmp-business-pipeline-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'business-pipeline-page',
    pageIndex: '14',
    outputDir,
    timestamp: 1770000000014,
    includeTemplateElements: false,
    detectShapes: false,
    detectBusinessComponents: true,
    ocrItems: [
      { text: '6#', x: 320, y: 420, width: 24, height: 18 },
      { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
      { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
      { text: 'leakWater', x: 820, y: 520, width: 86, height: 24 },
    ],
  });
  const stage = readStageFromGeneratedZip(result.package.zipPath);
  const componentNames = stage.children[0].children
    .filter((item) => item.attrs && item.attrs.moduleJson)
    .map((item) => item.attrs.moduleJson.children[0].className);
  const recognition = JSON.parse(fs.readFileSync(result.recognitionPath, 'utf8'));
  assert.strictEqual(recognition.detectBusinessComponents, true);
  assert.strictEqual(result.package.validation.status, 'PASS');
  assert.strictEqual(result.package.elementCounts.templateComponent, 2);
  assert.strictEqual(result.package.elementCounts.text, 0);
  assert.ok(componentNames.includes('wetHtml'));
  assert.ok(componentNames.includes('leakWater'));
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testCliOptionsParseRepeatedOverrides() {
  const args = parseArgs([
    '--chart', 'bar,100,120,300,180,Load',
    '--chart', 'line,500,120,320,180,Trend',
    '--component', 'wetHtml,20,30,90,55,T1,25.1,50.2',
  ]);
  const charts = parseRepeated(args, 'chart', parseChart);
  const components = parseRepeated(args, 'component', parseTemplateComponent);
  assert.strictEqual(charts.length, 2);
  assert.strictEqual(charts[0].cat, 'bar');
  assert.strictEqual(charts[1].title, 'Trend');
  assert.strictEqual(components.length, 1);
  assert.strictEqual(components[0].selector.className, 'wetHtml');
  assert.strictEqual(components[0].attrs.dataWet, 50.2);
}

function testCliOptionsMergeConfigArgs() {
  const config = {
    image: 'from-config.png',
    name: 'from-config',
    index: '7',
    chart: ['bar,100,120,300,180,Config'],
    'detect-business-components': 'true',
  };
  const args = parseArgs([
    '--name', 'from-cli',
    '--chart', 'line,500,120,320,180,CLI',
  ]);
  const merged = mergeConfigArgs(config, args);
  const charts = parseRepeated(merged, 'chart', parseChart);
  assert.strictEqual(merged.image, 'from-config.png');
  assert.strictEqual(merged.name, 'from-cli');
  assert.strictEqual(merged.index, '7');
  assert.strictEqual(merged['detect-business-components'], 'true');
  assert.strictEqual(charts.length, 2);
  assert.strictEqual(charts[0].title, 'Config');
  assert.strictEqual(charts[1].title, 'CLI');
}

function testReadJsonObject() {
  const outputDir = path.resolve(repoRoot, 'tmp-cli-config-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const configPath = path.join(outputDir, 'pipeline.json');
  fs.writeFileSync(configPath, JSON.stringify({ name: 'config-page' }), 'utf8');
  const config = readJsonObject(configPath);
  assert.strictEqual(config.name, 'config-page');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testResolveConfigPathsRelativeToConfigFile() {
  const outputDir = path.resolve(repoRoot, 'tmp-config-path-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(path.join(outputDir, 'inputs'), { recursive: true });
  const imagePath = path.join(outputDir, 'inputs', 'source.png');
  const ocrPath = path.join(outputDir, 'inputs', 'ocr.json');
  fs.writeFileSync(imagePath, Buffer.from([1, 2, 3]));
  fs.writeFileSync(ocrPath, '[]', 'utf8');
  const configPath = path.join(outputDir, 'pipeline.json');
  fs.writeFileSync(configPath, JSON.stringify({
    image: 'inputs/source.png',
    ocr: 'inputs/ocr.json',
    jobs: [{ name: 'relative-job', ocr: 'inputs/ocr.json' }],
  }), 'utf8');
  const config = readJsonObject(configPath);
  const resolved = resolveConfigPaths(config);
  assert.strictEqual(resolved.image, imagePath);
  assert.strictEqual(resolved.ocr, ocrPath);
  assert.strictEqual(resolved.jobs[0].ocr, ocrPath);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testValidatePipelineArgs() {
  const errors = validatePipelineArgs({
    image: 'missing.png',
    name: '',
    'template-zip': 'missing.zip',
    ocr: 'missing.json',
  });
  assert.ok(errors.some((error) => error.includes('image')));
  assert.ok(errors.some((error) => error.includes('name')));
  assert.ok(errors.some((error) => error.includes('template-zip')));
  assert.ok(errors.some((error) => error.includes('ocr')));
}

function testValidatePipelineArgsForBatchJobs() {
  const errors = validatePipelineArgs({
    image: samplePng,
    'template-zip': sampleZip,
    jobs: [
      { name: 'ok-page', index: '1', ocr: 'missing.json' },
      { index: '2' },
    ],
  });
  assert.ok(errors.some((error) => error.includes('jobs[0].ocr')));
  assert.ok(errors.some((error) => error.includes('jobs[1].name')));
}

function testRunImagePackagePipelineWithManualChartOverride() {
  const outputDir = path.resolve(repoRoot, 'tmp-pipeline-chart-override-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = runImagePackagePipeline({
    imagePath: samplePng,
    templateZip: sampleZip,
    pageName: 'pipeline-chart-override-page',
    pageIndex: '15',
    outputDir,
    timestamp: 1770000000015,
    includeTemplateElements: false,
    detectShapes: false,
    chartElements: [{ cat: 'bar', x: 600, y: 200, width: 320, height: 180, title: 'Load' }],
  });
  assert.strictEqual(result.package.validation.status, 'PASS');
  assert.strictEqual(result.package.elementCounts.chart, 1);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testRunImagePackageBatch() {
  const outputDir = path.resolve(repoRoot, 'tmp-pipeline-batch-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  const result = runImagePackageBatch({
    imagePath: samplePng,
    templateZip: sampleZip,
    outputDir,
    includeTemplateElements: false,
    detectShapes: false,
    detectWetHtml: true,
  }, [
    {
      pageName: 'batch-page-a',
      pageIndex: '16',
      timestamp: 1770000000016,
      ocrItems: [
        { text: '6#', x: 320, y: 420, width: 24, height: 18 },
        { text: '24.8', x: 362, y: 417, width: 42, height: 18 },
        { text: '51.6', x: 362, y: 445, width: 42, height: 18 },
      ],
    },
    {
      pageName: 'batch-page-b',
      pageIndex: '17',
      timestamp: 1770000000017,
      chartElements: [{ cat: 'bar', x: 600, y: 200, width: 320, height: 180, title: 'Load' }],
      ocrItems: [
        { text: '7#', x: 390, y: 420, width: 24, height: 18 },
        { text: '26.1', x: 432, y: 417, width: 42, height: 18 },
        { text: '49.3', x: 432, y: 445, width: 42, height: 18 },
      ],
    },
  ]);
  assert.strictEqual(result.length, 2);
  assert.ok(fs.existsSync(result[0].package.zipPath));
  assert.ok(fs.existsSync(result[1].package.zipPath));
  assert.ok(fs.existsSync(result[0].manifestPath));
  assert.ok(fs.existsSync(result[1].manifestPath));
  assert.strictEqual(result[0].package.validation.status, 'PASS');
  assert.strictEqual(result[1].package.validation.status, 'PASS');
  assert.strictEqual(result[0].package.elementCounts.templateComponent, 1);
  assert.strictEqual(result[1].package.elementCounts.chart, 1);
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testAcceptanceWritesLatestReport() {
  const outputDir = path.resolve(repoRoot, 'tmp-acceptance-report-test');
  fs.rmSync(outputDir, { recursive: true, force: true });
  fs.mkdirSync(outputDir, { recursive: true });
  const reportPath = path.join(outputDir, 'image-package-acceptance_123.json');
  const latestPath = path.join(outputDir, 'image-package-acceptance-latest.json');
  const { writeAcceptanceReport } = require('../acceptance');
  const report = {
    status: 'PASS',
    reportPath,
    results: [],
  };
  writeAcceptanceReport(report);
  assert.ok(fs.existsSync(reportPath));
  assert.ok(fs.existsSync(latestPath));
  assert.strictEqual(JSON.parse(fs.readFileSync(latestPath, 'utf8')).status, 'PASS');
  fs.rmSync(outputDir, { recursive: true, force: true });
}

function testAcceptanceFormatsMarkdownReport() {
  const { formatAcceptanceMarkdown } = require('../acceptance');
  const markdown = formatAcceptanceMarkdown({
    status: 'PASS',
    generatedAt: '2026-05-26T00:00:00.000Z',
    results: [{
      label: 'single',
      status: 'PASS',
      zipPath: 'C:/tmp/page.zip',
      components: { wetHtml: 1, Echart: 1 },
    }],
  });
  assert.ok(markdown.includes('# Image Package Acceptance'));
  assert.ok(markdown.includes('PASS'));
  assert.ok(markdown.includes('wetHtml=1'));
  assert.ok(markdown.includes('C:/tmp/page.zip'));
}

function testWetHtmlRendererUsesComponentSize() {
  const conElement = fs.readFileSync(path.resolve(repoRoot, 'src/Page/ConElement.js'), 'utf8');
  const previewElement = fs.readFileSync(path.resolve(repoRoot, 'src/Page/PreviewElement.js'), 'utf8');
  assert.ok(conElement.includes('const wetHtmlStyle'));
  assert.ok(conElement.includes('<div className="numstatus" style={wetHtmlStyle}>'));
  assert.ok(previewElement.includes('const wetHtmlStyle'));
  assert.ok(previewElement.includes('<div className="numstatus" style={wetHtmlStyle}>'));
}

function run() {
  assert.ok(fs.existsSync(samplePng), `Missing sample image: ${samplePng}`);
  testReadPngSize();
  testGetImageInfo();
  testCoordinateMapper();
  testNormalizeOcrItems();
  testShapeCandidates();
  testKonvaFactory();
  testZipWriter();
  testGenerateImagePackage();
  testDetectSimpleShapesFromPng();
  testReadPngRgbaSupportsRealSample();
  testGenerateImagePackageAutoDetectsLines();
  testDetectBarChartRegionFromPng();
  testDetectLineChartRegionFromPng();
  testGenerateImagePackageWithOverlayImage();
  testGenerateImagePackageWithTemplateElements();
  testGenerateImagePackageWithTemplateZipAssets();
  testLoadBasicComponentChartTemplates();
  testGenerateImagePackageWithBasicChartTemplate();
  testGenerateImagePackageWithChartOptions();
  testGenerateImagePackageAutoDetectsCharts();
  testValidateImagePackageReportsMissingImages();
  testExtractTemplateElementsFromPageText();
  testLoadTemplateElementsFromZip();
  testExtractComponentTemplatesFromZip();
  testCreateElementFromTemplateIndex();
  testCreateElementFromTemplateIndexCanResizeWetHtmlWhenExplicit();
  testGenerateImagePackageWithTemplateComponentOptions();
  testGenerateImagePackageDetectsWetHtmlFromOcr();
  testGenerateImagePackageDetectsMultipleWetHtmlFromOcr();
  testGenerateImagePackageDetectsLeakWaterFromOcr();
  testMergeRecognitionOptions();
  testGenerateImagePackageWithRecognitionOptions();
  testCreateImageRecognition();
  testCreateImageRecognitionFromTemplateComponents();
  testRunImagePackagePipeline();
  testRunImagePackagePipelineFromTemplateRecognition();
  testRunImagePackagePipelineDetectsBusinessComponents();
  testCliOptionsParseRepeatedOverrides();
  testCliOptionsMergeConfigArgs();
  testReadJsonObject();
  testResolveConfigPathsRelativeToConfigFile();
  testValidatePipelineArgs();
  testValidatePipelineArgsForBatchJobs();
  testRunImagePackagePipelineWithManualChartOverride();
  testRunImagePackageBatch();
  testAcceptanceWritesLatestReport();
  testAcceptanceFormatsMarkdownReport();
  testWetHtmlRendererUsesComponentSize();
  console.log('image-package tests passed');
}

run();
