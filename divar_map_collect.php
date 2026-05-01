#!/usr/bin/env php
<?php
/**
 * جمع‌آوری آگهی‌های نقشهٔ دیوار برای یک bbox اولیه (pure PHP، بدون Composer).
 *
 * تحلیل کوتاه درخواست/پاسخ:
 * - بدنهٔ POST شامل city_ids، map_state.camera_info (bbox + zoom + place_hash)،
 *   و search_data.form_data.data با bbox به صورت repeated_float به ترتیب:
 *   [min_longitude, min_latitude, max_longitude, max_latitude]
 * - پاسخ JSON معمولاً list_widgets با widget_type=POST_ROW دارد؛ token آگهی در
 *   data.action.payload.token یا data.token است.
 * - برای «تمام» آگهی‌های یک ناحیه، سرور در زوم کم جزئیات محدود می‌فرستد؛ این اسکریپت
 *   bbox را به صورت بازگشتی به ۴ زیرمستطیل تقسیم می‌کند (شبیه زوم بیشتر روی نقشه)،
 *   نتایج را با کلید token ادغام می‌کند و در یک فایل JSON ذخیره می‌کند.
 *
 * استفاده:
 *   php divar_map_collect.php initial_request.json output.json [گزینه‌ها]
 *
 * گزینه‌ها:
 *   --max-depth=N      عمق تقسیم بازگشتی (پیش‌فرض 5)
 *   --min-size-deg=X   حداقل اندازهٔ ضلع bbox به درجه؛ زیر این مقدار تقسیم متوقف می‌شود (پیش‌فرض 0.00025)
 *   --sleep-ms=N       فاصله بین درخواست‌ها به میلی‌ثانیه (پیش‌فرض 350)
 *   --zoom-step=X      افزایش zoom در هر سطح عمق (پیش‌فرض 0.35)
 *   --cookie-file=F    مسیر فایل حاوی هدر Cookie (اختیاری؛ برای جلسهٔ لاگین)
 *   --try-pagination   تلاش برای صفحه‌بندی با فیلدهای pagination برگشتی (آزمایشی؛ ممکن است API نیاز به شکل دیگری داشته باشد)
 *   --paginate-max=N   حداکثر صفحات اضافه در هر سلول در حالت --try-pagination (پیش‌فرض 30)
 *
 * محدودیت‌ها و هشدار حقوقی:
 * - استفادهٔ انبوه ممکن است با محدودیت‌های سرور یا شرایط استفادهٔ دیوار مغایرت داشته باشد.
 * - این ابزار صرفاً برای تحلیل فنی ساختار درخواست است؛ مسئولیت استفاده با خودتان است.
 */

declare(strict_types=1);

const DEFAULT_ENDPOINT = 'https://api.divar.ir/v8/postlist/w/search';

/** @return array<string, string> */
function default_headers(): array
{
    return [
        'accept' => 'application/json, text/plain, */*',
        'accept-language' => 'fa,en-US;q=0.9,en;q=0.8',
        'content-type' => 'application/json',
        'origin' => 'https://divar.ir',
        'referer' => 'https://divar.ir/',
        'user-agent' => 'Mozilla/5.0 (compatible; DivarMapCollector/1.0; +https://divar.ir)',
        'x-render-type' => 'CSR',
        'x-standard-divar-error' => 'true',
    ];
}

function http_post_json(string $url, array $body, array $extraHeaders = [], int $timeoutSec = 60): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        throw new RuntimeException('json_encode بدنهٔ درخواست ناموفق بود.');
    }

    $headersFlat = [];
    foreach (array_merge(default_headers(), $extraHeaders) as $k => $v) {
        $headersFlat[] = $k . ': ' . $v;
    }

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
    if ($raw === false) {
        throw new RuntimeException('خطا در اتصال به ' . $url);
    }

    /** @var array<string, mixed>|null $meta */
    $meta = $http_response_header ?? null;
    $statusLine = is_array($meta) && isset($meta[0]) ? $meta[0] : '';
    if (!preg_match('#\s(\d{3})\s#', $statusLine, $m)) {
        throw new RuntimeException('پاسخ HTTP نامعتبر: ' . $statusLine);
    }
    $code = (int) $m[1];
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' — بدنه: ' . mb_substr($raw, 0, 500));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON پاسخ نامعتبر است.');
    }

    return ['json' => $decoded, 'raw' => $raw];
}

/** @param mixed $decoded */
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

/** @return array<string, array<string, mixed>> token => خلاصهٔ آگهی */
function extract_posts_from_list_widgets(?array $listWidgets): array
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
        $out[$token] = [
            'token' => $token,
            'title' => $data['title'] ?? null,
            'middle_description_text' => $data['middle_description_text'] ?? null,
            'bottom_description_text' => $data['bottom_description_text'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'web_info' => $data['action']['payload']['web_info'] ?? null,
            'raw_post_row' => $data,
        ];
    }
    return $out;
}

/**
 * ادغام نتایج صفحه‌بندی آزمایشی (در صورت پشتیبانی سرور).
 *
 * @param array<string, mixed> $baseBody
 * @return array<int, array<string, mixed>>
 */
function fetch_extra_pages(
    string $url,
    array $baseBody,
    array $firstResponse,
    array $extraHeaders,
    int $sleepUs,
    int $paginateMax
): array {
    $pages = [];
    $resp = $firstResponse;
    for ($p = 0; $p < $paginateMax; $p++) {
        $pag = $resp['pagination'] ?? null;
        if (!is_array($pag) || empty($pag['has_next_page'])) {
            break;
        }
        $pagData = $pag['data'] ?? null;
        if (!is_array($pagData)) {
            break;
        }

        $nextBody = $baseBody;
        $nextBody['pagination'] = [
            'has_next_page' => true,
            'data' => $pagData,
            'is_first_page' => false,
        ];

        usleep($sleepUs);
        $got = http_post_json($url, $nextBody, $extraHeaders);
        $pages[] = $got['json'];
        $resp = $got['json'];
    }
    return $pages;
}

/**
 * @param array<string, mixed> $accumPosts
 * @param array<int|string, mixed> $extraResponses
 */
function merge_responses_into(array &$accumPosts, array $extraResponses): void
{
    foreach ($extraResponses as $jr) {
        if (!is_array($jr)) {
            continue;
        }
        $lw = $jr['list_widgets'] ?? null;
        if (!is_array($lw)) {
            continue;
        }
        foreach (extract_posts_from_list_widgets($lw) as $tok => $row) {
            $accumPosts[$tok] = $row;
        }
    }
}

/**
 * @param array<string, mixed> $accumPosts
 */
function crawl_quad(
    string $url,
    array $templateBody,
    float $baseZoom,
    float $zoomStep,
    float $minLon,
    float $minLat,
    float $maxLon,
    float $maxLat,
    int $depth,
    int $maxDepth,
    float $minSizeDeg,
    array &$accumPosts,
    array $extraHeaders,
    int $sleepUs,
    bool $tryPagination,
    int $paginateMax
): void {
    $width = $maxLon - $minLon;
    $height = $maxLat - $minLat;
    if ($width <= 0 || $height <= 0) {
        return;
    }

    $body = $templateBody;
    $body = set_bbox_in_body($body, $minLon, $minLat, $maxLon, $maxLat);
    $body = set_zoom_in_body($body, min(19.0, $baseZoom + $depth * $zoomStep));

    usleep($sleepUs);
    $pack = http_post_json($url, $body, $extraHeaders);
    /** @var array<string, mixed> $json */
    $json = $pack['json'];

    $fromMain = extract_posts_from_list_widgets($json['list_widgets'] ?? null);
    foreach ($fromMain as $tok => $row) {
        $accumPosts[$tok] = $row;
    }

    if ($tryPagination) {
        $extras = fetch_extra_pages($url, $body, $json, $extraHeaders, $sleepUs, $paginateMax);
        merge_responses_into($accumPosts, $extras);
    }

    $shouldSplit = $depth < $maxDepth && $width >= $minSizeDeg && $height >= $minSizeDeg;
    if (!$shouldSplit) {
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

    foreach ($quads as [$w, $s, $e, $n]) {
        crawl_quad(
            $url,
            $templateBody,
            $baseZoom,
            $zoomStep,
            $w,
            $s,
            $e,
            $n,
            $depth + 1,
            $maxDepth,
            $minSizeDeg,
            $accumPosts,
            $extraHeaders,
            $sleepUs,
            $tryPagination,
            $paginateMax
        );
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
    return ['cookie' => $c];
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
        }
    }

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

    fwrite(STDOUT, "شروع جمع‌آوری — bbox: [{$minLon}, {$minLat}, {$maxLon}, {$maxLat}] depth≤{$maxDepth}\n");

    crawl_quad(
        $url,
        $template,
        $baseZoom,
        $zoomStep,
        $minLon,
        $minLat,
        $maxLon,
        $maxLat,
        0,
        $maxDepth,
        $minSizeDeg,
        $accum,
        $extraHeaders,
        $sleepUs,
        $tryPagination,
        $paginateMax
    );

    $export = [
        'meta' => [
            'endpoint' => $url,
            'bbox' => ['min_lon' => $minLon, 'min_lat' => $minLat, 'max_lon' => $maxLon, 'max_lat' => $maxLat],
            'unique_posts' => count($accum),
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

    fwrite(STDOUT, 'تمام — تعداد آگهی یکتا: ' . count($accum) . " → {$outPath}\n");
    return 0;
}

exit(main($argv));
