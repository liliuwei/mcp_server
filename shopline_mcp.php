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
 *     hidden_order=true  → 只返回隐藏订单
 *     hidden_order=false → 只返回非隐藏订单
 *   （两类订单无法在一次请求中同时返回。）
 *
 *   本服务的处理策略（面向售后客服，目标是「不漏单」）：
 *     · list_orders：非隐藏单与隐藏单**分别查询后合并**（按 id 去重、
 *       created_at 倒序、截断到 limit）。因此不会出现「有非隐藏单就漏掉隐藏单」的问题。
 *     · get_order 按订单号/ID：先查非隐藏单，查不到再查隐藏单（一笔订单非此即彼，
 *       短路即可，省一次 API 调用）。
 *     · get_order 按邮箱：同样两次查询后合并。
 *   工具层已不再暴露 hidden_order 参数，调用方无需关心。
 *
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
const SERVER_VERSION = '2.4.2';

const JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

const SERVER_INSTRUCTIONS = 'SHOPLINE 店铺数据只读服务：订单、客户、商品、库存、履约单。'
    . '订单查询会自动合并隐藏订单与非隐藏订单，不会漏单；list_orders 返回精简视图，完整详情用 get_order（支持订单号或邮箱）。'
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
 * 单笔订单查询：先查非隐藏单，查不到再查隐藏单（短路，省一次调用）。
 * 适用于已知唯一标识（订单号 / 订单 ID）的场景。
 */
function ordersRequestSingle(array $q): array
{
    $q1 = $q;
    $q1['hidden_order'] = 'false';
    $r = shoplineRequest('orders.json', $q1);
    if (!$r['ok']) {
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

/**
 * 列表查询：非隐藏单 + 隐藏单「分别查询并合并」，避免漏单。
 *
 * SHOPLINE 的 hidden_order 是「过滤」语义（true 只返回隐藏单、false 只返回非隐藏单），
 * 两类无法在一次请求中同时返回，所以这里查两次再合并。
 *
 * 合并规则：按 id 去重 → created_at 倒序 → 截断到 limit。
 * 分页：带 page_info 时原样透传（游标自带原查询上下文），不做合并。
 */
function ordersRequestBoth(array $q): array
{
    // 翻页：游标已绑定原查询，直接透传
    if (!empty($q['page_info'])) {
        return shoplineRequest('orders.json', $q);
    }

    $limit = (int) ($q['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }

    $q1 = $q;
    $q1['hidden_order'] = 'false';
    $q1['limit'] = $limit;

    $q2 = $q;
    $q2['hidden_order'] = 'true';
    $q2['limit'] = $limit;

    $r1 = shoplineRequest('orders.json', $q1);
    $r2 = shoplineRequest('orders.json', $q2);

    // 两边都失败才算失败；一边成功就用成功的那边
    if (!$r1['ok'] && !$r2['ok']) {
        return $r1;
    }

    $o1 = $r1['ok'] ? ($r1['data']['orders'] ?? []) : [];
    $o2 = $r2['ok'] ? ($r2['data']['orders'] ?? []) : [];

    // 去重（按订单 id）
    $seen   = [];
    $merged = [];
    foreach (array_merge($o1, $o2) as $o) {
        $k = (string) ($o['id'] ?? '');
        if ($k !== '') {
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
        }
        $merged[] = $o;
    }

    // created_at 倒序（最近的在前）
    usort($merged, function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    // 截断到 limit
    $truncated = count($merged) > $limit;
    if ($truncated) {
        $merged = array_slice($merged, 0, $limit);
    }

    return [
        'ok'        => true,
        'status'    => 200,
        'data'      => ['orders' => $merged],
        'raw'       => '',
        // 合并后两个游标无法统一，故不透传；调用方用时间范围收窄
        'link'      => '',
        'error'     => null,
        'truncated' => $truncated,
    ];
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
// 中文键值对渲染（content.text 用；structuredContent 仍保留原始 JSON）
// ============================================================

/** 英文字段名 → 中文标签（未收录的回退为原字段名） */
function fieldLabels(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        // ---------- 订单主体 ----------
        'orders' => '订单',
        'id' => 'ID',
        'name' => '订单号',
        'status' => '订单状态',
        'financial_status' => '支付状态',
        'fulfillment_status' => '发货状态',
        'fulfillment_status_v2' => '发货状态(新)',
        'order_at' => '下单时间',
        'created_at' => '创建时间',
        'updated_at' => '更新时间',
        'processed_at' => '支付完成时间',
        'cancelled_at' => '取消时间',
        'cancel_reason' => '取消原因',
        'currency' => '币种',
        'presentment_currency' => '展示币种',
        'subtotal_price' => '商品小计',
        'current_subtotal_price' => '当前商品小计',
        'total_line_items_price' => '商品原价合计',
        'total_discounts' => '优惠合计',
        'current_total_discounts' => '当前优惠合计',
        'total_tax' => '税费合计',
        'current_total_tax' => '当前税费合计',
        'current_total_price' => '应付总额',
        'total_outstanding' => '未付金额',
        'total_weight' => '总重量',
        'total_tip_received' => '小费合计',
        'taxes_included' => '价格含税',
        'tax_type' => '税类型',
        'tax_number' => '税号',
        'tax_description' => '税费说明',
        'hidden_order' => '是否隐藏订单',
        'tags' => '标签',
        'phone' => '电话',
        'email' => '邮箱',
        'note' => '备注',
        'buyer_note' => '买家留言',
        'channel_handle' => '销售渠道',
        'order_source' => '订单来源',
        'source_name' => '来源名称',
        'source_identifier' => '来源标识',
        'source_url' => '来源地址',
        'referring_site' => '来源站点',
        'landing_site' => '落地页',
        'order_status_url' => '订单状态页',
        'store_id' => '店铺 ID',
        'user_id' => '用户 ID',
        'processed_user_id' => '处理人 ID',
        'pos_location_id' => 'POS 地点 ID',
        'draft_order_id' => '草稿单 ID',
        'contract_seq' => '合同序号',
        'checkout_id' => '结账 ID',
        'checkout_token' => '结账令牌',
        'checkout_language' => '结账语言',
        'customer_locale' => '客户语言',
        'cart_token' => '购物车令牌',
        'browser_ip' => '浏览器 IP',
        'po_number' => '采购单号',
        'credit_limit' => '信用额度',
        'service' => '服务',
        'adjust_price' => '调价金额',
        'deduct_member_point_amount' => '扣减积分金额',
        'payment_gateway_names' => '支付渠道',
        'payment_terms' => '账期条款',
        'refunds' => '退款记录',
        'note_attributes' => '备注属性',
        'return_info' => '退货信息',
        'client_details' => '客户端信息',
        'customer' => '客户',
        'line_items' => '商品明细',
        'shipping_address' => '收货地址',
        'billing_address' => '账单地址',
        'shipping_lines' => '运费明细',
        'fulfillments' => '发货单',
        'locations' => '库存地点',
        'payment_details' => '支付明细',
        'tax_lines' => '税费明细',
        'discount_codes' => '优惠码',
        'discount_applications' => '优惠规则',
        'utm_parameters' => 'UTM 参数',

        // ---------- 客户 ----------
        'customers' => '客户',
        'customer' => '客户',
        'first_name' => '名',
        'last_name' => '姓',
        'nick_name' => '昵称',
        'area_code' => '电话区号',
        'addresses' => '地址列表',
        'default_address' => '默认地址',
        'orders_count' => '订单数',
        'total_spent' => '累计消费',
        'last_order_id' => '最近订单 ID',
        'last_order_name' => '最近订单号',
        'verified_email' => '邮箱已验证',
        'accepts_marketing' => '接受邮件营销',
        'accepts_mobile_marketing' => '接受短信营销',
        'email_subscribe_flag' => '邮件订阅标记',
        'mobile_subscribe_flag' => '短信订阅标记',
        'email_marketing_consent' => '邮件营销授权',
        'sms_marketing_consent' => '短信营销授权',
        'social_customer' => '社交账号',
        'multipass_identifier' => 'Multipass 标识',
        'gender' => '性别',
        'birthday' => '生日',
        'language' => '语言',
        'state' => '状态',
        'asid' => 'ASID',
        'accepts_marketing_updated_at' => '邮件营销更新时间',
        'accepts_mobile_marketing_updated_at' => '短信营销更新时间',

        // ---------- 地址 ----------
        'address1' => '地址1',
        'address2' => '地址2',
        'city' => '城市',
        'province' => '省/州',
        'province_code' => '省/州代码',
        'province_code_v2' => '省/州代码(新)',
        'standard_province_code' => '标准省/州代码',
        'country' => '国家',
        'country_code' => '国家代码',
        'zip' => '邮编',
        'company' => '公司',
        'latitude' => '纬度',
        'longitude' => '经度',
        'district' => '区',
        'district_code' => '区代码',
        'neighborhood' => '街区',
        'town' => '镇',
        'address' => '地址',
        'address_add' => '补充地址',
        'return_status' => '退货状态',
        'delivery_store_code' => '提货点代码',
        'delivery_store_name' => '提货点名称',
        'default' => '是否默认',
        'customer_id' => '客户 ID',

        // ---------- 商品 / 规格 ----------
        'products' => '商品',
        'product' => '商品',
        'title' => '标题',
        'variant_title' => '规格',
        'sku' => 'SKU',
        'price' => '单价',
        'price_set' => '单价(含币种)',
        'quantity' => '数量',
        'fulfillable_quantity' => '可发货数量',
        'fulfillment_quantity' => '已发货数量',
        'fulfillment_service' => '发货服务',
        'requires_shipping' => '需发货',
        'taxable' => '需计税',
        'product_id' => '商品 ID',
        'variant_id' => '规格 ID',
        'image_url' => '图片地址',
        'vendor' => '供应商',
        'grams' => '重量(克)',
        'gift_card' => '礼品卡',
        'properties' => '附加属性',
        'customized_attributes' => '自定义属性',
        'discount_allocations' => '优惠分摊',
        'duties' => '关税',
        'is_bundle' => '是否组合',
        'is_bundle_component' => '是否组合子项',
        'bundle_parent_item' => '组合父项',
        'bundle_parent_variant_id' => '组合父规格 ID',
        'attribute' => '属性',
        'harmonized_system_code' => 'HS 编码',
        'country_code_of_origin' => '原产国代码',
        'location_id' => '地点 ID',
        'handle' => 'URL 标识',
        'body_html' => '详情 HTML',
        'product_type' => '商品类型',
        'product_category' => '商品分类',
        'standard_category_id' => '标准分类 ID',
        'published_at' => '发布时间',
        'published_scope' => '发布范围',
        'template_path' => '模板路径',
        'total_inventory' => '总库存',
        'variants' => '规格列表',
        'options' => '选项',
        'images' => '图片列表',
        'image' => '图片',
        'media' => '媒体',
        'featured_media' => '特色媒体',
        'is_gift_card' => '是否礼品卡',
        'unlisted' => '是否隐藏',
        'combined_listing' => '组合 listing',
        'combined_listing_role' => '组合 listing 角色',
        'product_behavior' => '商品行为',
        'spu' => 'SPU',
        'path' => '路径',
        'subtitle' => '副标题',
        'option1' => '选项1',
        'option2' => '选项2',
        'option3' => '选项3',
        'option4' => '选项4',
        'option5' => '选项5',
        'position' => '排序',
        'weight' => '重量',
        'weight_unit' => '重量单位',
        'inventory_item_id' => '库存单位 ID',
        'inventory_quantity' => '库存数量',
        'inventory_tracker' => '追踪库存',
        'inventory_policy' => '缺货策略',
        'compare_at_price' => '划线价',
        'barcode' => '条码',
        'require_shipping' => '需发货',
        'src' => '图片地址',
        'alt' => '图片描述',

        // ---------- 库存 ----------
        'inventory_levels' => '库存数量',
        'inventory_items' => '库存单位',
        'available' => '可用数量',
        'tracked' => '是否追踪',
        'cost' => '成本',
        'required_shipping' => '需发货',

        // ---------- 履约单 ----------
        'fulfillment_orders' => '履约单',
        'request_status' => '请求状态',
        'assigned_location_id' => '分配地点 ID',
        'assigned_location' => '分配地点',
        'shop_id' => '店铺 ID',
        'fulfill_at' => '发货时间',
        'fulfill_by' => '最迟发货时间',
        'supported_actions' => '支持的操作',
        'fulfillment_holds' => '履约冻结',
        'delivery_method' => '配送方式',
        'merchant_requests' => '商家请求',
        'destination' => '目的地',
        'display_status' => '显示状态',

        // ---------- 物流 / 支付 / 风控 / 通用 ----------
        'tracking_company' => '物流公司',
        'tracking_number' => '运单号',
        'tracking_numbers' => '运单号列表',
        'tracking_url' => '物流跟踪链接',
        'tracking_urls' => '物流跟踪链接列表',
        'tracking_info_list' => '物流信息',
        'shipment_status' => '物流/签收状态',
        'order_id' => '订单 ID',
        'amount' => '金额',
        'amount_set' => '金额(含币种)',
        'currency_code' => '币种',
        'shop_money' => '店铺币种金额',
        'presentment_money' => '买家币种金额',
        'message' => '消息',
        'code' => '代码',
        'type' => '类型',
        'value' => '值',
        'rate' => '税率',
        'gateway' => '支付网关',
        'receipt' => '凭证',
        'error_code' => '错误码',
        'error_msg' => '错误信息',
        'status_msg' => '状态信息',
        'status_code' => '状态码',
        'kind' => '交易类型',
        'credit_card_number' => '卡号',
        'credit_card_bin' => '卡 BIN',
        'credit_card_brand' => '卡品牌',
        'credit_card_expire_month' => '卡有效期(月)',
        'credit_card_expire_year' => '卡有效期(年)',
        'score' => '风险评分',
        'recommendation' => '风险建议',
        'risk_detail_msg_list' => '风险明细',
        'risk_detail_level' => '风险级别',
        'risk_detail_msg' => '风险说明',
        'cause_cancel' => '是否导致取消',
        'source' => '来源',
        'display' => '是否展示',
        'order_risk_seq' => '风险序号',
        'order_seq' => '订单序号',
        'metafields' => '自定义字段',
        'discounted_price' => '折后价',
        'discounted_price_set' => '折后价(含币种)',
        'shipping_price' => '运费',
        'shipping_price_set' => '运费(含币种)',
        'shipping_strategy_name' => '运费策略',
        'requested_fulfillment_service_id' => '请求的履约服务 ID',
        'total_shipping_price_set' => '运费合计(含币种)',
        'settle_pay_amount' => '结算金额',
        'create_time' => '创建时间',
        'pay_channel' => '支付渠道',
        'pay_merchant_order_id' => '商户订单号',
        'pay_status' => '支付状态',
        'payment_method' => '支付方式',
        'settle_currency' => '结算币种',
        'pay_channel_deal_id' => '渠道交易号',
        'pay_amount' => '支付金额',
        'pay_merchant_email' => '商户邮箱',
        'pay_merchant_id' => '商户 ID',
        'pay_seq' => '支付流水号',
        'giftcard_presentment_money' => '礼品卡金额',
        'refund_id' => '退款 ID',
        'order_adjustments' => '订单调账',
        'refund_line_items' => '退款商品明细',
        'transactions_v2' => '交易记录(新)',
        'total_duties_set' => '关税合计(含币种)',
        'tax_amount_set' => '税费金额(含币种)',
        'current_total_duties' => '当前关税合计',
        'activity_type' => '活动类型',
        'additional' => '附加说明',
        'allocation_method' => '分摊方式',
        'benefit_type' => '优惠类型',
        'description' => '描述',
        'discount_application_index' => '优惠索引',
        'role_visibility' => '可见角色',
        'session_hash' => '会话哈希',
        'show' => '是否展示',
        'target_selection' => '目标选择',
        'target_type' => '目标类型',
        'update_time' => '更新时间',
        'urls' => '链接列表',
        'user_agent' => '浏览器 UA',
        'value_type' => '值类型',
        'caption' => '说明文字',
        'content_type' => '内容类型',
        'preview_image' => '预览图',
        'values_colors' => '颜色值',
        'values_images' => '图片值',
        'consent_collected_from' => '授权来源',
        'consent_updated_at' => '授权更新时间',
        'opt_in_level' => '订阅级别',
        'fulfillment_order_id' => '履约单 ID',
        'line_item_id' => '订单商品行 ID',
    ];
    return $map;
}

/** 取字段中文标签：支持按父级上下文消歧；xxx_set 自动复用基字段标签 */
function fieldLabel(string $key, string $parent = ''): string
{
    static $ctx = [
        'name' => [
            'orders' => '订单号', 'line_items' => '商品名称', 'locations' => '地点名称',
            'fulfillments' => '发货单名称', 'tax_lines' => '税费名称',
            'shipping_lines' => '运费名称', 'properties' => '属性名称',
            'customized_attributes' => '属性名称',
        ],
        'id' => [
            'orders' => '订单 ID', 'line_items' => '明细 ID', 'fulfillments' => '发货单 ID',
            'customers' => '客户 ID', 'customer' => '客户 ID',
            'products' => '商品 ID', 'product' => '商品 ID', 'variants' => '规格 ID',
            'transactions' => '交易 ID', 'refunds' => '退款 ID',
            'locations' => '地点 ID', 'tax_lines' => '税费行 ID',
            'discount_codes' => '优惠码 ID', 'shipping_lines' => '运费行 ID',
            'payment_details' => '支付明细 ID', 'fulfillment_orders' => '履约单 ID',
            'inventory_levels' => '库存记录 ID', 'inventory_items' => '库存单位 ID',
            'addresses' => '地址 ID', 'default_address' => '地址 ID',
        ],
        'title' => [
            'line_items' => '商品标题', 'tax_lines' => '税费标题',
            'shipping_lines' => '运费标题', 'discount_applications' => '优惠标题',
        ],
    ];

    if ($parent !== '' && isset($ctx[$key][$parent])) {
        return $ctx[$key][$parent];
    }

    $map = fieldLabels();
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (substr($key, -4) === '_set') {
        $base = substr($key, 0, -4);
        if (isset($map[$base])) {
            return $map[$base] . '(含币种)';
        }
    }
    return $key;
}

/** 枚举值翻译（按字段名区分；未命中返回 null，表示按原值展示） */
function enumLabel(string $key, string $value): ?string
{
    static $enums = [
        'status' => [
            'open' => '处理中', 'cancelled' => '已取消', 'closed' => '已关闭', 'any' => '全部',
        ],
        'financial_status' => [
            'unpaid' => '未支付', 'authorized' => '已授权', 'pending' => '支付中',
            'partially_paid' => '部分支付', 'paid' => '已支付',
            'partially_refunded' => '部分退款', 'refunded' => '已退款',
        ],
        'fulfillment_status' => [
            'unshipped' => '未发货', 'partial' => '部分发货', 'shipped' => '已发货',
            'unfulfilled' => '未发货', 'fulfilled' => '已履约',
        ],
        'fulfillment_status_v2' => [
            'unfulfilled' => '未发货', 'partial' => '部分发货',
            'fulfilled' => '已履约', 'shipped' => '已发货',
        ],
        'kind' => [
            'sale' => '销售', 'refund' => '退款', 'authorization' => '授权',
            'capture' => '扣款', 'void' => '作废',
        ],
        'recommendation' => [
            'accept' => '接受', 'cancel' => '建议取消', 'investigate' => '需调查',
        ],
        'return_status' => [
            'NO_RETURN' => '无退货', 'RETURNED' => '已退货',
        ],
        'shipment_status' => [
            'out_for_delivery' => '正在配送', 'delivered' => '已送达',
            'picked_up' => '已取货', 'faliure' => '配送失败',
        ],
        'gender' => ['male' => '男', 'female' => '女', 'unknown' => '未知'],
        'pay_status' => [
            'paid' => '已支付', 'unpaid' => '未支付', 'pending' => '支付中',
            'refunded' => '已退款', 'failed' => '失败',
        ],
    ];
    return $enums[$key][$value] ?? null;
}

/** 标量值 → 中文文本 */
function scalarText(string $key, $value): string
{
    if ($value === null) {
        return '空';
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

/** 递归渲染为中文键值对文本（$parentKey 用于消歧字段标签） */
function renderChinese($data, int $depth = 0, string $parentKey = ''): string
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
                $out .= renderChinese($item, $depth + 1, $parentKey);
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
        $label = fieldLabel($key, $parentKey);

        // xxx_set 金额对象压缩成一行，避免大量重复嵌套
        if (substr($key, -4) === '_set' && is_array($value) && isset($value['shop_money'])) {
            $shop    = $value['shop_money']['amount'] ?? '';
            $shopCur = $value['shop_money']['currency_code'] ?? '';
            $pres    = $value['presentment_money']['amount'] ?? null;
            $presCur = $value['presentment_money']['currency_code'] ?? '';
            $txt = trim($shopCur . ' ' . $shop);
            if ($pres !== null && ($pres !== $shop || $presCur !== $shopCur)) {
                $txt .= "（买家 {$presCur} {$pres}）";
            }
            $out .= $indent . $label . '：' . $txt . "\n";
            continue;
        }

        if (is_array($value)) {
            if (empty($value)) {
                $out .= $indent . $label . '：（空）' . "\n";
            } elseif (array_is_list($value)) {
                $out .= $indent . $label . '（共 ' . count($value) . ' 项）：' . "\n";
                $out .= renderChinese($value, $depth + 1, $key);
            } else {
                $out .= $indent . $label . '：' . "\n";
                $out .= renderChinese($value, $depth + 1, $key);
            }
        } else {
            $out .= $indent . $label . '：' . scalarText($key, $value) . "\n";
        }
    }
    return $out;
}

// ============================================================
// 工具返回结构（text + data + isError）
// ============================================================

/**
 * 组装工具返回：
 *   - text：中文键值对描述（便于大模型/人阅读）
 *   - data：原始数据（structuredContent，供程序解析）
 *
 * text 受 $MAX_OUTPUT_CHARS 限制，data 不受限制。
 */
function formatResult(string $summary, $data, string $link = ''): array
{
    return formatResultText($summary, renderChinese($data), $data, $link);
}

/** 用自定义正文组装返回（正文由调用方渲染，例如订单精简视图） */
function formatResultText(string $summary, string $body, $data, string $link = ''): array
{
    global $MAX_OUTPUT_CHARS;

    $truncated = false;
    if (mb_strlen($body) > $MAX_OUTPUT_CHARS) {
        $body      = mb_substr($body, 0, $MAX_OUTPUT_CHARS);
        $truncated = true;
    }

    $out      = $summary;
    $pageInfo = extractPageInfo($link);
    if ($pageInfo !== '') {
        $out .= "\n下一页游标 page_info：{$pageInfo}（把它作为 page_info 参数再次调用即可翻页）";
    }
    $out .= "\n\n" . $body;
    if ($truncated) {
        $out .= "\n...（返回内容过长已截断，请用 limit / 时间范围 / 筛选条件缩小结果）";
    }

    // structuredContent：原始数据 + 分页游标（完整、不截断）
    $sc = is_array($data) ? $data : ['data' => $data];
    if ($pageInfo !== '') {
        $sc['_page_info'] = $pageInfo;
    }

    return ['text' => $out, 'data' => $sc, 'isError' => false];
}

// ============================================================
// 订单精简视图（列表场景用，每笔约 10 行）
// ============================================================

/** 枚举值 → 「原值（中文）」；空值返回「空」 */
function briefEnum(string $key, $value): string
{
    if ($value === null || $value === '') {
        return '空';
    }
    $value = (string) $value;
    $label = enumLabel($key, $value);
    return $label === null ? $value : "{$value}（{$label}）";
}

/** 商品明细 → 「标题 × 数量；标题 × 数量」 */
function briefItems(array $items): string
{
    if (empty($items)) {
        return '空';
    }
    $parts = [];
    foreach ($items as $it) {
        $title = $it['title'] ?? ($it['name'] ?? '');
        $qty   = $it['quantity'] ?? 1;
        $parts[] = trim($title) === '' ? "× {$qty}" : "{$title} × {$qty}";
    }
    return implode('；', $parts);
}

/** 发货单 → 「物流公司 运单号（物流状态）；…」 */
function briefTracking(array $fulfillments): string
{
    if (empty($fulfillments)) {
        return '未发货';
    }
    $parts = [];
    foreach ($fulfillments as $f) {
        $txt = trim(((string) ($f['tracking_company'] ?? '')) . ' ' . ((string) ($f['tracking_number'] ?? '')));
        if ($txt === '') {
            $txt = '已发货';
        }
        $ship = $f['shipment_status'] ?? null;
        if ($ship) {
            $txt .= '（' . briefEnum('shipment_status', $ship) . '）';
        }
        $parts[] = $txt;
    }
    return implode('；', $parts);
}

/**
 * 订单列表精简视图：每笔只输出售后判断所需的关键字段。
 * 需要完整字段时用 get_order。
 */
function renderOrderBriefList(array $orders): string
{
    if (empty($orders)) {
        return "  （无订单）\n";
    }

    $out = '订单列表（共 ' . count($orders) . ' 笔）：' . "\n";
    foreach ($orders as $i => $o) {
        $customer = trim(
            ((string) ($o['customer']['first_name'] ?? '')) . ' ' .
            ((string) ($o['customer']['last_name'] ?? ''))
        );

        $out .= '  第 ' . ($i + 1) . ' 笔：' . "\n";
        $out .= '    订单号：' . (($o['name'] ?? '') !== '' ? $o['name'] : '空') . "\n";
        $out .= '    下单时间：' . (($o['created_at'] ?? '') !== '' ? $o['created_at'] : '空') . "\n";
        $out .= '    应付总额：' . trim(((string) ($o['current_total_price'] ?? '')) . ' ' . ((string) ($o['currency'] ?? ''))) . "\n";
        $out .= '    支付状态：' . briefEnum('financial_status', $o['financial_status'] ?? null) . "\n";
        $out .= '    发货状态：' . briefEnum('fulfillment_status_v2', $o['fulfillment_status_v2'] ?? ($o['fulfillment_status'] ?? null)) . "\n";
        $out .= '    客户：' . ($customer !== '' ? $customer : '空') . "\n";
        $out .= '    邮箱：' . (($o['email'] ?? '') !== '' ? $o['email'] : '空') . "\n";
        $out .= '    商品：' . briefItems($o['line_items'] ?? []) . "\n";
        $out .= '    物流：' . briefTracking($o['fulfillments'] ?? []) . "\n";
    }
    return $out;
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
            'description' => '查询 SHOPLINE 订单列表（售后查客户订单首选）。支持按创建时间、更新时间、订单状态、支付状态、发货状态、订单号、邮箱等条件筛选。本工具会完整覆盖所有订单并合并去重（按创建时间倒序），不会漏单。返回精简视图（每笔约 10 行），需要完整字段请用 get_order。当用户询问"最近订单""某天的订单""未发货订单""某客户订单"时使用。',
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
                    'status'             => ['type' => 'string', 'description' => '订单状态，仅接受 open（处理中）/ cancelled（已取消）/ any（含已归档）。传其他值会报错。'],
                    'financial_status'   => ['type' => 'string', 'description' => '支付状态：unpaid / authorized / pending / partially_paid / paid / partially_refunded / refunded'],
                    'fulfillment_status' => ['type' => 'string', 'description' => '发货状态：unshipped（未发货）/ partial（部分发货）/ shipped（已发货）。注意不是 unfulfilled / fulfilled，传错会 500。'],
                    'ids'                => ['type' => 'string', 'description' => '订单 ID 列表，多个用英文逗号分隔'],
                    'name'               => ['type' => 'string', 'description' => '订单号，如 MG3812'],
                    'email'              => ['type' => 'string', 'description' => '买家邮箱'],
                    'buyer_id'           => ['type' => 'string', 'description' => '买家 ID'],
                    'location'           => ['type' => 'string', 'description' => '订单来源地区'],
                    'search_content'     => ['type' => 'string', 'description' => '关键字搜索（订单号、客户等）'],
                    'contract_ids'       => ['type' => 'string', 'description' => '合同/交易单 ID，多个用逗号分隔'],
                    'limit'              => ['type' => 'integer', 'description' => '合并后返回条数，默认 20，最大 100', 'default' => 20],
                    'since_id'           => ['type' => 'string', 'description' => '只返回 ID 大于该值的记录（增量拉取）'],
                    'page_info'          => ['type' => 'string', 'description' => '分页游标，上一页返回的 page_info'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_order',
            'title' => '查询订单详情',
            'description' => '查询订单详情。支持两种入参，order_id 与 email 至少传一个：① order_id（订单号如 MG3812，或纯数字订单 ID）→ 返回该单完整详情；② email → 返回该邮箱下**所有订单的完整详情**（可能多笔，文本较长）。两者同时传时优先用 order_id，查不到再回落到 email。所有订单都会被覆盖，不会漏单。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'string', 'description' => '订单号或订单 ID（可选）。纯数字（如 21071356717618744607449368）按订单 ID 查；含字母（如 MG3812）按订单号查。与 email 至少传一个。'],
                    'email'    => ['type' => 'string', 'description' => '买家邮箱（可选）。order_id 未传或查不到时使用；命中多笔时返回精简列表。与 order_id 至少传一个。'],
                ],
                'required' => [],
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
            'description' => '查询履约单（发货单）列表。location_ids 为必填。当用户询问"某地点的待处理履约单""履约进度"时使用。注意：SHOPLINE 该接口不支持按订单过滤（order_id 参数会被忽略），查某笔订单的发货信息请直接用 get_order —— 订单对象已内嵌 fulfillments。',
            'inputSchema' => [
                '$schema' => JSON_SCHEMA_DIALECT,
                'type' => 'object',
                'properties' => [
                    'location_ids'      => ['type' => 'string', 'description' => '地点 ID，必填，多个用英文逗号分隔'],
                    'assignment_status' => ['type' => 'string', 'description' => '分配状态，如 awaiting / assigned / cancelled'],
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
        'ids', 'name', 'email', 'buyer_id', 'location', 'search_content',
        'contract_ids', 'limit', 'since_id', 'page_info'];
    $q = cleanQuery($args, $allowed);

    // 枚举强校验：SHOPLINE 对这几个字段做严格校验，传错值会直接 500。
    // 这里提前拦截，给出可读提示（比 500 友好得多）。
    $enumChecks = [
        'status'             => ['open', 'cancelled', 'any'],
        'financial_status'   => ['unpaid', 'authorized', 'pending', 'partially_paid', 'paid', 'partially_refunded', 'refunded'],
        'fulfillment_status' => ['unshipped', 'partial', 'shipped'],
    ];
    foreach ($enumChecks as $field => $allowedValues) {
        if (isset($q[$field]) && !in_array((string) $q[$field], $allowedValues, true)) {
            return toolError("错误：{$field} 只能是 " . implode(' / ', $allowedValues) . "（收到：{$q[$field]}）");
        }
    }

    // orders.json 的 limit 上限为 100（传 >100 会 500），此处按 100 截断
    $q['limit'] = min((int) ($q['limit'] ?? 20), 100);

    // 隐藏单 + 非隐藏单「分别查询并合并」，避免漏单
    $r = ordersRequestBoth($q);
    if (!$r['ok']) {
        return failText($r);
    }

    $orders = $r['data']['orders'] ?? [];
    $n      = count($orders);

    if ($n === 0) {
        return formatResult('没有找到符合条件的订单。', $r['data'], $r['link']);
    }

    $summary = "查询到 {$n} 笔订单（limit={$q['limit']}）";
    if (!empty($r['truncated'])) {
        $summary .= "；结果已按 limit 截断，如需更早的订单请用时间范围收窄";
    }
    $summary .= '：';

    return formatResultText($summary, renderOrderBriefList($orders), $r['data'], $r['link']);
}

function toolGetOrder(array $args): array
{
    $id    = trim((string) ($args['order_id'] ?? ''));
    $email = trim((string) ($args['email'] ?? ''));

    if ($id === '' && $email === '') {
        return toolError('错误：请至少提供 order_id（订单号 / 订单 ID）或 email 之一');
    }
    if ($id !== '' && strpos($id, '@') !== false) {
        return toolError("错误：order_id 不接受邮箱，请改用 email 参数（收到：{$id}）");
    }

    $prefix = '';

    // ① 优先按订单号 / 订单 ID（短路：非隐藏 → 隐藏）
    if ($id !== '') {
        if (preg_match('/^\d+$/', $id)) {
            $q     = ['ids' => $id];
            $label = "订单 ID {$id}";
        } else {
            $q     = ['name' => $id];
            $label = "订单号 {$id}";
        }

        $r = ordersRequestSingle($q);
        if (!$r['ok']) {
            return failText($r);
        }
        if (countList($r['data'], ['orders', 'items', 'data']) > 0) {
            return formatResult("{$label} 详情：", $r['data']);
        }

        if ($email === '') {
            return [
                'text'    => "未找到{$label} 对应的订单",
                'data'    => is_array($r['data']) ? $r['data'] : ['orders' => []],
                'isError' => false,
            ];
        }
        $prefix = "未找到{$label}；";
    }

    // ② 邮箱查询（隐藏单 + 非隐藏单合并）
    $r2 = ordersRequestBoth(['email' => $email, 'limit' => 20]);
    if (!$r2['ok']) {
        return failText($r2);
    }

    $orders = $r2['data']['orders'] ?? [];
    $n      = count($orders);

    if ($n === 0) {
        return [
            'text'    => $prefix . "邮箱 {$email} 下也没有订单",
            'data'    => is_array($r2['data']) ? $r2['data'] : ['orders' => []],
            'isError' => false,
        ];
    }

    if ($n === 1) {
        return formatResult($prefix . "按邮箱 {$email} 找到 1 笔订单：", $r2['data']);
    }

    // 多笔：每笔都输出完整详情（注意：文本会较长，超出上限会截断）
    return formatResult(
        $prefix . "邮箱 {$email} 下共 {$n} 笔订单（完整详情）：",
        $r2['data']
    );
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
    $q = cleanQuery($args, ['location_ids', 'assignment_status', 'limit', 'since_id', 'page_info']);
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
