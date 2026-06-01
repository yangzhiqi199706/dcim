const fs = require('fs');
const path = require('path');

const basicComponentsPath = path.resolve(__dirname, '../../src/Page/Data/BasicComponents.json');
const autoDictionaryPath = path.resolve(__dirname, '../../src/i18n/dictionaries/auto.js');
const autoExtraDictionaryPath = path.resolve(__dirname, '../../src/i18n/dictionaries/auto-extra.js');
const I18N_TOKEN_PREFIX = '__i18n__.';

function clone(value) {
  return JSON.parse(JSON.stringify(value));
}

function isObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
}

function loadDictionaryObject(filePath, varName) {
  const source = fs.readFileSync(filePath, 'utf8');
  const body = source
    .replace(new RegExp(`^\\s*const\\s+${varName}\\s*=\\s*`), 'module.exports = ')
    .replace(new RegExp(`\\n\\s*export\\s+default\\s+${varName};?\\s*$`), '');
  const mod = { exports: {} };
  Function('module', 'exports', body)(mod, mod.exports);
  return mod.exports;
}

function getByPath(source, keyPath) {
  return String(keyPath || '').split('.').reduce((current, segment) => {
    if (!isObject(current)) return undefined;
    return current[segment];
  }, source);
}

function loadI18nDictionary() {
  const auto = loadDictionaryObject(autoDictionaryPath, 'auto');
  const autoExtra = loadDictionaryObject(autoExtraDictionaryPath, 'autoExtra');
  return {
    auto: {
      ...(auto['zh-CN'] || {}),
      ...(autoExtra['zh-CN'] || {}),
    },
  };
}

function localizeDeep(value, dictionary = loadI18nDictionary()) {
  if (Array.isArray(value)) return value.map((item) => localizeDeep(item, dictionary));
  if (isObject(value)) {
    const output = {};
    Object.keys(value).forEach((key) => {
      output[key] = localizeDeep(value[key], dictionary);
    });
    return output;
  }
  if (typeof value === 'string' && value.startsWith(I18N_TOKEN_PREFIX)) {
    const resolved = getByPath(dictionary, value.slice(I18N_TOKEN_PREFIX.length));
    return typeof resolved === 'string' ? resolved : value;
  }
  return value;
}

function visit(value, visitor) {
  if (Array.isArray(value)) {
    value.forEach((item) => visit(item, visitor));
    return;
  }
  if (!isObject(value)) return;
  visitor(value);
  Object.keys(value).forEach((key) => visit(value[key], visitor));
}

function getModuleChild(component) {
  return component
    && component.moduleJson
    && Array.isArray(component.moduleJson.children)
    && component.moduleJson.children[0];
}

function toGroup(component) {
  const width = Number(component.moduleJson && component.moduleJson.width) || 350;
  const height = Number(component.moduleJson && component.moduleJson.height) || 250;
  return {
    attrs: {
      id: String(Date.now() + Math.floor(Math.random() * 1000000)),
      handleTool: false,
      x: 0,
      y: 0,
      src: component.iconBase64 || '',
      moduleJson: clone(component.moduleJson),
      draggable: true,
      time: new Date().toLocaleString(),
      width,
      height,
      name: 'group',
    },
    className: 'Group',
    children: [],
  };
}

function loadBasicComponentTemplates(filePath = basicComponentsPath) {
  const raw = localizeDeep(JSON.parse(fs.readFileSync(filePath, 'utf8')));
  const charts = {};

  visit(raw, (component) => {
    const child = getModuleChild(component);
    if (!child || child.className !== 'Echart') return;
    const cat = child.attrs && child.attrs.cat;
    if (cat && !charts[cat]) {
      charts[cat] = toGroup(component);
    }
  });

  return { charts };
}

function createBasicChartElement(cat, overrides = {}) {
  const templates = loadBasicComponentTemplates();
  const template = templates.charts[cat];
  if (!template) throw new Error(`missing chart template: ${cat}`);

  const element = clone(template);
  const child = element.attrs.moduleJson.children[0];
  const width = Number(overrides.width || element.attrs.width || child.attrs.width || 350);
  const height = Number(overrides.height || element.attrs.height || child.attrs.height || 250);

  element.attrs.x = Number(overrides.x || 0);
  element.attrs.y = Number(overrides.y || 0);
  element.attrs.width = width;
  element.attrs.height = height;
  element.attrs.moduleJson.width = width;
  element.attrs.moduleJson.height = height;
  child.attrs.width = width;
  child.attrs.height = height;

  ['title', 'xdata', 'data'].forEach((key) => {
    if (Object.prototype.hasOwnProperty.call(overrides, key)) {
      child.attrs[key] = clone(overrides[key]);
    }
  });

  return element;
}

module.exports = {
  createBasicChartElement,
  loadBasicComponentTemplates,
  localizeDeep,
};
