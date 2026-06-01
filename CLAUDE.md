# 项目记忆 - 组态设计 (react-moodboard)

> 本文档供下次启动 Claude Code 时快速回忆项目结构、约定与坑位。
> 上次更新：2026-05-22

---

## 1. 项目定位

一个浏览器端 **组态/可视化大屏设计器**，支持：
- 拖拽元素（基础组件 / 图表 / 模板）到画布
- 设置元素属性、绑定设备数据、配置点击事件
- 多选 / 组合 / 磁吸对齐 / 锁定 / 剪贴板 / 撤销重做
- 保存为页面或模板，预览模式（`?type=…`）下根据协议/历史/告警/参数数据动态渲染
- 多语言（zh-CN / en-US），源码不允许中文，全部走 i18n 字典

项目代号：`react-moodboard`（见 `wwwroot/package.json`）。

---

## 2. 目录结构（关键路径）

```
localhost_8081/                       # 仓库根
├── .claude/                          # Claude 本地配置（agents / agent-memory / settings*.json）
├── .gitignore                        # 已忽略 build / node_modules / .env / *.bak 等
├── localhost_8086/                   # 备份位（untracked，旧版本副本）
└── wwwroot/                          # 实际前端 + 本地 API 服务
    ├── package.json                  # React 18 + Konva + ECharts + antd + MUI
    ├── .env                          # REACT_APP_APP_PORT=8081 / MAIN_API_PORT=8086 / VIDEO=18080
    ├── public/
    │   ├── index.html                # 主入口（title=组态设计）
    │   ├── login.html                # jQuery 登录页，POST /ZTLoginKey
    │   ├── runtime-endpoints.js      # 运行时注入 window.__RUNTIME_ENDPOINTS__
    │   ├── css/ js/                  # jquery-2.2.4.min.js / 公用样式
    │   └── Images/                   # dcim / icon / page / pagetpl / uploads / exports
    ├── scripts/
    │   └── check-no-cjk.js           # 源码内禁止 CJK 字符（字典除外）
    ├── server/
    │   ├── local-api.js              # 独立 Node 进程：node server/local-api.js（端口 8086）
    │   └── local-api-routes.js       # 6 个 POST 路由：见 §5
    └── src/
        ├── index.js                  # ReactDOM.createRoot -> <Home />
        ├── setupProxy.js             # dev: 把 /api/local/* 挂到本地路由
        ├── Assets/                   # http 工厂 + 公用样式
        │   ├── httpFactory.js        # createHttpClient（axios + nprogress + i18n 报错）
        │   ├── httpload.js           # 主 API 客户端（baseURL = mainApiBase）
        │   ├── httploadlocal.js      # 旧版本直接拼 :8086（保留以防）
        │   ├── httploadnode.js       # 本地 API 客户端（localApiBase）
        │   ├── httploadvideo.js      # 视频 API 客户端（:18080）
        │   ├── httpsend.js           # 统一出口：getData / getDataLocal / getDataVideo
        │   ├── imageDirectory.js     # 解析 nginx/apache 目录索引列文件
        │   └── style.css             # 主样式
        ├── config/
        │   └── endpoints.js          # 端口/URL 拼装，DEV 走 /api/local，PROD 拼 :8086
        ├── i18n/
        │   ├── index.js              # t() / setLocale / resolveI18nToken / localizeDeep
        │   └── dictionaries/         # zh-CN.js / en-US.js / auto.js (~5.5MB) / auto-extra.js
        └── Page/
            ├── Home.js               # 主画布（3631 行，单体 God Component）
            ├── ItemBox.js            # 左侧素材库 7 个 tab
            ├── ItemNav.js            # ItemBox 导航元数据
            ├── ToolList.js           # 顶部工具栏（撤销/复制/对齐…）
            ├── ConElement.js         # 设计态单个 Konva 节点
            ├── ElementAttr.js        # 右侧属性面板（2100 行，含 3 个 tab + 结构树）
            ├── ElementSvg.js         # SVG 形状渲染
            ├── SetChart.js           # ECharts 配置：仪表 / 饼 / 柱 / 折线 / 玫瑰 / 告警…
            ├── SvgBackground.js      # SVG 背景
            ├── PreviewElement.js     # 预览态节点（带跳转/视频/时间）
            ├── PreviewDeal.js        # 预览态数据处理（协议/历史/告警合并到 attrs）
            ├── PreviewGif.js / PreviewImage.js
            └── Data/
                ├── BasicComponents.json   # 基础组件库（93KB）
                ├── ScreenTemplate.json    # 大屏模板（27KB）
                ├── PageTemplate.json
                ├── GifImages.json
                └── tools.json             # 顶部工具栏元数据
```

代码体量：14731 行（不含 node_modules / dictionaries / 模板 JSON）。

---

## 3. 运行 / 构建

> ⚠️ 当前安装的 `react-scripts` 是 2.x（很老），Node ≥17 可能需 `NODE_OPTIONS=--openssl-legacy-provider`。

```bash
cd wwwroot

# 开发：8081 跑前端，setupProxy 把 /api/local/* 转发到 server/local-api-routes
npm start

# 单独跑本地文件 API（默认端口 8086，可被 LOCAL_API_PORT 覆盖）
npm run start:local-api

# 生产构建
NODE_OPTIONS=--openssl-legacy-provider node node_modules/react-scripts/bin/react-scripts.js build
# 或：node_modules/.bin/react-scripts build

# 提交前必跑：禁止源码出现中文
npm run check:no-cjk
```

端口约定（`.env` + `runtime-endpoints.js`）：
| 用途 | 端口 | 变量 |
|---|---|---|
| 前端 React | 8081 | `REACT_APP_APP_PORT` / `appPort` |
| 主业务后端（登录、Key 接口） | 8086 | `REACT_APP_MAIN_API_PORT` / `mainApiPort` |
| 本地文件 API | 8086（同主后端，挂在 `/api/local/*`） | `REACT_APP_LOCAL_API_BASE` / `localApiBase` |
| 视频 API | 18080 | `REACT_APP_VIDEO_API_PORT` / `videoApiPort` |

部署到生产时只需要修改 `public/runtime-endpoints.js`，无需重新打包。

---

## 4. 关键约定（务必遵守）

### 4.1 离线运行（用户全局规则）
> 引入资源必须能在本地运行，例如 **ECharts 必须可离线**。
- 当前 ECharts 走 `import * as echarts from "echarts"`，依赖 `node_modules` 已入仓（package-lock.json 921KB）。
- **禁止**新引入 CDN / Google Fonts / 外部图床。如需图标统一放 `public/Images/icon/`。

### 4.2 源码禁止中文
- `scripts/check-no-cjk.js` 扫描 `src/ server/ scripts/`，正则 `[㐀-鿿豈-﫿]`、`�`、`[Ѐ-ӿ]`（西里尔疑似乱码）。
- 唯一豁免：`src/i18n/dictionaries/**`。
- 已有大量 `// Comment translated to English.` 形如占位，**改代码时不要再把中文注释写回去**，要写英文。

### 4.3 国际化
- 静态文案：`t('auto.k0333')` 或 `t('itemBox.myPages')`。
- 字典里值可写为 `'__i18n__.some.key'`，使用时 `resolveI18nToken(value)` 或 `localizeDeep(obj)` 解析。
- 默认 zh-CN，存在 `localStorage.app_locale`。

### 4.4 HTTP 出口统一走 `httpsend`
```js
import httpsend from '../Assets/httpsend';

httpsend.getData('GetXxxKey', { ... });        // 主后端 :8086
httpsend.getDataLocal('imgData', { action: 'upload', ... }); // 本地文件 API
httpsend.getDataVideo('api/...', { ... });     // 视频后端 :18080，自动带 access-token
httpsend.mainURL();  // 前端 base
httpsend.viewURL();  // 主后端 base，用于拼图片地址
```
URL 不要硬编码端口，统一用 `config/endpoints.js` 里的 `appBase / mainApiBase / videoApiBase / buildMainApiUrl(path)`。

### 4.5 登录
- `localStorage.wl` 是登录 token。空 token + 非预览模式 → 弹错并跳 `login.html`。
- `login.html` 用 jQuery，POST 到 `${MAIN_API_BASE}/ZTLoginKey`，成功后写 `localStorage.wl` 跳 `index.html`。

### 4.6 预览模式
URL 带 `?type=...` 即进入预览，跳过登录校验；带 `?swiper=...` 走轮播。`title` 参数控制标题。

---

## 5. 本地 API 路由（`server/local-api-routes.js`）

挂载基址：`/api/local`（dev 由 setupProxy；prod 由 `local-api.js` 直接 `app.listen`）。
所有 6 个路由都是 **POST**，返回 `{ code: 100|400, msg, data }`：

| 路由 | 作用 |
|---|---|
| `imgData` | 列目录 / 上传 / 系统素材（含 action: upload / system / page / template / export） |
| `saveTpl` | 保存模板 txt 到 `public/Images/pagetpl/` |
| `savePage` | 保存页面 txt 到 `public/Images/page/` |
| `export` | 导出页面到 `public/Images/exports/` |
| `upload` | 通用文件上传 |
| `exportImport` | 导入导出兼容入口 |

PUBLIC 根固定为 `wwwroot/public`，所有目录在缺失时会 `fs.mkdirSync(recursive: true)`。文件名经 `sanitizeName` 清洗，禁止路径穿越。

---

## 6. 画布架构要点（Home.js 心智模型）

> Home.js 体量 3631 行，是项目最大的"上帝组件"。改它之前先全文搜索 ref 名。

### 6.1 关键状态
- `images` / `imagesRef` — 画布上所有元素数组，**真正的单一来源**。
- `selectedId` / `selectedIds` — 单选 / 多选 ID 集合。
- `marqueeHoverIds` / `hoverElementIds` — 框选过程高亮 / 鼠标悬停高亮。
- `stageWidth` / `stageHeight` — 默认 1920×1080，受 `normalizeStageSize` 保护。
- `history` — 模块级数组（不是 state），用于撤销。
- 多拖 / 磁吸 / 剪贴板 都通过 `useRef` 管理避免重渲染。
- 剪贴板 key：`PAGE_DESIGNER_CLIPBOARD_KEY = 'page_designer_clipboard_v1'`（sessionStorage）。

### 6.2 已实现编辑器特性（F-系列）
出自 git 历史，可作排查/扩展参考：
- **F1** 磁吸对齐 + 参考线（画布主线 / 元素相邻边）
- **F2** 元素锁定（`draggable:false`，Ctrl+K / Ctrl+Shift+K）
- **F3** undo 重构（移除全局 historyStep）
- **F4** savePage 始终回写 imagesRef
- **F5** 模板/重置时清理多拖、磁吸、选中、hover
- **F6** 多选拖动跟随（精简）
- **F7** 组合 / 取消组合 + 按组扩展
- **F8** Ctrl+C/X/V 剪贴板
- **F9** 画布边界约束（`getBoundedDragPosition` / `getBoundedTransformerBox`）
- **F10** 顶栏 flex 拆分 topLeft / topCenter / topRight
- **F12** 切换页面 tab 闪烁动画
- **F13** 结构树（属性面板第三个 tab）+ hover 联动
- **F14** 柱状图自动排序（见 SetChart）
- **F15** 素材库 tab 拆分 基础/图表 + 缩略图悬停大图预览
- **F16** 图片选择弹窗悬停大图预览
- **F17** 二级弹窗可拖动 + 居中
- **F18** 鼠标悬停元素 / 组合显示边框
- **F19** 框选拖拽过程实时给相交元素绿色边框

### 6.3 多选拖动血泪史（修过 N 次）
- dragstart 必须用 **Konva 节点真实位置** 记录起点，不要读 imagesRef（可能未同步）。
- dragmove **用鼠标指针 delta 计算位移**（不用 react state，避免重渲染回拉）。
- 拖动期间禁用其它 setState；`useLayoutEffect` 仅对 `pendingPositions` 做 backstop。
- dragend **只 push 一次 history**。
- 操作前（复制/对齐/保存）必须先把 Konva 真实位置同步回 `imagesRef`，否则会错位。
- 复制组合时要**重新映射 groupId**，避免新旧成员留在同组。

如果你又在调多选/组合拖动 bug，先去看 `git log --oneline | grep '多选\|组合'`，几乎全是踩过的坑。

---

## 7. 图表（SetChart.js）

- 入口：`setChart.echart(images, selectedId, alarmData)`，遍历 DOM `.chart` 节点找匹配 image 并渲染。
- 渲染器：Firefox 用 `svg`，其它用 `canvas`（`isFirefox` 检测）。
- 所有 setOption 走 `safeSetOption`，强制 `animation: false`、`notMerge: true`、`silent: true`，避免抖动。
- 复用实例：`echarts.getInstanceByDom`，painter 类型不一致才 dispose 重建。
- 支持类型：`gauge / pie / bar / line / rose / alarm` 等，配置字段命名风格 `xxxSwitch === '2'` 表示开启。

---

## 8. Git 现状

- 当前分支：**`port-yf`**（远端：`origin/port-yf`，`origin/main`，`origin/your-feature`）。
- 未提交修改：`wwwroot/src/Page/ConElement.js`（M）以及一些副本/中文文件名的 D 项。
- 未跟踪：`localhost_8086/`（旧版本备份目录）。
- 提交规范（参考 `.claude/settings.json` 历史）：
  - 提交模板：`feat(F##): xxx` / `fix(模块): xxx` / `refactor(F##): xxx`
  - 提交时常用 `git -c core.hooksPath=/dev/null commit -m '...'` 绕过本地 hook（注意 hook 可能就是 check-no-cjk）。

---

## 9. 常见任务速查

| 任务 | 起点文件 |
|---|---|
| 加新组件类型 | `Page/Data/BasicComponents.json` + `ConElement.js` 渲染逻辑 |
| 加图表类型 | `Page/SetChart.js`（switch by `chartInfo.cat`） |
| 改属性面板 | `Page/ElementAttr.js`（tab 切换看 `divTab`） |
| 调多选/组合 | `Page/Home.js` 搜 `multiDragRef / groupId / dragOffset` |
| 加 i18n key | `src/i18n/dictionaries/zh-CN.js` + `en-US.js`，组件里 `t('your.key')` |
| 加本地 API | `server/local-api-routes.js`，记得 dev 走 setupProxy、prod 走 local-api.js |
| 新增端口/服务 | `runtime-endpoints.js` + `config/endpoints.js` + `.env` 三处 |
| 改顶部工具栏 | `Page/Data/tools.json` + `Page/ToolList.js` |

---

## 10. 已知坑 / 待办

1. **node_modules 已入库**：历史遗留。`.gitignore` 注释说"如果后续要清理，单独开分支用 `git rm -r --cached`"。短期别动。
2. **`localhost_8086/` 目录**：根目录下旧版本副本，untracked，不要误改。
3. **副本/* 中文目录**：已 `D` 标记待提交清理。
4. **react-scripts 2.x**：升级到 5.x 风险大（大量 webpack 5 适配），暂时维持。
5. **Home.js 3631 行**：拆分计划尚未启动。
6. **图片地址混用**：有 `mainApiBase` 拼接，也有 `Images/...` 相对路径，预览时注意拼装规则。
7. **`auto.js` 字典 5.5MB**：自动生成的翻译字典，不要手编辑，改 `zh-CN.js / en-US.js` 即可。

---

## 11. 速访问命令

```bash
# 看最近提交（F-系列特性 / 修复）
git log --oneline -30

# 全文搜（用 Grep 工具，不要直接 grep）
# - 找 i18n key 用法
# - 找 ref 名（multiDragRef / clipboardRef / snapGuideRef）
# - 找路由（POST /imgData 等）

# 提交前自检
cd wwwroot && npm run check:no-cjk
```

---

> 📌 **下次会话从这里开始**：先 `git status`、`git log --oneline -10`、读这份 CLAUDE.md，
> 然后根据用户需求定位到对应章节（编辑器特性 → §6.2 / 图表 → §7 / API → §5）。
