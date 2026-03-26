<?php
/**
 * 从URL获取节目表XML并填充空时间段
 * 版本：2.1
 * 运行环境：PHP 7.4.33
 * 功能：从URL获取XML，填充空档，生成同名XML文件和GZ压缩文件
 * 用法：运行 php noepg.php
 */

// 配置参数
$xmlUrl = 'https://{xml的地址}:5678/t.xml';  // XML文件URL
$defaultTitle = '精彩节目';  // 默认节目名称
$defaultLang = 'zh';        // 默认语言
$minGapSeconds = 60;        // 最小填充间隔（秒），小于此间隔的空档不填充

// 获取当前脚本的基本名称（不含扩展名）
$scriptPath = $_SERVER['SCRIPT_FILENAME'];
$scriptName = pathinfo($scriptPath, PATHINFO_FILENAME);  // 获取脚本名（不含扩展名）
$scriptDir = dirname($scriptPath);  // 获取脚本所在目录

// 生成输出文件名
$xmlFilename = $scriptName . '.xml';      // XML文件名
$gzFilename = $scriptName . '.xml.gz';    // GZ压缩文件名

// 从URL获取XML内容
function fetchXmlFromUrl($url) {
    echo "正在从URL获取XML文件: {$url}\n";
    
    // 使用file_get_contents（需要allow_url_fopen开启）
    if (ini_get('allow_url_fopen')) {
        echo "使用file_get_contents获取...\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => "User-Agent: XML-Processor/2.1\r\n"
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        if ($content !== false) {
            return $content;
        }
    }
    
    // 如果file_get_contents失败，尝试使用cURL
    if (function_exists('curl_init')) {
        echo "使用cURL获取...\n";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'XML-Processor/2.1',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $content = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($content !== false) {
            return $content;
        } else {
            throw new Exception("cURL错误: " . $error);
        }
    }
    
    throw new Exception("无法从URL获取XML文件，请检查网络连接和URL地址");
}

// 解析XML时间字符串为DateTime对象
function parseXmlTime($timeStr) {
    // 格式: YYYYMMDDHHMMSS +zzzz
    if (empty($timeStr)) {
        return false;
    }
    
    try {
        $dateStr = substr($timeStr, 0, 14);
        $timezone = substr($timeStr, 15);
        
        $dateTime = DateTime::createFromFormat('YmdHis', $dateStr);
        if ($dateTime === false) {
            return false;
        }
        
        // 设置时区
        if (!empty($timezone)) {
            try {
                $timezoneObj = new DateTimeZone($timezone);
                $dateTime->setTimezone($timezoneObj);
            } catch (Exception $e) {
                // 时区解析失败，使用默认时区
            }
        }
        
        return $dateTime;
    } catch (Exception $e) {
        return false;
    }
}

// 格式化时间为XML格式
function formatXmlTime(DateTime $dateTime) {
    return $dateTime->format('YmdHis O');
}

// 主处理函数
function fillProgramGaps($xmlContent) {
    global $defaultTitle, $defaultLang, $minGapSeconds;
    
    // 创建DOMDocument对象
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    
    // 加载XML内容
    if (!@$dom->loadXML($xmlContent)) {
        throw new Exception("XML解析失败，可能是无效的XML格式");
    }
    
    // 获取根元素
    $root = $dom->documentElement;
    if (!$root) {
        throw new Exception("XML文件没有根元素");
    }
    
    // 获取所有programme节点
    $xpath = new DOMXPath($dom);
    $programs = $xpath->query('//programme');
    
    if ($programs->length === 0) {
        echo "警告: 没有找到programme节点\n";
        return $xmlContent; // 没有节目数据
    }
    
    echo "找到 {$programs->length} 个节目节点\n";
    
    // 按频道分组
    $channels = [];
    foreach ($programs as $program) {
        $channelName = $program->getAttribute('channel');
        if (empty($channelName)) {
            continue; // 跳过没有频道名称的节目
        }
        
        if (!isset($channels[$channelName])) {
            $channels[$channelName] = [];
        }
        
        $startTime = parseXmlTime($program->getAttribute('start'));
        $stopTime = parseXmlTime($program->getAttribute('stop'));
        
        if ($startTime === false || $stopTime === false) {
            echo "警告: 节目时间格式错误，跳过 - 频道: {$channelName}, 开始: {$program->getAttribute('start')}, 结束: {$program->getAttribute('stop')}\n";
            continue; // 跳过时间格式错误的节目
        }
        
        // 检查结束时间是否早于开始时间
        if ($stopTime <= $startTime) {
            echo "警告: 节目结束时间早于或等于开始时间，跳过 - 频道: {$channelName}\n";
            continue;
        }
        
        $channels[$channelName][] = [
            'node' => $program,
            'start' => $startTime,
            'stop' => $stopTime
        ];
    }
    
    // 对每个频道的节目按开始时间排序
    foreach ($channels as $channelName => &$programsList) {
        usort($programsList, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });
    }
    
    // 查找并填充空档
    $filledCount = 0;
    foreach ($channels as $channelName => $programsList) {
        $channelCount = count($programsList);
        
        for ($i = 0; $i < $channelCount - 1; $i++) {
            $current = $programsList[$i];
            $next = $programsList[$i + 1];
            
            // 检查是否存在空档（结束时间小于下一个开始时间）
            if ($current['stop'] < $next['start']) {
                // 计算空档时长（秒）
                $gapSeconds = $next['start']->getTimestamp() - $current['stop']->getTimestamp();
                
                // 只填充大于指定时间的空档
                if ($gapSeconds > $minGapSeconds) {
                    // 有空档，创建新节目节点
                    $newProgram = $dom->createElement('programme');
                    $newProgram->setAttribute('channel', $channelName);
                    $newProgram->setAttribute('start', formatXmlTime($current['stop']));
                    $newProgram->setAttribute('stop', formatXmlTime($next['start']));
                    
                    $titleElement = $dom->createElement('title', $defaultTitle);
                    $titleElement->setAttribute('lang', $defaultLang);
                    $newProgram->appendChild($titleElement);
                    
                    // 在DOM中插入新节点（插入到当前节点之后）
                    $current['node']->parentNode->insertBefore($newProgram, $next['node']);
                    
                    $filledCount++;
                    
                    echo "填充空档 - 频道: {$channelName}, 时间: " . 
                         $current['stop']->format('Y-m-d H:i:s') . " 到 " .
                         $next['start']->format('Y-m-d H:i:s') . 
                         " (" . gmdate("H:i:s", $gapSeconds) . ")\n";
                }
            }
        }
    }
    
    echo "共填充了 {$filledCount} 个空档\n";
    return $dom->saveXML();
}

// 生成压缩文件
function createGzipFile($content, $filename) {
    echo "正在创建GZ压缩文件: {$filename}\n";
    
    // 使用gzencode压缩
    $compressed = gzencode($content, 9); // 9是最高压缩级别
    
    if ($compressed === false) {
        throw new Exception("GZ压缩失败");
    }
    
    // 写入文件
    if (file_put_contents($filename, $compressed) === false) {
        throw new Exception("无法写入GZ文件: {$filename}");
    }
    
    echo "GZ压缩文件创建成功，大小: " . filesize($filename) . " 字节\n";
    return true;
}

// 主程序
try {
    echo "===== XML节目表空档填充工具 =====\n";
    echo "版本: 2.1\n";
    echo "开始时间: " . date('Y-m-d H:i:s') . "\n";
    echo "脚本名称: {$scriptName}\n";
    echo "脚本目录: {$scriptDir}\n";
    echo "XML来源: {$xmlUrl}\n";
    echo "生成文件: {$xmlFilename}, {$gzFilename}\n";
    echo "默认节目: {$defaultTitle}\n";
    echo "最小填充间隔: {$minGapSeconds} 秒\n";
    echo str_repeat("-", 60) . "\n";
    
    // 检查目录可写权限
    if (!is_writable($scriptDir)) {
        throw new Exception("脚本目录没有写入权限，请检查目录权限: {$scriptDir}");
    }
    
    // 检查文件是否已存在（警告但不阻止）
    if (file_exists($xmlFilename)) {
        echo "警告: XML文件 {$xmlFilename} 已存在，将被覆盖\n";
    }
    
    if (file_exists($gzFilename)) {
        echo "警告: GZ文件 {$gzFilename} 已存在，将被覆盖\n";
    }
    
    // 从URL获取XML内容
    $xmlContent = fetchXmlFromUrl($xmlUrl);
    
    if (empty($xmlContent)) {
        throw new Exception("获取的XML内容为空");
    }
    
    $originalSize = strlen($xmlContent);
    echo "获取成功，XML大小: " . number_format($originalSize) . " 字节\n";
    
    // 处理XML
    echo "开始处理XML...\n";
    $newXmlContent = fillProgramGaps($xmlContent);
    
    // 保存XML文件
    echo "正在保存XML文件: {$xmlFilename}\n";
    if (file_put_contents($xmlFilename, $newXmlContent) === false) {
        throw new Exception("无法写入XML文件: {$xmlFilename}");
    }
    
    $newSize = strlen($newXmlContent);
    echo "XML文件保存成功，大小: " . number_format($newSize) . " 字节\n";
    echo "文件保存位置: {$scriptDir}/{$xmlFilename}\n";
    
    // 创建GZ压缩文件
    createGzipFile($newXmlContent, $gzFilename);
    
    // 显示处理结果摘要
    echo str_repeat("-", 60) . "\n";
    echo "处理完成摘要:\n";
    echo "1. 脚本名称: {$scriptName}.php\n";
    echo "2. 原始XML大小: " . number_format($originalSize) . " 字节\n";
    echo "3. 处理后XML大小: " . number_format($newSize) . " 字节\n";
    echo "4. 生成文件列表:\n";
    echo "   - XML文件: {$xmlFilename}\n";
    echo "   - GZ压缩文件: {$gzFilename}\n";
    echo "5. 文件保存位置: {$scriptDir}\n";
    echo "6. 生成时间: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat("-", 60) . "\n";
    
    // 显示文件权限和所有者信息
    if (function_exists('posix_getpwuid')) {
        $xmlStat = stat($xmlFilename);
        $uid = $xmlStat['uid'];
        $userInfo = posix_getpwuid($uid);
        echo "文件所有者: " . ($userInfo ? $userInfo['name'] : $uid) . "\n";
    }
    
    // 列出脚本目录中的相关文件
    echo "脚本目录中的相关文件:\n";
    $files = scandir($scriptDir);
    $matchedFiles = [];
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $scriptDir . '/' . $file;
        $fileInfo = pathinfo($filePath);
        
        // 查找与脚本名相关的文件
        if (strpos($file, $scriptName) === 0 || $file === $scriptName . '.php') {
            $size = filesize($filePath);
            $modTime = date('Y-m-d H:i:s', filemtime($filePath));
            $matchedFiles[] = [
                'name' => $file,
                'size' => $size,
                'time' => $modTime
            ];
        }
    }
    
    if (count($matchedFiles) > 0) {
        foreach ($matchedFiles as $fileInfo) {
            echo "  - {$fileInfo['name']} (大小: " . number_format($fileInfo['size']) . 
                 " 字节, 修改时间: {$fileInfo['time']})\n";
        }
    } else {
        echo "  未找到相关文件\n";
    }
    
    echo "\n操作完成！\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    
    // 显示调试信息
    echo "\n调试信息:\n";
    echo "1. 当前工作目录: " . getcwd() . "\n";
    echo "2. 脚本所在目录: {$scriptDir}\n";
    echo "3. 脚本名称: {$scriptName}\n";
    echo "4. PHP版本: " . PHP_VERSION . "\n";
    echo "5. 已安装扩展: " . implode(', ', get_loaded_extensions()) . "\n";
    echo "6. 脚本路径: {$scriptPath}\n";
    
    exit(1);
}
?>
