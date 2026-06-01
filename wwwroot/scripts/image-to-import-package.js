#!/usr/bin/env node

const { generateImagePackage } = require('./image-package/generator');
const {
  hasFlag,
  parseArgs,
  parseChart,
  parseOverlay,
  parseRepeated,
  parseTemplateComponent,
  readJsonArray,
} = require('./image-package/cliOptions');
const {
  mergeRecognitionOptions,
  readJsonObject,
} = require('./image-package/recognitionConfig');

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!args.image || !args.name) {
    console.error('Usage: node scripts/image-to-import-package.js --image <png> --name <page-name> --index <page-index> [--template-zip page.zip] [--recognition recognition.json] [--component class,x,y,width,height,text,dataWen,dataWet] [--template-components-only] [--detect-wet-html] [--detect-charts] [--chart cat,x,y,width,height,title] [--overlay overlay.png] [--ocr ocr.json] [--rects rects.json] [--lines lines.json] [--out output-dir]');
    process.exit(1);
  }
  const overlayImages = parseRepeated(args, 'overlay', parseOverlay);
  const chartElements = parseRepeated(args, 'chart', parseChart);
  const templateComponents = parseRepeated(args, 'component', parseTemplateComponent);

  const generationOptions = mergeRecognitionOptions({
    imagePath: args.image,
    pageName: args.name,
    pageIndex: args.index || 1,
    outputDir: args.out,
    ocrItems: readJsonArray(args.ocr),
    rects: readJsonArray(args.rects),
    lines: readJsonArray(args.lines),
    overlayImages,
    chartElements,
    templateComponents,
    templateZip: args['template-zip'],
    includeTemplateElements: args['template-components-only'] === 'true' ? false : undefined,
    detectShapes: hasFlag(args, 'no-detect-shapes') ? args['no-detect-shapes'] !== 'true' : undefined,
    detectCharts: hasFlag(args, 'detect-charts') ? args['detect-charts'] === 'true' : undefined,
    detectWetHtml: (
      hasFlag(args, 'detect-wet-html')
      || hasFlag(args, 'detect-business-components')
    ) ? args['detect-wet-html'] === 'true' || args['detect-business-components'] === 'true' : undefined,
  }, readJsonObject(args.recognition));

  const result = generateImagePackage(generationOptions);

  console.log(JSON.stringify(result, null, 2));
}

main();
