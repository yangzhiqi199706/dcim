import React, { useEffect, useRef, useState } from 'react';
import { Layer, Stage } from 'react-konva';
import httpsend from '../Assets/httpsend';
import PreviewElement from './PreviewElement';
import PreviewDeal from './PreviewDeal';
import SvgBackground from './SvgBackground';
import setChart from './SetChart';
import {
    createEmptyPreviewResponse,
    createPreviewRefreshChannels,
    mergeSuccessfulPreviewData,
    runPreviewDataBatchWithStatus
} from './previewDataBatch';
import {
    PREVIEW_REALTIME_INTERVAL_MS,
    createInitialPreviewRenderState,
    createPreparedPreviewModel,
    getPreviewChartRenderIds,
    mergePreviewChartRenderIds,
    reconcilePreviewElements,
    selectPreviewSources
} from './previewIncrementalRender';
import { buildMainApiUrl } from '../config/endpoints';
import { getLogoConfig, persistLogoRuntimeConfig } from '../config/logoConfig';
import { applyGlobalDataSourceToShapes, readGlobalDataSource } from '../Assets/globalDataSource';
import {
    collectPreviewDataSourceGroups,
    groupPreviewSourcesByDataSource,
    mergePreviewShapesByDataSource,
    mergeDataSourceResponse,
    mergeFailedDataSourceHosts,
    requestDataSourceGroups,
    selectDataSourceResponse,
    splitPreviewShapeByDataSource,
    tagDataSourceResponse
} from '../Assets/dataSource';
import { t } from '../i18n';
import '../Assets/base.css';
import '../Assets/preview.css';

const params = new URLSearchParams(window.location.search);
const isSwiper = Boolean(params.get('swiper'));
const txttitle = params.get('title') || '';

const normalizeStageSize = (value, fallback) => {
    const parsed = Number(value);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return fallback;
    }
    return Math.round(parsed);
};

function PreviewApp() {
    const stageRef = useRef();
    const containerRef = useRef();
    const previewDataRef = useRef(null);
    const [backgroundImage, setBackgroundImage] = useState();
    const [alarmCatch, setalarmCatch] = useState('1');
    const alarmCatchRef = useRef(alarmCatch);
    const [imagesstatic, setImagesstatic] = useState([]);
    const [imagesdata, setImagesdata] = useState([]);
    const imagesRef = useRef(imagesdata);
    const [stageWidth, setstageWidth] = useState(1920);
    const stageWidthRef = useRef(stageWidth);
    const [stageHeight, setstageHeight] = useState(1080);
    const stageHeightRef = useRef(stageHeight);
    const safeStageWidth = normalizeStageSize(stageWidth, 1920);
    const safeStageHeight = normalizeStageSize(stageHeight, 1080);
    const [stageDimensions, setStageDimensions] = useState({
        width: 1920,
        height: 1080,
        scalex: 1,
        scaley: 1
    });
    const [useSlaveId, setUseSlaveId] = useState(() => localStorage.getItem('UseSlaveID') === '1');
    const useSlaveIdRef = useRef(useSlaveId);
    const [loadError, setLoadError] = useState('');

    useEffect(() => {
        useSlaveIdRef.current = useSlaveId;
    }, [useSlaveId]);

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
        return tplimages;
        // return PreviewDeal.PreviewDeal(tplimages, procotol, allDevcom, historyData, paramData, snmplist);
        // return PreviewDeal.PreviewDeal(tplimages, procotol, allDevcom, historyData, paramData, allsnmplist, historyparamData, alarmData)
    }

    async function gettxtdata() {
        const conres = await httpsend.getDataLocal('imgData', { action: 'page', name: txttitle });
        const hasPageData = conres
            && conres.code === 100
            && conres.data
            && conres.data[0]
            && typeof conres.data[0].moduleJson === 'string'
            && conres.data[0].moduleJson.indexOf('{') > -1;
        if (!hasPageData) return '';

        try {
            return JSON.parse(JSON.parse(conres.data[0].moduleJson));
        } catch (e) {
            return '';
        }
    }

    // Comment translated to English.

    useEffect(() => {
        let isDisposed = false;
        const intervalTimers = [];
        const timeoutTimers = [];
        let previewChartTimer = null;
        let pendingPreviewChartIds = [];
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
            let dataSourceGroups = collectPreviewDataSourceGroups([]);
            let failedProtocolSourceHosts = new Set();
            const requestFromDataSource = (sourceHost, path, payload) => httpsend.getDataFrom(sourceHost, path, payload);
            const hasGroupValues = (field) => Object.keys(dataSourceGroups).some((sourceHost) => (
                Array.isArray(dataSourceGroups[sourceHost][field]) && dataSourceGroups[sourceHost][field].length > 0
            ));

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
                                    if (!el.sourceHost && el.src && el.src.indexOf('@') > -1) {
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
                DevParID = [...new Set(DevParID)];// Comment translated to English.
                DevID = [...new Set(DevID)];// Comment translated to English.
                DevIDParam = [...new Set(DevIDParam)];// Comment translated to English.
                DevPar = [...new Set(DevPar)];// Comment translated to English.
                allDev = [...new Set(allDev)];// Comment translated to English.
                Object.keys(DevSpareID).forEach((serverIP) => {
                    const spare = DevSpareID[serverIP];
                    spare.devkey = [...new Set(spare.devkey)];
                    spare.cmdtype = [...new Set(spare.cmdtype)];
                    spare.devTodev = [...new Set(spare.devTodev)];
                });
                dataSourceGroups = collectPreviewDataSourceGroups(imagesdata);
            }
            // Comment translated to English.
            const getpro = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetDeviceProtocolListKey',
                    () => ({ ComboBox: 'all' }),
                    requestFromDataSource
                );
            }
            // Comment translated to English.
            const getAllcom = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetDevCommandListKey',
                    group => group.realtimeDeviceIds.length > 0 ? {
                        ComboBox: 'all',
                        DevIDs: group.realtimeDeviceIds.join(',')
                    } : null,
                    requestFromDataSource
                );
            }
            // Comment translated to English.
            const getParamData = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetParamListKey',
                    group => group.customParameterIds.length > 0 ? { ComboBox: 'calc' } : null,
                    requestFromDataSource
                );
            }
            // Comment translated to English.
            const getSnmpParamData = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetSnmpParamListKey',
                    group => group.snmpDeviceIds.length > 0 ? {
                        DevIDs: group.snmpDeviceIds.join(','),
                        DataType: t('auto.k0335'),
                        ComboBox: 'all'
                    } : null,
                    requestFromDataSource
                );
            }
            // Comment translated to English.
            const getHistoryData = async () => {
                let waitHistoryData = await requestDataSourceGroups(
                    dataSourceGroups,
                    'GetHistoryAlarmsKey',
                    group => group.historyDeviceIds.length > 0 ? {
                        startDateTime: getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00',
                        endDateTime: getDateTime(new Date(new Date())) + ' 23:59:59',
                        DevID: group.historyDeviceIds.join(','),
                        keyword: group.historyCommandTypes.join(','),
                        ComboBox: 'all'
                    } : null,
                    requestFromDataSource
                );
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
                            const taggedSpare = tagDataSourceResponse(spareRes, '');
                            waitHistoryData.data = waitHistoryData.data.concat(taggedSpare.data || []);
                            // console.log(waitHistoryData)
                        }
                    }
                }
                return waitHistoryData && waitHistoryData.data
                    ? waitHistoryData
                    : createEmptyPreviewResponse();
            }
            // Comment translated to English.
            const getHistoryParamData = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetParamDayListKey',
                    group => group.historyParameterIds.length > 0 ? {
                        startDateTime: getDateTime(new Date(new Date() - 1000 * 60 * 60 * 24 * 8)) + ' 00:00:00',
                        endDateTime: getDateTime(new Date(new Date())) + ' 23:59:59',
                        ParamIds: group.historyParameterIds.join(','),
                        ComboBox: 'all'
                    } : null,
                    requestFromDataSource
                );
            }

            // Comment translated to English.
            const getAlarmData = async () => {
                return requestDataSourceGroups(
                    dataSourceGroups,
                    'GetAlarmListKey',
                    () => ({ type: '1', ComboBox: 'all' }),
                    requestFromDataSource
                );
            }

            const previewRefreshChannels = createPreviewRefreshChannels();
            let preparedPreviewModel = createPreparedPreviewModel([]);
            const getNormalizedProtocol = PreviewDeal.createProtocolNormalizer();
            const applyPreviewBatch = (batchResult) => {
                const batch = mergeSuccessfulPreviewData({
                    protocol: procotol,
                    realtime: allDevcom,
                    snmp: allsnmplist,
                    history: historyData,
                    historyParam: historyparamData,
                    param: paramData,
                    alarm: alarmData
                }, batchResult);
                if (batch.protocol) procotol = mergeDataSourceResponse(procotol, batch.protocol);
                if (batch.protocol) {
                    failedProtocolSourceHosts = mergeFailedDataSourceHosts(
                        failedProtocolSourceHosts,
                        batch.protocol,
                        Object.keys(dataSourceGroups)
                    );
                }
                if (batch.realtime) allDevcom = mergeDataSourceResponse(allDevcom, batch.realtime);
                if (batch.snmp) allsnmplist = mergeDataSourceResponse(allsnmplist, batch.snmp);
                if (batch.history) historyData = mergeDataSourceResponse(historyData, batch.history);
                if (batch.historyParam) historyparamData = mergeDataSourceResponse(historyparamData, batch.historyParam);
                if (batch.param) paramData = mergeDataSourceResponse(paramData, batch.param);
                if (batch.alarm) alarmData = mergeDataSourceResponse(alarmData, batch.alarm);
            };
            const schedulePreviewChartRender = (changedChartIds) => {
                if (!changedChartIds.length) return;
                pendingPreviewChartIds = mergePreviewChartRenderIds(pendingPreviewChartIds, changedChartIds);
                if (previewChartTimer !== null) clearTimeout(previewChartTimer);
                previewChartTimer = registerTimeout(() => {
                    previewChartTimer = null;
                    const renderIds = pendingPreviewChartIds;
                    pendingPreviewChartIds = [];
                    setChart(imagesRef.current, null, alarmData, { changedChartIds: renderIds, delay: 0 });
                }, 100);
            };
            const loadPreviewData = (runner, options) => runner(async () => {
                const batchResult = await runPreviewDataBatchWithStatus([
                    options.protocol && {
                        key: 'protocol',
                        run: getpro,
                        fallback: createEmptyPreviewResponse
                    },
                    options.realtime && {
                        key: 'realtime',
                        run: () => hasGroupValues('realtimeDeviceIds')
                            ? getAllcom()
                            : createEmptyPreviewResponse(),
                        fallback: createEmptyPreviewResponse
                    },
                    options.snmp && {
                        key: 'snmp',
                        run: () => hasGroupValues('snmpDeviceIds')
                            ? getSnmpParamData()
                            : createEmptyPreviewResponse(),
                        fallback: createEmptyPreviewResponse
                    },
                    options.history && {
                        key: 'history',
                        run: () => hasGroupValues('historyDeviceIds') || Object.keys(DevSpareID).length
                            ? getHistoryData()
                            : createEmptyPreviewResponse(),
                        fallback: createEmptyPreviewResponse
                    },
                    options.historyParam && {
                        key: 'historyParam',
                        run: () => hasGroupValues('historyParameterIds')
                            ? getHistoryParamData()
                            : createEmptyPreviewResponse(),
                        fallback: createEmptyPreviewResponse
                    },
                    options.param && {
                        key: 'param',
                        run: () => hasGroupValues('customParameterIds') ? getParamData() : createEmptyPreviewResponse(),
                        fallback: createEmptyPreviewResponse
                    },
                    options.alarm && {
                        key: 'alarm',
                        run: getAlarmData,
                        fallback: createEmptyPreviewResponse
                    }
                ]);

                if (isDisposed) return batchResult;
                applyPreviewBatch(batchResult);
                const refreshCategories = Object.keys(options || {}).filter(key => options[key]);
                if (options.snmp) refreshCategories.push('realtime');
                setNewView(refreshCategories);
                return batchResult;
            });

            // Comment translated to English.
            const syncSlaveIdConfig = async () => {
                let res = await httpsend.getData('GetLogoKey', {})
                const logoData = getLogoConfig(res);
                if (!logoData) return;
                const enabled = persistLogoRuntimeConfig(logoData);
                useSlaveIdRef.current = enabled;
                setUseSlaveId(enabled);
            }

            // Comment translated to English.
            // const getEventData = async () => {
            //     let res = await httpsend.getData('GetEventListKey', {
            //         type: '1',
            //         ComboBox: 'all'
            //     })
            //     if (res) eventData = res;
            // }

            syncSlaveIdConfig();

            // Comment translated to English.
            const setNewView = (refreshCategories) => {
                const sources = selectPreviewSources(preparedPreviewModel, refreshCategories);
                const sourceClones = sources.reduce((items, shape) => (
                    items.concat(splitPreviewShapeByDataSource(shape))
                ), []);
                const groupedSources = groupPreviewSourcesByDataSource(sourceClones);
                const sourceCandidates = Object.keys(groupedSources).reduce((items, sourceHost) => {
                    const sourceProtocol = selectDataSourceResponse(procotol, sourceHost);
                    const sourceCandidates = PreviewDeal.PreviewDeal(
                        groupedSources[sourceHost],
                        sourceProtocol,
                        selectDataSourceResponse(allDevcom, sourceHost),
                        selectDataSourceResponse(historyData, sourceHost),
                        selectDataSourceResponse(paramData, sourceHost),
                        selectDataSourceResponse(allsnmplist, sourceHost),
                        selectDataSourceResponse(historyparamData, sourceHost),
                        selectDataSourceResponse(alarmData, sourceHost),
                        getNormalizedProtocol(sourceProtocol)
                    ) || [];
                    return items.concat(sourceCandidates);
                }, []);
                const candidates = mergePreviewShapesByDataSource(sourceCandidates);
                const result = reconcilePreviewElements(preparedPreviewModel, imagesRef.current, candidates);
                imagesRef.current = result.elements;
                setImagesdata(result.elements);
                schedulePreviewChartRender(getPreviewChartRenderIds(
                    preparedPreviewModel,
                    refreshCategories,
                    result.changedChartIds
                ));
            };

            let refreshTimersStarted = false;
            const startPreviewRefreshTimers = () => {
                if (refreshTimersStarted) return;
                refreshTimersStarted = true;

                registerInterval(() => {
                    void loadPreviewData(previewRefreshChannels.realtime, {
                        protocol: !procotol || failedProtocolSourceHosts.size > 0,
                        realtime: true,
                        param: true,
                        alarm: alarmCatchRef.current === '1'
                    });
                }, PREVIEW_REALTIME_INTERVAL_MS);

                if (hasGroupValues('historyDeviceIds') || Object.keys(DevSpareID).length) {
                    registerInterval(() => {
                        void loadPreviewData(previewRefreshChannels.background, { history: true });
                    }, 600000);
                }

                if (hasGroupValues('historyParameterIds')) {
                    registerInterval(() => {
                        const todayDate = getDateTime(new Date());
                        if (pageparamHistoryDate !== todayDate) {
                            pageparamHistoryDate = todayDate;
                            localStorage.setItem('pageparamHistoryDate', todayDate);
                            void loadPreviewData(previewRefreshChannels.background, { historyParam: true });
                        }
                    }, 3600000);
                }
            };

            const getPageInfo = async () => {
                if (txttitle) {
                    previewDataRef.current = await gettxtdata();
                } else {
                    previewDataRef.current = JSON.parse(JSON.parse(localStorage.getItem('stageJson')));
                }
                if (isDisposed) return;
                const previewjson = previewDataRef.current;
                if (previewjson) {
                    const layer = previewjson.children[0];
                    const globalDataSource = readGlobalDataSource();
                    const effectiveChildren = applyGlobalDataSourceToShapes(
                        layer.children,
                        globalDataSource.enabled ? globalDataSource.host : ''
                    );
                    const effectivePreviewJson = effectiveChildren === layer.children ? previewjson : {
                        ...previewjson,
                        children: [
                            { ...layer, children: effectiveChildren },
                            ...previewjson.children.slice(1),
                        ],
                    };
                    await getHisDevId(effectiveChildren);
                    if (isDisposed) return;
                    const dynamicSources = handlepredata(effectivePreviewJson);
                    preparedPreviewModel = createPreparedPreviewModel(dynamicSources);
                    const initialPreviewState = createInitialPreviewRenderState(preparedPreviewModel);
                    imagesRef.current = initialPreviewState.elements;
                    setImagesdata(initialPreviewState.elements);
                    schedulePreviewChartRender(initialPreviewState.chartIds);
                    startPreviewRefreshTimers();
                    void loadPreviewData(previewRefreshChannels.realtime, {
                        initial: true,
                        protocol: true,
                        realtime: true,
                        param: true,
                        alarm: alarmCatchRef.current === '1'
                    });
                    void loadPreviewData(previewRefreshChannels.background, {
                        snmp: true,
                        history: true,
                        historyParam: true
                    });
                } else {
                    setLoadError(txttitle + t('auto.k0339'));
                }
            };
            void getPageInfo();
            // window.addEventListener("resize", handleResize(stageWidthRef.current, stageHeightRef.current), false);

        return () => {
            isDisposed = true;
            if (previewChartTimer !== null) clearTimeout(previewChartTimer);
            pendingPreviewChartIds = [];
            clearAllTimers();
        };
    }, []);

    return (
        <>
            {loadError && <div className="previewLoadError">{loadError}</div>}
            <div
                className="canvasBody"
                ref={containerRef}
                style={{ width: stageDimensions.width + 'px', height: stageDimensions.height + 'px' }}
            >
                <Stage
                    className="canvasStage"
                    width={stageDimensions.width}
                    height={stageDimensions.height}
                    scaleX={stageDimensions.scalex}
                    scaleY={stageDimensions.scaley}
                    ref={stageRef}
                    style={{ width: stageDimensions.width + 'px', height: stageDimensions.height + 'px', overflowX: 'hidden' }}
                >
                    <Layer>
                        {(backgroundImage && typeof backgroundImage === 'string') && (
                            <SvgBackground
                                backgroundUrl={backgroundImage}
                                width={safeStageWidth}
                                height={safeStageHeight}
                            />
                        )}
                        {imagesstatic.map((shape) => (
                            <PreviewElement
                                id={shape.id}
                                key={shape.id}
                                shapeProps={shape}
                                wheight={stageDimensions.height}
                                wwidth={stageDimensions.width}
                                wscale={stageDimensions.scalex}
                                onhandleResize={(type) => {
                                    if (type === 'full') {
                                        handleResize(stageWidthRef.current, stageHeightRef.current);
                                    }
                                }}
                                isSwiper={isSwiper}
                                useSlaveId={useSlaveId}
                            />
                        ))}
                        {imagesdata.map((shape) => (
                            <PreviewElement
                                id={shape.id}
                                key={shape.id}
                                shapeProps={shape}
                                wheight={stageDimensions.height}
                                wwidth={stageDimensions.width}
                                wscale={stageDimensions.scalex}
                                onhandleResize={() => {}}
                                isSwiper={isSwiper}
                                useSlaveId={useSlaveId}
                            />
                        ))}
                    </Layer>
                </Stage>
            </div>
        </>
    );
}

export default PreviewApp;
