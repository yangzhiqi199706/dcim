<?php
    include_once ("config.php");

    //涓婁紶鏂囦欢绫诲瀷鍒楄〃
    $uptypes=array(
        'text/plain'
    );
    $max_file_size=20971520;     //涓婁紶鏂囦欢澶у皬闄愬埗, 鍗曚綅BYTE
    $destination_folder="/www/wwwroot/localhost_8086/wwwroot/public/Images/page/"; //涓婁紶鏂囦欢璺緞

    if ($_SERVER['REQUEST_METHOD'] == 'POST'){

        if (!is_uploaded_file($_FILES["file"]["tmp_name"])){//鏄惁瀛樺湪鏂囦欢
            errorJson('鏂囦欢涓嶅瓨鍦?);
            exit;
        }
        $file = $_FILES["file"];
        if($max_file_size < $file["size"]){//妫€鏌ユ枃浠跺ぇ灏?            errorJson('鏂囦欢澶ぇ!');
            exit;
        }
        if(!in_array($file["type"], $uptypes)){//妫€鏌ユ枃浠剁被鍨?            errorJson("鏂囦欢绫诲瀷涓嶇!".$file["type"]);
            exit;
        }

        if(!file_exists($destination_folder)){//鏄惁瀛樺湪鐩綍锛屼笉瀛樺湪灏卞垱寤?            if (!mkdir($destination_folder, 0755, true)) {
                errorJson("鏃犳硶鍒涘缓椤甸潰鐩綍: " . $destination_folder);
                exit;
            }
        }

        $filename=$file["tmp_name"];
        $pinfo=pathinfo($file["name"]);
        $ftype=$pinfo['extension'];

        $destination = $destination_folder . time() . "." . $ftype;
        if (file_exists($destination)){
            errorJson("鍚屽悕鏂囦欢宸茬粡瀛樺湪");
            exit;
        }

        if(!move_uploaded_file ($filename, $destination)){
            errorJson("绉诲姩鏂囦欢鍑洪敊");
            exit;
        }

        $newinfo=pathinfo($destination);
        $newname=$newinfo["filename"];

        try {
            $pdo = create_pdo($dbcfg);
            // 鏌ヨ鏄惁宸插瓨鍦?            $stmt = $pdo->prepare('SELECT id FROM "dcim-dmpage" WHERE "PageName" = ? AND "status" = 1 LIMIT 1');
            $stmt->execute([$pinfo['filename']]);
            $row = $stmt->fetch();
            if ($row) {
                $stmt = $pdo->prepare('UPDATE "dcim-dmpage" SET "PageTxt" = ? WHERE "id" = ?');
                $stmt->execute([$newname, $row['id']]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO "dcim-dmpage" ("PageName","PageIndex","PageType","PageTxt") VALUES (?,?,?,?)');
                $stmt->execute([$pinfo['filename'], 1, 1, $newname]);
            }
        } catch (Exception $e) {
            errorJson('鏁版嵁搴撴搷浣滃け璐? ' . $e->getMessage());
            exit;
        }

        setJson('璇锋眰鎴愬姛',$newname);
    }

?>


