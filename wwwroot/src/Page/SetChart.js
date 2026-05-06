import * as echarts from "echarts";
import { t } from '../i18n';
// Comment translated to English.
// import * as echarts from 'echarts/lib/echarts';
// import { LineChart, BarChart, PieChart } from 'echarts/charts';
// import { GridComponent, LegendComponent, TooltipComponent, TitleComponent } from 'echarts/components';
// import httpsend from '../Assets/httpsend';
// echarts.use([LineChart, BarChart, PieChart, GridComponent, LegendComponent, TooltipComponent, TitleComponent]);

// Comment translated to English.
const getAlarmData = async (element, chartInfo, alarmDataList) => {
    var alarmpieChart = echarts.init(element);
    // let res = await httpsend.getData('GroupAlarmStatisticKey', {
    //     "startDateTime": "1970-01-01 00:00:00",
    //     "endDateTime": "2099-12-31 23:59:59",
    //     "type": "level",
    //     "ServerCode": '',
    // })
    let alarmdata = [
        {name: t('auto.k0138'), value: 0},
        {name: t('auto.k0139'), value: 0},
        {name: t('auto.k0140'), value: 0},
        {name: t('auto.k0141'), value: 0},
        {name: t('auto.k0142'), value: 0}
    ];
    if (alarmDataList) {
        alarmdata = [
            {name: t('auto.k0138'), value: alarmDataList.data.filter(v=>v.AlarmLevel==='1').length},
            {name: t('auto.k0139'), value: alarmDataList.data.filter(v=>v.AlarmLevel==='2').length},
            {name: t('auto.k0140'), value: alarmDataList.data.filter(v=>v.AlarmLevel==='3').length},
            {name: t('auto.k0141'), value: alarmDataList.data.filter(v=>v.AlarmLevel==='4').length},
            {name: t('auto.k0142'), value: alarmDataList.data.filter(v=>v.AlarmLevel==='5').length}
        ]
    }
    let data = [
        {
            type: 'pie',
            radius: '70%',
            data: alarmdata,
            label: {
                position: 'inside',
                color: chartInfo.dataColor
            },
        }
    ];
    if (chartInfo.roseSwitch === '2') {
        if (data) {
            data.forEach(ele => {
                ele['radius'] = [20, 100];
                ele['roseType'] = 'radius';
                ele['itemStyle'] = {
                    borderRadius: 5
                };
            })
        }
    }
    var alarmpieoption = {
        tooltip: {
            trigger: 'item'
        },
        title: {// Comment translated to English.
            show: chartInfo.titleSwitch === '2' ? true : false,
            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
            textStyle: {
                color: chartInfo.titleColor,
                fontSize: chartInfo.titleFontSize
            }
        },
        legend: {// Comment translated to English.
            show: chartInfo.iconSwitch === '2' ? true : false,
            right: 10,
            textStyle: {
                color: chartInfo.iconColor
            },
            orient: chartInfo.orient,
            algin: chartInfo.algin
        },
        series: data
    };
    alarmpieoption && alarmpieChart.setOption(alarmpieoption);
}
// Comment translated to English.
function echart(images, selectedId,alarmData) {
    setTimeout(() => {
        if (!document.querySelectorAll(".chart")) return;
        let chartlist = document.querySelectorAll(".chart");
        for (var i = 0; i < chartlist.length; i++) {
            var element = chartlist[i];
            let findimg = getImgIndex(images, element);
            if (findimg > -1 && images[findimg] && images[findimg].id) {
                if (document.getElementById(images[findimg].id)) echarts.dispose(document.getElementById(images[findimg].id));
                // Comment translated to English.
                let chartInfo = images[findimg].moduleJson.children[0].attrs;
                if (chartInfo.cat === 'gauge') {// Comment translated to English.
                    var gaugeChart = echarts.init(element);
                    var gaugeoption = {
                        grid: {
                            left: 0,
                            top: 0,
                            bottom: 0,
                            right: 0
                        },
                        series: [
                            {
                                type: 'gauge',
                                max: chartInfo.max,
                                min: chartInfo.min,
                                progress: {// Comment translated to English.
                                    show: chartInfo.progressSwitch === '2' ? true : false
                                },
                                pointer: { // Comment translated to English.
                                    itemStyle: {
                                        color: chartInfo.progressSwitch === '2' ? chartInfo.progressColor : 'auto',
                                    }
                                },
                                axisTick: {// Comment translated to English.
                                    distance: 0,
                                    length: 3,
                                    lineStyle: {
                                        color: chartInfo.markColor,
                                        width: 1
                                    }
                                },
                                axisLabel: {// Comment translated to English.
                                    color: chartInfo.markFontColor,
                                },
                                itemStyle: {// Comment translated to English.
                                    color: chartInfo.progressSwitch === '2' ? chartInfo.progressColor : 'auto',
                                },
                                axisLine: {// Comment translated to English.
                                    lineStyle: {
                                        color: [
                                            [chartInfo.axisLine1, chartInfo.axisLineColor1],
                                            [chartInfo.axisLine2, chartInfo.axisLineColor2],
                                            [chartInfo.axisLine3, chartInfo.axisLineColor3],
                                            [chartInfo.axisLine4, chartInfo.axisLineColor4],
                                        ]
                                    }
                                },
                                splitLine: {// Comment translated to English.
                                    distance: 0,
                                    length: 5,
                                    lineStyle: {
                                        color: chartInfo.markColor,
                                        width: 1
                                    }
                                },
                                title: {
                                    offsetCenter: [0, '100%'],
                                    fontSize: chartInfo.titleFontSize,
                                    color: chartInfo.titleColor,
                                },
                                detail: {
                                    valueAnimation: true,
                                    formatter: '{value}',
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize,
                                    offsetCenter: [0, '70%']
                                },
                                data: [
                                    {
                                        value: chartInfo.data,
                                        name: chartInfo.titleSwitch === '2' ? chartInfo.title : ""
                                    }
                                ]
                            }
                        ]
                    };
                    gaugeChart.setOption(gaugeoption);
                } else if (chartInfo.cat === 'line') {// Comment translated to English.
                    var lineChart = echarts.init(element);
                    let top = 20;
                    let data = chartInfo.data;
                    let legenddata = [];
                    if (data) {
                        data.forEach(element => {
                            legenddata.push(element.name)
                        });
                    }
                    if (chartInfo.titleSwitch === '2') top = 50;
                    if (chartInfo.iconSwitch === '2') top = 50;
                    // Comment translated to English.
                    if (chartInfo.dataSwitch === '2') {
                        if (data) {
                            data.forEach(element => {
                                element['label'] = {
                                    show: true,
                                    position: 'top',
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize
                                }
                            });
                        }
                    }
                    // Comment translated to English.
                    if (chartInfo.areaSwitch === '2') {
                        if (data) {
                            data.forEach(element => {
                                element['areaStyle'] = {}
                            });
                        }
                    }
                    var lineoption = {
                        grid: {
                            top: top,
                            left: 50,
                            right: 20,
                            bottom: 30,
                        },
                        tooltip: {
                            trigger: 'axis'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            data: legenddata,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            }
                        },
                        xAxis: {
                            axisLine: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.xaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                show: chartInfo.xTextShow === '2' ? true : false,
                                textStyle: {
                                    color: chartInfo.xColor,
                                }
                            },
                            show: chartInfo.xshow === '2' ? true : false,
                            type: 'category',
                            boundaryGap: true,// Comment translated to English.
                            data: chartInfo.xdata
                        },
                        yAxis: {
                            axisLine: {// Comment translated to English.
                                show: chartInfo.yshow === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.yaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                textStyle: {
                                    color: chartInfo.yColor,
                                }
                            },
                            splitLine: {
                                show: chartInfo.splitSwitch === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.splitColor
                                }
                            },
                            type: 'value'
                        },
                        series: data
                    };

                    lineoption && lineChart.setOption(lineoption);
                } else if (chartInfo.cat === 'bar') {// Comment translated to English.
                    var barChart = echarts.init(element);
                    let top = 20;
                    let data = chartInfo.data;
                    let legenddata = [];
                    if (data) {
                        data.forEach(element => {
                            legenddata.push(element.name)
                        });
                    }
                    if (chartInfo.titleSwitch === '2') top = 50;
                    if (chartInfo.iconSwitch === '2') top = 50;
                    // Comment translated to English.
                    if (chartInfo.dataSwitch === '2') {
                        if (data) {
                            data.forEach(ele => {
                                ele['label'] = {
                                    show: true,
                                    position: 'top',
                                    color: chartInfo.dataColor,
                                    fontSize: chartInfo.dataFontSize
                                }
                            })
                        }
                    }
                    // Comment translated to English.
                    if (chartInfo.barbackgroundSwitch === '2') {
                        if (data) {
                            data.forEach(ele => {
                                ele['showBackground'] = true;
                                ele['backgroundStyle'] = {
                                    color: chartInfo.barbackground
                                }
                            })
                        }
                    }

                    var baroption = {
                        grid: {
                            top: top,
                            left: 50,
                            right: 20,
                            bottom: 30,
                        },
                        tooltip: {
                            trigger: 'axis'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            data: legenddata,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            }
                        },
                        xAxis: {
                            axisLine: {// Comment translated to English.
                                show: chartInfo.xshow === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.xaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                show:chartInfo.xTextShow === '2' ? true : false,
                                textStyle: {
                                    color: chartInfo.xColor,
                                }
                            },
                            type: 'category',
                            data: chartInfo.xdata
                        },
                        yAxis: {
                            axisLine: {// Comment translated to English.
                                show: chartInfo.yshow === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.yaxisLine
                                }
                            },
                            axisTick: {// Comment translated to English.
                                lineStyle: {
                                    color: chartInfo.markColor
                                }
                            },
                            axisLabel: {// Comment translated to English.
                                textStyle: {
                                    color: chartInfo.yColor,
                                }
                            },
                            splitLine: {
                                show: chartInfo.splitSwitch === '2' ? true : false,
                                lineStyle: {
                                    color: chartInfo.splitColor
                                }
                            },
                            type: 'value'
                        },
                        series: data
                    };

                    baroption && barChart.setOption(baroption);
                } else if (chartInfo.cat === 'pie') {// Comment translated to English.
                    var pieChart = echarts.init(element);
                    let data = [
                        {
                            type: 'pie',
                            radius: '70%',
                            data: chartInfo.data,
                            label: {
                                position: 'inside',
                                color: chartInfo.dataColor
                            },
                        }
                    ];
                    if (chartInfo.roseSwitch === '2') {
                        if (data) {
                            data.forEach(ele => {
                                ele['radius'] = [20, 100];
                                ele['roseType'] = 'radius';
                                ele['itemStyle'] = {
                                    borderRadius: 5
                                };
                            })
                        }
                    }
                    var pieoption = {
                        tooltip: {
                            trigger: 'item'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            },
                            orient: chartInfo.orient,
                            algin: chartInfo.algin
                        },
                        series: data
                    };

                    pieoption && pieChart.setOption(pieoption);
                } else if (chartInfo.cat === 'alarmpie') {// Comment translated to English.
                    getAlarmData(element, chartInfo, alarmData);
                } else if (chartInfo.cat === 'huan') {// Comment translated to English.
                    var huanChart = echarts.init(element);
                    var huanoption = {
                        tooltip: {
                            trigger: 'item'
                        },
                        title: {// Comment translated to English.
                            show: chartInfo.titleSwitch === '2' ? true : false,
                            text: chartInfo.titleSwitch === '2' ? chartInfo.title : '',
                            textStyle: {
                                color: chartInfo.titleColor,
                                fontSize: chartInfo.titleFontSize
                            }
                        },
                        legend: {// Comment translated to English.
                            show: chartInfo.iconSwitch === '2' ? true : false,
                            right: 10,
                            textStyle: {
                                color: chartInfo.iconColor
                            },
                            orient: chartInfo.orient,
                            algin: chartInfo.algin
                        },
                        series: [
                            {
                                type: 'pie',
                                radius: ['40%', '70%'],
                                avoidLabelOverlap: false,
                                label: {
                                    show: false,
                                    position: 'center'
                                },
                                emphasis: {
                                    label: {
                                        show: chartInfo.dataSwitch === '2' ? true : false,
                                        fontSize: chartInfo.datafontSize,
                                        fontWeight: 'bold',
                                        color: chartInfo.dataColor
                                    }
                                },
                                labelLine: {
                                    show: false
                                },
                                data: chartInfo.data
                            }
                        ]
                    };

                    huanoption && huanChart.setOption(huanoption);
                } else if (chartInfo.cat === 'pue') {// Comment translated to English.
                    var pueChart = echarts.init(element);
                    var pueoption = {
                        grid: {
                            left: 0,
                            top: 0,
                            bottom: 0,
                            right: 0
                        },
                        series: [
                            {
                                type: 'gauge',
                                max: 10,
                                axisLine: {
                                    lineStyle: {
                                        width: 10,
                                        color: [
                                            [
                                                0.5,
                                                {
                                                    type: 'linear',
                                                    x: 0,
                                                    y: 0,
                                                    x2: 0,
                                                    y2: 1,
                                                    colorStops: [
                                                        {
                                                            offset: 0,
                                                            color: '#B19589'
                                                        },
                                                        {
                                                            offset: 1,
                                                            color: '#3550F7'
                                                        }
                                                    ],
                                                    global: false
                                                }
                                            ],
                                            [
                                                1,
                                                {
                                                    type: 'linear',
                                                    x: 0,
                                                    y: 0,
                                                    x2: 0,
                                                    y2: 1,
                                                    colorStops: [
                                                        {
                                                            offset: 0,
                                                            color: '#B19589'
                                                        },
                                                        {
                                                            offset: 1,
                                                            color: '#F9BE4A'
                                                        }
                                                    ],
                                                    global: false
                                                }
                                            ]
                                        ]
                                    }
                                },
                                pointer: {
                                    itemStyle: {
                                        color: 'auto'
                                    }
                                },
                                axisTick: {
                                    distance: -16,
                                    length: 5,
                                    lineStyle: {
                                        color: '#fff',
                                        width: 1
                                    }
                                },
                                splitLine: {
                                    distance: -28,
                                    length: 30,
                                    lineStyle: {
                                        color: '#fff',
                                        width: 0
                                    }
                                },
                                axisLabel: {
                                    color: '#fff',
                                    distance: 12,
                                    fontSize: 10
                                },
                                detail: {
                                    valueAnimation: true,
                                    formatter: '{value}',
                                    color: '#F8C472',
                                    fontSize: 20,
                                    offsetCenter: [0, '70%']
                                },
                                data: [
                                    {
                                        value: chartInfo.data
                                    }
                                ]
                            }
                        ]
                    };
                    pueoption && pueChart.setOption(pueoption);
                }
            }
        }
    }, 1000)
}
// Comment translated to English.
function getImgIndex(images, element) {
    if (images && element) {
        return images.findIndex(v => 'Echart' + v.id === element.getAttribute('id'));
    } else {
        return null
    }

}
export default echart;