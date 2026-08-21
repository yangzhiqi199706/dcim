const assert = require('assert');

const { rewriteImportedPageImageRefs } = require('./local-api-routes');

const nestedModule = JSON.stringify({
  children: [{ attrs: { image: '../Images/uploads/nested-panel.png' } }],
});
const importedStage = {
  attrs: {
    fillPatternImage: '../Images/uploads/canvas-background.png',
  },
  children: [{
    attrs: {
      src: 'http://172.16.3.2:8081/Images/uploads/legacy-panel.png',
      moduleJson: nestedModule,
      label: 'Keep this label unchanged',
    },
  }],
};

const importedPageText = JSON.stringify(JSON.stringify(importedStage));
const rewrittenPageText = rewriteImportedPageImageRefs(importedPageText);
const rewrittenStage = JSON.parse(JSON.parse(rewrittenPageText));
const rewrittenModule = JSON.parse(rewrittenStage.children[0].attrs.moduleJson);

assert.strictEqual(rewrittenStage.attrs.fillPatternImage, 'Images/uploads/canvas-background.png');
assert.strictEqual(rewrittenStage.children[0].attrs.src, 'Images/uploads/legacy-panel.png');
assert.strictEqual(rewrittenModule.children[0].attrs.image, 'Images/uploads/nested-panel.png');
assert.strictEqual(rewrittenStage.children[0].attrs.label, 'Keep this label unchanged');
assert.doesNotMatch(rewrittenPageText, /172\.16\.3\.2:8081/);
assert.doesNotMatch(rewrittenPageText, /\.\.\/Images\//);

console.log('Imported page image path rewrite test passed');
