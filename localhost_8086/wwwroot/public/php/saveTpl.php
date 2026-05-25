<?php
    include_once ("config.php"); 
    $name = trim($_POST['name']);

    $destination_folder="/www/wwwroot/localhost_8086/wwwroot/public/Images/pagetpl/"; //涓婁紶鏂囦欢璺緞
    $file = $destination_folder . $name  . '.txt';
    $content = $_POST['tplcon'];

    if(!file_exists($destination_folder)){//鏄惁瀛樺湪鐩綍锛屼笉瀛樺湪灏卞垱寤?
        // mkdir($destination_folder, 0755, true);
        if (!mkdir($destination_folder, 0755, true)) {
            errorJson("鏃犳硶鍒涘缓妯＄増椤甸潰鐩綍: " . $destination_folder);
            exit;
        }
    }
    
    if (file_exists($file)){
        errorJson("鍚屽悕鏂囦欢宸茬粡瀛樺湪浜?);
        exit;
    }
    
    file_put_contents($file, $content);

    setJson('璇锋眰鎴愬姛',$fname);

?>

