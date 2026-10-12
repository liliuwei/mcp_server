<?php
/**
 * ============================================================================
 * SHOPLINE Admin REST API MCP Server（HTTP 模式）—— MCP 协议 2025-11-25
 * ============================================================================
 *
 * 【这个文件是干什么的】
 * 把 SHOPLINE 的 Admin REST API（订单 / 客户 / 商品 / 库存 / 履约单）封装成 MCP 服务，
 * 让 AI 客户端（Claude Desktop、Cursor、Cherry Studio、你的知识库系统等）通过标准
 * JSON-RPC over HTTP 接口读取店铺数据。
 *
 * 本文件只封装「只读」接口，对应以下权限：
 *   read_orders, read_assigned_fulfillment_orders, read_customers,
 *   read_products, read_inventory
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
 *   另见：shopline_mcp_v2.php —— 同一批工具，但跑 2026-07-28 的无状态现代协议。
 *
 * 【SHOPLINE 接口基础信息】
 *   请求地址：https://{handle}.myshopline.com/admin/openapi/{version}/xxx.json
 *   鉴权方式：Authorization: Bearer {access_token}
 *   响应上限：单次 8MB，超出需用分页
 *   分页方式：响应头 Link 里的 page_info 游标（不是页码）
 *
 * 【部署步骤】
 *
 * 1. 改配置（见下方「配置」区）
 *    - $SHOPLINE_SHOP_HANDLE  ← 必填！店铺域名前缀。
 *    - $SHOPLINE_ACCESS_TOKEN ← 填你申请到的访问令牌
 *    - $MCP_TOKEN             ← 建议改成你自己的强随机字符串（保护这个接口）
 *
 * 2. 上传文件到 Web 根目录，例如 /www/wwwroot/your-site/shopline_mcp.php
 *
 * 3. 配置 Nginx 传递请求头（用 Header 传 MCP Token / 传协议版本头时才需要）
 *    宝塔 + PHP 环境，在站点 server { } 块内、include enable-php-82.conf; 之前加：
 *
 *    location = /shopline_mcp.php {
 *        fastcgi_param HTTP_AUTHORIZATION        $http_authorization;
 *        fastcgi_param HTTP_MCP_PROTOCOL_VERSION $http_mcp_protocol_version;
 *        include enable-php-82.conf;
 *    }
 *
 * 4. 重载 Nginx：nginx -t && nginx -s reload
 *
 * 【鉴权方式（三种任选其一）】
 *   1. Header 传 Token（推荐，需要配 Nginx）
 *      -H "Authorization: Bearer your-mcp-token"
 *   2. URL 参数传 Token（不需要配 Nginx，但不安全）
 *      ?token=your-mcp-token
 *   3. 不传 Token（直接放行，仅建议内网/测试）
 *   注意：只要传了 Token 就必须匹配，不匹配返回 401；完全不传才放行。
 *
 * 【自检】
 *   部署后先调 shopline_ping 工具，能返回订单数据就说明配置正确。
 *
 * 【实测结论（已用你的令牌验证）】
 *   ✓ orders.json                          read_orders                    → 200
 *   ✓ products/products.json               read_products                  → 200
 *   ✓ products/{id}.json                   read_products                  → 200
 *   ✓ v2/customers.json                    read_customers                 → 200
 *   ✓ customers/v2/{id}.json               read_customers                 → 200
 *   ✓ fulfillment_orders/..._search.json   read_assigned_fulfillment_orders → 200（location_ids 必填）
 *   ✗ locations/list.json                  需「地点读取」权限，当前令牌 403
 *   ! inventory_levels.json / inventory_items.json 的 ids 参数为必填（read_inventory）
 *
 *   注意：SHOPLINE 的 handle 与令牌强绑定。用错 handle 会返回
 *   401 ACCESS_TOKEN is invalid! / 403 ACCESS_TOKEN is no permission!
 *
 *   【隐藏订单 hidden_order（重要）】
 *   SHOPLINE 存在「隐藏订单」（hidden_order=true，常见于测试单、特殊渠道单、
 *   Google Ads 等场景），默认订单列表不会返回它们。
 *   关键：该参数的语义是「过滤」而不是「包含」——
 *     hidden_order=true        → 只返回隐藏订单
 *     hidden_order=false / 不传 → 只返回非隐藏订单
 *   （两类订单无法在一次请求中同时返回，所以不能简单地"默认加上 true"，
 *     那样反而会把正常订单全部过滤掉。）
 *   本服务的处理策略：订单查询（列表 list_orders 与详情 get_order）一律
 *   先查非隐藏单；若结果为空，再自动补查隐藏单，从而兼顾两类订单、避免漏单。
 *   补查命中与否，返回结果不做额外标注。
 *   如需只查隐藏订单，显式传 hidden_order=true（此时不做兜底）。
 *   另注：orders.json 的 limit 上限为 100（传 >100 会 500）；商品接口上限 250，
 *   客户/库存等接口更宽松。各工具已按对应上限自动截断。
 *
 * 【可用工具】
 *   1.  shopline_ping             连通性/凭据自检
 *   2.  list_orders               查询订单列表
 *   3.  get_order                 按订单 ID 或订单号查询订单详情
 *   4.  list_products             查询商品列表
 *   5.  get_product               按商品 ID 查询商品详情
 *   6.  list_customers            查询客户列表
 *   7.  get_customer              按客户 ID 查询客户详情
 *   8.  list_locations            查询库存地点列表
 *   9.  get_inventory_levels      查询库存数量（按库存单位/地点）
 *   10. list_inventory_items      查询库存单位（SKU 维度）
 *   11. list_fulfillment_orders   查询履约单列表
 *   12. shopline_api_request      只读通用请求（白名单路径，兜底用）
 *
 * 【安全提醒】
 *   1. 本文件包含访问令牌，务必确保 Web 服务器禁止下载 .php 源码。
 *   2. 客户数据（手机号、邮箱、地址）属于个人隐私，注意合规使用。
 *   3. 建议在 Nginx 层加频率限制：
 *      limit_req_zone $binary_remote_addr zone=mcp:10m rate=10r/s;
 *      location = /shopline_mcp.php { limit_req zone=mcp burst=20 nodelay; ... }
 *
 * ============================================================================
 */

// 本文件只用 PHP 原生 cURL，不依赖第三方库；如果同目录存在 vendor 则一并加载
$__autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($__autoload)) {
    require_once $__autoload;
}

// ============================================================
// 配置
// ============================================================

// MCP 接口自身的访问令牌（保护这个 HTTP 接口，建议改成强随机字符串）
$MCP_TOKEN = 'shopline-mcp-2026-secret-token';

// SHOPLINE 店铺 handle：店铺域名前缀
// 例：后台地址是 https://open001.myshopline.com，则填 open001
// 本令牌对应店铺的 handle 为 mileseeygolf（open001 亦可访问同一店铺）
$SHOPLINE_SHOP_HANDLE = 'mileseeygolf';

// SHOPLINE 访问令牌（App 授权后获得，形如 eyJhbGciOi...）
$SHOPLINE_ACCESS_TOKEN = 'eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJhcHBJZCI6MCwiYXBwS2V5IjoiOWU4OGI5ZTEyNmMyNzRkYmUwNzdkZmExYzhjYTdmMTIzYmQ0MzQ5YiIsImV4cCI6MTg4NjIyMTQ5MCwiaXNzIjoieXNvdWwiLCJzZWxsZXJJZCI6IjI0MTIwNDAyMDUiLCJzdG9yZUlkIjoiMTczMzU2MjI2ODU1NyIsInRpbWVzdGFtcCI6MTc5MTUyNzA5MDYzNCwidmVyc2lvbiI6IlYyIn0.ydQRSK5unaKuUm_XP_bTtx8KCbu9iGlXz8SXG5taU8yFwU6Rna-JrYuw5O3xFm7m_lfRjo1A3H8X-QVo3acG0g';

// API 版本（v20260901 为当前稳定版）
$SHOPLINE_API_VERSION = 'v20260901';

// 单次请求超时（秒）
$SHOPLINE_TIMEOUT = 30;

// content 文本正文最大字符数（structuredContent 不受此限制）
$MAX_OUTPUT_CHARS = 50000;

// ============================================================
// MCP 2025-11-25 协议常量
// ============================================================

const MCP_PROTOCOL_VERSION   = '2025-11-25';
const MCP_SUPPORTED_VERSIONS = ['2025-11-25'];

const SERVER_NAME    = 'SHOPLINE_Admin_MCP';
const SERVER_TITLE   = 'SHOPLINE 店铺数据（只读）';
const SERVER_VERSION = '2.2.0';

const JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

const SERVER_INSTRUCTIONS = 'SHOPLINE 店铺数据只读服务：订单、客户、商品、库存、履约单。'
    . '订单查询（列表/详情）一律先查非隐藏订单，若为空再自动补查隐藏订单（hidden_order），避免漏单。'
    . '分页用返回的 page_info 游标。建议先调用 shopline_ping 验证连通性。';

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

// ---------- 鉴权：Header / URL 参数 / 不传 ----------
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$tokenFromHeader = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $tokenFromHeader = $m[1];
}
$tokenFromUrl  = $_GET['token'] ?? '';
$providedToken = $tokenFromHeader !== '' ? $tokenFromHeader : $tokenFromUrl;

if ($providedToken !== '' && !hash_equals($MCP_TOKEN, $providedToken)) {
    http_response_code(401);
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32000, 'message' => 'Unauthorized: token mismatch']]);
    exit;
}

$rawInput = file_get_contents('php://input');
$request  = json_decode($rawInput, true);

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

    // 通知（无 id）不需要响应
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
                        'description' => 'SHOPLINE 店铺数据只读 MCP 服务（订单/客户/商品/库存/履约单）。',
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
                // 输入校验错误 / API 失败 → Tool Execution Error
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

    if ($isNotification) {
        return null;
    }

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

// ============================================================
// SHOPLINE API 调用封装
// ============================================================

/**
 * 发起一次 SHOPLINE Admin REST API 请求
 *
 * @param string $path   形如 orders.json / products/products.json
 * @param array  $query  查询参数
 * @return array{ok:bool,status:int,data:?array,raw:string,link:string,error:?string}
 */
function shoplineRequest(string $path, array $query = []): array
{
    global $SHOPLINE_SHOP_HANDLE, $SHOPLINE_ACCESS_TOKEN, $SHOPLINE_API_VERSION, $SHOPLINE_TIMEOUT;

    if ($SHOPLINE_SHOP_HANDLE === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'link' => '',
                'error' => '未配置店铺 handle。请把 $SHOPLINE_SHOP_HANDLE 填成你的店铺域名前缀。'];
    }
    if ($SHOPLINE_ACCESS_TOKEN === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'link' => '',
                'error' => '未配置访问令牌，请设置 $SHOPLINE_ACCESS_TOKEN。'];
    }

    $path = ltrim($path, '/');
    $url  = sprintf(
        'https://%s.myshopline.com/admin/openapi/%s/%s',
        $SHOPLINE_SHOP_HANDLE,
        $SHOPLINE_API_VERSION,
        $path
    );
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $SHOPLINE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $SHOPLINE_ACCESS_TOKEN,
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
        ],
    ]);

    $resp       = curl_exec($ch);
    $errno      = curl_errno($ch);
    $curlError  = curl_error($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($errno !== 0 || $resp === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '', 'link' => '',
                'error' => "网络请求失败（curl {$errno}）：{$curlError}"];
    }

    $headersRaw = substr($resp, 0, $headerSize);
    $body       = substr($resp, $headerSize);

    // 解析 Link 响应头（分页游标）
    $link = '';
    if (preg_match('/^link:\s*(.+)$/im', $headersRaw, $lm)) {
        $link = trim($lm[1]);
    }

    $data = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        $msg = '';
        if (is_array($data)) {
            $msg = $data['errors'] ?? $data['message'] ?? $data['error'] ?? $data['error_msg']
                 ?? json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $msg = mb_substr($body, 0, 500);
        }
        $hint = '';
        if ($status === 401) {
            $hint = '（访问令牌无效或已过期，请重新授权获取 access_token）';
        } elseif ($status === 403) {
            $hint = '（权限不足或店铺不匹配：请确认 handle 是本令牌对应的店铺，且应用已开通对应 read_* 权限）';
        } elseif ($status === 404) {
            $hint = '（资源不存在，或店铺 handle / API 版本配置有误）';
        } elseif ($status === 429) {
            $hint = '（触发频率限制，请降低调用频率后重试）';
        }
        return ['ok' => false, 'status' => $status, 'data' => null, 'raw' => $body, 'link' => $link,
                'error' => "SHOPLINE 返回 HTTP {$status}：{$msg} {$hint}"];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data, 'raw' => $body, 'link' => $link, 'error' => null];
}

/** 从 Link 头里取出 page_info 游标（供 AI 翻页） */
function extractPageInfo(string $link): string
{
    if ($link === '') {
        return '';
    }
    foreach (explode(',', $link) as $part) {
        if (stripos($part, 'rel="next"') !== false || stripos($part, "rel='next'") !== false) {
            if (preg_match('/page_info=([^&>\s]+)/', $part, $m)) {
                return $m[1];
            }
        }
    }
    return '';
}

/** 去掉空值参数，保持 query 干净 */
function cleanQuery(array $args, array $allowed): array
{
    $q = [];
    foreach ($allowed as $key) {
        if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
            continue;
        }
        $v = $args[$key];
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        $q[$key] = $v;
    }
    return $q;
}

/**
 * 订单查询 + 隐藏订单兜底（列表 / 详情通用）。
 *
 * SHOPLINE 的 hidden_order 参数语义是「过滤」而非「包含」：
 *   hidden_order=true        → 只返回隐藏订单
 *   hidden_order=false/不传  → 只返回非隐藏订单
 * 因此不能直接叠加 true（那样会把正常订单全部过滤掉）。
 *
 * 策略：一律先按原条件查非隐藏单；若 0 条且允许兜底，再自动补查一次隐藏订单。
 *
 * @return array shoplineRequest 的返回结构
 */
function ordersRequestWithHiddenFallback(array $q, bool $allowAutoRetry): array
{
    $r = shoplineRequest('orders.json', $q);
    if (!$r['ok'] || !$allowAutoRetry) {
        return $r;
    }
    if (countList($r['data'], ['orders', 'items', 'data']) > 0) {
        return $r;
    }

    $q2 = $q;
    $q2['hidden_order'] = 'true';
    $r2 = shoplineRequest('orders.json', $q2);
    if ($r2['ok'] && countList($r2['data'], ['orders', 'items', 'data']) > 0) {
        return $r2;
    }

    return $r;
}

/** 统计一个列表返回了多少条 */
function countList(?array $data, array $keys): int
{
    if (!is_array($data)) {
        return 0;
    }
    foreach ($keys as $k) {
        if (isset($data[$k]) && is_array($data[$k])) {
            return count($data[$k]);
        }
    }
    return 0;
}

// ============================================================
// 工具返回结构（text + data + isError）
// ============================================================

/**
 * 组装工具返回：content 文本（摘要 + JSON） + structuredContent（原始数据）
 *
 * structuredContent 不受 $MAX_OUTPUT_CHARS 限制，因此完整数据始终可被程序解析。
 */
function formatResult(string $summary, $data, string $link = ''): array
{
    global $MAX_OUTPUT_CHARS;

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        $json = '(无法序列化返回数据)';
    }

    $truncated = false;
    if (mb_strlen($json) > $MAX_OUTPUT_CHARS) {
        $json      = mb_substr($json, 0, $MAX_OUTPUT_CHARS);
        $truncated = true;
    }

    $out      = $summary;
    $pageInfo = extractPageInfo($link);
    if ($pageInfo !== '') {
        $out .= "\n下一页游标 page_info：{$pageInfo}（把它作为 page_info 参数再次调用即可翻页）";
    }
    $out .= "\n\n" . $json;
    if ($truncated) {
        $out .= "\n\n...（返回内容过长已截断，请用 limit / 时间范围 / 筛选条件缩小结果）";
    }

    // structuredContent：原始数据 + 分页游标（完整、不截断）
    $sc = is_array($data) ? $data : ['data' => $data];
    if ($pageInfo !== '') {
        $sc['_page_info'] = $pageInfo;
    }

    return ['text' => $out, 'data' => $sc, 'isError' => false];
}

/** API 调用失败的统一输出（isError = true） */
function failText(array $r): array
{
    $msg = (string) ($r['error'] ?? '未知错误');
    return [
        'text'    => '调用失败：' . $msg,
        'data'    => ['error' => ['message' => $msg, 'status' => $r['status'] ?? null]],
        'isError' => true,
    ];
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
// 工具定义
// ============================================================
function getToolDefinitions(): array
{
    return [
        [
            'name' => 'shopline_ping',
            'title' => 'SHOPLINE 连通性自检',
            'description' => 'SHOPLINE 连通性与凭据自检。调用一次订单查询（orders.json）验证 handle、访问令牌和 API 版本是否配置正确。部署后或排查问题时应先调用它。',
            'inputSchema' => ['$schema' => JSON_SCHEMA_DIALECT, 'type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
        [
            'name' => 'list_orders',
            'title' => '查询订单列表',
            'description' => '查询 SHOPLINE 订单列表。支持按创建时间、更新时间、订单状态、支付状态、履约状态、订单号、邮箱等条件筛选。当用户询问"最近订单""某天的订单""未发货订单""某客户订单"时使用。注意：SHOPLINE 存在「隐藏订单」（hidden_order），默认不返回；本工具一律先查非隐藏订单，若结果为空再自动补查隐藏订单，避免漏单。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'created_at_min'     => ['type' => 'string', 'description' => '创建时间下限，ISO 8601，如 2026-10-01T00:00:00+08:00'],
                    'created_at_max'     => ['type' => 'string', 'description' => '创建时间上限，ISO 8601'],
                    'updated_at_min'     => ['type' => 'string', 'description' => '更新时间下限，ISO 8601（同步增量数据时推荐）'],
                    'updated_at_max'     => ['type' => 'string', 'description' => '更新时间上限，ISO 8601'],
                    'order_at_min'       => ['type' => 'string', 'description' => '下单时间下限，ISO 8601'],
                    'order_at_max'       => ['type' => 'string', 'description' => '下单时间上限，ISO 8601'],
                    'status'             => ['type' => 'string', 'description' => '订单状态，如 open / closed / cancelled'],
                    'financial_status'   => ['type' => 'string', 'description' => '支付状态，如 paid / pending / refunded / partially_refunded'],
                    'fulfillment_status' => ['type' => 'string', 'description' => '履约状态，如 unfulfilled / partial / fulfilled'],
                    'ids'                => ['type' => 'string', 'description' => '订单 ID 列表，多个用英文逗号分隔'],
                    'name'               => ['type' => 'string', 'description' => '订单号，如 MG3812'],
                    'email'              => ['type' => 'string', 'description' => '买家邮箱'],
                    'buyer_id'           => ['type' => 'string', 'description' => '买家 ID'],
                    'location'           => ['type' => 'string', 'description' => '订单来源地区'],
                    'search_content'     => ['type' => 'string', 'description' => '关键字搜索（订单号、客户等）'],
                    'sort_condition'     => ['type' => 'string', 'description' => '排序条件'],
                    'contract_ids'       => ['type' => 'string', 'description' => '合同/交易单 ID，多个用逗号分隔'],
                    'hidden_order'       => ['type' => 'boolean', 'description' => '隐藏订单过滤开关。SHOPLINE 语义：true=只返回隐藏订单，false/不传=只返回非隐藏订单（无法一次同时返回两类）。一般无需传；本工具已内置「先查非隐藏单，查不到再补查隐藏单」的兜底。'],
                    'limit'              => ['type' => 'integer', 'description' => '返回条数，默认 20，最大 100（orders 接口上限，超出会报错）', 'default' => 20],
                    'since_id'           => ['type' => 'string', 'description' => '只返回 ID 大于该值的记录（增量拉取）'],
                    'page_info'          => ['type' => 'string', 'description' => '分页游标，上一页返回的 page_info'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_order',
            'title' => '查询订单详情',
            'description' => '根据订单 ID 或订单号查询订单完整详情（含商品明细、金额、收货地址、支付与履约信息）。order_id 传纯数字视为订单 ID，传含字母的订单号（如 MG3812）会自动识别。若该订单为隐藏订单，会自动补查。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'order_id'     => ['type' => 'string', 'description' => '订单 ID 或订单号（必填）。纯数字（如 21071356717618744607449368）按订单 ID 查；含字母（如 MG3812）按订单号查，工具会自动识别。'],
                    'hidden_order' => ['type' => 'boolean', 'description' => '隐藏订单过滤开关（true=只查隐藏订单）。一般无需传，工具已内置自动补查。'],
                ],
                'required' => ['order_id'],
            ],
        ],
        [
            'name' => 'list_products',
            'title' => '查询商品列表',
            'description' => '查询 SHOPLINE 商品列表。支持按标题、状态、厂商、handle、商品 ID、集合、创建/更新时间等筛选。当用户询问"有哪些商品""某商品的库存/SKU""上下架情况"时使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'title'            => ['type' => 'string', 'description' => '商品标题（模糊匹配）'],
                    'status'           => ['type' => 'string', 'description' => '商品状态，如 active / draft / archived'],
                    'vendor'           => ['type' => 'string', 'description' => '厂商/品牌'],
                    'handle'           => ['type' => 'string', 'description' => '商品 URL handle'],
                    'ids'              => ['type' => 'string', 'description' => '商品 ID 列表，多个用逗号分隔'],
                    'collection_id'    => ['type' => 'string', 'description' => '集合 ID'],
                    'product_category' => ['type' => 'string', 'description' => '商品分类'],
                    'created_at_min'   => ['type' => 'string', 'description' => '创建时间下限，ISO 8601'],
                    'created_at_max'   => ['type' => 'string', 'description' => '创建时间上限，ISO 8601'],
                    'updated_at_min'   => ['type' => 'string', 'description' => '更新时间下限，ISO 8601'],
                    'updated_at_max'   => ['type' => 'string', 'description' => '更新时间上限，ISO 8601'],
                    'order_by'         => ['type' => 'string', 'description' => '排序字段'],
                    'limit'            => ['type' => 'integer', 'description' => '返回条数，默认 20，最大 250', 'default' => 20],
                    'since_id'         => ['type' => 'string', 'description' => '只返回 ID 大于该值的记录'],
                    'page_info'        => ['type' => 'string', 'description' => '分页游标'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_product',
            'title' => '查询商品详情',
            'description' => '根据商品 ID 查询单个商品的完整详情（含所有规格 variant、SKU、价格、库存、图片）。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'string', 'description' => '商品 ID（必填）'],
                ],
                'required' => ['product_id'],
            ],
        ],
        [
            'name' => 'list_customers',
            'title' => '查询客户列表',
            'description' => '查询 SHOPLINE 客户列表。支持按创建/更新时间、客户 ID 筛选。当用户询问"有多少客户""某客户信息"时使用。注意：客户数据含隐私信息，请合规使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'created_at_min' => ['type' => 'string', 'description' => '创建时间下限，ISO 8601'],
                    'created_at_max' => ['type' => 'string', 'description' => '创建时间上限，ISO 8601'],
                    'updated_at_min' => ['type' => 'string', 'description' => '更新时间下限，ISO 8601'],
                    'updated_at_max' => ['type' => 'string', 'description' => '更新时间上限，ISO 8601'],
                    'ids'            => ['type' => 'string', 'description' => '客户 ID 列表，多个用逗号分隔'],
                    'limit'          => ['type' => 'integer', 'description' => '返回条数，默认 20，最大 250', 'default' => 20],
                    'since_id'       => ['type' => 'string', 'description' => '只返回 ID 大于该值的记录'],
                    'page_info'      => ['type' => 'string', 'description' => '分页游标'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_customer',
            'title' => '查询客户详情',
            'description' => '根据客户 ID 查询单个客户的完整详情（联系方式、地址、消费统计等）。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => '客户 ID（必填）'],
                ],
                'required' => ['customer_id'],
            ],
        ],
        [
            'name' => 'list_locations',
            'title' => '查询库存地点',
            'description' => '查询店铺下所有库存地点（仓库/门店）。注意：该接口需要「地点读取」权限，若返回 403 说明应用未开通，此时可从订单数据里的 locations 字段获取 location_id。',
            'inputSchema' => ['$schema' => JSON_SCHEMA_DIALECT, 'type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
        [
            'name' => 'get_inventory_levels',
            'title' => '查询库存数量',
            'description' => '查询指定库存单位（inventory_item_id）的可用库存数量。inventory_item_ids 为必填。当用户询问"某商品还有多少库存"时使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'inventory_item_ids' => ['type' => 'string', 'description' => '库存单位 ID，必填，多个用英文逗号分隔'],
                    'location_ids'       => ['type' => 'string', 'description' => '地点 ID，可选，多个用英文逗号分隔'],
                ],
                'required' => ['inventory_item_ids'],
            ],
        ],
        [
            'name' => 'list_inventory_items',
            'title' => '查询库存单位',
            'description' => '查询库存单位列表（SKU 维度，含 sku、tracked 等）。ids 为必填。用于把商品规格与库存记录关联起来。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'ids'       => ['type' => 'string', 'description' => '库存单位 ID 列表，必填，多个用逗号分隔'],
                    'limit'     => ['type' => 'integer', 'description' => '返回条数，默认 20', 'default' => 20],
                    'page_info' => ['type' => 'string', 'description' => '分页游标'],
                ],
                'required' => ['ids'],
            ],
        ],
        [
            'name' => 'list_fulfillment_orders',
            'title' => '查询履约单列表',
            'description' => '查询履约单（发货单）列表。location_ids 为必填。当用户询问"待发货订单""某地点的待处理履约单""履约进度"时使用。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'location_ids'      => ['type' => 'string', 'description' => '地点 ID，必填，多个用英文逗号分隔'],
                    'assignment_status' => ['type' => 'string', 'description' => '分配状态，如 awaiting / assigned / cancelled'],
                    'order_id'          => ['type' => 'string', 'description' => '按订单 ID 筛选'],
                    'limit'             => ['type' => 'integer', 'description' => '返回条数，默认 20', 'default' => 20],
                    'since_id'          => ['type' => 'string', 'description' => '只返回 ID 大于该值的记录'],
                    'page_info'         => ['type' => 'string', 'description' => '分页游标'],
                ],
                'required' => ['location_ids'],
            ],
        ],
        [
            'name' => 'shopline_api_request',
            'title' => '只读通用请求',
            'description' => '只读通用请求（兜底工具）。当上面的专用工具覆盖不到某个只读接口时使用，只能访问白名单内的路径，且必须是 GET。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'path'  => ['type' => 'string', 'description' => '接口路径，不含版本号，如 orders.json、products/products.json'],
                    'query' => ['type' => 'object', 'description' => '查询参数字典，如 {"limit": 10}'],
                ],
                'required' => ['path'],
            ],
        ],
    ];
}

// ============================================================
// 工具执行
// ============================================================

/** @return array{text:string,data:mixed,isError:bool} */
function executeTool(string $name, array $args): array
{
    return match ($name) {
        'shopline_ping'           => toolPing(),
        'list_orders'             => toolListOrders($args),
        'get_order'               => toolGetOrder($args),
        'list_products'           => toolListProducts($args),
        'get_product'             => toolGetProduct($args),
        'list_customers'          => toolListCustomers($args),
        'get_customer'            => toolGetCustomer($args),
        'list_locations'          => toolListLocations(),
        'get_inventory_levels'    => toolGetInventoryLevels($args),
        'list_inventory_items'    => toolListInventoryItems($args),
        'list_fulfillment_orders' => toolListFulfillmentOrders($args),
        'shopline_api_request'    => toolApiRequest($args),
        default                   => throw new \RuntimeException("未知工具：{$name}"),
    };
}

function toolPing(): array
{
    $r = shoplineRequest('orders.json', ['limit' => 1]);
    if (!$r['ok']) {
        $msg = 'SHOPLINE 自检失败。' . ($r['error'] ?? '');
        return [
            'text'    => $msg,
            'data'    => ['ok' => false, 'error' => ['message' => $r['error'] ?? '']],
            'isError' => true,
        ];
    }
    $n = countList($r['data'], ['orders', 'items', 'data']);
    return [
        'text'    => "SHOPLINE 自检通过：handle、访问令牌、API 版本均正常，成功读到订单数据（本次返回 {$n} 笔）。",
        'data'    => ['ok' => true, 'orders_returned' => $n],
        'isError' => false,
    ];
}

function toolListOrders(array $args): array
{
    $allowed = ['created_at_min', 'created_at_max', 'updated_at_min', 'updated_at_max',
        'order_at_min', 'order_at_max', 'status', 'financial_status', 'fulfillment_status',
        'ids', 'name', 'email', 'buyer_id', 'location', 'search_content', 'sort_condition',
        'contract_ids', 'hidden_order', 'limit', 'since_id', 'page_info'];
    $q = cleanQuery($args, $allowed);
    // orders.json 的 limit 上限为 100（传 >100 会 500），此处按 100 截断
    $q['limit'] = min((int) ($q['limit'] ?? 20), 100);

    // 一律先查非隐藏单；若未显式指定 hidden_order 且查不到，再自动补查隐藏单
    $autoRetry = !isset($q['hidden_order']);
    $r = ordersRequestWithHiddenFallback($q, $autoRetry);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['orders', 'items', 'data']);
    return formatResult("查询到 {$n} 笔订单（limit={$q['limit']}）。", $r['data'], $r['link']);
}

function toolGetOrder(array $args): array
{
    $id = trim((string) ($args['order_id'] ?? ''));
    if ($id === '') {
        return toolError('错误：缺少 order_id 参数');
    }
    $q = cleanQuery($args, ['hidden_order']);

    // 自动识别参数类型：纯数字 → 订单 ID（用 ids）；含字母等 → 订单号（用 name）
    if (preg_match('/^\d+$/', $id)) {
        $q['ids'] = $id;
        $label    = "订单 ID {$id}";
    } else {
        $q['name'] = $id;
        $label     = "订单号 {$id}";
    }

    // 先查非隐藏单，查不到再自动补查隐藏单
    $autoRetry = !isset($q['hidden_order']);
    $r = ordersRequestWithHiddenFallback($q, $autoRetry);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['orders', 'items', 'data']);
    if ($n === 0) {
        // 空结果不是错误：isError 保持 false，structuredContent 为 {"orders":[]}
        return [
            'text'    => "未找到{$label} 对应的订单",
            'data'    => is_array($r['data']) ? $r['data'] : ['orders' => []],
            'isError' => false,
        ];
    }
    return formatResult("{$label} 详情：", $r['data']);
}

function toolListProducts(array $args): array
{
    $allowed = ['title', 'status', 'vendor', 'handle', 'ids', 'collection_id', 'product_category',
        'created_at_min', 'created_at_max', 'updated_at_min', 'updated_at_max',
        'order_by', 'limit', 'since_id', 'page_info'];
    $q = cleanQuery($args, $allowed);
    $q['limit'] = min((int) ($q['limit'] ?? 20), 250);

    $r = shoplineRequest('products/products.json', $q);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['products', 'items', 'data']);
    return formatResult("查询到 {$n} 个商品（limit={$q['limit']}）。", $r['data'], $r['link']);
}

function toolGetProduct(array $args): array
{
    $id = trim((string) ($args['product_id'] ?? ''));
    if ($id === '') {
        return toolError('错误：缺少 product_id 参数');
    }
    $r = shoplineRequest('products/' . rawurlencode($id) . '.json');
    if (!$r['ok']) {
        return failText($r);
    }
    return formatResult("商品 {$id} 详情：", $r['data']);
}

function toolListCustomers(array $args): array
{
    $allowed = ['created_at_min', 'created_at_max', 'updated_at_min', 'updated_at_max',
        'ids', 'limit', 'since_id', 'page_info'];
    $q = cleanQuery($args, $allowed);
    $q['limit'] = min((int) ($q['limit'] ?? 20), 250);

    $r = shoplineRequest('v2/customers.json', $q);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['customers', 'items', 'data']);
    return formatResult("查询到 {$n} 个客户（limit={$q['limit']}）。注意客户数据含隐私信息，请合规使用。", $r['data'], $r['link']);
}

function toolGetCustomer(array $args): array
{
    $id = trim((string) ($args['customer_id'] ?? ''));
    if ($id === '') {
        return toolError('错误：缺少 customer_id 参数');
    }
    $r = shoplineRequest('customers/v2/' . rawurlencode($id) . '.json');
    if (!$r['ok']) {
        return failText($r);
    }
    return formatResult("客户 {$id} 详情：", $r['data']);
}

function toolListLocations(): array
{
    $r = shoplineRequest('locations/list.json');
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['locations', 'items', 'data']);
    return formatResult("店铺下共有 {$n} 个库存地点：", $r['data']);
}

function toolGetInventoryLevels(array $args): array
{
    $q = cleanQuery($args, ['inventory_item_ids', 'location_ids']);
    if (empty($q['inventory_item_ids'])) {
        return toolError('错误：inventory_item_ids 为必填（SHOPLINE 要求必须指定库存单位 ID，可用 list_products / get_product 拿到 variant 对应的 inventory_item_id）');
    }
    $r = shoplineRequest('inventory_levels.json', $q);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['inventory_levels', 'items', 'data']);
    return formatResult("查询到 {$n} 条库存记录：", $r['data']);
}

function toolListInventoryItems(array $args): array
{
    $q = cleanQuery($args, ['ids', 'limit', 'page_info']);
    if (empty($q['ids'])) {
        return toolError('错误：ids 为必填（SHOPLINE 要求必须指定库存单位 ID）');
    }
    $q['limit'] = min((int) ($q['limit'] ?? 20), 250);

    $r = shoplineRequest('inventory_items.json', $q);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['inventory_items', 'items', 'data']);
    return formatResult("查询到 {$n} 个库存单位：", $r['data'], $r['link']);
}

function toolListFulfillmentOrders(array $args): array
{
    $q = cleanQuery($args, ['location_ids', 'assignment_status', 'order_id', 'limit', 'since_id', 'page_info']);
    if (empty($q['location_ids'])) {
        return toolError('错误：location_ids 为必填（SHOPLINE 要求必须指定地点 ID，可从订单数据的 locations 字段获取）');
    }
    $q['limit'] = min((int) ($q['limit'] ?? 20), 250);

    $r = shoplineRequest('fulfillment_orders/fulfillment_orders_search.json', $q);
    if (!$r['ok']) {
        return failText($r);
    }
    $n = countList($r['data'], ['fulfillment_orders', 'items', 'data']);
    return formatResult("查询到 {$n} 张履约单：", $r['data'], $r['link']);
}

function toolApiRequest(array $args): array
{
    $path = trim((string) ($args['path'] ?? ''));
    if ($path === '') {
        return toolError('错误：缺少 path 参数');
    }
    $path = ltrim($path, '/');

    // 只读白名单：防止被当成写接口或越权调用
    $whitelist = [
        'orders.json', 'products/products.json', 'v2/customers.json',
        'inventory_levels.json', 'inventory_items.json', 'locations/list.json',
        'fulfillment_orders/fulfillment_orders_search.json',
    ];
    $isWhitelisted = in_array($path, $whitelist, true)
        || preg_match('#^products/[A-Za-z0-9_\-]+\.json$#', $path)
        || preg_match('#^customers/v2/[A-Za-z0-9_\-]+\.json$#', $path);

    if (!$isWhitelisted) {
        return toolError("错误：路径 {$path} 不在只读白名单内。\n允许的路径：" . implode('、', $whitelist)
            . '，以及 products/{id}.json、customers/v2/{id}.json。');
    }

    $query = is_array($args['query'] ?? null) ? $args['query'] : [];
    $r = shoplineRequest($path, $query);
    if (!$r['ok']) {
        return failText($r);
    }
    return formatResult("请求 {$path} 成功：", $r['data'], $r['link']);
}
