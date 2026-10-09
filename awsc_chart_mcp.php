<?php
/**
 * ============================================================================
 * AWSC 通话记录 MCP Server（HTTP 模式）
 * ============================================================================
 *
 * 【这个文件是干什么的】
 * 把 wachat.csr_sip_log 表封装成 MCP 服务，让 AI 客户端（Claude Desktop、Cursor、
 * 你的知识库系统等）能通过标准 HTTP 接口查询通话记录、录音、转录文本和统计数据。
 *
 * 【部署步骤】
 *
 * 1. 上传文件
 *    把本文件放到 Web 根目录：
 *    /www/wwwroot/querylist.yixiu-cloud.com/awsc_chart_mcp.php
 *
 * 2. 改 Token
 *    找到下面 $MCP_TOKEN 那一行，改成你自己的强随机字符串。
 *
 * 3. 配置 Nginx 传递 Authorization 头（用 Header 传 Token 时才需要）
 *    你的环境是宝塔 + PHP 8.2，PHP 处理通过 include enable-php-82.conf 引入。
 *    在站点配置 server { } 块内，include enable-php-82.conf; 之前，加一段：
 *
 *    location = /awsc_chart_mcp.php {
 *        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
 *        include enable-php-82.conf;
 *    }
 *
 *    如果只用 URL 参数传 Token，或者不传 Token，这段可以不加。
 *
 * 4. 重载 Nginx（如果改了配置）
 *    nginx -t
 *    nginx -s reload
 *
 * 【鉴权方式（三种任选其一）】
 *
 * 1. Header 传 Token（推荐，需要配 Nginx）
 *    curl -X POST https://download.yixiu-cloud.com/awsc_chart_mcp.php \
 *      -H "Content-Type: application/json" \
 *      -H "Authorization: Bearer awsc-mcp-2024-secret-token" \
 *      -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 *
 * 2. URL 参数传 Token（不需要配 Nginx，但不安全，仅建议临时用）
 *    curl -X POST "https://download.yixiu-cloud.com/awsc_chart_mcp.php?token=awsc-mcp-2024-secret-token" \
 *      -H "Content-Type: application/json" \
 *      -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 *
 * 3. 不传 Token（直接放行，仅建议内网/测试环境）
 *    curl -X POST https://download.yixiu-cloud.com/awsc_chart_mcp.php \
 *      -H "Content-Type: application/json" \
 *      -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 *
 * 注意：如果提供了 Token 但和 $MCP_TOKEN 不匹配，会返回 401。
 *       只有“完全不传 Token”才会放行。
 *
 * 【接入 AI 客户端】
 *
 * 支持远程 URL 的 MCP 客户端，配置：
 * {
 *   "mcpServers": {
 *     "awsc-calls": {
 *       "url": "https://download.yixiu-cloud.com/awsc_chart_mcp.php",
 *       "headers": {
 *         "Authorization": "Bearer awsc-mcp-2024-secret-token"
 *       }
 *     }
 *   }
 * }
 *
 * 或者用 URL 参数：
 * {
 *   "mcpServers": {
 *     "awsc-calls": {
 *       "url": "https://download.yixiu-cloud.com/awsc_chart_mcp.php?token=awsc-mcp-2024-secret-token"
 *     }
 *   }
 * }
 *
 * 只支持 stdio 的客户端（如旧版 Claude Desktop），用 mcp-remote 桥接：
 * {
 *   "mcpServers": {
 *     "awsc-calls": {
 *       "command": "npx",
 *       "args": [
 *         "mcp-remote@latest",
 *         "https://download.yixiu-cloud.com/awsc_chart_mcp.php",
 *         "--header",
 *         "Authorization: Bearer awsc-mcp-2024-secret-token"
 *       ]
 *     }
 *   }
 * }
 *
 * 【普通业务系统怎么调】
 *
 * 用 curl 或任何能发 HTTP 请求的语言，直接 POST JSON-RPC：
 *
 * curl -X POST https://download.yixiu-cloud.com/awsc_chart_mcp.php \
 *   -H "Content-Type: application/json" \
 *   -H "Authorization: Bearer awsc-mcp-2024-secret-token" \
 *   -d '{
 *     "jsonrpc": "2.0",
 *     "id": 1,
 *     "method": "tools/call",
 *     "params": {
 *       "name": "query_calls",
 *       "arguments": {"project_id": "ADFstone", "limit": 5}
 *     }
 *   }'
 *
 * PHP 调用示例：
 * $ch = curl_init('https://download.yixiu-cloud.com/awsc_chart_mcp.php');
 * curl_setopt_array($ch, [
 *     CURLOPT_POST => true,
 *     CURLOPT_HTTPHEADER => [
 *         'Content-Type: application/json',
 *         'Authorization: Bearer awsc-mcp-2024-secret-token'
 *     ],
 *     CURLOPT_POSTFIELDS => json_encode([
 *         'jsonrpc' => '2.0',
 *         'id' => 1,
 *         'method' => 'tools/call',
 *         'params' => [
 *             'name' => 'query_calls',
 *             'arguments' => ['project_id' => 'ADFstone', 'limit' => 5]
 *         ]
 *     ]),
 *     CURLOPT_RETURNTRANSFER => true
 * ]);
 * $response = curl_exec($ch);
 * $result = json_decode($response, true);
 * echo $result['result']['content'][0]['text'];
 *
 * 【可用的工具】
 *
 * 1. query_calls
 *    查询通话记录，支持筛选：
 *    - project_id      项目 ID
 *    - agent_name      坐席名称
 *    - customer_phone  客户电话（前缀匹配）
 *    - date_from       开始日期 YYYY-MM-DD
 *    - date_to         结束日期 YYYY-MM-DD
 *    - has_recording   是否只看有录音的（true/false）
 *    - limit           返回条数，默认 20，最大 100
 *
 * 2. get_call_detail
 *    根据 contact_id 查单条通话的完整详情。
 *    参数：contact_id（必填）
 *
 * 3. get_call_transcript
 *    获取指定通话的转录文本。
 *    参数：contact_id（必填）
 *
 * 4. list_transcript_failures
 *    列出转录失败的通话记录（用于排查采集问题）。
 *    - aws_instance    AWS 实例地址（可选）
 *    - hours_back      往前查多少小时，默认 24
 *    - limit           返回条数，默认 20
 *
 * 5. get_call_stats
 *    按项目/坐席/日期统计通话量。
 *    - project_id      项目 ID
 *    - agent_name      坐席名称
 *    - date_from       开始日期
 *    - date_to         结束日期
 *    - group_by        统计维度：project / agent / date，默认 project
 *
 * 【MCP 协议方法】
 *
 * 客户端会发以下 JSON-RPC 请求：
 * - initialize                  握手
 * - notifications/initialized   初始化完成通知（无需响应）
 * - tools/list                  获取工具列表
 * - tools/call                  执行工具
 * - ping                        心跳
 *
 * 【注意事项】
 *
 * 1. 数据库密码硬编码在源码里。确保 Web 服务器禁用了 .php 源码下载，
 *    或者用 .htaccess 保护这个目录，否则源码泄露 = 数据库泄露。
 *
 * 2. 如果启用 Header 鉴权，Nginx 的 fastcgi_param HTTP_AUTHORIZATION 必须加，
 *    否则所有带 Token 的请求都会因为取不到 Header 而变成“未传 Token”。
 *
 * 3. recordingUrl 字段如果 OSS 是公开读的，等于把录音暴露了。
 *    建议 OSS 设为私有读，或者在本文件里对 recordingUrl 做脱敏处理。
 *
 * 4. 如果要加频率限制，在 Nginx 的 server 块外配：
 *    limit_req_zone $binary_remote_addr zone=mcp:10m rate=10r/s;
 *    然后在 location = /awsc_chart_mcp.php 里加：
 *    limit_req zone=mcp burst=20 nodelay;
 *
 * ============================================================================
 */

namespace Facebook\WebDriver;
require_once __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// ============================================================
// 配置
// ============================================================
$MCP_TOKEN = 'awsc-mcp-2024-secret-token'; // ← 改成你自己的强随机字符串

Db::setConfig([
    'default' => 'db_wachat',
    'connections' => [
        'db_wachat' => [
            'type'     => 'mysql',
            'hostname' => '47.241.125.229',
            'database' => 'wachat',
            'username' => 'root',
            'password' => 'yj4hHsbLwRsRBdp8',
            'charset'  => 'utf8mb4',
            'prefix'   => '',
        ],
    ]
]);

// ============================================================
// HTTP 入口
// ============================================================
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Method not allowed']]);
    exit;
}

// ============================================================
// 鉴权（三种方式任选其一）
// 1. Header:   Authorization: Bearer your-token
// 2. URL 参数: ?token=your-token
// 3. 不传:     直接放行（仅建议内网/测试环境使用）
// ============================================================
$tokenFromHeader = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $tokenFromHeader = $matches[1];
}

$tokenFromUrl = $_GET['token'] ?? '';

$providedToken = $tokenFromHeader !== '' ? $tokenFromHeader : $tokenFromUrl;

// 如果提供了 Token，必须匹配；如果没提供，直接放行
if ($providedToken !== '' && !hash_equals($MCP_TOKEN, $providedToken)) {
    http_response_code(401);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Unauthorized: token mismatch']]);
    exit;
}

$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if ($request === null || !is_array($request)) {
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error']]);
    exit;
}

$response = handleRequest($request);

if ($response !== null) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}
exit;

// ============================================================
// 工具定义
// ============================================================
function getToolDefinitions(): array
{
    return [
        [
            'name' => 'query_calls',
            'description' => '查询亚马逊 Connect 通话记录。支持按项目、坐席、客户电话、时间范围、是否有录音等条件筛选。当用户询问某段时间的通话、某坐席的通话记录时使用。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_id' => ['type' => 'string', 'description' => '项目 ID'],
                    'agent_name' => ['type' => 'string', 'description' => '坐席名称'],
                    'customer_phone' => ['type' => 'string', 'description' => '客户电话号码（支持前缀匹配）'],
                    'date_from' => ['type' => 'string', 'description' => '开始日期，格式 YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => '结束日期，格式 YYYY-MM-DD'],
                    'has_recording' => ['type' => 'boolean', 'description' => '是否只看有录音的记录'],
                    'limit' => ['type' => 'integer', 'description' => '返回条数，默认 20，最大 100', 'default' => 20]
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'get_call_detail',
            'description' => '根据 contact_id 获取单条通话的完整详情，包括录音 URL、转录状态、通话时长、客户信息等。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'string', 'description' => '通话记录 ID']
                ],
                'required' => ['contact_id']
            ]
        ],
        [
            'name' => 'get_call_transcript',
            'description' => '获取指定通话的转录文本（对话内容）。如果尚未转录完成，会返回状态说明。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'string', 'description' => '通话记录 ID']
                ],
                'required' => ['contact_id']
            ]
        ],
        [
            'name' => 'list_transcript_failures',
            'description' => '列出转录状态异常的通话记录（如 ResourceNotFoundException），用于排查采集失败的通话。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'aws_instance' => ['type' => 'string', 'description' => 'AWS 实例地址'],
                    'hours_back' => ['type' => 'integer', 'description' => '往前查多少小时，默认 24', 'default' => 24],
                    'limit' => ['type' => 'integer', 'description' => '返回条数，默认 20', 'default' => 20]
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'get_call_stats',
            'description' => '按项目或坐席统计通话量、总时长、平均时长等指标。当用户询问“某项目今天打了多少电话”、“某坐席的通话统计”时使用。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_id' => ['type' => 'string', 'description' => '项目 ID'],
                    'agent_name' => ['type' => 'string', 'description' => '坐席名称'],
                    'date_from' => ['type' => 'string', 'description' => '开始日期 YYYY-MM-DD'],
                    'date_to' => ['type' => 'string', 'description' => '结束日期 YYYY-MM-DD'],
                    'group_by' => ['type' => 'string', 'enum' => ['project', 'agent', 'date'], 'description' => '统计维度', 'default' => 'project']
                ],
                'required' => []
            ]
        ]
    ];
}

// ============================================================
// 工具执行
// ============================================================
function executeTool(string $name, array $args): string
{
    return match ($name) {
        'query_calls'              => toolQueryCalls($args),
        'get_call_detail'          => toolGetCallDetail($args),
        'get_call_transcript'      => toolGetCallTranscript($args),
        'list_transcript_failures' => toolListTranscriptFailures($args),
        'get_call_stats'           => toolGetCallStats($args),
        default => throw new \RuntimeException("未知工具：{$name}")
    };
}

function toolQueryCalls(array $args): string
{
    $query = Db::connect('db_wachat')->table('csr_sip_log');

    if (!empty($args['project_id'])) {
        $query->where('project_id', $args['project_id']);
    }
    if (!empty($args['agent_name'])) {
        $query->where('agent_name', $args['agent_name']);
    }
    if (!empty($args['customer_phone'])) {
        $query->where('customerPhone', 'like', $args['customer_phone'] . '%');
    }
    if (!empty($args['date_from'])) {
        $query->where('disconnectTimestamp', '>=', $args['date_from'] . ' 00:00:00');
    }
    if (!empty($args['date_to'])) {
        $query->where('disconnectTimestamp', '<=', $args['date_to'] . ' 23:59:59');
    }
    if (isset($args['has_recording']) && $args['has_recording'] === true) {
        $query->where('mediaStatus', 'AVAILABLE');
    }

    $limit = min((int)($args['limit'] ?? 20), 100);

    $rows = $query->field('contact_id,project_id,agent_name,customerPhone,systemPhone,initiationTimestamp,disconnectTimestamp,duration_minutes,queueName,mediaStatus,transcriptStatus,is_voicemail,state,timezone')
        ->order('disconnectTimestamp', 'desc')
        ->limit($limit)
        ->select()
        ->toArray();

    if (empty($rows)) {
        return '没有找到符合条件的通话记录';
    }

    $lines = ["找到 " . count($rows) . " 条通话记录："];
    foreach ($rows as $r) {
        $recording = $r['mediaStatus'] === 'AVAILABLE' ? '有录音' : '无录音';
        $transcript = $r['transcriptStatus'] === 'COMPLETED' ? '已转录' : $r['transcriptStatus'];
        $lines[] = sprintf(
            "  [%s] %s | 坐席:%s | 客户:%s | 时长:%s分钟 | %s | %s | 挂断:%s",
            $r['contact_id'],
            $r['project_id'] ?: '未知项目',
            $r['agent_name'] ?: '未知',
            $r['customerPhone'],
            $r['duration_minutes'],
            $recording,
            $transcript,
            $r['disconnectTimestamp']
        );
    }
    return implode("\n", $lines);
}

function toolGetCallDetail(array $args): string
{
    $contactId = $args['contact_id'] ?? '';
    if ($contactId === '') {
        return '错误：缺少 contact_id 参数';
    }

    $row = Db::connect('db_wachat')->table('csr_sip_log')
        ->where('contact_id', $contactId)
        ->find();

    if (!$row) {
        return "未找到 contact_id 为 {$contactId} 的通话记录";
    }

    $fields = [
        'contact_id' => '通话ID',
        'project_id' => '项目ID',
        'agent_name' => '坐席',
        'customerPhone' => '客户电话',
        'systemPhone' => '系统电话',
        'initiationTimestamp' => '呼入时间',
        'disconnectTimestamp' => '挂断时间',
        'duration' => '通话秒数',
        'duration_minutes' => '通话分钟',
        'queuedDuration' => '排队秒数',
        'queueName' => '队列',
        'mediaStatus' => '录音状态',
        'transcriptStatus' => '转录状态',
        'recordingUrl' => '录音地址',
        'is_voicemail' => '是否留言',
        'conversation_turns' => '对话轮次',
        'state' => '州',
        'timezone' => '时区',
        'project_date' => '项目日期',
        'create_time' => '入库时间',
    ];

    $lines = ["通话详情 [{$contactId}]："];
    foreach ($fields as $field => $label) {
        if (isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null) {
            $value = $row[$field];
            if ($field === 'is_voicemail') {
                $value = $value ? '是' : '否';
            }
            $lines[] = "  {$label}：{$value}";
        }
    }
    return implode("\n", $lines);
}

function toolGetCallTranscript(array $args): string
{
    $contactId = $args['contact_id'] ?? '';
    if ($contactId === '') {
        return '错误：缺少 contact_id 参数';
    }

    $row = Db::connect('db_wachat')->table('csr_sip_log')
        ->field('contact_id,recordingText,transcriptStatus,mediaStatus,is_voicemail,conversation_turns')
        ->where('contact_id', $contactId)
        ->find();

    if (!$row) {
        return "未找到 contact_id 为 {$contactId} 的通话记录";
    }

    if ($row['mediaStatus'] !== 'AVAILABLE') {
        return "该通话没有录音，无法提供转录文本（mediaStatus: {$row['mediaStatus']}）";
    }

    if ($row['transcriptStatus'] !== 'COMPLETED') {
        return "转录尚未完成，当前状态：{$row['transcriptStatus']}";
    }

    if (empty($row['recordingText'])) {
        return "转录状态为 COMPLETED，但文本为空";
    }

    $header = "通话 {$contactId} 转录文本（对话轮次：{$row['conversation_turns']}，是否留言：" . ($row['is_voicemail'] ? '是' : '否') . "）：";
    return $header . "\n" . $row['recordingText'];
}

function toolListTranscriptFailures(array $args): string
{
    $hoursBack = min((int)($args['hours_back'] ?? 24), 720);
    $limit = min((int)($args['limit'] ?? 20), 100);

    $query = Db::connect('db_wachat')->table('csr_sip_log')
        ->where('mediaStatus', 'AVAILABLE')
        ->whereIn('transcriptStatus', ['ResourceNotFoundException', 'FAILED', ''])
        ->where('disconnectTimestamp', '>=', date('Y-m-d H:i:s', time() - 3600 * $hoursBack));

    if (!empty($args['aws_instance'])) {
        $query->where('aws_instance', $args['aws_instance']);
    }

    $rows = $query->field('contact_id,aws_instance,disconnectTimestamp,transcriptStatus,project_id,agent_name')
        ->order('disconnectTimestamp', 'desc')
        ->limit($limit)
        ->select()
        ->toArray();

    if (empty($rows)) {
        return "最近 {$hoursBack} 小时内没有转录失败的记录";
    }

    $lines = ["最近 {$hoursBack} 小时内转录失败的记录（共 " . count($rows) . " 条）："];
    foreach ($rows as $r) {
        $lines[] = sprintf(
            "  [%s] 实例:%s | 项目:%s | 坐席:%s | 挂断:%s | 状态:%s",
            $r['contact_id'],
            parse_url($r['aws_instance'], PHP_URL_HOST) ?: $r['aws_instance'],
            $r['project_id'] ?: '未知',
            $r['agent_name'] ?: '未知',
            $r['disconnectTimestamp'],
            $r['transcriptStatus'] ?: '空'
        );
    }
    return implode("\n", $lines);
}

function toolGetCallStats(array $args): string
{
    $query = Db::connect('db_wachat')->table('csr_sip_log');

    if (!empty($args['project_id'])) {
        $query->where('project_id', $args['project_id']);
    }
    if (!empty($args['agent_name'])) {
        $query->where('agent_name', $args['agent_name']);
    }
    if (!empty($args['date_from'])) {
        $query->where('disconnectTimestamp', '>=', $args['date_from'] . ' 00:00:00');
    }
    if (!empty($args['date_to'])) {
        $query->where('disconnectTimestamp', '<=', $args['date_to'] . ' 23:59:59');
    }

    $groupBy = $args['group_by'] ?? 'project';

    $fieldMap = [
        'project' => 'project_id',
        'agent'   => 'agent_name',
        'date'    => 'project_date',
    ];
    $groupField = $fieldMap[$groupBy] ?? 'project_id';

    $rows = $query->field("{$groupField} as group_key, COUNT(*) as call_count, SUM(duration) as total_duration, AVG(duration) as avg_duration, SUM(CASE WHEN mediaStatus='AVAILABLE' THEN 1 ELSE 0 END) as recording_count, SUM(CASE WHEN is_voicemail=1 THEN 1 ELSE 0 END) as voicemail_count")
        ->group($groupField)
        ->order('call_count', 'desc')
        ->limit(50)
        ->select()
        ->toArray();

    if (empty($rows)) {
        return '没有找到符合条件的统计数据';
    }

    $label = ['project' => '项目', 'agent' => '坐席', 'date' => '日期'][$groupBy] ?? '分组';
    $lines = ["通话统计（按{$label}）："];
    foreach ($rows as $r) {
        $totalMin = round($r['total_duration'] / 60, 1);
        $avgMin = round($r['avg_duration'] / 60, 1);
        $lines[] = sprintf(
            "  %s：%d 通 | 总时长 %s 分钟 | 平均 %s 分钟 | 有录音 %d 通 | 留言 %d 通",
            $r['group_key'] ?: '未知',
            $r['call_count'],
            $totalMin,
            $avgMin,
            $r['recording_count'],
            $r['voicemail_count']
        );
    }
    return implode("\n", $lines);
}

// ============================================================
// JSON-RPC 协议处理
// ============================================================
function handleRequest(array $request): ?array
{
    $id      = $request['id'] ?? null;
    $method  = $request['method'] ?? '';
    $params  = $request['params'] ?? [];
    $isNotification = !array_key_exists('id', $request);

    $result = null;
    $error  = null;

    try {
        switch ($method) {
            case 'initialize':
                $result = [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'AWSC_Call_MCP', 'version' => '1.0.0']
                ];
                break;

            case 'notifications/initialized':
                return null;

            case 'tools/list':
                $result = ['tools' => getToolDefinitions()];
                break;

            case 'tools/call':
                $text = executeTool($params['name'] ?? '', $params['arguments'] ?? []);
                $result = ['content' => [['type' => 'text', 'text' => $text]]];
                break;

            case 'ping':
                $result = new \stdClass();
                break;

            default:
                $error = ['code' => -32601, 'message' => "Method not found: {$method}"];
        }
    } catch (\Throwable $e) {
        $error = ['code' => -32603, 'message' => $e->getMessage()];
    }

    if ($isNotification) return null;

    $response = ['jsonrpc' => '2.0', 'id' => $id];
    if ($error !== null) {
        $response['error'] = $error;
    } else {
        $response['result'] = $result;
    }
    return $response;
}