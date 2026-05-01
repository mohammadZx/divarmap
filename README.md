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

## هشدار

استفادهٔ خودکار از API دیوار ممکن است با محدودیت نرخ یا شرایط استفاده مغایرت داشته باشد؛ مسئولیت با خود کاربر است.
