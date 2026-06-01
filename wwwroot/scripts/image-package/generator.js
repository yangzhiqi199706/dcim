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
  createImageElement,
} = require('./konvaFactory');
const { createZipBuffer } = require('./zipWriter');
const { detectSimpleShapesFromPng } = require('./shapeDetector');
const {
  buildComponentTemplateIndex,
  createElementFromTemplateIndex,
  loadTemplateElementsFromZip,
} = require('./templateImporter');
const { createBasicChartElement } = require('./componentTemplateLibrary');
const { detectChartRegionsFromPng } = require('./chartDetector');
const { validateImagePackage } = require('./validator');
const {
  detectBusinessComponentsFromOcr,
  detectWetHtmlComponentsFromOcr,
} = require('./businessComponentDetector');

function sanitizeName(name, fallback = 'page') {
  const src = String(name || '').trim();
  const clean = path.basename(src)
    .replace(/[\\/:*?"<>|]/g, '_')
    .replace(/\s+/g, ' ')
    .replace(/^\.+|\.+$/g, '')
    .trim();
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

function normalizeOverlayImages(rawItems) {
  if (!Array.isArray(rawItems)) return [];
  return rawItems
    .map((item) => {
      const imagePath = item.path || item.imagePath;
      if (!imagePath) return null;
      return {
        path: path.resolve(imagePath),
        x: Number(item.x || 0),
        y: Number(item.y || 0),
        width: Number(item.width || 1920),
        height: Number(item.height || 1080),
      };
    })
    .filter((item) => item && fs.existsSync(item.path));
}

function loadTemplatePackage(options) {
  if (Array.isArray(options.templateElements)) {
    return {
      elements: JSON.parse(JSON.stringify(options.templateElements)),
      assets: [],
    };
  }
  if (options.templateZip) {
    const loaded = loadTemplateElementsFromZip(path.resolve(options.templateZip));
    const includeTemplateElements = options.includeTemplateElements !== false;
    return {
      ...loaded,
      elements: includeTemplateElements ? loaded.elements : [],
      assets: includeTemplateElements ? loaded.assets : [],
      index: buildComponentTemplateIndex(loaded.elements),
    };
  }
  return { elements: [], assets: [], index: buildComponentTemplateIndex([]) };
}

function createChartElements(rawItems) {
  if (!Array.isArray(rawItems)) return [];
  return rawItems.map((item) => createBasicChartElement(item.cat, item));
}

function createTemplateComponentElements(templatePackage, rawItems) {
  if (!Array.isArray(rawItems) || !templatePackage || !templatePackage.index) return [];
  return rawItems.map((item) => {
    const { sourceOcrItems, ...overrides } = item;
    return createElementFromTemplateIndex(
      templatePackage.index,
      item.selector,
      overrides,
    );
  });
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

  const shouldDetectShapes = options.detectShapes !== false && (!Array.isArray(options.rects) || !Array.isArray(options.lines));
  const detectedShapes = shouldDetectShapes
    ? detectSimpleShapesFromPng(imageInfo.path, options.shapeDetection || {})
    : { rects: [], lines: [] };
  const rawRects = Array.isArray(options.rects) ? options.rects : detectedShapes.rects;
  const rawLines = Array.isArray(options.lines) ? options.lines : detectedShapes.lines;

  const mapper = createCoordinateMapper(imageInfo, { width: 1920, height: 1080 });
  const normalizedOcrItems = normalizeOcrItems(options.ocrItems);
  const mappedOcrItems = normalizedOcrItems.map((item) => ({
    ...mapper.box(item),
    text: item.text,
    confidence: item.confidence,
  }));
  const detectedTemplateComponents = options.detectBusinessComponents
    ? detectBusinessComponentsFromOcr(mappedOcrItems)
    : options.detectWetHtml
      ? detectWetHtmlComponentsFromOcr(mappedOcrItems)
      : [];
  const consumedOcrItems = new Set(detectedTemplateComponents
    .flatMap((item) => item.sourceOcrItems || []));
  const textElements = mappedOcrItems
    .filter((item) => !consumedOcrItems.has(item))
    .map((item) => createTextElement({
      ...item,
      fill: item.fill || '#00ffff',
    }));
  const rectElements = createRectCandidates(rawRects)
    .map((item) => createRectElement(mapRectItem(mapper, item)));
  const lineElements = createLineCandidates(rawLines)
    .map((item) => createLineElement(mapLineItem(mapper, item)));
  const overlayImages = normalizeOverlayImages(options.overlayImages);
  const overlayEntries = overlayImages.map((item, index) => {
    const overlayInfo = getImageInfo(item.path);
    const overlayFileName = `${sanitizeName(path.parse(overlayInfo.path).name, 'overlay')}_${timestamp}_overlay_${index + 1}${overlayInfo.ext}`;
    return {
      fileName: overlayFileName,
      buffer: fs.readFileSync(overlayInfo.path),
      element: createImageElement({
        image: `Images/uploads/${overlayFileName}`,
        x: item.x,
        y: item.y,
        width: item.width,
        height: item.height,
      }),
    };
  });
  const detectedCharts = options.detectCharts
    ? detectChartRegionsFromPng(imageInfo.path, options.chartDetection || {}).charts
    : [];
  const mappedDetectedCharts = detectedCharts.map((item) => ({
    ...item,
    ...mapper.box(item),
  }));
  const rawChartElements = [
    ...(Array.isArray(options.chartElements) ? options.chartElements : []),
    ...mappedDetectedCharts,
  ];
  const templatePackage = loadTemplatePackage(options);
  const templateElements = templatePackage.elements || [];
  const templateAssets = templatePackage.assets || [];
  const rawTemplateComponents = [
    ...(Array.isArray(options.templateComponents) ? options.templateComponents : []),
    ...detectedTemplateComponents,
  ];
  const templateComponentElements = createTemplateComponentElements(templatePackage, rawTemplateComponents);
  const chartElements = createChartElements(rawChartElements);

  const stage = createStage({
    backgroundImage: `../Images/uploads/${imageFileName}`,
    elements: [
      ...overlayEntries.map((item) => item.element),
      ...templateElements,
      ...templateComponentElements,
      ...chartElements,
      ...rectElements,
      ...lineElements,
      ...textElements,
    ],
  });
  const pageText = encodePageText(stage);
  const imageBuffer = fs.readFileSync(imageInfo.path);
  const zipBuffer = createZipBuffer([
    { name: pageTxtName, data: Buffer.from(pageText, 'utf8') },
    { name: 'img/' },
    { name: `img/uploads/${imageFileName}`, data: imageBuffer },
    ...templateAssets.map((item) => ({ name: item.name, data: item.data })),
    ...overlayEntries.map((item) => ({ name: `img/uploads/${item.fileName}`, data: item.buffer })),
  ]);

  const zipPath = path.join(outputDir, `${pageName}_${timestamp}.zip`);
  fs.writeFileSync(zipPath, zipBuffer);
  const validation = validateImagePackage(zipBuffer);

  return {
    zipPath,
    pageTxtName,
    imageFileName,
    validation,
    elementCounts: {
      text: textElements.length,
      rect: rectElements.length,
      line: lineElements.length,
      image: overlayEntries.length,
      template: templateElements.length,
      templateComponent: templateComponentElements.length,
      chart: chartElements.length,
    },
  };
}

module.exports = {
  generateImagePackage,
  sanitizeName,
};
