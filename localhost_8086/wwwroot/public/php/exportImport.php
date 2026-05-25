<?php
    include_once ("config.php");
 
    try {
        $zip = new ZipArchive();
        $upload_dir = '/www/wwwroot/localhost_8086/wwwroot/public/Images/page/'; // 纭繚鐩綍鍙啓
        $imgurl = "/www/wwwroot/localhost_8086/wwwroot/public/Images/uploads/";
        $nameArr = explode("[", $_FILES['file']['name']);//xxxx[1].zip
        $PageName = $nameArr[0];
        $PageIndex = explode("]", $nameArr[1])[0];

        // 鍒涘缓鐩綍
        foreach ([$upload_dir, $imgurl] as $dir) {
            if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
                errorJson("鏃犳硶鍒涘缓鐩綍: " . $dir);
                exit;
            }
        }

        $filename = $upload_dir . basename($_FILES['file']['name']);
        if (file_exists($filename)) {
            unlink($filename);
        }

        $allowed = ['zip'];
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);

        if (in_array(strtolower($ext), $allowed)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime, ['application/zip', 'application/x-zip-compressed','multipart/x-zip'])) {
                errorJson('闈瀂IP鏂囦欢锛屾棤娉曞鍏?);
                exit;
            }
        }else{
            errorJson('璇峰鍏IP鏂囦欢');
            exit;
        }
        
        move_uploaded_file($_FILES['file']['tmp_name'], $filename);

        // 瑙ｅ帇
        if ($zip->open($filename) === TRUE) {
            $extract_path = $upload_dir . 'extracted/';
            if (!is_dir($extract_path) && !mkdir($extract_path, 0755, true)) {
                errorJson("鏃犳硶鍒涘缓瑙ｅ帇鐩綍: " . $extract_path);
                exit;
            }
            $zip->extractTo($extract_path);
            $zip->close();

            $file_list = scandir($extract_path);
            $pageTxt = '';
            foreach($file_list as $file) {
                if(strlen($file)>2 && strpos($file,'.')){//txt
                    $sourceFile = $extract_path . $file;
                    $targetDirectory = $upload_dir . $file;
                    $pageTxt = explode(".txt", $file)[0];
                    if(file_exists($targetDirectory)){
                        errorJson("txt鏂囦欢[" . $file . "]宸插瓨鍦?);
                        deleteFolder($extract_path,$filename);
                        exit;
                    }
                    if (!copy($sourceFile, $targetDirectory)) {
                        errorJson(getError($file.'鏂囦欢绉诲姩'));
                        deleteFolder($extract_path,$filename);
                        exit;
                    }
                };
                if(strlen($file)>2 && $file=='img'){//img鐩綍
                    $img_list = scandir($extract_path . 'img/');
                    foreach($img_list as $img) {
                        if(strlen($img)>2 && strpos($img,'.')){
                            $imgFile = $extract_path . 'img/' . $img;
                            $targetImgDirectory = $imgurl . $img;
                            if (!copy($imgFile, $targetImgDirectory)) {
                                errorJson(getError($img.'鍥剧墖鏂囦欢绉诲姩'));
                                if(isset($targetDirectory) && file_exists($targetDirectory)){
                                    unlink($targetDirectory);
                                }
                                deleteFolder($extract_path,$filename);
                                exit;
                            }
                        }
                    }
                }
            };

            // 鏁版嵁搴撳啓鍏?            $pdo = create_pdo($dbcfg);
            $stmt = $pdo->prepare('INSERT INTO "dcim-dmpage" ("PageName","PageIndex","PageType","PageTxt") VALUES (?,?,?,?)');
            $stmt->execute([$PageName, $PageIndex, 1, $pageTxt]);

            deleteFolder($extract_path,$filename);
            setJson('鏂囦欢瀵煎叆鎴愬姛',null);
        } else {
            errorJson(getError($PageName.'瑙ｅ帇ZIP鏂囦欢'));
        }

    } catch (Exception $e) {
        errorJson('鑾峰彇鍒板紓甯?' . $e->getMessage());
    }

    // 鍒犻櫎鏂囦欢澶瑰強鍘嬬缉鏂囦欢
    function deleteFolder($folderPath,$zipFilePath='') {
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

        if ($zipFilePath && file_exists($zipFilePath)) {//瀛樺湪鏂囦欢 鍒犻櫎涓婁紶鍘嬬缉鍖?            unlink($zipFilePath);
        }
    }
?>


