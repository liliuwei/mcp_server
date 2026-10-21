<?php
/**
 * ============================================================================
 * AWSC 通话记录 MCP Server（HTTP 模式）—— MCP 协议 2025-11-25
 * ============================================================================
 *
 * 【这个文件是干什么的】
 * 把 wachat.csr_sip_log 表封装成 MCP 服务，让 AI 客户端（Claude Desktop、Cursor、
 * 你的知识库系统等）能通过标准 HTTP 接口查询通话记录、录音、转录文本和统计数据。
 *
 * 【MCP 协议版本：2025-11-25（legacy / 握手式）】
 *   本文件按 MCP 2025-11-25 修订版实现，要点：
 *     1. 保留 initialize 握手与 notifications/initialized 通知；保留 ping。
 *     2. 版本协商：initialize 时若客户端请求的版本受支持，则回同版本；
 *        否则回本服务支持的最新版本（2025-11-25）。
 *     3. tools/call 同时返回 content（文本）与 structuredContent（结构化数据）；
 *        注意 structuredContent 的**根必须是 JSON 对象**（2025-11-25 的约束）。
 *     4. 输入校验错误（缺参数等）按 Tool Execution Error 返回，即 isError=true，
 *        而不是协议级 error —— 这样模型能看到错误并自我纠正。
 *     5. 握手之后，客户端应在所有请求上带 MCP-Protocol-Version 请求头。
 *     6. JSON Schema 2020-12 为默认方言（inputSchema 已显式声明 $schema）。
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
 * 3. 配置 Nginx 传递请求头（用 Header 传 Token / 传协议版本头时才需要）
 *    你的环境是宝塔 + PHP 8.2，PHP 处理通过 include enable-php-82.conf 引入。
 *    在站点配置 server { } 块内，include enable-php-82.conf; 之前，加一段：
 *
 *    location = /awsc_chart_mcp.php {
 *        fastcgi_param HTTP_AUTHORIZATION        $http_authorization;
 *        fastcgi_param HTTP_MCP_PROTOCOL_VERSION $http_mcp_protocol_version;
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
 * PHP 调用示例（注意：结构化数据在 result.structuredContent，文本在 result.content[0].text）：
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
 * $rows  = $result['result']['structuredContent']['calls'];   // 结构化数据
 * $text  = $result['result']['content'][0]['text'];           // 人类可读文本
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
 *    structuredContent: { calls: [...], count: N }
 *
 * 2. get_call_detail
 *    根据 contact_id 查单条通话的完整详情。
 *    参数：contact_id（必填）
 *    structuredContent: { call: {...} | null }
 *
 * 3. get_call_transcript
 *    获取指定通话的转录文本。
 *    参数：contact_id（必填）
 *    structuredContent: { contact_id, media_status, transcript_status, is_voicemail, conversation_turns, text }
 *
 * 4. list_transcript_failures
 *    列出转录失败的通话记录（用于排查采集问题）。
 *    - aws_instance    AWS 实例地址（可选）
 *    - hours_back      往前查多少小时，默认 24
 *    - limit           返回条数，默认 20
 *    structuredContent: { hours_back, failures: [...] }
 *
 * 5. get_call_stats
 *    按项目/坐席/日期统计通话量。
 *    - project_id      项目 ID
 *    - agent_name      坐席名称
 *    - date_from       开始日期
 *    - date_to         结束日期
 *    - group_by        统计维度：project / agent / date，默认 project
 *    structuredContent: { group_by, stats: [...] }
 *
 * 【MCP 协议方法】
 *
 * 客户端会发以下 JSON-RPC 请求：
 * - initialize                  握手（含版本协商）
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
// MCP 2025-11-25 协议常量
// ============================================================

const MCP_PROTOCOL_VERSION   = '2025-11-25';
const MCP_SUPPORTED_VERSIONS = ['2025-11-25'];

const SERVER_NAME    = 'AWSC_Call_MCP';
const SERVER_TITLE   = 'AWSC 通话记录（只读）';
const SERVER_VERSION = '2.3.0';

const JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

const SERVER_INSTRUCTIONS = 'AWSC 通话记录只读服务：通话列表、单条详情、转录文本、转录失败排查、通话统计。'
    . '时间字段为数据库时间；duration 单位为秒，duration_minutes 单位为分钟。';

// content 文本最大字符数（超出会截断；structuredContent 不受限制）
const MAX_TEXT_CHARS = 50000;

// JSON-RPC 错误码
const ERR_PARSE            = -32700;
const ERR_INVALID_PARAMS   = -32602;
const ERR_METHOD_NOT_FOUND = -32601;
const ERR_INTERNAL         = -32603;

// ============================================================
// HTTP 入口
// ============================================================
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32600, 'message' => 'Method not allowed']]);
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
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32000, 'message' => 'Unauthorized: token mismatch']]);
    exit;
}

$rawInput = file_get_contents('php://input');
$request = json_decode($rawInput, true);

if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => ERR_PARSE, 'message' => 'Parse error']]);
    exit;
}

// 2025-11-25：握手之后客户端应在每个请求上带 MCP-Protocol-Version 头
$hdrVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '';

$response = handleRequest($request, $hdrVersion);

if ($response !== null) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit;

// ============================================================
// MCP 协议层（2025-11-25，握手式）
// ============================================================

/**
 * 处理一次 JSON-RPC 请求
 *
 * @param array  $request    JSON-RPC 请求体
 * @param string $hdrVersion MCP-Protocol-Version 请求头
 * @return array|null        通知类请求返回 null（不响应）
 */
function handleRequest(array $request, string $hdrVersion = ''): ?array
{
    $id     = $request['id'] ?? null;
    $method = (string) ($request['method'] ?? '');
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];

    $isNotification = !array_key_exists('id', $request);

    // 协议版本头校验（initialize 之后才有意义；缺失则宽松放行）
    if ($method !== 'initialize' && $hdrVersion !== ''
        && !in_array($hdrVersion, MCP_SUPPORTED_VERSIONS, true)) {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => ERR_INVALID_PARAMS,
                'message' => 'Unsupported MCP-Protocol-Version header',
                'data'    => ['supported' => MCP_SUPPORTED_VERSIONS, 'requested' => $hdrVersion],
            ],
        ];
    }

    $result = null;
    $error  = null;

    try {
        switch ($method) {
            case 'initialize':
                // 版本协商：客户端版本受支持则回同版本，否则回本服务最新版本
                $clientVersion = (string) ($params['protocolVersion'] ?? '');
                $agreed = in_array($clientVersion, MCP_SUPPORTED_VERSIONS, true)
                    ? $clientVersion
                    : MCP_PROTOCOL_VERSION;

                $result = [
                    'protocolVersion' => $agreed,
                    'capabilities'    => ['tools' => new \stdClass()],
                    'serverInfo'      => [
                        'name'        => SERVER_NAME,
                        'title'       => SERVER_TITLE,
                        'version'     => SERVER_VERSION,
                        'description' => 'AWSC 通话记录只读 MCP 服务（通话/录音/转录/统计）。',
                    ],
                    'instructions'    => SERVER_INSTRUCTIONS,
                ];
                break;

            case 'notifications/initialized':
                return null;

            case 'tools/list':
                $result = ['tools' => getToolDefinitions()];
                break;

            case 'tools/call':
                $res = executeTool(
                    (string) ($params['name'] ?? ''),
                    is_array($params['arguments'] ?? null) ? $params['arguments'] : []
                );

                $payload = ['content' => [['type' => 'text', 'text' => $res['text']]]];
                // 输入校验错误 / 查询失败 → Tool Execution Error
                if (!empty($res['isError'])) {
                    $payload['isError'] = true;
                }
                // 结构化结果（2025-11-25 要求根为 JSON 对象）
                if (array_key_exists('data', $res) && $res['data'] !== null) {
                    $payload['structuredContent'] = ensureObject($res['data']);
                }
                $result = $payload;
                break;

            case 'ping':
                $result = new \stdClass();
                break;

            default:
                $error = ['code' => ERR_METHOD_NOT_FOUND, 'message' => "Method not found: {$method}"];
        }
    } catch (\Throwable $e) {
        $error = ['code' => ERR_INTERNAL, 'message' => $e->getMessage()];
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

/**
 * 保证 structuredContent 的根是 JSON 对象（2025-11-25 的硬性约束）。
 * 标量 → 包一层；顺序数组 → 包成 {"items": [...]}。
 */
function ensureObject($data): array
{
    if (!is_array($data)) {
        return ['value' => $data];
    }
    if (array_is_list($data)) {
        return ['items' => $data];
    }
    return $data;
}

/** 输入校验错误的统一输出（2025-11-25：按 Tool Execution Error 返回） */
function toolError(string $message): array
{
    return [
        'text'    => $message,
        'data'    => ['error' => ['message' => $message]],
        'isError' => true,
    ];
}

// ============================================================
// 中文键值对渲染（content.text 用；structuredContent 仍保留原始数据）
// ============================================================

/** 英文字段名 → 中文标签（未收录的回退为原字段名） */
function fieldLabels(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        // 集合
        'calls' => '通话记录',
        'call' => '通话详情',
        'failures' => '转录失败记录',
        'stats' => '统计数据',
        'count' => '条数',
        'hours_back' => '回溯小时数',
        'group_by' => '统计维度',
        'group_key' => '分组',
        'available' => '是否有转录',
        'text' => '文本',

        // 通话字段
        'contact_id' => '通话 ID',
        'project_id' => '项目 ID',
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
        'media_status' => '录音状态',
        'transcript_status' => '转录状态',
        'recordingUrl' => '录音地址',
        'recordingText' => '转录文本',
        'is_voicemail' => '是否留言',
        'conversation_turns' => '对话轮次',
        'state' => '州',
        'timezone' => '时区',
        'project_date' => '项目日期',
        'create_time' => '入库时间',
        'aws_instance' => 'AWS 实例',
        'id' => '记录 ID',
        'channel' => '渠道',
        'userName' => '坐席账号',

        // 统计字段
        'call_count' => '通话数',
        'total_duration' => '总时长(秒)',
        'avg_duration' => '平均时长(秒)',
        'total_minutes' => '总时长(分钟)',
        'avg_minutes' => '平均时长(分钟)',
        'recording_count' => '有录音数',
        'voicemail_count' => '留言数',
    ];
    return $map;
}

/** 取字段中文标签（未收录的回退为原字段名） */
function fieldLabel(string $key): string
{
    $map = fieldLabels();
    return $map[$key] ?? $key;
}

/** 枚举值翻译（按字段名区分；未命中返回 null，表示按原值展示） */
function enumLabel(string $key, string $value): ?string
{
    static $enums = [
        'mediaStatus' => ['AVAILABLE' => '有录音', 'no media' => '无录音'],
        'media_status' => ['AVAILABLE' => '有录音', 'no media' => '无录音'],
        'transcriptStatus' => [
            'COMPLETED' => '已完成', 'FAILED' => '失败',
            'ResourceNotFoundException' => '资源不存在',
            'no text' => '无文本',
        ],
        'transcript_status' => [
            'COMPLETED' => '已完成', 'FAILED' => '失败',
            'ResourceNotFoundException' => '资源不存在',
            'no text' => '无文本',
        ],
        'group_by' => ['project' => '项目', 'agent' => '坐席', 'date' => '日期'],
    ];
    return $enums[$key][$value] ?? null;
}

/** 标量值 → 中文文本 */
function scalarText(string $key, $value): string
{
    if ($value === null) {
        return '空';
    }
    // 数据库里 is_voicemail 是 0/1
    if ($key === 'is_voicemail') {
        return ($value === 1 || $value === '1' || $value === true) ? '是' : '否';
    }
    if (is_bool($value)) {
        return $value ? '是' : '否';
    }
    if (is_string($value)) {
        if ($value === '') {
            return '空';
        }
        $enum = enumLabel($key, $value);
        return $enum === null ? $value : "{$value}（{$enum}）";
    }
    return (string) $value;
}

/** 递归渲染为中文键值对文本 */
function renderChinese($data, int $depth = 0): string
{
    $indent = str_repeat('  ', $depth);

    if (!is_array($data)) {
        return $indent . scalarText('', $data) . "\n";
    }
    if (empty($data)) {
        return $indent . '（空）' . "\n";
    }

    $out = '';
    if (array_is_list($data)) {
        foreach ($data as $i => $item) {
            if (is_array($item) && !empty($item)) {
                $out .= $indent . '第 ' . ($i + 1) . ' 项：' . "\n";
                $out .= renderChinese($item, $depth + 1);
            } elseif (is_array($item)) {
                $out .= $indent . '第 ' . ($i + 1) . ' 项：（空）' . "\n";
            } else {
                $out .= $indent . '- ' . scalarText('', $item) . "\n";
            }
        }
        return $out;
    }

    foreach ($data as $key => $value) {
        $key   = (string) $key;
        $label = fieldLabel($key);
        if (is_array($value)) {
            if (empty($value)) {
                $out .= $indent . $label . '：（空）' . "\n";
            } elseif (array_is_list($value)) {
                $out .= $indent . $label . '（共 ' . count($value) . ' 项）：' . "\n";
                $out .= renderChinese($value, $depth + 1);
            } else {
                $out .= $indent . $label . '：' . "\n";
                $out .= renderChinese($value, $depth + 1);
            }
        } else {
            $out .= $indent . $label . '：' . scalarText($key, $value) . "\n";
        }
    }
    return $out;
}

/** 组装工具返回：text（中文键值对） + data（原始数据） */
function formatResult(string $summary, $data): array
{
    $body = renderChinese($data);

    $truncated = false;
    if (mb_strlen($body) > MAX_TEXT_CHARS) {
        $body      = mb_substr($body, 0, MAX_TEXT_CHARS);
        $truncated = true;
    }

    $out = $summary . "\n\n" . $body;
    if ($truncated) {
        $out .= "\n...（返回内容过长已截断，请用 limit 缩小结果）";
    }

    return ['text' => $out, 'data' => $data, 'isError' => false];
}

// ============================================================
// 工具定义
// ============================================================
function getToolDefinitions(): array
{
    return [
        [
            'name' => 'query_calls',
            'title' => '查询通话记录',
            'description' => '查询亚马逊 Connect 通话记录。支持按项目、坐席、客户电话、时间范围、是否有录音等条件筛选。当用户询问某段时间的通话、某坐席的通话记录时使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
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
            'title' => '查询通话详情',
            'description' => '根据 contact_id 获取单条通话的完整详情，包括录音 URL、转录状态、通话时长、客户信息等。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'string', 'description' => '通话记录 ID']
                ],
                'required' => ['contact_id']
            ]
        ],
        [
            'name' => 'get_call_transcript',
            'title' => '获取通话转录文本',
            'description' => '获取指定通话的转录文本（对话内容）。如果尚未转录完成，会返回状态说明。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'string', 'description' => '通话记录 ID']
                ],
                'required' => ['contact_id']
            ]
        ],
        [
            'name' => 'list_transcript_failures',
            'title' => '列出转录失败记录',
            'description' => '列出转录状态异常的通话记录（如 ResourceNotFoundException），用于排查采集失败的通话。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
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
            'title' => '通话量统计',
            'description' => '按项目或坐席统计通话量、总时长、平均时长等指标。当用户询问“某项目今天打了多少电话”、“某坐席的通话统计”时使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
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

/** @return array{text:string,data:mixed,isError:bool} */
function executeTool(string $name, array $args): array
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

function toolQueryCalls(array $args): array
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
        return formatResult('没有找到符合条件的通话记录', ['count' => 0, 'calls' => []]);
    }

    return formatResult(
        '找到 ' . count($rows) . ' 条通话记录',
        ['count' => count($rows), 'calls' => $rows]
    );
}

function toolGetCallDetail(array $args): array
{
    $contactId = $args['contact_id'] ?? '';
    if ($contactId === '') {
        return toolError('错误：缺少 contact_id 参数');
    }

    $row = Db::connect('db_wachat')->table('csr_sip_log')
        ->where('contact_id', $contactId)
        ->find();

    if (!$row) {
        return formatResult("未找到 contact_id 为 {$contactId} 的通话记录", ['call' => null]);
    }

    return formatResult("通话详情 [{$contactId}]", ['call' => $row]);
}

function toolGetCallTranscript(array $args): array
{
    $contactId = $args['contact_id'] ?? '';
    if ($contactId === '') {
        return toolError('错误：缺少 contact_id 参数');
    }

    $row = Db::connect('db_wachat')->table('csr_sip_log')
        ->field('contact_id,recordingText,transcriptStatus,mediaStatus,is_voicemail,conversation_turns')
        ->where('contact_id', $contactId)
        ->find();

    if (!$row) {
        return formatResult("未找到 contact_id 为 {$contactId} 的通话记录", ['call' => null]);
    }

    $base = [
        'contact_id'         => $contactId,
        'media_status'       => $row['mediaStatus'],
        'transcript_status'  => $row['transcriptStatus'],
        'is_voicemail'       => (bool) $row['is_voicemail'],
        'conversation_turns' => $row['conversation_turns'],
    ];

    if ($row['mediaStatus'] !== 'AVAILABLE') {
        return formatResult(
            "该通话没有录音，无法提供转录文本（mediaStatus: {$row['mediaStatus']}）",
            $base + ['available' => false, 'text' => null]
        );
    }

    if ($row['transcriptStatus'] !== 'COMPLETED') {
        return formatResult(
            "转录尚未完成，当前状态：{$row['transcriptStatus']}",
            $base + ['available' => false, 'text' => null]
        );
    }

    if (empty($row['recordingText'])) {
        return formatResult(
            '转录状态为 COMPLETED，但文本为空',
            $base + ['available' => true, 'text' => '']
        );
    }

    return formatResult(
        "通话 {$contactId} 转录文本",
        $base + ['available' => true, 'text' => $row['recordingText']]
    );
}

function toolListTranscriptFailures(array $args): array
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
        return formatResult(
            "最近 {$hoursBack} 小时内没有转录失败的记录",
            ['hours_back' => $hoursBack, 'count' => 0, 'failures' => []]
        );
    }

    return formatResult(
        "最近 {$hoursBack} 小时内转录失败的记录（共 " . count($rows) . " 条）",
        ['hours_back' => $hoursBack, 'count' => count($rows), 'failures' => $rows]
    );
}

function toolGetCallStats(array $args): array
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
        return formatResult(
            '没有找到符合条件的统计数据',
            ['group_by' => $groupBy, 'count' => 0, 'stats' => []]
        );
    }

    // 补上"分钟"口径，并收敛平均时长的精度，便于阅读
    foreach ($rows as &$r) {
        $r['total_minutes'] = round(((float) $r['total_duration']) / 60, 1);
        $r['avg_minutes']   = round(((float) $r['avg_duration']) / 60, 1);
        $r['avg_duration']  = round((float) $r['avg_duration'], 2);
    }
    unset($r);

    $label = ['project' => '项目', 'agent' => '坐席', 'date' => '日期'][$groupBy] ?? '分组';

    return formatResult(
        "通话统计（按{$label}）",
        ['group_by' => $groupBy, 'count' => count($rows), 'stats' => $rows]
    );
}
