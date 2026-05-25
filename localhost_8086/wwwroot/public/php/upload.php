<?php
    include_once ("config.php"); 
    //涓婁紶鏂囦欢绫诲瀷鍒楄〃
    $uptypes=array(
        'image/jpg',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/svg',
    );
    $max_file_size=20971520;     //涓婁紶鏂囦欢澶у皬闄愬埗, 鍗曚綅BYTE
    $destination_folder="/www/wwwroot/localhost_8086/wwwroot/public/Images/uploads/"; //涓婁紶鏂囦欢璺緞

    if ($_SERVER['REQUEST_METHOD'] == 'POST'){

        if (!is_uploaded_file($_FILES["file"]["tmp_name"])){//鏄惁瀛樺湪鏂囦欢
            errorJson('鍥剧墖涓嶅瓨鍦?');
            exit;
        }
        $file = $_FILES["file"];
        if($max_file_size < $file["size"]){//妫€鏌ユ枃浠跺ぇ灏?
            errorJson('鏂囦欢澶ぇ!');
            exit;
        }
        if(!in_array($file["type"], $uptypes)){//妫€鏌ユ枃浠剁被鍨?
            errorJson("鏂囦欢绫诲瀷涓嶇!".$file["type"]);
            exit;
        }
        if(!file_exists($destination_folder)){//鏄惁瀛樺湪鐩綍锛屼笉瀛樺湪灏卞垱寤?
            // mkdir($destination_folder,0755, true);
            if (!mkdir($destination_folder, 0755, true)) {
                errorJson("鏃犳硶鍒涘缓鍥剧墖鐩綍: " . $destination_folder);
                exit;
            }
        }

        $filename=$file["tmp_name"];
        $image_size = getimagesize($filename);
        $pinfo=pathinfo($file["name"]);
        $ftype=$pinfo['extension'];
        $destination = $destination_folder.time().".".$ftype;
        if (file_exists($destination)){
            errorJson("鍚屽悕鏂囦欢宸茬粡瀛樺湪浜?);
            exit;
        }

        if(!move_uploaded_file ($filename, $destination)){
            errorJson("绉诲姩鏂囦欢鍑洪敊");
            exit;
        }

        $pinfo=pathinfo($destination);
        $fname=$pinfo["basename"];
        // echo " <font color=red>宸茬粡鎴愬姛涓婁紶</font><br>鏂囦欢鍚?  <font color=blue>".$destination_folder.$fname."</font><br>";
        // echo " 瀹藉害:".$image_size[0];
        // echo " 闀垮害:".$image_size[1];
        // echo "<br> 澶у皬:".$file["size"]." bytes";
        setJson('璇锋眰鎴愬姛',$fname);
    }

?>

