const { getImageInfo } = require('./imageInfo');
const { createCoordinateMapper, normalizeOcrItems } = require('./detection');
const { detectChartRegionsFromPng } = require('./chartDetector');
const { loadTemplateElementsFromZip } = require('./templateImporter');

function getModuleChild(element) {
  return element
    && element.attrs
    && element.attrs.moduleJson
    && Array.isArray(element.attrs.moduleJson.children)
    && element.attrs.moduleJson.children[0];
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function createTemplateComponentsFromZip(zipPath, classNames) {
  if (!zipPath) return [];
  const allowed = new Set(Array.isArray(classNames) && classNames.length > 0
    ? classNames
    : ['wetHtml', 'leakWater']);
  return loadTemplateElementsFromZip(zipPath).elements
    .map((element) => {
      const child = getModuleChild(element);
      if (!child || !allowed.has(child.className)) return null;
      return {
        selector: { className: child.className },
        x: Number(element.attrs.x || 0),
        y: Number(element.attrs.y || 0),
        width: Number(element.attrs.width || child.attrs.width || 50),
        height: Number(element.attrs.height || child.attrs.height || 50),
        attrs: clone(child.attrs || {}),
      };
    })
    .filter(Boolean);
}

function createImageRecognition(options) {
  const imageInfo = getImageInfo(options.imagePath);
  const mapper = createCoordinateMapper(imageInfo, { width: 1920, height: 1080 });
  const ocrItems = normalizeOcrItems(options.ocrItems);
  const detectedCharts = options.detectCharts
    ? detectChartRegionsFromPng(imageInfo.path, options.chartDetection || {}).charts
    : [];
  const chartElements = detectedCharts.map((item) => ({
    ...item,
    ...mapper.box(item),
  }));
  const templateComponents = options.recognizeTemplateComponents
    ? createTemplateComponentsFromZip(options.templateZip, options.templateComponentClasses)
    : [];

  return {
    sourceImage: imageInfo.path,
    sourceSize: {
      width: imageInfo.width,
      height: imageInfo.height,
    },
    targetSize: {
      width: 1920,
      height: 1080,
    },
    detectWetHtml: Boolean(options.detectWetHtml || options.detectBusinessComponents),
    detectBusinessComponents: Boolean(options.detectBusinessComponents),
    ocrItems,
    chartElements,
    templateComponents,
  };
}

module.exports = {
  createImageRecognition,
  createTemplateComponentsFromZip,
};
