import React, { useRef, useState, useEffect, useLayoutEffect, useMemo } from "react";
import { flushSync } from "react-dom";
import { Stage, Layer, Rect, Transformer, Text, Group, Line } from "react-konva";
import httpsend from '../Assets/httpsend';
import ToolList from "./ToolList";
import ItemBox from "./ItemBox";
import ConElement from "./ConElement";
import ElementAttr from "./ElementAttr";
import ElementSvg from "./ElementSvg";
import SvgBackground from "./SvgBackground";
import { Select, message, Button, Cascader } from 'antd';
import setChart from "./SetChart";
import { Close } from '@mui/icons-material';
import { KeyOutlined } from '@ant-design/icons';
import { t } from '../i18n';
import KeyboardShortcutsModal from './KeyboardShortcutsModal';
import {
    normalizeStageForPersistence,
    resolveLogicalStageSize
} from './stagePersistence';
import '../Assets/base.css';
import '../Assets/designer.css';

import Konva from "konva";

let history = [];
const PAGE_DESIGNER_CLIPBOARD_KEY = 'page_designer_clipboard_v1';
const SNAP_GUIDE_OFFSET = 24;
// F20 Ctrl+wheel zoom: range / step (percent, aligned with the canvasScale slider)
const ZOOM_MIN_PERCENT = 10;
const ZOOM_MAX_PERCENT = 300;
const ZOOM_WHEEL_STEP_PERCENT = 10;
const DESIGNER_EMPTY_STATE_TEXT = '#e7eef1';
const isPreview = false;
let stagejson = '';// Comment translated to English.

// Comment translated to English.
const loginState = localStorage.getItem('wl') || null;
if (!loginState && !isPreview) {
    message.error(t('auto.k0333'), 2, function () {
        window.location.href = httpsend.mainURL() + 'login.html';
    });
} else if (loginState && !isPreview) {
    // checkToken();
}

const normalizeStageSize = (value, fallback) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return fallback;
    }
    return Math.round(parsed);
};

function DesignerApp() {
    const stageRef = useRef();
    const containerRef = useRef();

    // Comment translated to English.
    const [dragUrl, setDragUrl] = useState();
    const [dragAttrs, setDragAttrs] = useState(null);
    const [dragShape, setDragShape] = useState(null);
    // Comment translated to English.
    const [images, setImages] = useState([]);
    const imagesRef = useRef(images);
    // Comment translated to English.
    const [selectedId, setSelectedId] = useState(null);
    const selectedIdRef = useRef(selectedId);
    // Comment translated to English.
    const [selectedIds, selectShapes] = useState([]);
    const selectedIdsRef = useRef(selectedIds);
    const [marqueeHoverIds, setMarqueeHoverIds] = useState([]);
    const [hoverElementIds, setHoverElementIds] = useState([]);
    const layerRef = useRef();
    const transformRefids = useRef();
    // Comment translated to English.
    const selectionRectRef = useRef();
    const selection = useRef({
        visible: false,
        x1: 0,
        y1: 0,
        x2: 0,
        y2: 0
    });
    const oldPos = useRef(null);
    // Comment translated to English.
    const [toolType, settoolType] = useState(null);
    // Comment translated to English.
    const [showIndex, setshowIndex] = useState(1);// Comment translated to English.
    // Comment translated to English.
    const [backgroundImage, setBackgroundImage] = useState();

    const [alarmCatch, setalarmCatch] = useState('1');
    const alarmCatchRef = useRef(alarmCatch);

    // Comment translated to English.
    const [showsaveTplBox, setshowsaveTplBox] = useState(0);
    const [saveTplName, setsaveTplName] = useState();
    // Comment translated to English.
    const [savePagePidSel, setsavePagePidSel] = useState();// Comment translated to English.
    const [showsavePageBox, setshowsavePageBox] = useState(0);
    // Comment translated to English.
    const [savePageType, setsavePageType] = useState();// Comment translated to English.
    const [savePagePid, setsavePagePid] = useState();// Comment translated to English.
    const [savePageName, setsavePageName] = useState();// Comment translated to English.
    const [savePageTxt, setsavePageTxt] = useState();// Comment translated to English.
    const [savePageIndex, setsavePageIndex] = useState();// Comment translated to English.
    const [savePageLink, setsavePageLink] = useState();// Comment translated to English.
    const [savePageId, setsavePageId] = useState('0');// Comment translated to English.
    const [isModalOpen, setIsModalOpen] = useState(false);// Comment translated to English.
    const [editModalOpen, seteditModalOpen] = useState(false);// Comment translated to English.
    // Comment translated to English.
    // Comment translated to English.
    const [isOutOpen, setIsOutOpen] = useState(false);
    // Comment translated to English.
    const [resetBox, setresetBox] = useState(false);
    const [pagedevList, setpagedevList] = useState([]);// Comment translated to English.
    // F24 device-replace scope: when the user opens the dialog with a multi-selection,
    // we limit collection + replacement to those ids. null = whole canvas (legacy behaviour).
    const resetScopeIdsRef = useRef(null);
    // Comment translated to English.
    const [stageWidth, setstageWidth] = useState(1920);
    const [stageHeight, setstageHeight] = useState(1080);
    const [stageDimensions, setStageDimensions] = useState({
        width: stageWidth,
        height: stageHeight,
        scalex: 1,
        scaley: 1
    });
    const safeStageWidth = normalizeStageSize(stageWidth, 1920);
    const safeStageHeight = normalizeStageSize(stageHeight, 1080);
    // F20 Ctrl+wheel zoom: physical canvas size = 1x size * scale.
    // Without this, scale > 1 causes content to be clipped by the Konva canvas bounds.
    const displayedStageWidth = Math.round(safeStageWidth * (stageDimensions ? stageDimensions.scalex || 1 : 1));
    const displayedStageHeight = Math.round(safeStageHeight * (stageDimensions ? stageDimensions.scaley || 1 : 1));
    // Comment translated to English.
    const [canvasScale, setcanvasScale] = useState(100);
    const [useSlaveId] = useState(() => localStorage.getItem('UseSlaveID') === '1');

    const savedStatus = t('designer.saved');
    const modifiedStatus = t('designer.modified');
    const [saveStatusText, setSaveStatusText] = useState(savedStatus);
    const lastSavedStageJsonRef = useRef('');
    const saveStatusTimerRef = useRef(null);
    // Dirty flag: true when there are unsaved changes since the last save / load.
    const dirtyRef = useRef(false);
    // \u9875\u9762\u52a0\u8f7d\u4ee4\u724c：\u6bcf\u6b21 dealStringPage / newPage \u5207\u6362\u9875\u9762\u65f6\u6362\u4e00\u4e2a\u65b0\u503c，
    // \u7528\u4e8e\u8de8\u9875\u9762\u590d\u5236\u7c98\u8d34\u65f6\u5224\u65ad\u662f\u5426\u540c\u4e00\u9875\u9762（\u8349\u7a3f\u9875 savePageId \u90fd\u662f '0' \u65e0\u6cd5\u533a\u5206）
    const currentPageTokenRef = useRef('init-' + Date.now());
    // \u5207\u6362\u754c\u9762\u63d0\u793a\u4fdd\u5b58：\u5f53\u524d\u9875\u9762\u6709\u672a\u4fdd\u5b58\u6539\u52a8\u65f6，\u70b9\u5176\u5b83\u9875\u9762\u4f1a\u5f39\u786e\u8ba4\u6846
    const [switchConfirmBox, setSwitchConfirmBox] = useState(false);
    const pendingSwitchRef = useRef(null);     // \u6682\u5b58\u88ab\u6253\u65ad\u7684\u5207\u6362\u52a8\u4f5c {dragUrl, dragAttrs, type}
    const [tabFlash, setTabFlash] = useState('');
    const [hoverHighlightIds, setHoverHighlightIds] = useState([]);

    // F22 text replace: dialog state for "find / replace within selected text-like shapes".
    const [textReplaceBox, setTextReplaceBox] = useState(false);
    const [textReplaceFind, setTextReplaceFind] = useState('');
    const [textReplaceTo, setTextReplaceTo] = useState('');
    const [keyboardShortcutsOpen, setKeyboardShortcutsOpen] = useState(false);

    const setSavedStatus = (text = savedStatus) => {
        if (saveStatusTimerRef.current) {
            clearTimeout(saveStatusTimerRef.current);
            saveStatusTimerRef.current = null;
        }
        setSaveStatusText(text);
        if (text !== modifiedStatus) {
            saveStatusTimerRef.current = setTimeout(() => {
                setSaveStatusText(savedStatus);
                saveStatusTimerRef.current = null;
            }, 1800);
        }
    };

    // Serialize the current Stage to the JSON form expected by the savePage endpoint.
    const buildPageJson = () => {
        if (!stageRef.current) return '';
        if (typeof syncKonvaPositionsToImagesRef === 'function') {
            syncKonvaPositionsToImagesRef();
        }
        let raw = stageRef.current.toJSON();
        let newjson;
        try {
            newjson = normalizeStageForPersistence(JSON.parse(raw), safeStageWidth, safeStageHeight);
        } catch (e) {
            return '';
        }
        const shapeMap = {};
        imagesRef.current.forEach((shape) => {
            shapeMap[shape.id] = shape;
        });
        if (newjson && newjson.children && newjson.children[0] && Array.isArray(newjson.children[0].children)) {
            newjson.children[0].children.forEach(element => {
                if (element.attrs.id === 'canvasBackground') {
                    if (backgroundImage && backgroundImage.indexOf('#') === -1) {
                        if (backgroundImage.indexOf('/public/') > 0) {
                            element.attrs.fillPatternImage = backgroundImage.split('/public/')[1];
                        } else {
                            element.attrs.fillPatternImage = backgroundImage;
                        }
                    }
                    element.attrs.alarmCatch = alarmCatchRef.current;
                    return;
                }
                const currentShape = shapeMap[element.attrs.id];
                if (currentShape) {
                    element.attrs = JSON.parse(JSON.stringify(currentShape));
                }
            });
        }
        return JSON.stringify(newjson);
    };

    // Reset the dirty flag after a page load, once the setImages/setBackgroundImage triggered effects have settled.
    const markPageLoaded = () => {
        setTimeout(() => {
            dirtyRef.current = false;
            if (saveStatusTimerRef.current) {
                clearTimeout(saveStatusTimerRef.current);
                saveStatusTimerRef.current = null;
            }
            setSaveStatusText(savedStatus);
        }, 0);
    };

    // F22 text replace helpers ---------------------------------------------------
    // Returns true when the child node is a "displayed text" target we are willing to edit:
    // - Konva Text node with name === 'description' (plain text + data-text components)
    // - Konva Text node with name === 'complexTextValue' (status-text component currently active text)
    const isReplaceableTextChild = (child) => {
        if (!child || child.className !== 'Text') return false;
        const name = child.attrs && child.attrs.name;
        return name === 'description' || name === 'complexTextValue';
    };

    // Open the find/replace dialog. Pre-fill "find" with selection's first text if all selected texts are identical.
    const openTextReplaceDialog = () => {
        const ids = Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0
            ? selectedIdsRef.current
            : (selectedIdRef.current ? [selectedIdRef.current] : []);
        const candidates = imagesRef.current.filter((shape) => ids.includes(shape.id));
        const eligible = candidates.filter((shape) => {
            const children = shape && shape.moduleJson && shape.moduleJson.children;
            return Array.isArray(children) && children.some(isReplaceableTextChild);
        });
        if (eligible.length === 0) {
            message.warning(t('textReplace.noTargets'));
            return;
        }
        // Pre-fill: collect distinct visible texts; if exactly one distinct value, default "find" to it.
        const seen = new Set();
        eligible.forEach((shape) => {
            shape.moduleJson.children.forEach((child) => {
                if (!isReplaceableTextChild(child)) return;
                const raw = child.attrs && child.attrs.text;
                if (typeof raw === 'string') seen.add(raw);
            });
        });
        if (seen.size === 1) {
            setTextReplaceFind(Array.from(seen)[0] || '');
        }
        setTextReplaceTo('');
        setTextReplaceBox(true);
    };

    // Apply find/replace to the visible "text" attr of every replaceable child within the selected shapes.
    // Returns the number of child text nodes that actually changed.
    const applyTextReplace = (findStr, replaceStr) => {
        if (typeof findStr !== 'string' || findStr.length === 0) return 0;
        const ids = Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0
            ? selectedIdsRef.current
            : (selectedIdRef.current ? [selectedIdRef.current] : []);
        if (ids.length === 0) return 0;

        let changed = 0;
        const next = imagesRef.current.map((shape) => {
            if (!ids.includes(shape.id)) return shape;
            const children = shape && shape.moduleJson && shape.moduleJson.children;
            if (!Array.isArray(children)) return shape;
            let touched = false;
            const newChildren = children.map((child) => {
                if (!isReplaceableTextChild(child)) return child;
                const raw = child.attrs && child.attrs.text;
                if (typeof raw !== 'string' || raw.indexOf(findStr) === -1) return child;
                touched = true;
                changed += 1;
                // Plain string split / join — no regex, so user input never doubles as a pattern.
                const updated = raw.split(findStr).join(replaceStr || '');
                return {
                    ...child,
                    attrs: { ...child.attrs, text: updated },
                };
            });
            if (!touched) return shape;
            return {
                ...shape,
                moduleJson: { ...shape.moduleJson, children: newChildren },
            };
        });
        if (changed === 0) return 0;
        imagesRef.current = next;
        setImages(next);
        history.push(JSON.parse(JSON.stringify(next)));
        return changed;
    };
    // ----------------------------------------------------------------------------

    useEffect(() => {
        // \u5173\u95ed\u7f51\u9875 / \u5237\u65b0 / \u8df3\u8f6c\u5916\u90e8\u94fe\u63a5\u524d，\u5982\u679c\u8fd8\u6709\u672a\u4fdd\u5b58\u6539\u52a8\u5219\u5f39\u539f\u751f\u63d0\u793a
        // \u6ce8\u610f：\u73b0\u4ee3\u6d4f\u89c8\u5668\u51fa\u4e8e\u5b89\u5168\u8003\u8651\u53ea\u663e\u793a\u56fa\u5b9a\u6587\u6848（"\u79bb\u5f00\u6b64\u9875\u9762? / \u7cfb\u7edf\u53ef\u80fd\u4e0d\u4f1a\u4fdd\u5b58\u60a8\u6240\u505a\u7684\u66f4\u6539"），
        // returnValue \u7684\u5b57\u7b26\u4e32\u5185\u5bb9\u4e0d\u4f1a\u88ab\u4f7f\u7528，\u4f46\u5fc5\u987b\u8bbe\u7f6e\u624d\u80fd\u89e6\u53d1\u5bf9\u8bdd\u6846。
        // Chrome / Edge / Firefox \u90fd\u8981\u6c42 preventDefault() + returnValue \u53cc\u91cd\u4fdd\u9669。
        // \u5173\u952e：\u7528 ref \u8bfb\u53d6\u6700\u65b0\u7684 savePageId / savePageType / savePageTxt，\u907f\u514d useEffect [] \u95ed\u5305\u9648\u65e7
        // \u5bfc\u81f4\u5207\u5230\u65b0\u9875\u9762\u540e\u6539\u4e1c\u897f\u5173\u7f51\u9875\u4e0d\u5f39\u63d0\u793a。
        const handleBeforeUnload = (e) => {
            if (isPreview) return;                                                     // \u9884\u89c8\u6a21\u5f0f\u4e0d\u62e6\u622a
            if (!dirtyRef.current) return;                                             // \u6ca1\u6709\u672a\u4fdd\u5b58\u6539\u52a8\u4e0d\u62e6\u622a
            const curPageId = savePageIdRef.current;
            const curPageType = savePageTypeRef.current;
            const curPageTxt = savePageTxtRef.current;
            if (!curPageId || curPageId === '0') return;                               // \u8349\u7a3f\u9875\u672a\u65b0\u5efa\u8fc7\u4e0d\u62e6\u622a
            if (curPageType !== '1' || !curPageTxt) return;                            // \u975e PageType=1 \u4e0d\u62e6\u622a
            e.preventDefault();
            e.returnValue = t('designer.leaveUnsaved');
            return e.returnValue;
        };
        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            if (saveStatusTimerRef.current) {
                clearTimeout(saveStatusTimerRef.current);
            }
        };
    }, []);

    useEffect(() => {
        if (!savePageId || savePageId === '0') return;
        setshowIndex(1);
        setTabFlash('component');
        const timer = setTimeout(() => {
            setTabFlash('');
        }, 650);
        return () => clearTimeout(timer);
    }, [savePageId]);

    const initialImagesSnapshotRef = useRef(false);
    const saveStatusTextRef = useRef(savedStatus);
    // \u7528 ref \u8ddf\u8e2a savePageId / savePageType / savePageTxt \u7684\u6700\u65b0\u503c，
    // \u8ba9 useEffect [] \u5185\u7684 beforeunload \u76d1\u542c\u4e0d\u4f1a\u88ab\u521d\u59cb\u6302\u8f7d\u65f6\u7684\u95ed\u5305\u51bb\u7ed3
    const savePageIdRef = useRef(savePageId);
    const savePageTypeRef = useRef(savePageType);
    const savePageTxtRef = useRef(savePageTxt);
    useEffect(() => { savePageIdRef.current = savePageId; }, [savePageId]);
    useEffect(() => { savePageTypeRef.current = savePageType; }, [savePageType]);
    useEffect(() => { savePageTxtRef.current = savePageTxt; }, [savePageTxt]);
    useEffect(() => {
        saveStatusTextRef.current = saveStatusText;
    }, [saveStatusText]);
    useEffect(() => {
        if (!initialImagesSnapshotRef.current) {
            initialImagesSnapshotRef.current = true;
            return;
        }
        // User made changes -> mark dirty (used by save-and-switch confirmation and beforeunload guard).
        dirtyRef.current = true;
        if (saveStatusTextRef.current !== modifiedStatus) {
            if (saveStatusTimerRef.current) {
                clearTimeout(saveStatusTimerRef.current);
                saveStatusTimerRef.current = null;
            }
            setSaveStatusText(modifiedStatus);
        }
    }, [images, backgroundImage]);

    // Comment translated to English.
    const getevData = async (callback) => {
        let res = await httpsend.getData('GetDeviceListKey', {
            ComboBox: "all"
        });
        let devList = [];
        if (res && Array.isArray(res.data)) {
            res.data.forEach((val, n) => {
                devList.push({
                    value: val.id,
                    label: val.DeviceName,
                    code: val.ProtocolCode,
                    codeName: val.ProtocolName,
                    onlyCode: useSlaveId ? val.SlaveID : '',// Comment translated to English.
                })
            })
            callback(devList)
        }
    }
    useEffect(() => {
        if (resetBox) {
            getevData(function (devList) {
                let pagedev = [];
                // F24 scope filter: if a selection scope was captured when the dialog opened,
                // only walk those shapes; otherwise scan the whole canvas (legacy behaviour).
                const scopeIds = resetScopeIdsRef.current;
                const sourceShapes = (Array.isArray(scopeIds) && scopeIds.length > 0)
                    ? imagesRef.current.filter((shape) => scopeIds.includes(shape.id))
                    : imagesRef.current;
                sourceShapes.forEach(shapeProps => {
                    if (shapeProps.moduleJson && shapeProps.moduleJson.attrs.dataKey) {
                        let dataKey = shapeProps.moduleJson.attrs.dataKey;
                        if (dataKey && dataKey.length === 1) {// Comment translated to English.
                            dataKey.forEach((el) => {
                                // Comment translated to English.
                                if (el.key || el.deveventskey) {
                                    const currentKey = el.key || el.deveventskey;
                                    const currentKeyStr = String(currentKey);
                                    // Comment translated to English.
                                    let findpagedevindex = pagedev.findIndex(v => String(v.value) === currentKeyStr)
                                    if (findpagedevindex === -1) {
                                        // Comment translated to English.
                                        // Comment translated to English.
                                        let finddevindex = devList.findIndex(v => String(v.value) === currentKeyStr)
                                        if (finddevindex === -1) {
                                            // Comment translated to English.
                                            let finddevonlyindex = useSlaveId ? devList.findIndex(v => String(v.onlyCode) === currentKeyStr) : -1
                                            if (finddevonlyindex !== -1) {
                                                pagedev.push({
                                                    value: devList[finddevonlyindex]['onlyCode'],
                                                    label: devList[finddevonlyindex]['label'],
                                                    code: devList[finddevonlyindex]['code'],
                                                    codeName: devList[finddevonlyindex]['codeName'],
                                                    // children: devList.filter(v => (v.codeName === devList[finddevindex]['codeName']))
                                                    children: devList
                                                })
                                            }
                                        } else {
                                            pagedev.push({
                                                value: currentKey,
                                                label: devList[finddevindex]['label'],
                                                code: devList[finddevindex]['code'],
                                                codeName: devList[finddevindex]['codeName'],
                                                // children: devList.filter(v => (v.codeName === devList[finddevindex]['codeName']))
                                                // children: devList.filter(v => (v.value !== devList[finddevindex]['value']))
                                                children: devList
                                            })
                                        }
                                    }
                                }
                            })
                        }
                    }
                })
                // console.log(pagedev)
                setpagedevList(pagedev);
            });
        } else {
            setpagedevList([]);// Comment translated to English.
        }
    }, [resetBox, useSlaveId]);
    const ondataDevOptionSearch = (value) => { };
    const filterOption = (input, option) => (option && option.label).toLowerCase().includes(input.toLowerCase());
    // Comment translated to English.
    // Comment translated to English.
    // const [showUrlBox, setshowUrlBox] = useState(false);
    // const [showUrl, setshowUrl] = useState();

    // F20 Ctrl+wheel zoom: mirror latest values via refs so the native wheel listener never reads stale closures.
    const stageDimensionsRef = useRef(stageDimensions);
    useEffect(() => { stageDimensionsRef.current = stageDimensions; }, [stageDimensions]);
    const canvasScaleRef = useRef(canvasScale);
    useEffect(() => { canvasScaleRef.current = canvasScale; }, [canvasScale]);

    const getBoundedDragPosition = (metrics, x, y) => {
        if (!metrics) {
            return { x, y };
        }
        const maxX = Math.max(0, stageWidth - metrics.width);
        const maxY = Math.max(0, stageHeight - metrics.height);
        return {
            x: Math.min(Math.max(0, x), maxX),
            y: Math.min(Math.max(0, y), maxY),
        };
    };

    const getBoundedTransformerBox = (oldBox, newBox) => {
        if (!newBox) return oldBox;
        if (newBox.width < 5 || newBox.height < 5) {
            return oldBox;
        }
        let nextBox = { ...newBox };
        if (nextBox.x < 0) {
            nextBox.width += nextBox.x;
            nextBox.x = 0;
        }
        if (nextBox.y < 0) {
            nextBox.height += nextBox.y;
            nextBox.y = 0;
        }
        if (nextBox.x + nextBox.width > stageWidth) {
            nextBox.width = stageWidth - nextBox.x;
        }
        if (nextBox.y + nextBox.height > stageHeight) {
            nextBox.height = stageHeight - nextBox.y;
        }
        if (nextBox.width < 5 || nextBox.height < 5) {
            return oldBox;
        }
        return nextBox;
    };

    // F6 \u591a\u9009\u62d6\u52a8：\u8ddf\u968f\u79fb\u52a8 / \u63d0\u4ea4 / \u6846\u9009\u6269\u5c55（F7 \u65f6\u589e\u5f3a\u4e3a\u7ec4\u5408\u6269\u5c55）
    const multiDragRef = useRef({
        active: false,
        draggedId: null,
        startPositions: {},
        pendingPositions: null,
    });
    // \u591a\u9009/\u7ec4\u5408\u62d6\u52a8 dragend \u9636\u6bb5，Konva \u4f1a\u8ba9\u6240\u6709 nodes \u5404\u89e6\u53d1\u4e00\u6b21 onChange：
    //   - \u88ab\u62d6\u5143\u7d20 (draggedId) \u8d70 commitMultiDragPositions（push 1 \u6b21 history）
    //   - \u5176\u4f59\u8ddf\u968f\u6210\u5458\u82e5\u4e5f\u8d70 handleShapeChange \u53c8\u4f1a\u5404 push 1 \u6b21 history → \u64a4\u9500\u8981\u6309 N \u6b21
    // \u7528\u8fd9\u4e2a ref \u5728 dragstart \u65f6\u8bb0\u5f55"\u975e\u88ab\u62d6\u7684\u8ddf\u968f\u6210\u5458 id"，dragend \u65f6\u8fd9\u4e9b id \u7684 onChange \u76f4\u63a5 return \u5e76\u81ea\u79fb\u9664
    const pendingDragFollowerIdsRef = useRef(new Set());

    // F7 \u7ec4\u5408：\u57fa\u4e8e shape.groupId \u7ef4\u62a4\u903b\u8f91\u7ec4
    const getShapeGroupId = (shapeOrId) => {
        const shape = typeof shapeOrId === 'string'
            ? imagesRef.current.find((item) => item.id === shapeOrId)
            : shapeOrId;
        return shape && shape.groupId ? shape.groupId : '';
    };

    const getGroupMemberIds = (groupId) => {
        if (!groupId) return [];
        return imagesRef.current.filter((item) => item.groupId === groupId).map((item) => item.id);
    };

    const getExpandedSelectionIds = (shapeOrId) => {
        const shape = typeof shapeOrId === 'string'
            ? imagesRef.current.find((item) => item.id === shapeOrId)
            : shapeOrId;
        if (!shape) return [];
        if (!shape.groupId) return [shape.id];
        const memberIds = getGroupMemberIds(shape.groupId);
        return memberIds.length > 0 ? memberIds : [shape.id];
    };

    const createDerivedGroupId = (sourceGroupId) => {
        if (!sourceGroupId) return null;
        return `group_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    };

    // \u7ec4\u5408\u6269\u5c55：\u62d6\u52a8 / \u9009\u62e9\u67d0\u6210\u5458\u65f6\u628a\u540c\u7ec4\u6210\u5458\u4e00\u5e76\u7eb3\u5165（\u6392\u9664\u9501\u5b9a\u5143\u7d20）
    const expandDragSelectionIds = (ids, draggedShapeId) => {
        const result = new Set();
        const baseIds = Array.isArray(ids) ? ids : [];
        const mergedIds = [...new Set([...(draggedShapeId ? [draggedShapeId] : []), ...baseIds])];
        mergedIds.forEach((id) => {
            getUnlockedExpandedSelectionIds(id).forEach((memberId) => result.add(memberId));
        });
        return Array.from(result);
    };

    const groupSelectedShapes = () => {
        if (!Array.isArray(selectedIdsRef.current) || selectedIdsRef.current.length < 2) return;
        const groupId = `group_${Date.now()}`;
        const nextImages = imagesRef.current.map((shape) => (
            selectedIdsRef.current.includes(shape.id)
                ? { ...shape, groupId }
                : shape
        ));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
    };

    const isSelectionSingleGroup = () => {
        if (!Array.isArray(selectedIdsRef.current) || selectedIdsRef.current.length === 0) return false;
        const groupIds = [...new Set(selectedIdsRef.current.map((id) => getShapeGroupId(id)).filter(Boolean))];
        return groupIds.length === 1 && selectedIdsRef.current.every((id) => getShapeGroupId(id) === groupIds[0]);
    };

    const ungroupSelectedShapes = () => {
        if (!isSelectionSingleGroup()) return;
        const groupId = getShapeGroupId(selectedIdsRef.current[0]);
        const memberIds = getGroupMemberIds(groupId);
        const nextImages = imagesRef.current.map((shape) => (
            memberIds.includes(shape.id)
                ? { ...shape, groupId: null }
                : shape
        ));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes(memberIds);
        selectedIdsRef.current = memberIds;
    };

    // F2 \u9501\u5b9a\u4fdd\u62a4：\u57fa\u4e8e shape.draggable === false \u6807\u8bb0\u9501\u5b9a
    const isShapeUnlocked = (shapeOrId) => {
        const shape = typeof shapeOrId === 'string'
            ? imagesRef.current.find((item) => item.id === shapeOrId)
            : shapeOrId;
        return !!(shape && shape.draggable !== false);
    };

    const getUnlockedSelectedIds = () => {
        if (!Array.isArray(selectedIdsRef.current) || selectedIdsRef.current.length === 0) return [];
        return selectedIdsRef.current.filter((id) => isShapeUnlocked(id));
    };

    const getUnlockedExpandedSelectionIds = (shapeOrId) => {
        return getExpandedSelectionIds(shapeOrId).filter((id) => isShapeUnlocked(id));
    };

    const lockSelectedShapes = () => {
        const unlockedSelectedIds = getUnlockedSelectedIds();
        const targetIds = unlockedSelectedIds.length > 0
            ? unlockedSelectedIds
            : (selectedIdRef.current !== null && isShapeUnlocked(selectedIdRef.current) ? [selectedIdRef.current] : []);
        if (targetIds.length === 0) return;
        const nextImages = imagesRef.current.map((shape) => (
            targetIds.includes(shape.id) ? { ...shape, draggable: false } : shape
        ));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes([]);
        selectedIdsRef.current = [];
        setSelectedId(null);
        selectedIdRef.current = null;
        setDragShape(null);
    };

    const unlockSelectedShapes = () => {
        let lockedSelectedIds = [];
        if (Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0) {
            lockedSelectedIds = selectedIdsRef.current.filter((id) => !isShapeUnlocked(id));
        } else if (selectedIdRef.current !== null && !isShapeUnlocked(selectedIdRef.current)) {
            lockedSelectedIds = [selectedIdRef.current];
        } else {
            lockedSelectedIds = imagesRef.current.filter((shape) => shape.draggable === false).map((shape) => shape.id);
        }
        if (lockedSelectedIds.length === 0) return;
        const nextImages = imagesRef.current.map((shape) => (
            lockedSelectedIds.includes(shape.id) ? { ...shape, draggable: true } : shape
        ));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes([]);
        selectedIdsRef.current = [];
        setSelectedId(null);
        selectedIdRef.current = null;
        setDragShape(null);
    };

    const canGroupSelection = selectedIds.length >= 2;
    const canUngroupSelection = isSelectionSingleGroup();

    // \u5bf9\u9f50\u951a\u70b9：\u7528\u6237\u6700\u5148\u9009\u4e2d\u7684\u5143\u7d20 / \u7ec4\u5408，\u5bf9\u9f50\u65f6\u951a\u70b9\u4e0d\u52a8，\u5176\u4ed6 unit \u5411\u951a\u70b9\u9760\u62e2。
    // - \u5355\u9009 / 0 \u9009：\u65e0\u951a\u70b9（\u7a7a\u96c6），UI \u4e0d\u753b\u7d2b\u8272\u9ad8\u4eae
    // - \u591a\u9009：selectedIds[0] \u6240\u5728\u7684\u6574\u4e2a group（\u82e5\u65e0 groupId \u5219\u5c31\u8fd9\u4e00\u4e2a id）
    const alignmentAnchorIds = useMemo(() => {
        if (!Array.isArray(selectedIds) || selectedIds.length < 2) return [];
        const anchorId = selectedIds[0];
        if (!anchorId) return [];
        const anchorShape = imagesRef.current.find((s) => s.id === anchorId);
        if (!anchorShape) return [];
        const anchorGroupId = getShapeGroupId(anchorShape);
        if (anchorGroupId) {
            // \u6574\u7ec4\u90fd\u7b97\u951a\u70b9：\u9501\u5b9a\u6210\u5458\u4e5f\u6d82\u7d2b，\u4fbf\u4e8e\u7528\u6237\u76f4\u89c2\u770b\u5230\u54ea\u4e2a unit \u4e0d\u4f1a\u52a8
            return selectedIds.filter((id) => getShapeGroupId(id) === anchorGroupId);
        }
        return [anchorId];
    }, [selectedIds, images]);

    // F13 \u754c\u9762\u7ed3\u6784\u6811 + hover \u9ad8\u4eae
    const getStructureItemLabel = (shape, index = 0) => {
        if (!shape || !shape.moduleJson) return `${t('designer.element')} ${index + 1}`;
        const firstChild = shape.moduleJson.children && shape.moduleJson.children[0] ? shape.moduleJson.children[0] : null;
        const attrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
        return attrs.text || attrs.name || (firstChild && firstChild.className) || `${t('designer.element')} ${index + 1}`;
    };

    const getInterfaceStructure = () => {
        const groupMap = {};
        const singles = [];
        images.forEach((shape, index) => {
            const item = {
                id: shape.id,
                label: getStructureItemLabel(shape, index),
                groupId: shape.groupId || '',
            };
            if (shape.groupId) {
                if (!groupMap[shape.groupId]) {
                    groupMap[shape.groupId] = {
                        groupId: shape.groupId,
                        label: `${t('designer.groupPrefix')} ${Object.keys(groupMap).length + 1}`,
                        members: [],
                    };
                }
                groupMap[shape.groupId].members.push(item);
            } else {
                singles.push(item);
            }
        });
        return { singles, groups: Object.values(groupMap) };
    };

    const selectStructureTarget = (shapeId, useGroupSelection = true) => {
        const shape = imagesRef.current.find((item) => item.id === shapeId);
        if (!shape) return;
        const targetIds = useGroupSelection ? getExpandedSelectionIds(shapeId) : [shapeId];
        if (targetIds.length > 1) {
            selectShapes(targetIds);
            selectedIdsRef.current = targetIds;
        } else {
            selectShapes([]);
            selectedIdsRef.current = [];
        }
        setSelectedId(shapeId);
        selectedIdRef.current = shapeId;
        setDragShape(shape);
    };

    const scrollToStructureTarget = (shapeId, useGroupSelection = true) => {
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const scroller = containerRef.current ? containerRef.current.querySelector('.canvasStage') : null;
        if (!stage || !scroller) return;
        const targetIds = useGroupSelection ? getExpandedSelectionIds(shapeId) : [shapeId];
        const rects = targetIds
            .map((id) => stage.findOne('#' + id))
            .filter(Boolean)
            .map((node) => node.getClientRect());
        if (rects.length === 0) return;
        const left = Math.min(...rects.map((rect) => rect.x));
        const top = Math.min(...rects.map((rect) => rect.y));
        const right = Math.max(...rects.map((rect) => rect.x + rect.width));
        const bottom = Math.max(...rects.map((rect) => rect.y + rect.height));
        const padding = 40;
        const targetScrollLeft = Math.max(0, left - padding);
        const targetScrollTop = Math.max(0, top - padding);
        if (left < scroller.scrollLeft || right > scroller.scrollLeft + scroller.clientWidth) {
            scroller.scrollLeft = targetScrollLeft;
        }
        if (top < scroller.scrollTop || bottom > scroller.scrollTop + scroller.clientHeight) {
            scroller.scrollTop = targetScrollTop;
        }
    };

    const handleStructureItemClick = (shapeId, useGroupSelection = true) => {
        if (!shapeId) return;
        selectStructureTarget(shapeId, useGroupSelection);
        scrollToStructureTarget(shapeId, useGroupSelection);
        setshowIndex(1);
        setTabFlash('component');
        setTimeout(() => setTabFlash(''), 650);
    };

    // F8 \u526a\u8d34\u677f：copy / cut / paste（\u7cbe\u7b80\u7248：\u5c1a\u672a\u5f15\u5165\u9501\u5b9a\u4fdd\u62a4）
    const getClipboardSelectionShapes = () => {
        let targetIds = [];
        if (Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0) {
            targetIds = expandDragSelectionIds(selectedIdsRef.current, null);
        } else if (selectedIdRef.current !== null) {
            targetIds = getExpandedSelectionIds(selectedIdRef.current);
        }
        if (!Array.isArray(targetIds) || targetIds.length === 0) return [];
        return imagesRef.current.filter((shape) => targetIds.includes(shape.id));
    };

    const writeClipboard = (shapes) => {
        const payload = {
            type: 'page-elements',
            copiedAt: Date.now(),
            sourcePageId: savePageId,
            sourcePageToken: currentPageTokenRef.current,  // \u5373\u4f7f savePageId \u90fd\u662f '0'，token \u4e5f\u80fd\u533a\u5206\u8349\u7a3f\u9875
            elements: JSON.parse(JSON.stringify(shapes || [])),
        };
        try { localStorage.setItem(PAGE_DESIGNER_CLIPBOARD_KEY, JSON.stringify(payload)); } catch (e) { }
    };

    const readClipboard = () => {
        try {
            const raw = localStorage.getItem(PAGE_DESIGNER_CLIPBOARD_KEY);
            if (!raw) return null;
            const payload = JSON.parse(raw);
            if (!payload || payload.type !== 'page-elements' || !Array.isArray(payload.elements)) return null;
            return payload;
        } catch (error) {
            return null;
        }
    };

    // \u7b80\u5355 bounds：F1 \u843d\u4f4d\u540e\u4f1a\u6362\u6210\u57fa\u4e8e Konva node \u7684 metrics
    const getClipboardBoundsSimple = (elements) => {
        if (!Array.isArray(elements) || elements.length === 0) return null;
        const xs = []; const ys = []; const xs2 = []; const ys2 = [];
        elements.forEach((s) => {
            const x = Number(s.x || 0);
            const y = Number(s.y || 0);
            const w = Number((s.moduleJson && s.moduleJson.width) || s.width || 0);
            const h = Number((s.moduleJson && s.moduleJson.height) || s.height || 0);
            xs.push(x); ys.push(y); xs2.push(x + w); ys2.push(y + h);
        });
        const left = Math.min(...xs);
        const top = Math.min(...ys);
        const right = Math.max(...xs2);
        const bottom = Math.max(...ys2);
        return { left, top, right, bottom, centerX: (left + right) / 2, centerY: (top + bottom) / 2 };
    };

    const getViewportCenterOnCanvas = () => {
        const scroller = containerRef.current ? containerRef.current.querySelector('.canvasStage') : null;
        if (!scroller) return { x: stageWidth / 2, y: stageHeight / 2 };
        const scrollLeft = scroller.scrollLeft || 0;
        const scrollTop = scroller.scrollTop || 0;
        const centerX = (scrollLeft + scroller.clientWidth / 2) / (stageDimensions.scalex || 1);
        const centerY = (scrollTop + scroller.clientHeight / 2) / (stageDimensions.scaley || 1);
        return { x: centerX, y: centerY };
    };

    const copySelectionToClipboard = () => {
        // \u5173\u952e：\u5148\u628a Konva \u8282\u70b9\u7684\u771f\u5b9e\u4f4d\u7f6e\u540c\u6b65\u56de imagesRef，\u907f\u514d\u62d6\u52a8 / \u6d6e\u70b9\u7d2f\u79ef\u504f\u5dee\u5bfc\u81f4\u590d\u5236\u4f53\u76f8\u5bf9\u4f4d\u7f6e\u9519\u4e71
        syncKonvaPositionsToImagesRef();
        const selectionShapes = getClipboardSelectionShapes();
        if (selectionShapes.length === 0) {
            message.warning(t('designer.noCopySelection'));
            return;
        }
        writeClipboard(selectionShapes);
        message.success(t('designer.copiedSelection').replace('{count}', selectionShapes.length));
    };

    const cutSelectionToClipboard = () => {
        // \u5173\u952e：\u5148\u628a Konva \u8282\u70b9\u7684\u771f\u5b9e\u4f4d\u7f6e\u540c\u6b65\u56de imagesRef，\u907f\u514d\u526a\u5207\u540e\u7c98\u8d34\u4f4d\u7f6e\u9519\u4e71
        syncKonvaPositionsToImagesRef();
        const selectionShapes = getClipboardSelectionShapes();
        if (selectionShapes.length === 0) return;
        writeClipboard(selectionShapes);
        const deleteIds = new Set(selectionShapes.map((shape) => shape.id));
        const nextImages = imagesRef.current.filter((shape) => !deleteIds.has(shape.id));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes([]);
        selectedIdsRef.current = [];
        setSelectedId(null);
        selectedIdRef.current = null;
        setDragShape(null);
        settoolType(null);
        message.success(t('designer.cutSelection').replace('{count}', selectionShapes.length));
    };

    const pasteClipboardSelection = () => {
        const payload = readClipboard();
        if (!payload || !payload.elements || payload.elements.length === 0) {
            message.warning(t('designer.noPasteContent'));
            return;
        }
        const groupIdMap = {};
        const pastedIds = [];
        const nextImages = JSON.parse(JSON.stringify(imagesRef.current));
        const clipboardBounds = getClipboardBoundsSimple(payload.elements);
        const viewportCenter = getViewportCenterOnCanvas();
        // \u8de8\u9875\u9762\u7c98\u8d34\u65f6\u4fdd\u6301\u539f\u59cb\u4f4d\u7f6e（\u4e0d\u504f\u79fb），\u540c\u9875\u9762\u5185\u7c98\u8d34\u624d\u504f\u79fb\u5230\u89c6\u53e3\u4e2d\u5fc3
        // \u4f18\u5148\u7528 token \u5224（\u8349\u7a3f\u9875 savePageId \u90fd\u662f '0' \u65e0\u6cd5\u533a\u5206），fallback \u5230 savePageId
        let isCrossPagePaste = false;
        if (payload.sourcePageToken && payload.sourcePageToken !== currentPageTokenRef.current) {
            isCrossPagePaste = true;
        } else if (!payload.sourcePageToken && payload.sourcePageId && String(payload.sourcePageId) !== String(savePageId)) {
            // \u517c\u5bb9\u65e7\u7684 localStorage \u6570\u636e（\u6ca1\u6709 token）
            isCrossPagePaste = true;
        }
        const offsetX = isCrossPagePaste ? 0 : (clipboardBounds ? (viewportCenter.x - clipboardBounds.centerX + 8) : 8);
        const offsetY = isCrossPagePaste ? 0 : (clipboardBounds ? (viewportCenter.y - clipboardBounds.centerY + 8) : 8);

        payload.elements.forEach((shape, index) => {
            const newId = `${Date.now()}_${index}_${Math.random().toString(36).slice(2, 6)}`;
            let nextGroupId = shape.groupId || null;
            if (shape.groupId) {
                if (!groupIdMap[shape.groupId]) {
                    groupIdMap[shape.groupId] = createDerivedGroupId(shape.groupId);
                }
                nextGroupId = groupIdMap[shape.groupId];
            }
            const nextShape = {
                ...shape,
                id: newId,
                x: Number(shape.x || 0) + offsetX,
                y: Number(shape.y || 0) + offsetY,
                groupId: nextGroupId,
            };
            pastedIds.push(newId);
            nextImages.push(nextShape);
        });

        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes(pastedIds);
        selectedIdsRef.current = pastedIds;
        if (pastedIds.length > 0) {
            setSelectedId(pastedIds[0]);
            selectedIdRef.current = pastedIds[0];
            const pastedShape = nextImages.find((shape) => shape.id === pastedIds[0]);
            setDragShape(pastedShape || null);
        }
        message.success(t('designer.pastedSelection').replace('{count}', pastedIds.length));
    };

    // F1 \u78c1\u5438 + \u53c2\u8003\u7ebf
    const [snapEnabled, setSnapEnabled] = useState(true);
    const [snapThreshold, setSnapThreshold] = useState(6);
    // \u5f15\u5bfc\u7ebf\u7528 Konva \u8282\u70b9 ref + imperative \u66f4\u65b0（\u907f\u514d setState \u89e6\u53d1 React \u91cd\u6e32\u67d3\u628a\u591a\u9009\u62d6\u52a8\u7684 Konva \u7ec4\u5458\u8282\u70b9\u56de\u62c9）
    const snapGuideVRef = useRef(null);
    const snapGuideHRef = useRef(null);
    const updateSnapGuides = (guideData) => {
        const v = snapGuideVRef.current;
        const h = snapGuideHRef.current;
        if (v) {
            const g = guideData && guideData.vertical;
            if (g) {
                v.setAttrs({
                    visible: true,
                    points: [g.x, g.y1, g.x, g.y2],
                    stroke: g.isStageGuide ? '#fa8c16' : '#148cf1',
                    strokeWidth: g.isStageGuide ? 2 : 1,
                    dash: g.isStageGuide ? [10, 6] : [6, 4],
                    shadowColor: g.isStageGuide ? '#fa8c16' : '#148cf1',
                    shadowBlur: g.isStageGuide ? 4 : 2,
                });
            } else {
                v.setAttrs({ visible: false });
            }
            const layer = v.getLayer && v.getLayer();
            if (layer) layer.batchDraw();
        }
        if (h) {
            const g = guideData && guideData.horizontal;
            if (g) {
                h.setAttrs({
                    visible: true,
                    points: [g.x1, g.y, g.x2, g.y],
                    stroke: g.isStageGuide ? '#fa8c16' : '#148cf1',
                    strokeWidth: g.isStageGuide ? 2 : 1,
                    dash: g.isStageGuide ? [10, 6] : [6, 4],
                    shadowColor: g.isStageGuide ? '#fa8c16' : '#148cf1',
                    shadowBlur: g.isStageGuide ? 4 : 2,
                });
            } else {
                h.setAttrs({ visible: false });
            }
            const layer = h.getLayer && h.getLayer();
            if (layer) layer.batchDraw();
        }
    };
    const clearSnapGuides = () => updateSnapGuides(null);

    const getShapeRenderMetrics = (shape, stageNode) => {
        if (!shape || !shape.moduleJson || !shape.moduleJson.children || shape.moduleJson.children.length === 0) return null;
        // Prefer the real rendered bbox from Konva when the live node is available.
        // The legacy attr-derived path below misses width/height for many shape types
        // (charts / data-text / custom components keep size in nested children attrs),
        // which made alginvertical/algincenter behave like left/top alignment.
        if (stageNode && typeof stageNode.getClientRect === 'function') {
            try {
                const stage = typeof stageNode.getStage === 'function' ? stageNode.getStage() : null;
                const rect = stageNode.getClientRect({
                    relativeTo: stage || undefined,
                    skipShadow: true,
                    skipStroke: false,
                });
                if (rect && rect.width > 0 && rect.height > 0) {
                    return {
                        x: rect.x, y: rect.y,
                        width: rect.width, height: rect.height,
                        left: rect.x, centerX: rect.x + rect.width / 2, right: rect.x + rect.width,
                        top: rect.y, centerY: rect.y + rect.height / 2, bottom: rect.y + rect.height,
                    };
                }
            } catch (e) {
                // fall through to attr-based fallback
            }
        }
        const groupAttr = shape.moduleJson.children[0].attrs || {};
        const groupName = groupAttr.name;
        let width = shape.width || shape.moduleJson.width || 0;
        let height = shape.height || shape.moduleJson.height || 0;
        let borderWidth = Number(groupAttr.strokeWidth || 0);
        if (groupName === 'rectBackground' && shape.moduleJson.children[3] && shape.moduleJson.children[3].attrs) {
            const rectAttrs = shape.moduleJson.children[3].attrs;
            width = rectAttrs.width || width;
            height = rectAttrs.height || height;
            borderWidth = Number(rectAttrs.strokeWidth || borderWidth || 0);
        } else {
            if (groupAttr.width) width = groupAttr.width;
            if (groupAttr.height) height = groupAttr.height;
        }
        const scaleX = shape.scaleX || 1;
        const scaleY = shape.scaleY || 1;
        let actualWidth = width * scaleX;
        let actualHeight = height * scaleY;
        if (groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') {
            actualWidth += borderWidth * scaleX;
            actualHeight += borderWidth * scaleY;
        }
        const x = Number(shape.x || 0);
        const y = Number(shape.y || 0);
        return {
            x, y,
            width: actualWidth, height: actualHeight,
            left: x, centerX: x + actualWidth / 2, right: x + actualWidth,
            top: y, centerY: y + actualHeight / 2, bottom: y + actualHeight,
        };
    };

    const buildStageGuideCandidates = () => ({
        vertical: [0, stageWidth / 2, stageWidth],
        horizontal: [0, stageHeight / 2, stageHeight],
    });

    const buildGroupMetricsFromIds = (ids, positionMap = null) => {
        if (!Array.isArray(ids) || ids.length === 0) return null;
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const metricsList = ids.map((id) => {
            const shape = imagesRef.current.find((item) => item.id === id);
            if (!shape) return null;
            const stageNode = stage ? stage.findOne('#' + id) : null;
            const positionOverride = positionMap && positionMap[id] ? positionMap[id] : null;
            return getShapeRenderMetrics(
                positionOverride ? { ...shape, x: positionOverride.x, y: positionOverride.y } : shape,
                stageNode,
            );
        }).filter(Boolean);
        if (metricsList.length === 0) return null;
        const left = Math.min(...metricsList.map((item) => item.left));
        const top = Math.min(...metricsList.map((item) => item.top));
        const right = Math.max(...metricsList.map((item) => item.right));
        const bottom = Math.max(...metricsList.map((item) => item.bottom));
        return {
            x: left, y: top, left, top, right, bottom,
            width: right - left, height: bottom - top,
            centerX: left + (right - left) / 2, centerY: top + (bottom - top) / 2,
        };
    };

    // \u628a\u9009\u4e2d ids \u6298\u6210"\u5bf9\u9f50\u5355\u5143"\u5217\u8868：\u540c groupId \u7684\u6210\u5458\u5408\u5e76\u6210 1 \u4e2a unit（\u7528\u6574\u7ec4\u5916\u5305\u76d2），
    // \u5355 id \u4f5c\u4e3a\u72ec\u7acb unit。\u8fd9\u6837\u540e\u7eed\u5bf9\u9f50\u6309 unit \u8ba1\u7b97 offset，\u518d\u628a offset \u5e94\u7528\u5230 unit \u5185\u5168\u90e8\u6210\u5458，
    // \u7ec4\u5408\u5c31\u80fd\u4f5c\u4e3a\u6574\u4f53\u53c2\u4e0e\u5bf9\u9f50（\u6210\u5458\u76f8\u5bf9\u4f4d\u7f6e\u4fdd\u6301\u4e0d\u53d8）。
    const getAlignmentUnits = (ids) => {
        if (!Array.isArray(ids) || ids.length === 0) return [];
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const units = [];
        const visited = new Set();
        ids.forEach((id) => {
            if (visited.has(id)) return;
            const shape = imagesRef.current.find((item) => item.id === id);
            if (!shape) return;
            const groupId = getShapeGroupId(shape);
            if (groupId) {
                // \u53d6\u9009\u4e2d ids \u4e2d\u5c5e\u4e8e\u540c\u4e00\u7ec4\u7684\u6240\u6709\u6210\u5458（\u4e0d\u6269\u5c55\u672a\u9009\u6210\u5458，\u4fdd\u6301\u7528\u6237\u610f\u56fe）
                const memberIds = ids.filter((selectedId) => getShapeGroupId(selectedId) === groupId);
                memberIds.forEach((memberId) => visited.add(memberId));
                const metrics = buildGroupMetricsFromIds(memberIds);
                if (metrics) {
                    units.push({ key: groupId, memberIds, metrics, isGroup: true });
                }
                return;
            }
            visited.add(id);
            const stageNode = stage ? stage.findOne('#' + id) : null;
            const metrics = getShapeRenderMetrics(shape, stageNode);
            if (metrics) {
                units.push({ key: id, memberIds: [id], metrics, isGroup: false });
            }
        });
        return units;
    };

    // \u6309 unit \u7c92\u5ea6\u8ba1\u7b97"\u6c34\u5e73\u7b49\u8ddd / \u5782\u76f4\u7b49\u8ddd"\u76ee\u6807\u5750\u6807
    const getDistributedUnitTargets = (units, axis) => {
        if (!Array.isArray(units) || units.length === 0) return {};
        if (units.length === 1) {
            return { [units[0].key]: axis === 'x' ? units[0].metrics.x : units[0].metrics.y };
        }
        const sortedUnits = [...units].sort((a, b) => (
            axis === 'x' ? a.metrics.x - b.metrics.x : a.metrics.y - b.metrics.y
        ));
        const firstUnit = sortedUnits[0];
        const lastUnit = sortedUnits[sortedUnits.length - 1];
        const start = axis === 'x' ? firstUnit.metrics.x : firstUnit.metrics.y;
        const end = axis === 'x'
            ? lastUnit.metrics.x + lastUnit.metrics.width
            : lastUnit.metrics.y + lastUnit.metrics.height;
        const totalSize = sortedUnits.reduce(
            (sum, unit) => sum + (axis === 'x' ? unit.metrics.width : unit.metrics.height),
            0,
        );
        const gap = sortedUnits.length > 1 ? (end - start - totalSize) / (sortedUnits.length - 1) : 0;
        const targets = {};
        let cursor = start;
        sortedUnits.forEach((unit) => {
            targets[unit.key] = cursor;
            cursor += (axis === 'x' ? unit.metrics.width : unit.metrics.height) + gap;
        });
        return targets;
    };

    const buildGuideCandidates = (excludeIds = []) => {
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const excluded = new Set(excludeIds);
        const candidates = { vertical: [], horizontal: [] };
        imagesRef.current.forEach((shape) => {
            if (!shape || excluded.has(shape.id)) return;
            const stageNode = stage ? stage.findOne('#' + shape.id) : null;
            const metrics = getShapeRenderMetrics(shape, stageNode);
            if (!metrics) return;
            candidates.vertical.push({ value: metrics.left, top: metrics.top, bottom: metrics.bottom });
            candidates.vertical.push({ value: metrics.centerX, top: metrics.top, bottom: metrics.bottom });
            candidates.vertical.push({ value: metrics.right, top: metrics.top, bottom: metrics.bottom });
            candidates.horizontal.push({ value: metrics.top, left: metrics.left, right: metrics.right });
            candidates.horizontal.push({ value: metrics.centerY, left: metrics.left, right: metrics.right });
            candidates.horizontal.push({ value: metrics.bottom, left: metrics.left, right: metrics.right });
        });
        const stageGuides = buildStageGuideCandidates();
        stageGuides.vertical.forEach((value) => candidates.vertical.push({ value, top: 0, bottom: stageHeight }));
        stageGuides.horizontal.forEach((value) => candidates.horizontal.push({ value, left: 0, right: stageWidth }));
        return candidates;
    };

    const getBestSnapMatch = (edges, guideCandidates, axis) => {
        let bestMatch = null;
        edges.forEach((edge) => {
            guideCandidates.forEach((guide) => {
                const distance = Math.abs(edge.value - guide.value);
                if (distance > snapThreshold) return;
                if (!bestMatch || distance < bestMatch.distance) {
                    bestMatch = { axis, edge, guide, distance };
                }
            });
        });
        return bestMatch;
    };

    const getSnappedMetrics = (metrics, excludeIds = []) => {
        const candidates = buildGuideCandidates(excludeIds);
        const verticalEdges = [
            { type: 'left', value: metrics.left },
            { type: 'centerX', value: metrics.centerX },
            { type: 'right', value: metrics.right },
        ];
        const horizontalEdges = [
            { type: 'top', value: metrics.top },
            { type: 'centerY', value: metrics.centerY },
            { type: 'bottom', value: metrics.bottom },
        ];
        const matchX = getBestSnapMatch(verticalEdges, candidates.vertical, 'x');
        const matchY = getBestSnapMatch(horizontalEdges, candidates.horizontal, 'y');
        let snappedX = metrics.x;
        let snappedY = metrics.y;
        if (matchX) snappedX += matchX.guide.value - matchX.edge.value;
        if (matchY) snappedY += matchY.guide.value - matchY.edge.value;
        const nextMetrics = {
            ...metrics,
            x: snappedX, y: snappedY,
            left: snappedX, right: snappedX + metrics.width,
            top: snappedY, bottom: snappedY + metrics.height,
            centerX: snappedX + metrics.width / 2,
            centerY: snappedY + metrics.height / 2,
        };
        return { matchX, matchY, snappedMetrics: nextMetrics };
    };

    const buildSnapGuideLine = (snapX, snapY, metrics, matchX, matchY) => ({
        vertical: matchX ? {
            x: snapX,
            y1: Math.min(metrics.top, matchX.guide.top) - SNAP_GUIDE_OFFSET,
            y2: Math.max(metrics.bottom, matchX.guide.bottom) + SNAP_GUIDE_OFFSET,
            isStageGuide: matchX.guide.top === 0 && matchX.guide.bottom === stageHeight,
        } : null,
        horizontal: matchY ? {
            y: snapY,
            x1: Math.min(metrics.left, matchY.guide.left) - SNAP_GUIDE_OFFSET,
            x2: Math.max(metrics.right, matchY.guide.right) + SNAP_GUIDE_OFFSET,
            isStageGuide: matchY.guide.left === 0 && matchY.guide.right === stageWidth,
        } : null,
    });

    const applySnapForShape = (node, shape) => {
        if (!node || !shape) return;
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const stageNode = stage ? stage.findOne('#' + shape.id) : null;
        const metrics = getShapeRenderMetrics({ ...shape, x: node.x(), y: node.y() }, stageNode || node);
        if (!metrics) return;
        // For rotated elements, metrics.x/y comes from getClientRect (axis-aligned bbox top-left)
        // and is NOT equal to node.x()/node.y() (the rotation pivot). We must apply bound/snap
        // corrections as a delta against the node's real position, otherwise the node would be
        // teleported to the bbox top-left every dragmove and the element would jump.
        const nodeX = node.x();
        const nodeY = node.y();
        if (!snapEnabled) {
            const boundedPosition = getBoundedDragPosition(metrics, metrics.x, metrics.y);
            const dx = boundedPosition.x - metrics.x;
            const dy = boundedPosition.y - metrics.y;
            node.position({ x: nodeX + dx, y: nodeY + dy });
            clearSnapGuides();
            return;
        }
        const { matchX, matchY, snappedMetrics } = getSnappedMetrics(metrics, [shape.id]);
        const boundedPosition = getBoundedDragPosition(snappedMetrics, snappedMetrics.x, snappedMetrics.y);
        const boundedMetrics = {
            ...snappedMetrics,
            x: boundedPosition.x, y: boundedPosition.y,
            left: boundedPosition.x, right: boundedPosition.x + snappedMetrics.width,
            top: boundedPosition.y, bottom: boundedPosition.y + snappedMetrics.height,
            centerX: boundedPosition.x + snappedMetrics.width / 2,
            centerY: boundedPosition.y + snappedMetrics.height / 2,
        };
        const dx = boundedMetrics.x - metrics.x;
        const dy = boundedMetrics.y - metrics.y;
        node.position({ x: nodeX + dx, y: nodeY + dy });
        if (matchX || matchY) {
            updateSnapGuides(buildSnapGuideLine(
                matchX ? matchX.guide.value : null,
                matchY ? matchY.guide.value : null,
                boundedMetrics,
                matchX, matchY,
            ));
            return;
        }
        clearSnapGuides();
    };

    // F9 \u591a\u9009\u8fb9\u754c（\u4f9d\u8d56 buildGroupMetricsFromIds）
    const getBoundedMultiDragPositions = (positionMap, ids) => {
        if (!positionMap || !Array.isArray(ids) || ids.length === 0) return positionMap;
        const groupMetrics = buildGroupMetricsFromIds(ids, positionMap);
        if (!groupMetrics) return positionMap;
        const boundedGroup = getBoundedDragPosition(groupMetrics, groupMetrics.x, groupMetrics.y);
        const offsetX = boundedGroup.x - groupMetrics.x;
        const offsetY = boundedGroup.y - groupMetrics.y;
        if (offsetX === 0 && offsetY === 0) return positionMap;
        return Object.keys(positionMap).reduce((acc, id) => {
            acc[id] = { x: positionMap[id].x + offsetX, y: positionMap[id].y + offsetY };
            return acc;
        }, {});
    };

    const applyMultiDragPositions = (positionMap) => {
        if (!positionMap) return;
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        if (!stage) return;
        let touchedLayer = null;
        Object.keys(positionMap).forEach((id) => {
            const shape = imagesRef.current.find((s) => s.id === id);
            const node = stage.findOne('#' + id);
            if (!node) return;
            if (shape && shape.draggable === false) {
                node.position({ x: shape.x, y: shape.y });
            } else {
                node.position(positionMap[id]);
            }
            if (!touchedLayer) touchedLayer = node.getLayer();
        });
        if (touchedLayer) touchedLayer.batchDraw();
    };

    // \u628a\u6240\u6709 Konva \u8282\u70b9\u7684\u771f\u5b9e\u4f4d\u7f6e\u56de\u5199\u5230 imagesRef.current（\u4e0d\u89e6\u53d1 setState）。
    // \u7528\u4e8e\u590d\u5236 / \u526a\u5207 / \u5bf9\u9f50 / \u7b49\u8ddd\u7b49\u6279\u91cf\u64cd\u4f5c\u524d，\u786e\u4fdd imagesRef \u4e0e Konva \u89c6\u89c9\u4f4d\u7f6e\u4e00\u81f4，
    // \u907f\u514d\u62d6\u52a8\u540e\u504f\u5dee\u7d2f\u79ef、\u5237\u65b0 / \u590d\u5236\u540e\u5143\u7d20\u9519\u4f4d。
    // \u4e0d\u5199 history、\u4e0d\u8c03 setImages，\u8c03\u7528\u65b9\u6309\u9700\u540e\u7eed setImages + history.push。
    const syncKonvaPositionsToImagesRef = () => {
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        if (!stage) return false;
        let mutated = false;
        const next = imagesRef.current.map((shape) => {
            if (!shape || !shape.id) return shape;
            // \u9501\u5b9a\u5143\u7d20：\u4e0d\u540c\u6b65（\u89c6\u89c9\u4e0e\u903b\u8f91\u5c31\u4e0d\u8be5\u98d8）
            if (shape.draggable === false) return shape;
            const node = stage.findOne('#' + shape.id);
            if (!node) return shape;
            const nx = node.x();
            const ny = node.y();
            const cx = Number(shape.x) || 0;
            const cy = Number(shape.y) || 0;
            // \u6d6e\u70b9\u5bb9\u5dee：< 0.5px \u89c6\u4e3a\u76f8\u7b49，\u907f\u514d Konva \u5185\u90e8\u6d6e\u70b9\u7d2f\u79ef\u9020\u6210\u65e0\u610f\u4e49\u6296\u52a8
            if (Math.abs(nx - cx) < 0.5 && Math.abs(ny - cy) < 0.5) return shape;
            mutated = true;
            return { ...shape, x: nx, y: ny };
        });
        if (mutated) {
            imagesRef.current = next;
        }
        return mutated;
    };

    const commitMultiDragPositions = (positionMap) => {
        if (!positionMap) return;
        const nextImages = imagesRef.current.map((shape) => {
            if (!positionMap[shape.id]) return shape;
            if (shape.draggable === false) return shape;
            return { ...shape, x: positionMap[shape.id].x, y: positionMap[shape.id].y };
        });
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        // \u62d6\u52a8\u7ed3\u675f\u4fdd\u7559\u6574\u7ec4\u9009\u4e2d（your-feature \u98ce\u683c）：\u907f\u514d setState \u5f02\u6b65\u8fc7\u7a0b\u4e2d
        // \u51fa\u73b0 selectedIds=[] \u4f46 selectedId=\u88ab\u62d6\u5143\u7d20 \u7684\u4e2d\u95f4\u5e27，\u5426\u5219\u90a3\u4e2a\u6210\u5458\u4f1a\u95ea\u73b0\u84dd\u8272\u5355\u9009\u6846，
        // \u4e14 selectedIdsRef \u4e0e state \u4e0d\u540c\u6b65\u4f1a\u8ba9\u4e0b\u4e00\u6b21\u62d6\u52a8 startPositions \u9519\u7b97\u5bfc\u81f4\u53e6\u4e00\u6210\u5458\u4e71\u8dd1。
        selectShapes([...selectedIdsRef.current]);
    };

    // \u952e\u76d8\u65b9\u5411\u952e\u79fb\u52a8\u9009\u4e2d\u5143\u7d20 / \u7ec4\u5408 / \u591a\u5143\u7d20 / \u591a\u7ec4\u5408
    // - \u5355\u9009：\u6269\u5c55\u5230\u6574\u7ec4（\u4e0e\u62d6\u52a8\u4e00\u81f4）
    // - \u591a\u9009：\u76f4\u63a5\u6309 selectedIds \u79fb\u52a8（\u5df2\u542b\u6574\u7ec4）
    // - \u9501\u5b9a\u5143\u7d20：\u8df3\u8fc7
    // - \u590d\u7528 sync + applyMultiDragPositions + commitMultiDragPositions，\u4e0e\u62d6\u52a8\u540c\u4e00\u5957\u63d0\u4ea4\u94fe\u8def
    // - \u6bcf\u6b21\u6309\u952e = 1 \u6b21 history.push，\u64a4\u9500\u7cbe\u5ea6\u6309 1 \u6b65
    const moveSelectionByArrow = (dx, dy) => {
        if (isPreview) return;
        if (!dx && !dy) return;
        // \u6536\u96c6\u8981\u79fb\u52a8\u7684 id
        let targetIds = [];
        if (Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0) {
            targetIds = selectedIdsRef.current;
        } else if (selectedIdRef.current) {
            targetIds = getExpandedSelectionIds(selectedIdRef.current);
        }
        if (targetIds.length === 0) return;
        // \u8fc7\u6ee4\u9501\u5b9a\u5143\u7d20
        const unlockedIds = targetIds.filter((id) => isShapeUnlocked(id));
        if (unlockedIds.length === 0) return;
        // \u5148\u628a Konva \u771f\u5b9e\u4f4d\u7f6e\u540c\u6b65\u56de imagesRef，\u907f\u514d\u57fa\u4e8e\u8fc7\u671f x/y \u8ba1\u7b97（\u4e0e\u5bf9\u9f50/\u590d\u5236\u540c\u4e00\u5957\u4fdd\u62a4）
        syncKonvaPositionsToImagesRef();
        // \u6784\u9020 positionMap：\u57fa\u4e8e\u5f53\u524d imagesRef.x/y + dx/dy
        const positionMap = {};
        unlockedIds.forEach((id) => {
            const shape = imagesRef.current.find((s) => s.id === id);
            if (!shape) return;
            positionMap[id] = {
                x: (Number(shape.x) || 0) + dx,
                y: (Number(shape.y) || 0) + dy,
            };
        });
        // \u540c\u6b65\u63a8\u5230 Konva \u8282\u70b9（\u5373\u65f6\u89c6\u89c9\u53cd\u9988），\u518d\u63d0\u4ea4\u5230 state + history
        applyMultiDragPositions(positionMap);
        commitMultiDragPositions(positionMap);
    };

    // \u62d6\u52a8\u671f\u95f4，\u6bcf\u6b21 React \u91cd\u6e32\u67d3（\u4f8b\u5982 onSelect \u89e6\u53d1\u7684\u9009\u4e2d setState）\u540e\u7acb\u5373\u628a Konva \u8282\u70b9\u4f4d\u7f6e\u91cd\u65b0\u5bf9\u9f50\u5230 pendingPositions，
    // \u907f\u514d <Group {...shapeProps}> \u7528 imagesRef \u65e7 x/y \u56de\u62c9\u540c\u7ec4\u6210\u5458，\u9020\u6210\u6f02\u79fb
    // \u7528 useLayoutEffect \u5728\u6d4f\u89c8\u5668\u7ed8\u5236\u4e4b\u524d\u540c\u6b65\u6267\u884c，\u907f\u514d\u7528\u6237\u770b\u5230\u9519\u4f4d\u7684\u4e00\u5e27
    // \u5173\u952e：\u53ea\u5728 pendingPositions \u5df2\u5c31\u7eea（dragmove \u81f3\u5c11\u8dd1\u8fc7\u4e00\u6b21）\u65f6\u624d backstop；
    // \u5426\u5219 dragstart \u540e、\u9996\u6b21 dragmove \u524d\u7684\u91cd\u6e32\u67d3\u4f1a\u7528 startPositions(=origin) \u628a\u88ab\u62d6\u5143\u7d20\u62fd\u56de\u539f\u70b9，\u8ddf Konva \u5185\u90e8 dragMove \u5f62\u6210\u5bf9\u6297，
    // \u5bfc\u81f4"\u5148 click \u518d drag → \u62d6\u4e0d\u52a8"
    useLayoutEffect(() => {
        if (!multiDragRef.current.active) return;
        const positions = multiDragRef.current.pendingPositions;
        if (positions) applyMultiDragPositions(positions);
    });

    // \u591a\u9009/\u7ec4\u5408\u62d6\u52a8：dragstart \u7acb\u5373\u7528 Konva \u8282\u70b9\u771f\u5b9e\u4f4d\u7f6e\u8bb0\u5f55\u6240\u6709\u6210\u5458\u8d77\u70b9。
    // \u5173\u952e\u4fee\u590d：startPositions \u5fc5\u987b\u4e0e e.target.position() \u540c\u6e90（\u90fd\u6765\u81ea Konva \u8282\u70b9）。
    // \u65e7\u5b9e\u73b0\u7528 imagesRef.current.x/y \u505a\u8d77\u70b9，\u4e00\u65e6 React \u72b6\u6001\u4e0e Konva \u8282\u70b9\u4e0d\u540c\u6b65（\u5982\u4e0a\u6b21\u62d6\u52a8 commit、
    // useLayoutEffect backstop、\u81ea\u52a8\u4fdd\u5b58 setState \u7b49），\u9996\u5e27 delta \u4f1a\u628a\u8fd9\u90e8\u5206\u5386\u53f2\u504f\u5dee\u4e00\u6b21\u6027\u5438\u6536，
    // \u7136\u540e\u539f\u5c01\u4e0d\u52a8\u52a0\u5230\u5176\u4ed6\u6210\u5458\u4e0a，\u9020\u6210"\u9f20\u6807\u9009\u4e2d\u5143\u7d20\u7a33，\u5176\u4ed6\u5143\u7d20\u77ac\u95f4\u504f\u4e00\u4e0b"\u7684\u4e71\u8dd1\u73b0\u8c61。
    const handleShapeDragStart = (e, shape) => {
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        if (!stage) return;
        const expandedSelectedIds = expandDragSelectionIds(selectedIdsRef.current, shape.id);
        const dragSelectedIds = expandedSelectedIds.filter((id) => {
            const currentShape = imagesRef.current.find((item) => item.id === id);
            return currentShape && currentShape.draggable !== false;
        });
        // \u5355\u62d6：\u590d\u4f4d multiDragRef，\u8ba9 dragmove \u8d70\u5355\u5143\u7d20\u5206\u652f（applySnapForShape）
        if (!Array.isArray(dragSelectedIds) || dragSelectedIds.length <= 1 || !dragSelectedIds.includes(shape.id)) {
            multiDragRef.current = {
                active: false,
                draggedId: null,
                startPositions: {},
                pendingPositions: null,
            };
            pendingDragFollowerIdsRef.current = new Set();
            return;
        }
        const startPositions = {};
        dragSelectedIds.forEach((id) => {
            const node = stage.findOne('#' + id);
            if (node) {
                // \u7528 Konva \u8282\u70b9\u7684\u771f\u5b9e\u4f4d\u7f6e\u4f5c\u4e3a\u8d77\u70b9，\u4e0e\u88ab\u62d6\u5143\u7d20 e.target.position() \u540c\u6e90
                startPositions[id] = { x: node.x(), y: node.y() };
            } else {
                const currentShape = imagesRef.current.find((item) => item.id === id);
                if (currentShape) {
                    startPositions[id] = { x: Number(currentShape.x) || 0, y: Number(currentShape.y) || 0 };
                }
            }
        });
        multiDragRef.current = {
            active: true,
            draggedId: shape.id,
            startPositions,
            pendingPositions: null,
        };
        // \u8bb0\u5f55"\u8ddf\u968f\u6210\u5458"：dragend \u65f6\u8fd9\u4e9b id \u7684 onChange \u76f4\u63a5 return，\u907f\u514d\u6bcf\u4e2a\u6210\u5458\u90fd push \u4e00\u6b21 history
        const followers = new Set();
        dragSelectedIds.forEach((id) => {
            if (id !== shape.id) followers.add(id);
        });
        pendingDragFollowerIdsRef.current = followers;
    };

    // \u591a\u9009/\u7ec4\u5408\u62d6\u52a8：dragmove \u8ba1\u7b97 delta \u5e76\u628a\u6240\u6709\u6210\u5458\u5b9a\u4f4d\u5230 startPositions[id] + delta
    // dragstart \u5df2\u7ecf\u9884\u8bb0\u5f55\u8d77\u70b9；\u8fd9\u91cc\u4fdd\u7559\u61d2\u521d\u59cb\u5316\u4f5c\u4e3a\u515c\u5e95（\u4e07\u4e00 dragstart \u672a\u89e6\u53d1\u4e5f\u80fd fallback）
    const handleShapeDragMove = (e, shape) => {
        const expandedSelectedIds = expandDragSelectionIds(selectedIdsRef.current, shape.id);
        const dragSelectedIds = expandedSelectedIds.filter((id) => {
            const currentShape = imagesRef.current.find((item) => item.id === id);
            return currentShape && currentShape.draggable !== false;
        });
        const isMultiDrag = Array.isArray(dragSelectedIds) && dragSelectedIds.length > 1 && dragSelectedIds.includes(shape.id);
        if (!isMultiDrag) {
            multiDragRef.current = {
                active: false,
                draggedId: null,
                startPositions: {},
                pendingPositions: null,
            };
            applySnapForShape(e.target, shape);
            return;
        }
        if (!snapEnabled) {
            clearSnapGuides();
        }
        // \u61d2\u521d\u59cb\u5316\u515c\u5e95：dragstart \u672a\u89e6\u53d1\u65f6（\u6781\u5c11\u6570\u60c5\u51b5），\u7528 Konva \u8282\u70b9\u771f\u5b9e\u4f4d\u7f6e\u8bb0\u5f55\u8d77\u70b9，
        // \u4e0e e.target.position() \u540c\u6e90，\u907f\u514d\u5438\u6536 React/Konva \u4e0d\u540c\u6b65\u7684\u504f\u5dee\u5bfc\u81f4\u5176\u4ed6\u6210\u5458\u4e71\u8dd1
        if (!multiDragRef.current.active || multiDragRef.current.draggedId !== shape.id) {
            const stage = stageRef.current ? stageRef.current.getStage() : null;
            const startPositions = {};
            dragSelectedIds.forEach((id) => {
                const node = stage ? stage.findOne('#' + id) : null;
                if (node) {
                    startPositions[id] = { x: node.x(), y: node.y() };
                } else {
                    const currentShape = imagesRef.current.find((item) => item.id === id);
                    if (currentShape) {
                        startPositions[id] = { x: Number(currentShape.x) || 0, y: Number(currentShape.y) || 0 };
                    }
                }
            });
            multiDragRef.current = {
                active: true,
                draggedId: shape.id,
                startPositions,
                pendingPositions: null,
            };
        }
        const startPosition = multiDragRef.current.startPositions[shape.id];
        if (!startPosition) {
            applySnapForShape(e.target, shape);
            return;
        }
        const deltaX = e.target.x() - startPosition.x;
        const deltaY = e.target.y() - startPosition.y;
        let nextPositions = {};
        dragSelectedIds.forEach((id) => {
            const basePos = multiDragRef.current.startPositions[id];
            if (basePos) {
                nextPositions[id] = {
                    x: basePos.x + deltaX,
                    y: basePos.y + deltaY,
                };
            }
        });
        if (snapEnabled) {
            const groupMetrics = buildGroupMetricsFromIds(dragSelectedIds, nextPositions);
            if (groupMetrics) {
                const { matchX, matchY, snappedMetrics } = getSnappedMetrics(groupMetrics, dragSelectedIds);
                if (matchX || matchY) {
                    const offsetX = snappedMetrics.x - groupMetrics.x;
                    const offsetY = snappedMetrics.y - groupMetrics.y;
                    nextPositions = Object.keys(nextPositions).reduce((acc, id) => {
                        acc[id] = {
                            x: nextPositions[id].x + offsetX,
                            y: nextPositions[id].y + offsetY,
                        };
                        return acc;
                    }, {});
                    // \u5f15\u5bfc\u7ebf\u7528 ref imperative \u7ed8\u5236，\u4e0d\u89e6\u53d1 React \u91cd\u6e32\u67d3，\u591a\u9009/\u7ec4\u5408\u62d6\u52a8\u4e5f\u80fd\u663e\u793a
                    updateSnapGuides(buildSnapGuideLine(
                        matchX ? matchX.guide.value : null,
                        matchY ? matchY.guide.value : null,
                        snappedMetrics,
                        matchX, matchY,
                    ));
                } else {
                    clearSnapGuides();
                }
            } else {
                clearSnapGuides();
            }
        } else {
            clearSnapGuides();
        }
        nextPositions = getBoundedMultiDragPositions(nextPositions, dragSelectedIds);
        applyMultiDragPositions(nextPositions);
        multiDragRef.current.pendingPositions = nextPositions;
    };

    // Comment translated to English.
    useEffect(() => {
        // Comment translated to English.
        const onKeyDown = (e) => {
            // \u8f93\u5165\u6846 / \u6587\u672c\u57df / contenteditable \u7126\u70b9\u65f6，\u4e0d\u62e6\u622a\u4efb\u4f55\u6309\u952e（\u8ba9\u7528\u6237\u6b63\u5e38\u8f93\u5165）
            const tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : '';
            const isEditing = tag === 'input' || tag === 'textarea' || tag === 'select'
                || (e.target && e.target.isContentEditable);
            if (isEditing) return;
            if (e.ctrlKey && e.shiftKey && (e.key === 'J' || e.key === 'j')) {
                e.preventDefault();
                ungroupSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'G' || e.key === 'g')) {
                e.preventDefault();
                groupSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'C' || e.key === 'c')) {
                copySelectionToClipboard();
            } else if (e.ctrlKey && (e.key === 'X' || e.key === 'x')) {
                cutSelectionToClipboard();
            } else if (e.ctrlKey && (e.key === 'V' || e.key === 'v')) {
                pasteClipboardSelection();
            } else if (e.ctrlKey && e.key === 'ArrowUp') {
                handleToolChange('up');
            } else if (e.ctrlKey && (e.key === 'Z' || e.key === 'z')) {
                handleToolChange('undo');
            } else if (e.ctrlKey && e.key === 'ArrowDown') {
                handleToolChange('down');
            } else if (!e.ctrlKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                // \u65b9\u5411\u952e\u79fb\u52a8\u9009\u4e2d\u5143\u7d20 / \u7ec4\u5408 / \u591a\u5143\u7d20 / \u591a\u7ec4\u5408
                // Shift = 10px \u52a0\u901f，\u5426\u5219 1px \u7cbe\u7ec6\u5fae\u8c03（Figma / Photoshop \u6807\u51c6）
                // Ctrl + \u65b9\u5411\u952e\u4e0d\u5728\u8fd9\u91cc\u5904\u7406（Ctrl+↑/↓ \u5df2\u88ab\u5c42\u7ea7\u8c03\u6574\u5360\u7528）
                e.preventDefault();
                const step = e.shiftKey ? 10 : 1;
                let dx = 0, dy = 0;
                if (e.key === 'ArrowUp') dy = -step;
                else if (e.key === 'ArrowDown') dy = step;
                else if (e.key === 'ArrowLeft') dx = -step;
                else if (e.key === 'ArrowRight') dx = step;
                moveSelectionByArrow(dx, dy);
            } else if (e.ctrlKey && (e.key === 'E' || e.key === 'e')) {
                handleToolChange('top');
            } else if (e.ctrlKey && (e.key === 'B' || e.key === 'b')) {
                handleToolChange('bottom');
                // } else if (e.key === 'A') {
                //     handleToolChange('alginleft');
                // } else if (e.key === 'D') {
                //     handleToolChange('alginright');
                // } else if (e.key === 'W') {
                //     handleToolChange('algintop');
                // } else if (e.key === 'S') {
                //     handleToolChange('alginbottom');
                // } else if (e.key === 'Q') {
                //     handleToolChange('algincenter');
                // } else if (e.key === 'E') {
                //     handleToolChange('alginvertical');
            } else if (e.ctrlKey && e.shiftKey && (e.key === 'K' || e.key === 'k')) {
                e.preventDefault();
                unlockSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'K' || e.key === 'k')) {
                e.preventDefault();
                lockSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'L' || e.key === 'l')) {
                handleToolChange('lock');
            } else if (e.ctrlKey && (e.key === 'N' || e.key === 'n')) {
                handleToolChange('unlock');
            } else if (e.ctrlKey && (e.key === 'S' || e.key === 's')) {
                if (savePageId !== '0' && savePageType === '1') {
                    savePage('page')
                }
            } else if (e.ctrlKey && (e.key === 'H' || e.key === 'h')) {
                e.preventDefault();
                openTextReplaceDialog();
            } else if (e.key === 'Delete') {
                handleToolChange('del');
            } else {
                return;
            }
        }
        window.addEventListener('keydown', onKeyDown);
        return () => {
            window.removeEventListener("keydown", onKeyDown);
        };
    }, []);// Comment translated to English.

    // Comment translated to English.
    useEffect(() => {
        const nodes = selectedIds
            .filter((id) => isShapeUnlocked(id))
            .map((id) => layerRef.current.findOne("#" + id))
            .filter(Boolean);
        if (transformRefids.current) {
            transformRefids.current.nodes(nodes);
        }
    }, [selectedIds]);

    // Comment translated to English.
    useEffect(() => {
        if (showsavePageBox === 1) {
            // Comment translated to English.
            setTimeout(async () => {
                let res = await httpsend.getData('GetDmpageListKey', { ComboBox: '1' })
                let options = [{
                    value: '0',
                    label: t('auto.k0360')
                }];
                if (res) {
                    res.data.forEach((el) => {
                        let firstop = {
                            value: el.id,
                            label: el.PageName
                        }
                        if (el.children.length !== 0) {
                            firstop.children = [];
                            el.children.forEach((y) => {
                                let secop = {
                                    value: y.id,
                                    label: y.PageName
                                }
                                if (y.children.length !== 0) {
                                    secop.children = [];
                                    y.children.forEach((m) => {
                                        let throp = {
                                            value: m.id,
                                            label: m.PageName
                                        }
                                        secop.children.push(throp)
                                    })
                                }
                                firstop.children.push(secop)
                            })
                        }
                        options.push(firstop);
                    })
                }
                setsavePagePidSel(options);
            });
        }

    }, [showsavePageBox]);

    // Comment translated to English.
    const checkDeselect = async () => {
        // const clickedOnEmpty = e.target === e.target.getStage();
        // if (clickedOnEmpty) {
        console.log("remove all selections")
        selectShapes([]);
        selectedIdsRef.current = [];
        setSelectedId(null);
        selectedIdRef.current = null;
        setDragShape(null);
        settoolType(null);
        // }
    };
    // Comment translated to English.
    const onClickTap = async (e) => {
        if (e === 'handle') {
            checkDeselect();
            return;
        }
        // Comment translated to English.
        const { x1, x2, y1, y2 } = selection.current;
        const moved = x1 !== x2 || y1 !== y2;
        if (moved) {
            return;
        }
        let stage = e.target.getStage();
        let layer = layerRef.current;
        let tr = transformRefids.current;

        // Comment translated to English.
        if (e.target === stage || e.target.id() === 'canvasBackground') {
            checkDeselect();
            return;
        }
        // \u591a\u9009\u952e：Ctrl（Win/Linux）\u6216 Cmd（Mac）。Shift \u4e0d\u518d\u89e6\u53d1\u591a\u9009
        const metaPressed = e.evt.ctrlKey || e.evt.metaKey;
        const clickedShapeId = e.target.parent.attrs.id;
        // \u628a "\u5df2\u9009" \u5224\u65ad\u6269\u5c55\u5230\u6574\u7ec4：onSelect \u5df2\u7ecf\u628a\u540c\u7ec4\u6210\u5458\u88c5\u5165 selectedIdsRef，\u8fd9\u91cc\u8981\u8bc6\u522b\u6210\u5df2\u9009
        const isSelected = tr.nodes().indexOf(e.target) >= 0
            || (selectedIdsRef.current && selectedIdsRef.current.includes(clickedShapeId));
        const isDrag = e.target.parent.attrs.draggable;
        if (!isDrag) { message.error(t('auto.k0361')); return; }
        // \u70b9\u51fb\u6210\u5458\u6240\u5728\u6574\u7ec4（\u7ec4\u5408\u5219\u5c55\u5f00\u4e3a\u6574\u7ec4\u6210\u5458，\u5355\u5143\u7d20\u5219\u5c31\u662f\u81ea\u8eab）
        const clickedGroupIds = getUnlockedExpandedSelectionIds(clickedShapeId);
        if (!metaPressed && !isSelected) {
            // \u65e0\u4fee\u9970\u952e + \u672a\u9009\u4e2d：\u6e05\u7a7a\u9009\u533a（\u5177\u4f53\u5355\u9009/\u6574\u7ec4\u9009\u4ea4\u7ed9 onSelect \u5904\u7406）
            selectShapes([]);
            selectedIdsRef.current = [];
            return;
        } else if (metaPressed && isSelected) {
            // Ctrl + \u5df2\u9009：\u628a\u6574\u7ec4\u4ece\u9009\u533a\u4e2d\u6574\u4f53\u79fb\u9664
            selectShapes((oldShapes) => {
                const removeSet = new Set(clickedGroupIds);
                const ids = oldShapes.filter((oldId) => !removeSet.has(oldId));
                selectedIdsRef.current = ids;
                return ids;
            });
        } else if (metaPressed && !isSelected) {
            // Ctrl + \u672a\u9009：\u628a\u6574\u7ec4\u6210\u5458\u52a0\u5165\u9009\u533a，\u5e76\u4fdd\u7559\u4e4b\u524d\u7684 selectedId（\u82e5\u53ef\u62d6）
            selectShapes((oldShapes) => {
                let resShapes = oldShapes;
                if (selectedId && oldShapes.indexOf(selectedId) === -1) {
                    const isDragIndex = images.findIndex((findid) => selectedId === findid.id);
                    if (isDragIndex !== -1) {
                        const prevDraggable = images[isDragIndex].draggable;
                        if (prevDraggable) {
                            // \u4e4b\u524d\u7684 selectedId \u4e5f\u8981\u6309\u6574\u7ec4\u6269\u5c55
                            const prevGroupIds = getUnlockedExpandedSelectionIds(selectedId);
                            prevGroupIds.forEach((id) => {
                                if (!resShapes.includes(id)) resShapes = [...resShapes, id];
                            });
                        }
                    }
                }
                clickedGroupIds.forEach((id) => {
                    if (!resShapes.includes(id)) resShapes = [...resShapes, id];
                });
                selectedIdsRef.current = resShapes;
                return resShapes;
            });
        }
        layer.draw();
    }
    // Comment translated to English.
    // Comment translated to English.
    const updateSelectionRect = (type) => {
        const node = selectionRectRef.current;
        if (type) {
            node.setAttrs({
                visible: false,
                x: 0,
                y: 0,
                width: 0,
                height: 0
            });
            selection.current.visible = false;
            selection.current.x1 = 0;
            selection.current.x2 = 0;
            selection.current.y1 = 0;
            selection.current.y2 = 0;
        } else {
            node.setAttrs({
                visible: selection.current.visible,
                x: Math.min(selection.current.x1, selection.current.x2),
                y: Math.min(selection.current.y1, selection.current.y2),
                width: Math.abs(selection.current.x1 - selection.current.x2),
                height: Math.abs(selection.current.y1 - selection.current.y2),
                fill: "rgba(0, 161, 255, 0.3)"
            });
        }
        node.getLayer().batchDraw();
    };
    const onMouseDown = (e) => {
        if (e.target !== e.target.getStage() && e.target.id() !== 'canvasBackground') {
            return;
        }
        const pos = e.target.getStage().getPointerPosition();
        selection.current.visible = true;
        selection.current.x1 = pos.x / stageDimensions.scalex;
        selection.current.y1 = pos.y / stageDimensions.scalex;
        selection.current.x2 = pos.x / stageDimensions.scalex;
        selection.current.y2 = pos.y / stageDimensions.scalex;
        setMarqueeHoverIds([]);
        updateSelectionRect();
    };
    const onMouseMove = (e) => {
        if (!selection.current.visible) {
            return;
        }
        const pos = e.target.getStage().getPointerPosition();
        selection.current.x2 = pos.x / stageDimensions.scalex;
        selection.current.y2 = pos.y / stageDimensions.scalex;
        updateSelectionRect();
        // \u5b9e\u65f6\u8ba1\u7b97\u4e0e\u9009\u6846\u76f8\u4ea4\u7684\u53ef\u62d6\u52a8\u5143\u7d20 id
        const selBox = selectionRectRef.current.getClientRect();
        const hoverIds = [];
        layerRef.current.find(".group").forEach((elementNode) => {
            const elBox = elementNode.getClientRect();
            if (Konva.Util.haveIntersection(selBox, elBox)) {
                const sid = elementNode.attrs.id;
                const shape = imagesRef.current.find((s) => s.id === sid);
                if (shape && shape.draggable !== false) {
                    hoverIds.push(sid);
                }
            }
        });
        setMarqueeHoverIds((prev) => {
            if (prev.length === hoverIds.length && prev.every((id, i) => id === hoverIds[i])) {
                return prev;
            }
            return hoverIds;
        });
    };
    const onMouseUp = () => {
        oldPos.current = null;
        selection.current.visible = false;
        const { x1, x2, y1, y2 } = selection.current;
        const moved = x1 !== x2 || y1 !== y2;
        if (!moved) {
            setMarqueeHoverIds([]);
            updateSelectionRect();
            return;
        }
        const selBox = selectionRectRef.current.getClientRect();
        const elements = [];
        layerRef.current.find(".group").forEach((elementNode) => {
            const elBox = elementNode.getClientRect();
            if (Konva.Util.haveIntersection(selBox, elBox)) {
                elements.push(elementNode);
            }
        });
        let ids = elements.map((el) => el.attrs.id);
        selectShapes(ids);
        selectedIdsRef.current = ids;
        setMarqueeHoverIds([]);
        updateSelectionRect('remove');
    };
    // Comment translated to English.

    // Comment translated to English.
    // Comment translated to English.
    const handleItemDragUrl = async (dragUrl, dragAttrs, type) => {
        // \u5207\u6362\u754c\u9762\u63d0\u793a\u4fdd\u5b58：\u5f53\u524d\u9875\u9762\u6709\u672a\u4fdd\u5b58\u6539\u52a8 + \u662f\u5df2\u5b58\u5728\u7684\u6b63\u5f0f\u9875\u9762 → \u5f39\u786e\u8ba4\u6846
        if (
            type
            && dirtyRef.current
            && savePageId
            && savePageId !== '0'
            && savePageType === '1'
            && savePageTxt
        ) {
            // \u6682\u5b58\u8fd9\u6b21\u5207\u6362\u52a8\u4f5c，\u7b49\u7528\u6237\u70b9\u5f39\u7a97\u91cc"\u4fdd\u5b58\u5e76\u5207\u6362 / \u4e0d\u4fdd\u5b58\u5207\u6362"\u518d\u7ee7\u7eed
            pendingSwitchRef.current = { dragUrl, dragAttrs, type };
            setSwitchConfirmBox(true);
            return;
        }
        await performItemDragUrl(dragUrl, dragAttrs, type);
    };

    // \u5b9e\u9645\u6267\u884c\u5207\u6362\u7684\u5185\u90e8\u51fd\u6570（\u88ab handleItemDragUrl \u548c\u786e\u8ba4\u5f39\u7a97\u6309\u94ae\u590d\u7528）
    const performItemDragUrl = async (dragUrl, dragAttrs, type) => {
        setDragUrl(dragUrl);
        if (type) {
            let conres = await httpsend.getDataLocal('imgData', { action: 'page', name: type.split('&')[0] });
            if (conres) {
                // seteditPageId(type.split('&')[3]);
                // seteditPageName(type.split('&')[2]);
                // seteditPageType(type.split('&')[4]);
                setsavePageTxt(type.split('&')[0]);
                setsavePageId(type.split('&')[3]);
                setsavePageName(type.split('&')[2]);
                setsavePageType(type.split('&')[4].toString());
                const hasPageData = conres.code === 100
                    && conres.data
                    && conres.data[0]
                    && typeof conres.data[0].moduleJson === 'string';
                if (!hasPageData) {
                    setDragAttrs('');
                    dealStringPage('');
                } else {
                    setDragAttrs(conres.data[0].moduleJson);
                    // Comment translated to English.
                    dealStringPage(conres.data[0].moduleJson);
                }
            }
        } else {
            setDragAttrs(dragAttrs);
        }
    };
    // Comment translated to English.
    const dealStringPage = (dragAttrs) => {
        let tplimages = [];
        let dargJson = null;
        setBackgroundImage(null);
        setalarmCatch('1');
        alarmCatchRef.current = '1';
        if (typeof dragAttrs === 'string' && dragAttrs.indexOf('{') > -1) {
            try {
                dargJson = JSON.parse(JSON.parse(dragAttrs));
            } catch (e) {
                try {
                    dargJson = JSON.parse(dragAttrs);
                } catch (e2) {
                    dargJson = null;
                }
            }
        } else if (dragAttrs && typeof dragAttrs === 'object' && dragAttrs.className === 'Stage') {
            dargJson = dragAttrs;
        }

        if (dargJson && dargJson.children && dargJson.children[0] && dargJson.children[0].children) {
            const logicalStageSize = resolveLogicalStageSize(dargJson);
            setstageWidth(logicalStageSize.width);
            setstageHeight(logicalStageSize.height);
            setStageDimensions({
                width: logicalStageSize.width,
                height: logicalStageSize.height,
                scalex: 1,
                scaley: 1,
            });
            setcanvasScale(100);
            dargJson.children[0].children.forEach(element => {
                if (element.attrs.id !== 'canvasBackground') {
                    tplimages.push(element.attrs);
                } else {
                    if (element.attrs.fillPatternImage) {
                        if (element.attrs.fillPatternImage.indexOf('/public/') > 0) {
                            setBackgroundImage(element.attrs.fillPatternImage.split('/public/')[1]);
                        } else {
                            setBackgroundImage(element.attrs.fillPatternImage);
                        }
                    } else {
                        setBackgroundImage(element.attrs.fill);
                    }
                    if (element.attrs.alarmCatch) {
                        setalarmCatch(element.attrs.alarmCatch)
                        alarmCatchRef.current = element.attrs.alarmCatch;
                    }
                }
            });
        }
        // F5 \u5207\u6362\u6a21\u677f/\u9875\u9762\u524d\u5148\u6e05\u7406\u591a\u62d6 / \u78c1\u5438 / \u9009\u62e9 / hover \u72b6\u6001
        multiDragRef.current = {
            active: false,
            draggedId: null,
            startPositions: {},
            pendingPositions: null,
        };
        clearSnapGuides();
        selectShapes([]);
        selectedIdsRef.current = [];
        setSelectedId(null);
        selectedIdRef.current = null;
        setDragShape(null);
        settoolType(null);
        setHoverHighlightIds([]);
        setImages(tplimages);
        imagesRef.current = tplimages;
        setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);
        history = [];
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        // F11b \u7cbe\u7b80\u7248：\u521a\u52a0\u8f7d\u5b8c\u9875\u9762，\u628a dirty \u72b6\u6001\u538b\u56de\u53bb（\u907f\u514d\u968f\u4e4b\u800c\u6765\u7684 setImages \u628a\u521a\u52a0\u8f7d\u7684\u5185\u5bb9\u5224\u810f）
        markPageLoaded();
        // \u5207\u6362\u9875\u9762 → \u6362\u65b0 token（\u7528\u4e8e\u8de8\u9875\u9762\u590d\u5236\u7c98\u8d34\u5224\u5b9a）
        currentPageTokenRef.current = 'page-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
    }
    // Comment translated to English.
    const handleOnDrop = (e) => {
        // Comment translated to English.
        if (e) {
            e.preventDefault();
            stageRef.current.setPointersPositions(e);// Comment translated to English.
        }
        if (typeof dragAttrs === 'string' || (dragAttrs && typeof dragAttrs === 'object' && dragAttrs.className === 'Stage')) {
            // Comment translated to English.
            setsavePageType('1');
            dealStringPage(dragAttrs);
            // Comment translated to English.
            setcanvasScale(100);
        } else {
            let eleId = parseInt(new Date().getTime()).toString()// Comment translated to English.
            // Comment translated to English.
            // console.log(stageRef.current.getPointerPosition());
            let newX = stageRef.current.getPointerPosition().x / stageDimensions.scalex;
            let newY = stageRef.current.getPointerPosition().y / stageDimensions.scalex;
            // console.log(newX)
            // console.log(newY)
            let shape = {
                // ...stageRef.current.getPointerPosition(),
                x: newX,
                y: newY,
                src: dragUrl,
                moduleJson: dragAttrs,
                id: eleId,
                draggable: true,
                time: new Date().toLocaleString(),
                width: dragAttrs.width,
                height: dragAttrs.height
            }
            setImages(// Comment translated to English.
                images.concat([shape])
            );
            imagesRef.current = images.concat([shape]);
            history.push(JSON.parse(JSON.stringify(imagesRef.current)));

            setSelectedId(null);
            setDragShape(null);
            setTimeout(() => {
                // Comment translated to English.
                setDragShape(shape);
                setSelectedId(shape.id)// Comment translated to English.
                selectedIdRef.current = shape.id;
                setChart(JSON.parse(JSON.stringify(imagesRef.current)), selectedIdRef.current, null);
            })
        }
    };
    // Comment translated to English.
    // Comment translated to English.
    const handleShapeChange = (newShapeProps, id) => {
        // F6 \u591a\u9009\u62d6\u52a8：\u8ddf\u968f\u6210\u5458\u7684 onChange \u8d70\u5230\u8fd9\u91cc\u4e5f\u76f4\u63a5\u4e22\u5f03（\u515c\u5e95，\u6b63\u5e38\u8def\u5f84\u5df2\u5728 JSX onChange \u91cc return）
        if (pendingDragFollowerIdsRef.current.has(newShapeProps && newShapeProps.id)) {
            pendingDragFollowerIdsRef.current.delete(newShapeProps.id);
            return;
        }
        // F6 \u591a\u9009\u62d6\u52a8：\u5982\u679c\u5f53\u524d\u6b63\u5728\u591a\u9009\u62d6\u52a8，\u7531 commitMultiDragPositions \u7edf\u4e00\u63d0\u4ea4，\u5355\u70b9 onChange \u8df3\u8fc7
        if (multiDragRef.current.active && multiDragRef.current.pendingPositions) {
            const pending = multiDragRef.current.pendingPositions;
            multiDragRef.current = {
                active: false,
                draggedId: null,
                startPositions: {},
                pendingPositions: null,
            };
            pendingDragFollowerIdsRef.current = new Set();
            commitMultiDragPositions(pending);
            return;
        }
        let imagesToUpdate = images;
        const findIndex = images.findIndex((img) => img.id === newShapeProps.id);
        let singleImageToUpdate = imagesToUpdate[findIndex];
        singleImageToUpdate = newShapeProps;
        imagesToUpdate[findIndex] = singleImageToUpdate;
        setImages(JSON.parse(JSON.stringify(imagesToUpdate)));
        imagesRef.current = JSON.parse(JSON.stringify(imagesToUpdate));
        history.push(imagesToUpdate);
        setChart(imagesRef.current, selectedIdRef.current, null);
        if (selectedIdsRef.current.length === 0) {
            if (id) {
                setSelectedId(null);
                selectedIdRef.current = null;
                setDragShape(null);
            }
            setTimeout(() => {
                // Comment translated to English.
                setSelectedId(newShapeProps.id);
                selectedIdRef.current = newShapeProps.id;
                setDragShape(newShapeProps);
            })
        } else {
            selectShapes(selectedIdsRef.current)
        }
    };
    // Comment translated to English.
    const handleToolBack = async (newShapeProps, type) => {
        switch (type) {
            case 'copy':
                if (newShapeProps !== null) {
                    let eleId = parseInt(new Date().getTime()).toString()// Comment translated to English.
                    let shape = {
                        ...newShapeProps,
                        x: newShapeProps.x + 5,
                        y: newShapeProps.y + 5,
                        id: eleId
                    }
                    let imagesToUpdate = images;
                    imagesToUpdate.push(shape);
                    setImages(JSON.parse(JSON.stringify(imagesToUpdate)));// Comment translated to English.
                    imagesRef.current = JSON.parse(JSON.stringify(imagesToUpdate));
                    history.push(imagesToUpdate);
                    setChart(imagesRef.current, selectedIdRef.current, null);

                    setSelectedId(null);
                    setDragShape(null);
                    setTimeout(() => {
                        setDragShape(shape);// Comment translated to English.
                        setSelectedId(shape.id)// Comment translated to English.
                        selectedIdRef.current = shape.id;
                    })
                    console.log(t('auto.k0363'));
                }
                break;
            case 'del': {
                // \u5220\u9664\u76ee\u6807\u5143\u7d20；\u82e5\u5c5e\u4e8e\u7ec4\u5408，\u5219\u6574\u7ec4\u4e00\u8d77\u5220
                const delIds = new Set(getExpandedSelectionIds(newShapeProps.id));
                if (delIds.size === 0) delIds.add(newShapeProps.id);
                const delImageToUpdate = imagesRef.current.filter((img) => !delIds.has(img.id));
                setImages(JSON.parse(JSON.stringify(delImageToUpdate)));
                imagesRef.current = JSON.parse(JSON.stringify(delImageToUpdate));
                history.push(delImageToUpdate);
                setChart(imagesRef.current, selectedIdRef.current, null);

                selectShapes([]);
                selectedIdsRef.current = [];
                setSelectedId(null);
                selectedIdRef.current = null;
                setDragShape(null);
                console.log(t('auto.k0364'));
                break;
            }
            case 'lock':
            case 'unlock':
                handleShapeChange(newShapeProps, newShapeProps.id);
                console.log(t('auto.k0365'));
                break;
            default: console.log(t('auto.k0366')); break;
        }
        settoolType(null);
    };
    // Comment translated to English.
    const handleMultiToolBack = (type) => {
        // \u5173\u952e：\u5148\u628a Konva \u8282\u70b9\u771f\u5b9e\u4f4d\u7f6e\u540c\u6b65\u56de imagesRef，\u907f\u514d\u5bf9\u9f50 / \u7b49\u8ddd / \u590d\u5236\u57fa\u4e8e\u9519\u4f4d\u7684 imagesRef \u8ba1\u7b97，
        // \u5bfc\u81f4\u89c6\u89c9\u4e0a"\u770b\u4f3c\u5bf9\u9f50"\u4f46\u4fdd\u5b58\u5230 chart \u7684 x/y \u504f\u79fb、\u5237\u65b0\u540e\u53c8\u9519\u4f4d\u7684\u73b0\u8c61。
        // del \u4e5f\u8981\u540c\u6b65，\u5426\u5219\u5220\u9664\u524d\u4e34\u65f6\u504f\u79fb\u4f1a\u4e22\u5931（\u867d\u7136\u4e0b\u9762 del \u5206\u652f\u7acb\u5373 return，\u4e0d\u4f1a\u8bfb x/y，\u4f46\u4fdd\u6301\u8c03\u7528\u4e00\u81f4\u66f4\u5b89\u5168）。
        syncKonvaPositionsToImagesRef();
        // \u591a\u9009\u5220\u9664：\u6279\u91cf\u5220\u9664 selectedIds（\u7ec4\u5408\u70b9\u51fb\u540e selectedIds \u5df2\u542b\u6574\u7ec4）
        if (type === 'del') {
            const delIds = new Set(selectedIdsRef.current);
            if (delIds.size === 0) {
                settoolType(null);
                return;
            }
            const nextImages = imagesRef.current.filter((img) => !delIds.has(img.id));
            setImages(JSON.parse(JSON.stringify(nextImages)));
            imagesRef.current = JSON.parse(JSON.stringify(nextImages));
            history.push(JSON.parse(JSON.stringify(imagesRef.current)));
            setChart(imagesRef.current, selectedIdRef.current, null);
            selectShapes([]);
            selectedIdsRef.current = [];
            setSelectedId(null);
            selectedIdRef.current = null;
            setDragShape(null);
            settoolType(null);
            return;
        }
        // F7+ Unit \u6a21\u578b：\u628a\u540c groupId \u6210\u5458\u6298\u6210\u4e00\u4e2a unit，\u6309 unit \u8ba1\u7b97\u5bf9\u9f50\u76ee\u6807，\u518d\u628a offset
        // \u5e94\u7528\u5230 unit \u5168\u90e8\u6210\u5458\u4e0a，\u786e\u4fdd\u7ec4\u5408\u4f5c\u4e3a\u6574\u4f53\u5e73\u79fb、\u5185\u90e8\u76f8\u5bf9\u4f4d\u7f6e\u4e0d\u53d8。
        const tr = transformRefids.current;
        const unlockedSelectedIds = getUnlockedSelectedIds();
        const alignmentUnits = getAlignmentUnits(unlockedSelectedIds);
        if (!tr || alignmentUnits.length === 0) {
            settoolType(null);
            return;
        }

        // \u951a\u70b9：\u4ee5"\u7528\u6237\u6700\u5148\u9009\u4e2d\u7684\u5143\u7d20 / \u7ec4\u5408"\u4e3a\u53c2\u8003\u7ebf，\u5bf9\u9f50\u65f6\u951a\u70b9\u4e0d\u52a8，\u5176\u4ed6 unit \u5411\u951a\u70b9\u5bf9\u9f50
        // alignmentUnits[0] \u6765\u81ea selectedIds[0]，\u6b63\u597d\u662f\u7528\u6237\u9996\u9009\u9879；\u5982\u679c\u9996\u9009\u9879\u662f\u7ec4\u5408，\u6574\u7ec4\u4f5c\u4e3a\u4e00\u4e2a unit
        const anchorMetrics = alignmentUnits[0].metrics;
        const xl = anchorMetrics.x;                                   // \u951a\u70b9\u5de6\u8fb9
        const xr = anchorMetrics.x + anchorMetrics.width;             // \u951a\u70b9\u53f3\u8fb9
        const yt = anchorMetrics.y;                                   // \u951a\u70b9\u4e0a\u8fb9
        const yb = anchorMetrics.y + anchorMetrics.height;            // \u951a\u70b9\u4e0b\u8fb9
        const xr2 = anchorMetrics.x + anchorMetrics.width / 2;        // \u951a\u70b9\u6c34\u5e73\u4e2d\u7ebf
        const yt2 = anchorMetrics.y + anchorMetrics.height / 2;       // \u951a\u70b9\u5782\u76f4\u4e2d\u7ebf

        // equal \u7cfb\u5217：\u4ee5 unit（\u7ec4\u5408\u5916\u5305\u76d2 / \u5355\u5143\u7d20\u5916\u5305\u76d2）\u4e3a\u7c92\u5ea6\u7edf\u8ba1 maxw/maxh
        let maxh = 0, maxw = 0;
        if (type.indexOf('equal') >= 0) {
            alignmentUnits.forEach((unit) => {
                if (maxh < unit.metrics.height) maxh = unit.metrics.height;
                if (maxw < unit.metrics.width) maxw = unit.metrics.width;
            });
        }

        // \u6392\u5e8f：\u6c34\u5e73\u7b49\u8ddd\u6309 x \u5347\u5e8f、\u5782\u76f4\u7b49\u8ddd\u6309 y \u5347\u5e8f，\u5176\u5b83\u5bf9\u9f50\u4fdd\u6301 units \u539f\u987a\u5e8f
        let orderedUnitKeys = [];
        if (type === 'equallevel') {
            orderedUnitKeys = [...alignmentUnits]
                .sort((a, b) => a.metrics.x - b.metrics.x)
                .map((u) => u.key);
        } else if (type === 'equalvertical') {
            orderedUnitKeys = [...alignmentUnits]
                .sort((a, b) => a.metrics.y - b.metrics.y)
                .map((u) => u.key);
        } else {
            orderedUnitKeys = alignmentUnits.map((u) => u.key);
        }

        // \u7b49\u8ddd：\u6309 unit \u8ba1\u7b97\u6bcf\u4e2a unit \u7684\u76ee\u6807\u5750\u6807
        const equalLevelTargets = type === 'equallevel'
            ? getDistributedUnitTargets(alignmentUnits, 'x') : {};
        const equalVerticalTargets = type === 'equalvertical'
            ? getDistributedUnitTargets(alignmentUnits, 'y') : {};

        // \u590d\u5236：\u6309 unit \u6536\u96c6 groupId \u91cd\u6620\u5c04，\u786e\u4fdd\u540c\u4e00\u7ec4\u7684\u6210\u5458\u590d\u5236\u540e\u4ecd\u5c5e\u4e8e\u540c\u4e00\u65b0\u7ec4
        const copyGroupIdMap = {};
        if (type === 'copys') {
            unlockedSelectedIds.forEach((sid) => {
                const src = imagesRef.current.find((img) => img.id === sid);
                if (src && src.groupId && !copyGroupIdMap[src.groupId]) {
                    copyGroupIdMap[src.groupId] = createDerivedGroupId(src.groupId);
                }
            });
        }

        const unitMap = {};
        alignmentUnits.forEach((u) => { unitMap[u.key] = u; });

        const copyids = [];
        const nextImages = JSON.parse(JSON.stringify(imagesRef.current));

        orderedUnitKeys.forEach((unitKey) => {
            const unit = unitMap[unitKey];
            if (!unit) return;
            const width = unit.metrics.width;
            const height = unit.metrics.height;

            // 1. \u5148\u6309 unit \u7b97\u51fa\u6574\u4f53\u76ee\u6807\u5750\u6807（unit \u5916\u5305\u76d2\u5de6\u4e0a\u89d2\u5e94\u8be5\u5230\u54ea）
            let targetX = unit.metrics.x;
            let targetY = unit.metrics.y;
            switch (type) {
                case 'alginleft':     targetX = xl;             break;
                case 'alginright':    targetX = xr - width;     break;
                case 'algintop':      targetY = yt;             break;
                case 'alginbottom':   targetY = yb - height;    break;
                case 'alginvertical': targetX = xr2 - width / 2;  break;
                case 'algincenter':   targetY = yt2 - height / 2; break;
                case 'equallevel':
                    if (equalLevelTargets[unit.key] !== undefined) targetX = equalLevelTargets[unit.key];
                    break;
                case 'equalvertical':
                    if (equalVerticalTargets[unit.key] !== undefined) targetY = equalVerticalTargets[unit.key];
                    break;
                default: break;
            }
            const offsetX = targetX - unit.metrics.x;
            const offsetY = targetY - unit.metrics.y;

            // 2. \u628a offset / scale / \u590d\u5236 \u5e94\u7528\u5230 unit \u5185\u5168\u90e8\u6210\u5458（\u7ec4\u5408\u4f5c\u4e3a\u6574\u4f53\u5e73\u79fb）
            unit.memberIds.forEach((memberId) => {
                const findIndex = nextImages.findIndex((img) => img.id === memberId);
                if (findIndex === -1) return;
                let singleImageToUpdate = JSON.parse(JSON.stringify(nextImages[findIndex]));
                switch (type) {
                    case 'copys': {
                        const eleId = parseInt(new Date().getTime()).toString() + copyids.length;
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: singleImageToUpdate.x + 5,
                            y: singleImageToUpdate.y + 5,
                            id: eleId,
                            groupId: singleImageToUpdate.groupId
                                ? (copyGroupIdMap[singleImageToUpdate.groupId] || null)
                                : null,
                        };
                        copyids.push(eleId);
                        nextImages.push(singleImageToUpdate);
                        console.log(t('auto.k0373'));
                        break;
                    }
                    case 'equalhight': {
                        if (height !== 0 && maxh !== height) {
                            const scaleY = (singleImageToUpdate.scaleY || 1) * (maxh / height);
                            singleImageToUpdate = { ...singleImageToUpdate, scaleY };
                        }
                        nextImages[findIndex] = singleImageToUpdate;
                        console.log(t('auto.k0380'));
                        break;
                    }
                    case 'equalwidth': {
                        if (width !== 0 && maxw !== width) {
                            const scaleX = (singleImageToUpdate.scaleX || 1) * (maxw / width);
                            singleImageToUpdate = { ...singleImageToUpdate, scaleX };
                        }
                        nextImages[findIndex] = singleImageToUpdate;
                        console.log(t('auto.k0381'));
                        break;
                    }
                    case 'equal': {
                        const next = { ...singleImageToUpdate };
                        if (height !== 0 && maxh !== height) next.scaleY = (singleImageToUpdate.scaleY || 1) * (maxh / height);
                        if (width !== 0 && maxw !== width) next.scaleX = (singleImageToUpdate.scaleX || 1) * (maxw / width);
                        nextImages[findIndex] = next;
                        console.log(t('auto.k0382'));
                        break;
                    }
                    default: {
                        // \u5bf9\u9f50 / \u7b49\u8ddd：\u7ec4\u5408\u6240\u6709\u6210\u5458\u540c\u6b65\u504f\u79fb，\u76f8\u5bf9\u4f4d\u7f6e\u4fdd\u6301
                        if (offsetX !== 0 || offsetY !== 0) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                x: singleImageToUpdate.x + offsetX,
                                y: singleImageToUpdate.y + offsetY,
                            };
                        }
                        nextImages[findIndex] = singleImageToUpdate;
                        if (type === 'alginleft') console.log(t('auto.k0374'));
                        else if (type === 'alginright') console.log(t('auto.k0375'));
                        else if (type === 'algintop') console.log(t('auto.k0376'));
                        else if (type === 'alginbottom') console.log(t('auto.k0377'));
                        else if (type === 'alginvertical') console.log(t('auto.k0378'));
                        else if (type === 'algincenter') console.log(t('auto.k0379'));
                        else if (type === 'equalvertical') console.log(t('auto.k0383'));
                        else if (type === 'equallevel') console.log(t('auto.k0384'));
                        break;
                    }
                }
            });
        });

        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        if (type === 'copys') {
            selectShapes(copyids);
            selectedIdsRef.current = copyids;
        }
        settoolType(null);
    }
    // Comment translated to English.
    const handleToolChange = async (type) => {
        if (type === 'undo') {
            if (history.length <= 1) {
                return;
            }
            history = history.slice(0, -1);
            const previous = JSON.parse(JSON.stringify(history[history.length - 1]));
            setImages(previous);
            imagesRef.current = previous;
            onClickTap('handle');
            setChart(imagesRef.current, selectedIdRef.current, null);
        } else {
            // Comment translated to English.
            if (selectedIdRef.current !== null || selectedIdsRef.current.length !== 0) {
                if (selectedIdsRef.current.length !== 0) {
                    if (type === 'copy') {
                        settoolType('copys');
                        handleMultiToolBack('copys');
                    } else {
                        handleMultiToolBack(type);
                    }
                } else {
                    settoolType(type);
                }
            }
        }
    };
    // Comment translated to English.
    const addToBackground = (backgroundUrl) => {
        setBackgroundImage(backgroundUrl);
    };
    // Comment translated to English.
    const savePage = async (type) => {
        // \u5173\u952e：\u4fdd\u5b58\u524d\u5148\u628a Konva \u8282\u70b9\u771f\u5b9e\u4f4d\u7f6e\u540c\u6b65\u56de imagesRef，\u786e\u4fdd\u4fdd\u5b58\u5230 chart \u7684 x/y
        // \u6c38\u8fdc\u7b49\u4e8e\u89c6\u89c9\u770b\u5230\u7684\u4f4d\u7f6e。\u5426\u5219\u590d\u5236\u540e\u89c6\u89c9\u4e0a\u5bf9，\u4f46\u4fdd\u5b58\u7684\u662f imagesRef \u7684\u65e7 x/y，
        // \u5237\u65b0\u52a0\u8f7d\u540e\u5143\u7d20\u4f4d\u7f6e\u4e0e\u4fdd\u5b58\u65f6\u89c6\u89c9\u4e0d\u4e00\u81f4 → \u590d\u5236\u4f53\u9519\u4f4d。
        syncKonvaPositionsToImagesRef();
        stagejson = stageRef.current.toJSON();
        let newjson = normalizeStageForPersistence(JSON.parse(stagejson), safeStageWidth, safeStageHeight);
        const shapeMap = {};
        imagesRef.current.forEach((shape) => {
            shapeMap[shape.id] = shape;
        });
        if (newjson && newjson.children && newjson.children[0] && Array.isArray(newjson.children[0].children)) {
            newjson.children[0].children.forEach(element => {// Comment translated to English.
                if (element.attrs.id === 'canvasBackground') {
                    if (backgroundImage && backgroundImage.indexOf('#') === -1) {
                        if (backgroundImage.indexOf('/public/') > 0) {
                            element.attrs.fillPatternImage = backgroundImage.split('/public/')[1];
                        } else {
                            element.attrs.fillPatternImage = backgroundImage;
                        }
                    }
                    element.attrs.alarmCatch = alarmCatchRef.current;
                    return;
                }
                const currentShape = shapeMap[element.attrs.id];
                if (currentShape) {
                    element.attrs = JSON.parse(JSON.stringify(currentShape));
                }
            });
        }
        stagejson = JSON.stringify(newjson);
        if (type === 'preview') {// Comment translated to English.
            localStorage.setItem('stageJson', JSON.stringify(stagejson));
            window.open(httpsend.viewURL() + 'preview.html?type=preview');
        }
        if (type === 'tpl') {// Comment translated to English.
            if (!saveTplName) {
                message.error(t('auto.k0441'));
                return false;
            }
            return JSON.stringify(stagejson);
        }

        if (type === 'page') {// Comment translated to English.
            if (!savePageId || savePageId === '0') {
                message.error(t('auto.k0442'));
                return false;
            }
            setIsModalOpen(true);
            seteditModalOpen(false);
        }
        if (type === 'editpage') {// Comment translated to English.
            if (savePageId && savePageId !== '0') {// Comment translated to English.
                setIsModalOpen(true);
                seteditModalOpen(true);
            } else {
                setIsModalOpen(false);
                seteditModalOpen(false);
                newPage();
            }
        }
    }

    // Comment translated to English.
    const newPage = () => {
        setsavePageId('0');
        setsavePageType();
        setsavePageTxt();
        setsavePageName();
        setsavePagePid();
        setsavePageIndex();
        setsavePageLink();
        setshowsavePageBox(1);
        setstageWidth(1920);
        setstageHeight(1080);
        setStageDimensions({
            width: 1920,
            height: 1080,
            scalex: 1,
            scaley: 1,
        });
        setcanvasScale(100);
        // \u65b0\u5efa\u9875\u9762 → \u6362\u65b0 token（\u7528\u4e8e\u8de8\u9875\u9762\u590d\u5236\u7c98\u8d34\u5224\u5b9a）
        currentPageTokenRef.current = 'page-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
    }
    // Comment translated to English.
    const loginOut = () => {
        localStorage.clear();
        window.location.href = httpsend.mainURL() + 'login.html';
    }
    // Comment translated to English.
    const handleCanvasChange = (val) => {
        const next = Math.max(ZOOM_MIN_PERCENT, Math.min(ZOOM_MAX_PERCENT, Number(val) || 100));
        // Slider zoom: anchor at the .canvasStage scroller center to keep the original "in-place zoom" feel without losing content.
        applyZoom(next, null);
    }
    // F20 Ctrl+wheel zoom core: place the content point under the cursor back to its original screen coordinate after zoom.
    // anchorClient: { x, y } cursor viewport coords; when null, the visible center of the scroller is used.
    const applyZoom = (nextPercent, anchorClient) => {
        const scroller = containerRef.current ? containerRef.current.querySelector('.canvasStage') : null;
        const canvasEl = scroller ? scroller.querySelector('canvas') : null;
        const clamped = Math.max(ZOOM_MIN_PERCENT, Math.min(ZOOM_MAX_PERCENT, nextPercent));
        const oldScale = (stageDimensionsRef.current && stageDimensionsRef.current.scalex) || 1;
        const newScale = clamped / 100;
        if (Math.abs(newScale - oldScale) < 1e-4) return;

        // Pre-zoom: use the canvas element's own boundingRect as reference
        // (avoids the offset ambiguity caused by .canvasStage2's margin: auto centering).
        let contentX = 0;
        let contentY = 0;
        let anchorViewportX = 0;
        let anchorViewportY = 0;
        let canMeasure = false;
        if (scroller && canvasEl) {
            const scrollerRect = scroller.getBoundingClientRect();
            const canvasRect = canvasEl.getBoundingClientRect();
            anchorViewportX = anchorClient ? anchorClient.x : (scrollerRect.left + scroller.clientWidth / 2);
            anchorViewportY = anchorClient ? anchorClient.y : (scrollerRect.top + scroller.clientHeight / 2);
            // Cursor offset relative to canvas top-left in physical pixels -> convert to 1x content coordinates.
            contentX = (anchorViewportX - canvasRect.left) / oldScale;
            contentY = (anchorViewportY - canvasRect.top) / oldScale;
            canMeasure = true;
        }

        // flushSync forces the setState calls to commit synchronously so the next line can read the updated scrollWidth/scrollHeight and canvas physical size.
        flushSync(() => {
            setStageDimensions({
                scalex: newScale,
                scaley: newScale,
            });
            setcanvasScale(clamped);
        });

        if (scroller && canvasEl && canMeasure) {
            // Post-zoom canvas position (margin: auto may still be active or may have switched to flush-left).
            const newCanvasRect = canvasEl.getBoundingClientRect();
            // We want the same content point (contentX, contentY) to remain at the anchor in the viewport.
            // The point's post-zoom viewport X = newCanvasRect.left + contentX * newScale,
            // and the difference from the target anchorViewportX is offset via scrollLeft.
            const dx = (newCanvasRect.left + contentX * newScale) - anchorViewportX;
            const dy = (newCanvasRect.top + contentY * newScale) - anchorViewportY;
            const targetScrollLeft = scroller.scrollLeft + dx;
            const targetScrollTop = scroller.scrollTop + dy;
            const maxScrollLeft = Math.max(0, scroller.scrollWidth - scroller.clientWidth);
            const maxScrollTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
            scroller.scrollLeft = Math.max(0, Math.min(maxScrollLeft, targetScrollLeft));
            scroller.scrollTop = Math.max(0, Math.min(maxScrollTop, targetScrollTop));
        }
    }
    // F20 Ctrl+wheel zoom: register a native wheel listener on .canvasBody (passive:false is required to preventDefault).
    useEffect(() => {
        if (isPreview) return undefined;
        const container = containerRef.current;
        if (!container) return undefined;
        const onWheel = (e) => {
            if (!(e.ctrlKey || e.metaKey)) return;
            // Only handle wheel zoom inside the canvas (avoid swallowing scroll on the asset panel and elsewhere).
            const scroller = container.querySelector('.canvasStage');
            if (!scroller || !scroller.contains(e.target)) return;
            e.preventDefault();
            const delta = e.deltaY || e.wheelDelta || 0;
            if (!delta) return;
            const direction = delta > 0 ? -1 : 1; // wheel up = zoom in, wheel down = zoom out.
            const cur = canvasScaleRef.current || 100;
            const next = cur + direction * ZOOM_WHEEL_STEP_PERCENT;
            applyZoom(next, { x: e.clientX, y: e.clientY });
        };
        container.addEventListener('wheel', onWheel, { passive: false });
        return () => container.removeEventListener('wheel', onWheel);
    }, [isPreview]);
    // F23 middle-button drag: pan the canvas by holding the wheel button.
    // - Cursor switches to grabbing while panning
    // - preventDefault on auxclick / scroll-anchor so the browser doesn't open the autoscroll cursor
    useEffect(() => {
        if (isPreview) return undefined;
        const container = containerRef.current;
        if (!container) return undefined;
        const scroller = container.querySelector('.canvasStage');
        if (!scroller) return undefined;

        let panning = false;
        let lastClientX = 0;
        let lastClientY = 0;
        let prevCursor = '';

        const stopPan = () => {
            if (!panning) return;
            panning = false;
            scroller.style.cursor = prevCursor || '';
            window.removeEventListener('mousemove', onMouseMove, true);
            window.removeEventListener('mouseup', onMouseUp, true);
        };

        const onMouseMove = (e) => {
            if (!panning) return;
            const dx = e.clientX - lastClientX;
            const dy = e.clientY - lastClientY;
            lastClientX = e.clientX;
            lastClientY = e.clientY;
            // Drag direction = mouse direction, so subtract the delta from scroll position.
            scroller.scrollLeft -= dx;
            scroller.scrollTop -= dy;
            e.preventDefault();
        };

        const onMouseUp = (e) => {
            if (e.button === 1) {
                e.preventDefault();
                stopPan();
            }
        };

        const onMouseDown = (e) => {
            if (e.button !== 1) return; // only middle button triggers pan
            if (!scroller.contains(e.target)) return;
            // Stop propagation in capture phase so Konva never sees this mousedown
            // and won't start dragging the element under the cursor.
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            panning = true;
            lastClientX = e.clientX;
            lastClientY = e.clientY;
            prevCursor = scroller.style.cursor;
            scroller.style.cursor = 'grabbing';
            window.addEventListener('mousemove', onMouseMove, true);
            window.addEventListener('mouseup', onMouseUp, true);
        };

        // Suppress the default middle-click autoscroll cursor in browsers that show it.
        const onAuxClick = (e) => {
            if (e.button === 1 && scroller.contains(e.target)) e.preventDefault();
        };

        // Register in capture phase so we run before Konva's bubble-phase mousedown handler.
        scroller.addEventListener('mousedown', onMouseDown, true);
        scroller.addEventListener('auxclick', onAuxClick);
        return () => {
            scroller.removeEventListener('mousedown', onMouseDown, true);
            scroller.removeEventListener('auxclick', onAuxClick);
            stopPan();
        };
    }, [isPreview, savePageId]);
    // F20 reset view: zoom back to 100% and scroll the viewport to the origin.
    const handleResetView = () => {
        const scroller = containerRef.current ? containerRef.current.querySelector('.canvasStage') : null;
        flushSync(() => {
            setStageDimensions({ scalex: 1, scaley: 1 });
            setcanvasScale(100);
        });
        if (scroller) {
            scroller.scrollLeft = 0;
            scroller.scrollTop = 0;
        }
    }
    // Comment translated to English.
    return (
        <>
            {!isPreview &&
                <main className="designerShell" aria-label={t('auto.k0387')}>
                    <div className="top designerHeader">
                        <div className="topLeft">
                            <label>{t('auto.k0387')}</label>
                        </div>
                        <div className="topCenter">
                            <div className="topGroup topToolList designerEditTools">
                                <ToolList
                                    MultiSelect={selectedIds.length !== 0}
                                    handleTool={(type) => {
                                        handleToolChange(type);
                                    }} />
                            </div>
                            <div className="topGroup topControls designerSelectionTools">
                                <Button
                                    type="default"
                                    icon={<KeyOutlined />}
                                    aria-label={t('designer.shortcuts.title')}
                                    title={t('designer.shortcuts.title')}
                                    onClick={() => setKeyboardShortcutsOpen(true)}
                                >{t('designer.shortcuts.trigger')}</Button>
                                <Button type={snapEnabled ? 'primary' : 'default'} onClick={() => {
                                    setSnapEnabled((prev) => {
                                        if (prev) clearSnapGuides();
                                        return !prev;
                                    });
                                }}>{snapEnabled ? t('designer.snapOn') : t('designer.snapOff')}</Button>
                                <select className="topControl" value={String(snapThreshold)} onChange={(e) => setSnapThreshold(Number(e.target.value))}>
                                    <option value="4">4px</option>
                                    <option value="6">6px</option>
                                    <option value="8">8px</option>
                                    <option value="10">10px</option>
                                </select>
                                <Button type="default" disabled={!canGroupSelection} onClick={groupSelectedShapes}>{t('designer.group')}</Button>
                                <Button type="default" disabled={!canUngroupSelection} onClick={ungroupSelectedShapes}>{t('designer.ungroup')}</Button>
                            </div>
                        </div>
                        <div className="topRight">
                            <span className={`saveStatus ${saveStatusText === modifiedStatus ? 'dirty' : ''}`}>{saveStatusText}</span>
                            <Button type="primary" className="topActionBtn" onClick={() => setIsOutOpen(true)}>{t('auto.k0388')}</Button>
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => savePage('preview')}>{t('auto.k0389')}</Button>}
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => setshowsaveTplBox(1)}>{t('auto.k0390')}</Button>}
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => savePage('page')}>{t('auto.k0391')}</Button>}
                            <Button type="primary" className="topActionBtn" onClick={() => savePage('editpage')}>{t('auto.k0392')}</Button>
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => {
                                // F24 capture current multi-selection so the dialog only lists those devices.
                                // Falls back to single-selected id; null means scan whole canvas.
                                const ids = Array.isArray(selectedIdsRef.current) && selectedIdsRef.current.length > 0
                                    ? [...selectedIdsRef.current]
                                    : (selectedIdRef.current ? [selectedIdRef.current] : null);
                                resetScopeIdsRef.current = ids;
                                setresetBox(true);
                            }}>{t('auto.k0393')}</Button>}
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={openTextReplaceDialog}>{t('textReplace.triggerLabel')}</Button>}
                        </div>
                    </div>
                    <ItemBox
                        onChangeDragUrl={handleItemDragUrl}
                        isChanged={savePageId} />
                    <div className="eleAttrs designerInspector">
                        <ul>
                            <li className={`${showIndex === 1 ? 'check' : ''} ${tabFlash === 'component' ? 'tabFlash' : ''}`.trim()} onClick={() => setshowIndex(1)}>{t('auto.k0394')}</li>
                            <li className={showIndex === 2 ? 'check' : ''} onClick={() => setshowIndex(2)}>{t('auto.k0395')}</li>
                            <li className={showIndex === 3 ? 'check' : ''} onClick={() => setshowIndex(3)}>{t('designer.interfaceProperties')}</li>
                        </ul>
                        {showIndex === 1 &&
                            <ElementAttr
                                MultiSelect={selectedIds.length !== 0}
                                dragShape={dragShape}
                                useSlaveId={useSlaveId}
                                onChange={(dragShape, clickEvnt) => {
                                    handleShapeChange(dragShape, clickEvnt);
                                }} />}
                        {showIndex === 2 &&
                            <><ElementSvg
                                imgUrl={backgroundImage}
                                alarmCatch={alarmCatch}
                                onSelChange={(val) => {
                                    setalarmCatch(val);
                                    alarmCatchRef.current = val;
                                }}
                                onChange={(backgroundUrl) => {
                                    addToBackground(backgroundUrl);
                                }} />
                            </>}
                        {showIndex === 3 && (() => {
                            const structure = getInterfaceStructure();
                            return (
                                <div className="interfaceAttrs">
                                    <div className="attrTitle">{t('designer.ungroupedElements')}</div>
                                    {structure.singles.length === 0 && <div className="attrBox">{t('designer.noUngroupedElements')}</div>}
                                    {structure.singles.map((item) => (
                                        <div
                                            key={item.id}
                                            className="attrBox interfaceItem"
                                            onMouseEnter={() => setHoverHighlightIds([item.id])}
                                            onMouseLeave={() => setHoverHighlightIds([])}
                                            onClick={() => handleStructureItemClick(item.id, false)}
                                        >
                                            <label>{item.label}</label>
                                            <span>{item.id}</span>
                                        </div>
                                    ))}
                                    <div className="attrTitle">{t('designer.groupedElements')}</div>
                                    {structure.groups.length === 0 && <div className="attrBox">{t('designer.noGroups')}</div>}
                                    {structure.groups.map((group) => (
                                        <div key={group.groupId} className="interfaceGroup">
                                            <div
                                                className="attrBox interfaceGroupHeader"
                                                onMouseEnter={() => setHoverHighlightIds(group.members.map((m) => m.id))}
                                                onMouseLeave={() => setHoverHighlightIds([])}
                                                onClick={() => handleStructureItemClick(group.members.length > 0 ? group.members[0].id : '', true)}
                                            >
                                                <label>{group.label}</label>
                                                <span>{group.members.length} {t('designer.elementsSuffix')}</span>
                                            </div>
                                            {group.members.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="attrBox interfaceItem interfaceGroupMember"
                                                    onMouseEnter={() => setHoverHighlightIds([item.id])}
                                                    onMouseLeave={() => setHoverHighlightIds([])}
                                                    onClick={() => handleStructureItemClick(item.id, true)}
                                                >
                                                    <label>{item.label}</label>
                                                    <span>{item.id}</span>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                                </div>
                            );
                        })()}
                    </div>
                    <div
                        className="canvasBody designerCanvasBody"
                        ref={containerRef}
                        onDrop={handleOnDrop}
                        onDragOver={(e) => e.preventDefault()}
                    >
                        <div className="canvasRange" style={{ display: 'flex', alignItems: 'center' }}>
                            <span
                                className="canvasResetBtn"
                                title={t('common.resetView')}
                                onClick={handleResetView}
                                style={{
                                    display: 'inline-block',
                                    padding: '0 8px',
                                    marginRight: '8px',
                                    height: '20px',
                                    lineHeight: '20px',
                                    fontSize: '12px',
                                    color: '#fff',
                                    background: '#148cf1',
                                    border: '1px solid #148cf1',
                                    borderRadius: '3px',
                                    cursor: 'pointer',
                                    userSelect: 'none',
                                }}
                            >{t('common.resetView')}</span>
                            <img src="Images/icon/narrow.png" style={{ 'width': '15px', 'marginRight': '5px' }} alt={t('auto.k0329')} />
                            <input type="range" min={ZOOM_MIN_PERCENT} max={ZOOM_MAX_PERCENT} onChange={(e) => handleCanvasChange(e.target.value)} value={canvasScale} />
                            <img src="Images/icon/enlarge.png" style={{ 'width': '15px', 'marginLeft': '5px' }} alt={t('auto.k0330')} />
                        </div>
                        {savePageId === '0' && <Stage
                            className="canvasStage canvasStage2"
                            width={displayedStageWidth}
                            height={displayedStageHeight}
                            scaleX={stageDimensions.scalex}
                            scaleY={stageDimensions.scaley}
                            ref={stageRef}
                        >
                            <Layer ref={layerRef} style={{ 'backgroundColor': '#fff' }}>
                                <Group>
                                    <Text text={t('auto.k0331')} fill={DESIGNER_EMPTY_STATE_TEXT} fontSize={25} lineHeight={5} padding={50} />
                                </Group>
                            </Layer>
                        </Stage>}
                        {savePageId !== '0' && (savePageType === '1' ? <Stage
                            className="canvasStage canvasStage2"
                            width={displayedStageWidth}
                            height={displayedStageHeight}
                            scaleX={stageDimensions.scalex}
                            scaleY={stageDimensions.scaley}
                            ref={stageRef}
                            onClick={onClickTap}
                            onTap={onClickTap}
                            onMouseDown={onMouseDown}
                            onMouseUp={onMouseUp}
                            onMouseMove={onMouseMove}
                            key='stage001'
                        >
                            <Layer ref={layerRef} style={{ 'backgroundColor': '#fff' }}>
                                {(backgroundImage && typeof backgroundImage === "string") && (
                                    <SvgBackground
                                        backgroundUrl={backgroundImage}
                                        width={safeStageWidth}
                                        height={safeStageHeight} />
                                )}
                                {images.map((shape) => {
                                    const isUnlocked = shape.draggable !== false;
                                    const isPrimarySelected = isUnlocked && shape.id === selectedId && selectedIds.length === 0;
                                    const hasSelectionFrame = isUnlocked && (selectedIds.includes(shape.id) || marqueeHoverIds.includes(shape.id) || (shape.id === selectedId && selectedIds.length === 0));
                                    const isElementHover = hoverElementIds.includes(shape.id);
                                    const isAlignmentAnchor = alignmentAnchorIds.includes(shape.id);
                                    return (<ConElement
                                        id={shape.id}
                                        key={shape.id}
                                        shapeProps={shape}
                                        isSelected={isPrimarySelected}
                                        showSelectionFrame={hasSelectionFrame}
                                        isAlignmentAnchor={isAlignmentAnchor}
                                        isHoverHighlighted={hoverHighlightIds.includes(shape.id)}
                                        isElementHover={isElementHover}
                                        onHoverEnter={(s) => {
                                            // \u62d6\u52a8\u671f\u95f4\u7981\u7528 hover \u72b6\u6001\u66f4\u65b0，\u907f\u514d setState \u89e6\u53d1 React \u91cd\u6e32\u67d3\u628a\u540c\u7ec4\u6210\u5458\u7684 Konva \u8282\u70b9\u56de\u62c9\u5230\u65e7\u4f4d\u7f6e
                                            if (multiDragRef.current.active) return;
                                            // \u9501\u5b9a\u5143\u7d20：\u53ea\u4eae\u81ea\u8eab\u7ea2\u8fb9，\u4e0d\u8054\u52a8\u7ec4
                                            if (s.draggable === false) {
                                                setHoverElementIds([s.id]);
                                                return;
                                            }
                                            // \u672a\u9501\u5b9a\u5143\u7d20：\u6574\u7ec4\u8054\u52a8，\u4f46\u8fc7\u6ee4\u6389\u7ec4\u5185\u9501\u5b9a\u6210\u5458
                                            const groupIds = getExpandedSelectionIds(s.id).filter((id) => {
                                                const member = imagesRef.current.find((m) => m.id === id);
                                                return member && member.draggable !== false;
                                            });
                                            setHoverElementIds(groupIds.length > 0 ? groupIds : [s.id]);
                                        }}
                                        onHoverLeave={() => {
                                            if (multiDragRef.current.active) return;
                                            setHoverElementIds([]);
                                        }}
                                        toolType={shape.id === selectedId ? toolType : null}
                                        onToolBack={(newShapeProps, type) => {
                                            handleToolBack(newShapeProps, type);
                                        }}
                                        onDragStart={(e, currentShape) => handleShapeDragStart(e, currentShape)}
                                        onDragMove={(e, currentShape) => handleShapeDragMove(e, currentShape)}
                                        onSelect={(evt) => {
                                            // Ctrl / Cmd：\u4ea4\u7ed9 onClickTap \u8d70\u591a\u9009\u903b\u8f91
                                            if (evt && evt.evt && (evt.evt.ctrlKey || evt.evt.metaKey)) {
                                                return;
                                            }
                                            // \u62d6\u52a8\u5f00\u59cb：dragmove \u61d2\u521d\u59cb\u5316 multiDragRef，\u8fd9\u91cc\u8df3\u8fc7 setState \u907f\u514d React \u91cd\u6e32\u67d3\u62fd\u56de Konva \u8282\u70b9
                                            if (evt && evt.evt && evt.evt.__draggingSelection) {
                                                return;
                                            }
                                            // \u7ec4\u5408\u6210\u5458\u88ab\u70b9\u4e2d：\u6574\u7ec4\u540c\u6b65\u7eb3\u5165 selectedIds（\u6309\u7ec4\u6574\u4f53\u9009\u4e2d）
                                            const groupSelectionIds = getExpandedSelectionIds(shape.id);
                                            if (groupSelectionIds.length > 1) {
                                                // \u5df2\u7ecf\u662f\u540c\u6837\u7684\u6574\u7ec4\u5728\u9009\u4e2d：\u8df3\u8fc7 setState，\u907f\u514d\u91cd\u6e32\u67d3\u56de\u62c9\u62d6\u52a8\u4e2d\u7684 Konva \u8282\u70b9
                                                const same = selectedIdsRef.current.length === groupSelectionIds.length
                                                    && groupSelectionIds.every((id) => selectedIdsRef.current.includes(id));
                                                if (same && selectedIdRef.current === shape.id) {
                                                    return;
                                                }
                                                selectShapes(groupSelectionIds);
                                                selectedIdsRef.current = groupSelectionIds;
                                                setSelectedId(shape.id);
                                                selectedIdRef.current = shape.id;
                                                setDragShape(shape);
                                                return;
                                            }
                                            // \u666e\u901a\u5355\u5143\u7d20：\u4fdd\u6301\u539f\u6709\u5355\u9009\u884c\u4e3a
                                            if (selectedId !== shape.id) {
                                                setSelectedId(null);
                                                setDragShape(null);
                                                selectShapes([]);
                                                selectedIdsRef.current = [];
                                                setTimeout(() => {
                                                    setSelectedId(shape.id);
                                                    selectedIdRef.current = shape.id;
                                                    setDragShape(shape);
                                                });
                                            }
                                        }}
                                        onChange={(newShapeProps) => {
                                            // \u591a\u9009\u62d6\u52a8 dragend：\u8ddf\u968f\u6210\u5458 (\u975e\u88ab\u62d6\u90a3\u4e2a) \u7684 onChange \u76f4\u63a5 return，
                                            // \u907f\u514d\u6bcf\u4e2a\u6210\u5458\u90fd push \u4e00\u6b21 history \u5bfc\u81f4\u64a4\u9500\u8981\u6309 N \u6b21
                                            if (pendingDragFollowerIdsRef.current.has(shape.id)) {
                                                pendingDragFollowerIdsRef.current.delete(shape.id);
                                                return;
                                            }
                                            // \u591a\u9009\u62d6\u52a8：\u88ab\u62d6\u5143\u7d20\u7684 onChange \u4f18\u5148\u628a pendingPositions \u4e00\u6b21\u6027 commit，\u907f\u514d\u5355\u70b9 handleShapeChange \u628a\u6574\u7ec4\u5e26\u6b6a
                                            if (multiDragRef.current.active && multiDragRef.current.draggedId === shape.id && multiDragRef.current.pendingPositions) {
                                                commitMultiDragPositions(multiDragRef.current.pendingPositions);
                                                multiDragRef.current = {
                                                    active: false,
                                                    draggedId: null,
                                                    startPositions: {},
                                                    pendingPositions: null,
                                                };
                                                // \u88ab\u62d6\u5143\u7d20\u5df2 commit，\u5269\u4f59 follower \u4e0d\u9700\u8981\u518d\u4fdd\u7559\u8ffd\u8e2a
                                                pendingDragFollowerIdsRef.current = new Set();
                                                clearSnapGuides();
                                                return;
                                            }
                                            handleShapeChange(newShapeProps, shape.id);
                                        }} />
                                    );
                                })}
                                {/* \u78c1\u5438\u5f15\u5bfc\u7ebf：\u59cb\u7ec8\u6e32\u67d3，\u7531 ref imperative \u63a7\u5236 visible/points/\u6837\u5f0f，\u4e0d\u89e6\u53d1 React \u91cd\u6e32\u67d3 */}
                                <Line
                                    ref={snapGuideVRef}
                                    visible={false}
                                    points={[0, 0, 0, 0]}
                                    stroke="#148cf1"
                                    strokeWidth={1}
                                    dash={[6, 4]}
                                    listening={false}
                                />
                                <Line
                                    ref={snapGuideHRef}
                                    visible={false}
                                    points={[0, 0, 0, 0]}
                                    stroke="#148cf1"
                                    strokeWidth={1}
                                    dash={[6, 4]}
                                    listening={false}
                                />
                                <Transformer
                                    ref={transformRefids}
                                    flipEnabled={false}
                                    // \u53c2\u8003 your-feature \u5206\u652f：\u901a\u8fc7\u9690\u85cf\u89c6\u89c9\u5143\u7d20\u8ba9 Stage \u7ea7 Transformer \u4e0d\u663e\u793a\u8fb9\u6846/\u951a\u70b9，
                                    // \u540c\u65f6\u4fdd\u7559\u9ed8\u8ba4 listening=true \u4ee5\u4fdd\u8bc1 transformRefids.current.nodes(nodes) \u6b63\u5e38\u5de5\u4f5c。
                                    // \u5143\u7d20\u81ea\u5df1\u7684\u8fb9\u6846\u7531 ConElement \u5185\u90e8\u7684 transformRef \u5355\u72ec\u7ed8\u5236（\u591a\u9009\u6bcf\u4e2a\u6210\u5458\u5404\u81ea\u663e\u793a）。
                                    // \u4e4b\u524d\u7528 listening={selectedIds.length<=1} \u5207\u6362 listening \u4f1a\u7834\u574f Konva Transformer \u5185\u90e8
                                    // nodes \u8ddf\u8e2a / \u4e8b\u4ef6 hook \u94fe\u8def，\u5bfc\u81f4"\u5148\u70b9\u51fb\u7ec4\u5408 → \u518d\u62d6\u52a8 → \u62d6\u4e0d\u52a8"。
                                    borderEnabled={false}
                                    anchorSize={0}
                                    rotateEnabled={false}
                                    boundBoxFunc={(oldBox, newBox) => getBoundedTransformerBox(oldBox, newBox)} />
                                <Rect fill="rgba(0,0,255,0.5)" ref={selectionRectRef} />
                            </Layer>
                        </Stage> : <Stage
                            className="canvasStage canvasStage2"
                            width={displayedStageWidth}
                            height={displayedStageHeight}
                            scaleX={stageDimensions.scalex}
                            scaleY={stageDimensions.scaley}
                            ref={stageRef}
                            key='stage002'
                        >
                            <Layer ref={layerRef} style={{ 'backgroundColor': '#fff' }}>
                                <Group>
                                    <Text text={t('auto.k0332')} fill={DESIGNER_EMPTY_STATE_TEXT} fontSize={25} lineHeight={5} padding={50} />
                                </Group>
                            </Layer>
                        </Stage>)}
                    </div>
                    <KeyboardShortcutsModal
                        open={keyboardShortcutsOpen}
                        onClose={() => setKeyboardShortcutsOpen(false)}
                    />
                    <div className="layui-layer" id="saveTpl" style={showsaveTplBox === 1 ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('auto.k0396')}</div>
                        <div className="layui-layer-content">
                            <div>
                                <label>{t('auto.k0397')}</label>
                                <input style={{ width: '167px' }} type="text" onChange={(e) => setsaveTplName(e.target.value)} value={saveTplName} />
                            </div>
                        </div>
                        <span className="layui-layer-setwin" onClick={() => setshowsaveTplBox(0)}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                let savejson = await savePage('tpl');
                                let res = await httpsend.getDataLocal('saveTpl', { name: saveTplName, tplcon: savejson });
                                if (res) {
                                    message.success(t('auto.k0443')); setSavedStatus(savedStatus); dirtyRef.current = false;
                                }
                                setsaveTplName('');
                                setshowsaveTplBox(0);
                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                    <div className="layui-layer" id="savePage" style={showsavePageBox === 1 ? { 'display': 'block' } : { 'display': 'none' }} key={showsavePageBox}>
                        <div className="layui-layer-title">{t('auto.k0398')}</div>
                        <div className="layui-layer-content">
                            <div>
                                <label>{t('auto.k0399')}</label>
                                <select onChange={(e) => setsavePageType(e.target.value)} defaultValue={savePageType} style={{ 'width': '167px' }}>
                                    <option value=''>{t('auto.k0229')}</option>
                                    <option value='1'>{t('auto.k0400')}</option>
                                    <option value='2'>{t('auto.k0401')}</option>
                                    <option value='3'>{t('auto.k0402')}</option>
                                </select>
                            </div>
                            {savePageType === '1' && <div>
                                <label>{t('auto.k0403')}</label>
                                <input style={{ width: '76px' }} type="text" onChange={(e) => setstageWidth(normalizeStageSize(e.target.value, safeStageWidth))} defaultValue={stageWidth} />
                                <span> * </span>
                                <input style={{ width: '76px' }} type="text" onChange={(e) => setstageHeight(normalizeStageSize(e.target.value, safeStageHeight))} defaultValue={stageHeight} />
                            </div>}
                            <div>
                                <label>{t('auto.k0404')}</label>
                                <input style={{ width: '167px' }} type="text" onChange={(e) => setsavePageName(e.target.value)} defaultValue={savePageName} />
                            </div>
                            <div>
                                <label>{t('auto.k0405')}</label>
                                <Cascader options={savePagePidSel} onChange={(val) => setsavePagePid(val[val.length - 1])} changeOnSelect style={{ 'width': '167px' }} />
                            </div>
                            <div>
                                <label>{t('auto.k0406')}</label>
                                <input style={{ width: '167px' }} type="number" onChange={(e) => setsavePageIndex(e.target.value)} defaultValue={savePageIndex} />
                            </div>
                            {savePageType === '3' && <div>
                                <label>{t('auto.k0407')}</label>
                                <input style={{ width: '167px' }} type="text" onChange={(e) => setsavePageLink(e.target.value)} defaultValue={savePageLink} />
                            </div>}
                        </div>
                        <span className="layui-layer-setwin" onClick={() => setshowsavePageBox(0)}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                if (!savePageType) {
                                    message.error(t('auto.k0408'));
                                    return;
                                }
                                if (!savePageName) {
                                    message.error(t('auto.k0409'));
                                    return;
                                }
                                if (!savePagePid) {
                                    message.error(t('auto.k0410'));
                                    return;
                                }
                                if (!savePageIndex) {
                                    message.error(t('auto.k0411'));
                                    return;
                                }
                                if (savePageType === '3' && !savePageLink) {
                                    message.error(t('auto.k0412'));
                                    return;
                                }
                                let savefilename = (new Date().getTime()).toString();
                                let res = await httpsend.getData('CreateDmpageKey', {
                                    PageType: savePageType,
                                    PageName: savePageName,
                                    pid: savePagePid,
                                    PageIndex: savePageIndex,
                                    ProId: 0,
                                    PageTop: -1,
                                    PageTxt: savePageType === '3' ? savePageLink : savefilename,
                                });
                                if (res.code === 100) {
                                    console.log(t('auto.k0413'))
                                    setStageDimensions({
                                        width: stageWidth,
                                        height: stageHeight,
                                        scalex: 1,
                                        scaley: 1,
                                    });
                                    setcanvasScale(100);
                                    setsavePageId(res.data.id);
                                    setsavePageTxt(res.data.PageTxt);
                                    multiDragRef.current = {
                                        active: false,
                                        draggedId: null,
                                        startPositions: {},
                                        pendingPositions: null,
                                    };
                                    clearSnapGuides();
                                    selectShapes([]);
                                    selectedIdsRef.current = [];
                                    setSelectedId(null);
                                    selectedIdRef.current = null;
                                    setDragShape(null);
                                    settoolType(null);
                                    setHoverHighlightIds([]);
                                    setImages([]);
                                    setBackgroundImage(null);
                                    setalarmCatch('1')
                                    alarmCatchRef.current = '1';
                                    imagesRef.current = [];
                                    setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);
                                    history = [];
                                    if (savePageType === '1') {// Comment translated to English.
                                        const pageJson = buildPageJson();
                                        let fileres = await httpsend.getDataLocal('savePage', { name: savefilename, pagecon: JSON.stringify(pageJson) });
                                        if (fileres.code === 100) {
                                            message.success(t('auto.k0443')); setSavedStatus(savedStatus); dirtyRef.current = false;
                                        } else {
                                            message.error(t('auto.k0444'));
                                        }
                                    } else {
                                        message.success(t('auto.k0443')); setSavedStatus(savedStatus); dirtyRef.current = false;
                                    }
                                } else {
                                    message.error(t('auto.k0445'));
                                }
                                setshowsavePageBox(0);
                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                    <div className="layui-layer" style={isModalOpen ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('auto.k0218')}</div>
                        {!editModalOpen && <div className="layui-layer-content">{t('auto.k0414')}<span style={{ color: '#148cf1', fontSize: 18 }}>《{savePageName}》</span>{t('auto.k0415')}</div>}
                        {editModalOpen && <div className="layui-layer-content">{t('auto.k0416')}<span style={{ color: '#148cf1', fontSize: 18 }}>《{savePageName}》</span>{t('auto.k0417')}</div>}
                        <span className="layui-layer-setwin" onClick={() => {
                            if (editModalOpen) {// Comment translated to English.
                                newPage()
                            }
                            setIsModalOpen(false);
                            seteditModalOpen(false);
                        }}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                let savejson = JSON.stringify(stagejson);
                                // let savefilename = (new Date().getTime()).toString();
                                // Comment translated to English.
                                if (savePageId && savePageName) {
                                    let params = {
                                        id: savePageId
                                    }
                                    // if (savePageType === '1') params.PageTxt = savefilename;
                                    let res = await httpsend.getData('ChangeDmpageKey', params);
                                    if (res.code === 100) {
                                        // Comment translated to English.
                                        if (savePageType === '1') {
                                            let res2 = await httpsend.getDataLocal('savePage', { name: savePageTxt, pagecon: savejson });
                                            if (res2.code === 100) {
                                                message.success(t('auto.k0443')); setSavedStatus(savedStatus); dirtyRef.current = false;
                                            } else {
                                                message.error(t('auto.k0444'));
                                            }
                                        } else {
                                            message.success(t('auto.k0443')); setSavedStatus(savedStatus); dirtyRef.current = false;
                                        }
                                    } else {
                                        message.error(t('auto.k0445'));
                                    }
                                } else {
                                    message.error(t('auto.k0446'));
                                }
                                if (editModalOpen) {
                                    newPage()
                                }
                                setIsModalOpen(false);
                                seteditModalOpen(false);
                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                    <div className="layui-layer" style={isOutOpen ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('auto.k0218')}</div>
                        <div className="layui-layer-content">{t('auto.k0418')}</div>
                        <span className="layui-layer-setwin" onClick={() => setIsOutOpen(false)}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                setIsOutOpen(false);
                                loginOut();
                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                    {/* Comment translated to English. */}
                    <div className="layui-layer" style={resetBox ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('auto.k0419')}</div>
                        <div className="layui-layer-content">
                            {pagedevList.length > 0 && pagedevList.map((el) => {
                                return (<><div style={{ width: '50%', 'float': 'left' }}>
                                    <label style={{ width: '60px' }}>{t('auto.k0420')}</label>
                                    <input defaultValue={el.label} readOnly style={{ 'width': 'calc(100% - 70px)' }} />
                                </div>
                                    <div style={{ width: '50%', 'float': 'right' }}>
                                        <label style={{ width: '60px' }}>{t('auto.k0421')}</label>
                                        <Select
                                            showSearch
                                            placeholder={t('auto.k0230')}
                                            optionFilterProp="children"
                                            onChange={(val) => {
                                                if (val) {
                                                    el.newid = val
                                                }
                                            }}
                                            onSearch={ondataDevOptionSearch}
                                            filterOption={filterOption}
                                            options={el.children}
                                            style={{ 'width': 'calc(100% - 60px)' }}
                                        />
                                    </div></>)
                            })}
                            {pagedevList.length === 0 && <span>{t('auto.k0422')}</span>}
                        </div>
                        <span className="layui-layer-setwin" onClick={() => {
                            resetScopeIdsRef.current = null;
                            setresetBox(false);
                        }}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                // console.log(pagedevList);
                                // Comment translated to English.
                                if (pagedevList.length !== 0) {
                                    // F24 scope filter: limit replacement to the captured selection (if any),
                                    // otherwise apply across the whole canvas as before.
                                    const scopeIds = resetScopeIdsRef.current;
                                    const inScope = (id) => !Array.isArray(scopeIds) || scopeIds.length === 0 || scopeIds.includes(id);
                                    let newimags = [];
                                    imagesRef.current.forEach(shapeProps => {
                                        if (inScope(shapeProps.id) && shapeProps.moduleJson && shapeProps.moduleJson.attrs.dataKey) {
                                            let dataKey = shapeProps.moduleJson.attrs.dataKey;
                                            if (dataKey && dataKey.length === 1) {
                                                dataKey.forEach((el) => {
                                                    // Comment translated to English.
                                                    let findpagedevindex = pagedevList.findIndex(v => String(v.value) === String(el.key || el.deveventskey))
                                                    if (findpagedevindex !== -1 && pagedevList[findpagedevindex]['newid']) {
                                                        if (el.key) el.key = pagedevList[findpagedevindex]['newid'];
                                                        if (el.deveventskey) el.deveventskey = pagedevList[findpagedevindex]['newid'];
                                                    }
                                                })
                                            }
                                        }
                                        newimags.push(shapeProps);
                                    })

                                    setImages(newimags);
                                    imagesRef.current = newimags;
                                    setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);
                                    history.push(JSON.parse(JSON.stringify(imagesRef.current)));
                                }
                                resetScopeIdsRef.current = null;
                                setresetBox(false);

                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                    {/* \u5207\u6362\u754c\u9762\u63d0\u793a\u4fdd\u5b58：\u5f53\u524d\u9875\u9762\u6709\u672a\u4fdd\u5b58\u6539\u52a8\u65f6\u5207\u5230\u5176\u5b83\u9875\u9762\u4f1a\u5f39\u8fd9\u4e2a */}
                    <div className="layui-layer" style={switchConfirmBox ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('designer.switchTitle')}</div>
                        <div className="layui-layer-content">
                            {t('designer.switchCurrentPrefix')}<span style={{ color: '#148cf1', fontSize: 18 }}>{savePageName}</span>{t('designer.switchCurrentSuffix')}
                        </div>
                        <span className="layui-layer-setwin" onClick={() => {
                            // \u53d6\u6d88：\u7559\u5728\u5f53\u524d\u9875\u9762，\u4e22\u5f03\u6302\u8d77\u7684\u5207\u6362\u52a8\u4f5c
                            pendingSwitchRef.current = null;
                            setSwitchConfirmBox(false);
                        }}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                // Save-and-switch: run the same blocking save path as the "save page" modal.
                                // On any failure, surface the real backend message and DO NOT switch — the user can retry or pick "discard".
                                const pending = pendingSwitchRef.current;
                                if (!savePageId || savePageId === '0' || !savePageTxt || savePageType !== '1') {
                                    message.error(t('auto.k0442'));
                                    return;
                                }
                                const savejsonRaw = buildPageJson();
                                if (!savejsonRaw) {
                                    message.error(t('auto.k0444'));
                                    return;
                                }
                                // dealStringPage uses JSON.parse(JSON.parse(...)), so we must double-stringify
                                // to match the format the "save page" confirm button writes.
                                const savejson = JSON.stringify(savejsonRaw);
                                let res;
                                try {
                                    res = await httpsend.getData('ChangeDmpageKey', { id: savePageId });
                                } catch (e) {
                                    return;
                                }
                                if (!res || res.code !== 100) {
                                    return;
                                }
                                let res2;
                                try {
                                    res2 = await httpsend.getDataLocal('savePage', { name: savePageTxt, pagecon: savejson });
                                } catch (e) {
                                    return;
                                }
                                if (!res2 || res2.code !== 100) {
                                    message.error(t('auto.k0444'));
                                    return;
                                }
                                setSavedStatus(savedStatus);
                                dirtyRef.current = false;
                                lastSavedStageJsonRef.current = savejson;
                                pendingSwitchRef.current = null;
                                setSwitchConfirmBox(false);
                                if (pending) {
                                    await performItemDragUrl(pending.dragUrl, pending.dragAttrs, pending.type);
                                }
                            }}>{t('designer.saveAndSwitch')}</Button>
                            <Button onClick={async () => {
                                // Discard-and-switch: drop dirty changes and proceed with the pending navigation.
                                const pending = pendingSwitchRef.current;
                                pendingSwitchRef.current = null;
                                setSwitchConfirmBox(false);
                                dirtyRef.current = false;
                                if (pending) {
                                    await performItemDragUrl(pending.dragUrl, pending.dragAttrs, pending.type);
                                }
                            }}>{t('designer.discardAndSwitch')}</Button>
                            <Button onClick={() => {
                                // \u53d6\u6d88：\u7559\u5728\u5f53\u524d\u9875\u9762
                                pendingSwitchRef.current = null;
                                setSwitchConfirmBox(false);
                            }}>{t('textReplace.cancel')}</Button>
                        </div>
                    </div>
                    {/* F22 Text replace dialog: find / replace within selected text-like shapes (Ctrl+H). */}
                    <div className="layui-layer" style={textReplaceBox ? { 'display': 'block' } : { 'display': 'none' }}>
                        <div className="layui-layer-title">{t('textReplace.triggerLabel')}</div>
                        <div className="layui-layer-content">
                            <div style={{ marginBottom: '10px' }}>
                                <label style={{ display: 'inline-block', width: '80px' }}>{t('textReplace.find')}</label>
                                <input
                                    type="text"
                                    value={textReplaceFind}
                                    onChange={(e) => setTextReplaceFind(e.target.value)}
                                    style={{ width: 'calc(100% - 90px)' }}
                                    autoFocus
                                />
                            </div>
                            <div>
                                <label style={{ display: 'inline-block', width: '80px' }}>{t('textReplace.replaceTo')}</label>
                                <input
                                    type="text"
                                    value={textReplaceTo}
                                    onChange={(e) => setTextReplaceTo(e.target.value)}
                                    style={{ width: 'calc(100% - 90px)' }}
                                />
                            </div>
                        </div>
                        <span className="layui-layer-setwin" onClick={() => setTextReplaceBox(false)}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={() => {
                                if (!textReplaceFind) {
                                    message.warning(t('textReplace.mustHaveFind'));
                                    return;
                                }
                                const changed = applyTextReplace(textReplaceFind, textReplaceTo);
                                if (changed === 0) {
                                    message.warning(t('textReplace.nothingMatched'));
                                    return;
                                }
                                message.success(t('textReplace.replacedCount').replace('{count}', String(changed)));
                                setTextReplaceBox(false);
                            }}>{t('auto.k0202')}</Button>
                            <Button onClick={() => setTextReplaceBox(false)}>{t('textReplace.cancel')}</Button>
                        </div>
                    </div>
                </main>
            }
        </>
    );
}
export default DesignerApp;
