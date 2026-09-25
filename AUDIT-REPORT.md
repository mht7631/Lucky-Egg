# Lucky Egg 1.1.0: گزارش Audit + Fix

نسخه‌ی مبنا: 1.0.0 (فایل ارسالی) · نسخه‌ی خروجی: 1.1.0 (DB schema 1.1.0)
روش: بازبینی کامل تک‌تک فایل‌ها (PHP/JS/CSS/POT/uninstall) و بعد cross-check وابستگی‌ها بعد از هر اصلاح.

## ۱) فهرست ایرادها، شدت، علت و اصلاح

| # | شدت | ایراد | علت | اصلاح انجام‌شده |
|---|---|---|---|---|
| 1 | **Critical** | Login به حساب موجود فقط با شماره موبایل | `register_or_login()` حساب دارای همان `lucky_egg_phone` را پیدا می‌کرد و با `wp_set_auth_cookie(remember=true)` لاگین می‌کرد. هر کس شماره‌ی کسی را می‌دانست وارد حساب او می‌شد (با WooCommerce یعنی دسترسی به آدرس/سفارش‌ها؛ فیلتر `lucky_egg_allow_auto_login` هم می‌توانست دامنه را به نقش‌های دیگر باز کند). | کل مسیر Auto-login حذف شد (`register_or_login`، `can_auto_login`، `login`، `find_user_id_by_phone`). افزونه دیگر هرگز `wp_set_auth_cookie` / `wp_set_current_user` صدا نمی‌زند و `wp_login` جعلی fire نمی‌کند. |
| 2 | **High** | ساخت حساب WordPress برای Guest بدون اثبات مالکیت شماره | حساب بدون ایمیل و بدون رمز قابل‌استفاده ساخته می‌شد؛ مهاجم می‌توانست شماره‌ی دیگران را «اشغال» کند (صاحب واقعی بعداً `account_protected` می‌گرفت و راهی برای ورود نداشت) و با چرخش IP حساب اسپم بسازد. | Guest بدون ساخت حساب بازی می‌کند. نام/موبایل بعد از اعتبارسنجی در **Guest Session سمت سرور** (transient با کلید HMAC از cookie تصادفی + campaign، TTL 30 دقیقه، یک‌بارمصرف) نگه داشته می‌شود. Entry با `user_id = NULL` ثبت می‌شود. OTP اضافه نشد چون بدون آن هم Spec (نمایش کد + SMS) کامل کار می‌کند و OTP یک وابستگی SMS همزمان به مسیر اصلی اضافه می‌کرد. |
| 3 | **High** | نشست‌های صادرشده در 1.0.0 همچنان معتبر | کوکی‌های Auto-login تا ۱۴ روز معتبر می‌ماندند. | Migration 1.1.0 همه‌ی sessionهای حساب‌های `lucky_egg_created=1` را در batchهای ۲۰۰تایی با WP-Cron باطل می‌کند (`WP_Session_Tokens::destroy_all`). خود حساب‌ها و Entryها حذف نمی‌شوند. |
| 4 | **High** | Guest در کمپین «فقط اعضا» به Login هدایت نمی‌شد | Shortcode برای Guest خروجی خالی برمی‌گرداند و AJAX فقط `not_allowed` می‌داد. | Egg رندر می‌شود؛ سرور با کد `login_required` و `login_url` (همان `wp_login_url()` با redirect به همان صفحه، معتبرسازی‌شده با `wp_validate_redirect`، فیلتر `lucky_egg_login_url`) جواب می‌دهد و JS دکمه‌ی ورود نشان می‌دهد. بعد از Login صفحه دوباره لود و وضعیت از WordPress خوانده می‌شود. این تصمیم در زمان کلیک گرفته می‌شود پس پشت Page Cache هم درست است. |
| 5 | **High** | دور زدن Rate Limit با X-Forwarded-For | با `trust_proxy` مقدار **چپ‌ترین** XFF (که کاملاً دست کلاینت است) خوانده می‌شد. | برای XFF مقدار راست‌ترین (اضافه‌شده توسط proxy) خوانده می‌شود؛ ترتیب: CF-Connecting-IP → X-Real-IP → XFF. |
| 6 | **Medium** | دور زدن Rate Limit با IPv6 | هر کلاینت IPv6 معمولاً یک /64 کامل دارد و آدرسش را می‌چرخاند. | کلید IP برای Rate Limit در IPv6 روی /64 گروه می‌شود و IPv4-mapped به IPv4 برمی‌گردد (`get_rate_ip()`). IP واقعی همچنان در Entry ذخیره می‌شود. |
| 7 | **Medium** | نبود لایه‌ی شماره در Rate Limit بعد از حذف حساب Guest | در 1.0.0 شماره عملاً از طریق user_id محدود می‌شد. | ستون `phone` به `lucky_egg_rate_limits` اضافه شد (+ index) و در شرط OR لحاظ می‌شود: cookie ∨ IP ∨ user_id ∨ phone. این لایه SMS-bombing را هم به یک پیام در هر پنجره‌ی کمپین برای هر شماره محدود می‌کند. داده‌ی قدیمی از Entryها backfill می‌شود. |
| 8 | **Medium** | لاگ خطاها داخل Transaction از بین می‌رفت | `Lucky_Egg_Logger` در option می‌نوشت، روی همان connection، قبل از `ROLLBACK`، پس لاگ خطا هم rollback می‌شد. | Logger در حین Transaction بافر می‌کند (`defer()` / `flush()`) و بعد از COMMIT/ROLLBACK می‌نویسد. |
| 9 | **Medium** | GET_LOCK: نبود safety net و catch برای Exception | در Exception بین `START TRANSACTION` و `COMMIT` هیچ rollback/release صریحی نبود (با اتصال persistent، Lock می‌ماند). در `register_or_login` اگر Lock نمی‌گرفت، بدون Lock ادامه می‌داد. | بخش بحرانی داخل `try/catch (Throwable)` رفت؛ Lockهای گرفته‌شده ردیابی می‌شوند و `register_shutdown_function` در صورت باقی‌ماندن، ROLLBACK + RELEASE_LOCK می‌کند. ترتیب درست حفظ شد: Lock → check → TX → insert → COMMIT → release. قفل ناقص دوم به‌همراه همان کد حذف شد. |
| 10 | **Medium** | SMS: بدون retry و گیرکردن در `pending`/`sending` | WP-Cron ممکن است دیر یا هرگز اجرا نشود؛ اگر worker وسط ارسال می‌مرد، `sending` برای همیشه می‌ماند؛ هر خطای موقت فوراً `failed` می‌شد. | ستون‌های `sms_attempts` و `sms_updated_at`؛ حداکثر ۳ تلاش با back-off (۵ و ۱۰ دقیقه)؛ خطای config مستقیم `failed`؛ **Sweeper ساعتی** (`lucky_egg_sms_sweep`) رکوردهای `pending` بی‌رویداد (>۱۰ دقیقه) و `sending` گیرکرده (>۱۵ دقیقه) را دوباره صف می‌کند؛ claim اتمیک از ارسال دوباره جلوگیری می‌کند. کد همیشه اول نمایش داده می‌شود و شکست SMS فقط `sms_status` را تغییر می‌دهد. |
| 11 | **Medium** | Nonce کهنه پشت Full-Page Cache | Nonce داخل HTML کش‌شده بعد از ۱۲ تا ۲۴ ساعت نامعتبر می‌شد و Guestها `invalid_nonce` می‌گرفتند. | Endpoint `lucky_egg_nonce` (بدون CORS، با هدرهای no-cache خود admin-ajax) و retry خودکار یک‌باره در JS. |
| 12 | **Medium** | آمار «شرکت‌کنندگان» Guestها را نمی‌شمرد | `COUNT(DISTINCT user_id)` ردیف‌های NULL را حذف می‌کند (بعد از اصلاح #2 همه‌ی Guestها). | `COUNT(DISTINCT phone)` در آمار کلی و آمار هر کمپین. |
| 13 | **Medium** | ویجت المنتور در ادیتور re-init نمی‌شد | `elementor/frontend/init` با jQuery trigger می‌شود و `addEventListener` بومی آن را نمی‌گیرد. | Hook با jQuery + بومی + فراخوانی مستقیم (idempotent). |
| 14 | **Low** | Deprecation notice در Elementor 3.5+ | صرفِ hook کردن `widgets_registered` در 3.5+ notice می‌دهد. | Hook قدیمی فقط روی نسخه‌های < 3.5 و با رعایت ترتیب `elementor/loaded`. |
| 15 | **Low** | CSV غیر RFC 4180 | `fputcsv(..., '\\')` برای `\"` کوتیشن را دوتایی نمی‌کرد. | escape خالی (مجاز در PHP 7.4). BOM حفظ شد. |
| 16 | **Low** | CSV Injection ناقص | فضای خالی ابتدای مقدار و `|` پوشش داده نمی‌شد. | خنثی‌سازی مقادیر با whitespace ابتدایی و `= + - @ | %`. ستون‌های «نوع کاربر» و «شناسه کاربر» اضافه شد (مالکیت Entry). |
| 17 | **Low** | خراب شدن API key/Password هنگام ذخیره | `sanitize_text_field` کاراکترهای `%xx` و `<…>` را حذف می‌کند. | `sanitize_secret()`: فقط UTF-8 نامعتبر و کاراکترهای کنترلی حذف می‌شوند؛ مقدار همچنان هرگز چاپ نمی‌شود. |
| 18 | **Low** | تزریق لینک در SMS از طریق {name} | نام واردشده توسط بازدیدکننده از خط رسمی سایت به شماره‌ی دلخواه پیامک می‌شد (فیشینگ). | `sms_safe_name()` توکن‌های لینک‌مانند (`http`، `www.`، `.` `/` `@` `:`) را حذف می‌کند؛ فیلتر `lucky_egg_sms_name`. |
| 19 | **Low** | کاربر لاگین‌شده‌ی WooCommerce دوباره شماره می‌داد | فقط meta خود افزونه خوانده می‌شد. | `get_profile()`: meta افزونه → `billing_phone` / نام billing (WooCommerce) → first/last name → display_name. فقط وقتی هیچ شماره‌ای وجود ندارد مودال یک‌بار باز می‌شود و روی پروفایل **خود همان کاربر** ذخیره می‌شود. |
| 20 | **Low** | شمارش شماره بین حساب‌ها (enumeration) و phone squatting برای اعضا | `attach_phone` با `phone_taken` نشان می‌داد شماره متعلق به حساب دیگری است. | چون شماره دیگر شناسه/credential نیست، یکتایی سراسری لازم نیست؛ سوءاستفاده‌ی چندحسابی را لایه‌ی phone در Rate Limit می‌گیرد. |
| 21 | **Low** | JSON خراب در مسیر flush زودهنگام | `ob_end_flush` خروجی‌های سرگردان (notice) را قبل از JSON می‌فرستاد. | `ob_end_clean`. |
| 22 | **Low** | رویدادهای Cron بعد از deactivate/activate یا سایت جدید شبکه برنمی‌گشتند | فقط در `install()` زمان‌بندی می‌شدند. | `ensure_events()` روی `init` (ارزان؛ فقط `wp_next_scheduled`). |
| 23 | **Low** | WooCommerce HPOS هشدار «ناسازگار» | اعلام سازگاری وجود نداشت. | `declare_compatibility('custom_order_tables')` (افزونه هیچ سفارشی را نمی‌خواند یا نمی‌نویسد). |
| 24 | **Low** | POT قدیمی | رشته‌های جدید در قالب ترجمه نبودند. | POT از روی سورس بازتولید شد (۲۲۴ رشته، با کامنت translators و `php-format`). |

### مواردی که بررسی شدند و **درست بودند** (دست نخوردند)
Prepared queryها در همه‌ی کلاس‌ها · `dbDelta` (دو فاصله بعد از PRIMARY KEY، KEYهای درست) · تولید کد با `random_bytes` و الفبای ۳۲تایی بدون modulo bias + UNIQUE(code) + retry روی Duplicate · جداسازی کمپین‌ها (campaign همیشه سمت سرور از key resolve می‌شود) · CRUD ادمین با `manage_options` + `check_admin_referer` در همه‌ی handlerها · escaping کامل خروجی‌ها (`esc_html/esc_attr/esc_url/textContent`) · whitelist تنظیمات کمپین و رنگ‌ها · عدم نمایش مقدار Secretها · redact/scrub در Logger · RTL · بارگذاری Asset فقط در صفحات دارای Egg · uninstall چندسایته.

## ۲) فایل‌های تغییرکرده
`lucky-egg.php` · `uninstall.php` · `includes/class-lucky-egg.php` · `includes/class-lucky-egg-activator.php` · `includes/class-lucky-egg-ajax.php` · `includes/class-lucky-egg-user.php` · `includes/class-lucky-egg-rate-limiter.php` · `includes/class-lucky-egg-entry.php` · `includes/class-lucky-egg-sms.php` · `includes/class-lucky-egg-logger.php` · `includes/class-lucky-egg-shortcode.php` · `includes/class-lucky-egg-elementor.php` · `includes/class-lucky-egg-admin.php` · `assets/js/front.js` · `assets/css/front.css` · `languages/lucky-egg.pot`

فایل جدید: فقط همین گزارش. فایل حذف‌شده: ندارد.
تغییر نکردند: `class-lucky-egg-campaign.php`، `elementor-widget.php`، `admin.js`، `admin.css`، SVGها، `index.php`ها.

## ۳) تغییرات قرارداد (برای توسعه‌دهنده‌ها)
- کدهای خطای حذف‌شده: `account_protected`، `phone_taken`. کدهای جدید: `login_required` (با `login_url`)، `info_required`.
- Action حذف‌شده: `lucky_egg_user_registered`. فیلتر حذف‌شده: `lucky_egg_allow_auto_login`.
- فیلترهای جدید: `lucky_egg_login_url`، `lucky_egg_user_profile`، `lucky_egg_sms_name`.
- Cron جدید: `lucky_egg_sms_sweep` (ساعتی)، `lucky_egg_revoke_legacy_sessions` (یک‌باره، batch).
- تنظیم `registration_limit` حالا یعنی «حداکثر ثبت اطلاعات مهمان از یک IP در ساعت».

## ۴) وضعیت نهایی
**امنیت:** افزونه هیچ مسیر احراز هویت موازی ندارد؛ هویت فقط از `is_user_logged_in()` / `wp_get_current_user()` می‌آید. شماره موبایل نه credential است نه proof of ownership. Nonce روی همه‌ی endpointهای state-changing، ادمین با capability + nonce، کوئری‌ها prepared، خروجی‌ها escaped.
**ریسک‌های باقی‌مانده (ذاتی، بدون OTP):** (الف) Guest می‌تواند با شماره‌ی شخص دیگری شرکت کند و فرصت آن شماره را در همان پنجره مصرف کند؛ (ب) Guest مصمم با cookie جدید + IP جدید + شماره‌ی جدید دوباره شرکت می‌کند. اگر کمپین ارزش مالی دارد: «فقط اعضا» یا افزودن OTP در نسخه‌ی بعد. (ج) لایه‌ی IP روی CGNAT اپراتورهای موبایل ممکن است کاربران واقعی پشت یک IP را هم محدود کند (رفتار Spec حفظ شد). (د) پیام rate_limited نشان می‌دهد یک شماره در کمپین شرکت کرده است.
**معماری:** همان ساختار کلاس‌ها و DI حفظ شد؛ تغییرات حداقلی و محلی.
**سازگاری:** PHP 7.4 (بدون syntax مخصوص PHP 8)، WordPress 6.x (textdomain روی init)، WooCommerce (HPOS، billing fields)، Elementor قدیم و جدید.

## ۵) چیزهایی که runtime-test نشدند
در محیط من PHP/MySQL/WordPress وجود نداشت؛ فقط بررسی ایستا، تطبیق ارجاع‌ها بین فایل‌ها، بررسی توازن syntax همه‌ی فایل‌های PHP و `node --check` برای JS انجام شد. این موارد نیاز به تست واقعی دارند:
1. `php -l` و PHPCS (WPCS) روی PHP 7.4 و 8.x.
2. Migration از 1.0.0 → 1.1.0 (ستون‌های جدید با dbDelta، backfill phone، revoke sessions روی تعداد زیاد کاربر).
3. همزمانی واقعی (دو درخواست crack موازی) و GET_LOCK، به‌خصوص با drop-inهای HyperDB/LudicrousDB (GET_LOCK باید روی primary اجرا شود).
4. ارسال واقعی SMS با کاوه‌نگار/ملی‌پیامک/SMS.ir، رفتار retry و Sweeper با WP-Cron و با `DISABLE_WP_CRON` + cron سیستمی.
5. مسیر `fastcgi_finish_request` / LiteSpeed.
6. Page cache (LiteSpeed/WP Rocket/Cloudflare APO) و refresh nonce.
7. ویجت Elementor در ادیتور و preview (قدیم < 3.5 و جدید).
8. WooCommerce + HPOS و خواندن `billing_phone`.
9. Login redirect با افزونه‌هایی که صفحه‌ی ورود را عوض می‌کنند.
10. باز کردن CSV در Excel/LibreOffice (BOM و فارسی).
