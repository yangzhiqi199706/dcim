/**
 * Created by Administrator on 2019/08/02.
 */
// alert(window.location.hostname)
// console.log(window.location.origin);


let ajaxUrl = window.location.origin + "/";
let imgUrl = window.location.origin + "/";
let ajaxPort = window.location.port;

const videoUrl = window.location.protocol + '//' + window.location.hostname + ":18080/";
var videoToken = localStorage.getItem("videoToken") || null;
var videoTokenRefreshing = false;
var videoTokenPending = [];
var videoTokenLastFailAt = 0;
var videoTokenFailCooldownMs = 10000;
var videoTokenRequestTimeoutMs = 3000;
var token = localStorage.getItem("token") || null;      //是否登录
var userName = localStorage.getItem("name") || "管理员";
var userId = localStorage.getItem("userId") || "";
var dm = localStorage.getItem("dm") || -1;
var isRedirectingToLogin = false;


/*列表数据*/
var pageNo = 1;
var pageSize = 15;
//列表请求参数
var param = {
	token: token,
	pageNo: pageNo,
	pageSize: pageSize
};

// 全站统一重试策略（允许在页面上通过 window.__ajaxRetryPolicy 覆盖）
var ajaxRetryPolicy = $.extend({
	maxRetries: 2,
	baseDelay: 400,
	maxDelay: 2000,
	retry429: true
}, window.__ajaxRetryPolicy || {});
window.__ajaxRetryPolicy = ajaxRetryPolicy;

function getAjaxRetryDelay(retryIndex, retryConfig) {
	var delay = retryConfig.baseDelay * retryIndex;
	return delay > retryConfig.maxDelay ? retryConfig.maxDelay : delay;
}

function shouldRetryAjax(xhr, textStatus, retryIndex, retryConfig) {
	if (retryIndex > retryConfig.maxRetries) return false;
	if (textStatus === "abort") return false;
	if (textStatus === "timeout") return true;
	var status = xhr ? xhr.status : 0;
	if (status === 0 || status === 408) return true;
	if (retryConfig.retry429 && status === 429) return true;
	if (status >= 500 && status < 600) return true;
	return false;
}

function ajaxWithRetry(options, retryOptions) {
	var retryConfig = $.extend({}, ajaxRetryPolicy, retryOptions || {});
	var retryIndex = 0;
	var deferred = $.Deferred();
	function sendRequest() {
		var userSuccess = options.success;
		var userError = options.error;
		var userComplete = options.complete;
		var ajaxOptions = $.extend({}, options);
		var retryScheduled = false;
		ajaxOptions.success = function (data, textStatus, xhr) {
			if (typeof userSuccess === "function") {
				userSuccess(data, textStatus, xhr);
			}
			deferred.resolve(data, textStatus, xhr);
		};
		ajaxOptions.error = function (xhr, textStatus, errorThrown) {
			retryIndex += 1;
			if (shouldRetryAjax(xhr, textStatus, retryIndex, retryConfig)) {
				retryScheduled = true;
				setTimeout(sendRequest, getAjaxRetryDelay(retryIndex, retryConfig));
				return;
			}
			if (typeof userError === "function") {
				userError(xhr, textStatus, errorThrown);
			}
			deferred.reject(xhr, textStatus, errorThrown);
		};
		ajaxOptions.complete = function (xhr, textStatus) {
			if (retryScheduled) return;
			if (typeof userComplete === "function") {
				userComplete(xhr, textStatus);
			}
		};
		$.ajax(ajaxOptions);
	}
	sendRequest();
	return deferred.promise();
}

var list = {
	/*
	 获取列表数据：
	 url     请求的url
	 param   传递参数
	 dealdata  数据处理
	 */
	get: function (url, param, dealdata, callback) {
		ajaxWithRetry({
			type: "post", //POST：向指定资源提交数据，请求服务器进行处理（例如提交表单或者上传文件）GET：向指定的资源发出“显示”请求。
			url: ajaxUrl + url,
			data: param,
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					//判断是否为初始化
					let psize = Math.ceil(r.data.page.total / r.data.page.p_n);
					if (r.data.page.p == 1) {
						x_admin_page(r.data.page.total, psize, pagechange);
					}
					if (dealdata) {
						dealdata(r);
					}
					r.data.pageSize = psize;
					r.data.pageNo = pageNo;
					var lists = template("listtemplate", r);
					$("tbody:not('.chooseTable')").html(lists);
					if (r.data.page.total) {
						$("#total-right").html("共有数据:" + r.data.pageTotal + "条");
					} else {
						$("#total-right").html("");
					}
					if (callback) callback(r);

					//选中行变色
					// $('.layui-table tbody>tr').click(function(){
					// 	$(this).css("background-color", "#fff!important");
					// })
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			}
		});
	},
	getobj: function (url, param, obj, pagechange, dealdata, callback) {
		ajaxWithRetry({
			type: "post",
			url: ajaxUrl + url,
			data: param,
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					//判断是否为初始化
					let psize = Math.ceil(r.data.page.total / r.data.page.p_n);
					if (r.data.page.p == 1) {
						x_admin_pageobj(obj.pageelem, r.data.page.total, psize, pagechange);
					}
					if (dealdata) {
						dealdata(r);
					}
					r.data.pageSize = psize;
					r.data.pageNo = pageNo;
					var lists = template(obj.htmltpl, r);
					$(obj.htmlobj).html(lists);
					if (r.data.page.total) {
						$(obj.total).html("共有数据:" + r.data.pageTotal + "条");
					} else {
						$(obj.total).html("");
					}
					if (callback) callback(r);
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			}
		});
	},
	/*
	 列表数据删除：
	 delurl     请求的url
	 delparam   传递参数
	 obj   操作对象
	 */
	del: function (delurl, delparam, obj) {
		ajaxWithRetry({
			type: "post",
			url: ajaxUrl + delurl,
			async: true,
			data: delparam,
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					if (typeof obj === "function") {
						obj();
					} else {
						$(obj).parents("tr").remove();
						layer.msg("已删除!", {
							icon: 1,
							time: 1000
						});
					}
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			}
		});
	},
	/*
	 列表数据状态变更：
	 changeurl     请求的url
	 changeparam   传递参数
	 changedo   成功回调
	 */
	change: function (changeurl, changeparam, changedo) {
		ajaxWithRetry({
			type: "post",
			url: ajaxUrl + changeurl,
			async: true,
			data: changeparam,
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					changedo(r);
					layer.msg("操作成功", {
						icon: 1,
						time: 1000
					});
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			}
		});
	},
	/*
	 数据详情或非获取列表数据或提交数据：
	 infourl     请求的url
	 infoparam   传递参数
	 infodo   成功回调
	 */
	info: function (infourl, infoparam, infodo) {
		ajaxWithRetry({
			type: "post",
			url: ajaxUrl + infourl,
			data: infoparam,
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					infodo(r);
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			}
		});
	},
	/*
	 表单数据格式提交：
	 formurl     请求的url
	 formparam   传递参数
	 formdo   成功回调
	 */
	form: function (formurl, formparam, formdo) {
		ajaxWithRetry({
			type: "post",
			async: true,
			cache: false,
			contentType: false,
			processData: false,
			url: ajaxUrl + formurl,
			data: formparam,
			beforeSend: function () {
				$(".wait").show();
			},
			success: function (r) {
				r = JSON.parse(r);
				if (r.code == 100) {
					formdo(r);
				} else if (r.code == 300) {
					tologin();
				} else {
					layer.msg(r.msg, {
						icon: 5,
						time: 1000
					});
				}
			},
			complete: function () {
				$(".wait").hide();
			}
		});
	}
};
//跳转登录
function tologin() {
	if (isRedirectingToLogin) return;
	isRedirectingToLogin = true;
	layui.use("layer", function () {
		var layer = layui.layer;
		layer.msg("登录失效，请重新登录", {
			icon: 5,
			time: 1000
		}, function () {
			localStorage.removeItem('token');
			window.top.location = "welcome.html";
		});
	});
}

// 统一处理接口401，视频服务接口除外（视频服务需要先尝试刷新token）
$(document).ajaxError(function (event, xhr, settings) {
	if (!xhr || xhr.status !== 401) return;
	if (settings && settings.url && settings.url.indexOf(videoUrl) === 0) return;
	tologin();
});
/*数据分页处理*/
/*
 参数解释：
 total   总页数
 pagechange 分页变化回调
*/
function x_admin_page(total, pageSize, pagechange) {
	layui.use("laypage", function () {
		var laypage = layui.laypage;
		//执行一个laypage实例
		laypage.render({
			elem: "bottomPaging",
			count: total,
			limit: pageSize,
			jump: function (obj, first) {
				//首次不执行
				if (!first) {
					pagechange(obj.curr);
				}
			}
		});
	});
}
function x_admin_pageobj(elem, total, pageSize, pagechange) {
	layui.use("laypage", function () {
		var laypage = layui.laypage;
		//执行一个laypage实例
		laypage.render({
			elem: elem,
			count: total,
			limit: pageSize,
			jump: function (obj, first) {
				//首次不执行
				if (!first) {
					pagechange(obj.curr);
				}
			}
		});
	});
}
//数据删除
function x_admin_del(tips, url, id, obj) {
	layer.confirm(tips, function (index) {
		list.del(url, {
			token: token,
			id: id
		}, obj)
		layer.close(index);
	});
}
//批量处理数据
function x_admin_dealall(callback) {
	var dealid = [], dealparam = [], parparam = [];
	$.each($(".layui-form input[name=id]"), function (n, val) {
		if (val.checked == true) {
			dealid.push($(val).val());
			if ($(val).attr('data-param')) {
				dealparam.push($(val).attr('data-param'))//判断是否同类型
			}
			if ($(val).attr('data-parent')) {
				parparam.push($(val).attr('data-parent'))//判断类型父级
			}
		};
		if (n == $(".layui-form input[name=id]").length - 1) {
			if (dealid.length == 0) {
				layer.msg("请选择需要处理的数据！", {
					icon: 5,
					time: 2000
				});
				return false;
			}
			layer.confirm('是否批量处理这些数据？', function (index) {
				layer.close(index);
				callback(dealid, dealparam, parparam);
			});
		}
	});
}
//数据提示
function x_admin_tips(tips, url, id, obj, callback) {
	layer.confirm(tips, function (index) {
		list.info(url, {
			token: token,
			id: id
		}, function (r) {
			if (callback) callback(r, obj);
			layer.close(index);
		})
	});
}
//时间戳转换
function formatDateTime(data) {
	var time = new Date(data);
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
	return y + "-" + m + "-" + d + " " + h + ":" + minute + ":" + second;
}
//当前时间
function getDateTime(timeStr, type) {
	var time = timeStr ? timeStr : new Date();
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
	if (type) {
		if (type == 'cn') {
			return y + "年" + m + "月" + d + "日" + h + "时" + minute + "分" + second + "秒";
		} else {
			return y + "-" + m + "-" + d + " " + h + ":" + minute + ":" + second;
		}
	} else {
		return y + "-" + m + "-" + d + " " + h + ":" + minute + ":" + second;
	}
}
//当前星期
function getWeek() {
	var time = new Date();
	var today = new Array('星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六');
	var week = today[time.getDay()];
	return week;
}
//获取一个月的数据
function getOnemonth(datamonth) {
	let start = datamonth + '-01 00:00:00';
	let endday = '31';
	let year = datamonth.split('-')[0];
	let month = datamonth.split('-')[1];
	switch (month) {
		case '04':
		case '06':
		case '09':
		case '11': endday = 30; break;
		case '02':
			if (year % 4 == 0) {
				if (year % 100 == 0) {
					endday = year % 400 == 0 ? 29 : 28;
				} else {
					endday = 29;
				}
			} else {
				endday = 28;
			}
			break;
	}
	let end = datamonth + '-' + endday + ' 23:59:59';
	return [start, end];
}

//关闭iframe层返回父级页
//tips  提示语
function backToparent(tips) {
	layer.msg(tips, {
		icon: 6,
		time: 1000
	}, function () {
		var index = parent.layer.getFrameIndex(window.name);
		parent.layer.close(index);
		if (window.parent.changecallbak) {
			window.parent.changecallbak();
		} else {
			window.parent.location.reload();
		}
	});
}

//获取地址栏参数
function GetString(name) {
	var reg = new RegExp("(^|&)" + name + "=([^&]*)(&|$)");
	var r = window.location.search.substr(1).match(reg);
	if (r != null) return unescape(r[2]);
	return "";
}

//数据全选\单选
function checkall(form) {
	form.render();
	form.on("checkbox(checkall)", function (data) {
		if (data.elem.checked == true) {
			$("input[name=id]").prop("checked", true).parents('tr').addClass('tr-bg');
			form.render('checkbox');
		} else {
			$("input[name=id]").prop("checked", false).parents('tr').removeClass('tr-bg');
			form.render('checkbox');
		}
	});
	form.on('checkbox(checkone)', function (data) {
		if (data.elem.checked == true) {
			$(data.elem).parents('tr').addClass('tr-bg');
		} else {
			$(data.elem).parents('tr').removeClass('tr-bg');
		}
		var item = $("input[name=id]");
		var all = item.length;
		$.each(item, function (n) {
			if (item[n].checked == false) {
				$("#checkall").prop("checked", false);
				form.render('checkbox');
			} else {
				all--;
				if (all == 0) {
					$("#checkall").prop("checked", true);
					form.render('checkbox');
				}
			}
		});

	});
}

//下拉选中
//id 操作对象
//str 判断中值
function layuiSelected(id, str) {
	//1、设置select的值
	$("#" + id).attr("value", str);
	//2、1把select下的option的selected换成现在的
	$("#" + id).children("option").each(function () {
		if ($(this).text() == str) {
			$(this).attr("selected", "selected");
		} else {
			if ($(this).attr("selected") == "selected") {
				$(this).removeAttr("selected");
			}
		}
	});
	//3、首先设置输框
	$("#" + id).siblings("div[class='layui-unselect layui-form-select']").children("div[class='layui-select-title']").children("input").val(str);
	//4、其次，设置dl下的dd
	$("#" + id).siblings("div[class='layui-unselect layui-form-select']").children("dl").children("dd").each(function () {
		if ($(this).text() == str) {
			if (!$(this).hasClass("layui-this")) {
				$(this).addClass("layui-this");
				$(this).click();
			}
			return true;
		} else {
			if ($(this).hasClass("layui-this")) {
				$(this).removeClass("layui-this");
			}
		}
	});
}
//上传图片
// parameter 类型 jpeg png  parameter不存在默认就是png
function uploadImg(obj, parameter) {
	var file = obj.files[0];
	var r = new FileReader();
	r.readAsDataURL(file);
	$(obj).parent().prev().find("#FileImg").attr("value", file.name);
	$(r).load(function () {
		dealImage(this.result, {
			width: 500,
			type: parameter ? parameter : 'png'
		}, function (base) {
			// if (!parameter || parameter == '' || parameter == 'undefined') {
			$(obj).attr("value", base);
			changImg(base, obj);//请求服务器上传base64图片
			// }else{
			// 	$(obj).parent().prev().find("#BaseImg").val(base);
			// 	$(obj).parent().next().attr('href', base).find('img').attr('src', base);
			// 	$(obj).parent().siblings().show();
			// 	//预览图片
			// 	fnFancyBox('.fancybox-view');
			// }
		});
	});
}
//上传图片到后台
function changImg(base, obj) {
	ajaxWithRetry({
		type: "post",
		url: ajaxUrl + "UpLoadPictureKey",
		data: {
			token: token,
			img: base
		},
		beforeSend: function () {
			$(".wait").show();
		},
		success: function (r) {
			r = JSON.parse(r);
			if (r.code == 100) {
				layer.msg("上传成功", {
					icon: 1,
					time: 1000
				});
				$(obj).parent().prev().find("#BaseImg").val(r.data.url);
				$(obj).parent().next().attr('href', imgUrl + r.data.url).find('img').attr('src', imgUrl + r.data.url);
				$(obj).parent().siblings().show();
				//预览图片
				fnFancyBox('.fancybox-view');
			} else if (r.code == 300) {
				tologin();
			} else {
				layer.msg(r.msg, {
					icon: 5,
					time: 1000
				});
			}
		},
		complete: function () {
			$(".wait").hide();
		}
	});
}
//清除选中图片
function CleanImg(obj) {
	$(obj).hide();
	$(obj).prev().attr('href', '').hide().find('img').attr('src', '');
	$(obj).siblings().find('input').attr('value', '');
}
//压缩图片
function dealImage(path, obj, callback) {
	var img = new Image();
	img.src = path;
	img.onload = function () {
		var that = this;
		// 默认按比例压缩
		var w = that.width,
			h = that.height,
			scale = w / h;
		w = obj.width || w;
		h = obj.height || (w / scale);
		var quality = 0.7;  // 默认图片质量为0.7
		//生成canvas
		var canvas = document.createElement("canvas");
		var ctx = canvas.getContext("2d");
		// 创建属性节点
		var anw = document.createAttribute("width");
		anw.nodeValue = w;
		var anh = document.createAttribute("height");
		anh.nodeValue = h;
		canvas.setAttributeNode(anw);
		canvas.setAttributeNode(anh);
		ctx.drawImage(that, 0, 0, w, h);
		// 图像质量
		if (obj.quality && obj.quality <= 1 && obj.quality > 0) {
			quality = obj.quality;
		}
		// quality值越小，所绘制出的图像越模糊
		var base64 = canvas.toDataURL("image/" + obj.type, quality);
		// 回调函数返回base64的值
		callback(base64);
	};
}

//服务器
function getServer(form, callback) {
	param.ComboBox = "all";
	list.info("GetServerListKey", param, function (res) {
		var serverhtml = "<option value=''>请选择服务器</option>";
		$.each(res.data, function (n, val) {
			serverhtml += "<option value=\"" + val.id + "\">" + val.ServerName + "</option>";
		});
		$("#ServerCode").html(serverhtml);
		form.render("select");
		if (callback) callback(res);
	});
}

//地区
function getArea(form, data, callback) {
	param.ServerCode = data.value;
	param.AreaId = '';
	param.ComboBox = "all";
	$("#AreaId").html('<option value="">请选择区域</option>');
	list.info("GetAreaListKey", param, function (res) {
		var areahtml = "";
		$.each(res.data, function (n, val) {
			areahtml += "<option value=\"" + val.id + "\">" + val.AreaName + "</option>";
		});
		$("#AreaId").append(areahtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//部门
function getDept(form, data, callback) {
	param.AreaId = data.value;
	param.DeptId = '';
	param.ComboBox = "all";
	$("#DeptId").html('<option value="">请选择部门</option>');
	list.info("GetDeptListKey", param, function (res) {
		var Depthtml = "";
		$.each(res.data, function (n, val) {
			Depthtml += "<option value=\"" + val.id + "\">" + val.DeptName + "</option>";
		});
		$("#DeptId").append(Depthtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//人员
function getEmp(form, data, callback) {
	param.DeptId = data.value;
	param.EmpId = '';
	param.ComboBox = "all";
	$("#EmpId").html('<option value="">请选择人员</option>');
	list.info("GetEmpListKey", param, function (res) {
		var Emphtml = "";
		$.each(res.data, function (n, val) {
			Emphtml += "<option value=\"" + val.id + "\">" + val.PersonName + "</option>";
		});
		$("#EmpId").append(Emphtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//机柜列
function getColumn(form, data, callback) {
	param.AreaId = data.value;
	param.column = '';
	param.ComboBox = "all";
	$("#column").html('<option value="">请选择机柜列</option>');
	list.info("GetArrangeKey", param, function (res) {
		var columnhtml = "";
		$.each(res.data, function (n, val) {
			columnhtml += "<option value=\"" + val.column + "\">" + val.column + "</option>";
		});
		$("#column").append(columnhtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//机柜
function getCabinet(form, data, callback) {
	param.column = data.value;
	param.position = '';
	param.ComboBox = "all";
	$("#CabinetId").html('<option value="">请选择机柜</option>');
	list.info("GetCabinetListKey", param, function (res) {
		var cabinethtml = "";
		$.each(res.data, function (n, val) {
			cabinethtml += "<option value=\"" + val.id + "\">" + val.position + "</option>";
		});
		$("#CabinetId").append(cabinethtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//获取绑定了资产的机柜
function getAssetCabinet(form, data, callback) {
	param.column = data.value;
	param.position = '';
	param.ComboBox = "all";
	$("#CabinetId").html('<option value="">请选择机柜</option>');
	list.info("GetAssetCabinetListKey", param, function (res) {
		var cabinethtml = "";
		$.each(res.data, function (n, val) {
			cabinethtml += "<option value=\"" + val.id + "\">" + val.position + "</option>";
		});
		$("#CabinetId").append(cabinethtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//仓库、
function getStoreLocation(form, data, callback) {
	if (data) param.ServerCode = data.value;
	param.StoreLocationId = '';
	param.ComboBox = 'all';
	$("#StoreLocationId").html('<option value="">请选择仓库位置</option>');
	list.info("GetStoreLocationListKey", param, function (res) {
		var storehtml = "";
		$.each(res.data, function (n, val) {
			storehtml += "<option value=\"" + val.id + "\">" + val.StoreLocationName + "</option>";
		});
		$("#StoreLocationId").append(storehtml);
		form.render("select");
		if (callback) callback(res);
	});
}
//供应商、
function getSupplier(form, callback) {
	param.ComboBox = 'all';
	$("#SupplierId").html('<option value="">请选择供应商</option>');
	list.info('GetSupplierListKey', param, function (res) {
		var supplierhtml = "";
		$.each(res.data, function (n, val) {
			supplierhtml += "<option value=\"" + val.id + "\">" + val.SupplierName + "</option>";
		});
		$("#SupplierId").append(supplierhtml);
		form.render("select");
		if (callback) callback(res);
	});
}

var videolist = {
	/*
	 获取列表数据：
	 url     请求的url
	 param   传递参数
	 dealdata  数据处理
	 */
	get: function (type, url, param, dealdata, callback, error) {
		var doRequest = function () {
			ajaxWithRetry({
				type: type,
				url: videoUrl + url,
				data: param,
				headers: {
					'access-token': videoToken
				},
				success: function (r) {
					//判断是否为初始化
					if (r.pages == 1) {
						x_admin_page(r.total, pagechange);
					}
					if (dealdata) {
						dealdata(r);
					}
					var lists = template("listtemplate", r);
					$("tbody:not('#chooseTable')").html(lists);
					if (r.total) {
						$("#total-right").html("共有数据:" + r.total + "条");
					} else {
						$("#total-right").html("");
					}
					if (callback) callback(r);
				},
				error: function (xhr, textStatus, errorThrown) {
					if (error) error(xhr, textStatus, errorThrown);
				}
			});
		};
		ensureVideoToken(doRequest, error);
	},
	/*
	 列表数据删除：
	 delurl     请求的url
	 delparam   传递参数
	 obj   操作对象
	 */
	del: function (delurl, delparam, obj, error) {
		var doRequest = function () {
			ajaxWithRetry({
				type: "get",
				url: videoUrl + delurl,
				async: true,
				data: delparam,
				headers: {
					'access-token': videoToken
				},
				success: function (r) {
					if (typeof obj === "function") {
						obj();
					} else {
						$(obj).parents("tr").remove();
						layer.msg("操作成功!", {
							icon: 1,
							time: 1000
						});
					}
				},
				error: function (xhr, textStatus, errorThrown) {
					if (error) error(xhr, textStatus, errorThrown);
				}
			});
		};
		ensureVideoToken(doRequest, error);
	},
	/*
	 数据详情或非获取列表数据或提交数据：
	 infourl     请求的url
	 infoparam   传递参数
	 infodo   成功回调
	 */
	info: function (type, infourl, infoparam, infodo, error, retryCount) {
		try {
			var doRequest = function (refreshRetryCount) {
				ajaxWithRetry({
					type: type,
					url: videoUrl + infourl,
					data: infoparam,
					headers: {
						'access-token': videoToken
					},
					success: function (r) {
						if (typeof infodo === "function") {
							infodo(r);
						}
					},
					error: function (xhr, textStatus, errorThrown) {
						var responseCode = xhr && xhr.responseJSON ? xhr.responseJSON.code : null;
						var isTokenExpired = xhr && xhr.status === 401 || responseCode == '401';
						if (isTokenExpired) {
							var currentRetry = refreshRetryCount || 0;
							if (currentRetry >= 1) {
								if (error) error(xhr, textStatus, errorThrown);
								return;
							}
							getVideoToken(function () {
								doRequest(currentRetry + 1);
							}, function () {
								if (error) error(xhr, textStatus, errorThrown);
							}, true);
							return;
						}
						if (error) error(xhr, textStatus, errorThrown);
					}
				});
			};
			ensureVideoToken(function () {
				doRequest(retryCount || 0);
			}, error);
		} catch (e) {
			console.error('请及时修改视频配置文件！');
		}
	}
};

function ensureVideoToken(success, fail) {
	if (videoToken) {
		if (typeof success === 'function') success();
		return;
	}
	getVideoToken(function (r) {
		if (typeof success === 'function') success(r);
	}, function (err) {
		if (typeof fail === 'function') fail(err);
	});
}

//获取video token
function getVideoToken(success, fail, forceRefresh) {
	if (!forceRefresh && !videoToken && videoTokenLastFailAt) {
		var now = Date.now();
		if (now - videoTokenLastFailAt < videoTokenFailCooldownMs) {
			if (typeof fail === 'function') {
				fail({ code: 'TOKEN_COOLDOWN', msg: 'video token cooldown' });
			}
			return;
		}
	}
	if (videoTokenRefreshing) {
		videoTokenPending.push({ success: success, fail: fail });
		return;
	}
	videoTokenRefreshing = true;
	ajaxWithRetry({
		type: 'get',
		url: videoUrl + 'api/user/login?username=admin&password=551c76780e34e1c1fab9ff85dfc79947',
		timeout: videoTokenRequestTimeoutMs,
		success: function (r) {
			if (r && r.code == '0' && r.data && r.data.accessToken) {
				videoToken = r.data.accessToken;
				videoTokenLastFailAt = 0;
				localStorage.setItem('videoToken', videoToken);
				if (typeof success === 'function') success(r);
				$.each(videoTokenPending, function (n, cb) {
					if (typeof cb.success === 'function') cb.success(r);
				});
			} else {
				videoTokenLastFailAt = Date.now();
				if (typeof fail === 'function') fail(r);
				$.each(videoTokenPending, function (n, cb) {
					if (typeof cb.fail === 'function') cb.fail(r);
				});
			}
		},
		error: function (xhr) {
			videoTokenLastFailAt = Date.now();
			if (typeof fail === 'function') fail(xhr);
			$.each(videoTokenPending, function (n, cb) {
				if (typeof cb.fail === 'function') cb.fail(xhr);
			});
		},
		complete: function () {
			videoTokenRefreshing = false;
			videoTokenPending = [];
		}
	}, {
		maxRetries: 0,
		retry429: false
	});
}
$(function () {
	//修改头部导航
	var txt = $('.layui-breadcrumb cite').html();
	if ($('.layui-breadcrumb a')) {
		var txt = $('.layui-breadcrumb cite').html() || $('.layui-breadcrumb a').html();
		if (txt) {
			var texarr = txt.split('-&gt;');
			var newtxt = '';
			$.each(texarr, function (n, val) {
				if (n == (texarr.length - 1)) {
					newtxt += `<span style="color:#FFA666">` + val + `</span>`;
				} else {
					newtxt += val + "&nbsp;>&nbsp;";
				}
			});
			$('.layui-breadcrumb a').html(newtxt);
		}
	}

})

//导出表格
function displayexport(btn, obj, name) {
	// $(document).on("click", "#" + btn, function () {//按钮点击事件
	var $trs = $('#' + obj).find("tr");//表格id元素
	var rows = [];

	function escapeCsvCell(value) {
		var text = value == null ? '' : String(value);
		// CSV 格式转义：包含逗号、双引号或换行时使用双引号包裹，并转义内部双引号
		if (/[",\r\n]/.test(text)) {
			text = '"' + text.replace(/"/g, '""') + '"';
		}
		return text;
	}

	for (var i = 0; i < $trs.length; i++) {
		var $tds = $trs.eq(i).find("td,th");
		var cells = [];
		for (var j = 0; j < $tds.length; j++) {
			cells.push(escapeCsvCell($tds.eq(j).text()));
		}
		rows.push(cells.join(","));
	}
	var csvContent = "\ufeff" + rows.join("\r\n");
	var blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
	var aaaa = URL.createObjectURL(blob);
	var link = document.createElement("a");
	link.setAttribute("href", aaaa);
	link.setAttribute("download", name + ".csv");//表名
	$('body').append(link);
	link.click();
	$(link).remove();
	URL.revokeObjectURL(aaaa);
	// });
}

//告警事件弹框
function RealeventAlarm() {
	layer.closeAll('iframe');
	layer.open({
		type: 2,
		area: ['560px', '320px'],
		offset: ['calc(100vh - 340px)', 'calc(100vw - 570px)'],
		fix: false, //不固定
		maxmin: true,
		shadeClose: true,
		shade: 0.4,
		title: `告警事件`,
		skin: 'eventsmallbox',
		content: 'system-param-eventsmall.html',
		success: function (layero, index) {
			layero.find('.layui-layer-max,.layui-layer-min').hide();
			$('.layui-layer-setwin', window.parent.document).append(`<span style="
					float: left;
					width: 110px;
					height: 30px;
					padding: 0;
					line-height: 30px;
					position:relative;
					top:-6px"
					class="layui-btn"
					id="viewAllEvent"
					onclick="xadmin.open('告警事件', 'system-param-event.html')">查看全部告警</span>`).find('#viewAllMassage').remove()
		}
	});

}

//消息弹框
function RealmassageAlarm() {
	layer.closeAll('iframe');
	layer.open({
		type: 2,
		area: ['560px', '320px'],
		offset: ['calc(100vh - 340px)', 'calc(100vw - 570px)'],
		fix: false, //不固定
		maxmin: true,
		shadeClose: true,
		shade: 0.4,
		title: `消息`,
		skin: 'eventsmallbox',
		content: 'system-param-massagesmall.html',
		success: function (layero, index) {
			layero.find('.layui-layer-max,.layui-layer-min').hide();
			$('.layui-layer-setwin', window.parent.document).append(`<span style="
					float: left;
					width: 110px;
					height: 30px;
					padding: 0;
					line-height: 30px;
					position:relative;
					top:-6px"
					class="layui-btn"
					id="viewAllMassage"
					onclick="xadmin.open('消息', 'system-param-massage.html')">查看全部消息</span>`).find('#viewAllEvent').remove()
		}
	});
}

//人员修改同步弹框
function PersonAlarm(SNstr) {
	let allSN = SNstr.split(',');
	console.log('修改同步sn', allSN);
	if (allSN.length <= 0) {
		return false;
	}
	try {
		allSN.forEach((id, index) => {
			// console.log(index);
			// console.log(sn);
			let sendparam = {
				token: token,
				DevId: id,
				changePower: 1
			};
			// 发送请求
			list.info("ChangeDoorParamKey", sendparam, function (r) {
				if (index === allSN.length - 1) {
					// 最后一个处理完成，弹出同步成功提示
					layer.msg('同步成功', {
						icon: 6,
						time: 1000
					});
					getDoorInfo('hik', '');
				}
			});
		});
	} catch (e) {
		console.error("关闭弹出框失败:", e);
	}
}

//图片动画处理
function setGif(obj, txt, total) {
	let i = 0;
	setInterval(() => {
		i++;
		if (i > total) i = 1;
		$(obj).attr('src', 'images/dcim/gif/' + txt + i + '.png');
	}, 100);
}

//设备参数获取
function getDevParam(id, command, callback) {
	let param = {
		token: token,
		DevID: id,
		ComboBox: '1'
	}
	if (command) param.Command = command;
	list.info('GetDeviceCommandListKey', param, function (r) {
		if (r.data.length != 0) {
			if (command) {
				if (r.data[0].LastReceiveData) {
					let LastReceiveData = r.data[0].LastReceiveData;
					LastReceiveData = LastReceiveData.replace(/'/g, '"');
					let jsonarr = JSON.parse(LastReceiveData);
					callback(jsonarr);
				}
			} else {
				callback(r);
			}
		}
	})
}
//设备单个参数处理
function dealParam(val) {
	let jsonarr;
	if (val.LastReceiveData) {
		let LastReceiveData = val.LastReceiveData;
		LastReceiveData = LastReceiveData.replace(/'/g, '"');
		jsonarr = JSON.parse(LastReceiveData);
	}
	let Recvarr = [];
	if (val.CommandRecv) {
		let CommandRecv = val.CommandRecv;
		CommandRecv = CommandRecv.split(':');
		$.each(CommandRecv, function (x, y) {
			Recvarr.push(y.split(',')[1])
		})
		Recvarr = Recvarr.slice(0, -1);//去掉最后一个空数组
	}
	return [jsonarr, Recvarr]
}
//处理一个毽子队的数值和单位
function dealoneParam(str) {
	let unit = str.indexOf('(') > -1 ? str.split('(')[1].split(')')[0] : '';
	let rstr = str.indexOf('(') > -1 ? str.split('(')[0] : str;

	if (parseInt(rstr.replace('.', '')).toString().indexOf('32767') > -1 || parseInt(rstr.replace('.', '')).toString().indexOf('65535') > -1) {
		rstr = '-1';
	}
	return [rstr, unit]
}

//发送控制指令
// DevId 设备id
// text 指令说明 关键词匹配
// callback 回调函数
// tipfalse 存在此字段不展示提示框
function sendCommand(DevId, text, callback, tipfalse) {
	//先查询指令
	list.info('GetDeviceCommandListKey', {
		DevID: DevId,
		token: token,
		ComboBox: 'all',
		type: 2
	}, function (res) {
		if (res.data.length != 0) {
			let findCom = res.data.filter(v => v.CommandDesc.indexOf(text) > -1);
			if (findCom.length > 0) {
				if (findCom.length == 1) {
					//发送指令
					if (tipfalse) {
						list.info('CreateDeviceCommandSendKey', {
							token: token,
							DevID: DevId,
							Command: findCom[0]['Command']
						})
					} else {
						layer.confirm('确认发送此控制命令', function (index) {
							list.info('CreateDeviceCommandSendKey', {
								token: token,
								DevID: DevId,
								Command: findCom[0]['Command']
							}, function () {
								if (callback) callback();
								layer.msg('已发送', {
									icon: 6,
									time: 1000
								})
							})
							layer.close(index);
						});
					}
					// CommandSend(DevId, findCom[0]['Command'])
				} else {
					layer.msg('匹配到了多条相关指令', {
						icon: 5,
						time: 2000
					});
				}
			} else {
				layer.msg('未匹配到相关指令', {
					icon: 5,
					time: 2000
				});
			}
		}
	})
}
//发送指令
function CommandSend(DevID, Command) {
	layer.confirm('确认发送此控制命令', function (index) {
		list.info('CreateDeviceCommandSendKey', {
			token: token,
			DevID: DevID,
			Command: Command
		}, function () {
			layer.msg('已发送', {
				icon: 6,
				time: 1000
			})
		})
		layer.close(index);
	});
}

//门禁设备类型请求
function getDoorInfo(type, data, callback) {
	$.get('http://' + window.location.hostname + ':8089/' + type, data, function (res) {
		// var res = (data.format == 'json') ? JSON.parse(res) : res;
		if (callback) callback(res);
	})
}

//获取地址栏中文字符
let CNparams = getParams(window.location.href);
function getParams(url) {
	if (url.indexOf("?") > 0) {
		var paramsStr = url.split("?")[1];
		var paramsArr = paramsStr.split("&");
		var paramsObj = {};
		for (var i = 0, len = paramsArr.length; i < len; i++) {
			var arr = paramsArr[i].split("=");
			var name = decodeURIComponent(arr[0]);
			var value = decodeURIComponent(arr[1]);
			paramsObj[name] = value;
		}
		return paramsObj;
	}
}

//版本区分 //动环版本，只取1的值
function setdcim(r) {
	let dcim = -1;
	$.ajaxSettings.async = false;
	list.info('GetLogoKey', {
		token: token
	}, function (res) {
		dcim = res.data[0].dcim;
		localStorage.setItem('dm', dcim);
	})
	$.ajaxSettings.async = true;
	if (dcim == 1) {
		let res = {
			data: []
		}
		$.each(r.data, function (n, val) {
			if (val.dcim == 1) {
				let newobj = {
					...val,
					children: []
				}
				res.data.push(newobj);
				$.each(val.children, function (x, y) {
					if (y.dcim == 1) {
						let newobj2 = {
							...y,
							children: []
						}
						newobj.children.push(newobj2);
						$.each(y.children, function (d, b) {
							if (b.dcim == 1) {
								let newobj3 = {
									...b,
									children: []
								}
								newobj2.children.push(newobj3);
								$.each(b.children, function (k, v) {
									if (v.dcim == 1) {
										newobj3.children.push({
											...v
										});
									}
								})
							}
						})
					}
				})
			}
		})
		r.data = res.data;
	}

	return r.data;
}

//中文转码
function toUtf8(str) {
	var out, i, len, c;
	out = "";
	len = str.length;
	for (i = 0; i < len; i++) {
		c = str.charCodeAt(i);
		if ((c >= 0x0001) && (c <= 0x007F)) {
			out += str.charAt(i);
		} else if (c > 0x07FF) {
			out += String.fromCharCode(0xE0 | ((c >> 12) & 0x0F));
			out += String.fromCharCode(0x80 | ((c >> 6) & 0x3F));
			out += String.fromCharCode(0x80 | ((c >> 0) & 0x3F));
		} else {
			out += String.fromCharCode(0xC0 | ((c >> 6) & 0x1F));
			out += String.fromCharCode(0x80 | ((c >> 0) & 0x3F));
		}
	}
	return out;
}

//新版本文件
// 导入功能
// let formData = {
// 	"file_path": res.path.substring(1),
// 	"table": "dcim-assetattr",
// 	"primary_key": "AttrName",
// 	"primary_field_name": "AttrName",
// 	"mappings": [
// 		//     {
// 		//         "excel_field": "companyId",
// 		//         "lookup_table": "company",
// 		//         "lookup_field": "companyName",
// 		//         "lookup_value": "id"
// 		//     }
// 	]
// }
function upDataPtl(upload, formData, callback, elem = '#importBtn') {
	var uploadint = upload.render({
		elem: elem
		, url: ajaxUrl + 'upload'
		, accept: 'file'
		, exts: 'xls|xlsx'
		, method: 'POST'
		, headers: { Auth: token }
		, done: function (res) {
			formData['file_path'] = res.path.substring(1);
			ajaxWithRetry({
				type: 'POST',
				url: ajaxUrl + 'import',
				data: JSON.stringify(formData),
				contentType: "application/json; charset=utf-8",
				beforeSend: function (xhr) {
					xhr.setRequestHeader("Auth", token);
				},
				success: function (r) {
					if (r.status != 'error') {
						layer.msg('导入成功！', {
							icon: 1,
							time: 1000
						}, function () {
							if (callback) callback();
						})
					} else {
						var myMsg;
						let errorTip = '';
						if (r.errors.length > 0) {
							$.each(r.errors, function (n, val) {
								errorTip += `第${val.row + 1}行，${val.error}<br/>`
							})
						}
						myMsg = layer.msg(errorTip, {
							icon: 5,
							time: false
						});
						$(document).on("click", ".layui-layer-msg", function () {
							layer.close(myMsg);
						});
					}
				},
				error: function (r) {
					layer.msg(r.message, {
						icon: 5,
						time: 2000
					});
				}
			});
		}
		, error: function () { }
	});
}
