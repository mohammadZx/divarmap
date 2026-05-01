#!/usr/bin/env php
<?php
/**
 * جمع‌آوری آگهی‌های نقشهٔ دیوار برای یک bbox اولیه (pure PHP، بدون Composer).
 *
 * استفاده:
 *   php divar_map_collect.php initial_request.json output.json [گزینه‌ها]
 *
 * گزینه‌ها:
 *   --max-depth=N      عمق تقسیم بازگشتی (پیش‌فرض 5)
 *   --min-size-deg=X   حداقل اندازهٔ ضلع bbox به درجه (پیش‌فرض 0.00025)
 *   --sleep-ms=N       فاصله بین درخواست‌ها به میلی‌ثانیه (پیش‌فرض 350)
 *   --zoom-step=X      افزایش zoom در هر سطح عمق (پیش‌فرض 0.35)
 *   --cookie-file=F    فایل حاوی رشتهٔ Cookie (بدون پیشوند Cookie:)
 *   --log-file=F       فایل لاگ جزئیات (پیش‌فرض: خروجی + .collect.log)
 *   --no-log-file      بدون فایل لاگ
 *   --quiet            بدون چاپ روی stderr (فقط فایل لاگ اگر فعال باشد)
 *   --max-requests=N   حداکثر تعداد درخواست HTTP (ایمنی؛ پیش‌فرض 5000)
 *   --timeout-sec=N    تایم‌اوت هر درخواست (پیش‌فرض 60)
 *   --max-runtime-sec=N حداکثر زمان کل اجرا به ثانیه (۰ = نامحدود). مثال: ۶۰ = یک دقیقه زوم/کاور
 *   --try-pagination   صفحه‌بندی آزمایشی با pagination پاسخ
 *   --paginate-max=N   حداکثر صفحات اضافه در هر سلول (پیش‌فرض 30)
 *   --lite              فقط خلاصهٔ همان نتیجهٔ سرچ (عنوان، تصویر، توضیح کوتاه، منطقه…)
 *                       بدون raw_post_row؛ برای کم‌حجم‌تر و بدون «خزیدن» جزئیات اضافه
 *   --search-summary-only  همان --lite
 */

declare(strict_types=1);

const DEFAULT_ENDPOINT = 'https://api.divar.ir/v8/postlist/w/search';

/** مهلت زمانی با microtime(true)؛ null یعنی بدون محدودیت */
function is_runtime_deadline_passed(?float $deadlineMono): bool
{
    return $deadlineMono !== null && microtime(true) >= $deadlineMono;
}

/**
 * کپی عمیق آرایه — بدون این، در PHP کپی سطحی است و bbox تمام شاخه‌های بازگشتی
 * روی همان آرایهٔ تو در تو نوشته می‌شود و نتیجه غلط یا «گیر کرده» می‌شود.
 *
 * @param array<mixed> $data
 * @return array<mixed>
 */
function deep_copy_array(array $data): array
{
    $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($j === false) {
        throw new RuntimeException('deep_copy_array: json_encode ناموفق');
    }
    $copy = json_decode($j, true);
    if (!is_array($copy)) {
        throw new RuntimeException('deep_copy_array: json_decode ناموفق');
    }
    return $copy;
}

final class RunLogger
{
    private string $path;
    private bool $quiet;

    public function __construct(string $path, bool $quiet)
    {
        $this->path = $path;
        $this->quiet = $quiet;
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function line(string $level, string $message, ?array $context = null): void
    {
        $ts = gmdate('Y-m-d\TH:i:s.v\Z');
        $line = "[{$ts}] [{$level}] {$message}";
        if ($context !== null && $context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $line .= "\n";
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        if (!$this->quiet) {
            fwrite(STDERR, $line);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function logHttpRequest(int $rid, string $method, string $url, array $headers, array $body): void
    {
        $safeHeaders = $headers;
        if (isset($safeHeaders['cookie'])) {
            $c = $safeHeaders['cookie'];
            $safeHeaders['cookie'] = strlen($c) > 80 ? substr($c, 0, 40) . '…(' . strlen($c) . ' bytes)' : $c;
        }
        $this->line('HTTP_REQ', "rid={$rid} {$method} {$url}", [
            'headers' => $safeHeaders,
            'body' => $body,
            'body_json_bytes' => strlen((string) json_encode($body, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    public function logHttpResponse(
        int $rid,
        int $status,
        string $statusLine,
        array $respHeaders,
        string $raw,
        ?array $json,
        float $elapsedMs
    ): void {
        $summary = null;
        if (is_array($json)) {
            $lw = $json['list_widgets'] ?? null;
            $postRows = 0;
            if (is_array($lw)) {
                foreach ($lw as $w) {
                    if (is_array($w) && ($w['widget_type'] ?? '') === 'POST_ROW') {
                        $postRows++;
                    }
                }
            }
            $summary = [
                'list_widgets_count' => is_array($lw) ? count($lw) : 0,
                'post_row_widgets' => $postRows,
                'pagination_has_next' => $json['pagination']['has_next_page'] ?? null,
                'search_uid' => $json['pagination']['data']['search_uid'] ?? ($json['action_log']['server_side_info']['info']['search_uid'] ?? null),
                'keys_top' => array_slice(array_keys($json), 0, 25),
            ];
        }
        $rawPreview = strlen($raw) > 8000 ? substr($raw, 0, 8000) . "\n… trimmed " . strlen($raw) . " bytes" : $raw;
        $this->line('HTTP_RES', "rid={$rid} status={$status} time_ms=" . round($elapsedMs, 2), [
            'status_line' => $statusLine,
            'response_headers' => $respHeaders,
            'summary' => $summary,
            'raw_body' => $rawPreview,
            'decoded_json' => $json,
        ]);
    }

    public function logEvent(string $event, array $data = []): void
    {
        $this->line('EVENT', $event, $data);
    }
}

/** @return array<string, string> */
function default_headers(): array
{
    return [
        'accept' => 'application/json, text/plain, */*',
        'accept-language' => 'fa,en-US;q=0.9,en;q=0.8',
        'content-type' => 'application/json',
        'origin' => 'https://divar.ir',
        'referer' => 'https://divar.ir/',
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'x-render-type' => 'CSR',
        'x-standard-divar-error' => 'true',
    ];
}

/**
 * @param array<string, string> $extraHeaders
 * @return array{json: array<string, mixed>|null, raw: string, status: int, status_line: string, response_headers: array<int|string, mixed>}
 */
function http_post_json(
    string $url,
    array $body,
    array $extraHeaders,
    int $timeoutSec,
    ?RunLogger $logger,
    int $requestId
): array {
    $t0 = microtime(true);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        throw new RuntimeException('json_encode بدنهٔ درخواست ناموفق بود.');
    }

    $allHeaders = array_merge(default_headers(), $extraHeaders);

    if ($logger !== null) {
        $logger->logHttpRequest($requestId, 'POST', $url, $allHeaders, $body);
    }

    if (extension_loaded('curl')) {
        return http_post_json_curl($url, $payload, $allHeaders, $timeoutSec, $logger, $requestId, $t0);
    }

    return http_post_json_stream($url, $payload, $allHeaders, $timeoutSec, $logger, $requestId, $t0);
}

/**
 * @param array<string, string> $allHeaders
 * @return array{json: array<string, mixed>|null, raw: string, status: int, status_line: string, response_headers: array<int|string, mixed>}
 */
function http_post_json_curl(
    string $url,
    string $payload,
    array $allHeaders,
    int $timeoutSec,
    ?RunLogger $logger,
    int $requestId,
    float $t0
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init ناموفق');
    }
    $flat = [];
    foreach ($allHeaders as $k => $v) {
        $flat[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $flat,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(30, $timeoutSec),
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $full = curl_exec($ch);
    if ($full === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL خطا: ' . $err);
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $rawHeaders = substr($full, 0, $headerSize);
    $rawBody = substr($full, $headerSize);
    $statusLine = '';
    $respHeaders = [];
    foreach (preg_split("/\r\n/", $rawHeaders) as $i => $line) {
        if ($i === 0) {
            $statusLine = $line;
            continue;
        }
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$hk, $hv] = explode(':', $line, 2);
        $respHeaders[trim($hk)] = trim($hv);
    }

    $json = null;
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $json = $decoded;
    }

    $elapsed = (microtime(true) - $t0) * 1000.0;
    if ($logger !== null) {
        $logger->logHttpResponse($requestId, $status, $statusLine, $respHeaders, $rawBody, $json, $elapsed);
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP ' . $status . ' — پیش‌نمایش بدنه: ' . substr($rawBody, 0, 600));
    }
    if ($json === null) {
        throw new RuntimeException('JSON پاسخ نامعتبر (آیا HTML یا خطای میانی است؟) پیش‌نمایش: ' . substr($rawBody, 0, 400));
    }

    return [
        'json' => $json,
        'raw' => $rawBody,
        'status' => $status,
        'status_line' => $statusLine,
        'response_headers' => $respHeaders,
    ];
}

/**
 * @param array<string, string> $allHeaders
 * @return array{json: array<string, mixed>|null, raw: string, status: int, status_line: string, response_headers: array<int|string, mixed>}
 */
function http_post_json_stream(
    string $url,
    string $payload,
    array $allHeaders,
    int $timeoutSec,
    ?RunLogger $logger,
    int $requestId,
    float $t0
): array {
    $headersFlat = [];
    foreach ($allHeaders as $k => $v) {
        $headersFlat[] = $k . ': ' . $v;
    }

    $prevTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', (string) $timeoutSec);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headersFlat),
            'content' => $payload,
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    ini_set('default_socket_timeout', (string) $prevTimeout);

    if ($raw === false) {
        throw new RuntimeException('خطا در اتصال به ' . $url . ' (allow_url_fopen یا شبکه)');
    }

    $meta = $http_response_header ?? [];
    $statusLine = isset($meta[0]) ? (string) $meta[0] : '';
    $status = 0;
    if (preg_match('#\s(\d{3})\s#', $statusLine, $m)) {
        $status = (int) $m[1];
    }

    $respHeaders = [];
    foreach ($meta as $i => $h) {
        if ($i === 0) {
            continue;
        }
        if (!str_contains((string) $h, ':')) {
            continue;
        }
        [$hk, $hv] = explode(':', (string) $h, 2);
        $respHeaders[trim($hk)] = trim($hv);
    }

    $json = null;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $json = $decoded;
    }

    $elapsed = (microtime(true) - $t0) * 1000.0;
    if ($logger !== null) {
        $logger->logHttpResponse($requestId, $status, $statusLine, $respHeaders, $raw, $json, $elapsed);
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP ' . $status . ' — بدنه: ' . substr($raw, 0, 500));
    }
    if ($json === null) {
        throw new RuntimeException('JSON پاسخ نامعتبر است. پیش‌نمایش: ' . substr($raw, 0, 400));
    }

    return [
        'json' => $json,
        'raw' => $raw,
        'status' => $status,
        'status_line' => $statusLine,
        'response_headers' => $respHeaders,
    ];
}

/** @param array<string, mixed> $decoded */
function bbox_from_request_body(array $decoded): array
{
    $map = $decoded['map_state']['camera_info']['bbox'] ?? null;
    if (is_array($map)
        && isset($map['min_longitude'], $map['min_latitude'], $map['max_longitude'], $map['max_latitude'])) {
        return [
            (float) $map['min_longitude'],
            (float) $map['min_latitude'],
            (float) $map['max_longitude'],
            (float) $map['max_latitude'],
        ];
    }

    $vals = $decoded['search_data']['form_data']['data']['bbox']['repeated_float']['value'] ?? null;
    if (is_array($vals) && count($vals) >= 4) {
        $nums = [];
        foreach ($vals as $item) {
            if (is_array($item) && array_key_exists('value', $item)) {
                $nums[] = (float) $item['value'];
            }
        }
        if (count($nums) >= 4) {
            return [$nums[0], $nums[1], $nums[2], $nums[3]];
        }
    }

    throw new RuntimeException('bbox در بدنهٔ اولیه یافت نشد.');
}

/**
 * @param mixed $body
 * @return mixed
 */
function set_bbox_in_body($body, float $minLon, float $minLat, float $maxLon, float $maxLat)
{
    if (!is_array($body)) {
        return $body;
    }

    if (isset($body['map_state']) && is_array($body['map_state'])) {
        if (!isset($body['map_state']['camera_info']) || !is_array($body['map_state']['camera_info'])) {
            $body['map_state']['camera_info'] = [];
        }
        $body['map_state']['camera_info']['bbox'] = [
            'min_longitude' => $minLon,
            'min_latitude' => $minLat,
            'max_longitude' => $maxLon,
            'max_latitude' => $maxLat,
        ];
    }

    if (isset($body['search_data']['form_data']['data']) && is_array($body['search_data']['form_data']['data'])) {
        $body['search_data']['form_data']['data']['bbox'] = [
            'repeated_float' => [
                'value' => [
                    ['value' => $minLon],
                    ['value' => $minLat],
                    ['value' => $maxLon],
                    ['value' => $maxLat],
                ],
            ],
        ];
    }

    return $body;
}

/**
 * @param mixed $body
 * @return mixed
 */
function set_zoom_in_body($body, float $zoom)
{
    if (!is_array($body)) {
        return $body;
    }
    if (isset($body['map_state']['camera_info']) && is_array($body['map_state']['camera_info'])) {
        $body['map_state']['camera_info']['zoom'] = $zoom;
    }
    return $body;
}

/** @return array<string, array<string, mixed>> */
function extract_posts_from_list_widgets(?array $listWidgets, bool $lite = false): array
{
    $out = [];
    if ($listWidgets === null) {
        return $out;
    }
    foreach ($listWidgets as $w) {
        if (!is_array($w) || ($w['widget_type'] ?? '') !== 'POST_ROW') {
            continue;
        }
        $data = $w['data'] ?? null;
        if (!is_array($data)) {
            continue;
        }
        $token = null;
        if (isset($data['action']['payload']['token'])) {
            $token = (string) $data['action']['payload']['token'];
        } elseif (isset($data['token'])) {
            $token = (string) $data['token'];
        }
        if ($token === null || $token === '') {
            continue;
        }
        $row = [
            'token' => $token,
            'title' => $data['title'] ?? null,
            'middle_description_text' => $data['middle_description_text'] ?? null,
            'bottom_description_text' => $data['bottom_description_text'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'web_info' => $data['action']['payload']['web_info'] ?? null,
        ];
        if (!$lite) {
            $row['raw_post_row'] = $data;
        }
        $out[$token] = $row;
    }
    return $out;
}

/**
 * @param array<string, mixed> $baseBody
 * @return array<int, array<string, mixed>>
 */
function fetch_extra_pages(
    string $url,
    array $baseBody,
    array $firstResponse,
    array $extraHeaders,
    int $timeoutSec,
    int $sleepUs,
    int $paginateMax,
    ?RunLogger $logger,
    int &$globalRequestCount,
    int $maxRequests,
    ?float $deadlineMono,
    array &$ctx
): array {
    $pages = [];
    $resp = $firstResponse;
    for ($p = 0; $p < $paginateMax; $p++) {
        if (is_runtime_deadline_passed($deadlineMono)) {
            if ($logger !== null) {
                $logger->logEvent('pagination_stopped_max_runtime', ['requests_so_far' => $globalRequestCount]);
            }
            if (($ctx['stop_reason'] ?? null) === null) {
                $ctx['stop_reason'] = 'max_runtime';
            }
            break;
        }

        $pag = $resp['pagination'] ?? null;
        if (!is_array($pag) || empty($pag['has_next_page'])) {
            break;
        }
        $pagData = $pag['data'] ?? null;
        if (!is_array($pagData)) {
            break;
        }

        if ($globalRequestCount >= $maxRequests) {
            if ($logger !== null) {
                $logger->logEvent('pagination_stopped_max_requests', ['at' => $globalRequestCount]);
            }
            break;
        }

        $nextBody = deep_copy_array($baseBody);
        $nextBody['pagination'] = [
            'has_next_page' => true,
            'data' => $pagData,
            'is_first_page' => false,
        ];

        usleep($sleepUs);
        if (is_runtime_deadline_passed($deadlineMono)) {
            if ($logger !== null) {
                $logger->logEvent('pagination_stopped_max_runtime_after_sleep', ['requests_so_far' => $globalRequestCount]);
            }
            if (($ctx['stop_reason'] ?? null) === null) {
                $ctx['stop_reason'] = 'max_runtime';
            }
            break;
        }

        $globalRequestCount++;
        $got = http_post_json($url, $nextBody, $extraHeaders, $timeoutSec, $logger, $globalRequestCount);
        $pages[] = $got['json'];
        $resp = $got['json'];
    }
    return $pages;
}

/**
 * @param array<string, mixed> $accumPosts
 * @param array<int|string, mixed> $extraResponses
 */
function merge_responses_into(array &$accumPosts, array $extraResponses, bool $lite): void
{
    foreach ($extraResponses as $jr) {
        if (!is_array($jr)) {
            continue;
        }
        $lw = $jr['list_widgets'] ?? null;
        if (!is_array($lw)) {
            continue;
        }
        foreach (extract_posts_from_list_widgets($lw, $lite) as $tok => $row) {
            $accumPosts[$tok] = $row;
        }
    }
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $accumPosts
 */
function crawl_quad(array &$ctx, array &$accumPosts): void
{
    $deadlineMono = $ctx['deadline_mono'] ?? null;
    if (is_runtime_deadline_passed($deadlineMono)) {
        if ($ctx['logger'] ?? null) {
            /** @var RunLogger $lg */
            $lg = $ctx['logger'];
            $lg->logEvent('crawl_skip_max_runtime', [
                'depth' => $ctx['depth'] ?? -1,
                'elapsed_sec' => isset($ctx['run_started_mono']) ? round(microtime(true) - (float) $ctx['run_started_mono'], 3) : null,
            ]);
        }
        if (($ctx['stop_reason'] ?? null) === null) {
            $ctx['stop_reason'] = 'max_runtime';
        }
        return;
    }

    $url = $ctx['url'];
    /** @var array<string, mixed> $templateBody */
    $templateBody = $ctx['templateBody'];
    $baseZoom = $ctx['baseZoom'];
    $zoomStep = $ctx['zoomStep'];
    $minLon = $ctx['minLon'];
    $minLat = $ctx['minLat'];
    $maxLon = $ctx['maxLon'];
    $maxLat = $ctx['maxLat'];
    $depth = $ctx['depth'];
    $maxDepth = $ctx['maxDepth'];
    $minSizeDeg = $ctx['minSizeDeg'];
    $extraHeaders = $ctx['extraHeaders'];
    $sleepUs = $ctx['sleepUs'];
    $tryPagination = $ctx['tryPagination'];
    $paginateMax = $ctx['paginateMax'];
    $logger = $ctx['logger'];
    $timeoutSec = $ctx['timeoutSec'];
    $maxRequests = $ctx['maxRequests'];
    $deadlineMono = $ctx['deadline_mono'] ?? null;
    $lite = !empty($ctx['lite']);

    /** @var int $globalRequestCount */
    $globalRequestCount = &$ctx['globalRequestCount'];

    $width = $maxLon - $minLon;
    $height = $maxLat - $minLat;
    if ($width <= 0 || $height <= 0) {
        if ($logger !== null) {
            $logger->logEvent('crawl_skip_invalid_bbox', ['depth' => $depth, 'minLon' => $minLon, 'minLat' => $minLat, 'maxLon' => $maxLon, 'maxLat' => $maxLat]);
        }
        return;
    }

    if ($globalRequestCount >= $maxRequests) {
        if ($logger !== null) {
            $logger->logEvent('crawl_skip_max_requests', ['depth' => $depth, 'count' => $globalRequestCount]);
        }
        if (($ctx['stop_reason'] ?? null) === null) {
            $ctx['stop_reason'] = 'max_requests';
        }
        return;
    }

    if (is_runtime_deadline_passed($deadlineMono)) {
        if ($logger !== null) {
            $logger->logEvent('crawl_skip_max_runtime_before_request', ['depth' => $depth]);
        }
        if (($ctx['stop_reason'] ?? null) === null) {
            $ctx['stop_reason'] = 'max_runtime';
        }
        return;
    }

    // مهم: deep copy تا مرزهای سلول را روی قالب مشترک بازنویسی نکنیم
    $body = deep_copy_array($templateBody);
    $body = set_bbox_in_body($body, $minLon, $minLat, $maxLon, $maxLat);
    $body = set_zoom_in_body($body, min(19.0, $baseZoom + $depth * $zoomStep));

    if ($logger !== null) {
        $logger->logEvent('crawl_cell_begin', [
            'depth' => $depth,
            'bbox' => [$minLon, $minLat, $maxLon, $maxLat],
            'zoom' => min(19.0, $baseZoom + $depth * $zoomStep),
            'request_index_before' => $globalRequestCount,
        ]);
    }

    usleep($sleepUs);
    if (is_runtime_deadline_passed($deadlineMono)) {
        if ($logger !== null) {
            $logger->logEvent('crawl_skip_max_runtime_after_sleep', ['depth' => $depth]);
        }
        if (($ctx['stop_reason'] ?? null) === null) {
            $ctx['stop_reason'] = 'max_runtime';
        }
        return;
    }

    $globalRequestCount++;
    $pack = http_post_json($url, $body, $extraHeaders, $timeoutSec, $logger, $globalRequestCount);
    /** @var array<string, mixed> $json */
    $json = $pack['json'];

    $fromMain = extract_posts_from_list_widgets($json['list_widgets'] ?? null, $lite);
    $beforeCount = count($accumPosts);
    foreach ($fromMain as $tok => $row) {
        $accumPosts[$tok] = $row;
    }
    $added = count($accumPosts) - $beforeCount;

    if ($logger !== null) {
        $logger->logEvent('crawl_cell_after_main_request', [
            'depth' => $depth,
            'new_tokens_this_cell' => $added,
            'total_unique_posts' => count($accumPosts),
        ]);
    }

    if ($tryPagination) {
        $extras = fetch_extra_pages(
            $url,
            $body,
            $json,
            $extraHeaders,
            $timeoutSec,
            $sleepUs,
            $paginateMax,
            $logger,
            $globalRequestCount,
            $maxRequests,
            $deadlineMono,
            $ctx
        );
        merge_responses_into($accumPosts, $extras, $lite);
        if ($logger !== null) {
            $logger->logEvent('crawl_cell_after_pagination', [
                'depth' => $depth,
                'extra_pages' => count($extras),
                'total_unique_posts' => count($accumPosts),
            ]);
        }
    }

    $shouldSplit = $depth < $maxDepth && $width >= $minSizeDeg && $height >= $minSizeDeg;
    if (!$shouldSplit) {
        if ($logger !== null) {
            $logger->logEvent('crawl_cell_no_split', [
                'depth' => $depth,
                'reason' => $depth >= $maxDepth ? 'max_depth' : 'bbox_too_small',
                'width' => $width,
                'height' => $height,
                'min_size_deg' => $minSizeDeg,
            ]);
        }
        return;
    }

    if (is_runtime_deadline_passed($deadlineMono)) {
        if ($logger !== null) {
            $logger->logEvent('crawl_skip_split_max_runtime', ['depth' => $depth, 'would_have_split' => true]);
        }
        if (($ctx['stop_reason'] ?? null) === null) {
            $ctx['stop_reason'] = 'max_runtime';
        }
        return;
    }

    $midLon = ($minLon + $maxLon) / 2.0;
    $midLat = ($minLat + $maxLat) / 2.0;

    $quads = [
        [$minLon, $minLat, $midLon, $midLat],
        [$midLon, $minLat, $maxLon, $midLat],
        [$minLon, $midLat, $midLon, $maxLat],
        [$midLon, $midLat, $maxLon, $maxLat],
    ];

    foreach ($quads as $qi => $q) {
        if (is_runtime_deadline_passed($deadlineMono)) {
            if ($logger !== null) {
                $logger->logEvent('crawl_stop_children_max_runtime', ['parent_depth' => $depth, 'child_index_skipped_from' => $qi]);
            }
            if (($ctx['stop_reason'] ?? null) === null) {
                $ctx['stop_reason'] = 'max_runtime';
            }
            break;
        }

        [$w, $s, $e, $n] = $q;
        $child = $ctx;
        $child['minLon'] = $w;
        $child['minLat'] = $s;
        $child['maxLon'] = $e;
        $child['maxLat'] = $n;
        $child['depth'] = $depth + 1;
        if ($logger !== null) {
            $logger->logEvent('crawl_recurse_child', [
                'parent_depth' => $depth,
                'child_index' => $qi,
                'child_bbox' => [$w, $s, $e, $n],
            ]);
        }
        crawl_quad($child, $accumPosts);
    }
}

function read_cookie_file(?string $path): array
{
    if ($path === null || $path === '') {
        return [];
    }
    if (!is_readable($path)) {
        throw new RuntimeException('فایل کوکی خوانا نیست: ' . $path);
    }
    $c = trim((string) file_get_contents($path));
    if ($c === '') {
        return [];
    }
    $c = preg_replace('#^Cookie:\s*#i', '', $c);
    return ['cookie' => $c !== null ? $c : ''];
}

/** @param array<int, string> $argv */
function main(array $argv): int
{
    array_shift($argv);
    if (count($argv) < 2) {
        fwrite(STDERR, "usage: php divar_map_collect.php <initial_request.json> <output.json> [options]\n");
        return 2;
    }

    $inPath = array_shift($argv);
    $outPath = array_shift($argv);

    $maxDepth = 5;
    $minSizeDeg = 0.00025;
    $sleepMs = 350;
    $zoomStep = 0.35;
    $cookieFile = null;
    $tryPagination = false;
    $paginateMax = 30;
    $logFile = null;
    $noLogFile = false;
    $quiet = false;
    $maxRequests = 5000;
    $timeoutSec = 60;
    $maxRuntimeSec = 0;
    $lite = false;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--max-depth=')) {
            $maxDepth = max(0, (int) substr($arg, strlen('--max-depth=')));
        } elseif (str_starts_with($arg, '--min-size-deg=')) {
            $minSizeDeg = max(1e-8, (float) substr($arg, strlen('--min-size-deg=')));
        } elseif (str_starts_with($arg, '--sleep-ms=')) {
            $sleepMs = max(0, (int) substr($arg, strlen('--sleep-ms=')));
        } elseif (str_starts_with($arg, '--zoom-step=')) {
            $zoomStep = (float) substr($arg, strlen('--zoom-step='));
        } elseif (str_starts_with($arg, '--cookie-file=')) {
            $cookieFile = substr($arg, strlen('--cookie-file='));
        } elseif ($arg === '--try-pagination') {
            $tryPagination = true;
        } elseif (str_starts_with($arg, '--paginate-max=')) {
            $paginateMax = max(1, (int) substr($arg, strlen('--paginate-max=')));
        } elseif (str_starts_with($arg, '--log-file=')) {
            $logFile = substr($arg, strlen('--log-file='));
        } elseif ($arg === '--no-log-file') {
            $noLogFile = true;
        } elseif ($arg === '--quiet') {
            $quiet = true;
        } elseif (str_starts_with($arg, '--max-requests=')) {
            $maxRequests = max(1, (int) substr($arg, strlen('--max-requests=')));
        } elseif (str_starts_with($arg, '--timeout-sec=')) {
            $timeoutSec = max(5, (int) substr($arg, strlen('--timeout-sec=')));
        } elseif (str_starts_with($arg, '--max-runtime-sec=')) {
            $maxRuntimeSec = max(0, (int) substr($arg, strlen('--max-runtime-sec=')));
        } elseif ($arg === '--lite' || $arg === '--search-summary-only') {
            $lite = true;
        }
    }

    $runStartedMono = microtime(true);
    $deadlineMono = $maxRuntimeSec > 0 ? $runStartedMono + $maxRuntimeSec : null;

    if (!is_readable($inPath)) {
        fwrite(STDERR, "فایل ورودی خوانا نیست: {$inPath}\n");
        return 1;
    }

    $rawIn = file_get_contents($inPath);
    if ($rawIn === false) {
        fwrite(STDERR, "خواندن فایل ورودی ناموفق.\n");
        return 1;
    }

    $template = json_decode($rawIn, true);
    if (!is_array($template)) {
        fwrite(STDERR, "بدنهٔ JSON اولیه نامعتبر است.\n");
        return 1;
    }

    [$minLon, $minLat, $maxLon, $maxLat] = bbox_from_request_body($template);

    $baseZoom = 14.0;
    if (isset($template['map_state']['camera_info']['zoom'])) {
        $baseZoom = (float) $template['map_state']['camera_info']['zoom'];
    }

    $extraHeaders = read_cookie_file($cookieFile);
    $sleepUs = $sleepMs * 1000;

    $accum = [];
    $url = DEFAULT_ENDPOINT;

    if (!$noLogFile && $logFile === null) {
        $logFile = $outPath . '.collect.log';
    }

    $logger = null;
    if (!$noLogFile && $logFile !== null) {
        $logger = new RunLogger($logFile, $quiet);
        $logger->logEvent('run_start', [
            'input' => $inPath,
            'output' => $outPath,
            'bbox' => [$minLon, $minLat, $maxLon, $maxLat],
            'max_depth' => $maxDepth,
            'min_size_deg' => $minSizeDeg,
            'sleep_ms' => $sleepMs,
            'zoom_step' => $zoomStep,
            'try_pagination' => $tryPagination,
            'max_requests' => $maxRequests,
            'timeout_sec' => $timeoutSec,
            'max_runtime_sec' => $maxRuntimeSec > 0 ? $maxRuntimeSec : null,
            'deadline_mono' => $deadlineMono,
            'curl_available' => extension_loaded('curl'),
            'allow_url_fopen' => filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
            'php_version' => PHP_VERSION,
            'log_file' => $logFile,
            'lite_search_summary_only' => $lite,
        ]);
    } elseif ($quiet === false && $noLogFile) {
        fwrite(STDERR, "[hint] لاگ فایل با --no-log-file خاموش است؛ برای ذخیرهٔ ریکوئست/ریسپانس هر مرحله آن را بردارید یا --log-file=PATH بدهید.\n");
    }

    // قالب پایه را یک بار عمیق کپی می‌کنیم تا حین کراول، JSON ورودی کاربر دست‌نخورده بماند
    $templateImmutable = deep_copy_array($template);

    $ctx = [
        'url' => $url,
        'templateBody' => $templateImmutable,
        'baseZoom' => $baseZoom,
        'zoomStep' => $zoomStep,
        'minLon' => $minLon,
        'minLat' => $minLat,
        'maxLon' => $maxLon,
        'maxLat' => $maxLat,
        'depth' => 0,
        'maxDepth' => $maxDepth,
        'minSizeDeg' => $minSizeDeg,
        'extraHeaders' => $extraHeaders,
        'sleepUs' => $sleepUs,
        'tryPagination' => $tryPagination,
        'paginateMax' => $paginateMax,
        'logger' => $logger,
        'timeoutSec' => $timeoutSec,
        'maxRequests' => $maxRequests,
        'globalRequestCount' => 0,
        'deadline_mono' => $deadlineMono,
        'run_started_mono' => $runStartedMono,
        'stop_reason' => null,
        'lite' => $lite,
    ];

    if (!$quiet) {
        $rtMsg = $deadlineMono !== null ? ' — حداکثر زمان اجرا: ' . $maxRuntimeSec . 's' : '';
        fwrite(STDERR, "شروع جمع‌آوری — bbox: [{$minLon}, {$minLat}, {$maxLon}, {$maxLat}] depth≤{$maxDepth}{$rtMsg}\n");
        if ($logger !== null) {
            fwrite(STDERR, "لاگ جزئیات: {$logFile}\n");
        }
    }

    try {
        crawl_quad($ctx, $accum);
    } catch (Throwable $e) {
        if ($logger !== null) {
            $logger->logEvent('fatal_error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
        return 1;
    }

    if (($ctx['stop_reason'] ?? null) === null) {
        $ctx['stop_reason'] = 'complete';
    }
    if ($logger !== null) {
        $logger->logEvent('run_end', [
            'http_requests' => $ctx['globalRequestCount'],
            'unique_posts' => count($accum),
            'elapsed_wall_sec' => round(microtime(true) - $runStartedMono, 3),
            'stop_reason' => $ctx['stop_reason'],
            'max_runtime_sec' => $maxRuntimeSec > 0 ? $maxRuntimeSec : null,
            'lite' => $lite,
        ]);
    }

    $export = [
        'meta' => [
            'endpoint' => $url,
            'bbox' => ['min_lon' => $minLon, 'min_lat' => $minLat, 'max_lon' => $maxLon, 'max_lat' => $maxLat],
            'unique_posts' => count($accum),
            'http_requests' => $ctx['globalRequestCount'],
            'elapsed_wall_sec' => round(microtime(true) - $runStartedMono, 3),
            'stop_reason' => $ctx['stop_reason'],
            'max_runtime_sec' => $maxRuntimeSec > 0 ? $maxRuntimeSec : null,
            'lite_search_summary_only' => $lite,
            'collected_at' => gmdate('c'),
        ],
        'tokens' => array_keys($accum),
        'posts' => $accum,
    ];

    $encoded = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        fwrite(STDERR, "json_encode خروجی ناموفق.\n");
        return 1;
    }

    if (file_put_contents($outPath, $encoded) === false) {
        fwrite(STDERR, "نوشتن خروجی ناموفق: {$outPath}\n");
        return 1;
    }

    if (!$quiet) {
        $reason = $ctx['stop_reason'] !== null ? ' — توقف: ' . $ctx['stop_reason'] : '';
        fwrite(STDERR, 'تمام — زمان wall: ' . round(microtime(true) - $runStartedMono, 2) . 's — درخواست‌ها: '
            . $ctx['globalRequestCount'] . ' — آگهی یکتا: ' . count($accum) . $reason . " → {$outPath}\n");
    }
    return 0;
}

exit(main($argv));
