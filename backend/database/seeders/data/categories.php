<?php

// Root categories with children. Child: slug => [Dari, Pashto|null, English, icon, aliases (other names people search by)].
// Names in Pashto and English are a first draft: a native speaker must review them before launch.
return [
    'construction' => [
        'name' => ['ساختمان و تعمیرات', 'ساختمان او ترمیم', 'Construction & Repair'], 'icon' => 'hammer',
        'aliases' => ['ساختمانی', 'تعمیر خانه'],
        'children' => [
            'electrician' => ['برقکار', null, 'Electrician', 'zap', ['برق', 'برقی', 'الکتریک', 'برق کار', 'electric']],
            'plumber' => ['پلمبر', null, 'Plumber', 'droplet', ['نل ساز', 'نلساز', 'لوله کش', 'لوله‌کش', 'plumbing']],
            'carpenter' => ['نجار', 'ترکان', 'Carpenter', 'saw', ['نجاری', 'دروازه ساز', 'چوب کار']],
            'painter' => ['رنگکار', null, 'Painter', 'paint-roller', ['رنگ مال', 'رنگ‌مال', 'نقاش ساختمان', 'رنگ']],
            'mason' => ['بنا', null, 'Mason', 'brick-wall', ['معمار', 'ساختمان ساز', 'ساختمان‌ساز', 'بنایی']],
            'welder' => ['جوشکار', null, 'Welder', 'flame', ['آهنگر', 'جوش', 'دروازه آهنی']],
            'tiler' => ['کاشی‌کار', null, 'Tiler', 'grid', ['کاشی کار', 'سرامیک', 'ماربل']],
            'plasterer' => ['گچکار', null, 'Plasterer', 'layers', ['گچ کار', 'گچ بر', 'پی او پی']],
            'glazier' => ['شیشه‌بر', null, 'Glazier', 'square', ['شیشه بر', 'شیشه گر', 'شیشه']],
        ],
    ],
    'vehicles' => [
        'name' => ['موتر و ماشین', 'موټر او ماشین', 'Vehicles'], 'icon' => 'car',
        'aliases' => ['گاری', 'ماشین'],
        'children' => [
            'car-mechanic' => ['موتر‌ساز', null, 'Car mechanic', 'wrench', ['مکانیک', 'تعمیرکار موتر', 'موتر ساز', 'ورکشاپ']],
            'auto-electrician' => ['برق موتر', null, 'Auto electrician', 'zap', ['برقکار موتر', 'اتو الکتریک']],
            'tire-repair' => ['پنچرگیر', null, 'Tire repair', 'circle', ['پنچر', 'تایر', 'ټایر']],
            'car-wash' => ['موتر شویی', null, 'Car wash', 'sparkles', ['شستن موتر', 'کارواش']],
            'motorbike-mechanic' => ['موترسایکل‌ساز', null, 'Motorbike mechanic', 'bike', ['موتر سایکل', 'موټرسایکل', 'بایسکل']],
        ],
    ],
    'tech' => [
        'name' => ['برقیات و تکنالوژی', 'برېښنایي توکي او ټیکنالوژي', 'Electronics & Tech'], 'icon' => 'smartphone',
        'aliases' => ['الکترونیک', 'تکنولوژی'],
        'children' => [
            'mobile-repair' => ['تعمیر موبایل', null, 'Mobile repair', 'smartphone', ['موبایل ساز', 'موبایل‌ساز', 'سمارت فون']],
            'computer-repair' => ['تعمیر کمپیوتر', null, 'Computer repair', 'laptop', ['کمپیوتر ساز', 'لپتاپ', 'کامپیوتر']],
            'ac-fridge-repair' => ['یخچال و کولر', null, 'AC & fridge repair', 'snowflake', ['یخچال ساز', 'کولر ساز', 'ای سی', 'AC', 'سرد کننده']],
            'tv-repair' => ['تلویزیون‌ساز', null, 'TV repair', 'tv', ['تلویزیون', 'تعمیر تلویزیون']],
            'cctv-solar' => ['کمره و پنل آفتابی', null, 'CCTV & solar', 'sun', ['کمره نصب', 'سولر', 'پنل سولری', 'سی سی تی وی']],
        ],
    ],
    'home' => [
        'name' => ['خدمات خانه', 'د کور خدمتونه', 'Home services'], 'icon' => 'home',
        'aliases' => ['خانگی'],
        'children' => [
            'cleaning' => ['نظافت', null, 'Cleaning', 'sparkles', ['نظافت چی', 'نظافتچی', 'خانه تکانی', 'پاک کاری']],
            'cook' => ['آشپز', 'اشپز', 'Cook', 'chef-hat', ['آشپزی', 'طباخ', 'باورچی']],
            'gardener' => ['باغبان', null, 'Gardener', 'flower', ['باغبانی', 'گلکاری']],
            'mover' => ['باربر', null, 'Mover', 'package', ['حمل اسباب', 'اسباب کشی', 'اثاثیه']],
            'laundry' => ['خشک‌شویی', null, 'Laundry', 'shirt', ['خشک شویی', 'لباس شویی', 'قالین شویی']],
        ],
    ],
    'clothing' => [
        'name' => ['پوشاک و خیاطی', 'جامې او خیاطي', 'Clothing & Tailoring'], 'icon' => 'scissors',
        'aliases' => ['لباس'],
        'children' => [
            'tailor' => ['خیاط', 'خیاط', 'Tailor', 'scissors', ['دوزنده', 'خیاطی', 'دوخت', 'لباس دوز']],
            'embroidery' => ['گل‌دوزی', null, 'Embroidery', 'flower', ['گل دوزی', 'سوزن دوزی', 'خامک دوزی']],
            'cobbler' => ['کفاش', 'کفاش', 'Cobbler', 'footprints', ['بوت دوز', 'کفش', 'بوت']],
        ],
    ],
    'education' => [
        'name' => ['آموزش', 'زده کړه', 'Education'], 'icon' => 'book-open',
        'aliases' => ['درس', 'تدریس'],
        'children' => [
            'private-tutor' => ['معلم خصوصی', null, 'Private tutor', 'graduation-cap', ['استاد', 'تدریس خصوصی', 'درس خصوصی', 'ټیوټر']],
            'language-teacher' => ['آموزش زبان', null, 'Language teacher', 'languages', ['زبان انگلیسی', 'انگلیسی', 'کورس زبان', 'english']],
            'quran-teacher' => ['معلم قرآن', null, 'Quran teacher', 'book', ['قاری', 'قرآن', 'حفظ قرآن']],
            'computer-trainer' => ['آموزش کمپیوتر', null, 'Computer trainer', 'monitor', ['کورس کمپیوتر', 'آموزش کامپیوتر']],
            'driving-instructor' => ['آموزش موترچلانی', null, 'Driving instructor', 'steering-wheel', ['درایوری آموزش', 'درایور آموزش']],
        ],
    ],
    'health-beauty' => [
        'name' => ['صحت و آرایش', 'روغتیا او ښکلا', 'Health & Beauty'], 'icon' => 'heart',
        'aliases' => ['زیبایی'],
        'children' => [
            'barber' => ['سلمانی', 'سلماني', 'Barber', 'scissors', ['آرایشگر مردانه', 'آرایشگاه مردانه', 'حجام']],
            'beauty-salon' => ['آرایشگر زنانه', null, 'Beauty salon', 'sparkle', ['سالن زیبایی', 'سالون', 'بیوتی', 'آرایشگاه زنانه']],
            'nurse' => ['نرس', null, 'Nurse', 'stethoscope', ['پرستار', 'مراقب مریض', 'نرسینگ']],
            'physiotherapy' => ['فزیوتراپی', null, 'Physiotherapy', 'activity', ['فیزیوتراپی', 'ماساژ', 'تداوی طبیعی']],
        ],
    ],
    'professional' => [
        'name' => ['خدمات حرفه‌ای', 'مسلکي خدمتونه', 'Professional services'], 'icon' => 'briefcase',
        'aliases' => ['اداری', 'دفتری'],
        'children' => [
            'translator' => ['مترجم', 'ژباړن', 'Translator', 'languages', ['ترجمه', 'ترجمان']],
            'accountant' => ['حسابدار', null, 'Accountant', 'calculator', ['حسابداری', 'محاسب', 'مالیه']],
            'lawyer' => ['وکیل', 'وکیل', 'Lawyer', 'scale', ['حقوق', 'مشاور حقوقی', 'قانون']],
            'photographer' => ['عکاس', 'عکاس', 'Photographer', 'camera', ['فلمبردار', 'فلم بردار', 'عکس', 'ویدیو']],
            'graphic-designer' => ['طراح گرافیک', null, 'Graphic designer', 'pen-tool', ['گرافیک', 'دیزاینر', 'لوگو', 'پوستر']],
            'programmer' => ['برنامه‌نویس', null, 'Programmer', 'code', ['برنامه نویس', 'ویب سایت', 'وبسایت', 'دولپر', 'developer']],
        ],
    ],
    'events' => [
        'name' => ['محفل و مراسم', 'محفل او مراسم', 'Events'], 'icon' => 'party-popper',
        'aliases' => ['عروسی', 'عروسی و مراسم'],
        'children' => [
            'event-decoration' => ['تزیین محفل', null, 'Event decoration', 'sparkles', ['دکوریشن', 'سجاوت', 'تزئین']],
            'caterer' => ['پخت و پز محفل', null, 'Caterer', 'utensils', ['کیترینگ', 'دیگ', 'آشپز محفل']],
            'sound-system' => ['صدا و موسیقی', null, 'Sound & music', 'music', ['ساز و آواز', 'دی جی', 'سیستم صدا']],
        ],
    ],
    'transport' => [
        'name' => ['حمل و نقل', 'ترانسپورت', 'Transport'], 'icon' => 'truck',
        'aliases' => ['ترانسپورت'],
        'children' => [
            'driver' => ['راننده', 'چلوونکی', 'Driver', 'steering-wheel', ['درایور', 'دریور', 'موتروان', 'تکسی']],
            'cargo' => ['باربری', null, 'Cargo transport', 'truck', ['حمل بار', 'لاری', 'موتر باربری', 'ریچ ترک']],
            'delivery' => ['رساندن بسته', null, 'Delivery', 'package', ['دلیوری', 'پیک', 'رسانیدن', 'قاصد']],
        ],
    ],
];
