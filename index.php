<?php
require_once("init.php");
require_once("func.php");



// 解析mail_data.json文件內容並儲存為array
$mailData = json_decode(file_get_contents("mail_data.json"), true);
// 將部分信息儲存為單獨的變量中
$mailPrefixContentSequentially = $mailData["mail_prefix_content_sequentially"];
$directlyMailData = $mailData["mail_directly"];
$needReplyMailData = $mailData["mail_need_reply"];

//echo "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

// 將不會變信息的define成constant
define("senderName", $mailData["sender_name"]);
define("receiverName", $mailData["receiver_name"]);
//print_r($defaultReplyData);



// 如果收到用戶用GET傳輸希望清空部分$_SESSION的信息
if(isset($_GET["unsetSession"])){
	unset($_SESSION["displayData"]);
	unset($_SESSION["welcomeAlertClosedArr"]);
}
// FOR TESTING
//unset($_SESSION["displayData"]);



// 記錄$welcomeAlertClosedArrId以確定這一檢查點的網頁的「歡迎界面」是否被關閉過（如果關閉過就不會再顯示）
if(empty($_GET["checkpointId"])){
	$welcomeAlertClosedArrId = "default";
}else{
	$welcomeAlertClosedArrId = $_GET["checkpointId"];
}

// 如果還未創建該session
if(!isset($_SESSION["welcomeAlertClosedArr"])){
	// 初始化$_SESSION["welcomeAlertClosedArr"]
	$_SESSION["welcomeAlertClosedArr"] = [];
}


// 初始化$sequenceArr
$sequenceArr = [];
// 如果收到用戶用GET傳輸順序信息
if(!empty($_GET["sequence"])){
	// 將順序信息以int的方式儲存至$sequenceArr中
	$sequenceArr = array_map('intval', explode(',', $_GET["sequence"]));
	//var_dump($sequenceArr);
}


// 如果$_SESSION["displayData"]為空
if(empty($_SESSION["displayData"])){
	// 初始化$_SESSION["displayData"]
	$_SESSION["displayData"] = [];
	// 循環JSON內「初始介紹郵件」
	foreach($mailData["introduction_mail"] as $eachIntroMailData){
		$mailSenderName = senderName;
		// 如果有自定義發件人名稱
		if(!empty($eachIntroMailData["sender"])){
			$mailSenderName = $eachIntroMailData["sender"];
		}
		// 生成郵件數據
		$valueToBeAppend = generateMailData($mailSenderName, getCurrentTime(), parsedownText($eachIntroMailData["content"]));
		// 將郵件加入空$_SESSION["displayData"]中
		array_push($_SESSION["displayData"], $valueToBeAppend);
	}
}
//print_r($_SESSION["displayData"]);

// 如果收到用戶用GET傳輸關於目前checkpointId的信息
if(!empty($_GET["checkpointId"])){
	// 將（如果有）多個checkpointId分裂為Array
	$checkpointIdArr = explode(',', $_GET["checkpointId"]);
	// 循環所有傳入的checkpointId
	foreach($checkpointIdArr as $checkpointId){
		// 如果JSON文件內有這一checkpointId的郵件內容
		if(isset($directlyMailData[$checkpointId])){
			// 設定$currentMailData為儲存於JSON內的預設郵件內容
			$currentMailData = $directlyMailData[$checkpointId];
			// 初始化郵件內容
			$currentMailDataContent = "";

			// 初始化$isLastCheckpoint
			$isLastCheckpoint = false;

			// 如果目前的checkpointId在sequenceArr內
			if(in_array(intval($checkpointId), $sequenceArr)){
				// 找到這是第N個checkpoint
				$sequenceKey = array_search(intval($checkpointId), $sequenceArr);
				// 將這個checkpoint應顯示的prefixContent加入至郵件內容
				if(check($sequenceKey, $mailPrefixContentSequentially)){
					$currentMailDataContent .= $mailPrefixContentSequentially[$sequenceKey]."\n\n";
				}

				// 如果這個checkpoint是$sequenceArr中的最後一個checkpoint
				if($sequenceKey == count($sequenceArr)-1){
					$isLastCheckpoint = true;
				}
			}

			// 如果目前checkpoint不是最後一個checkpoint
			if(!$isLastCheckpoint){
				// 將JSON內的預設郵件內容加入郵件內容中
				$currentMailDataContent .= $currentMailData["content"];
			}

			// 替換部分信息
			$currentMailDataContent = replaceWithAllCheckpointData($currentMailDataContent, $checkpointId, $sequenceArr);

			// 對郵件內容進行格式化處理
			$currentMailDataContent = parsedownText($currentMailDataContent);

			// 初始化$shouldAdd
			$shouldAdd = true;

			// 循環所有已經顯示在屏幕上的郵件
			foreach($_SESSION["displayData"] as $eachValue){
				// 如果其中有一封郵件的內容與想要添加的內容重複
				if($eachValue["content"] == $currentMailDataContent){
					// 不允許添加本郵件
					$shouldAdd = false;
				}
			}
			// 如果允許添加本郵件
			if($shouldAdd == true){
				$mailSenderName = senderName;
				// 如果有自定義發件人名稱
				if(!empty($currentMailData["sender"])){
					$mailSenderName = $currentMailData["sender"];
				}
				// 生成郵件數據
				$valueToBeAppend = generateMailDataWithID($checkpointId, $mailSenderName, getCurrentTime(), $currentMailDataContent);
				// 將郵件加入$_SESSION["displayData"]中
				array_push($_SESSION["displayData"], $valueToBeAppend);
			}
		}
	}
}


// 如果收到AJAX用POST傳輸用戶發表新回覆的信息
if(!empty($_POST["reply-content"])){
	// 設定回覆內容為$replyContent
	$replyContent = $_POST["reply-content"];
	// 生成郵件數據
	$valueToBeAppend = generateMailData(receiverName, getCurrentTime(), $replyContent);
	// 將回覆郵件加入$_SESSION["displayData"]中
	array_push($_SESSION["displayData"], $valueToBeAppend);
	// 生成郵件HTML以供AJAX新增至網頁
	getMailContentHTML($valueToBeAppend);


	// 循環JSON內「需要回覆才會發送的郵件」
	foreach($needReplyMailData as $eachData){
		// 初始化$canAppendValue
		$canAppendValue = true;
		// 如果$canAppendValue仍然為true的話
		if($canAppendValue == true){
			// 如果郵件需要用戶回覆指定內容
			if(!empty($eachData["requiredReplyBeforeShown"])){
				// 檢查用戶是否回覆指定內容
				if($replyContent == $eachData["requiredReplyBeforeShown"]){
					$canAppendValue = true;
				}else{
					$canAppendValue = false;
				}
			}
		}
		// 如果$canAppendValue仍然為true的話
		if($canAppendValue == true){
			// 如果郵件需要GET傳入指定checkpointId
			if(!empty($eachData["requiredCheckpointId"])){
				// 如果GET傳入的checkpointId為空
				if(empty($_GET["checkpointId"])){
					$canAppendValue = false;
				}else{
					// 將（如果有）多個checkpointId分裂為Array
					$checkpointIdArr = explode(',', $_GET["checkpointId"]);
					// 如果目前GET是在指定的checkpointId中
					if(in_array($eachData["requiredCheckpointId"], $checkpointIdArr)){
						$canAppendValue = true;
					}else{
						$canAppendValue = false;
					}
				}
			}
		}
		// 如果$canAppendValue仍然為true的話
		if($canAppendValue == true){
			// 如果郵件需要通過具體的function來驗證用戶回覆的內容
			if(!empty($eachData["requiredValidationFunction"])){
				/* MARK: Required Validation Function Detail */
				// 將JSON內的字串設定為$requiredValidationFunctionString
				$requiredValidationFunctionString = $eachData["requiredValidationFunction"];

				if(!empty($_GET["checkpointId"])){
					// 獲取GET中的最後一個checkpointId
					$checkpointId = array_slice(explode(',', $_GET["checkpointId"]), -1)[0];

					// 替換部分信息
					$requiredValidationFunctionString = replaceWithAllCheckpointData($requiredValidationFunctionString, $checkpointId, $sequenceArr);
				}

				// 將郵件內容中的requiredValidationFunction用「,」分割
				$explodedFunctionArr = explode(",", $requiredValidationFunctionString);

				// 如果第[0]個值是一個function的名字
				if(function_exists($explodedFunctionArr[0])){
					// 第[0]個值將是該function的名字
					// 該function的第一個參數將是用戶回覆內容
					// 第二個參數將是explodedFunctionArr中除function名字外的值（即刪除（array_slice）explodedFunctionArr[0]後以Array形式傳送）
					$canAppendValue = call_user_func($explodedFunctionArr[0], $replyContent, array_slice($explodedFunctionArr, 1));
				}
			}
		}
		// 如果$canAppendValue仍然為true的話
		if($canAppendValue == true){

			$replyMailContent = $eachData["content"];

			if(!empty($_GET["checkpointId"])){
				// 獲取GET中的最後一個checkpointId
				$checkpointId = array_slice(explode(',', $_GET["checkpointId"]), -1)[0];

				// 替換部分信息
				$replyMailContent = replaceWithAllCheckpointData($replyMailContent, $checkpointId, $sequenceArr);
			}

			// 生成郵件數據
			$valueToBeAppend = generateMailData(senderName, getCurrentTime(), parsedownText($replyMailContent));
			// 將新的一封郵件加入$_SESSION["displayData"]中
			array_push($_SESSION["displayData"], $valueToBeAppend);

			// 生成郵件HTML以供AJAX新增至網頁
			// 第二個參數為true代表這一郵件將延遲N秒（見JS文件）後才顯示
			getMailContentHTML($valueToBeAppend, true);
		}
	}

	// 由於是AJAX請求，所以直接exit即可
	exit;
}


// 如果收到用戶用GET傳輸指示希望顯示debug信息
if(isset($_GET["showDebug"])){
	//echo "<br /><br /><br />";
	?><pre><?php print_r($_SESSION["displayData"]); ?></pre><?php
	//echo "<br /><br />";
	//print_r($displayData);
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="user-scalable=no" />
	<link rel="stylesheet" type="text/css" href="css/style.css">
	<link rel="stylesheet" type="text/css" href="css/modal_dialog.css">
	<link rel="stylesheet" type="text/css" href="css/loading_animation.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.0.0/jquery.min.js"></script>
	<script src="script.js"></script>
	<title><?php echo $mailData["page_title"]; ?></title>
</head>
<body>
<!-- 載入中特效 -->
<div id="circle"></div><div id="circle1"></div>

<?php

// 如果該checkpointId頁面還沒顯示過「歡迎界面」的話
if(!check($welcomeAlertClosedArrId, $_SESSION["welcomeAlertClosedArr"])){
	if(!empty($mailData["welcome_to_new_checkpoint_message"])){
		?><script>alert("<?php echo $mailData['welcome_to_new_checkpoint_message']; ?>");</script><?php
	}
	// 記錄該網頁已顯示alert
	$_SESSION["welcomeAlertClosedArr"][$welcomeAlertClosedArrId] = true;
}
?>

<div class="mail-header">
	<span class="sender">寄件人：<span class="email-address"><?php echo $mailData["sender"]; ?></span></span><br />
	<span class="receiver">收件人：<span class="email-address"><?php echo $mailData["receiver"]; ?></span></span>
	<p class="title">標題：<b><?php echo $mailData["email_title"]; ?></b></p>
</div>

<?php
// 如果$_SESSION["displayData"]不為空的話
if(!empty($_SESSION["displayData"])){
	foreach($_SESSION["displayData"] as $eachData){
		// 生成郵件HTML
		getMailContentHTML($eachData);
	}
}
?>

<hr style="margin-top: 25px;" id="before-reply-email-content" />

<div class="reply-email-content">
	<form id="reply-email" method="POST">
		<p><label for="reply-content">回覆：</label></p>
		<textarea class="reply" id="reply-content" name="reply-content" required="required"></textarea>
		<input type="submit" value="傳送" />
	</form>
</div>
</body>
</html>