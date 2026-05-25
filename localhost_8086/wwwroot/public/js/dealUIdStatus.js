//有资产条上架要发控制命令变更在架 资产条同一项目必须唯一
//根据资产条的id和U位 通过事件获取设备id command命令为 资产条id - u - 设置值
//notips 无提醒
function setUStatus(uid, u, setval, notips) {
    //模糊匹配事件获取设备id  0000000000000101-1U状态
    let alaramname = uid + '-' + u + 'U状态';
    let DevID = '';
    let Command = uid + '-' + u + '-' + setval;
    let returnRes = '';//返回信息
    list.info('GetAlarmTypeListKey', {
        token: token,
        search: alaramname,
        ComboBox: 'all'
    }, function (res) {
        //获取结果仅有一条  获取多条则默认第一个
        if (res.data.length > 1) {
            layer.msg('设备匹配不唯一！', {
                icon: 5,
                time: 2000
            });
            return false;
        } else {
            if (res.data.length == 0) {
                layer.msg('设备匹配不成功！', {
                    icon: 5,
                    time: 2000
                });
                return false;
            } else {
                DevID = res.data[0].DevId
            }
        }
        if (DevID) {
            //主从机 或许存在
            let paramCommand = {
                token: token,
                DevID: DevID,
                Command: Command,
                RecvData: '',
                SendState: '0'
            }
            //先查询是否存在已发送未返回命令
            list.info('GetDeviceCommandSendListKey', {
                token: token,
                DevID: DevID,
                search: Command,
                SendState: '0'
            }, function (respo) {
                if (respo.data.info.length == 0) {
                    //主从机是否存在
                    list.info('GetDeviceDetailKey', {
                        id: DevID,
                        token: token
                    }, function (res) {
                        let onlycode = res.data.OnlyCode;
                        let ServerCode = res.data.ServerCode;
                        if (onlycode) {
                            paramCommand.RecvData = '已发送';
                            paramCommand.SendState = '1';
                            list.info('GetServerListKey', {
                                token: token,
                                ComboBox: 'all'
                            }, function (ress) {
                                let serveripindex = ress.data.findIndex(v => v.ServerCode === ServerCode)
                                let sendip = ress.data[serveripindex]['ServerIP']
                                const url = 'http://' + sendip + ':' + ajaxPort + '/CreateDeviceCommandSendKey';
                                const data = {
                                    'DevID': onlycode,
                                    'Command': Command
                                };
                                $.post(url, data, function (r) { })
                            })
                        }
                        list.info('CreateDeviceCommandSendKey', paramCommand, function () {
                            if (!notips) {
                                layer.msg('已发送', {
                                    icon: 6,
                                    time: 1000
                                })
                            }
                        })
                    })
                    returnRes = true;
                } else {
                    console.log(DevID + '-' + Command + '存在未发送指令!');
                    returnRes = false;
                }
            })
        } else {
            layer.msg('设备匹配失败！', {
                icon: 5,
                time: 2000
            });
            returnRes = false;
        }
    })
}
//机柜U位定位器 发送控制命令 
// 蓝色常亮是上架，红色常亮是异常下架，蓝闪是预上架，红闪是预下架，灭灯是未使用
// 0=蓝色，1 =红色，2=蓝闪，3=红闪，4=灭灯
// 控制命令 01 060001 04 02 5B0B 
// 由于现场资产条是反着装的，04 应现场实际39U  42-4=38+1
// 01 对应GatewayId 设备id 的串联地址 
//
function setUposStatus(GatewayId, u, setval, devAddress, CabinetId, notips) {
    console.log('GatewayId', GatewayId)
    console.log('u', u)
    console.log('setval', setval)
    console.log('devAddress', devAddress)
    console.log('CabinetId', CabinetId)
    console.log('notips', notips)

    let DevID = GatewayId;
    if (DevID) {

        let DevAddress = parseInt(devAddress);
        let uindex = 42 - parseInt(u) + 1;
        let commandLed;
        switch (setval) {
            case '0': commandLed = 6; break;
            case '1': commandLed = 1; break;
            case '2': commandLed = 13; break;
            case '3': commandLed = 8; break;
            case '4': commandLed = 0; break;
        }

        if (!DevAddress || !uindex || !commandLed) {
            return false
        }

        //先查询是否存在待发送的命令
        let CommandS = DevAddress.toString(16).padStart(2, '0').toUpperCase() + '060001' + uindex.toString(16).padStart(2, '0').toUpperCase() + commandLed.toString(16).padStart(2, '0').toUpperCase();
        let Command = CommandS + hexStringToCRC(CommandS);

        //主从机 或许存在 这个要考虑
        let paramCommand = {
            token: token,
            DevID: DevID,
            Command: Command,
            RecvData: '',
            SendState: '0'
        }
        // console.log(paramCommand)
        // return;
        //先查询是否存在已发送未返回命令
        list.info('GetDeviceCommandSendListKey', {
            token: token,
            DevID: DevID,
            search: Command,
            SendState: '0'
        }, function (respo) {
            if (respo.data.info.length == 0) {
                //改变U位状态
                list.info('ChangeUDevStatus', {
                    CabinetId: CabinetId,
                    token: token,
                    ULocation: u,
                    UdeviceStatus: setval
                }, function (res) {

                })
                //主从机是否存在
                list.info('GetDeviceDetailKey', {
                    id: DevID,
                    token: token
                }, function (res) {
                    let onlycode = res.data.OnlyCode;
                    let ServerCode = res.data.ServerCode;
                    if (onlycode) {
                        paramCommand.RecvData = '已发送';
                        paramCommand.SendState = '1';
                        list.info('GetServerListKey', {
                            token: token,
                            ComboBox: 'all'
                        }, function (ress) {
                            let serveripindex = ress.data.findIndex(v => v.ServerCode === ServerCode)
                            let sendip = ress.data[serveripindex]['ServerIP']
                            const url = 'http://' + sendip + ':' + ajaxPort + '/CreateDeviceCommandSendKey';
                            const data = {
                                'DevID': onlycode,
                                'Command': Command
                            };
                            $.post(url, data, function (r) { })
                        })
                    }
                    list.info('CreateDeviceCommandSendKey', paramCommand, function () {
                        if (!notips) {
                            layer.msg('已发送', {
                                icon: 6,
                                time: 1000
                            })
                        }
                    })
                })
                returnRes = true;
            } else {
                console.log(DevID + '-' + Command + '存在未发送指令!');
                returnRes = false;
            }
        })
    }
}

function calculateCRC16Modbus(data) {
    let crc = 0xFFFF;
    for (let i = 0; i < data.length; i++) {
        crc ^= data.charCodeAt(i);
        for (let j = 0; j < 8; j++) {
            if (crc & 0x0001) {
                crc = (crc >> 1) ^ 0xA001;
            } else {
                crc = crc >> 1;
            }
        }
    }
    return crc;
}

function hexStringToCRC(input) {
    // 将16进制字符串转换为字节数组
    let bytes = [];
    for (let i = 0; i < input.length; i += 2) {
        bytes.push(parseInt(input.substr(i, 2), 16));
    }

    // 计算CRC
    const crc = calculateCRC16Modbus(String.fromCharCode(...bytes));

    // 转换为16进制字符串并大写
    const crcHex = crc.toString(16).toUpperCase().padStart(4, '0');

    // 交换高低字节 (Modbus格式)
    return crcHex.substr(2, 2) + crcHex.substr(0, 2);
}

// const input = "010600010301";
// const crcResult = hexStringToCRC(input); // 应该输出 "193A"