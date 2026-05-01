# divarmap

اسکریپت PHP برای جمع‌آوری آگهی‌های نقشهٔ دیوار از روی همان بدنهٔ `POST` که از مرورگر کپی می‌کنید.

## فایل‌ها

- `divar_map_collect.php` — جمع‌آوری با تقسیم بازگشتی bbox (شبیه زوم بیشتر)
- `example_initial_request.json` — نمونهٔ بدنهٔ اولیه

## اجرا

```bash
php divar_map_collect.php example_initial_request.json out.json --max-depth=6 --sleep-ms=400
```

اگر نیاز به کوکی جلسه دارید:

```bash
php divar_map_collect.php my_body.json out.json --cookie-file=cookies.txt
```

(`cookies.txt` فقط یک خط با مقدار هدر `Cookie: ...` یا خود رشتهٔ کوکی.)

گزینهٔ `--try-pagination` صفحهٔ بعد را با فیلدهای `pagination` پاسخ امتحان می‌کند؛ اگر سرور بدنهٔ دیگری بخواهد، باید از DevTools همان درخواست را ضبط کنید.

### محدودیت زمان اجرا (کاور تا سقف زمان)

برای مثال **فقط یک دقیقه** زوم/تقسیم و جمع‌آوری، بدون اینکه تا عمق کامل برود:

```bash
php divar_map_collect.php example_initial_request.json out.json \
  --max-runtime-sec=60 \
  --max-depth=12 \
  --sleep-ms=100
```

- `--max-runtime-sec=0` (پیش‌فرض) یعنی بدون محدودیت زمانی تا زمانی که `max-depth` یا `max-requests` برسد.
- هنگام رسیدن به مهلت، کراول متوقف می‌شود؛ در `meta.stop_reason` مقدار `max_runtime` و در `meta.elapsed_wall_sec` زمان واقعی wall-clock ذخیره می‌شود.

### فقط خلاصهٔ نتیجهٔ سرچ (بدون `raw_post_row`)

API همان **لیست جست‌وجو** را برمی‌گرداند؛ اسکریپت به‌طور پیش‌فرض کل آبجکت ردیف (`raw_post_row`) را هم ذخیره می‌کند. برای **فقط فیلدهای کوتاه** (عنوان، تصویر، قیمت/توضیح میانی، زمان/محله، `web_info`):

```bash
php divar_map_collect.php body.json out.json --lite
```

یا معادل: `--search-summary-only`

برای **حداکثر ۶ درخواست HTTP** و همچنان تقسیم نقشه تا عمق ۶ (بعد از ۶ درخواست متوقف می‌شود):

```bash
php divar_map_collect.php body.json out.json --lite --max-depth=6 --max-requests=6
```

در `meta` فیلد **`lite_search_summary_only`: true** ثبت می‌شود.

### لاگ دیباگ (ریکوئست / ریسپانس هر مرحله)

به‌صورت پیش‌فرض کنار فایل خروجی، فایل `*.collect.log` ساخته می‌شود و شامل:

- بدنهٔ JSON هر درخواست و هدرها (کوکی کوتاه می‌شود)
- وضعیت HTTP، هدرهای پاسخ، خلاصهٔ تعداد ویجت‌ها، و **کل بدنهٔ خام + JSON decode شده**

گزینه‌ها:

- `--log-file=/path/run.log` — مسیر دلخواه
- `--no-log-file` — بدون فایل لاگ
- `--quiet` — بدون چاپ روی ترمینال (فقط نوشتن در فایل لاگ)

### باگ قبلی (چرا «روی همان کد می‌ماند»)

در PHP آرایه‌ها **کپی سطحی** می‌شوند؛ بدون `deep_copy_array` همهٔ شاخه‌های بازگشتی همان bbox را روی یک آبجکت مشترک می‌نوشتند و نتیجه غلط یا تکراری می‌شد. الان هر سلول قبل از تغییر bbox عمیق کپی می‌شود.

## خطای `Parse error: Unmatched '}'`

اگر این خطا را می‌بینید، معمولاً فایل **`divar_map_collect.php` قدیمی یا دست‌خورده** است (یک `}` اضافه یا کم).

1. آخرین نسخه را از **`main`** بگیرید: `git pull` یا دانلود مجدد ZIP از GitHub.
2. چک کنید:

```bash
php -l divar_map_collect.php
```

باید بنویسد `No syntax errors`.

3. یا از همین ریپو:

```bash
php tools/check_braces.php divar_map_collect.php
```

## هشدار

استفادهٔ خودکار از API دیوار ممکن است با محدودیت نرخ یا شرایط استفاده مغایرت داشته باشد؛ مسئولیت با خود کاربر است.
