# 图片转可导入页面包设计

## 背景

当前项目支持导入页面 zip。现有导入包结构通常包含一个页面 txt 和若干图片资源：

```text
page.txt
img/
img/uploads/example.png
```

导入时，本地 API 会把 txt 保存到 `public/Images/page/`，把 zip 内 `img/` 下的图片保存到 `public/Images/uploads/`，然后前端调用主业务接口创建页面记录。

本设计要新增一个“图片转导入包”能力：用户给一张大屏图片，系统生成可被当前导入功能直接使用的 zip。导入后页面视觉上能还原原图，并额外包含可编辑的文字、矩形、线条元素。

## 目标

- 输入一张图片，输出一个当前项目可直接导入的 zip。
- 生成页面固定为 1920x1080 画布，兼容现有 `Home.js` / `ConElement.js` 渲染结构。
- 原图作为背景图保底，确保视觉还原。
- 自动生成可编辑覆盖元素：
  - `Text`：识别出的文字。
  - `Rect`：识别出的面板框、色块、矩形边框。
  - `Line` 或窄 `Rect`：识别出的分隔线、直线。
- 不改变现有导入 `.txt/.zip` 流程。

## 非目标

- 第一版不自动生成 `wetHtml`、设备绑定、点击事件、图表、视频组件。
- 第一版不强制把复杂图标、照片、渐变装饰切成独立元素。
- 第一版不直接改造设计器 UI；先实现可验证的生成器，稳定后再接入页面按钮或本地 API。

## 推荐实现形态

第一阶段新增独立生成脚本：

```text
wwwroot/scripts/image-to-import-package.js
```

脚本输入：

```text
node scripts/image-to-import-package.js --image <path> --name <page-name> --index <page-index>
```

脚本输出：

```text
wwwroot/public/Images/exports/<page-name>_<timestamp>.zip
```

后续稳定后，可再封装为本地 API：

```text
POST /api/local/imageToPackage
```

## 输出 zip 格式

生成包沿用当前导入逻辑：

```text
<page-name>[<page-index>].txt
img/
img/uploads/<safe-image-name>_<timestamp>.png
```

txt 内容仍采用现有页面文件格式：外层是 JSON 字符串，字符串内容是 Konva Stage JSON。

Stage 结构：

```text
Stage 1920x1080
└── Layer
    ├── Rect canvasBackground
    ├── Text elements
    ├── Rect elements
    └── Line elements
```

背景 Rect 示例字段：

```json
{
  "attrs": {
    "width": 1920,
    "height": 1080,
    "fillPatternRepeat": "no-repeat",
    "id": "canvasBackground",
    "fillPatternImage": "../Images/uploads/<image-name>.png",
    "alarmCatch": "1"
  },
  "className": "Rect"
}
```

## 元素生成规则

### 图片适配

- 输入图不是 1920x1080 时，先计算缩放映射。
- 背景图最终按 1920x1080 画布铺满。
- OCR 和图形检测得到的坐标统一映射到 1920x1080。

### Text

每个识别出的文字框生成一个 `Group`，内部 `moduleJson.children[0].className` 为 `Text`。

字段尽量匹配现有 Text 模板：

- `x`, `y`：文字框左上角。
- `width`, `height`：文字框尺寸。
- `text`：识别出的文字。
- `fontSize`：根据文字框高度估算。
- `fill`：优先从文字区域采样，失败则使用高对比默认色。
- `fontFamily`：默认 `Arial` 或沿用项目模板值。
- `draggable: true`。

### Rect

检测明显矩形面板、边框和色块后生成 `Rect` 元素。

规则：

- 大面积单色/半透明区域生成填充矩形。
- 明显边框生成无填充或低透明填充矩形。
- 太碎、太小、置信度低的矩形忽略。

### Line

检测横线、竖线和简单分隔线。

规则：

- 如果线条非常细，可生成 `Line`。
- 如果线条有明显厚度，可生成窄 `Rect`，兼容编辑器属性面板。
- 避免把文字笔画误识别为线条。

## 数据流

1. 读取输入图片。
2. 复制或转换图片到临时工作目录，生成安全文件名。
3. 读取图片尺寸，建立坐标映射。
4. 执行文字识别，得到文字框列表。
5. 执行图形检测，得到矩形和直线列表。
6. 对检测结果做过滤、合并、去重。
7. 转换为项目现有 `Group + moduleJson + children` 元素结构。
8. 生成 Stage JSON。
9. 将 Stage JSON 再 JSON.stringify 一次，写入 txt。
10. 创建 zip，包含 txt 和 `img/uploads/` 下的背景图。
11. 输出 zip 路径和识别统计。

## 错误处理

- 输入图片不存在：直接失败并提示路径。
- 图片格式不支持：提示支持格式。
- OCR 不可用：仍生成只有背景图的 zip，并提示文字识别被跳过。
- 图形检测失败：仍生成背景图和已识别文字。
- zip 写入失败：不生成半成品，返回错误。
- 生成的 txt 必须可被现有 `exportImport` 路由导入。

## 验证计划

使用已有样例包中的背景图进行第一轮验证：

```text
preview_import_original/img_uploads_1727243565.png
```

验收标准：

- 生成的 zip 可以用当前“导入”按钮成功导入。
- 页面树新增页面记录。
- 打开页面后背景图完整显示。
- 识别出的文字能被单独选中、移动、编辑。
- 识别出的矩形和线条能被单独选中、移动、删除。
- 现有导入 `.txt/.zip` 功能不受影响。

## 后续增强

- 增加原图和生成结果的浏览器对比页。
- 支持用户在生成前选择识别强度：保守、标准、激进。
- 支持手动指定某些区域生成为 `wetHtml`。
- 支持把图标或重复小组件裁剪成独立 `Image` 元素。
- 支持接入本地 API 和设计器按钮，形成完整 UI 流程。
