const path = require('path');

const { parseZipEntries } = require('./templateImporter');

function parseStageFromPageText(pageText) {
  const first = JSON.parse(String(pageText || ''));
  return typeof first === 'string' ? JSON.parse(first) : first;
}

function walk(value, visitor) {
  visitor(value);
  if (Array.isArray(value)) {
    value.forEach((item) => walk(item, visitor));
  } else if (value && typeof value === 'object') {
    Object.keys(value).forEach((key) => walk(value[key], visitor));
  }
}

function normalizeImageRef(value) {
  if (typeof value !== 'string') return '';
  const normalized = value.replace(/\\/g, '/');
  const match = normalized.match(/(?:^|\/)Images\/uploads\/([^?#]+)/);
  if (!match) return '';
  return `img/uploads/${match[1]}`;
}

function collectReferencedImages(stage) {
  const refs = new Set();
  walk(stage, (value) => {
    const ref = normalizeImageRef(value);
    if (ref) refs.add(ref);
  });
  return [...refs].sort();
}

function validateStage(stage, errors, warnings) {
  if (!stage || stage.className !== 'Stage') {
    errors.push('txt stage className must be Stage');
    return;
  }
  if (!stage.attrs || stage.attrs.width !== 1920 || stage.attrs.height !== 1080) {
    warnings.push('stage size is not 1920x1080');
  }
  const layer = stage.children && stage.children[0];
  if (!layer || !Array.isArray(layer.children)) {
    errors.push('stage missing layer children');
    return;
  }
  const background = layer.children[0];
  if (!background || !background.attrs || background.attrs.id !== 'canvasBackground') {
    errors.push('layer first child must be canvasBackground');
  }

  const ids = new Set();
  layer.children.slice(1).forEach((node, index) => {
    if (!node || node.className !== 'Group') {
      warnings.push(`element ${index + 1} is not Group`);
      return;
    }
    const id = node.attrs && node.attrs.id;
    if (!id) {
      errors.push(`element ${index + 1} missing id`);
    } else if (ids.has(id)) {
      errors.push(`duplicate element id: ${id}`);
    } else {
      ids.add(id);
    }
    const child = node.attrs
      && node.attrs.moduleJson
      && Array.isArray(node.attrs.moduleJson.children)
      && node.attrs.moduleJson.children[0];
    if (!child || !child.className) {
      errors.push(`element ${index + 1} missing module child`);
    }
    if (child && child.className === 'Echart' && !(child.attrs && child.attrs.cat)) {
      errors.push(`echart element ${index + 1} missing cat`);
    }
  });
}

function validateImagePackage(zipBuffer) {
  const errors = [];
  const warnings = [];
  const entries = parseZipEntries(zipBuffer);
  const names = entries.map((entry) => entry.name);
  const rootTxtEntries = entries.filter((entry) => path.posix.dirname(entry.name) === '.' && path.extname(entry.name).toLowerCase() === '.txt');
  const txtEntry = rootTxtEntries[0] || entries.find((entry) => path.extname(entry.name).toLowerCase() === '.txt');

  if (!txtEntry) errors.push('zip missing txt file');
  if (rootTxtEntries.length > 1) warnings.push('zip contains multiple root txt files');

  const packagedImages = names
    .filter((name) => name.startsWith('img/uploads/'))
    .sort();
  let stage = null;
  let referencedImages = [];

  if (txtEntry) {
    try {
      stage = parseStageFromPageText(txtEntry.data.toString('utf8'));
      validateStage(stage, errors, warnings);
      referencedImages = collectReferencedImages(stage);
    } catch (error) {
      errors.push(`txt parse failed: ${error.message}`);
    }
  }

  const packagedSet = new Set(packagedImages);
  const referencedSet = new Set(referencedImages);
  const missingImages = referencedImages.filter((name) => !packagedSet.has(name));
  const unusedImages = packagedImages.filter((name) => !referencedSet.has(name));
  missingImages.forEach((name) => errors.push(`missing image: ${name}`));
  unusedImages.forEach((name) => warnings.push(`unused image: ${name}`));

  return {
    status: errors.length ? 'FAIL' : (warnings.length ? 'WARN' : 'PASS'),
    txtName: txtEntry ? txtEntry.name : '',
    stageSize: stage && stage.attrs ? { width: stage.attrs.width, height: stage.attrs.height } : null,
    referencedImages,
    packagedImages,
    missingImages,
    unusedImages,
    warnings,
    errors,
  };
}

module.exports = {
  collectReferencedImages,
  validateImagePackage,
};
