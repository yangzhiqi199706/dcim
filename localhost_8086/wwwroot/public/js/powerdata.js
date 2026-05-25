function setHTML(){
	var id = GetString("id");
	getDevParam(id,'',function(json){
		$.each(json.data,function(n,val){
			let jsonarr;
			if(val.LastReceiveData){
				let LastReceiveData= val.LastReceiveData;
				LastReceiveData = LastReceiveData.replace(/'/g, '"');
				jsonarr = JSON.parse(LastReceiveData);
			}
			if(val.LastReceiveData && n==1) {
			   deal(jsonarr);
			}
			if(val.LastReceiveData && n==2) {
				let dataArr = {
					data:[
						{'paramKey':'正向有功','unit':'kwh','param':'正向有功电能','paramVal':'0'},
						{'paramKey':'反向有功','unit':'kvarh','param':'负向有功电能','paramVal':'0'},
						{'paramKey':'正向无功','unit':'kwh','param':'正向无功电能','paramVal':'0'},
						{'paramKey':'反向无功','unit':'kvarh','param':'负向无功电能','paramVal':'0'},
					]
				};
				for (let key in jsonarr) {
					$.each(dataArr.data,function(n,val){
						if(key.indexOf(val['paramKey'])>=0){
							val.paramVal=jsonarr[key].indexOf('6553')>=0?0:jsonarr[key];
						}
					})
				}
				var html = template("detailtpl2", dataArr);
				$("#detail2").html(html);
			}
			if(val.LastReceiveData && n==3) {
				let parArr = {
					data:[
						{'paramKey':'无功功率','unit':'Kvar','param':'无功功率','u':'0','v':'0','w':'0','p':'0'},
						{'paramKey':'有功功率','unit':'KW','param':'有功功率','u':'0','v':'0','w':'0','p':'0'},
						{'paramKey':'视在功率','unit':'Kva','param':'视在功率','u':'0','v':'0','w':'0','p':'0'},
						{'paramKey':'功率因数','unit':'','param':'功率因数','u':'0','v':'0','w':'0','p':'0'},
					]
				};
				for (let key in jsonarr) {
					if(key.indexOf('A相')>=0 || key.indexOf('B相')>=0 || key.indexOf('C相')>=0 || key.indexOf('总')>=0){
						let u,v,w,p;
						if(key.indexOf('A相')>=0)u=jsonarr[key];
						if(key.indexOf('B相')>=0)v=jsonarr[key];
						if(key.indexOf('C相')>=0)w=jsonarr[key];
						if(key.indexOf('总')>=0)p=jsonarr[key];
	
						key=key.split('(')[0];
	
						if(key.indexOf('A相')>=0)key=key.replace('A相','');
						if(key.indexOf('B相')>=0)key=key.replace('B相','');
						if(key.indexOf('C相')>=0)key=key.replace('C相','');
						if(key.indexOf('总')>=0)key=key.replace('总','');
	
						if(u || v || w || p){
							$.each(parArr.data,function(n,val){
								if(val['paramKey']==key){
									if(u)val.u=u;
									if(v)val.v=v;
									if(w)val.w=w;
									if(p)val.p=p;
								}
							})
						}
					}
				}
	
				var html3 = template("detailtpl3", parArr);
				$("#detail3").html(html3);
			}
		})
	})
	//数据处理
	function deal(jsonarr){
		let dataArr = {
			data:[
				{'paramKey':'相电','unit':'V','param':'相电压','u':'0','v':'0','w':'0','p':'0'},
				{'paramKey':'线电','unit':'V','param':'线电压','u':'0','v':'0','w':'0','p':'0'},
				{'paramKey':'电流','unit':'A','param':'相电流','u':'0','v':'0','w':'0','p':'0'},
			]
		};
		
		let chart = [];
		for (let key in jsonarr) {
	
			if(key.indexOf('总频率')>=0)$('#totalNum').html(jsonarr[key]);//频率
			
			let u,v,w,p;
			if(key.indexOf('Uca')>=0 || key.indexOf('Uan')>=0 || key.indexOf('Ia')>=0)u=jsonarr[key];
			if(key.indexOf('Uab')>=0 || key.indexOf('Ubn')>=0 || key.indexOf('Ib')>=0)v=jsonarr[key];
			if(key.indexOf('Ubc')>=0 || key.indexOf('Ucn')>=0 || key.indexOf('Ic')>=0)w=jsonarr[key];
			if(key.indexOf('ULLAvg')>=0 || key.indexOf('ULNAvg')>=0 || key.indexOf('IAvg')>=0)p=jsonarr[key];
	
			key=key.slice(0, 2);//匹配前3位
	
			if(u || v || w || p){
				$.each(dataArr.data,function(n,val){
					if(val['paramKey']==key){
						if(u)val.u=u;
						if(v)val.v=v;
						if(w)val.w=w;
						if(p)val.p=p;
					}
					if(key=='三相' && val['paramKey']=='电流'){
						val.p=p;
					}
				})
			}
		}
	
		chart.push(dataArr.data[2].u);
		chart.push(dataArr.data[2].v);
		chart.push(dataArr.data[2].w);
		var html = template("detailtpl", dataArr);
		$("#detail").html(html);
		setChart(chart);
	
	}
	
	function setChart(data){
		var changeChartDom = document.getElementById('changeChart');
		var changeChart = echarts.init(changeChartDom);
		var changetitle = ['A相电流','B相电流','C相电流'];
		var changedata = data;
			
		var changeChartoption= {
			grid:{
				x:40,
				y:50,
				x2:25,
				y2:30
			},
			xAxis: {
				type: 'category',
				data: changetitle,
				axisLine:{
					show:true,//是否显示坐标轴轴线，
					lineStyle:{
						color:'#B9D1FF',//轴线的颜色
						width:1,//粗细
					}
				}
			},
			yAxis: {
				type: 'value',
				axisLabel: {
					show:true,
					textStyle: {
						color: '#B9D1FF',
						fontSize: '13'
					}
				},
				splitLine:{
					show:false,
				},
				axisTick:{
					show:true,//是否显示刻度
					lineStyle:{color:'#B9D1FF'} 
				},
				axisLine:{
					show:true,//是否显示坐标轴轴线，
					lineStyle:{
						color:'#B9D1FF',//轴线的颜色
						width:1,//粗细
					}
				}
			},
			series: [
				{
					data: changedata,
					type: 'bar',
					markPoint: {
						data: [
							{
								name: 'A相电流',
								coord: [0, data[0]],
								value: data[0],
								label: {
									color: '#fff'
								},
								itemStyle: {
									color:'#C04000'
								}
							},
							{
								name: 'B相电流',
								coord: [1, data[1]],
								value: data[1],
								label: {
									color: '#fff'
								},
								itemStyle: {
									color:'#00C0C0'
								}
							},
							{
								name: 'C相电流',
								coord: [2, data[2]],
								value: data[2],
								label: {
									color: '#fff'
								},
								itemStyle: {
									color:'#00C000'
								}
							},
						]
					},
					itemStyle: {
						normal: {
							//这里是循环开始的地方
							color: function(params) {
								var colorList = ['#C04000','#00C0C0','#00C000']
								if (params.dataIndex >= colorList.length) {
										params.dataIndex = params.dataIndex - colorList.length
									}
								return colorList[params.dataIndex]
							},
						}
					}
				}
			]
		};
		changeChartoption && changeChart.setOption(changeChartoption);
	}
}
