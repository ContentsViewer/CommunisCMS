<?php

require_once dirname(__FILE__) . "/../Module/Authenticator.php";

Authenticator::RequireLoginedSession();


header ('Content-Type: text/html; charset=UTF-8');


require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";


function SendErrorResponseAndExit($response, $error){
    $response['error'] = $error;
    SendResponseAndExit($response);
}

function SendResponseAndExit($response){
    echo json_encode($response);
    exit;
}


if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    exit;
}


if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    SendResponseAndExit(null);
}


if(!isset($_POST['cmd'])){
    SendResponseAndExit(null);
}

$rootContentPath = ContentsDatabaseManager::GetRelatedRootFile(Authenticator::GetContentsFolder() . '/');
$tagMapMetaFileName = ContentsDatabaseManager::GetRelatedTagMapMetaFileName(Authenticator::GetContentsFolder() . '/');


$cmd = $_POST['cmd'];


if($cmd === 'GetGlobalTagList'){
    ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);

    echo json_encode(Content::GlobalTagMap());
    exit;
}

elseif($cmd === 'GetTaggedContentList' &&
       isset($_POST['tagName'])){

    $tagName = $_POST['tagName'];
    $response = ["isOk" => true, "tagName" => $tagName, "contentList" => []];

    ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);


    if(array_key_exists($tagName, Content::GlobalTagMap())){
        $response["contentList"] = Content::GlobalTagMap()[$tagName];
    }

    SendResponseAndExit($response);
}

elseif($cmd === 'SaveContentFile' && 
    (isset($_POST['content']) || (isset($_POST['path']) && isset($_POST['contentFileString']))) &&
     isset($_POST['openTime'])){

    $contentFileString = "";
    $path = "";
    $modifyTag = 'Y';
    $modifyContentsLink = 'Y';

    if(isset($_POST['modifyTag']) && $_POST['modifyTag'] === 'N'){
        $modifyTag = $_POST['modifyTag'];
    }
    if(isset($_POST['modifyContentsLink']) && $_POST['modifyContentsLink'] === 'N'){
        $modifyContentsLink = $_POST['modifyContentsLink'];
    }
    
    if(isset($_POST['content'])){
        $mappedContent = json_decode($_POST['content'], true);

        $content = new Content();

        $content->SetPath($mappedContent["path"]);
        $content->SetTitle($mappedContent["title"]);
        $content->SetCreatedAt($mappedContent["createdAt"]);
        $content->SetParentPath($mappedContent["parentPath"]);
        $content->SetSummary($mappedContent["summary"]);
        $content->SetBody($mappedContent["body"]);
        $content->SetChildPathList($mappedContent["childPathList"]);
        $content->SetTags($mappedContent["tags"]);

        $contentFileString = $content->ToContentFileString();

        $path = $mappedContent["path"];
    }
    else{
        $contentFileString = $_POST['contentFileString'];
        $path = $_POST['path'];
    }

    $contentFileString = str_replace("\r", "", $contentFileString);

    $openTime = $_POST['openTime'];
    $updatedTime = 0;

    $realPath = Content::RealPath($path, null, false);
    if(file_exists($realPath)){
        $updatedTime = filemtime($realPath);
    }
    
    if($openTime > $updatedTime){

        file_put_contents($realPath,
                         $contentFileString, LOCK_EX);
        
        if($modifyTag === 'Y'){
            Content::CreateGlobalTagMap($rootContentPath);
            Content::SaveGlobalTagMap($tagMapMetaFileName);
        }

        if($modifyContentsLink === 'Y'){
            ContentsDatabaseManager::NotifyContentsLinkChange($path);
        }
        

        header('Location: ../?content=' . $path);
        
        exit;
    }

    RenderDiffEdit($path, file_get_contents($realPath), $contentFileString, $modifyTag, $modifyContentsLink);

    exit;
}

SendResponseAndExit(null);


function RenderDiffEdit($path, $oldContentFileString, $newContentFileString, $modifyTag, $modifyContentsLink){
    $contentFileName = basename($path);

    ?>
    
<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile("../Client/Common/CommonHead.html"); ?>
    
    <title>競合解消 | <?=$contentFileName?></title>
    <style type="text/css" media="screen">
        body {
            overflow: hidden;
        }

        #diff {
            position: absolute;
            bottom: 50px;
            top: 0;
            left: 0;
            right: 0;
        }
        
        #logout{
            position: absolute;
            left: 0;
            top: 95%;
            margin: 0;
            /* height: 5%; */
            z-index:100;
        }
        
        .save{
            position: absolute;

            right: 0;
            bottom: 0;
            font: 3em;
            top: 95%;
            width: 100px;
            
            display: flex;
            align-items: center;
            justify-content: center;

            cursor: pointer;
            color: green;
            border: solid green;

            z-index:99;
        }

    </style>
    
    <script src="../Client/Splitter/Splitter.js" type="text/javascript" charset="utf-8"></script>
    <script src="../Client/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>

    <script src="../Client/ace-diff/ace-diff.js"></script>
    <link href="../Client/ace-diff/dist/ace-diff.min.css" rel="stylesheet">
    <link href="../Client/ace-diff/dist/ace-diff-dark.min.css" rel="stylesheet">
</head>
<body>
    <input type="hidden" id="token" value="<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>"> 
    <input type="hidden" id="contentPath" value="<?=$path?>">
    <input type="hidden" id="openTime" value="<?=time()?>">

    <input type='hidden' id='oldContent' value='<?=htmlspecialchars($oldContentFileString, ENT_QUOTES)?>'>
    <input type='hidden' id='newContent' value='<?=htmlspecialchars($newContentFileString, ENT_QUOTES)?>'>

    <input type='hidden' id='modifyTag' value='<?=$modifyTag?>'>
    <input type='hidden' id='modifyContentsLink' value='<?=$modifyContentsLink?>'>

    <p id='logout'><a href="../logout.php?token=<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>">ログアウト</a></p>

    <div id='diff'></div>

    <div class='save' onclick=SaveContentFile()>SAVE</div>
 
    <script>
        alert("ページ編集中にファイルが変更されたようです. 差分を確認して再保存してください.");

        token = document.getElementById('token').value;
        contentPath = document.getElementById('contentPath').value;
        oldContent = document.getElementById('oldContent').value;
        newContent = document.getElementById('newContent').value;
        modifyContentsLink = document.getElementById('modifyContentsLink').value;
        modifyTag = document.getElementById('modifyTag').value;


        var differ = new AceDiff({
        element: '#diff',
        left: {
            content: oldContent,
            editable: false
        },
        right: {
            content: newContent,
            editable: true,
            copyLinkEnabled: false,
        },
        });
        // var editor = ace.edit("editor");
        // InitEditor(editor);

        // var splitter = new Splitter(Splitter.Direction.Vertical, 
        //                             document.getElementById('diff'),
        //                             document.getElementById('editor'),
        //                             {'percent': 60, 'rect': new Rect(new Vector2(0, 0), new Vector2(100, 95)),
        //                             'onResizeElementBCallbackFunc':function(){editor.resize();}});
        
        document.onkeydown = 
        function (e) {
            if (event.ctrlKey ){
                if (event.keyCode == 83){
                    SaveContentFile();
                    event.keyCode = 0;
                    return false;
                }
            }
        }

        document.onkeypress = 
        function (e) {
            if (e != null){
                if ((e.ctrlKey || e.metaKey) && e.which == 115){
                    SaveContentFile();
                    return false;
                }
            }
        }

        window.onbeforeunload = function(event){
            event = event || window.event; 
            event.returnValue = 'ページから移動しますか？';
        }


        function InitEditor(editor){
                
            editor.setTheme("ace/theme/monokai");
            editor.getSession().setMode("ace/mode/markdown");
            editor.session.setTabSize(4);
            editor.session.setUseSoftTabs(true);
            editor.session.setUseWrapMode(false);

        }

        
        function SaveContentFile(){
            
            alert("Save content.")
            if(!window.confirm('Are you sure?')){
                return;
            }
            
            openTime = document.getElementById('openTime').value;

            window.onbeforeunload = null;

            form = document.createElement('form');
            form.setAttribute('action', 'contents-database-edit-service.php'); 
            form.setAttribute('method', 'POST'); // POSTリクエストもしくはGETリクエストを書く。
            form.style.display = 'none'; // 画面に表示しないことを指定する
            document.body.appendChild(form);

            data = {"cmd": "SaveContentFile", "token": token, "path": contentPath, "openTime": openTime,
            　　　　"contentFileString": differ.getEditors().right.session.getValue(),
                    "modifyTag": modifyTag, "modifyContentsLink": modifyContentsLink};

            if (data !== undefined) {
            Object.keys(data).map((key)=>{
                let input = document.createElement('input');
                input.setAttribute('type', 'hidden');
                input.setAttribute('name', key); //「name」は適切な名前に変更する。
                input.setAttribute('value', data[key]);
                form.appendChild(input);
            })
            }
            form.submit();
            // console.log(form)
            return;
        }

    </script>
</body>
</html>
    <?php
}
?>
