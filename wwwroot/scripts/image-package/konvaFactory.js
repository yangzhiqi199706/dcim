let idCounter = Date.now();

function nextId() {
  idCounter += 1;
  return String(idCounter);
}

function createModuleAttrForText() {
  return [
    {
      attrGroupName: 'Appearance',
      attrGroupContent: [
        { attrName: 'Text', attrCode: 'text', attrType: 'textarea', attrWhere: 'description' },
        { attrName: 'Font Size', attrCode: 'fontSize', attrType: 'number', attrWhere: 'description' },
        { attrName: 'Font Color', attrCode: 'fill', attrType: 'color', attrWhere: 'description' },
      ],
    },
  ];
}

function createGroup(moduleJson, attrs) {
  const child = moduleJson.children[0];
  return {
    attrs: {
      id: attrs.id || nextId(),
      handleTool: false,
      x: attrs.x || 0,
      y: attrs.y || 0,
      src: attrs.src || '',
      moduleJson,
      draggable: attrs.draggable !== false,
      time: new Date().toLocaleString(),
      width: attrs.width || moduleJson.width || 50,
      height: attrs.height || moduleJson.height || 50,
      name: 'group',
    },
    className: 'Group',
    children: [
      {
        attrs: { ...child.attrs },
        className: child.className,
      },
    ],
  };
}

function createTextElement(item) {
  const fontSize = Math.max(10, Math.round((item.fontSize || item.height || 20) * 0.7));
  const child = {
    attrs: {
      text: item.text,
      fontSize,
      lineHeight: 1,
      fontFamily: 'Arial',
      fill: item.fill || '#00ffff',
      padding: 0,
      align: 'left',
      fontStyle: 'normal',
      verticalAlign: 'middle',
      name: 'description',
      width: item.width,
      height: item.height,
    },
    className: 'Text',
  };
  const moduleJson = {
    attrs: {
      dataKey: [],
      moduleAttr: createModuleAttrForText(),
    },
    children: [child],
    width: item.width,
    height: item.height,
  };
  return createGroup(moduleJson, item);
}

function createRectElement(item) {
  const child = {
    attrs: {
      name: 'myShape',
      width: item.width,
      height: item.height,
      stroke: item.stroke || '#00ffff',
      strokeWidth: item.strokeWidth == null ? 1 : item.strokeWidth,
      fill: item.fill || 'rgba(0,0,0,0)',
      opacity: item.opacity == null ? 1 : item.opacity,
    },
    className: 'Rect',
  };
  const moduleJson = {
    attrs: {
      moduleAttr: [
        {
          attrGroupName: 'Appearance',
          attrGroupContent: [
            { attrName: 'Fill', attrCode: 'fill', attrType: 'color', attrWhere: 'myShape' },
            { attrName: 'Stroke', attrCode: 'stroke', attrType: 'color', attrWhere: 'myShape' },
          ],
        },
      ],
    },
    children: [child],
    width: item.width,
    height: item.height,
  };
  return createGroup(moduleJson, item);
}

function createLineElement(item) {
  const child = {
    attrs: {
      points: item.points,
      stroke: item.stroke || '#00ffff',
      strokeWidth: item.strokeWidth == null ? 1 : item.strokeWidth,
      opacity: item.opacity == null ? 1 : item.opacity,
      name: 'myLine',
    },
    className: 'Line',
  };
  const moduleJson = {
    attrs: { moduleAttr: [] },
    children: [child],
    width: 50,
    height: 50,
  };
  return createGroup(moduleJson, { ...item, x: 0, y: 0, width: 50, height: 50 });
}

function createImageElement(item) {
  const child = {
    attrs: {
      name: 'myImage',
      image: item.image,
      width: item.width,
      height: item.height,
    },
    className: 'Image',
  };
  const moduleJson = {
    attrs: {
      moduleAttr: [
        {
          attrGroupName: 'Appearance',
          attrGroupContent: [
            { attrName: 'Image Width', attrCode: 'width', attrType: 'number', attrWhere: 'myImage' },
            { attrName: 'Image Height', attrCode: 'height', attrType: 'number', attrWhere: 'myImage' },
            { attrName: 'Image', attrCode: 'image', attrType: 'image', attrWhere: 'myImage' },
          ],
        },
      ],
    },
    children: [child],
    width: item.width,
    height: item.height,
  };
  return createGroup(moduleJson, item);
}

function createStage(options) {
  return {
    attrs: {
      width: 1920,
      height: 1080,
      className: 'canvasStage canvasStage2',
    },
    className: 'Stage',
    children: [
      {
        attrs: {
          style: { backgroundColor: '#fff' },
        },
        className: 'Layer',
        children: [
          {
            attrs: {
              width: 1920,
              height: 1080,
              fillPatternRepeat: 'no-repeat',
              id: 'canvasBackground',
              fillPatternImage: options.backgroundImage,
              alarmCatch: '1',
            },
            className: 'Rect',
          },
          ...(options.elements || []),
        ],
      },
    ],
  };
}

function encodePageText(stage) {
  return JSON.stringify(JSON.stringify(stage));
}

module.exports = {
  createStage,
  encodePageText,
  createTextElement,
  createRectElement,
  createLineElement,
  createImageElement,
};
