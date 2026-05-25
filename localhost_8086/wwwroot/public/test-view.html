function dealCancelNotify(r, Notify ,tips) {
    // //获取全局告警取消配置
    // list.info('GetAlarmParamKey', {
    //     id: 1,
    //     token: token
    // }, function (result) {
    //     if (result.data.CancelNotify == 1) {
    //取得当前告警设备信息
    let devinfo, notifyMode, alarmtype = r.data.AlarmType;
    list.info("GetDeviceDetailKey", {
        token: token,
        id: r.data.DevId
    }, function (re) {
        devinfo = re.data
        //取得告警规则
        if (alarmtype == '5' || alarmtype == '6') {
            list.info("GetAlarmTypeListKey", {
                token: token,
                DevId: r.data.DevId,
                AlarmType: alarmtype
            }, function (re) {
                if (re.data.info.length > 1) {
                    lyayer.msg('告警规则匹配错误', {
                        icon: '5',
                        time: 2000
                    })
                    return false;
                }
                notifyMode = re.data.info[0]
                dealNotifyMode(notifyMode, alarmtype, r.data.TextMessage, devinfo.AreaId, tips,Notify)
            })
        } else {
            list.info("GetAlarmTypeDetailKey", {
                token: token,
                id: r.data.NotifyModeID
            }, function (re) {
                notifyMode = re.data
                dealNotifyMode(notifyMode, alarmtype, r.data.TextMessage, devinfo.AreaId, tips,Notify)
            })
        }
    })

    //     }
    // })
}
//根据通知模型处理数据
function dealNotifyMode(notifyMode, alarmtype, TextMessage, AreaId, tips,Notify) {
    // console.log(tips)
    //取得告警参数
    let phoneNotify = 0;
    let smsNotify = 0;
    let weixinNotify = 0;
    let weicomNotify = 0;
    let dingdingNotify = 0;
    let emailNotify = 0;
    let noiseNotify = 0;

    if(Notify){
        if(Notify.indexOf('1')>-1) phoneNotify = 1;
        if(Notify.indexOf('2')>-1) smsNotify = 1;
        if(Notify.indexOf('3')>-1) weixinNotify = 1;
        if(Notify.indexOf('4')>-1) weicomNotify = 1;
        if(Notify.indexOf('5')>-1) dingdingNotify = 1;
        if(Notify.indexOf('6')>-1) emailNotify = 1;
        if(Notify.indexOf('7')>-1) noiseNotify = 1;
    }else{
        phoneNotify = notifyMode.PhoneNotify;
        smsNotify = notifyMode.SMSNotify;
        weixinNotify = notifyMode.WeixinNotify;
        weicomNotify = notifyMode.WeComNotify;
        dingdingNotify = notifyMode.DingdingNotify;
        emailNotify = notifyMode.EmailNotify;
        noiseNotify = notifyMode.noiseNotify;
    }

    //取得通知对象
    let userID = notifyMode.UserID
    // console.log(userID)

    if (!userID) {
        if (tips) {
            backToparent("告警已确认！！");
        }
        return false
    }

    

    //取得通知用户信息, 多个用户ID以逗号分隔
    // userID = userID.split(',')
    let arrtxtmsg = TextMessage.split(",")
    // let NotifyContent = arrtxtmsg[0] + ',' + arrtxtmsg[1] + ',' + arrtxtmsg[2] + '已手动确认' + ',,' + getDateTime()
    let NotifyContent = arrtxtmsg[0] + ',' + arrtxtmsg[1] + ',' + arrtxtmsg[2] + ',' + arrtxtmsg[3] + ',' + GetCurMdhms()+',已手动确认'
    // $.each(userID, function (n, val) {
        list.info("GetPersonByGroupKey", {
            token: token,
            groupId: userID
        }, function (re) {
            let usrs = re.data;
            if(usrs.length>0){
                $.each(usrs, function (n, usr) {
                    if (parseInt(phoneNotify) == 1 && usr.PersonPhone) {//电话告警
                        setAlarmNotifyList(usr.id, usr.PersonPhone, '1', alarmtype, NotifyContent, AreaId)
                    }
                    if (parseInt(smsNotify) == 1 && usr.PersonPhone) {//短信告警
                        setAlarmNotifyList(usr.id, usr.PersonPhone, '2', alarmtype, NotifyContent, AreaId)
                    }
                    if (parseInt(weixinNotify) == 1 && usr.PersonPhone) {//微信告警
                        setAlarmNotifyList(usr.id, usr.PersonPhone, '3', alarmtype, NotifyContent, AreaId)
                    }
                    if (parseInt(weicomNotify) == 1 && usr.WeicomUrl) {//企业微信告警
                        setAlarmNotifyList(usr.id, usr.WeicomUrl, '4', alarmtype, NotifyContent, AreaId)
                    }
                    if (parseInt(dingdingNotify) == 1 && usr.DingdingUrl) {//钉钉告警
                        setAlarmNotifyList(usr.id, usr.DingdingUrl, '5', alarmtype, NotifyContent, AreaId)
                    }
                    if (parseInt(emailNotify) == 1 && usr.email) {//邮件告警
                        setAlarmNotifyList(usr.id, usr.email, '6', alarmtype, NotifyContent, AreaId)
                    }
    
                    if(n==usrs.length-1 && tips){
                        backToparent("告警已确认！！");
                    }
                })
            }
        })
    // })
}
//增加通知消息
function setAlarmNotifyList(NotifyUserID, NotifyAddr, NotifyType, AlarmType, NotifyContent, AreaId) {
    var strformData = new FormData();
    strformData.append("token", token);
    strformData.append("NotifyUserID", NotifyUserID);
    strformData.append("NotifyAddr", NotifyAddr);
    strformData.append("NotifyType", NotifyType);
    strformData.append("AlarmType", AlarmType);
    strformData.append("NotifyContent", NotifyContent);
    strformData.append("AreaId", AreaId);
    list.form("CreateAlarmNotifyKey", strformData, function (r) { });
}
//获取当前时间的月日时分秒
function GetCurMdhms(){
    var time = new Date();
	var y = time.getFullYear();
	var m = time.getMonth() + 1;
	m = m < 10 ? ("0" + m) : m;
	var d = time.getDate();
	d = d < 10 ? ("0" + d) : d;
	var h = time.getHours();
	h = h < 10 ? ("0" + h) : h;
	var minute = time.getMinutes();
	minute = minute < 10 ? ("0" + minute) : minute;
	var second = time.getSeconds();
	second = second < 10 ? ("0" + second) : second;
	return m + "月" + d + "日 " + h + ":" + minute + ":" + second;
}
