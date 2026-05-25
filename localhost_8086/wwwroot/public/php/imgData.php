<?php
    include_once ("config.php"); 

    $action = $_POST['action'];
    $name = $_POST['name'];
    switch ($action){
        case 'system':getSystemImg();break;
        case 'upload':getUploadImg();break;
        case 'del':delUploadImg();break;
        case 'tpl':getTpl();break;
        case 'deltpl':delTpl();break;
        case 'page':getPage($name);break;
        case 'delpage':delPage();break;
    }
    //鑾峰彇绯荤粺榛樿鍥剧墖
    function getSystemImg(){
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/dcim"; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞

        // 浣跨敤 scandir() 鍑芥暟鑾峰彇鎸囧畾鐩綍涓嬬殑鎵€鏈夋枃浠跺拰瀛愮洰褰?
        $file_list = scandir($dir);

        // 寰幆閬嶅巻鏂囦欢鍒楄〃骞惰緭鍑烘瘡涓枃浠跺拰瀛愮洰褰曠殑鍚嶇О
        $data = array();
        foreach($file_list as $file) {
            if(strlen($file)>2 && strpos($file,'.')){
                $new['imgUrl']="../Images/dcim/". $file;
                array_push($data,$new);
            };
        };
        setJson('璇锋眰鎴愬姛',$data);
    }

    //鑾峰彇鐢ㄦ埛涓婁紶鍥剧墖
    function getUploadImg(){
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/uploads"; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞

        // 浣跨敤 scandir() 鍑芥暟鑾峰彇鎸囧畾鐩綍涓嬬殑鎵€鏈夋枃浠跺拰瀛愮洰褰?
        $file_list = scandir($dir);

        // 寰幆閬嶅巻鏂囦欢鍒楄〃骞惰緭鍑烘瘡涓枃浠跺拰瀛愮洰褰曠殑鍚嶇О
        $data = array();
        foreach($file_list as $file) {
            if(strlen($file)>2 && strpos($file,'.')){
                $new['imgUrl']="../Images/uploads/". $file;
                array_push($data,$new);
            };
        };
        setJson('璇锋眰鎴愬姛',$data);
    }
    //鍒犻櫎鐢ㄦ埛涓婁紶鍥剧墖
    function delUploadImg(){
        $img = $_POST['img'];
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/uploads/"; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞
        $imgarr = explode("/", $img);
        $file_path = $dir . $imgarr[count($imgarr)-1];
        if (file_exists($file_path)) {
            unlink($file_path);
            setJson('鍒犻櫎鎴愬姛',1);
        } else {
            errorJson('鏂囦欢涓嶅瓨鍦?);
        }
    }

    //鑾峰彇鐢ㄦ埛瀛樺偍鐨勬ā鐗?
    function getTpl(){
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/pagetpl"; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞

        // 浣跨敤 scandir() 鍑芥暟鑾峰彇鎸囧畾鐩綍涓嬬殑鎵€鏈夋枃浠跺拰瀛愮洰褰?
        $file_list = scandir($dir);

        if( $file_list == false){
            errorJson("鏃犳硶璇诲彇鐩綍鍐呭");
        }else{
            // 寰幆閬嶅巻鏂囦欢鍒楄〃骞惰緭鍑烘瘡涓枃浠跺拰瀛愮洰褰曠殑鍚嶇О
            $data = array();

            foreach($file_list as $file) {
                
                // 妫€鏌ョ紪鐮?
                if (!mb_check_encoding($file, 'UTF-8')) {
                    continue;
                }
                
                // 妫€鏌ョ壒娈婃儏鍐?
                if (preg_match('/[锟絔/', $file)) {
                    continue;
                }

                if(strlen($file)>2 && strpos($file,'.')){
                    $new['moduleName']=explode(".", $file)[0];
                    $new['iconBase64']="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAQlJREFUWEdjZBhgwDjA9jNgOMDVM9aegYlBjNoOY2Rk/M/I8O/2zi1LLiKbjeIAN5/YPEYGhonUthzJvL//GBmcd29efBAmhuIAd5+Y2QwMjCk0dADD//+M2bu2LpqG1QEeHgkK/1j+TGP4zyhK0BGMDNqMDAycUHWv//9neEhQD8P/G7++/sw8cGD1F6wOIGwAQoWbT+w1RgYGTajI7J1bFqeRon/UAaMhMBoCoyEwGgKjITAaAqMhMBoCoyFAcQi4+8SCOhh6IIP+//8/bdfWJdn0bZR6xRYyMP7vZWRg/Paf4b/brq1LjtHVASDL3HxjFZn//Piyffvq1+RYDtIz+Dqn5PqEXH0A9hGnIbhiy9wAAAAASUVORK5CYII=";
                    // $new['fileurl']="Images/pagetpl/" . $file;
                    $new['moduleJson']=file_get_contents('/www/wwwroot/localhost_8086/wwwroot/public/Images/pagetpl/'.$file);
                    array_push($data,$new);
                };

            };
            setJson('璇锋眰鎴愬姛',$data);
        }
        
    }
    //鍒犻櫎闈炵郴缁熼粯璁ゆā鐗?
    function delTpl(){
        $name = $_POST['name'];
        if($name=='UPS' || $name=='鐢垫睜缁? || $name=='鐜鐩戞祴' || $name=='绮惧瘑绌鸿皟' || $name=='閰嶇數鏌滆繘绾? || $name=='閰嶇數鏌滄敮璺? || $name=='鏅€氱┖璋? || $name=='寰ā鍧?){
            errorJson('榛樿妯＄増涓嶅彲鍒犻櫎锛?);
            exit;
        }
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/pagetpl/".$name.'.txt'; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞

        $file_path = $dir . $img;
        if (file_exists($file_path)) {
            unlink($file_path);
            setJson('鍒犻櫎鎴愬姛',1);
        } else {
            errorJson('鏂囦欢涓嶅瓨鍦?);
        }
    }
    //鑾峰彇鐢ㄦ埛瀛樺偍鐨勯〉闈?
    function getPage($name){
        $dir = "/www/wwwroot/localhost_8086/wwwroot/public/Images/page"; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞

        // 浣跨敤 scandir() 鍑芥暟鑾峰彇鎸囧畾鐩綍涓嬬殑鎵€鏈夋枃浠跺拰瀛愮洰褰?
        $file_list = scandir($dir);

        // 寰幆閬嶅巻鏂囦欢鍒楄〃骞惰緭鍑烘瘡涓枃浠跺拰瀛愮洰褰曠殑鍚嶇О
        $data = array();
        // if(!$name){
        //     foreach($file_list as $file) {
        //         if(strlen($file)>2 && strpos($file,'.')){
        //             $new['moduleName']=explode(".", $file)[0];
        //             $new['iconBase64']="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAQlJREFUWEdjZBhgwDjA9jNgOMDVM9aegYlBjNoOY2Rk/M/I8O/2zi1LLiKbjeIAN5/YPEYGhonUthzJvL//GBmcd29efBAmhuIAd5+Y2QwMjCk0dADD//+M2bu2LpqG1QEeHgkK/1j+TGP4zyhK0BGMDNqMDAycUHWv//9neEhQD8P/G7++/sw8cGD1F6wOIGwAQoWbT+w1RgYGTajI7J1bFqeRon/UAaMhMBoCoyEwGgKjITAaAqMhMBoCoyFAcQi4+8SCOhh6IIP+//8/bdfWJdn0bZR6xRYyMP7vZWRg/Paf4b/brq1LjtHVASDL3HxjFZn//Piyffvq1+RYDtIz+Dqn5PqEXH0A9hGnIbhiy9wAAAAASUVORK5CYII=";
        //             $new['moduleJson']=file_get_contents('/www/wwwroot/localhost_8086/wwwroot/public/Images/page/'.$file);
        //             array_push($data,$new);
        //         };
        //     };
        //     setJson('璇锋眰鎴愬姛',$data);
        // }else{
            if (file_exists('/www/wwwroot/localhost_8086/wwwroot/public/Images/page/'.$name.'.txt')) {
                $new['moduleName']=$name;
                $new['iconBase64']="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAQlJREFUWEdjZBhgwDjA9jNgOMDVM9aegYlBjNoOY2Rk/M/I8O/2zi1LLiKbjeIAN5/YPEYGhonUthzJvL//GBmcd29efBAmhuIAd5+Y2QwMjCk0dADD//+M2bu2LpqG1QEeHgkK/1j+TGP4zyhK0BGMDNqMDAycUHWv//9neEhQD8P/G7++/sw8cGD1F6wOIGwAQoWbT+w1RgYGTajI7J1bFqeRon/UAaMhMBoCoyEwGgKjITAaAqMhMBoCoyFAcQi4+8SCOhh6IIP+//8/bdfWJdn0bZR6xRYyMP7vZWRg/Paf4b/brq1LjtHVASDL3HxjFZn//Piyffvq1+RYDtIz+Dqn5PqEXH0A9hGnIbhiy9wAAAAASUVORK5CYII=";
                $new['moduleJson']=file_get_contents('/www/wwwroot/localhost_8086/wwwroot/public/Images/page/'.$name.'.txt');
                array_push($data,$new);
                setJson('璇锋眰鎴愬姛',$data);
            }else{
                setJson('鏂囦欢涓嶅瓨鍦?,null);
            }
        // }
    }
    //鍒犻櫎椤甸潰  鍒犻櫎涓轰簡瀹夊叏杩樻槸绉诲埌澶囦唤鏂囦欢澶瑰幓
    function delPage(){
        $name = $_POST['name'];
        $file_path = "/www/wwwroot/localhost_8086/wwwroot/public/Images/page/" . $name .'.txt'; // 瑕佽幏鍙栨枃浠跺垪琛ㄧ殑鐩綍璺緞
        $backup_path = '/www/wwwroot/localhost_8086/wwwroot/public/Images/page/backup/';
        if (!is_dir($backup_path)) {//涓嶅瓨鍦ㄦ枃浠跺す锛屽垱寤烘枃浠跺す
            // mkdir($backup_path, 0755, true);
            if (!mkdir($backup_path, 0755, true)) {
                errorJson("鏃犳硶鍒涘缓澶囦唤鏂囦欢鐩綍: " . $backup_path);
                exit;
            }
        }
        if(!file_exists($file_path)){//鏈夊彲鑳戒笉鏄粍鎬侀〉闈紝涓嶅瓨鍦╰xt鏂囦欢
            setJson('鍒犻櫎鎴愬姛',1);
        }else{
            if (copy($file_path, $backup_path . $name .'.txt')) {
                if (file_exists($file_path)) {
                    unlink($file_path);
                    setJson('鍒犻櫎鎴愬姛',1);
                } else {
                    errorJson('鏂囦欢涓嶅瓨鍦?);
                }
            } else {
                errorJson("澶囦唤txt鏂囦欢绉诲姩澶辫触銆?);
            }
        }
    }

?>

