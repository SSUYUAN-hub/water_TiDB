<?php
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}
$apiKey = $_ENV['GOOGLE_API_KEY'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OCR 偵錯</title>
<style>
body { font-family: monospace; padding: 16px; background: #f5f5f5; }
.card { background: white; padding: 16px; border-radius: 8px; margin-bottom: 14px; }
pre { white-space: pre-wrap; word-break: break-all; font-size: 0.85em; background: #f8f8f8; padding: 12px; border-radius: 6px; }
.token { display: inline-block; margin: 3px; padding: 3px 7px; border-radius: 4px; font-size: 0.82em; border: 1px solid #ddd; }
.top-token   { background: #FFF9C4; border-color: #F9A825; } /* 上方30% */
.other-token { background: #f0f0f0; }
.year-match  { background: #C8E6C9; border-color: #388E3C; font-weight: bold; }
h3 { margin: 0 0 10px; color: #333; font-size: 0.95em; }
.btn { padding: 10px 20px; background: #2E7D32; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 1em; }
</style>
</head>
<body>
<div class="card">
  <h3>📷 上傳打卡卡片進行 OCR 偵錯</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="card_image" accept="image/*" required style="margin-bottom:10px;display:block">
    <button type="submit" class="btn">🔍 偵錯辨識</button>
  </form>
</div>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['card_image'])) {
    $rawImage  = file_get_contents($_FILES['card_image']['tmp_name']);
    $imageData = base64_encode($rawImage);

    $payload = json_encode(["requests" => [["image" => ["content" => $imageData], "features" => [["type" => "DOCUMENT_TEXT_DETECTION"]]]]]);
    $ch = curl_init("https://vision.googleapis.com/v1/images:annotate?key=" . $apiKey);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $result      = json_decode($response, true);
    $annotations = $result['responses'][0]['textAnnotations'] ?? [];
    $fullText    = $annotations[0]['description'] ?? '';
    $tokens      = array_slice($annotations, 1);

    // 計算圖片範圍
    $allY = []; $allX = [];
    foreach ($tokens as $ann) {
        $verts = $ann['boundingPoly']['vertices'] ?? [];
        foreach ($verts as $v) {
            if (isset($v['y'])) $allY[] = $v['y'];
            if (isset($v['x'])) $allX[] = $v['x'];
        }
    }
    $imgMinY = $allY ? min($allY) : 0;
    $imgMaxY = $allY ? max($allY) : 1;
    $imgMinX = $allX ? min($allX) : 0;
    $imgMaxX = $allX ? max($allX) : 1;
    $imgH    = $imgMaxY - $imgMinY;
    $imgW    = $imgMaxX - $imgMinX;
    $topCutoff = $imgMinY + $imgH * 0.30;

    // 整理 token 資料
    $topTexts = [];
    $tokenData = [];
    foreach ($tokens as $ann) {
        $text  = $ann['description'] ?? '';
        $verts = $ann['boundingPoly']['vertices'] ?? [];
        $ys    = array_column($verts, 'y');
        $xs    = array_column($verts, 'x');
        $cy    = $ys ? (min($ys)+max($ys))/2 : 0;
        $cx    = $xs ? (min($xs)+max($xs))/2 : 0;
        $relX  = $imgW > 0 ? ($cx - $imgMinX) / $imgW : 0;
        $isTop = $cy <= $topCutoff;
        if ($isTop) $topTexts[] = $text;
        $tokenData[] = ['text'=>$text, 'cx'=>round($cx), 'cy'=>round($cy), 'relX'=>round($relX,3), 'isTop'=>$isTop];
    }

    // 年月解析結果
    $topText      = implode(' ', $topTexts);
    $topTextClean = strtr($topText, ['l'=>'1','O'=>'0','I'=>'1','o'=>'0','A'=>'月','#'=>'年']);
    $yearMonth    = '未找到';
    $matchDetail  = '';

    // 策略一：座標定位
    $candidateYear = null; $candidateMonth = null; $yearRelX = 0;
    foreach ($tokenData as $t) {
        if (!$t['isTop']) continue;
        $cleaned = strtr($t['text'], ['l'=>'1','O'=>'0','I'=>'1','o'=>'0','S'=>'5']);
        if (preg_match('/^([1][0-9]{2})$/', $cleaned, $m)) {
            $y=(int)$m[1];
            if ($y>=100&&$y<=199&&$t['relX']>=0.50&&$t['relX']<=0.82) {
                $candidateYear=$y; $yearRelX=$t['relX'];
                $matchDetail .= "年份token: {$t['text']}({$t['relX']}) ";
            }
        }
        if (preg_match('/^([0-9]{1,2})$/', $cleaned, $m)) {
            $mo=(int)$m[1];
            if ($mo>=1&&$mo<=12&&$t['relX']>=0.80&&$t['relX']<=0.99&&$candidateYear!==null&&$t['relX']>$yearRelX) {
                $candidateMonth=$mo;
                $matchDetail .= "月份token: {$t['text']}({$t['relX']}) ";
            }
        }
    }
    if ($candidateYear!==null&&$candidateMonth!==null) {
        $yearMonth=($candidateYear+1911).'-'.str_pad($candidateMonth,2,'0',STR_PAD_LEFT);
        $matchDetail = '策略一（座標定位）: '.$matchDetail;
    } else {
        // 策略二：文字比對
        if (preg_match('/([1][0-9]{2})\s*[年#\$&]\s*([0-9]{1,2})\s*[月A]/', $topTextClean, $m)) {
            $y=(int)$m[1]; $mo=(int)$m[2];
            if ($y>=100&&$y<=199&&$mo>=1&&$mo<=12) {
                $yearMonth=($y+1911).'-'.str_pad($mo,2,'0',STR_PAD_LEFT);
                $matchDetail='策略二（文字比對）';
            }
        }
    }

    echo '<div class="card"><h3>📋 全文（Raw OCR）</h3><pre>'.htmlspecialchars($fullText).'</pre></div>';

    echo '<div class="card"><h3>🟡 上方30%區域 Token（用於年月搜尋）</h3>';
    echo '<p style="font-size:0.82em;color:#555">原始：'.htmlspecialchars($topText).'</p>';
    echo '<p style="font-size:0.82em;color:#555">清理後：'.htmlspecialchars($topTextClean).'</p>';
    echo '</div>';

    echo '<div class="card"><h3>✅ 年月解析結果</h3>';
    echo '<p><strong>yearMonth = '.$yearMonth.'</strong></p>';
    echo '<p style="color:#555;font-size:0.85em">匹配來源：'.($matchDetail ?: '無匹配').'</p>';
    echo '</div>';

    echo '<div class="card"><h3>🔢 所有 Token（黃=上方30%，白=其他）</h3><div>';
    foreach ($tokenData as $t) {
        $cls = $t['isTop'] ? 'top-token' : 'other-token';
        $isYM = preg_match('/年|月|\d{2,3}/', $t['text']);
        echo "<span class='token {$cls}' title='cx={$t['cx']} cy={$t['cy']} relX={$t['relX']}'>".htmlspecialchars($t['text'])."</span>";
    }
    echo '</div></div>';

    echo '<div class="card"><h3>📐 圖片範圍資訊</h3>';
    echo "<p>Y範圍：{$imgMinY} ~ {$imgMaxY}（高度：{$imgH}）</p>";
    echo "<p>X範圍：{$imgMinX} ~ {$imgMaxX}（寬度：{$imgW}）</p>";
    echo "<p>上方30%截止Y座標：".round($topCutoff)."</p>";
    echo '</div>';
}
?>
</body>
</html>
