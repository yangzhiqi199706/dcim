const fs = require('fs');
const path = require('path');

const { generateImagePackage } = require('./generator');
const { createImageRecognition } = require('./recognizer');
const { mergeRecognitionOptions } = require('./recognitionConfig');

function ensureDirectory(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function createManifest(result) {
  return {
    status: result.package.validation.status,
    recognitionPath: result.recognitionPath,
    zipPath: result.package.zipPath,
    pageTxtName: result.package.pageTxtName,
    imageFileName: result.package.imageFileName,
    elementCounts: result.package.elementCounts,
    validation: result.package.validation,
  };
}

function runImagePackagePipeline(options) {
  const outputDir = path.resolve(options.outputDir || path.join(__dirname, '../../public/Images/exports'));
  ensureDirectory(outputDir);

  const recognition = createImageRecognition({
    imagePath: options.imagePath,
    ocrItems: options.ocrItems,
    detectWetHtml: options.detectWetHtml,
    detectBusinessComponents: options.detectBusinessComponents,
    detectCharts: options.detectCharts,
    chartDetection: options.chartDetection,
    templateZip: options.templateZip,
    recognizeTemplateComponents: options.recognizeTemplateComponents,
    templateComponentClasses: options.templateComponentClasses,
  });
  const pageName = options.pageName || 'generated_page';
  const timestamp = options.timestamp || Date.now();
  const recognitionPath = path.join(outputDir, `${pageName}_${timestamp}.recognition.json`);
  fs.writeFileSync(recognitionPath, `${JSON.stringify(recognition, null, 2)}\n`, 'utf8');

  const {
    ocrItems,
    detectWetHtml,
    detectBusinessComponents,
    detectCharts,
    chartDetection,
    recognizeTemplateComponents,
    templateComponentClasses,
    ...generationOptions
  } = options;
  const packageOptions = mergeRecognitionOptions({
    ...generationOptions,
    outputDir,
  }, recognition);
  const packageResult = generateImagePackage(packageOptions);
  const manifestPath = path.join(outputDir, `${pageName}_${timestamp}.manifest.json`);
  const result = {
    recognitionPath,
    manifestPath,
    package: packageResult,
  };
  fs.writeFileSync(manifestPath, `${JSON.stringify(createManifest(result), null, 2)}\n`, 'utf8');

  return result;
}

function runImagePackageBatch(defaultOptions, jobs) {
  return (Array.isArray(jobs) ? jobs : []).map((job) => runImagePackagePipeline({
    ...(defaultOptions || {}),
    ...(job || {}),
  }));
}

module.exports = {
  runImagePackageBatch,
  runImagePackagePipeline,
};
