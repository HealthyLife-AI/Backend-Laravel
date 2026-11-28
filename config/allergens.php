<?php

/*
|--------------------------------------------------------------------------
| Allergen keywords — the ONE reviewable list (audit A-01, A-03)
|--------------------------------------------------------------------------
|
| Food names are matched against these words to find allergen groups:
| for tagging foods (FoodTagger, `foods:tag-allergens`), for the plan's
| allergen check (activation, edits, the dashboard's inline warning) and
| for both AI draft paths. App\Support\Allergens\AllergenMatcher reads it.
|
| Rule: OVER-TAG. A wrong tag costs the nutritionist one acknowledgement;
| a missing tag can put an allergen in a patient's plan.
|
| How words match (both languages):
|  - Whole words, case-insensitive. A keyword with a space is a phrase.
|  - Arabic is normalised first (أ إ آ → ا, ة → ه, ى → ي, no diacritics),
|    so write words the usual way. The article and attached prefixes are
|    stripped from the food name before comparing: "الجبنة", "بالجبنة",
|    "والجبنة" all match "جبنة". Write keywords WITHOUT ال.
|  - English plurals: "peanuts" in a name also matches "peanut".
|  - Plural forms that are not a simple suffix (أجبان, أسماك) must be listed.
|
| `not`: phrases that contain a keyword but are not that allergen
| ("peanut butter" is not milk). They only stop THAT group: peanut butter
| still matches "peanuts".
|
| Changing this file changes nothing stored. Run `php artisan
| foods:tag-allergens` (a dry run) to see what it would add, then
| `--apply`. Tags are only ever added, never removed.
*/

return [
    'keywords' => [
        'milk_lactose' => [
            'en' => ['milk', 'cheese', 'cheeses', 'yogurt', 'yoghurt', 'butter', 'cream', 'whey', 'casein', 'lactose', 'buttermilk', 'custard', 'pudding', 'cheesecake', 'pizza', 'alfredo', 'au gratin', 'creamed', 'milkshake', 'latte', 'cappuccino', 'ghee', 'labneh', 'kunafa', 'muhallabia', 'ice cream', 'mozzarella', 'cheddar', 'parmesan', 'ricotta', 'feta', 'halloumi', 'kefir', 'bechamel'],
            'ar' => ['حليب', 'لبن', 'لبنة', 'جبن', 'جبنة', 'أجبان', 'زبدة', 'قشطة', 'قشدة', 'كريمة', 'زبادي', 'رائب', 'سمن', 'حلوم', 'حلومي', 'عكاوي', 'نابلسي', 'موزاريلا', 'شيدر', 'مهلبية', 'كنافة', 'بشاميل', 'مخيض', 'شنينة', 'كشك', 'بوظة', 'آيس كريم', 'لاكتوز', 'كازين', 'مصل اللبن', 'كاسترد', 'بودينغ', 'لاتيه', 'كابتشينو', 'بيتزا', 'أرز بحليب', 'رز بحليب'],
        ],
        'egg' => [
            'en' => ['egg', 'eggs', 'mayonnaise', 'mayo', 'custard', 'meringue', 'omelet', 'omelette', 'quiche', 'french toast', 'eggnog', 'shakshuka', 'aioli'],
            'ar' => ['بيض', 'بيضة', 'بيضتين', 'مايونيز', 'مايونيزي', 'عجة', 'شكشوكة', 'أومليت', 'اومليت', 'كاسترد', 'ميرينغ'],
        ],
        'wheat_gluten' => [
            'en' => ['wheat', 'flour', 'bread', 'breads', 'bun', 'buns', 'pasta', 'spaghetti', 'macaroni', 'noodle', 'noodles', 'couscous', 'bulgur', 'semolina', 'durum', 'barley', 'rye', 'malt', 'cracker', 'crackers', 'cookie', 'cookies', 'cake', 'cakes', 'muffin', 'muffins', 'biscuit', 'biscuits', 'pie', 'pastry', 'pastries', 'croissant', 'croissants', 'pancake', 'pancakes', 'waffle', 'waffles', 'pretzel', 'pretzels', 'sandwich', 'burger', 'hamburger', 'breaded', 'batter', 'tortilla', 'tortillas', 'doughnut', 'doughnuts', 'bagel', 'bagels', 'crouton', 'croutons', 'stuffing', 'seitan', 'soy sauce', 'oats', 'oat', 'oatmeal', 'pita', 'manakish', 'freekeh', 'kibbeh', 'gluten', 'bran', 'toast', 'pizza', 'lasagna', 'ravioli', 'dumpling', 'dumplings'],
            'ar' => ['قمح', 'طحين', 'دقيق', 'خبز', 'عيش', 'صمون', 'توست', 'كعك', 'كعكة', 'كيك', 'معكرونة', 'مكرونة', 'باستا', 'سباغيتي', 'شعيرية', 'برغل', 'فريكة', 'سميد', 'شعير', 'جاودار', 'بسكويت', 'معجنات', 'فطيرة', 'فطائر', 'كرواسون', 'بان كيك', 'وافل', 'منقوشة', 'مناقيش', 'كبة', 'كنافة', 'بقلاوة', 'قطايف', 'هريسة', 'بسبوسة', 'صاج', 'سمبوسة', 'سمبوسك', 'شوفان', 'كسكس', 'كسكسي', 'مفتول', 'شطيرة', 'ساندويتش', 'برجر', 'همبرغر', 'فتة', 'فتوش', 'مسخن', 'منسف', 'بيتزا', 'لازانيا', 'جلوتين', 'غلوتين', 'نخالة', 'ردة', 'شاورما', 'فلافل', 'كشك'],
        ],
        'soy' => [
            'en' => ['soy', 'soya', 'soybean', 'soybeans', 'tofu', 'edamame', 'miso', 'tempeh', 'natto'],
            'ar' => ['صويا', 'الصويا', 'توفو', 'إدامامي', 'ميسو', 'تمبه'],
        ],
        'sesame' => [
            'en' => ['sesame', 'tahini', 'halva', 'halavah', 'halawa', 'hummus', 'zaatar', 'baba ghanoush', 'moutabal'],
            'ar' => ['سمسم', 'طحينة', 'طحينية', 'طحينيه', 'حلاوة', 'حلاوة طحينية', 'حمص', 'زعتر', 'متبل', 'بابا غنوج', 'سمسمية'],
        ],
        'peanuts' => [
            'en' => ['peanut', 'peanuts', 'groundnut', 'groundnuts'],
            'ar' => ['فول سوداني', 'فستق سوداني', 'فستق عبيد', 'زبدة الفول السوداني'],
        ],
        'tree_nuts' => [
            'en' => ['almond', 'almonds', 'walnut', 'walnuts', 'pecan', 'pecans', 'cashew', 'cashews', 'pistachio', 'pistachios', 'hazelnut', 'hazelnuts', 'filbert', 'filberts', 'macadamia', 'brazil nut', 'brazilnuts', 'pine nut', 'pine nuts', 'pinyon', 'chestnut', 'chestnuts', 'praline', 'marzipan', 'nougat', 'baklava', 'beechnut', 'hickorynuts', 'nut', 'nuts', 'nutella'],
            'ar' => ['لوز', 'جوز', 'عين الجمل', 'كاجو', 'فستق', 'فستق حلبي', 'بندق', 'مكاديميا', 'صنوبر', 'كستناء', 'أبو فروة', 'بقلاوة', 'مارزيبان', 'نوجا', 'برالين', 'بيكان', 'مكسرات', 'نوتيلا'],
        ],
        'fish' => [
            'en' => ['fish', 'salmon', 'tuna', 'cod', 'anchovy', 'anchovies', 'sardine', 'sardines', 'tilapia', 'trout', 'herring', 'mackerel', 'haddock', 'halibut', 'pollock', 'catfish', 'bass', 'snapper', 'swordfish', 'flounder', 'grouper', 'perch', 'pike', 'carp', 'whitefish', 'roe', 'caviar'],
            'ar' => ['سمك', 'أسماك', 'سمكة', 'تونة', 'تونا', 'سلمون', 'سالمون', 'سردين', 'أنشوجة', 'بلطي', 'ماكريل', 'هامور', 'سلطان إبراهيم', 'بوري', 'دنيس', 'قاروص', 'كافيار', 'بطارخ', 'فسيخ', 'رنجة', 'سمك القد'],
        ],
        'shellfish' => [
            'en' => ['shrimp', 'shrimps', 'crab', 'crabs', 'lobster', 'clam', 'clams', 'oyster', 'oysters', 'mussel', 'mussels', 'scallop', 'scallops', 'crayfish', 'squid', 'calamari', 'octopus', 'crustaceans', 'mollusks', 'abalone', 'whelk', 'prawn', 'prawns', 'seafood'],
            'ar' => ['جمبري', 'روبيان', 'قريدس', 'قمبري', 'سلطعون', 'كابوريا', 'سرطان البحر', 'استاكوزا', 'كركند', 'محار', 'بلح البحر', 'اسكالوب', 'حبار', 'كاليماري', 'أخطبوط', 'مأكولات بحرية', 'ثمار البحر'],
        ],
    ],

    'not' => [
        'milk_lactose' => [
            'en' => ['peanut butter', 'apple butter', 'cocoa butter', 'butter beans', 'butterbur', 'cream of tartar', 'coconut milk', 'coconut cream', 'soymilk', 'soy milk', 'almond milk', 'rice milk', 'oat milk', 'nut butter', 'sesame butter', 'shea'],
            'ar' => ['زبدة الفول السوداني', 'زبدة الكاكاو', 'زبدة اللوز', 'زبدة الشيا', 'حليب جوز الهند', 'كريمة جوز الهند', 'حليب الصويا', 'حليب اللوز', 'حليب الشوفان', 'حليب الأرز', 'سمن نباتي'],
        ],
        'egg' => [
            'en' => ['eggplant', 'egg roll', 'egg rolls'],
            'ar' => [],
        ],
        'wheat_gluten' => [
            'en' => ['rice noodle', 'rice noodles', 'corn tortilla', 'corn tortillas', 'buckwheat', 'rice flour', 'corn flour', 'potato flour', 'soy flour', 'peanut flour', 'chickpea flour', 'tapioca', 'arrowroot', 'pie filling'],
            'ar' => ['دقيق الأرز', 'طحين الأرز', 'دقيق الذرة', 'طحين الذرة', 'نشا الذرة', 'شعيرية الأرز', 'دقيق الحمص', 'طحين الحمص'],
        ],
        'tree_nuts' => [
            'en' => ['water chestnut', 'water chestnuts', 'butternut', 'nutmeg', 'coconut', 'doughnut', 'doughnuts', 'nutrition', 'nutritional'],
            'ar' => ['جوز الهند', 'جوزة الطيب', 'جوز الطيب'],
        ],
        'fish' => [
            'en' => ['fish sauce substitute'],
            'ar' => [],
        ],
    ],
];
