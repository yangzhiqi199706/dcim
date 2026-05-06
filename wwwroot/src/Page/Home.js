import React, { useRef, useState, useEffect } from "react";
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
import PreviewElement from "./PreviewElement";
import PreviewDeal from "./PreviewDeal";
import { buildMainApiUrl } from '../config/endpoints';
import { t } from '../i18n';

import Konva from "konva";

const SNAP_GUIDE_OFFSET = 24;

let history = [];
const params = new URLSearchParams(window.location.search);
const isPreview = params.get('type') ? true : false;// Comment translated to English.
const isSwiper = params.get('swiper') ? true : false;// Comment translated to English.
const txttitle = params.get('title') || '';// Comment translated to English.
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

let previewjson;// Comment translated to English.
const PAGE_DESIGNER_CLIPBOARD_KEY = 'page_designer_clipboard';


function Home() {
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
    const multiDragRef = useRef({
        active: false,
        draggedId: null,
        startPositions: {},
        pendingPositions: null,
    });
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
    const [imagesstatic, setImagesstatic] = useState([]);
    const [imagesdata, setImagesdata] = useState([]);
    // Comment translated to English.
    const [isOutOpen, setIsOutOpen] = useState(false);
    // Comment translated to English.
    const [resetBox, setresetBox] = useState(false);
    const [pagedevList, setpagedevList] = useState([]);// Comment translated to English.
    // Comment translated to English.
    const [stageWidth, setstageWidth] = useState(1920);
    const stageWidthRef = useRef(stageWidth);
    const [stageHeight, setstageHeight] = useState(1080);
    const stageHeightRef = useRef(stageHeight);
    // Comment translated to English.
    const [canvasScale, setcanvasScale] = useState(100);
    const [snapEnabled, setSnapEnabled] = useState(true);
    const [snapThreshold, setSnapThreshold] = useState(6);
    const [hoverHighlightIds, setHoverHighlightIds] = useState([]);
    const [tabFlash, setTabFlash] = useState('');
    const [saveStatusText, setSaveStatusText] = useState('已保存');
    const [lastAutoSaveTime, setLastAutoSaveTime] = useState('');
    const pendingPageSwitchRef = useRef(null);
    const lastSavedStageJsonRef = useRef('');
    const saveStatusTimerRef = useRef(null);
    const autoSaveTimerRef = useRef(null);

    const formatTime = (date = new Date()) => {
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        return `${hours}:${minutes}:${seconds}`;
    };

    const setSavedStatus = (text = '已保存') => {
        if (saveStatusTimerRef.current) {
            clearTimeout(saveStatusTimerRef.current);
            saveStatusTimerRef.current = null;
        }
        setSaveStatusText(text);
        if (text === '已自动保存') {
            setLastAutoSaveTime(formatTime());
        } else if (text === '已保存') {
            setLastAutoSaveTime('');
        }
        if (text !== '已修改') {
            saveStatusTimerRef.current = setTimeout(() => {
                setSaveStatusText('已保存');
                saveStatusTimerRef.current = null;
            }, 1800);
        }
    };

    const buildStageJson = () => {
        let nextStageJson = stageRef.current.toJSON();
        if (backgroundImage) {
            let newjson = JSON.parse(nextStageJson);
            newjson.children[0].children.forEach(element => {
                if (element.attrs.id === 'canvasBackground') {
                    if (backgroundImage.indexOf('#') === -1) {
                        if (backgroundImage.indexOf('/public/') > 0) {
                            element.attrs.fillPatternImage = backgroundImage.split('/public/')[1];
                        } else {
                            element.attrs.fillPatternImage = backgroundImage;
                        }
                    }
                    element.attrs.alarmCatch = alarmCatchRef.current;
                }
            });
            nextStageJson = JSON.stringify(newjson);
        }
        stagejson = nextStageJson;
        return nextStageJson;
    };

    const silentSaveExistingPage = async () => {
        if (!savePageId || savePageId === '0' || savePageType !== '1' || !savePageTxt) return true;
        const savejson = buildStageJson();
        let res = await httpsend.getData('ChangeDmpageKey', { id: savePageId });
        if (!res || res.code !== 100) {
            message.error(t('auto.k0445'));
            return false;
        }
        let res2 = await httpsend.getDataLocal('savePage', { name: savePageTxt, pagecon: savejson });
        if (!res2 || res2.code !== 100) {
            message.error(t('auto.k0444'));
            return false;
        }
        lastSavedStageJsonRef.current = savejson;
        setSavedStatus('已自动保存');
        return true;
    };

    const createAndSavePage = async ({ silent = false } = {}) => {
        if (!savePageType) {
            if (!silent) message.error(t('auto.k0408'));
            return { ok: false, needInfo: true };
        }
        if (!savePageName) {
            if (!silent) message.error(t('auto.k0409'));
            return { ok: false, needInfo: true };
        }
        if (!savePagePid) {
            if (!silent) message.error(t('auto.k0410'));
            return { ok: false, needInfo: true };
        }
        if (!savePageIndex) {
            if (!silent) message.error(t('auto.k0411'));
            return { ok: false, needInfo: true };
        }
        if (savePageType === '3' && !savePageLink) {
            if (!silent) message.error(t('auto.k0412'));
            return { ok: false, needInfo: true };
        }
        const savefilename = (new Date().getTime()).toString();
        const pageContent = buildStageJson();
        let res = await httpsend.getData('CreateDmpageKey', {
            PageType: savePageType,
            PageName: savePageName,
            pid: savePagePid,
            PageIndex: savePageIndex,
            ProId: 0,
            PageTop: -1,
            PageTxt: savePageType === '3' ? savePageLink : savefilename,
        });
        if (!res || res.code !== 100) {
            if (!silent) message.error(t('auto.k0445'));
            return { ok: false, needInfo: false };
        }
        if (savePageType === '1') {
            let fileres = await httpsend.getDataLocal('savePage', { name: savefilename, pagecon: pageContent });
            if (!fileres || fileres.code !== 100) {
                if (!silent) message.error(t('auto.k0444'));
                return { ok: false, needInfo: false };
            }
        }
        setsavePageId(res.data.id);
        setsavePageTxt(res.data.PageTxt);
        lastSavedStageJsonRef.current = pageContent;
        if (silent) {
            setSavedStatus('已自动保存');
        } else {
            setSavedStatus('已保存');
        }
        if (!silent) {
            message.success(t('auto.k0443'));
        }
        return { ok: true, needInfo: false };
    };

    const tryAutoSaveBeforeSwitch = async () => {
        const currentStageJson = buildStageJson();
        const hasSavedSnapshot = !!lastSavedStageJsonRef.current;
        const isDirty = !hasSavedSnapshot || currentStageJson !== lastSavedStageJsonRef.current;
        if (!isDirty) return true;
        if (savePageId && savePageId !== '0') {
            const saved = await silentSaveExistingPage();
            if (saved) {
                lastSavedStageJsonRef.current = currentStageJson;
            }
            return saved;
        }
        const hasContent = Array.isArray(imagesRef.current) && imagesRef.current.length > 0;
        const hasBackground = !!backgroundImage;
        if (!hasContent && !hasBackground) return true;
        const createResult = await createAndSavePage({ silent: true });
        if (createResult.ok) {
            lastSavedStageJsonRef.current = currentStageJson;
            return true;
        }
        if (createResult.needInfo) {
            setshowsavePageBox(1);
            return false;
        }
        return false;
    };

    const continuePendingPageSwitch = useRef(async () => { });

    // Comment translated to English.
    const runIdleAutoSave = async (scheduledPageId) => {
        if (isPreview) return;
        if (!scheduledPageId || String(savePageId || '') !== String(scheduledPageId)) return;
        const currentStageJson = buildStageJson();
        const hasSavedSnapshot = !!lastSavedStageJsonRef.current;
        const isDirty = !hasSavedSnapshot || currentStageJson !== lastSavedStageJsonRef.current;
        if (!isDirty) {
            scheduleIdleAutoSave();
            return;
        }
        if (savePageId && savePageId !== '0') {
            const saved = await silentSaveExistingPage();
            if (saved) {
                lastSavedStageJsonRef.current = currentStageJson;
            }
            scheduleIdleAutoSave();
            return;
        }
        const hasContent = Array.isArray(imagesRef.current) && imagesRef.current.length > 0;
        const hasBackground = !!backgroundImage;
        if (!hasContent && !hasBackground) {
            scheduleIdleAutoSave();
            return;
        }
        const infoReady = !!(savePageType && savePageName && savePagePid && savePageIndex && (savePageType !== '3' || savePageLink));
        if (!infoReady) {
            scheduleIdleAutoSave();
            return;
        }
        const createResult = await createAndSavePage({ silent: true });
        if (createResult.ok) {
            lastSavedStageJsonRef.current = currentStageJson;
        }
        scheduleIdleAutoSave();
    };

    const scheduleIdleAutoSave = () => {
        if (autoSaveTimerRef.current) {
            clearTimeout(autoSaveTimerRef.current);
            autoSaveTimerRef.current = null;
        }
        if (isPreview || !savePageType) return;
        const scheduledPageId = savePageId || '__draft__';
        autoSaveTimerRef.current = setTimeout(() => {
            runIdleAutoSave(scheduledPageId);
        }, 5 * 60 * 1000);
    };

    const getStructureItemLabel = (shape, index = 0) => {
        if (!shape || !shape.moduleJson) return `元素 ${index + 1}`;
        const firstChild = shape.moduleJson.children && shape.moduleJson.children[0] ? shape.moduleJson.children[0] : null;
        const attrs = firstChild && firstChild.attrs ? firstChild.attrs : {};
        return attrs.text || attrs.name || firstChild.className || `元素 ${index + 1}`;
    };

    useEffect(() => {
        return () => {
            if (saveStatusTimerRef.current) {
                clearTimeout(saveStatusTimerRef.current);
            }
            if (autoSaveTimerRef.current) {
                clearTimeout(autoSaveTimerRef.current);
            }
        };
    }, []);

    useEffect(() => {
        if (!stageRef.current) return;
        const currentStageJson = buildStageJson();
        if (!lastSavedStageJsonRef.current) {
            setSaveStatusText('已保存');
            return;
        }
        setSaveStatusText(currentStageJson === lastSavedStageJsonRef.current ? '已保存' : '已修改');
    }, [images, backgroundImage, alarmCatch, stageWidth, stageHeight]);

    useEffect(() => {
        scheduleIdleAutoSave();
    }, [savePageId, savePageType]);

    useEffect(() => {
        continuePendingPageSwitch.current = async () => {
            const pending = pendingPageSwitchRef.current;
            if (!pending) return;
            pendingPageSwitchRef.current = null;
            await handleItemDragUrl(pending.dragUrl, pending.dragAttrs, pending.type, { skipAutoSave: true });
        };
    }, [dragUrl, dragAttrs, savePageId, savePageType, savePageTxt, backgroundImage]);

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
                        label: `组合 ${Object.keys(groupMap).length + 1}`,
                        members: [],
                    };
                }
                groupMap[shape.groupId].members.push(item);
            } else {
                singles.push(item);
            }
        });

        return {
            singles,
            groups: Object.values(groupMap),
        };
    };

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

    const isShapeUnlocked = (shapeOrId) => {
        const shape = typeof shapeOrId === 'string'
            ? imagesRef.current.find((item) => item.id === shapeOrId)
            : shapeOrId;
        return !!(shape && shape.draggable !== false);
    };
    const getExpandedSelectionIds = (shapeOrId) => {
        const shape = typeof shapeOrId === 'string'
            ? imagesRef.current.find((item) => item.id === shapeOrId)
            : shapeOrId;
        if (!shape) return [];
        if (!shape.groupId) {
            return [shape.id];
        }
        const memberIds = getGroupMemberIds(shape.groupId);
        return memberIds.length > 0 ? memberIds : [shape.id];
    };

    const getUnlockedExpandedSelectionIds = (shapeOrId) => {
        const expandedIds = getExpandedSelectionIds(shapeOrId);
        return expandedIds.filter((id) => isShapeUnlocked(id));
    };

    const createDerivedGroupId = (sourceGroupId) => {
        if (!sourceGroupId) return null;
        return `group_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    };

    const expandDragSelectionIds = (ids, draggedShapeId) => {
        const result = new Set();
        const baseIds = Array.isArray(ids) ? ids : [];
        const mergedIds = [...new Set([...(draggedShapeId ? [draggedShapeId] : []), ...baseIds])];

        mergedIds.forEach((id) => {
            const memberIds = getUnlockedExpandedSelectionIds(id);
            memberIds.forEach((memberId) => result.add(memberId));
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

    const canGroupSelection = selectedIds.length >= 2;
    const canUngroupSelection = isSelectionSingleGroup();

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

    useEffect(() => {
        if (!savePageId || savePageId === '0') return;
        setshowIndex(1);
        setTabFlash('component');
        const timer = setTimeout(() => {
            setTabFlash('');
        }, 650);
        return () => clearTimeout(timer);
    }, [savePageId]);

    const handleStructureItemClick = (shapeId, useGroupSelection = true) => {
        if (!shapeId) return;
        selectStructureTarget(shapeId, useGroupSelection);
        scrollToStructureTarget(shapeId, useGroupSelection);
        setshowIndex(1);
        setTabFlash('component');
        setTimeout(() => {
            setTabFlash('');
        }, 650);
    };

    const selectAllShapes = () => {
        const allIds = imagesRef.current.map((shape) => shape.id);
        selectShapes(allIds);
        selectedIdsRef.current = allIds;
        if (allIds.length > 0) {
            const firstUnlocked = imagesRef.current.find((shape) => shape.draggable !== false);
            const anchorShape = firstUnlocked || imagesRef.current[0];
            setSelectedId(anchorShape.id);
            selectedIdRef.current = anchorShape.id;
            setDragShape(anchorShape);
        }
    };

    const getClipboardSelectionShapes = () => {
        let targetIds = [];
        if (selectedIdsRef.current.length > 0) {
            targetIds = expandDragSelectionIds(selectedIdsRef.current, null);
        } else if (selectedIdRef.current !== null) {
            targetIds = getUnlockedExpandedSelectionIds(selectedIdRef.current);
        }
        if (!Array.isArray(targetIds) || targetIds.length === 0) {
            return [];
        }
        return imagesRef.current.filter((shape) => targetIds.includes(shape.id));
    };

    const writeClipboard = (shapes) => {
        const payload = {
            type: 'page-elements',
            copiedAt: Date.now(),
            sourcePageId: savePageId,
            elements: JSON.parse(JSON.stringify(shapes || [])),
        };
        localStorage.setItem(PAGE_DESIGNER_CLIPBOARD_KEY, JSON.stringify(payload));
    };

    const readClipboard = () => {
        const raw = localStorage.getItem(PAGE_DESIGNER_CLIPBOARD_KEY);
        if (!raw) return null;
        try {
            const payload = JSON.parse(raw);
            if (!payload || payload.type !== 'page-elements' || !Array.isArray(payload.elements)) {
                return null;
            }
            return payload;
        } catch (error) {
            return null;
        }
    };

    const getClipboardBounds = (elements) => {
        if (!Array.isArray(elements) || elements.length === 0) return null;
        const metricsList = elements.map((shape) => getShapeRenderMetrics(shape, null)).filter(Boolean);
        if (metricsList.length === 0) return null;

        const left = Math.min(...metricsList.map((item) => item.left));
        const top = Math.min(...metricsList.map((item) => item.top));
        const right = Math.max(...metricsList.map((item) => item.right));
        const bottom = Math.max(...metricsList.map((item) => item.bottom));

        return {
            left,
            top,
            right,
            bottom,
            width: right - left,
            height: bottom - top,
            centerX: left + (right - left) / 2,
            centerY: top + (bottom - top) / 2,
        };
    };

    const getViewportCenterOnCanvas = () => {
        const scroller = containerRef.current ? containerRef.current.querySelector('.canvasStage') : null;
        if (!scroller) {
            return {
                x: stageWidth / 2,
                y: stageHeight / 2,
            };
        }

        const scrollLeft = scroller.scrollLeft || 0;
        const scrollTop = scroller.scrollTop || 0;
        const centerX = (scrollLeft + scroller.clientWidth / 2) / (stageDimensions.scalex || 1);
        const centerY = (scrollTop + scroller.clientHeight / 2) / (stageDimensions.scaley || 1);

        return {
            x: centerX,
            y: centerY,
        };
    };

    const copySelectionToClipboard = () => {
        const selectionShapes = getClipboardSelectionShapes();
        if (selectionShapes.length === 0) {
            message.warning('当前没有可复制的未锁定元素');
            return;
        }
        writeClipboard(selectionShapes);
        message.success(`已复制 ${selectionShapes.length} 个元素`);
    };

    const cutSelectionToClipboard = () => {
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
        message.success(`已剪切 ${selectionShapes.length} 个元素`);
    };
    const pasteClipboardSelection = () => {
        const payload = readClipboard();
        if (!payload || !payload.elements || payload.elements.length === 0) {
            message.warning('暂无可粘贴内容');
            return;
        }

        const groupIdMap = {};
        const pastedIds = [];
        const nextImages = JSON.parse(JSON.stringify(imagesRef.current));
        const clipboardBounds = getClipboardBounds(payload.elements);
        const viewportCenter = getViewportCenterOnCanvas();
        const offsetX = clipboardBounds ? (viewportCenter.x - clipboardBounds.centerX + 8) : 8;
        const offsetY = clipboardBounds ? (viewportCenter.y - clipboardBounds.centerY + 8) : 8;

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
        message.success(`已粘贴 ${pastedIds.length} 个元素`);
    };

    const toggleSelectionLockState = () => {
        const targetIds = selectedIdsRef.current.length > 0
            ? selectedIdsRef.current
            : (selectedIdRef.current !== null ? [selectedIdRef.current] : []);
        if (targetIds.length === 0) return;

        const targetShapes = imagesRef.current.filter((shape) => targetIds.includes(shape.id));
        if (targetShapes.length === 0) return;

        const allLocked = targetShapes.every((shape) => shape.draggable === false);
        const nextImages = imagesRef.current.map((shape) => (
            targetIds.includes(shape.id)
                ? { ...shape, draggable: allLocked ? true : false }
                : shape
        ));

        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));

        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
    };


    const getevData = async (callback) => {
        let res = await httpsend.getData('GetDeviceListKey', {
            ComboBox: "all"
        });
        let devList = [];
        if (res) {
            res.data.forEach((val) => {
                devList.push({
                    value: val.id,
                    label: val.DeviceName,
                    code: val.ProtocolCode,
                    codeName: val.ProtocolName,
                    onlyCode: val.OnlyCode,
                })
            })
            callback(devList)
        }
    };

    useEffect(() => {
        if (resetBox) {
            getevData(function (devList) {
                let pagedev = [];
                imagesRef.current.forEach(shapeProps => {
                    if (shapeProps.moduleJson && shapeProps.moduleJson.attrs.dataKey) {
                        let dataKey = shapeProps.moduleJson.attrs.dataKey;
                        if (dataKey && dataKey.length === 1) {
                            dataKey.forEach((el) => {
                                if (el.key || el.deveventskey) {
                                    let findpagedevindex = pagedev.findIndex(v => (v.value === el.key || v.value === el.deveventskey))
                                    if (findpagedevindex === -1) {
                                        let finddevindex = devList.findIndex(v => (v.value === el.key || v.value === el.deveventskey))
                                        if (finddevindex === -1) {
                                            let finddevonlyindex = devList.findIndex(v => (v.onlyCode === el.key || v.onlyCode === el.deveventskey))
                                            if (finddevonlyindex !== -1) {
                                                pagedev.push({
                                                    value: devList[finddevonlyindex]['onlyCode'],
                                                    label: devList[finddevonlyindex]['label'],
                                                    code: devList[finddevonlyindex]['code'],
                                                    codeName: devList[finddevonlyindex]['codeName'],
                                                    children: devList
                                                })
                                            }
                                        } else {
                                            pagedev.push({
                                                value: el.key ? el.key : el.deveventskey,
                                                label: devList[finddevindex]['label'],
                                                code: devList[finddevindex]['code'],
                                                codeName: devList[finddevindex]['codeName'],
                                                children: devList
                                            })
                                        }
                                    }
                                }
                            })
                        }
                    }
                })
                setpagedevList(pagedev);
            });
        } else {
            setpagedevList([]);
        }
    }, [resetBox]);
    const filterOption = (input, option) => (option && option.label).toLowerCase().includes(input.toLowerCase());
    const ondataDevOptionSearch = (value) => { };

    // Comment translated to English.
    // Comment translated to English.
    // const [showUrlBox, setshowUrlBox] = useState(false);
    // const [showUrl, setshowUrl] = useState();

    // Comment translated to English.
    // const stageWidth = window.innerWidth,
    //     stageHeight = window.innerHeight;
    // const stageWidth = 1920,//1730
    //     stageHeight = 1080;//829

    // Comment translated to English.
    const [stageDimensions, setStageDimensions] = useState({
        width: stageWidth,
        height: stageHeight,
        scalex: 1,
        scaley: 1
    });
    const [snapGuides, setSnapGuides] = useState({
        vertical: null,
        horizontal: null,
    });

    const clearSnapGuides = () => {
        setSnapGuides({
            vertical: null,
            horizontal: null,
        });
    };

    const getShapeRenderMetrics = (shape, stageNode) => {
        if (!shape || !shape.moduleJson || !shape.moduleJson.children || shape.moduleJson.children.length === 0) {
            return null;
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
            x,
            y,
            width: actualWidth,
            height: actualHeight,
            left: x,
            centerX: x + actualWidth / 2,
            right: x + actualWidth,
            top: y,
            centerY: y + actualHeight / 2,
            bottom: y + actualHeight,
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
            x: left,
            y: top,
            left,
            top,
            right,
            bottom,
            width: right - left,
            height: bottom - top,
            centerX: left + (right - left) / 2,
            centerY: top + (bottom - top) / 2,
        };
    };

    const buildGuideCandidates = (excludeIds = []) => {
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const excluded = new Set(excludeIds);
        const candidates = {
            vertical: [],
            horizontal: [],
        };

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
        stageGuides.vertical.forEach((value) => {
            candidates.vertical.push({ value, top: 0, bottom: stageHeight });
        });
        stageGuides.horizontal.forEach((value) => {
            candidates.horizontal.push({ value, left: 0, right: stageWidth });
        });

        return candidates;
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

        if (matchX) {
            snappedX += matchX.guide.value - matchX.edge.value;
        }
        if (matchY) {
            snappedY += matchY.guide.value - matchY.edge.value;
        }

        const nextMetrics = {
            ...metrics,
            x: snappedX,
            y: snappedY,
            left: snappedX,
            right: snappedX + metrics.width,
            top: snappedY,
            bottom: snappedY + metrics.height,
            centerX: snappedX + metrics.width / 2,
            centerY: snappedY + metrics.height / 2,
        };

        return {
            matchX,
            matchY,
            snappedMetrics: nextMetrics,
        };
    };

    const applyMultiDragPositions = (positionMap) => {
        if (!positionMap) return;
        Object.keys(positionMap).forEach((id) => {
            const stage = stageRef.current ? stageRef.current.getStage() : null;
            const node = stage ? stage.findOne('#' + id) : null;
            if (node) {
                node.position(positionMap[id]);
            }
        });
    };

    const commitMultiDragPositions = (positionMap) => {
        if (!positionMap) return;
        const nextImages = imagesRef.current.map((shape) => (
            positionMap[shape.id]
                ? { ...shape, x: positionMap[shape.id].x, y: positionMap[shape.id].y }
                : shape
        ));
        setImages(JSON.parse(JSON.stringify(nextImages)));
        imagesRef.current = JSON.parse(JSON.stringify(nextImages));

        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
        setChart(imagesRef.current, selectedIdRef.current, null);
        selectShapes([...selectedIdsRef.current]);
    };



    const getBestSnapMatch = (edges, guideCandidates, axis) => {
        let bestMatch = null;

        edges.forEach((edge) => {
            guideCandidates.forEach((guide) => {
                const distance = Math.abs(edge.value - guide.value);
                if (distance > snapThreshold) return;
                if (!bestMatch || distance < bestMatch.distance) {
                    bestMatch = {
                        axis,
                        edge,
                        guide,
                        distance,
                    };
                }
            });
        });

        return bestMatch;
    };

    const buildSnapGuideLine = (snapX, snapY, metrics, matchX, matchY) => {
        return {
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
        };
    };

    const applySnapForShape = (node, shape) => {
        if (!node || !shape) return;
        const stage = stageRef.current ? stageRef.current.getStage() : null;
        const stageNode = stage ? stage.findOne('#' + shape.id) : null;
        const metrics = getShapeRenderMetrics({ ...shape, x: node.x(), y: node.y() }, stageNode || node);
        if (!metrics) return;

        if (!snapEnabled) {
            const boundedPosition = getBoundedDragPosition(metrics, metrics.x, metrics.y);
            node.position(boundedPosition);
            clearSnapGuides();
            return;
        }

        const { matchX, matchY, snappedMetrics } = getSnappedMetrics(metrics, [shape.id]);
        const boundedPosition = getBoundedDragPosition(snappedMetrics, snappedMetrics.x, snappedMetrics.y);
        const boundedMetrics = {
            ...snappedMetrics,
            x: boundedPosition.x,
            y: boundedPosition.y,
            left: boundedPosition.x,
            right: boundedPosition.x + snappedMetrics.width,
            top: boundedPosition.y,
            bottom: boundedPosition.y + snappedMetrics.height,
            centerX: boundedPosition.x + snappedMetrics.width / 2,
            centerY: boundedPosition.y + snappedMetrics.height / 2,
        };

        node.position({
            x: boundedMetrics.x,
            y: boundedMetrics.y,
        });

        if (matchX || matchY) {
            setSnapGuides(buildSnapGuideLine(
                matchX ? matchX.guide.value : null,
                matchY ? matchY.guide.value : null,
                boundedMetrics,
                matchX,
                matchY,
            ));
            return;
        }

        clearSnapGuides();
    };

    const handleShapeDragMove = (e, shape) => {
        const expandedSelectedIds = expandDragSelectionIds(selectedIdsRef.current, shape.id);
        const isMultiDrag = Array.isArray(expandedSelectedIds) && expandedSelectedIds.length > 1 && expandedSelectedIds.includes(shape.id);

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

        if (!multiDragRef.current.active || multiDragRef.current.draggedId !== shape.id) {
            const startPositions = {};
            expandedSelectedIds.forEach((id) => {
                const currentShape = imagesRef.current.find((item) => item.id === id);
                if (currentShape) {
                    startPositions[id] = {
                        x: currentShape.x,
                        y: currentShape.y,
                    };
                }
            });
            selectedIdsRef.current = expandedSelectedIds;
            selectShapes(expandedSelectedIds);
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

        let deltaX = e.target.x() - startPosition.x;
        let deltaY = e.target.y() - startPosition.y;
        let nextPositions = {};
        expandedSelectedIds.forEach((id) => {
            const basePos = multiDragRef.current.startPositions[id];
            if (basePos) {
                nextPositions[id] = {
                    x: basePos.x + deltaX,
                    y: basePos.y + deltaY,
                };
            }
        });

        if (snapEnabled) {
            const groupMetrics = buildGroupMetricsFromIds(expandedSelectedIds, nextPositions);
            if (groupMetrics) {
                const { matchX, matchY, snappedMetrics } = getSnappedMetrics(groupMetrics, expandedSelectedIds);
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
                    setSnapGuides(buildSnapGuideLine(
                        matchX ? matchX.guide.value : null,
                        matchY ? matchY.guide.value : null,
                        snappedMetrics,
                        matchX,
                        matchY,
                    ));
                } else {
                    clearSnapGuides();
                }
            }
        }

        nextPositions = getBoundedMultiDragPositions(nextPositions, expandedSelectedIds);
        applyMultiDragPositions(nextPositions);
        multiDragRef.current.pendingPositions = nextPositions;
    };

    // Comment translated to English.
    const getAlignmentUnits = (ids) => {
        if (!Array.isArray(ids) || ids.length === 0) return [];
        const units = [];
        const visited = new Set();

        ids.forEach((id) => {
            if (visited.has(id)) return;
            const shape = imagesRef.current.find((item) => item.id === id);
            if (!shape) return;

            const groupId = getShapeGroupId(shape);
            if (groupId) {
                const memberIds = ids.filter((selectedId) => getShapeGroupId(selectedId) === groupId);
                memberIds.forEach((memberId) => visited.add(memberId));
                const metrics = buildGroupMetricsFromIds(memberIds);
                if (metrics) {
                    units.push({
                        key: groupId,
                        memberIds,
                        metrics,
                        isGroup: true,
                    });
                }
                return;
            }

            visited.add(id);
            const stage = stageRef.current ? stageRef.current.getStage() : null;
            const stageNode = stage ? stage.findOne('#' + id) : null;
            const metrics = getShapeRenderMetrics(shape, stageNode);
            if (metrics) {
                units.push({
                    key: id,
                    memberIds: [id],
                    metrics,
                    isGroup: false,
                });
            }
        });

        return units;
    };



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

    const getBoundedMultiDragPositions = (positionMap, ids) => {
        if (!positionMap || !Array.isArray(ids) || ids.length === 0) return positionMap;
        const groupMetrics = buildGroupMetricsFromIds(ids, positionMap);
        if (!groupMetrics) return positionMap;

        const boundedGroup = getBoundedDragPosition(groupMetrics, groupMetrics.x, groupMetrics.y);
        const offsetX = boundedGroup.x - groupMetrics.x;
        const offsetY = boundedGroup.y - groupMetrics.y;

        if (offsetX === 0 && offsetY === 0) {
            return positionMap;
        }

        return Object.keys(positionMap).reduce((acc, id) => {
            acc[id] = {
                x: positionMap[id].x + offsetX,
                y: positionMap[id].y + offsetY,
            };
            return acc;
        }, {});
    };

    const getBoundedTransformerBox = (oldBox, newBox) => {
        if (!newBox) return oldBox;
        if (newBox.width < 5 || newBox.height < 5) {
            return oldBox;
        }

        let nextBox = {
            ...newBox,
        };

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

    const handleResize = (stageWidth, stageHeight) => {
        // let sceneWidth = containerRef.current.clientWidth;
        // let scale = sceneWidth / stageWidth;
        let sceneWidth = window.innerWidth;//1730
        // alert(sceneWidth);
        // let sceneHeight = window.innerHeight;//829
        // let scaley = sceneHeight / stageHeight;
        let scalex = sceneWidth / stageWidth;
        console.log(new Date() + t('auto.k0334'))
        // console.log(stageWidth)
        // console.log(stageHeight)
        // console.log(scalex)
        // console.log(stageWidth * scalex)
        // console.log(stageHeight * scalex)
        setStageDimensions({
            width: stageWidth * scalex,
            height: stageHeight * scalex,
            scalex: scalex,
            scaley: scalex,
        });
    };
    // Comment translated to English.
    const handlepredata = (previewjson) => {
        let tplimages = [];// Comment translated to English.
        let tplstaticimages = [];// Comment translated to English.
        if (previewjson) {
            setstageWidth(previewjson.attrs.width);
            stageWidthRef.current = previewjson.attrs.width;
            setstageHeight(previewjson.attrs.height);
            stageHeightRef.current = previewjson.attrs.height;
            setStageDimensions({
                width: stageWidth,
                height: stageHeight,
                scalex: 1,
                scaley: 1,
            });
            handleResize(previewjson.attrs.width, previewjson.attrs.height);
            previewjson.children[0].children.forEach((element) => {
                if (element.attrs.id !== 'canvasBackground' && element.attrs.moduleJson) {
                    if ((element.attrs.moduleJson.attrs.dataKey && element.attrs.moduleJson.attrs.dataKey.length !== 0) || (element.attrs.moduleJson.children && element.attrs.moduleJson.children[0].attrs.cat === 'alarmpie') ||
                        (element.attrs.moduleJson.children && element.attrs.moduleJson.children[0].className === 'alarmList') ||
                        element.attrs.moduleJson.children[0].attrs.name === 'ipImage') {
                        tplimages.push(element.attrs);
                    } else {
                        tplstaticimages.push(element.attrs);
                    }
                }
                if (element.attrs.id === 'canvasBackground') {
                    if (element.attrs.fill) {
                        setBackgroundImage(element.attrs.fill);
                    }
                    if (element.attrs.fillPatternImage) {
                        if (element.attrs.fillPatternImage.indexOf('/public/') > 0) {
                            setBackgroundImage(element.attrs.fillPatternImage.split('/public/')[1]);
                        } else {
                            setBackgroundImage(element.attrs.fillPatternImage);
                        }
                    }
                    if (element.attrs.alarmCatch) {
                        setalarmCatch(element.attrs.alarmCatch)
                        alarmCatchRef.current = element.attrs.alarmCatch;
                    }
                }
            });
        }
        setImagesstatic(tplstaticimages);
        // return PreviewDeal.PreviewDeal(tplimages, procotol, allDevcom, historyData, paramData, snmplist);
        // return PreviewDeal.PreviewDeal(tplimages, procotol, allDevcom, historyData, paramData, allsnmplist, historyparamData, alarmData)
    }
    // Comment translated to English.
    useEffect(() => {
        const onKeyDown = (e) => {
            if (e.ctrlKey && e.shiftKey && (e.key === 'J' || e.key === 'j')) {
                e.preventDefault();
                ungroupSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'C' || e.key === 'c')) {
                copySelectionToClipboard();
            } else if (e.ctrlKey && (e.key === 'X' || e.key === 'x')) {
                cutSelectionToClipboard();
            } else if (e.ctrlKey && (e.key === 'V' || e.key === 'v')) {
                pasteClipboardSelection();
            } else if (e.ctrlKey && (e.key === 'A' || e.key === 'a')) {
                e.preventDefault();
                selectAllShapes();
            } else if (e.ctrlKey && (e.key === 'J' || e.key === 'j')) {
                e.preventDefault();
                groupSelectedShapes();
            } else if (e.ctrlKey && (e.key === 'K' || e.key === 'k')) {
                e.preventDefault();
                toggleSelectionLockState();
            } else if (e.ctrlKey && e.key === 'ArrowUp') {
                handleToolChange('up');
            } else if (e.ctrlKey && (e.key === 'Z' || e.key === 'z')) {
                handleToolChange('undo');
            } else if (e.ctrlKey && e.key === 'ArrowDown') {
                handleToolChange('down');
            } else if (e.ctrlKey && (e.key === 'E' || e.key === 'e')) {
                handleToolChange('top');
            } else if (e.ctrlKey && (e.key === 'B' || e.key === 'b')) {
                handleToolChange('bottom');
            } else if (e.ctrlKey && (e.key === 'S' || e.key === 's')) {
                if (savePageId !== '0' && savePageType === '1') {
                    savePage('page')
                }
            } else if (e.key === 'Delete') {
                handleToolChange('del');
            } else {
                return;
            }
        }
        window.addEventListener('keydown', onKeyDown);
        return () => {
            window.removeEventListener('keydown', onKeyDown);
        }
    }, [savePageId, savePageType, images, selectedIds, selectedId]);    useEffect(() => {
        // Comment translated to English.
        async function gettxtdata() {
            let conres = await httpsend.getDataLocal('imgData', { action: 'page', name: txttitle })
            const hasPageData = conres
                && conres.code === 100
                && conres.data
                && conres.data[0]
                && typeof conres.data[0].moduleJson === 'string'
                && conres.data[0].moduleJson.indexOf('{') > -1;
            if (!hasPageData) return '';

            try {
                return JSON.parse(JSON.parse(conres.data[0].moduleJson))
            } catch (e) {
                return '';
            }
        }

        let pageTime;// Comment translated to English.
        let pageTimecalc = 0;// Comment translated to English.
        let pageHistoryTime;// Comment translated to English.
        let pageparamHistoryTime;// Comment translated to English.

        // Comment translated to English.
        if (isPreview) {
            // handleResize();
            // Comment translated to English.
            // Comment translated to English.
            const getDateTime = (timeStr) => {
                var time = timeStr ? timeStr : new Date();
                var y = time.getFullYear();
                var m = time.getMonth() + 1;
                m = m < 10 ? ("0" + m) : m;
                var d = time.getDate();
                d = d < 10 ? ("0" + d) : d;
                return y + "-" + m + "-" + d;
            }

            let procotol;// Comment translated to English.
            let allDevcom;// Comment translated to English.
            let paramData;// Comment translated to English.
            let historyData;// Comment translated to English.
            let alarmData = {
                data: []
            };// Comment translated to English.
            // Comment translated to English.
            let historyparamData;// Comment translated to English.
            let allsnmplist;// Comment translated to English.

            // var reportStart = getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00';
            // var reportEnd = getDateTime(new Date(new Date())) + ' 23:59:59';
            let allDev = [];// Comment translated to English.
            let DevID = [];// Comment translated to English.
            let DevIDParam = [];// Comment translated to English.
            let DevSpareID = {};// Comment translated to English.
            let DevParID = [];// Comment translated to English.
            let DevSnmp = [];// Comment translated to English.
            let DevPar = [];// Comment translated to English.

            let pageparamHistoryDate = '';// Comment translated to English.
            if (localStorage.getItem('pageparamHistoryDate')) {
                pageparamHistoryDate = localStorage.getItem('pageparamHistoryDate')
            } else {
                pageparamHistoryDate = getDateTime(new Date(new Date()));
                localStorage.setItem('pageparamHistoryDate', pageparamHistoryDate);
            }
            // let pageparamHistoryDate ='2024-12-10';
            // Comment translated to English.
            const getHisDevId = async (imagesdata) => {
                imagesdata.forEach((shapeProps) => {
                    // Comment translated to English.
                    if (shapeProps.attrs.moduleJson && shapeProps.attrs.moduleJson.children[0].className === 'Echart' && shapeProps.attrs.moduleJson.children[0].attrs.cat === 'line') {
                        const dataKey = shapeProps.attrs.moduleJson.attrs.dataKey;// Comment translated to English.
                        if (dataKey && dataKey.length > 0) {
                            // Comment translated to English.
                            dataKey.forEach((el) => {
                                // console.log(el)
                                if (el.devkey) {// Comment translated to English.
                                    if (el.src && el.src.indexOf('@') > -1) {
                                        // Comment translated to English.
                                        let serverIP = el.src.split('@')[0];
                                        let devkey = el.src.split('@')[1];
                                        if (!DevSpareID[serverIP]) {
                                            DevSpareID[serverIP] = {
                                                devkey: [],
                                                cmdtype: [],
                                                devTodev: [],
                                                serverIP: serverIP
                                            };
                                        }
                                        DevSpareID[serverIP].devkey.push(devkey);
                                        DevSpareID[serverIP].cmdtype.push(el.cmdtype);
                                        DevSpareID[serverIP].devTodev.push(devkey + '-' + el.devkey);// Comment translated to English.
                                    } else {
                                        DevID.push(el.devkey)
                                        DevIDParam.push(el.cmdtype)
                                    }
                                }
                            })
                        }
                    }

                    // Comment translated to English.
                    if (shapeProps.attrs.moduleJson &&
                        shapeProps.attrs.moduleJson.attrs.dataKey &&
                        shapeProps.attrs.moduleJson.attrs.dataKey.length !== 0 &&
                        (shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('parkey') || shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('paramskey'))) {
                        const dataKey = shapeProps.attrs.moduleJson.attrs.dataKey;// Comment translated to English.
                        if (dataKey && dataKey.length > 0) {
                            dataKey.forEach((el) => {
                                if (el.parkey) DevPar.push(el.parkey)
                            })
                        }
                    }
                    if (shapeProps.attrs.moduleJson &&
                        shapeProps.attrs.moduleJson.attrs.dataKey &&
                        shapeProps.attrs.moduleJson.attrs.dataKey.length !== 0 &&
                        shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('paramskey')) {
                        const dataKey = shapeProps.attrs.moduleJson.attrs.dataKey;// Comment translated to English.
                        if (dataKey && dataKey.length > 0) {
                            dataKey.forEach((el) => {
                                if (el.paramskey) DevParID.push(el.paramskey)
                            })
                        }
                    }

                    // Comment translated to English.

                    // Comment translated to English.
                    // Comment translated to English.
                    // Comment translated to English.
                    // Comment translated to English.
                    // Comment translated to English.
                    if (shapeProps.attrs.moduleJson &&
                        shapeProps.attrs.moduleJson.attrs.dataKey &&
                        shapeProps.attrs.moduleJson.attrs.dataKey.length !== 0) {
                        // && shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('type') ) {
                        //&& shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('name')
                        const dataKey = shapeProps.attrs.moduleJson.attrs.dataKey;// Comment translated to English.
                        if (dataKey && dataKey.length > 0) {
                            dataKey.forEach((el) => {
                                if (el.type === '3' && shapeProps.attrs.moduleJson.attrs.dataKey[0].hasOwnProperty('name')) {
                                    DevSnmp.push(el.key ? el.key : el.devkey);
                                }
                                allDev.push(el.key ? el.key : el.devkey);
                            })
                        }
                    }

                })

                DevSnmp = [...new Set(DevSnmp)];// Comment translated to English.
                // console.log('DevSnmp');
                // console.log(DevSnmp);
                if (DevSnmp.length !== 0) {
                    getSnmpParamData(DevSnmp.join(','));
                } else {
                    allsnmplist = {
                        data: []
                    }
                }

                DevParID = [...new Set(DevParID)];// Comment translated to English.
                // console.log('DevParID');
                // console.log(DevParID);
                if (DevParID.length !== 0) {
                    getHistoryParamData(DevParID.join(','));
                } else {
                    historyparamData = {
                        data: []
                    }
                }

                DevID = [...new Set(DevID)];// Comment translated to English.
                // Comment translated to English.
                // console.log('DevID');
                // console.log(DevID);
                // console.log('DevSpareID')
                // console.log(DevSpareID)
                if (DevID.length !== 0 || Object.keys(DevSpareID).length > 0) {
                    DevIDParam = [...new Set(DevIDParam)];// Comment translated to English.
                    getHistoryData(DevID.join(','), DevIDParam.join(','), DevSpareID);
                } else {
                    historyData = {
                        data: []
                    }
                }

                DevPar = [...new Set(DevPar)];// Comment translated to English.
                // console.log('DevPar');
                // console.log(DevPar);
                if (DevPar.length !== 0) getParamData();

                allDev = [...new Set(allDev)];// Comment translated to English.
                // console.log('allDev');
                // console.log(allDev);
                if (allDev.length !== 0) {
                    getAllcom(allDev.join(','));
                } else {
                    allDevcom = {
                        data: []
                    }
                }
            }
            // Comment translated to English.
            const getpro = async () => {
                let res = await httpsend.getData('GetDeviceProtocolListKey', {
                    ComboBox: 'all'
                })
                if (res) procotol = res
            }
            // Comment translated to English.
            const getAllcom = async (id) => {
                let res = await httpsend.getData('GetDevCommandListKey', {
                    ComboBox: 'all',
                    DevIDs: id
                })
                if (res) allDevcom = res
            }
            // Comment translated to English.
            const getParamData = async () => {
                let res = await httpsend.getData('GetParamListKey', {
                    ComboBox: 'calc'
                })
                if (res) paramData = res
            }
            // Comment translated to English.
            const getSnmpParamData = async (id) => {
                let res = await httpsend.getData('GetSnmpParamListKey', {
                    DevIDs: id,
                    DataType: t('auto.k0335'),
                    ComboBox: 'all'
                })
                if (res) allsnmplist = res
            }
            // Comment translated to English.
            const getHistoryData = async (DevID, DevIDParam, DevSpareID = {}) => {
                let waitHistoryData = {};
                if (DevID) {
                    let res = await httpsend.getData('GetHistoryAlarmsKey', {
                        startDateTime: getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00',
                        endDateTime: getDateTime(new Date(new Date())) + ' 23:59:59',
                        DevID: DevID,
                        keyword: DevIDParam,
                        ComboBox: 'all'
                    })
                    if (res) waitHistoryData = res;
                }
                // Comment translated to English.
                // console.log(DevSpareID)
                // console.log(DevSpareID.length)
                if (DevSpareID) {
                    // Comment translated to English.
                    for (var kes in DevSpareID) {
                        // DevSpareID[serverIP] = {
                        //     devkey: [],
                        //     cmdtype: [],
                        //     devTodev: [],
                        //     serverIP: serverIP
                        // };
                        // Comment translated to English.

                        let spareUrl = buildMainApiUrl('GetHistoryAlarmsKey', kes);
                        let spareDevId = [...new Set(DevSpareID[kes].devkey)];
                        let spareDevCmd = [...new Set(DevSpareID[kes].cmdtype)];
                        let spareMap = [...new Set(DevSpareID[kes].devTodev)];// Comment translated to English.
                        let spareObj = {};
                        spareMap.forEach(item => {
                            const [key, value] = item.split('-');
                            spareObj[key] = parseInt(value);
                        });
                        console.log(t('auto.k0336'))
                        console.log(spareUrl)
                        // console.log(spareDevId)
                        // console.log(spareDevCmd)
                        console.log(spareObj)

                        let spareRes = await httpsend.handlePostSubmit(spareUrl, {
                            startDateTime: getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00',
                            endDateTime: getDateTime(new Date(new Date())) + ' 23:59:59',
                            DevID: spareDevId,
                            keyword: spareDevCmd,
                            ComboBox: 'all'
                        })
                        if (spareRes) {
                            spareRes.data.forEach((ele) => {
                                ele.DevID = spareObj[ele.DevID].toString() || ele.DevID.toString()
                            })
                            if (!waitHistoryData.data) {
                                waitHistoryData.data = [];
                            }
                            waitHistoryData.data = waitHistoryData.data.concat(spareRes.data);
                            // console.log(waitHistoryData)
                        }
                    }
                }
                if (waitHistoryData) historyData = waitHistoryData;
            }
            // Comment translated to English.
            const getHistoryParamData = async (parID) => {
                let res = await httpsend.getData('GetParamDayListKey', {
                    startDateTime: getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00',
                    endDateTime: getDateTime(new Date(new Date())) + ' 23:59:59',
                    ParamIds: parID,
                    ComboBox: 'all'
                })
                if (res) historyparamData = res
            }

            // Comment translated to English.
            const getAlarmData = async () => {
                let res = await httpsend.getData('GetAlarmListKey', {
                    type: '1',
                    ComboBox: 'all'
                })
                if (res) alarmData = res;
            }

            // Comment translated to English.
            const getSystemStartTime = async () => {
                let res = await httpsend.getData('GetLogoKey', {
                })
                if (res && res.data && res.data[0].create_time) localStorage.setItem('SystemStartTime', res.data[0].create_time)
            }

            // Comment translated to English.
            // const getEventData = async () => {
            //     let res = await httpsend.getData('GetEventListKey', {
            //         type: '1',
            //         ComboBox: 'all'
            //     })
            //     if (res) eventData = res;
            // }

            getpro();
            getSystemStartTime();

            // Comment translated to English.
            const setNewView = (previewjson) => {
                // Comment translated to English.
                let startviewTime = new Date().getTime();
                let tplimages = [];
                if (previewjson) {
                    previewjson.children[0].children.forEach((element) => {
                        if (element.attrs.id !== 'canvasBackground' && element.attrs.moduleJson) {
                            if ((element.attrs.moduleJson.attrs.dataKey && element.attrs.moduleJson.attrs.dataKey.length !== 0) ||
                                (element.attrs.moduleJson.children && element.attrs.moduleJson.children[0].attrs.cat === 'alarmpie') ||
                                (element.attrs.moduleJson.children && element.attrs.moduleJson.children[0].className === 'alarmList') ||
                                element.attrs.moduleJson.children[0].attrs.name === 'ipImage'
                            ) {
                                tplimages.push(element.attrs);
                            }
                        }
                    });
                }
                let newtplimages = PreviewDeal.PreviewDeal(tplimages, procotol, allDevcom, historyData, paramData, allsnmplist, historyparamData, alarmData);
                setTimeout(() => {
                    // Comment translated to English.
                    let endviewTime = new Date().getTime();
                    console.log(t('auto.k0337') + (parseInt(endviewTime) - parseInt(startviewTime)))
                    // console.log(JSON.parse(JSON.stringify(newtplimages)));
                    console.log(txttitle + t('auto.k0338'));
                    setImagesdata(JSON.parse(JSON.stringify(newtplimages)));
                    imagesRef.current = JSON.parse(JSON.stringify(newtplimages));
                    setChart(JSON.parse(JSON.stringify(newtplimages)), null, alarmData);
                }, 500)
            }

            // let newtplimages;
            // Comment translated to English.
            const getPageInfo = async () => {
                if (txttitle) {
                    previewjson = await gettxtdata();
                } else {
                    previewjson = JSON.parse(JSON.parse(localStorage.getItem('stageJson')));
                }
                if (previewjson) {
                    await getHisDevId(previewjson.children[0].children);
                    handlepredata(previewjson);// Comment translated to English.
                    setNewView(previewjson)// Comment translated to English.
                } else {
                    message.error(txttitle + t('auto.k0339'));
                }
            };
            getPageInfo();

            var devtime = setInterval(async () => {
                // console.log(allDevcom)
                // console.log(devtime)
                if (allDevcom && allDevcom.data && devtime) {
                    clearInterval(devtime);
                    console.log(t('auto.k0340') + new Date())
                    console.log(t('auto.k0341') + new Date())
                    // Comment translated to English.
                    // Comment translated to English.
                    // Comment translated to English.
                    setNewView(previewjson);
                    // setTimeout(() => {
                    console.log(t('auto.k0342'));
                    console.log('DevID');
                    console.log(DevID);
                    console.log('DevSpareID')
                    console.log(DevSpareID)
                    if (DevID.length !== 0 || Object.keys(DevSpareID).length > 0) {
                        console.log(t('auto.k0343') + new Date())
                        var historytime = setInterval(() => {
                            if (historyData && historyData.msg) {// Comment translated to English.
                                clearInterval(historytime);
                                // havehis = true;
                                setPageView();
                                console.log(t('auto.k0344') + new Date())
                                // Comment translated to English.
                                pageHistoryTime = setInterval(() => {
                                    console.log(txttitle + t('auto.k0345'));
                                    getHistoryData(DevID.join(','), DevIDParam.join(','), DevSpareID);
                                }, 600000)
                            }
                        }, 10)
                    } else {
                        // havehis = true;
                        setPageView()
                        console.log(t('auto.k0346'))
                    }
                    if (DevParID.length !== 0) {
                        console.log(t('auto.k0347') + new Date())
                        var historyparamtime = setInterval(() => {
                            if (historyparamData && historyparamData.msg) {// Comment translated to English.
                                clearInterval(historyparamtime);
                                // havehispar = true;
                                setPageView();
                                console.log(t('auto.k0348') + new Date())
                                // Comment translated to English.
                                // Comment translated to English.
                                pageparamHistoryTime = setInterval(() => {
                                    let todayDate = getDateTime(new Date(new Date()));
                                    console.log(txttitle + t('auto.k0349') + pageparamHistoryDate);
                                    console.log(txttitle + t('auto.k0350') + todayDate);
                                    if (pageparamHistoryDate !== todayDate) {
                                        getHistoryParamData(DevParID.join(','));
                                        console.log(txttitle + t('auto.k0351'));
                                        pageparamHistoryDate = todayDate;// Comment translated to English.
                                        localStorage.setItem('pageparamHistoryDate', todayDate);
                                    }
                                }, 3600000)
                                // },10000)
                            }
                        }, 10)

                    } else {
                        // havehispar = true;
                        setPageView();
                        console.log(t('auto.k0352'))
                    }
                    if (DevSnmp.length !== 0) {
                        console.log(t('auto.k0353') + new Date())
                        var snmptime = setInterval(() => {
                            if (allsnmplist && allsnmplist.msg) {// Comment translated to English.
                                clearInterval(snmptime);
                                // havesnmp=true;
                                setPageView();
                                console.log(t('auto.k0354') + new Date())
                            }
                        }, 10)
                    } else {
                        // havesnmp=true;
                        setPageView();
                        console.log(t('auto.k0355'))
                    }
                    if (DevPar.length !== 0) {
                        console.log(t('auto.k0356') + new Date())
                        var cuspartime = setInterval(() => {
                            if (paramData && paramData.msg) {// Comment translated to English.
                                clearInterval(cuspartime);
                                setPageView();
                                console.log(t('auto.k0357') + new Date())
                            }
                        }, 10)
                    } else {
                        setPageView();
                        console.log(t('auto.k0358'))
                    }
                    // }, 100);
                    function setPageView() {
                        // console.log()
                        // if (!newtplimages) return false;
                        // setImagesdata(JSON.parse(JSON.stringify(newtplimages)));
                        // imagesRef.current = JSON.parse(JSON.stringify(newtplimages));
                        // setChart(JSON.parse(JSON.stringify(newtplimages)), null);
                        setNewView(previewjson);
                    }
                }
                console.log(t('auto.k0359') + new Date())
            }, 10)
            pageTime = setInterval(() => {
                if (allDev.length !== 0) getAllcom(allDev.join(','));
                if (DevPar.length !== 0) getParamData();
                if (alarmCatchRef.current === '1') getAlarmData();
                setNewView(previewjson);
                pageTimecalc++;
                console.log(pageTimecalc);
            }, 10000)
            // window.addEventListener("resize", handleResize(stageWidthRef.current, stageHeightRef.current), false);
        }
        // handleResize();
        // window.addEventListener("resize", handleResize, false);
        // return () => window.addEventListener("resize", handleResize, false);
        return () => {
            clearInterval(pageTime);
            if (pageHistoryTime) clearInterval(pageHistoryTime);
            if (pageparamHistoryTime) clearInterval(pageparamHistoryTime);
        }
    }, []);// Comment translated to English.

    // Comment translated to English.
    useEffect(() => {
        if (!layerRef.current || !transformRefids.current) {
            return;
        }
        const nodes = selectedIds
            .map((id) => layerRef.current.findOne("#" + id))
            .filter(Boolean);
        transformRefids.current.nodes(nodes);
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
        // do we pressed shift or ctrl?
        const metaPressed = e.evt.shiftKey || e.evt.ctrlKey || e.evt.metaKey;
        const isSelected = tr.nodes().indexOf(e.target) >= 0;
        const isDrag = e.target.parent.attrs.draggable;
        const clickedShapeId = e.target.parent.attrs.id;
        const clickedSelectionIds = getExpandedSelectionIds(clickedShapeId);
        // Comment translated to English.
        if (!isDrag) { message.error(t('auto.k0361')); return; }
        if (!metaPressed && !isSelected) {
            selectShapes([]);
            selectedIdsRef.current = [];
            if (clickedSelectionIds.length > 1) {
                selectShapes(clickedSelectionIds);
                selectedIdsRef.current = clickedSelectionIds;
                setSelectedId(clickedShapeId);
                selectedIdRef.current = clickedShapeId;
                const clickedShape = imagesRef.current.find((item) => item.id === clickedShapeId);
                setDragShape(clickedShape || null);
            }
            return;
        } else if (metaPressed && isSelected) {
            selectShapes((oldShapes) => {
                let ids = oldShapes.filter((oldId) => !clickedSelectionIds.includes(oldId))
                selectedIdsRef.current = ids;
                return ids;
            });

        } else if (metaPressed && !isSelected) {
            selectShapes((oldShapes) => {
                const nextIds = [...new Set([...oldShapes, ...clickedSelectionIds])];
                selectedIdsRef.current = nextIds;
                return nextIds;
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
    };
    const onMouseUp = () => {
        oldPos.current = null;
        selection.current.visible = false;
        const { x1, x2, y1, y2 } = selection.current;
        const moved = x1 !== x2 || y1 !== y2;
        if (!moved) {
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
        let rawIds = elements.map((el) => el.attrs.id);
        const expandedSet = new Set();
        rawIds.forEach((id) => {
            getExpandedSelectionIds(id).forEach((mid) => expandedSet.add(mid));
        });
        const ids = Array.from(expandedSet);
        selectShapes(ids);
        selectedIdsRef.current = ids;
        updateSelectionRect('remove');
    };
    // Comment translated to English.

    // Comment translated to English.
    // Comment translated to English.
    const handleItemDragUrl = async (dragUrl, dragAttrs, type, options = {}) => {
        setDragUrl(dragUrl);
        const { skipAutoSave = false } = options;
        if (type && !skipAutoSave) {
            pendingPageSwitchRef.current = {
                dragUrl,
                dragAttrs,
                type,
            };
            const canSwitch = await tryAutoSaveBeforeSwitch();
            if (!canSwitch) {
                return false;
            }
            pendingPageSwitchRef.current = null;
        }
        if (type) {
            let conres = await httpsend.getDataLocal('imgData', { action: 'page', name: type.split('&')[0] });
            if (conres) {
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
                    dealStringPage(conres.data[0].moduleJson);
                }
            }
        } else {
            setDragAttrs(dragAttrs);
        }
        return true;
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
            setstageWidth(dargJson.attrs.width);
            setstageHeight(dargJson.attrs.height);
            setStageDimensions({
                width: stageWidth,
                height: stageHeight,
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
        const serializedStage = JSON.stringify(dargJson || '');
        lastSavedStageJsonRef.current = typeof serializedStage === 'string' ? serializedStage : '';
        setSaveStatusText('已保存');
        scheduleIdleAutoSave();
        setImages(tplimages);
        imagesRef.current = tplimages;
        setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);
        history = [];
        history.push(JSON.parse(JSON.stringify(imagesRef.current)));
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
        clearSnapGuides();
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
                        id: eleId,
                        groupId: createDerivedGroupId(newShapeProps.groupId)
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
            case 'del':
                let imagesToUpdate = images;
                const delImageToUpdate = imagesToUpdate.filter((img) => img.id !== newShapeProps.id);
                setImages(JSON.parse(JSON.stringify(delImageToUpdate)));
                imagesRef.current = JSON.parse(JSON.stringify(delImageToUpdate));
        
                history.push(delImageToUpdate);
                setChart(imagesRef.current, selectedIdRef.current, null);

                setSelectedId(null);
                selectedIdRef.current = null;
                setDragShape(null);
                console.log(t('auto.k0364'));
                break;
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
    const getDistributedUnitTargets = (units, axis) => {
        if (!Array.isArray(units) || units.length === 0) return {};
        if (units.length === 1) {
            return { [units[0].key]: axis === 'x' ? units[0].metrics.x : units[0].metrics.y };
        }

        const sortedUnits = [...units].sort((a, b) => (
            axis === 'x'
                ? a.metrics.x - b.metrics.x
                : a.metrics.y - b.metrics.y
        ));

        const firstUnit = sortedUnits[0];
        const lastUnit = sortedUnits[sortedUnits.length - 1];
        const start = axis === 'x' ? firstUnit.metrics.x : firstUnit.metrics.y;
        const end = axis === 'x'
            ? lastUnit.metrics.x + lastUnit.metrics.width
            : lastUnit.metrics.y + lastUnit.metrics.height;
        const totalSize = sortedUnits.reduce((sum, unit) => sum + (axis === 'x' ? unit.metrics.width : unit.metrics.height), 0);
        const gap = sortedUnits.length > 1 ? (end - start - totalSize) / (sortedUnits.length - 1) : 0;

        const targets = {};
        let cursor = start;
        sortedUnits.forEach((unit) => {
            targets[unit.key] = cursor;
            cursor += (axis === 'x' ? unit.metrics.width : unit.metrics.height) + gap;
        });
        return targets;
    };

    const handleMultiToolBack = (type) => {
        if (type === 'del') {
            const deleteIds = new Set(selectedIdsRef.current);
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
            return;
        }

        if (type === 'lock' || type === 'unlock') {
            const targetDraggable = type === 'unlock';
            const targetIds = new Set(selectedIdsRef.current);
            const nextImages = imagesRef.current.map((shape) => (
                targetIds.has(shape.id)
                    ? { ...shape, draggable: targetDraggable }
                    : shape
            ));
            setImages(JSON.parse(JSON.stringify(nextImages)));
            imagesRef.current = JSON.parse(JSON.stringify(nextImages));
    
            history.push(JSON.parse(JSON.stringify(imagesRef.current)));
            setChart(imagesRef.current, selectedIdRef.current, null);
            selectShapes([...targetIds]);
            selectedIdsRef.current = [...targetIds];
            settoolType(null);
            return;
        }

        let tr = transformRefids.current;
        const alignmentUnits = getAlignmentUnits(selectedIdsRef.current);
        if (!tr || alignmentUnits.length === 0) {
            settoolType(null);
            return;
        }

        const firstUnit = alignmentUnits[0];
        const anchorCenterX = firstUnit ? firstUnit.metrics.centerX : 0;
        const anchorCenterY = firstUnit ? firstUnit.metrics.centerY : 0;

        const xl = tr.x();
        const xr = tr.x() + tr.width();
        const yt = tr.y();
        const yb = tr.y() + tr.height();

        let maxh = 0, maxw = 0;
        let newxUnits = [], newyUnits = [];

        if (type.indexOf('equal') >= 0) {
            alignmentUnits.forEach((unit) => {
                const width = unit.metrics.width;
                const height = unit.metrics.height;
                if (maxh < height) maxh = height;
                if (maxw < width) maxw = width;
                newxUnits.push({ key: unit.key, x: unit.metrics.x });
                newyUnits.push({ key: unit.key, y: unit.metrics.y });
            });
        }

        let orderedUnitKeys = [];
        if (type === "equallevel") {
            newxUnits = newxUnits.sort((a, b) => a.x - b.x);
            newxUnits.forEach((item) => orderedUnitKeys.push(item.key));
        } else if (type === "equalvertical") {
            newyUnits = newyUnits.sort((a, b) => a.y - b.y);
            newyUnits.forEach((item) => orderedUnitKeys.push(item.key));
        } else {
            orderedUnitKeys = alignmentUnits.map((unit) => unit.key);
        }

        const sourceGroupIds = {};
        selectedIdsRef.current.forEach((selectedId) => {
            const sourceShape = imagesRef.current.find((img) => img.id === selectedId);
            if (sourceShape && sourceShape.groupId && !sourceGroupIds[sourceShape.groupId]) {
                sourceGroupIds[sourceShape.groupId] = createDerivedGroupId(sourceShape.groupId);
            }
        });

        let copyids = [];
        let nextImages = JSON.parse(JSON.stringify(imagesRef.current));
        const unitMap = {};
        alignmentUnits.forEach((unit) => {
            unitMap[unit.key] = unit;
        });
        const equalLevelTargets = type === 'equallevel' ? getDistributedUnitTargets(alignmentUnits, 'x') : {};
        const equalVerticalTargets = type === 'equalvertical' ? getDistributedUnitTargets(alignmentUnits, 'y') : {};

        orderedUnitKeys.forEach((unitKey) => {
            const unit = unitMap[unitKey];
            if (!unit) return;

            const width = unit.metrics.width;
            const height = unit.metrics.height;
            let targetX = unit.metrics.x;
            let targetY = unit.metrics.y;

            switch (type) {
                case "alginleft":
                    targetX = xl;
                    break;
                case "alginright":
                    targetX = xr - width;
                    break;
                case "algintop":
                    targetY = yt;
                    break;
                case "alginbottom":
                    targetY = yb - height;
                    break;
                case "alginvertical":
                    targetX = anchorCenterX - (width / 2);
                    break;
                case "algincenter":
                    targetY = anchorCenterY - (height / 2);
                    break;
                case "equalvertical":
                    targetY = equalVerticalTargets[unit.key] !== undefined ? equalVerticalTargets[unit.key] : unit.metrics.y;
                    break;
                case "equallevel":
                    targetX = equalLevelTargets[unit.key] !== undefined ? equalLevelTargets[unit.key] : unit.metrics.x;
                    break;
                default:
                    break;
            }

            const offsetX = targetX - unit.metrics.x;
            const offsetY = targetY - unit.metrics.y;

            unit.memberIds.forEach((memberId) => {
                const findIndex = nextImages.findIndex((img) => img.id === memberId);
                if (findIndex === -1) return;
                let singleImageToUpdate = JSON.parse(JSON.stringify(nextImages[findIndex]));

                switch (type) {
                    case "copys": {
                        const eleId = parseInt(new Date().getTime()).toString() + copyids.length;
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: singleImageToUpdate.x + 5,
                            y: singleImageToUpdate.y + 5,
                            id: eleId,
                            groupId: singleImageToUpdate.groupId ? sourceGroupIds[singleImageToUpdate.groupId] : singleImageToUpdate.groupId
                        };
                        copyids.push(eleId);
                        nextImages.push(singleImageToUpdate);
                        break;
                    }
                    case "equalhight":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            scaleY: singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY * (maxh / height) : (maxh / height)
                        };
                        nextImages[findIndex] = singleImageToUpdate;
                        break;
                    case "equalwidth":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            scaleX: singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX * (maxw / width) : (maxw / width)
                        };
                        nextImages[findIndex] = singleImageToUpdate;
                        break;
                    case "equal":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            scaleY: singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY * (maxh / height) : (maxh / height),
                            scaleX: singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX * (maxw / width) : (maxw / width)
                        };
                        nextImages[findIndex] = singleImageToUpdate;
                        break;
                    default:
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: singleImageToUpdate.x + offsetX,
                            y: singleImageToUpdate.y + offsetY,
                        };
                        nextImages[findIndex] = singleImageToUpdate;
                        break;
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
    };

    // Comment translated to English.
    const handleToolChange = async (type) => {
        if (type === 'copy') {
            copySelectionToClipboard();
            return;
        }
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
            if (selectedIdRef.current !== null || selectedIdsRef.current.length !== 0) {
                if (selectedIdsRef.current.length !== 0) {
                    handleMultiToolBack(type);
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
        stagejson = stageRef.current.toJSON();
        if (backgroundImage) {// Comment translated to English.
            let newjson = JSON.parse(stagejson);
            newjson.children[0].children.forEach(element => {// Comment translated to English.
                if (element.attrs.id === 'canvasBackground') {
                    if (backgroundImage.indexOf('#') === -1) {
                        if (backgroundImage.indexOf('/public/') > 0) {
                            element.attrs.fillPatternImage = backgroundImage.split('/public/')[1];
                        } else {
                            element.attrs.fillPatternImage = backgroundImage;
                        }
                    }
                    element.attrs.alarmCatch = alarmCatch;
                }
            })
            stagejson = JSON.stringify(newjson);
        }
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
    }
    // Comment translated to English.
    const loginOut = () => {
        localStorage.clear();
        window.location.href = httpsend.mainURL() + 'login.html';
    }
    // Comment translated to English.
    const handleCanvasChange = (val) => {
        setStageDimensions({
            scalex: val / 100,
            scaley: val / 100,
        });
        setcanvasScale(val);
    }
    // Comment translated to English.
    return (
        <>
            {!isPreview &&
                <>
                    <div className="top">
                        <div className="topLeft">
                            <label>{t('auto.k0387')}</label>
                        </div>
                        <div className="topCenter">
                            <div className="topGroup topToolList">
                                <ToolList
                                    MultiSelect={selectedIds.length !== 0}
                                    handleTool={(type) => {
                                        handleToolChange(type);
                                    }} />
                            </div>
                            <div className="topGroup topControls">
                                <Button type={snapEnabled ? 'primary' : 'default'} onClick={() => {
                                    setSnapEnabled((prev) => {
                                        if (prev) {
                                            clearSnapGuides();
                                        }
                                        return !prev;
                                    });
                                }}>{snapEnabled ? '磁吸开' : '磁吸关'}</Button>
                                <select className="topControl" value={String(snapThreshold)} onChange={(e) => setSnapThreshold(Number(e.target.value))}>
                                    <option value="4">4px</option>
                                    <option value="6">6px</option>
                                    <option value="8">8px</option>
                                    <option value="10">10px</option>
                                </select>
                                <Button type="default" disabled={!canGroupSelection} onClick={groupSelectedShapes}>组合</Button>
                                <Button type="default" disabled={!canUngroupSelection} onClick={ungroupSelectedShapes}>取消组合</Button>
                            </div>
                        </div>
                        <div className="topRight">
                            <span className={`saveStatus ${saveStatusText === '已修改' ? 'dirty' : ''}`}>{saveStatusText === '已自动保存' && lastAutoSaveTime ? `${saveStatusText} · ${lastAutoSaveTime}` : saveStatusText}</span>
                            <Button type="primary" className="topActionBtn" onClick={() => setIsOutOpen(true)}>{t('auto.k0388')}</Button>
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => savePage('preview')}>{t('auto.k0389')}</Button>}
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => setshowsaveTplBox(1)}>{t('auto.k0390')}</Button>}
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => savePage('page')}>{t('auto.k0391')}</Button>}
                            <Button type="primary" className="topActionBtn" onClick={() => savePage('editpage')}>{t('auto.k0392')}</Button>
                            {(savePageId !== '0' && savePageType === '1') && <Button type="primary" className="topActionBtn" onClick={() => setresetBox(true)}>{t('auto.k0393')}</Button>}
                        </div>
                    </div>
                    <ItemBox
                        onChangeDragUrl={handleItemDragUrl}
                        isChanged={savePageId} />
                    <div className="eleAttrs">
                        <ul>
                            <li className={`${showIndex === 1 ? 'check' : ''} ${tabFlash === 'component' ? 'tabFlash' : ''}`.trim()} onClick={() => setshowIndex(1)}>{t('auto.k0394')}</li>
                            <li className={showIndex === 2 ? 'check' : ''} onClick={() => setshowIndex(2)}>{t('auto.k0395')}</li>
                            <li className={showIndex === 3 ? 'check' : ''} onClick={() => setshowIndex(3)}>界面属性</li>
                        </ul>
                        {showIndex === 1 &&
                            <ElementAttr
                                MultiSelect={selectedIds.length !== 0}
                                currentPageId={savePageId}
                                dragShape={dragShape}
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
                                    <div className="attrTitle">未组合元素</div>
                                    {structure.singles.length === 0 && <div className="attrBox">暂无未组合元素</div>}
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
                                    <div className="attrTitle">组合元素</div>
                                    {structure.groups.length === 0 && <div className="attrBox">暂无组合</div>}
                                    {structure.groups.map((group) => (
                                        <div key={group.groupId} className="interfaceGroup">
                                            <div
                                                className="attrBox interfaceGroupTitle"
                                                onMouseEnter={() => setHoverHighlightIds(group.members.map((member) => member.id))}
                                                onMouseLeave={() => setHoverHighlightIds([])}
                                                onClick={() => handleStructureItemClick(group.members.length > 0 ? group.members[0].id : '', true)}
                                            >
                                                <label>{group.label}</label>
                                                <span>{group.members.length} 个元素</span>
                                            </div>
                                            {group.members.map((item) => (
                                                <div
                                                    key={item.id}
                                                    className="attrBox interfaceItem interfaceItemChild"
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
                        className="canvasBody"
                        style={{ 'backgroundColor': '#eee' }}
                        ref={containerRef}
                        onDrop={handleOnDrop}
                        onDragOver={(e) => e.preventDefault()}
                    >
                        <div className="canvasRange">
                            <img src="Images/icon/narrow.png" style={{ 'width': '15px', 'verticalAlign': 'super', 'marginRight': '5px' }} alt={t('auto.k0329')} />
                            <input type="range" min="10" max="100" onChange={(e) => handleCanvasChange(e.target.value)} defaultValue={canvasScale} />
                            <img src="Images/icon/enlarge.png" style={{ 'width': '15px', 'verticalAlign': 'super', 'marginLeft': '5px' }} alt={t('auto.k0330')} />
                        </div>
                        {savePageId === '0' && <Stage
                            className="canvasStage canvasStage2"
                            width={stageWidth}
                            height={stageHeight}
                            scaleX={stageDimensions.scalex}
                            scaleY={stageDimensions.scaley}
                            ref={stageRef}
                        >
                            <Layer ref={layerRef} style={{ 'backgroundColor': '#fff' }}>
                                <Group>
                                    <Text text={t('auto.k0331')} fontSize={25} lineHeight={5} padding={50} />
                                </Group>
                            </Layer>
                        </Stage>}
                        {savePageId !== '0' && (savePageType === '1' ? <Stage
                            className="canvasStage canvasStage2"
                            width={stageWidth}
                            height={stageHeight}
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
                                        width={stageWidth}
                                        height={stageHeight} />
                                )}
                                {images.map((shape) => {
                                    return (<ConElement
                                        id={shape.id}
                                        key={shape.id}
                                        shapeProps={shape}
                                        isSelected={shape.id === selectedId && selectedIds.length === 0}
                                        showSelectionFrame={selectedIds.includes(shape.id) || (shape.id === selectedId && selectedIds.length === 0)}
                                        isHoverHighlighted={hoverHighlightIds.includes(shape.id)}
                                        toolType={shape.id === selectedId ? toolType : null}
                                        onToolBack={(newShapeProps, type) => {
                                            handleToolBack(newShapeProps, type);
                                        }}
                                        onSelect={(evt) => {
                                            if (evt && evt.evt && (evt.evt.shiftKey || evt.evt.ctrlKey || evt.evt.metaKey)) {
                                                return;
                                            }
                                            if (evt && evt.evt && evt.evt.__draggingSelection && selectedIdsRef.current.length > 1 && selectedIdsRef.current.includes(shape.id)) {
                                                return;
                                            }
                                            const groupSelectionIds = getExpandedSelectionIds(shape);
                                            if (groupSelectionIds.length > 1) {
                                                selectShapes(groupSelectionIds);
                                                selectedIdsRef.current = groupSelectionIds;
                                                setSelectedId(shape.id);
                                                selectedIdRef.current = shape.id;
                                                setDragShape(shape);
                                                return;
                                            }
                                            if (selectedId !== shape.id) {
                                                setSelectedId(null);
                                                setDragShape(null);
                                                setTimeout(() => {
                                                    setSelectedId(shape.id);
                                                    selectedIdRef.current = shape.id;
                                                    setDragShape(shape);
                                                });
                                            }
                                        }}
                                        onDragMove={(e, currentShape) => {
                                            handleShapeDragMove(e, currentShape);
                                        }}
                                        onChange={(newShapeProps) => {
                                            // Comment translated to English.
                                            // console.log(newShapeProps)
                                            if (multiDragRef.current.active && multiDragRef.current.draggedId === shape.id && multiDragRef.current.pendingPositions) {
                                                commitMultiDragPositions(multiDragRef.current.pendingPositions);
                                                multiDragRef.current = {
                                                    active: false,
                                                    draggedId: null,
                                                    startPositions: {},
                                                    pendingPositions: null,
                                                };
                                                clearSnapGuides();
                                                return;
                                            }
                                            handleShapeChange(newShapeProps, shape.id);
                                        }} />
                                    );
                                })}
                                {snapGuides.vertical && (
                                    <Line
                                        points={[
                                            snapGuides.vertical.x,
                                            snapGuides.vertical.y1,
                                            snapGuides.vertical.x,
                                            snapGuides.vertical.y2,
                                        ]}
                                        stroke={snapGuides.vertical.isStageGuide ? "#fa8c16" : "#148cf1"}
                                        strokeWidth={snapGuides.vertical.isStageGuide ? 2 : 1}
                                        dash={snapGuides.vertical.isStageGuide ? [10, 6] : [6, 4]}
                                        shadowColor={snapGuides.vertical.isStageGuide ? "#fa8c16" : "#148cf1"}
                                        shadowBlur={snapGuides.vertical.isStageGuide ? 4 : 2}
                                        listening={false}
                                    />
                                )}
                                {snapGuides.horizontal && (
                                    <Line
                                        points={[
                                            snapGuides.horizontal.x1,
                                            snapGuides.horizontal.y,
                                            snapGuides.horizontal.x2,
                                            snapGuides.horizontal.y,
                                        ]}
                                        stroke={snapGuides.horizontal.isStageGuide ? "#fa8c16" : "#148cf1"}
                                        strokeWidth={snapGuides.horizontal.isStageGuide ? 2 : 1}
                                        dash={snapGuides.horizontal.isStageGuide ? [10, 6] : [6, 4]}
                                        shadowColor={snapGuides.horizontal.isStageGuide ? "#fa8c16" : "#148cf1"}
                                        shadowBlur={snapGuides.horizontal.isStageGuide ? 4 : 2}
                                        listening={false}
                                    />
                                )}
                                <Transformer
                                    ref={transformRefids}
                                    flipEnabled={false}
                                    borderEnabled={false}
                                    anchorSize={0}
                                    rotateEnabled={false}
                                    boundBoxFunc={(oldBox, newBox) => getBoundedTransformerBox(oldBox, newBox)} />
                                <Rect fill="rgba(0,0,255,0.5)" ref={selectionRectRef} />
                            </Layer>
                        </Stage> : <Stage
                            className="canvasStage canvasStage2"
                            width={stageWidth}
                            height={stageHeight}
                            scaleX={stageDimensions.scalex}
                            scaleY={stageDimensions.scaley}
                            ref={stageRef}
                            key='stage002'
                        >
                            <Layer ref={layerRef} style={{ 'backgroundColor': '#fff' }}>
                                <Group>
                                    <Text text={t('auto.k0332')} fontSize={25} lineHeight={5} padding={50} />
                                </Group>
                            </Layer>
                        </Stage>)}
                    </div>
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
                                    message.success(t('auto.k0443'));
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
                                <input style={{ width: '76px' }} type="text" onChange={(e) => setstageWidth(e.target.value)} defaultValue={stageWidth} />
                                <span> * </span>
                                <input style={{ width: '76px' }} type="text" onChange={(e) => setstageHeight(e.target.value)} defaultValue={stageHeight} />
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
                        <span className="layui-layer-setwin" onClick={() => {
                            pendingPageSwitchRef.current = null;
                            setshowsavePageBox(0)
                        }}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                const createResult = await createAndSavePage();
                                if (!createResult.ok) {
                                    return;
                                }
                                console.log(t('auto.k0413'))
                                setStageDimensions({
                                    width: stageWidth,
                                    height: stageHeight,
                                    scalex: 1,
                                    scaley: 1,
                                });
                                setcanvasScale(100);
                                setImages([]);
                                setBackgroundImage(null);
                                setalarmCatch('1')
                                alarmCatchRef.current = '1';
                                imagesRef.current = [];
                                setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);

                                history = [];
                                await continuePendingPageSwitch.current();
                                scheduleIdleAutoSave();
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
                                let savejson = buildStageJson();
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
                                                message.success(t('auto.k0443'));
                                                lastSavedStageJsonRef.current = savejson;
                                                scheduleIdleAutoSave();
                                            } else {
                                                message.error(t('auto.k0444'));
                                            }
                                        } else {
                                            message.success(t('auto.k0443'));
                                            lastSavedStageJsonRef.current = savejson;
                                            scheduleIdleAutoSave();
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
                        <span className="layui-layer-setwin" onClick={() => setresetBox(false)}>
                            <Close />
                        </span>
                        <div className="layui-layer-btn">
                            <Button type="primary" onClick={async () => {
                                // console.log(pagedevList);
                                // Comment translated to English.
                                if (pagedevList.length !== 0) {
                                    let newimags = [];
                                    imagesRef.current.forEach(shapeProps => {
                                        if (shapeProps.moduleJson && shapeProps.moduleJson.attrs.dataKey) {
                                            let dataKey = shapeProps.moduleJson.attrs.dataKey;
                                            if (dataKey && dataKey.length === 1) {
                                                dataKey.forEach((el) => {
                                                    // Comment translated to English.
                                                    let findpagedevindex = pagedevList.findIndex(v => (v.value === el.key || v.value === el.deveventskey))
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
                                setresetBox(false);

                            }}>{t('auto.k0202')}</Button>
                        </div>
                    </div>
                </>
            }
            {isPreview && <div
                className="canvasBody"
                ref={containerRef}
                style={{ 'width': stageDimensions.width + 'px', 'height': stageDimensions.height + 'px' }}
            >
                <Stage
                    className="canvasStage"
                    width={stageDimensions.width}
                    height={stageDimensions.height}
                    scaleX={stageDimensions.scalex}
                    scaleY={stageDimensions.scaley}
                    ref={stageRef}
                    style={{ 'width': stageDimensions.width + 'px', 'height': stageDimensions.height + 'px', 'overflowX': 'hidden' }}
                >
                    <Layer ref={layerRef}>
                        {(backgroundImage && typeof backgroundImage === "string") && (
                            <SvgBackground
                                backgroundUrl={backgroundImage}
                                width={stageWidth}
                                height={stageHeight}
                            />
                        )}
                        {imagesstatic && imagesstatic.map((shape) => {
                            return (<PreviewElement
                                id={shape.id}
                                key={shape.id}
                                shapeProps={shape}
                                wheight={stageDimensions.height}
                                wwidth={stageDimensions.width}
                                wscale={stageDimensions.scalex}
                                onhandleResize={(type) => {
                                    if (type === 'full') {
                                        handleResize(stageWidthRef.current, stageHeightRef.current)
                                    }
                                }}
                                isSwiper={isSwiper}
                            />
                            );
                        })}
                        {imagesdata && imagesdata.map((shape) => {
                            return (<PreviewElement
                                id={shape.id}
                                key={shape.id}
                                shapeProps={shape}
                                wheight={stageDimensions.height}
                                wwidth={stageDimensions.width}
                                wscale={stageDimensions.scalex}
                                onhandleResize={(type) => { }}
                                isSwiper={isSwiper}
                            />
                            );
                        })}
                    </Layer>
                </Stage>
            </div>
            }
        </>
    );
}
export default Home;
