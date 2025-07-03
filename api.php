<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 检查目录是否存在
if (!file_exists('contents')) {
    mkdir('contents', 0777, true);
}

// 默认配置
$defaultConfig = [
    'base62_chars' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    'BASE' => 62,
    'prefix' => 'F',
    'sleep_time' => 1,
    'timeout' => 5,
    'batch_size' => 50
];

// 用户代理列表
$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 10; SM-G981B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36'
];

function base62_to_int($s, $base62_chars) {
    $num = 0;
    $base = strlen($base62_chars);
    for ($i = 0; $i < strlen($s); $i++) {
        $num = $num * $base + strpos($base62_chars, $s[$i]);
    }
    return $num;
}

function int_to_base62($n, $base62_chars, $length = 5) {
    $s = '';
    $base = strlen($base62_chars);
    while ($n > 0) {
        $s = $base62_chars[$n % $base] . $s;
        $n = intdiv($n, $base);
    }
    return str_pad($s, $length, '0', STR_PAD_LEFT);
}

function check_short_links_batch($codes, &$results, $userAgents, $timeout) {
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $batchResults = [];
    
    // 创建所有curl句柄
    foreach ($codes as $code) {
        $url = "http://163cn.tv/{$code}";
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgents[array_rand($userAgents)],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$code] = $ch;
    }
    
    // 执行所有请求
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // 收集结果
    foreach ($codes as $code) {
        $ch = $curlHandles[$code];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        $result = 'error';
        
        if (!$error && ($httpCode == 301 || $httpCode == 302)) {
            if (preg_match('/Location:\s*(.+?)[\r\n]/i', $response, $matches)) {
                $location = trim($matches[1]);
                
                if (strpos($location, 'vip-invite-cashier') !== false) {
                    $url = "http://163cn.tv/{$code}";
                    $results['vip_links'][$code] = ['url' => $url, 'redirect' => $location];
                    $result = 'vip';
                } elseif (strpos($location, 'gift-receive') !== false) {
                    $url = "http://163cn.tv/{$code}";
                    $results['gift_links'][$code] = ['url' => $url, 'redirect' => $location];
                    $result = 'gift';
                } else {
                    $result = 'other_redirect';
                }
            }
        } elseif (!$error && $httpCode == 200) {
            $result = 'invalid';
        } else {
            $result = 'invalid';
        }
        
        $batchResults[$code] = $result;
        
        // 清理curl句柄
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    // 清理multi句柄
    curl_multi_close($multiHandle);
    
    return $batchResults;
}

/**
 * 生成缓存文件名
 * @param string $prefix 短链前缀
 * @param string $start_suffix 起始后缀
 * @return string 缓存文件路径
 */
function generateCacheFileName($prefix, $start_suffix) {
    // 新的命名规则：prefix + start_suffix
    $cache_key = $prefix . $start_suffix;
    return "contents/results_{$cache_key}.json";
}

function initializeResults($config, $start_suffix, $max_range, $start_batch = 1) {
    return [
        'vip_links' => [],
        'gift_links' => [],
        'checked' => 0,
        'status' => 'processing',
        'message' => '开始处理...',
        'last_checked_id' => 0,
        'config' => $config,
        'start_suffix' => $start_suffix,
        'max_range' => $max_range,
        'start_batch' => $start_batch,
        'log' => [],
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'batch_stats' => [
            'total_batches' => ceil($max_range / $config['batch_size']),
            'completed_batches' => 0,
            'current_batch_size' => 0,
            'skipped_batches' => $start_batch - 1
        ]
    ];
}

function updateResultsConfig(&$results, $config, $max_range, $start_batch = 1) {
    $results['config'] = $config;
    $results['max_range'] = $max_range;
    $results['start_batch'] = $start_batch;
    $results['updated_at'] = date('Y-m-d H:i:s');
    
    // 更新批次统计
    if (!isset($results['batch_stats'])) {
        $results['batch_stats'] = [];
    }
    $results['batch_stats']['total_batches'] = ceil($max_range / $config['batch_size']);
    $results['batch_stats']['skipped_batches'] = $start_batch - 1;
    
    $logEntry = [
        'time' => date('Y-m-d H:i:s'),
        'code' => 'CONFIG_UPDATE',
        'result' => 'config_updated'
    ];
    
    if (!isset($results['log'])) {
        $results['log'] = [];
    }
    array_unshift($results['log'], $logEntry);
}

// 处理GET请求 - 获取进度
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start_suffix = $_GET['start_suffix'] ?? 'Bm5xqZ';
    $prefix = $_GET['prefix'] ?? 'F';
    
    $cache_file = generateCacheFileName($prefix, $start_suffix);
    
    if (file_exists($cache_file)) {
        $results = json_decode(file_get_contents($cache_file), true);
        echo json_encode($results);
    } else {
        echo json_encode([
            'status' => 'not_started',
            'message' => '尚未开始处理',
            'start_suffix' => $start_suffix,
            'prefix' => $prefix,
            'cache_file' => $cache_file
        ]);
    }
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // 处理删除请求
    if (isset($_GET['action']) && $_GET['action'] === 'delete') {
        $start_suffix = $input['start_suffix'] ?? 'Bm5xqZ';
        $config = $input['config'] ?? [];
        
        // 合并配置以获取正确的prefix
        $config = array_merge($defaultConfig, $config);
        $prefix = $config['prefix'];
        
        $cache_file = generateCacheFileName($prefix, $start_suffix);
        
        if (file_exists($cache_file)) {
            if (unlink($cache_file)) {
                echo json_encode([
                    'success' => true, 
                    'message' => '缓存文件已删除',
                    'deleted_file' => $cache_file
                ]);
            } else {
                echo json_encode([
                    'success' => false, 
                    'message' => '无法删除缓存文件',
                    'file_path' => $cache_file
                ]);
            }
        } else {
            echo json_encode([
                'success' => false, 
                'message' => '未找到缓存文件',
                'file_path' => $cache_file
            ]);
        }
        exit;
    }
    
    // 处理正常的检测请求
    $start_suffix = $input['start_suffix'] ?? 'Bm5xqZ';
    $max_range = intval($input['max_range'] ?? 900000000);
    $start_batch = intval($input['start_batch'] ?? 1);
    $config = $input['config'] ?? [];
    
    // 合并配置
    $config = array_merge($defaultConfig, $config);
    $prefix = $config['prefix'];
    
    $cache_file = generateCacheFileName($prefix, $start_suffix);
    
    // 加载已有结果或初始化
    if (file_exists($cache_file)) {
        $results = json_decode(file_get_contents($cache_file), true);
        
        // 检查并更新配置
        if (!isset($results['config']) || 
            json_encode($results['config']) !== json_encode($config) ||
            $results['max_range'] !== $max_range ||
            $results['start_batch'] !== $start_batch) {
            updateResultsConfig($results, $config, $max_range, $start_batch);
        }
    } else {
        $results = initializeResults($config, $start_suffix, $max_range, $start_batch);
    }
    
    // 计算ID范围，考虑自定义起始批次
    $base_start_id = base62_to_int($start_suffix, $config['base62_chars']);
    $batch_offset = ($start_batch - 1) * $config['batch_size'];
    $effective_start_id = $base_start_id + $batch_offset;
    $max_id = $base_start_id + $max_range;
    
    // 确定当前要检查的ID
    $current_id = $results['last_checked_id'] > 0 ? $results['last_checked_id'] + 1 : $effective_start_id;
    
    // 检查是否已完成
    if ($current_id >= $max_id) {
        $results['status'] = 'completed';
        $results['message'] = '处理完成';
        $results['progress'] = 100;
        $results['current_code'] = $results['current_code'] ?? '-';
    } else {
        // 计算当前批次大小
        $batch_size = min($config['batch_size'], $max_id - $current_id);
        $codes = [];
        
        // 生成当前批次的codes
        for ($i = 0; $i < $batch_size; $i++) {
            $id = $current_id + $i;
            $suffix = int_to_base62($id, $config['base62_chars']);
            $code = $config['prefix'] . $suffix;
            $codes[] = $code;
        }
        
        // 批量检查短链
        $batchResults = check_short_links_batch($codes, $results, $userAgents, $config['timeout']);
        
        // 更新统计信息
        $results['checked'] += count($codes);
        $results['last_checked_id'] = $current_id + $batch_size - 1;
        $results['current_code'] = end($codes);
        
        // 计算进度（基于实际处理的范围）
        $processed_range = $results['last_checked_id'] - $effective_start_id + 1;
        $total_processing_range = $max_id - $effective_start_id;
        $results['progress'] = round(($processed_range / $total_processing_range) * 100, 2);
        $results['status'] = 'processing';
        
        // 更新批次统计
        if (!isset($results['batch_stats'])) {
            $results['batch_stats'] = [
                'total_batches' => ceil($max_range / $config['batch_size']),
                'completed_batches' => 0,
                'current_batch_size' => 0,
                'skipped_batches' => $start_batch - 1
            ];
        }
        $results['batch_stats']['completed_batches']++;
        $results['batch_stats']['current_batch_size'] = $batch_size;
        
        // 统计结果并添加日志
        $stats = [
            'vip' => 0,
            'gift' => 0,
            'other_redirect' => 0,
            'invalid' => 0,
            'error' => 0
        ];
        
        foreach ($batchResults as $code => $result) {
            $stats[$result]++;
            
            // 添加详细日志（只记录重要结果）
            if (in_array($result, ['vip', 'gift', 'error'])) {
                $logEntry = [
                    'time' => date('Y-m-d H:i:s'),
                    'code' => $code,
                    'result' => $result
                ];
                
                if (!isset($results['log'])) {
                    $results['log'] = [];
                }
                array_unshift($results['log'], $logEntry);
            }
        }
        
        // 添加批次汇总日志
        $actual_batch_number = $start_batch + $results['batch_stats']['completed_batches'] - 1;
        $batchLogEntry = [
            'time' => date('Y-m-d H:i:s'),
            'code' => "BATCH_{$actual_batch_number}",
            'result' => 'batch_completed',
            'details' => [
                'batch_number' => $actual_batch_number,
                'batch_size' => $batch_size,
                'vip_found' => $stats['vip'],
                'gift_found' => $stats['gift'],
                'other_redirect' => $stats['other_redirect'],
                'invalid' => $stats['invalid'],
                'error' => $stats['error']
            ]
        ];
        
        if (!isset($results['log'])) {
            $results['log'] = [];
        }
        array_unshift($results['log'], $batchLogEntry);
        
        // 限制日志数量
        if (count($results['log']) > 9) {
            $results['log'] = array_slice($results['log'], 0, 9);
        }
        
        // 更新状态消息
        $found_count = $stats['vip'] + $stats['gift'];
        if ($found_count > 0) {
            $results['message'] = "批次 {$actual_batch_number}: 发现 {$found_count} 个有效链接 (VIP: {$stats['vip']}, 礼物: {$stats['gift']})";
        } else {
            $results['message'] = "批次 {$actual_batch_number}: 检查了 {$batch_size} 个链接，无有效结果";
        }
        
        $results['updated_at'] = date('Y-m-d H:i:s');
    }
    
    // 保存结果
    if (file_put_contents($cache_file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        echo json_encode([
            'status' => 'error',
            'message' => '无法保存检测结果',
            'file_path' => $cache_file
        ]);
        exit;
    }
    
    // 返回最新状态（添加缓存文件信息用于调试）
    $results['debug_info'] = [
        'cache_file' => $cache_file,
        'prefix' => $prefix,
        'start_suffix' => $start_suffix
    ];
    
    echo json_encode($results);
    exit;
}

// 处理OPTIONS请求（CORS预检）
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 其他请求方法
http_response_code(405);
echo json_encode([
    'status' => 'error',
    'message' => '不支持的请求方法'
]);
?>
