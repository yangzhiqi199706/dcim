import React, { useRef, useState, useEffect } from "react";
import { Stage, Layer, Rect, Transformer, Text, Group } from "react-konva";
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

const normalizeStageSize = (value, fallback) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return fallback;
    }
    return Math.round(parsed);
};

function Home() {
    const stageRef = useRef();
    const containerRef = useRef();
    const previewDataRef = useRef(null);

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
    const safeStageWidth = normalizeStageSize(stageWidth, 1920);
    const safeStageHeight = normalizeStageSize(stageHeight, 1080);
    // Comment translated to English.
    const [canvasScale, setcanvasScale] = useState(100);

    // Comment translated to English.
    const getevData = async (callback) => {
        let res = await httpsend.getData('GetDeviceListKey', {
            ComboBox: "all"
        });
        let devList = [];
        if (res) {
            res.data.forEach((val, n) => {
                devList.push({
                    value: val.id,
                    label: val.DeviceName,
                    code: val.ProtocolCode,
                    codeName: val.ProtocolName,
                    onlyCode: val.OnlyCode,// Comment translated to English.
                })
            })
            callback(devList)
        }
    }
    useEffect(() => {
        if (resetBox) {
            getevData(function (devList) {
                let pagedev = [];
                imagesRef.current.forEach(shapeProps => {
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
                                            let finddevonlyindex = devList.findIndex(v => String(v.onlyCode) === currentKeyStr)
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
    }, [resetBox]);
    const ondataDevOptionSearch = (value) => { };
    const filterOption = (input, option) => (option && option.label).toLowerCase().includes(input.toLowerCase());
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

    // Comment translated to English.
    const handleResize = (stageWidth, stageHeight) => {
        const normalizedWidth = normalizeStageSize(stageWidth, 1920);
        const normalizedHeight = normalizeStageSize(stageHeight, 1080);
        // let sceneWidth = containerRef.current.clientWidth;
        // let scale = sceneWidth / stageWidth;
        let sceneWidth = window.innerWidth;//1730
        // alert(sceneWidth);
        // let sceneHeight = window.innerHeight;//829
        // let scaley = sceneHeight / stageHeight;
        let scalex = sceneWidth / normalizedWidth;
        console.log(new Date() + t('auto.k0334'))
        // console.log(stageWidth)
        // console.log(stageHeight)
        // console.log(scalex)
        // console.log(stageWidth * scalex)
        // console.log(stageHeight * scalex)
        setStageDimensions({
            width: normalizedWidth * scalex,
            height: normalizedHeight * scalex,
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
        let isDisposed = false;
        const intervalTimers = [];
        const timeoutTimers = [];
        const registerInterval = (callback, delay) => {
            const id = setInterval(() => {
                if (!isDisposed) {
                    callback();
                }
            }, delay);
            intervalTimers.push(id);
            return id;
        };
        const registerTimeout = (callback, delay) => {
            const id = setTimeout(() => {
                if (!isDisposed) {
                    callback();
                }
            }, delay);
            timeoutTimers.push(id);
            return id;
        };
        const clearAllTimers = () => {
            intervalTimers.forEach((id) => clearInterval(id));
            timeoutTimers.forEach((id) => clearTimeout(id));
            intervalTimers.length = 0;
            timeoutTimers.length = 0;
        };

        // Comment translated to English.
        const onKeyDown = (e) => {
            if (e.ctrlKey && (e.key === 'C' || e.key === 'c')) {
                handleToolChange('copy');
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
            } else if (e.ctrlKey && (e.key === 'L' || e.key === 'l')) {
                handleToolChange('lock');
            } else if (e.ctrlKey && (e.key === 'N' || e.key === 'n')) {
                handleToolChange('unlock');
            } else if (e.ctrlKey && (e.key === 'S' || e.key === 's')) {
                if (savePageId !== '0' && savePageType === '1') {
                    savePage('page')
                }
            } else if (e.key === 'Delete') {
                handleToolChange('del');
                // } else if (e.key === 'F' || e.key === 'f') {
                //     if (!document.fullscreenElement) {
                // Comment translated to English.
                //         document.documentElement.requestFullscreen().then(() => {
                //             handleResize(stageWidthRef.current, stageHeightRef.current);
                //         });
                //     }
            } else {
                return;
            }
        }
        window.addEventListener('keydown', onKeyDown);
        // Comment translated to English.
        // const checkFull = () => {
        //     if (!document.webkitIsFullScreen && !document.mozFullScreen && !document.msFullscreenElement) {
        // Comment translated to English.
        //         handleResize(stageWidthRef.current, stageHeightRef.current);
        //     } else {
        // Comment translated to English.
        //     }
        // };
        // window.addEventListener("webkitfullscreenchange", checkFull);
        // window.addEventListener("mozfullscreenchange", checkFull);
        // window.addEventListener("fullscreenchange", checkFull);
        // window.addEventListener("MSFullscreenChange", checkFull);

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
            const setNewView = () => {
                // Comment translated to English.
                let startviewTime = new Date().getTime();
                let tplimages = [];
                const previewjson = previewDataRef.current;
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
                registerTimeout(() => {
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
                    previewDataRef.current = await gettxtdata();
                } else {
                    previewDataRef.current = JSON.parse(JSON.parse(localStorage.getItem('stageJson')));
                }
                if (isDisposed) return;
                const previewjson = previewDataRef.current;
                if (previewjson) {
                    await getHisDevId(previewjson.children[0].children);
                    if (isDisposed) return;
                    handlepredata(previewjson);// Comment translated to English.
                    setNewView()// Comment translated to English.
                } else {
                    message.error(txttitle + t('auto.k0339'));
                }
            };
            getPageInfo();

            var devtime = registerInterval(async () => {
                // console.log(allDevcom)
                // console.log(devtime)
                if (allDevcom && allDevcom.data && devtime) {
                    clearInterval(devtime);
                    console.log(t('auto.k0340') + new Date())
                    console.log(t('auto.k0341') + new Date())
                    // Comment translated to English.
                    // Comment translated to English.
                    // Comment translated to English.
                    setNewView();
                    // setTimeout(() => {
                    console.log(t('auto.k0342'));
                    console.log('DevID');
                    console.log(DevID);
                    console.log('DevSpareID')
                    console.log(DevSpareID)
                    if (DevID.length !== 0 || Object.keys(DevSpareID).length > 0) {
                        console.log(t('auto.k0343') + new Date())
                        var historytime = registerInterval(() => {
                            if (historyData && historyData.msg) {// Comment translated to English.
                                clearInterval(historytime);
                                // havehis = true;
                                setPageView();
                                console.log(t('auto.k0344') + new Date())
                                // Comment translated to English.
                                pageHistoryTime = registerInterval(() => {
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
                        var historyparamtime = registerInterval(() => {
                            if (historyparamData && historyparamData.msg) {// Comment translated to English.
                                clearInterval(historyparamtime);
                                // havehispar = true;
                                setPageView();
                                console.log(t('auto.k0348') + new Date())
                                // Comment translated to English.
                                // Comment translated to English.
                                pageparamHistoryTime = registerInterval(() => {
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
                        var snmptime = registerInterval(() => {
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
                        var cuspartime = registerInterval(() => {
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
                        setNewView();
                    }
                }
                console.log(t('auto.k0359') + new Date())
            }, 10)
            pageTime = registerInterval(() => {
                if (allDev.length !== 0) getAllcom(allDev.join(','));
                if (DevPar.length !== 0) getParamData();
                if (alarmCatchRef.current === '1') getAlarmData();
                setNewView();
                pageTimecalc++;
                console.log(pageTimecalc);
            }, 10000)
            // window.addEventListener("resize", handleResize(stageWidthRef.current, stageHeightRef.current), false);
        }
        // handleResize();
        // window.addEventListener("resize", handleResize, false);
        // return () => window.addEventListener("resize", handleResize, false);
        return () => {
            isDisposed = true;
            window.removeEventListener("keydown", onKeyDown);
            clearAllTimers();
            clearInterval(pageTime);
            if (pageHistoryTime) clearInterval(pageHistoryTime);
            if (pageparamHistoryTime) clearInterval(pageparamHistoryTime);
        }
    }, []);// Comment translated to English.

    // Comment translated to English.
    useEffect(() => {
        const nodes = selectedIds.map((id) => layerRef.current.findOne("#" + id));
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
        // do we pressed shift or ctrl?
        const metaPressed = e.evt.shiftKey;
        const isSelected = tr.nodes().indexOf(e.target) >= 0;
        const isDrag = e.target.parent.attrs.draggable;
        // Comment translated to English.
        if (!isDrag) { message.error(t('auto.k0361')); return; }
        if (!metaPressed && !isSelected) {
            // if no key pressed and the node is not selected
            // Comment translated to English.
            selectShapes([]);
            selectedIdsRef.current = [];
            return;
        } else if (metaPressed && isSelected) {// Comment translated to English.
            // if we pressed keys and node was selected
            // we need to remove it from selection:
            selectShapes((oldShapes) => {
                let ids = oldShapes.filter((oldId) => oldId !== e.target.parent.attrs.id)
                selectedIdsRef.current = ids;
                return ids;
            });

        } else if (metaPressed && !isSelected) {
            // add the node into selection
            selectShapes((oldShapes) => {
                let resShapes = oldShapes;
                if (oldShapes.indexOf(selectedId) === -1) {
                    let isDragIndex = images.findIndex((findid) => selectedId === findid.id)
                    if (isDragIndex !== -1) {
                        let isDrag = images[isDragIndex].draggable;// Comment translated to English.
                        if (isDrag) resShapes = [...resShapes, selectedId];
                    }
                }
                selectedIdsRef.current = [...resShapes, e.target.parent.attrs.id];
                return [...resShapes, e.target.parent.attrs.id];
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
        let ids = elements.map((el) => el.attrs.id);
        selectShapes(ids);
        selectedIdsRef.current = ids;
        updateSelectionRect('remove');
    };
    // Comment translated to English.

    // Comment translated to English.
    // Comment translated to English.
    const handleItemDragUrl = async (dragUrl, dragAttrs, type) => {
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
    const handleMultiToolBack = (type) => {
        let tr = transformRefids.current;
        const stage = stageRef.current.getStage();
        let w = tr.width();
        let h = tr.height();
        // Comment translated to English.
        // Comment translated to English.
        // Comment translated to English.
        // Comment translated to English.
        // Comment translated to English.
        let xl = tr.x();//left
        let xr = tr.x() + w;//right
        let yt = tr.y();//top
        let yb = tr.y() + h;//bottom
        let xr2 = tr.x() + (w / 2);
        let yt2 = tr.y() + (h / 2);
        let maxh = 0, maxw = 0, totalw = 0, totalh = 0, newxSelectedIds = [], newySelectedIds = [];
        if (type.indexOf('equal') >= 0) {// Comment translated to English.
            selectedIdsRef.current.forEach((ids, n) => {
                const findIndex = imagesRef.current.findIndex((img) => img.id === ids);
                const singnodeId = stage.findOne('#' + ids);
                if (singnodeId && findIndex !== -1) {
                    let singleImage = imagesRef.current[findIndex];
                    let groupAttr = singleImage.moduleJson.children[0].attrs;
                    let groupName = groupAttr.name;
                    if (groupName === 'rectBackground') {
                        groupAttr = singleImage.moduleJson.children[3].attrs;
                    }
                    let findWidth = groupAttr.width ? groupAttr.width : singnodeId.children[0].textWidth + 20;
                    let findHeight = groupAttr.height ? groupAttr.height : singnodeId.children[0].textHeight + 20;
                    let width = findWidth * (singleImage.scaleX ? singleImage.scaleX : 1);
                    let height = findHeight * (singleImage.scaleY ? singleImage.scaleY : 1);
                    let borderWidth = groupAttr.strokeWidth;// Comment translated to English.

                    if (n !== selectedIdsRef.current.length - 1) {
                        width = (groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? width + borderWidth * (singleImage.scaleX ? singleImage.scaleX : 1) : width;
                        height = (groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? height + borderWidth * (singleImage.scaleY ? singleImage.scaleY : 1) : height;
                    }
                    if (maxh < height) {
                        maxh = height;
                    }
                    if (maxw < width) {
                        maxw = width;
                    }
                    totalw += width;
                    totalh += height;

                    newxSelectedIds.push({// Comment translated to English.
                        id: ids,
                        x: singleImage.x
                    });
                    newySelectedIds.push({
                        id: ids,
                        y: singleImage.y
                    })
                }
            })
        }
        console.log(t('auto.k0367') + w)
        console.log(t('auto.k0368') + totalw)
        console.log(t('auto.k0369') + h)
        console.log(t('auto.k0370') + totalh)

        let stepx = (w - totalw) / (selectedIdsRef.current.length - 1);// Comment translated to English.
        let stepy = (h - totalh) / (selectedIdsRef.current.length - 1);
        console.log(t('auto.k0371') + stepx)
        console.log(t('auto.k0372') + stepy)

        let neworderIds = [];// Comment translated to English.
        // Comment translated to English.
        if (type === "equallevel") {
            newxSelectedIds = newxSelectedIds.sort(function (a, b) {
                return a.x - b.x;
            });
            newxSelectedIds.forEach((id) => {
                neworderIds.push(id.id);
            })
            // Comment translated to English.
        } else if (type === "equalvertical") {
            newySelectedIds = newySelectedIds.sort(function (a, b) {
                return a.y - b.y;
            });
            newySelectedIds.forEach((id) => {
                neworderIds.push(id.id);
            })
        } else {
            neworderIds = selectedIdsRef.current;
        }
        let newy = 0;// Comment translated to English.
        let newx = 0;
        let copyids = [];
        neworderIds.forEach((ids, n) => {
            let imagesToUpdate = imagesRef.current;
            const findIndex = imagesRef.current.findIndex((img) => img.id === ids);
            const singnodeId = stage.findOne('#' + ids);
            // console.log(singnodeId)
            if (singnodeId && findIndex !== -1) {
                let singleImageToUpdate = JSON.parse(JSON.stringify(imagesToUpdate))[findIndex];
                // Comment translated to English.
                let groupAttr = singleImageToUpdate.moduleJson.children[0].attrs;
                let groupName = groupAttr.name;
                if (groupName === 'rectBackground') {
                    groupAttr = singleImageToUpdate.moduleJson.children[3].attrs;
                }
                let findWidth = groupAttr.width ? groupAttr.width : singnodeId.children[0].textWidth + 20;
                let findHeight = groupAttr.height ? groupAttr.height : singnodeId.children[0].textHeight + 20;
                let width = findWidth * (singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX : 1);
                let height = findHeight * (singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY : 1);
                let borderWidth = groupAttr.strokeWidth / 2;// Comment translated to English.
                switch (type) {
                    case "copys":
                        let eleId = parseInt(new Date().getTime()).toString() + n// Comment translated to English.
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: singleImageToUpdate.x + 5,
                            y: singleImageToUpdate.y + 5,
                            id: eleId
                        };
                        copyids.push(eleId);
                        console.log(t('auto.k0373'));
                        break;
                    case "alginleft":
                        // Comment translated to English.
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: (groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? xl + borderWidth * (singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX : 1) : xl
                        };
                        console.log(t('auto.k0374'));
                        break;
                    case "alginright":
                        // Comment translated to English.
                        // Comment translated to English.
                        // Comment translated to English.
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: parseFloat(((groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? (xr - borderWidth * (singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX : 1)) : xr) - width)
                            // x: parseFloat(xr - width)
                        };
                        console.log(t('auto.k0375'));
                        break;
                    case "algintop":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            y: (groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? yt + borderWidth * (singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY : 1) : yt
                        };
                        console.log(t('auto.k0376'));
                        break;
                    case "alginbottom":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            y: parseFloat(((groupName === 'myShape' || groupName === 'buttonRect' || groupName === 'rectBackground') ? (yb - borderWidth * (singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY : 1)) : yb) - height)
                        };
                        console.log(t('auto.k0377'));
                        break;
                    case "alginvertical":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            x: parseFloat(xr2 - (width / 2))
                        };
                        console.log(t('auto.k0378'));
                        break;
                    case "algincenter":
                        singleImageToUpdate = {
                            ...singleImageToUpdate,
                            y: parseFloat(yt2 - (height / 2))
                        };
                        console.log(t('auto.k0379'));
                        break;
                    case "equalhight":
                        if (maxh !== height) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                scaleY: maxh / height
                            };
                        }
                        console.log(t('auto.k0380'));
                        break;
                    case "equalwidth":
                        if (maxw !== width) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                scaleX: maxw / height
                            };
                        }
                        console.log(t('auto.k0381'));
                        break;
                    case "equal":
                        if (maxh !== height || maxw !== width) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                scaleY: maxh / height,
                                scaleX: maxw / width
                            };
                        }
                        console.log(t('auto.k0382'));
                        break;
                    case "equalvertical":
                        if (newy !== 0) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                y: newy
                            };
                        }
                        //     height=((groupName==='myShape' || groupName==='buttonRect' || groupName==='rectBackground')?(height+0.5*(singleImageToUpdate.scaleY ? singleImageToUpdate.scaleY : 1)):height)
                        newy = singleImageToUpdate.y + height + stepy// Comment translated to English.
                        console.log(t('auto.k0383'));
                        break;
                    case "equallevel":
                        if (newx !== 0) {
                            singleImageToUpdate = {
                                ...singleImageToUpdate,
                                x: newx
                            };
                        }
                        // width=((groupName==='myShape' || groupName==='buttonRect' || groupName==='rectBackground')?(width-0.5*(singleImageToUpdate.scaleX ? singleImageToUpdate.scaleX : 1)):width)
                        newx = singleImageToUpdate.x + width + stepx// Comment translated to English.
                        console.log(t('auto.k0384'));
                        break;
                    default: break;
                }
                if (type === 'copys') {
                    imagesToUpdate.push(JSON.parse(JSON.stringify(singleImageToUpdate)));
                } else {
                    imagesToUpdate[findIndex] = singleImageToUpdate;
                }
                setImages(JSON.parse(JSON.stringify(imagesToUpdate)));
                imagesRef.current = JSON.parse(JSON.stringify(imagesToUpdate));
            }
            if (n + 1 === neworderIds.length) {
                history.push(JSON.parse(JSON.stringify(imagesToUpdate)));
                setChart(imagesRef.current, selectedIdRef.current, null);
                if (type === 'copys') {
                    selectShapes(copyids);
                    selectedIdsRef.current = copyids;
                }
            }
        })
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
        stagejson = stageRef.current.toJSON();
        let newjson = JSON.parse(stagejson);
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
                                {/* 磁吸 / 组合按钮位 — 后续 commit 填入 */}
                            </div>
                        </div>
                        <div className="topRight">
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
                            <li className={showIndex === 1 ? 'check' : ''} onClick={() => setshowIndex(1)}>{t('auto.k0394')}</li>
                            <li className={showIndex === 2 ? 'check' : ''} onClick={() => setshowIndex(2)}>{t('auto.k0395')}</li>
                        </ul>
                        {showIndex === 1 &&
                            <ElementAttr
                                MultiSelect={selectedIds.length !== 0}
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
                            width={safeStageWidth}
                            height={safeStageHeight}
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
                            width={safeStageWidth}
                            height={safeStageHeight}
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
                                    return (<ConElement
                                        id={shape.id}
                                        key={shape.id}
                                        shapeProps={shape}
                                        isSelected={shape.id === selectedId && selectedIds.length === 0}
                                        toolType={shape.id === selectedId ? toolType : null}
                                        onToolBack={(newShapeProps, type) => {
                                            handleToolBack(newShapeProps, type);
                                        }}
                                        onSelect={() => {
                                            if (selectedId !== shape.id) {
                                                setSelectedId(null);
                                                setDragShape(null);
                                                setTimeout(() => {
                                                    // Comment translated to English.
                                                    setSelectedId(shape.id);
                                                    selectedIdRef.current = shape.id;
                                                    setDragShape(shape);
                                                    // console.log(shape)
                                                });
                                            }
                                        }}
                                        onChange={(newShapeProps) => {
                                            // Comment translated to English.
                                            // console.log(newShapeProps)
                                            handleShapeChange(newShapeProps, shape.id);
                                        }} />
                                    );
                                })}
                                <Transformer
                                    ref={transformRefids}
                                    flipEnabled={false}
                                    boundBoxFunc={(oldBox, newBox) => getBoundedTransformerBox(oldBox, newBox)} />
                                <Rect fill="rgba(0,0,255,0.5)" ref={selectionRectRef} />
                            </Layer>
                        </Stage> : <Stage
                            className="canvasStage canvasStage2"
                            width={safeStageWidth}
                            height={safeStageHeight}
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
                                    setImages([]);
                                    setBackgroundImage(null);
                                    setalarmCatch('1')
                                    alarmCatchRef.current = '1';
                                    imagesRef.current = [];
                                    setChart(JSON.parse(JSON.stringify(imagesRef.current)), null, null);
                                    history = [];
                                    if (savePageType === '1') {// Comment translated to English.
                                        let fileres = await httpsend.getDataLocal('savePage', { name: savefilename, pagecon: JSON.stringify(stageRef.current.toJSON()) });
                                        if (fileres.code === 100) {
                                            message.success(t('auto.k0443'));
                                        } else {
                                            message.error(t('auto.k0444'));
                                        }
                                    } else {
                                        message.success(t('auto.k0443'));
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
                                                message.success(t('auto.k0443'));
                                            } else {
                                                message.error(t('auto.k0444'));
                                            }
                                        } else {
                                            message.success(t('auto.k0443'));
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
                                width={safeStageWidth}
                                height={safeStageHeight}
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
