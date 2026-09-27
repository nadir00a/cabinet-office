# نظام مكتب المحاسبة — PHP + MySQL

نسخة PHP خام (PDO) مهيأة للعمل على XAMPP، مع واجهة عربية RTL وتصميم حديث ومتجاوب.

## التشغيل على XAMPP

1. انسخ المجلد `php-app` إلى:
   `C:\xampp\htdocs\cabinet-office`
2. شغّل Apache و MySQL من XAMPP.
3. افتح phpMyAdmin واستورد `database/schema.sql`. الملف ينشئ قاعدة البيانات `cabinet_office` ويختارها تلقائيًا.
4. اختياريًا استورد `database/seed_sample_data.sql` لإضافة بيانات تجريبية.
5. راجع `config/db.php` إذا كانت إعدادات MySQL مختلفة عن XAMPP الافتراضي.
6. افتح:
   `http://localhost/cabinet-office/database/setup_users.php`
   مرة واحدة لإنشاء حسابات الاختبار، ثم **احذف الملف**.
7. افتح:
   `http://localhost/cabinet-office/`

### حسابات الاختبار

- `manager` / `password123`
- `accountant1` / `password123`
- `accountant2` / `password123`
- `reception1` / `password123`

غيّر كلمات المرور قبل الاستخدام الفعلي.

## ما تم إصلاحه في هذه النسخة

- توحيد كل الروابط الداخلية على `/cabinet-office` حتى لا تظهر أخطاء Apache مثل `Not Found`.
- إصلاح تسجيل الخروج وإعادة التوجيه.
- إصلاح رابط CSS والقائمة والنماذج التي كانت تستخدم مسارات جذرية.
- تحسين الجلسات: HttpOnly + SameSite و session regeneration بعد تسجيل الدخول.
- الحفاظ على PDO و prepared statements و CSRF الموجودين في المشروع.
- إضافة تحقق أفضل لبعض حالات التحديث.
- واجهة حديثة RTL: sidebar، topbar، بطاقات إحصائيات، جداول، أزرار، تنبيهات، responsive mobile.
- لا توجد مكتبات خارجية مطلوبة.

## الوحدات

لوحة التحكم، قائمة الانتظار والحجز، النقاط، الزبائن، ملفات الزبائن والعمال، التصريحات الجبائية، التصريحات شبه الجبائية، الطلبات، المراقبات، المراقبة العشوائية، الآجال والتمديدات، وسجل العمليات.

> هذه النسخة تصلح كأساس عملي على جهازك المحلي. قبل نشرها على الإنترنت يجب تعطيل `display_errors`، حذف `database/setup_users.php`، وتغيير كلمات المرور وإعدادات قاعدة البيانات.
