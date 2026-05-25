<?php
    include_once ("config.php"); 

    try {
        $pageName = trim($_POST['pageName']);//闇€瑕佸鍑虹殑page鍚嶇О
        $pageTxt = trim($_POST['pageTxt']);//闇€瑕佸鍑虹殑page json鏂囦欢鍚?
        //鏍规嵁鏂囦欢鍚嶈幏鍙栨枃浠跺唴瀹?json
        // var_dump( $pageName);
        // var_dump( $pageTxt);
        $pageurl = "/www/wwwroot/localhost_8086/wwwroot/public/Images/page/";
        $imgurl = "/www/wwwroot/localhost_8086/wwwroot/public/";

        $fileurl =  $pageurl . $pageTxt . ".txt";
        // var_dump( $fileurl);
        // var_dump( file_exists($fileurl));
        //璇诲彇鏂囦欢鍐呭
   
        if (file_exists($fileurl)){
            // 瑕佸垱寤虹殑鏂囦欢澶瑰悕绉?
            $folderName = $pageurl . $pageTxt;
            // 鍒涘缓鏂版枃浠跺す
            if(is_dir($folderName)){//鏂囦欢澶瑰瓨鍦?鍒欏垹闄ゆ枃浠跺す
                deleteFolder($folderName);
            }
            if (!mkdir($folderName, 0755, true)) {
                // errorJson('鏃犳硶鍒涘缓鐩綍');
                errorJson(getError('鍒涘缓鐩綍'));
                exit;
            }else{
                $folderimgName = $pageurl . $pageTxt . '/img';//鍥剧墖鐩爣鏂囦欢澶?
                if (!mkdir($folderimgName, 0755, true)) {
                    // errorJson('鏃犳硶鍒涘缓鍥剧墖鐩綍');
                    errorJson(getError('鍒涘缓鍥剧墖鐩綍'));
                    exit;
                }
            }
            $jsonString = file_get_contents($fileurl); // 璇诲彇txt鏂囦欢鍐呭
            $newdata = json_decode($jsonString, true); // 瑙ｇ爜JSON瀛楃涓?
            $data = json_decode($newdata,true);
            $findImg = [];
            if (json_last_error() === JSON_ERROR_NONE) {
                $childArr = $data['children'][0]['children'];
                if($childArr){
                    foreach ( $childArr as $key => $value) {
                        //鍙鍙?className = image  鑳屾櫙id= canvasBackground
                        if($value['attrs']['id']=='canvasBackground' && $value['attrs']['fillPatternImage']){
                            $findImg[] = $value['attrs']['fillPatternImage'];
                        }
                        // var_dump($value);
                        if($value['attrs']['moduleJson']['children']){
                            foreach ($value['attrs']['moduleJson']['children'] as $k => $val) {
                                if($val['className']=='Image'){
                                    // var_dump($val['attrs']['image']);
                                    // var_dump($value['attrs']['moduleJson']['attrs']['where']);
                                    //鍥剧墖  浣跨敤attrs閲岄潰鐨勫浘   鍥剧墖鍒囨崲 鍜岀姸鎬佸姩鍥剧敤 where 閲岄潰鐨?
                                    if($value['attrs']['moduleJson']['attrs']['where']){
                                        foreach ($value['attrs']['moduleJson']['attrs']['where'] as $x => $y) {
                                            // var_dump($y['statusSelectColor']);
                                            if(strpos($y['statusSelectColor'],'data:image')===false && strpos($y['statusSelectColor'],'Images/dcim/') === false){//鍓旈櫎绯荤粺鑷甫鏂囦欢
                                                $findImg[] = $y['statusSelectColor'];
                                            }
                                        }
                                    }
                                    // var_dump($val['attrs']['image']);
                                    if(strpos($val['attrs']['image'],'data:image')===false && strpos($val['attrs']['image'],'Images/dcim/') === false){//鍓旈櫎绯荤粺鑷甫鏂囦欢
                                        $findImg[] = $val['attrs']['image'];
                                    }
                                }
                            }
                        }
                    }
                }
                $errorinfo = '';
                // var_dump($findImg);
                //灏嗘墍鏈夊浘鐗囨斁鍒?img 鏂囦欢澶逛笅
                foreach ( $findImg as $key => $value) {
                    $newval =  str_replace("../", "", $value);
                    $newurl = $imgurl . $newval;
                    $destinationFile = $folderimgName . '/' . basename($newval);
                    // var_dump( $newurl);
                    // var_dump( $destinationFile);
                    if (file_exists($newurl)) {
                        if (!copy($newurl, $destinationFile)) {
                            // $errorinfo .= $newurl . '鍥剧墖鏂囦欢澶嶅埗澶辫触';
                            $errorinfo .= getError($value.'鍥剧墖鏂囦欢澶嶅埗');
                        }
                    }else{
                        // $errorinfo .= $newurl . '鍥剧墖鏂囦欢涓嶅瓨鍦?;
                        $errorinfo .= getError($value.'鍥剧墖鏂囦欢鏌ユ壘');
                    }
                }

                if($errorinfo){
                    errorJson($errorinfo);
                    return;
                }

                //灏嗗師濮媡xt鏂囦欢鏀惧埌涓巌mg鍚岀骇鐩綍涓?
                // var_dump( $fileurl);
                // var_dump( $folderName);
                if (!copy($fileurl, $folderName.'/'. $pageTxt . ".txt")) {
                    // errorJson('txt鏂囦欢澶嶅埗澶辫触');
                    errorJson(getError($pageTxt.'.txt鏂囦欢澶嶅埗'));
                    return;
                }

                // 浣跨敤鍑芥暟鍘嬬缉鏂囦欢澶?
                $sourceFolder = $folderName; // 瑕佸帇缂╃殑鏂囦欢澶硅矾寰?
                $destinationZip = $folderName.'.zip'; // 鍘嬬缉鏂囦欢瀛樺偍璺緞
                if (file_exists($destinationZip)) {
                    unlink($destinationZip);
                }
                if (zipFolder($sourceFolder, $destinationZip)) {
                    deleteFolder($folderName);
                    setJson('璇锋眰鎴愬姛',$destinationZip);
                } else {
                    // errorJson("鍘嬬缉澶辫触銆?);
                    errorJson(getError($destinationZip.'鍘嬬缉'));
                }

            } else {
                errorJson('JSON瑙ｇ爜澶辫触');
            }
        }else{
            // errorJson('鏂囦欢涓嶅瓨鍦?);
            errorJson(getError( $pageTxt.'.txt鏂囦欢鏌ユ壘'));
        }

    } catch (Exception $e) {
        // 鎹曡幏骞跺鐞嗗紓甯?
        errorJson('鎹曡幏鍒板紓甯? ' . $e->getMessage());
    }
    
    //鍒犻櫎鏂囦欢澶瑰強涓嬮潰鐨勬枃浠?
    function deleteFolder($folderPath) {
        if (!is_dir($folderPath)) {
            return;
        }
        $files = glob($folderPath . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                deleteFolder($file);
            } else {
                unlink($file);
            }
        }
        
        rmdir($folderPath);
    }
    //鎵撳寘鍘嬬缉鏂囦欢
    function zipFolder($source, $destination) {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }
     
        $zip = new ZipArchive();
        if (!$zip->open($destination, ZipArchive::CREATE)) {
            return false;
        }
     
        $source = str_replace('\\', '/', realpath($source));
     
        if (is_dir($source) === true) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
     
            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);
     
                // Ignore "." and ".." folders
                if (in_array(substr($file, strrpos($file, '/')+1), array('.', '..')))
                    continue;
     
                $file = realpath($file);
     
                if (is_dir($file) === true) {
                    $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                } else if (is_file($file) === true) {
                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                }
            }
        } else if (is_file($source) === true) {
            $zip->addFromString(basename($source), file_get_contents($source));
        }
     
        return $zip->close();
    }
?>

