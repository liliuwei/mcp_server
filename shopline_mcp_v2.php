<?php
/**
 * ============================================================================
 * SHOPLINE Admin REST API MCP Server v2 —— 基于 MCP 协议 2026-07-28
 * ============================================================================
 *
 * 【这个文件与 shopline_mcp.php 的区别】
 *   shopline_mcp.php     : 旧协议（initialize 握手，2024-11-05）
 *   本文件 (v2)          : 新协议 2026-07-28「modern / 无状态」模式
 *   v1 保持不动，两个文件可并存，用不同 URL 分别接入与灰度对比。
 *
 * 【2026-07-28 相对旧版的破坏性变化（本文件已按新规实现）】
 *   1. 取消 initialize / notifications/initialized 握手 —— 完全无状态。
 *      每个请求自带 _meta["io.modelcontextprotocol/protocolVersion"]。
 *   2. 移除协议方法 ping / logging/setLevel / notifications/roots/list_changed。
 *   3. 移除协议级会话与 Mcp-Session-Id 头；移除 SSE 断线续传。
 *   4. 新增 server/discover（MUST 实现）：返回支持的协议版本、能力与身份。
 *   5. 版本不支持 → 返回 UnsupportedProtocolVersionError（-32022），
 *      data 里带 supported / requested。
 *   6. 所有 result 必须带 resultType（普通结果填 "complete"）。
 *   7. tools/list 等列表结果必须带 ttlMs 与 cacheScope。
 *   8. Streamable HTTP POST 需带 Mcp-Method / Mcp-Name 头，
 *      MCP-Protocol-Version 头须与 _meta 中的版本一致；不一致 → HeaderMismatch（-32020）。
 *   9. structuredContent 放宽为任意 JSON 值。
 *   10. 服务端可在每个 result 的 _meta 里自报身份（io.modelcontextprotocol/serverInfo）。
 *
 * 【本服务的工具】
 *   业务工具与 v1 完全一致（12 个只读工具），区别只在协议外壳：
 *     shopline_ping / list_orders / get_order / list_products / get_product /
 *     list_customers / get_customer / list_locations / get_inventory_levels /
 *     list_inventory_items / list_fulfillment_orders / shopline_api_request
 *
 *   注：这里的 shopline_ping 是「业务工具」，与被协议移除的 ping 方法无关。
 *
 * 【本文件实现的协议方法】
 *   server/discover   版本 / 能力 / 身份
 *   tools/list        工具清单（含 ttlMs / cacheScope）
 *   tools/call        调用工具（content + structuredContent）
 *   notifications/*   忽略（无响应）
 *   其他               -32601 Method not found
 *
 * 【部署】
 *   1. 把本文件放到 Web 根目录，例如 /www/wwwroot/your-site/shopline_mcp_v2.php
 *   2. 修改下方「配置」区的 $MCP_TOKEN（建议用与 v1 不同的独立令牌，便于灰度）
 *   3. Nginx 需放行以下请求头到 PHP（否则 $hdrXxx 会取不到）：
 *        MCP-Protocol-Version → HTTP_MCP_PROTOCOL_VERSION
 *        Mcp-Method           → HTTP_MCP_METHOD
 *        Mcp-Name             → HTTP_MCP_NAME
 *        Authorization        → HTTP_AUTHORIZATION
 *      宝塔 + PHP 站点可在 server{} 内、include enable-php-82.conf; 之前加：
 *
 *        location = /shopline_mcp_v2.php {
 *            fastcgi_param HTTP_AUTHORIZATION       $http_authorization;
 *            fastcgi_param HTTP_MCP_PROTOCOL_VERSION $http_mcp_protocol_version;
 *            fastcgi_param HTTP_MCP_METHOD          $http_mcp_method;
 *            fastcgi_param HTTP_MCP_NAME            $http_mcp_name;
 *            include enable-php-82.conf;
 *        }
 *
 *   4. nginx -t && nginx -s reload
 *
 * 【自检（curl 示例）】
 *   curl -s -X POST https://your-domain.com/shopline_mcp_v2.php \
 *     -H "Content-Type: application/json" \
 *     -H "MCP-Protocol-Version: 2026-07-28" \
 *     -H "Mcp-Method: server/discover" \
 *     -H "Authorization: Bearer your-mcp-token" \
 *     -d '{"jsonrpc":"2.0","id":1,"method":"server/discover",
 *          "params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28",
 *                             "io.modelcontextprotocol/clientCapabilities":{}}}}'
 *
 * 【鉴权】同 v1：Header Bearer / URL ?token= / 不传（仅测试）。传了就必须匹配。
 *
 * 【安全提醒】
 *   1. 本文件含访问令牌，务必确保 Web 服务器禁止下载 .php 源码。
 *   2. 客户数据（手机号、邮箱、地址）属个人隐私，注意合规使用。
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

// MCP 接口自身的访问令牌（建议与 v1 用不同的值，便于灰度与回滚）
$MCP_TOKEN = 'shopline-mcp-v2-2026-secret-token';

// SHOPLINE 店铺 handle：店铺域名前缀（本令牌对应 mileseeygolf）
$SHOPLINE_SHOP_HANDLE = 'mileseeygolf';

// SHOPLINE 访问令牌
$SHOPLINE_ACCESS_TOKEN = 'eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJhcHBJZCI6MCwiYXBwS2V5IjoiOWU4OGI5ZTEyNmMyNzRkYmUwNzdkZmExYzhjYTdmMTIzYmQ0MzQ5YiIsImV4cCI6MTg4NjIyMTQ5MCwiaXNzIjoieXNvdWwiLCJzZWxsZXJJZCI6IjI0MTIwNDAyMDUiLCJzdG9yZUlkIjoiMTczMzU2MjI2ODU1NyIsInRpbWVzdGFtcCI6MTc5MTUyNzA5MDYzNCwidmVyc2lvbiI6IlYyIn0.ydQRSK5unaKuUm_XP_bTtx8KCbu9iGlXz8SXG5taU8yFwU6Rna-JrYuw5O3xFm7m_lfRjo1A3H8X-QVo3acG0g';

// API 版本（v20260901 为当前稳定版）
$SHOPLINE_API_VERSION = 'v20260901';

// 单次请求超时（秒）
$SHOPLINE_TIMEOUT = 30;

// content 文本正文最大字符数（structuredContent 不受此限制）
$MAX_OUTPUT_CHARS = 50000;

// ============================================================
// MCP 2026-07-28 协议常量
// ============================================================

const MCP_PROTOCOL_VERSION   = '2026-07-28';
const MCP_SUPPORTED_VERSIONS = ['2026-07-28'];

const SERVER_NAME    = 'SHOPLINE_Admin_MCP';
const SERVER_VERSION = '2.0.0';

// _meta 键名
const META_VERSION     = 'io.modelcontextprotocol/protocolVersion';
const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
const META_CLIENT_CAPS = 'io.modelcontextprotocol/clientCapabilities';
const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

// 缓存提示（tools/list、server/discover）
const CACHE_TTL_MS    = 3600000;   // 1 小时
const CACHE_SCOPE     = 'public';

// JSON-RPC 错误码
const ERR_PARSE               = -32700;
const ERR_METHOD_NOT_FOUND    = -32601;
const ERR_INTERNAL            = -32603;
const ERR_HEADER_MISMATCH     = -32020;  // HeaderMismatchError
const ERR_UNSUPPORTED_VERSION = -32022;  // UnsupportedProtocolVersionError

const SERVER_INSTRUCTIONS = 'SHOPLINE 店铺数据只读服务：订单、客户、商品、库存、履约单。'
    . '订单查询（列表/详情）一律先查非隐藏订单，若为空再自动补查隐藏订单（hidden_order），避免漏单。'
    . '分页用返回的 page_info 游标。建议先调用 shopline_ping 验证连通性。';

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

// ---------- 2026-07-28 传输层请求头 ----------
$hdrVersion = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '';
$hdrMethod  = $_SERVER['HTTP_MCP_METHOD'] ?? '';
$hdrName    = $_SERVER['HTTP_MCP_NAME'] ?? '';

$response = handleRequest($request, $hdrVersion, $hdrMethod, $hdrName);
if ($response !== null) {
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
exit;

// ============================================================
// MCP 协议层（2026-07-28，无状态）
// ============================================================

/**
 * 处理一次 JSON-RPC 请求
 *
 * @param array  $req        JSON-RPC 请求体
 * @param string $hdrVersion MCP-Protocol-Version 请求头
 * @param string $hdrMethod  Mcp-Method 请求头
 * @param string $hdrName    Mcp-Name 请求头
 * @return array|null        通知类请求返回 null（不响应）
 */
function handleRequest(array $req, string $hdrVersion = '', string $hdrMethod = '', string $hdrName = ''): ?array
{
    $id     = $req['id'] ?? null;
    $method = (string) ($req['method'] ?? '');
    $params = is_array($req['params'] ?? null) ? $req['params'] : [];
    $meta   = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

    // 通知不需要响应（无 id，或 notifications/* 前缀）
    if (!array_key_exists('id', $req) || strpos($method, 'notifications/') === 0) {
        return null;
    }

    // 1) 版本校验：优先取 body 的 _meta，其次取 HTTP 头
    $version = (string) ($meta[META_VERSION] ?? $hdrVersion);
    if ($version === '' || !in_array($version, MCP_SUPPORTED_VERSIONS, true)) {
        return errorResponse($id, ERR_UNSUPPORTED_VERSION, 'Unsupported protocol version', [
            'supported' => MCP_SUPPORTED_VERSIONS,
            'requested' => $version,
        ]);
    }

    // 2) 头部一致性（宽松策略：请求头存在才校验，避免误伤未发头的客户端）
    if ($hdrVersion !== '' && $hdrVersion !== $version) {
        return errorResponse($id, ERR_HEADER_MISMATCH,
            'MCP-Protocol-Version header does not match _meta protocolVersion');
    }
    if ($hdrMethod !== '' && $hdrMethod !== $method) {
        return errorResponse($id, ERR_HEADER_MISMATCH,
            'Mcp-Method header does not match request method');
    }
    if ($hdrName !== '' && $method === 'tools/call'
        && $hdrName !== (string) ($params['name'] ?? '')) {
        return errorResponse($id, ERR_HEADER_MISMATCH,
            'Mcp-Name header does not match tool name');
    }

    // 3) 无状态分发
    try {
        switch ($method) {
            case 'server/discover':
                return okResponse($id, [
                    'supportedVersions' => MCP_SUPPORTED_VERSIONS,
                    'capabilities'      => ['tools' => new \stdClass()],
                    'instructions'      => SERVER_INSTRUCTIONS,
                    'ttlMs'             => CACHE_TTL_MS,
                    'cacheScope'        => CACHE_SCOPE,
                ]);

            case 'tools/list':
                return okResponse($id, [
                    'tools'      => getToolDefinitions(),
                    'ttlMs'      => CACHE_TTL_MS,
                    'cacheScope' => CACHE_SCOPE,
                ]);

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $res  = executeTool($name, $args);

                $payload = ['content' => [['type' => 'text', 'text' => $res['text']]]];
                if (array_key_exists('data', $res) && $res['data'] !== null) {
                    $payload['structuredContent'] = $res['data'];
                }
                if (!empty($res['isError'])) {
                    $payload['isError'] = true;
                }
                return okResponse($id, $payload);

            default:
                return errorResponse($id, ERR_METHOD_NOT_FOUND, "Method not found: {$method}");
        }
    } catch (\Throwable $e) {
        return errorResponse($id, ERR_INTERNAL, $e->getMessage());
    }
}

/** 统一成功响应：补 resultType 与 _meta.serverInfo */
function okResponse($id, array $result): array
{
    $payload = ['resultType' => 'complete'];
    foreach ($result as $k => $v) {
        $payload[$k] = $v;
    }
    $payload['_meta'] = [
        META_SERVER_INFO => ['name' => SERVER_NAME, 'version' => SERVER_VERSION],
    ];
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $payload];
}

/** 统一错误响应 */
function errorResponse($id, int $code, string $message, array $data = []): array
{
    $error = ['code' => $code, 'message' => $message];
    if (!empty($data)) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

// ============================================================
// SHOPLINE API 调用封装（与 v1 相同）
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
// 工具返回结构（v2：text + data + isError）
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

/** 参数错误的统一输出（isError = true） */
function toolError(string $message): array
{
    return [
        'text'    => $message,
        'data'    => ['error' => ['message' => $message]],
        'isError' => true,
    ];
}

// ============================================================
// 工具定义（与 v1 一致）
// ============================================================
function getToolDefinitions(): array
{
    return [
        [
            'name' => 'shopline_ping',
            'description' => 'SHOPLINE 连通性与凭据自检。调用一次订单查询（orders.json）验证 handle、访问令牌和 API 版本是否配置正确。部署后或排查问题时应先调用它。',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
        [
            'name' => 'list_orders',
            'description' => '查询 SHOPLINE 订单列表。支持按创建时间、更新时间、订单状态、支付状态、履约状态、订单号、邮箱等条件筛选。当用户询问"最近订单""某天的订单""未发货订单""某客户订单"时使用。注意：SHOPLINE 存在「隐藏订单」（hidden_order），默认不返回；本工具一律先查非隐藏订单，若结果为空再自动补查隐藏订单，避免漏单。',
            'inputSchema' => [
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
            'description' => '根据订单 ID 或订单号查询订单完整详情（含商品明细、金额、收货地址、支付与履约信息）。order_id 传纯数字视为订单 ID，传含字母的订单号（如 MG3812）会自动识别。若该订单为隐藏订单，会自动补查。',
            'inputSchema' => [
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
            'description' => '查询 SHOPLINE 商品列表。支持按标题、状态、厂商、handle、商品 ID、集合、创建/更新时间等筛选。当用户询问"有哪些商品""某商品的库存/SKU""上下架情况"时使用。',
            'inputSchema' => [
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
            'description' => '根据商品 ID 查询单个商品的完整详情（含所有规格 variant、SKU、价格、库存、图片）。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'string', 'description' => '商品 ID（必填）'],
                ],
                'required' => ['product_id'],
            ],
        ],
        [
            'name' => 'list_customers',
            'description' => '查询 SHOPLINE 客户列表。支持按创建/更新时间、客户 ID 筛选。当用户询问"有多少客户""某客户信息"时使用。注意：客户数据含隐私信息，请合规使用。',
            'inputSchema' => [
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
            'description' => '根据客户 ID 查询单个客户的完整详情（联系方式、地址、消费统计等）。',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'string', 'description' => '客户 ID（必填）'],
                ],
                'required' => ['customer_id'],
            ],
        ],
        [
            'name' => 'list_locations',
            'description' => '查询店铺下所有库存地点（仓库/门店）。注意：该接口需要「地点读取」权限，若返回 403 说明应用未开通，此时可从订单数据里的 locations 字段获取 location_id。',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
        [
            'name' => 'get_inventory_levels',
            'description' => '查询指定库存单位（inventory_item_id）的可用库存数量。inventory_item_ids 为必填。当用户询问"某商品还有多少库存"时使用。',
            'inputSchema' => [
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
            'description' => '查询库存单位列表（SKU 维度，含 sku、tracked 等）。ids 为必填。用于把商品规格与库存记录关联起来。',
            'inputSchema' => [
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
            'description' => '查询履约单（发货单）列表。location_ids 为必填。当用户询问"待发货订单""某地点的待处理履约单""履约进度"时使用。',
            'inputSchema' => [
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
            'description' => '只读通用请求（兜底工具）。当上面的专用工具覆盖不到某个只读接口时使用，只能访问白名单内的路径，且必须是 GET。',
            'inputSchema' => [
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
