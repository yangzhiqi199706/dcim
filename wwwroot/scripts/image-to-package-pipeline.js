#!/usr/bin/env node

const {
  hasFlag,
  mergeConfigArgs,
  parseArgs,
  parseChart,
  parseOverlay,
  parseRepeated,
  parseTemplateComponent,
  readJsonArray,
  readJsonObject,
  resolveConfigPaths,
  validatePipelineArgs,
} = require('./image-package/cliOptions');
const {
  runImagePackageBatch,
  runImagePackagePipeline,
} = require('./image-package/pipeline');

function toPipelineOptions(args) {
  return {
    imagePath: args.image,
    pageName: args.name,
    pageIndex: args.index || 1,
    outputDir: args.out,
    templateZip: args['template-zip'],
    includeTemplateElements: args['template-components-only'] === 'true' ? false : undefined,
    detectShapes: hasFlag(args, 'no-detect-shapes') ? args['no-detect-shapes'] !== 'true' : undefined,
    detectWetHtml: args['detect-wet-html'] === 'true' || args['detect-business-components'] === 'true',
    detectBusinessComponents: args['detect-business-components'] === 'true',
    detectCharts: args['detect-charts'] === 'true',
    recognizeTemplateComponents: args['recognize-template-components'] === 'true',
    templateComponentClasses: args['template-component-classes']
      ? String(args['template-component-classes']).split(',').map((item) => item.trim()).filter(Boolean)
      : undefined,
    ocrItems: readJsonArray(args.ocr),
    overlayImages: parseRepeated(args, 'overlay', parseOverlay),
    chartElements: parseRepeated(args, 'chart', parseChart),
    templateComponents: parseRepeated(args, 'component', parseTemplateComponent),
  };
}

function mergeJobArgs(args, job) {
  return mergeConfigArgs(args, job || {});
}

function main() {
  const rawArgs = parseArgs(process.argv.slice(2));
  const config = resolveConfigPaths(readJsonObject(rawArgs.config));
  const args = mergeConfigArgs(config, rawArgs);
  const validationErrors = validatePipelineArgs(args);
  if (validationErrors.length > 0) {
    console.error(`Config validation failed:\n${validationErrors.map((error) => `- ${error}`).join('\n')}`);
    process.exit(1);
  }
  if (Array.isArray(config.jobs)) {
    const defaults = toPipelineOptions(args);
    const jobs = config.jobs.map((job) => toPipelineOptions(mergeJobArgs(args, job)));
    const result = runImagePackageBatch(defaults, jobs);
    console.log(JSON.stringify(result, null, 2));
    return;
  }
  if (!args.image || !args.name) {
    console.error('Usage: node scripts/image-to-package-pipeline.js [--config pipeline.json] --image <png> --name <page-name> --index <page-index> [--template-zip page.zip] [--template-components-only] [--ocr ocr.json] [--detect-wet-html] [--detect-business-components] [--detect-charts] [--recognize-template-components] [--template-component-classes wetHtml,leakWater] [--chart cat,x,y,width,height,title] [--component class,x,y,width,height,text,dataWen,dataWet] [--overlay overlay.png,x,y,width,height] [--out output-dir]');
    process.exit(1);
  }

  const result = runImagePackagePipeline(toPipelineOptions(args));

  console.log(JSON.stringify(result, null, 2));
}

main();
