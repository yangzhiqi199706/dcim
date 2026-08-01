const fs = require('fs');
const path = require('path');

const MASTER_CONTROL_KIND = 'master-control';
const MASTER_CONTROL_ICON = 'Images/icon/tpl.png';

function ensureDirectory(directory) {
  if (!fs.existsSync(directory)) {
    fs.mkdirSync(directory, { recursive: true });
  }
}

function sanitizeName(name, fallback = 'master-control') {
  const raw = String(name || '').trim();
  const fileName = path.basename(raw).replace(/[\\/:*?"<>|]/g, '_');
  const stem = path.parse(fileName).name.replace(/^\.+/, '').replace(/\.+$/, '').trim();
  return stem || fallback;
}

function parseDefinition(value) {
  let definition = value;
  if (typeof definition === 'string') {
    try {
      definition = JSON.parse(definition);
    } catch (error) {
      return null;
    }
  }

  if (!definition || typeof definition !== 'object') return null;
  if (definition.kind !== MASTER_CONTROL_KIND) return null;
  if (!Array.isArray(definition.shapes) || definition.shapes.length === 0) return null;
  return definition;
}

function createMasterControlStore(directory) {
  const targetDirectory = path.resolve(directory);

  const filePathFor = (name) => path.join(targetDirectory, `${sanitizeName(name)}.json`);

  const list = () => {
    ensureDirectory(targetDirectory);
    return fs.readdirSync(targetDirectory, { withFileTypes: true })
      .filter((entry) => entry.isFile() && path.extname(entry.name).toLowerCase() === '.json')
      .map((entry) => {
        try {
          const definition = parseDefinition(fs.readFileSync(path.join(targetDirectory, entry.name), 'utf8'));
          if (!definition) return null;
          return {
            moduleName: String(definition.name || path.parse(entry.name).name),
            iconBase64: MASTER_CONTROL_ICON,
            moduleJson: definition,
          };
        } catch (error) {
          return null;
        }
      })
      .filter(Boolean)
      .sort((left, right) => left.moduleName.localeCompare(right.moduleName));
  };

  const save = (name, rawDefinition) => {
    const definition = parseDefinition(rawDefinition);
    if (!definition) return { ok: false, message: 'invalid master control definition' };

    const safeName = sanitizeName(name || definition.name);
    const filePath = filePathFor(safeName);
    if (fs.existsSync(filePath)) return { ok: false, message: 'master control already exists' };

    ensureDirectory(targetDirectory);
    fs.writeFileSync(filePath, JSON.stringify(definition), 'utf8');
    return {
      ok: true,
      data: {
        moduleName: String(definition.name || safeName),
        iconBase64: MASTER_CONTROL_ICON,
        moduleJson: definition,
      },
    };
  };

  const remove = (name) => {
    const filePath = filePathFor(name);
    if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
      return { ok: false, message: 'master control not found' };
    }

    fs.unlinkSync(filePath);
    return { ok: true };
  };

  return { list, remove, save };
}

module.exports = {
  createMasterControlStore,
};
