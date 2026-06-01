const fs = require('fs');
const path = require('path');

function readJsonObject(filePath) {
  if (!filePath) return {};
  const absPath = path.resolve(filePath);
  if (!fs.existsSync(absPath)) return {};
  const parsed = JSON.parse(fs.readFileSync(absPath, 'utf8'));
  return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
}

function arrayFrom(value) {
  return Array.isArray(value) ? value : [];
}

function boolFrom(value, fallback) {
  return typeof value === 'boolean' ? value : fallback;
}

function mergeArrayOption(baseValue, recognitionValue) {
  return [
    ...arrayFrom(baseValue),
    ...arrayFrom(recognitionValue),
  ];
}

function mergeRecognitionOptions(baseOptions, recognition) {
  const source = recognition && typeof recognition === 'object' ? recognition : {};
  return {
    ...source,
    ...baseOptions,
    ocrItems: mergeArrayOption(baseOptions.ocrItems, source.ocrItems),
    rects: mergeArrayOption(baseOptions.rects, source.rects),
    lines: mergeArrayOption(baseOptions.lines, source.lines),
    overlayImages: mergeArrayOption(baseOptions.overlayImages, source.overlayImages),
    chartElements: mergeArrayOption(baseOptions.chartElements, source.chartElements),
    templateComponents: mergeArrayOption(baseOptions.templateComponents, source.templateComponents),
    detectShapes: boolFrom(baseOptions.detectShapes, source.detectShapes),
    detectCharts: boolFrom(baseOptions.detectCharts, source.detectCharts),
    detectWetHtml: boolFrom(baseOptions.detectWetHtml, source.detectWetHtml),
    detectBusinessComponents: boolFrom(
      baseOptions.detectBusinessComponents,
      source.detectBusinessComponents,
    ),
  };
}

module.exports = {
  mergeRecognitionOptions,
  readJsonObject,
};
