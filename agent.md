# 项目速查说明

## 基本要求

- 始终使用简体中文回复用户。
- 本项目源码改动要尽量遵循现有风格，避免顺手重构无关代码。
- 修改 `src/`、`server/`、`scripts/` 下源码时，注意不要新增中文字符。中文文案应放到 i18n 字典中，再通过 `t(...)` 或 `localizeDeep(...)` 使用。
- 不要改动 `node_modules/`、`build/` 产物和大量静态图片资源，除非用户明确要求。

## 项目定位

这是一个浏览器端可视化大屏/组态设计器，项目名为 `react-moodboard`。

主要能力：

- 拖拽基础组件、图表、模板、图片到画布。
- 在画布中编辑元素位置、尺寸、旋转、层级、锁定、多选、组合、复制粘贴、撤销重做等。
- 右侧属性面板支持元素样式、数据绑定、设备参数、点击事件、图片/GIF 等配置。
- 预览模式下根据协议、历史、告警、参数等数据渲染动态大屏。
- 支持页面和模板保存、导入导出、本地图片上传。
- 支持 `zh-CN` / `en-US` 多语言。

## 目录结构

```text
C:\Users\杨治琪\Desktop\脚本运行\AI8081
├── CLAUDE.md
├── .gitignore
└── wwwroot
    ├── package.json
    ├── public
    │   ├── index.html
    │   ├── login.html
    │   ├── runtime-endpoints.js
    │   └── Images
    ├── server
    │   ├── local-api.js
    │   └── local-api-routes.js
    ├── scripts
    │   └── check-no-cjk.js
    └── src
        ├── index.js
        ├── setupProxy.js
        ├── Assets
        ├── config
        ├── i18n
        └── Page
```

## 技术栈

- React 18
- react-konva / konva
- ECharts
- Ant Design
- MUI Icons / MUI Material
- axios
- react-scripts 2.x
- Express 本地 API

## 常用命令

在 `wwwroot` 目录下执行：

```bash
npm start
npm run start:local-api
npm run build
npm run check:no-cjk
```

说明：

- `npm start` 启动 React 开发服务。
- `npm run start:local-api` 单独启动本地文件 API，默认端口 `8086`。
- `npm run check:no-cjk` 检查源码中是否出现中文或疑似乱码字符，字典目录除外。
- `react-scripts` 版本较老，较新 Node 环境可能需要 `NODE_OPTIONS=--openssl-legacy-provider`。

## 端口和接口约定

端口和运行时地址主要由以下文件控制：

- `wwwroot/src/config/endpoints.js`
- `wwwroot/public/runtime-endpoints.js`

默认约定：

- 前端：`8081`
- 主业务后端：`8086`
- 本地文件 API：开发环境走 `/api/local/`，生产环境默认拼到 `8086/api/local`
- 视频 API：`18080`

不要在业务代码中硬编码端口，优先使用 `endpoints.js` 中的 `appBase`、`mainApiBase`、`videoApiBase`、`localApiBase`、`buildMainApiUrl(...)`。

## 入口和核心文件

- `wwwroot/src/index.js`
  - React 入口，直接渲染 `<Home />`。

- `wwwroot/src/Page/Home.js`
  - 项目核心画布总控组件。
  - 管理 `images`、`selectedId`、`selectedIds`、画布尺寸、缩放、历史记录、页面保存、预览、拖拽、多选、组合、吸附、快捷键等。
  - 文件体量较大，改动前先搜索相关 state/ref/function，不要凭局部上下文直接改。

- `wwwroot/src/Page/ConElement.js`
  - 设计态单个 Konva 元素渲染。
  - 处理选中框、hover 框、拖拽、变换、锁定、层级工具操作等。

- `wwwroot/src/Page/ItemBox.js`
  - 左侧素材/页面面板。
  - 管理页面树、基础组件、图表组件、屏幕模板、自定义模板、系统图片、上传图片。

- `wwwroot/src/Page/ElementAttr.js`
  - 右侧属性面板。
  - 管理元素属性、设备列表、参数绑定、事件绑定、点击动作、图片/GIF 选择弹窗等。

- `wwwroot/src/Page/SetChart.js`
  - ECharts 渲染逻辑。
  - 支持仪表盘、饼图、柱状图、折线图、玫瑰图、告警图表等类型。

- `wwwroot/src/Page/PreviewElement.js`
  - 预览态单个元素渲染。

- `wwwroot/src/Page/PreviewDeal.js`
  - 预览态数据处理，将协议、历史、告警、参数等数据合并到元素属性。

## 数据和素材

- `wwwroot/src/Page/Data/BasicComponents.json`
  - 基础组件和图表组件定义。

- `wwwroot/src/Page/Data/ScreenTemplate.json`
  - 大屏模板定义。

- `wwwroot/src/Page/Data/PageTemplate.json`
  - 页面模板数据。

- `wwwroot/src/Page/Data/GifImages.json`
  - GIF 素材数据。

- `wwwroot/src/Page/Data/tools.json`
  - 顶部工具栏配置。

- `wwwroot/public/Images`
  - 静态图片、系统素材、上传素材、页面 txt、模板 txt、导出文件等。

## HTTP 和本地 API

统一 HTTP 出口：

- `wwwroot/src/Assets/httpsend.js`

常用方法：

- `httpsend.getData(...)`
  - 请求主业务后端。

- `httpsend.getDataLocal(...)`
  - 请求本地文件 API。

- `httpsend.getDataVideo(...)`
  - 请求视频 API，会处理 `access-token`。

- `httpsend.mainURL()`
  - 前端 base URL。

- `httpsend.viewURL()`
  - 主业务后端 base URL，常用于拼图片地址。

本地 API：

- `wwwroot/server/local-api.js`
- `wwwroot/server/local-api-routes.js`
- `wwwroot/src/setupProxy.js`

本地 API 路由均为 POST，挂载在 `/api/local`：

- `imgData`
  - 列图片、模板、页面，也处理删除动作。

- `saveTpl`
  - 保存模板 txt 到 `public/Images/pagetpl/`。

- `savePage`
  - 保存页面 txt 到 `public/Images/page/`。

- `export`
  - 将页面 txt 和用户上传图片打包导出到 `public/Images/exports/`。

- `upload`
  - 通用 base64 文件上传到 `public/Images/uploads/`。

- `exportImport`
  - 导入 txt 或 zip 页面包。

本地 API 已包含文件名清洗、路径归一化、越界保护、zip 读写等逻辑，新增能力时要延续这些安全约束。

## i18n 约定

入口：

- `wwwroot/src/i18n/index.js`

字典：

- `wwwroot/src/i18n/dictionaries/zh-CN.js`
- `wwwroot/src/i18n/dictionaries/en-US.js`
- `wwwroot/src/i18n/dictionaries/auto.js`
- `wwwroot/src/i18n/dictionaries/auto-extra.js`

使用方式：

```js
import { t, localizeDeep, resolveI18nToken } from '../i18n';

t('some.key')
localizeDeep(data)
resolveI18nToken('__i18n__.some.key')
```

约定：

- 默认语言是 `zh-CN`。
- 当前语言存储在 `localStorage.app_locale`。
- JSON 数据中可写 `__i18n__.some.key`，使用时通过 `localizeDeep(...)` 或 `resolveI18nToken(...)` 解析。
- `auto.js` 较大，通常不要手改，优先改 `zh-CN.js` 和 `en-US.js`。

## 画布状态模型

`Home.js` 中最关键的状态和 ref：

- `images` / `imagesRef`
  - 画布元素数组，是真正的主要数据源。

- `selectedId` / `selectedIdRef`
  - 单选元素 ID。

- `selectedIds` / `selectedIdsRef`
  - 多选元素 ID 集合。

- `stageWidth` / `stageHeight`
  - 画布尺寸，默认 `1920 x 1080`。

- `canvasScale`
  - 画布缩放比例。

- `history`
  - 撤销重做相关历史栈。

- `multiDragRef`
  - 多选拖拽过程状态。

- `snapEnabled` / `snapThreshold`
  - 吸附开关和吸附阈值。

- `dirtyRef`
  - 页面是否存在未保存改动。

改动画布相关逻辑时，要特别注意 `images` 和 Konva 节点真实位置之间的同步。拖拽、多选、组合、复制、保存前通常需要确认最新位置已经写回 `imagesRef`。

## 预览模式

URL 带 `type` 参数时进入预览相关流程。

预览模式通常会：

- 跳过普通设计态登录/编辑流程。
- 加载页面或模板数据。
- 使用 `PreviewDeal.js` 处理设备、协议、历史、告警、参数等数据。
- 使用 `PreviewElement.js` 渲染预览态元素。

涉及预览问题时，优先检查：

- `Home.js` 中 URL 参数和页面加载逻辑。
- `PreviewDeal.js` 的数据合并逻辑。
- `PreviewElement.js` 的渲染逻辑。
- `SetChart.js` 的图表刷新逻辑。

## 登录相关

- `wwwroot/public/login.html` 是 jQuery 登录页。
- 登录成功后写入 `localStorage.wl`。
- 普通设计态可能会检查 `localStorage.wl`。
- 预览模式可能绕过普通登录检查。

## 改动建议

- 改 `Home.js` 前先用 `rg` 搜索相关函数、ref、state、常量。
- 改图表先看 `SetChart.js`，再看组件 JSON 中对应 `moduleJson` 结构。
- 改属性面板先看 `ElementAttr.js` 中对应 tab、弹窗、字段初始化和回写逻辑。
- 改左侧素材或页面树先看 `ItemBox.js` 和 `ItemNav.js`。
- 改本地文件能力先看 `server/local-api-routes.js`，保持路径安全检查。
- 改接口地址先看 `config/endpoints.js` 和 `public/runtime-endpoints.js`。
- 新增文案时同步维护中英文字典，并运行 `npm run check:no-cjk`。

## 已知注意点

- 根目录当前不是 git 仓库，不能依赖 `git status` 判断变更。
- `CLAUDE.md` 里有项目记忆，但当前读取时显示为乱码，仍可看出大体结构和约定。
- `node_modules` 已存在且体量很大，搜索时要排除它。
- `build` 是构建产物，常规开发不要直接改。
- `Home.js` 是明显的“大组件”，局部改动需要格外小心状态联动。

## 2026-05-29 图表外观与动效开发经验

- 图表相关能力主要集中在 `wwwroot/src/Page/SetChart.js`，属性面板在 `wwwroot/src/Page/ElementAttr.js`，新拖入组件默认配置在 `wwwroot/src/Page/Data/BasicComponents.json`，属性控制补全逻辑在 `wwwroot/src/Page/chartAttributeControls.js`。
- 优化图表外观时，必须保留 `chartStyle: "original"` 原始外观；新增风格和动效只能作用在 ECharts option 的展示层，不能改变后端接口、请求参数、数据字段和原始数据绑定逻辑。
- 旧组件或新拖入组件可能缺少新字段，属性面板侧要做运行时兜底：ECharts 组件默认补 `chartStyle: "original"`、`chartAnimation: "off"`，否则用户会看不到新增按钮。
- “动效按钮存在”不代表图表本体真的动了。排查时要沿着 `chartAnimation` 字段从属性面板、组件 JSON、保存的页面 txt、`SetChart.js` 最终 option 一路确认。
- 柱状图“呼吸效果”如果只加 `graphic` 外框或背景层，用户会感觉单柱体没动。单柱体呼吸应对每根柱体本身做可见发光/明暗变化，可用 `custom` series 叠加透明交互覆盖层，根据原 bar 数据计算坐标，不修改原 bar series 的 `data`。
- ECharts 普通 `bar` series 不适合直接做持续循环的单柱体 keyframe；更稳的方案是在 `chartAnimation: "pulse"` 时追加 `bar-pulse-body` 这类展示层 series，并设置 `silent: true`，避免影响 tooltip、点击和数据含义。
- 折线图流光不能照搬普通 line 的无效 `effect` 配置，应使用叠加 `lines` series 的方式实现。
- 仪表盘、饼图、环形图等圆形图表可以用 `graphic` ring 做辅助呼吸，但仍要优先检查图表主体是否有明显变化。
- 动效要支持 `chartStyle: "original"`。不要把动效绑定到科技风格分支里，否则用户保留原始外观时会以为动效失效。
- 修改图表动效后要补单元测试，重点断言：
  - 原始数据不变。
  - 新增展示层存在。
  - 关闭动效时 animation 被禁用。
  - 新增动效不依赖非 original 外观。
- 本项目本地测试常用命令：
  - `npm test -- --runTestsByPath src/Page/SetChart.test.js src/Page/chartAttributeControls.test.js --watchAll=false`
  - 新版本 Node 构建时可能需要先设置 `NODE_OPTIONS=--openssl-legacy-provider`，再执行 `npm run build`。
- 当前线上 8081 页面是 `react-scripts start` 开发服务读取源码，不是生产 hash build。同步服务器时更新容器内 `/www/wwwroot/localhost_8081/wwwroot/src/Page/SetChart.js` 后，要再检查 `https://192.168.0.50:8081/static/js/main.chunk.js` 是否包含新增标记，例如 `bar-pulse-body`、`keyframeAnimation`。
- 用户从 `https://192.168.0.50:8086/index.html` 查看效果时，外层页面可能嵌入 8081，浏览器缓存会影响验证。同步后提醒用户对外层页面和 iframe 做强刷，必要时在 DevTools 勾选 Disable cache。
- 服务器或登录凭据只用于临时排查，不要写入代码、文档或提交记录。
