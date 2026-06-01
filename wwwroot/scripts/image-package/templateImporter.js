const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

function findEndOfCentralDirectoryOffset(zipBuffer) {
  const minOffset = Math.max(0, zipBuffer.length - 22 - 0xffff);
  for (let offset = zipBuffer.length - 22; offset >= minOffset; offset -= 1) {
    if (zipBuffer.readUInt32LE(offset) === 0x06054b50) return offset;
  }
  return -1;
}

function normalizeEntryName(rawName) {
  const normalized = String(rawName || '').replace(/\\/g, '/');
  const clean = path.posix.normalize(normalized).replace(/^\/+/, '');
  if (!clean || clean === '.' || clean.startsWith('..') || clean.includes('/../')) return '';
  return clean;
}

function parseZipEntries(zipBuffer) {
  const eocdOffset = findEndOfCentralDirectoryOffset(zipBuffer);
  if (eocdOffset < 0) throw new Error('invalid zip central directory');
  const centralDirSize = zipBuffer.readUInt32LE(eocdOffset + 12);
  const centralDirOffset = zipBuffer.readUInt32LE(eocdOffset + 16);
  const centralDirEnd = centralDirOffset + centralDirSize;
  const entries = [];
  let offset = centralDirOffset;

  while (offset + 46 <= centralDirEnd) {
    if (zipBuffer.readUInt32LE(offset) !== 0x02014b50) {
      throw new Error('invalid zip central file header');
    }
    const flags = zipBuffer.readUInt16LE(offset + 8);
    const compressionMethod = zipBuffer.readUInt16LE(offset + 10);
    const compressedSize = zipBuffer.readUInt32LE(offset + 20);
    const fileNameLength = zipBuffer.readUInt16LE(offset + 28);
    const extraFieldLength = zipBuffer.readUInt16LE(offset + 30);
    const fileCommentLength = zipBuffer.readUInt16LE(offset + 32);
    const localHeaderOffset = zipBuffer.readUInt32LE(offset + 42);
    const fileNameStart = offset + 46;
    const fileNameEnd = fileNameStart + fileNameLength;
    const useUtf8 = (flags & 0x0800) !== 0;
    const decodedName = zipBuffer.slice(fileNameStart, fileNameEnd).toString(useUtf8 ? 'utf8' : 'latin1');
    const name = normalizeEntryName(decodedName);

    if (name && !name.endsWith('/')) {
      if (zipBuffer.readUInt32LE(localHeaderOffset) !== 0x04034b50) {
        throw new Error('invalid zip local header');
      }
      const localFileNameLength = zipBuffer.readUInt16LE(localHeaderOffset + 26);
      const localExtraFieldLength = zipBuffer.readUInt16LE(localHeaderOffset + 28);
      const dataStart = localHeaderOffset + 30 + localFileNameLength + localExtraFieldLength;
      const dataEnd = dataStart + compressedSize;
      const compressedData = zipBuffer.slice(dataStart, dataEnd);
      let data = Buffer.alloc(0);
      if (compressionMethod === 0) {
        data = Buffer.from(compressedData);
      } else if (compressionMethod === 8) {
        data = zlib.inflateRawSync(compressedData);
      } else {
        throw new Error(`unsupported zip compression method: ${compressionMethod}`);
      }
      entries.push({ name, data });
    }

    offset = fileNameEnd + extraFieldLength + fileCommentLength;
  }

  return entries;
}

function parseStageFromPageText(pageText) {
  const first = JSON.parse(String(pageText || ''));
  return typeof first === 'string' ? JSON.parse(first) : first;
}

function extractTemplateElementsFromPageText(pageText) {
  const stage = parseStageFromPageText(pageText);
  const layer = stage && stage.children && stage.children[0];
  const children = layer && Array.isArray(layer.children) ? layer.children : [];
  return children
    .filter((node) => !(node && node.attrs && node.attrs.id === 'canvasBackground'))
    .map((node) => JSON.parse(JSON.stringify(node)));
}

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function getModuleChild(element) {
  return element
    && element.attrs
    && element.attrs.moduleJson
    && Array.isArray(element.attrs.moduleJson.children)
    && element.attrs.moduleJson.children[0];
}

function indexTemplateElement(index, element) {
  const child = getModuleChild(element);
  if (!child || !child.className) return;
  if (!index.byClass[child.className]) index.byClass[child.className] = [];
  index.byClass[child.className].push(clone(element));

  const childName = child.attrs && child.attrs.name;
  if (childName && !index.byName[childName]) {
    index.byName[childName] = clone(element);
  }

  const cat = child.attrs && child.attrs.cat;
  if (cat) {
    const key = `${child.className}:${cat}`;
    if (!index.byCat[key]) index.byCat[key] = clone(element);
  }
}

function buildComponentTemplateIndex(elements) {
  const index = { byClass: {}, byName: {}, byCat: {} };
  (Array.isArray(elements) ? elements : []).forEach((element) => indexTemplateElement(index, element));
  return index;
}

function extractComponentTemplatesFromZip(zipPath) {
  const loaded = loadTemplateElementsFromZip(zipPath);
  return buildComponentTemplateIndex(loaded.elements);
}

function pickTemplate(index, selector) {
  if (!index || !selector) return null;
  if (selector.name && index.byName[selector.name]) return index.byName[selector.name];
  if (selector.className && selector.cat && index.byCat[`${selector.className}:${selector.cat}`]) {
    return index.byCat[`${selector.className}:${selector.cat}`];
  }
  const items = selector.className && index.byClass[selector.className];
  return items && items[0] ? items[0] : null;
}

function createElementFromTemplateIndex(index, selector, overrides = {}) {
  const template = pickTemplate(index, selector);
  if (!template) throw new Error(`missing component template: ${JSON.stringify(selector)}`);

  const element = clone(template);
  const child = getModuleChild(element);
  const x = Number(overrides.x == null ? element.attrs.x || 0 : overrides.x);
  const y = Number(overrides.y == null ? element.attrs.y || 0 : overrides.y);
  const preservesTemplateSize = child.className === 'wetHtml' && overrides.resize !== true;
  const width = Number((preservesTemplateSize ? null : overrides.width) || element.attrs.width || child.attrs.width || element.attrs.moduleJson.width || 50);
  const height = Number((preservesTemplateSize ? null : overrides.height) || element.attrs.height || child.attrs.height || element.attrs.moduleJson.height || 50);

  element.attrs.id = overrides.id || String(Date.now() + Math.floor(Math.random() * 1000000));
  element.attrs.x = x;
  element.attrs.y = y;
  element.attrs.width = width;
  element.attrs.height = height;
  element.attrs.time = new Date().toLocaleString();
  element.attrs.moduleJson.width = width;
  element.attrs.moduleJson.height = height;
  child.attrs.width = width;
  child.attrs.height = height;
  if (Array.isArray(element.children)) {
    element.children.forEach((renderChild) => {
      if (renderChild && renderChild.attrs) {
        if (Object.prototype.hasOwnProperty.call(renderChild.attrs, 'width')) renderChild.attrs.width = width;
        if (Object.prototype.hasOwnProperty.call(renderChild.attrs, 'height')) renderChild.attrs.height = height;
      }
    });
  }

  if (overrides.attrs && typeof overrides.attrs === 'object') {
    Object.assign(child.attrs, clone(overrides.attrs));
  }

  return element;
}

function loadTemplateElementsFromZip(zipPath) {
  const entries = parseZipEntries(fs.readFileSync(zipPath));
  const txtEntry = entries.find((entry) => path.extname(entry.name).toLowerCase() === '.txt');
  if (!txtEntry) throw new Error('zip missing txt file');
  return {
    txtName: txtEntry.name,
    elements: extractTemplateElementsFromPageText(txtEntry.data.toString('utf8')),
    assets: entries.filter((entry) => entry.name.startsWith('img/uploads/')),
  };
}

module.exports = {
  buildComponentTemplateIndex,
  createElementFromTemplateIndex,
  extractTemplateElementsFromPageText,
  extractComponentTemplatesFromZip,
  loadTemplateElementsFromZip,
  parseZipEntries,
};
