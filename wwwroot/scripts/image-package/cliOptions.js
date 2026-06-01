const fs = require('fs');
const path = require('path');

function parseArgs(argv) {
  const result = {};
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    if (!arg.startsWith('--')) continue;
    const key = arg.slice(2);
    const value = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : 'true';
    if (key === 'overlay' || key === 'chart' || key === 'component') {
      if (!Array.isArray(result[key])) result[key] = [];
      result[key].push(value);
    } else {
      result[key] = value;
    }
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

function readJsonObject(filePath, fallback = {}) {
  if (!filePath) return fallback;
  const absPath = path.resolve(filePath);
  if (!fs.existsSync(absPath)) return fallback;
  const parsed = JSON.parse(fs.readFileSync(absPath, 'utf8'));
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return fallback;
  Object.defineProperty(parsed, '__configDir', {
    enumerable: false,
    value: path.dirname(absPath),
  });
  return parsed;
}

function parseOverlay(value) {
  const parts = String(value || '').split(',');
  return {
    path: parts[0],
    x: Number(parts[1] || 0),
    y: Number(parts[2] || 0),
    width: Number(parts[3] || 1920),
    height: Number(parts[4] || 1080),
  };
}

function parseChart(value) {
  const parts = String(value || '').split(',');
  return {
    cat: parts[0],
    x: Number(parts[1] || 0),
    y: Number(parts[2] || 0),
    width: Number(parts[3] || 350),
    height: Number(parts[4] || 250),
    title: parts[5] || '',
  };
}

function parseTemplateComponent(value) {
  const parts = String(value || '').split(',');
  const className = parts[0];
  const attrs = {};
  if (parts[5]) attrs.text = parts[5];
  if (parts[6]) attrs.dataWen = Number(parts[6]);
  if (parts[7]) attrs.dataWet = Number(parts[7]);
  return {
    selector: { className },
    x: Number(parts[1] || 0),
    y: Number(parts[2] || 0),
    width: Number(parts[3] || 50),
    height: Number(parts[4] || 50),
    attrs,
  };
}

function hasFlag(args, key) {
  return Object.prototype.hasOwnProperty.call(args, key);
}

function parseRepeated(args, key, parser) {
  return (Array.isArray(args[key]) ? args[key] : []).map(parser);
}

function normalizeRepeated(value) {
  if (Array.isArray(value)) return value;
  return value == null ? [] : [value];
}

function mergeConfigArgs(config, args) {
  const merged = {
    ...(config && typeof config === 'object' ? config : {}),
    ...(args && typeof args === 'object' ? args : {}),
  };
  ['overlay', 'chart', 'component'].forEach((key) => {
    const values = [
      ...normalizeRepeated(config && config[key]),
      ...normalizeRepeated(args && args[key]),
    ];
    if (values.length > 0) merged[key] = values;
  });
  return merged;
}

function resolvePathValue(value, configDir) {
  if (!value || !configDir || path.isAbsolute(value)) return value;
  const cwdPath = path.resolve(value);
  if (fs.existsSync(cwdPath)) return cwdPath;
  return path.resolve(configDir, value);
}

function resolveOverlayValue(value, configDir) {
  const parts = String(value || '').split(',');
  if (!parts[0]) return value;
  return [resolvePathValue(parts[0], configDir), ...parts.slice(1)].join(',');
}

function resolveConfigPaths(args) {
  const configDir = args && args.__configDir;
  if (!configDir) return args;
  const resolved = { ...args };
  ['image', 'ocr', 'template-zip'].forEach((key) => {
    if (resolved[key]) resolved[key] = resolvePathValue(resolved[key], configDir);
  });
  if (resolved.overlay) {
    resolved.overlay = normalizeRepeated(resolved.overlay)
      .map((value) => resolveOverlayValue(value, configDir));
  }
  if (Array.isArray(resolved.jobs)) {
    resolved.jobs = resolved.jobs.map((job) => resolveConfigPaths(Object.defineProperty({
      ...(job || {}),
    }, '__configDir', {
      enumerable: false,
      value: configDir,
    })));
  }
  Object.defineProperty(resolved, '__configDir', {
    enumerable: false,
    value: configDir,
  });
  return resolved;
}

function pathExists(value) {
  return value && fs.existsSync(path.resolve(value));
}

function addPathError(errors, args, key, label) {
  if (args[key] && !pathExists(args[key])) {
    errors.push(`${label || key} not found: ${args[key]}`);
  }
}

function validateSinglePipelineArgs(args, label = '') {
  const prefix = label ? `${label}.` : '';
  const errors = [];
  if (!args.image) {
    errors.push(`${prefix}image is required`);
  } else if (!pathExists(args.image)) {
    errors.push(`${prefix}image not found: ${args.image}`);
  }
  if (!args.name) errors.push(`${prefix}name is required`);
  if (args['recognize-template-components'] === 'true' && !args['template-zip']) {
    errors.push(`${prefix}template-zip is required when recognize-template-components is true`);
  }
  addPathError(errors, args, 'template-zip', `${prefix}template-zip`);
  addPathError(errors, args, 'ocr', `${prefix}ocr`);
  return errors;
}

function validatePipelineArgs(args) {
  const baseErrors = validateSinglePipelineArgs(args);
  if (!Array.isArray(args.jobs)) return baseErrors;
  const errors = baseErrors.filter((error) => !error.includes('name is required'));
  args.jobs.forEach((job, index) => {
    errors.push(...validateSinglePipelineArgs(mergeConfigArgs(args, job || {}), `jobs[${index}]`));
  });
  return errors;
}

module.exports = {
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
};
