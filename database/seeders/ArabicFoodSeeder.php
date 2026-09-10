<?php

namespace Database\Seeders;

use App\Models\Food;
use Illuminate\Database\Seeder;

/**
 * S2-08 / FR-23, FR-25: the admin-curated Arabic food layer (PRD F-8) —
 * common Levantine/Gulf/Egyptian dishes the USDA SR Legacy set has no
 * equivalent for (it's a generic-ingredient database, not a regional-
 * cuisine one). Values are the admin's best compiled per-100g estimate
 * from standard published composition for each dish, the same way any
 * initial nutrition-database curation starts — refined over time via
 * nutritionist-submitted corrections (BR-5), not claimed as lab-tested.
 *
 * `source = 'admin'`, `status = 'approved'` — pre-approved, unlike a
 * nutritionist submission (BR-5).
 */
class ArabicFoodSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::DISHES as $dish) {
            // Matched on the admin-owned row for this name, not on a
            // database constraint. The original `upsert(uniqueBy:
            // ['name_en'])` needed a real UNIQUE index behind it and the
            // migration only indexes `name_en` (non-unique), so MySQL
            // silently dropped the conflict clause and re-inserted all 58
            // dishes on every `db:seed --force` — production ended up
            // with two of every dish, which also let an AI draft pick the
            // "same" food twice under two different ids.
            //
            // Adding that UNIQUE index is the wrong repair: BR-5 lets a
            // nutritionist submit their own local "Hummus" with different
            // macros, and a globally unique name would reject it at the
            // database level rather than as a validation message. Scoping
            // the match to `source = 'admin'` keeps this seeder idempotent
            // without constraining what nutritionists may submit.
            Food::updateOrCreate(
                ['source' => 'admin', 'name_en' => $dish['name_en']],
                $dish + ['status' => 'approved'],
            );
        }
    }

    /**
     * @var list<array{name_ar: string, name_en: string, calories_per_100g: float, protein_g_per_100g: float, carbs_g_per_100g: float, fat_g_per_100g: float, fiber_g_per_100g: float}>
     */
    private const DISHES = [
        ['name_ar' => 'أرز أبيض مطبوخ', 'name_en' => 'White Rice, Cooked (Arabic style)', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.7, 'carbs_g_per_100g' => 28.0, 'fat_g_per_100g' => 0.3, 'fiber_g_per_100g' => 0.4],
        ['name_ar' => 'أرز بسمتي مطبوخ', 'name_en' => 'Basmati Rice, Cooked', 'calories_per_100g' => 121, 'protein_g_per_100g' => 2.5, 'carbs_g_per_100g' => 25.0, 'fat_g_per_100g' => 0.4, 'fiber_g_per_100g' => 0.4],
        ['name_ar' => 'مجدرة', 'name_en' => 'Mujaddara (Rice & Lentils)', 'calories_per_100g' => 150, 'protein_g_per_100g' => 5.0, 'carbs_g_per_100g' => 28.0, 'fat_g_per_100g' => 3.0, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'كبسة دجاج', 'name_en' => 'Chicken Kabsa', 'calories_per_100g' => 165, 'protein_g_per_100g' => 10.0, 'carbs_g_per_100g' => 18.0, 'fat_g_per_100g' => 6.0, 'fiber_g_per_100g' => 1.0],
        ['name_ar' => 'برياني لحم', 'name_en' => 'Beef Biryani', 'calories_per_100g' => 180, 'protein_g_per_100g' => 9.0, 'carbs_g_per_100g' => 20.0, 'fat_g_per_100g' => 7.0, 'fiber_g_per_100g' => 1.2],
        ['name_ar' => 'مسخن دجاج', 'name_en' => 'Musakhan (Sumac Chicken)', 'calories_per_100g' => 210, 'protein_g_per_100g' => 12.0, 'carbs_g_per_100g' => 18.0, 'fat_g_per_100g' => 10.0, 'fiber_g_per_100g' => 2.0],
        ['name_ar' => 'فتة حمص', 'name_en' => 'Fatteh with Chickpeas', 'calories_per_100g' => 175, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 20.0, 'fat_g_per_100g' => 8.0, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'حمص بطحينة', 'name_en' => 'Hummus', 'calories_per_100g' => 166, 'protein_g_per_100g' => 7.9, 'carbs_g_per_100g' => 14.3, 'fat_g_per_100g' => 9.6, 'fiber_g_per_100g' => 6.0],
        ['name_ar' => 'متبل باذنجان', 'name_en' => 'Baba Ghanoush', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.5, 'carbs_g_per_100g' => 9.0, 'fat_g_per_100g' => 10.0, 'fiber_g_per_100g' => 4.0],
        ['name_ar' => 'تبولة', 'name_en' => 'Tabbouleh', 'calories_per_100g' => 90, 'protein_g_per_100g' => 2.0, 'carbs_g_per_100g' => 11.0, 'fat_g_per_100g' => 5.0, 'fiber_g_per_100g' => 2.5],
        ['name_ar' => 'فتوش', 'name_en' => 'Fattoush', 'calories_per_100g' => 70, 'protein_g_per_100g' => 1.5, 'carbs_g_per_100g' => 8.0, 'fat_g_per_100g' => 4.0, 'fiber_g_per_100g' => 2.0],
        ['name_ar' => 'ورق عنب بزيت الزيتون', 'name_en' => 'Stuffed Grape Leaves (Vegetarian)', 'calories_per_100g' => 150, 'protein_g_per_100g' => 3.0, 'carbs_g_per_100g' => 22.0, 'fat_g_per_100g' => 6.0, 'fiber_g_per_100g' => 2.5],
        ['name_ar' => 'كبة مقلية', 'name_en' => 'Fried Kibbeh', 'calories_per_100g' => 280, 'protein_g_per_100g' => 12.0, 'carbs_g_per_100g' => 22.0, 'fat_g_per_100g' => 16.0, 'fiber_g_per_100g' => 1.5],
        ['name_ar' => 'كبة نية', 'name_en' => 'Raw Kibbeh (Kibbeh Nayyeh)', 'calories_per_100g' => 200, 'protein_g_per_100g' => 15.0, 'carbs_g_per_100g' => 15.0, 'fat_g_per_100g' => 9.0, 'fiber_g_per_100g' => 1.0],
        ['name_ar' => 'شاورما دجاج', 'name_en' => 'Chicken Shawarma', 'calories_per_100g' => 220, 'protein_g_per_100g' => 18.0, 'carbs_g_per_100g' => 8.0, 'fat_g_per_100g' => 13.0, 'fiber_g_per_100g' => 0.5],
        ['name_ar' => 'شاورما لحم', 'name_en' => 'Beef Shawarma', 'calories_per_100g' => 250, 'protein_g_per_100g' => 20.0, 'carbs_g_per_100g' => 6.0, 'fat_g_per_100g' => 16.0, 'fiber_g_per_100g' => 0.5],
        ['name_ar' => 'فلافل', 'name_en' => 'Falafel', 'calories_per_100g' => 333, 'protein_g_per_100g' => 13.3, 'carbs_g_per_100g' => 31.8, 'fat_g_per_100g' => 17.8, 'fiber_g_per_100g' => 4.9],
        ['name_ar' => 'مسقعة باذنجان', 'name_en' => 'Moussaka (Eggplant)', 'calories_per_100g' => 120, 'protein_g_per_100g' => 4.0, 'carbs_g_per_100g' => 10.0, 'fat_g_per_100g' => 7.0, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'ملوخية بدجاج', 'name_en' => 'Molokhia with Chicken', 'calories_per_100g' => 90, 'protein_g_per_100g' => 8.0, 'carbs_g_per_100g' => 5.0, 'fat_g_per_100g' => 4.0, 'fiber_g_per_100g' => 2.0],
        ['name_ar' => 'ملوخية سادة', 'name_en' => 'Molokhia, Plain', 'calories_per_100g' => 45, 'protein_g_per_100g' => 3.5, 'carbs_g_per_100g' => 6.0, 'fat_g_per_100g' => 0.5, 'fiber_g_per_100g' => 2.0],
        ['name_ar' => 'مقلوبة دجاج', 'name_en' => 'Maqluba with Chicken', 'calories_per_100g' => 170, 'protein_g_per_100g' => 9.0, 'carbs_g_per_100g' => 20.0, 'fat_g_per_100g' => 6.0, 'fiber_g_per_100g' => 1.5],
        ['name_ar' => 'منسف', 'name_en' => 'Mansaf', 'calories_per_100g' => 200, 'protein_g_per_100g' => 14.0, 'carbs_g_per_100g' => 12.0, 'fat_g_per_100g' => 11.0, 'fiber_g_per_100g' => 0.8],
        ['name_ar' => 'شيش طاووق', 'name_en' => 'Shish Tawook', 'calories_per_100g' => 165, 'protein_g_per_100g' => 25.0, 'carbs_g_per_100g' => 2.0, 'fat_g_per_100g' => 6.0, 'fiber_g_per_100g' => 0.2],
        ['name_ar' => 'كفتة مشوية', 'name_en' => 'Grilled Kofta', 'calories_per_100g' => 250, 'protein_g_per_100g' => 18.0, 'carbs_g_per_100g' => 3.0, 'fat_g_per_100g' => 19.0, 'fiber_g_per_100g' => 0.5],
        ['name_ar' => 'سمك مشوي', 'name_en' => 'Grilled Fish (General)', 'calories_per_100g' => 140, 'protein_g_per_100g' => 22.0, 'carbs_g_per_100g' => 0.0, 'fat_g_per_100g' => 5.0, 'fiber_g_per_100g' => 0.0],
        ['name_ar' => 'سبانخ بزيت الزيتون', 'name_en' => 'Spinach with Olive Oil', 'calories_per_100g' => 80, 'protein_g_per_100g' => 3.0, 'carbs_g_per_100g' => 6.0, 'fat_g_per_100g' => 5.5, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'فاصولياء خضراء بزيت الزيتون', 'name_en' => 'Green Beans with Olive Oil', 'calories_per_100g' => 75, 'protein_g_per_100g' => 2.5, 'carbs_g_per_100g' => 8.0, 'fat_g_per_100g' => 4.0, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'لوبيا بالزيت', 'name_en' => 'White Bean Stew', 'calories_per_100g' => 110, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 15.0, 'fat_g_per_100g' => 3.0, 'fiber_g_per_100g' => 5.0],
        ['name_ar' => 'شوربة عدس', 'name_en' => 'Lentil Soup', 'calories_per_100g' => 95, 'protein_g_per_100g' => 5.5, 'carbs_g_per_100g' => 15.0, 'fat_g_per_100g' => 1.2, 'fiber_g_per_100g' => 3.5],
        ['name_ar' => 'فول مدمس', 'name_en' => 'Ful Medames', 'calories_per_100g' => 110, 'protein_g_per_100g' => 7.6, 'carbs_g_per_100g' => 17.0, 'fat_g_per_100g' => 1.5, 'fiber_g_per_100g' => 5.4],
        ['name_ar' => 'بيض مسلوق', 'name_en' => 'Boiled Egg (Arabic breakfast)', 'calories_per_100g' => 155, 'protein_g_per_100g' => 12.6, 'carbs_g_per_100g' => 1.1, 'fat_g_per_100g' => 10.6, 'fiber_g_per_100g' => 0.0],
        ['name_ar' => 'جبنة عكاوي', 'name_en' => 'Akkawi White Cheese', 'calories_per_100g' => 260, 'protein_g_per_100g' => 17.0, 'carbs_g_per_100g' => 3.0, 'fat_g_per_100g' => 20.0, 'fiber_g_per_100g' => 0.0],
        ['name_ar' => 'لبنة', 'name_en' => 'Labneh', 'calories_per_100g' => 130, 'protein_g_per_100g' => 5.5, 'carbs_g_per_100g' => 4.0, 'fat_g_per_100g' => 10.5, 'fiber_g_per_100g' => 0.0],
        ['name_ar' => 'زعتر وزيت', 'name_en' => 'Zaatar with Olive Oil', 'calories_per_100g' => 350, 'protein_g_per_100g' => 8.0, 'carbs_g_per_100g' => 30.0, 'fat_g_per_100g' => 22.0, 'fiber_g_per_100g' => 8.0],
        ['name_ar' => 'مناقيش زعتر', 'name_en' => 'Zaatar Manakish', 'calories_per_100g' => 300, 'protein_g_per_100g' => 7.0, 'carbs_g_per_100g' => 40.0, 'fat_g_per_100g' => 12.0, 'fiber_g_per_100g' => 4.0],
        ['name_ar' => 'خبز عربي', 'name_en' => 'Arabic Pita Bread', 'calories_per_100g' => 275, 'protein_g_per_100g' => 9.1, 'carbs_g_per_100g' => 55.7, 'fat_g_per_100g' => 1.2, 'fiber_g_per_100g' => 2.2],
        ['name_ar' => 'خبز حبوب كاملة', 'name_en' => 'Whole Wheat Bread (Arabic style)', 'calories_per_100g' => 247, 'protein_g_per_100g' => 13.0, 'carbs_g_per_100g' => 41.0, 'fat_g_per_100g' => 3.4, 'fiber_g_per_100g' => 7.0],
        ['name_ar' => 'شكشوكة', 'name_en' => 'Shakshuka', 'calories_per_100g' => 100, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 6.0, 'fat_g_per_100g' => 6.0, 'fiber_g_per_100g' => 1.5],
        ['name_ar' => 'مسبحة حمص', 'name_en' => 'Musabaha', 'calories_per_100g' => 160, 'protein_g_per_100g' => 7.0, 'carbs_g_per_100g' => 13.0, 'fat_g_per_100g' => 9.0, 'fiber_g_per_100g' => 5.0],
        ['name_ar' => 'متبلة كوسا', 'name_en' => 'Zucchini Mutabbal', 'calories_per_100g' => 60, 'protein_g_per_100g' => 2.0, 'carbs_g_per_100g' => 6.0, 'fat_g_per_100g' => 3.0, 'fiber_g_per_100g' => 2.0],
        ['name_ar' => 'يخنة بامية', 'name_en' => 'Okra Stew', 'calories_per_100g' => 90, 'protein_g_per_100g' => 4.0, 'carbs_g_per_100g' => 10.0, 'fat_g_per_100g' => 4.0, 'fiber_g_per_100g' => 3.5],
        ['name_ar' => 'يخنة فاصولياء حمراء', 'name_en' => 'Kidney Bean Stew', 'calories_per_100g' => 120, 'protein_g_per_100g' => 8.0, 'carbs_g_per_100g' => 18.0, 'fat_g_per_100g' => 2.0, 'fiber_g_per_100g' => 6.0],
        ['name_ar' => 'برغل بالبندورة', 'name_en' => 'Bulgur with Tomato', 'calories_per_100g' => 130, 'protein_g_per_100g' => 4.0, 'carbs_g_per_100g' => 25.0, 'fat_g_per_100g' => 2.0, 'fiber_g_per_100g' => 4.5],
        ['name_ar' => 'كشري مصري', 'name_en' => 'Egyptian Koshari', 'calories_per_100g' => 150, 'protein_g_per_100g' => 5.0, 'carbs_g_per_100g' => 25.0, 'fat_g_per_100g' => 4.0, 'fiber_g_per_100g' => 3.0],
        ['name_ar' => 'طعمية مصرية', 'name_en' => 'Egyptian Taameya', 'calories_per_100g' => 330, 'protein_g_per_100g' => 15.0, 'carbs_g_per_100g' => 30.0, 'fat_g_per_100g' => 17.0, 'fiber_g_per_100g' => 6.0],
        ['name_ar' => 'بامية باللحم', 'name_en' => 'Okra with Meat', 'calories_per_100g' => 130, 'protein_g_per_100g' => 9.0, 'carbs_g_per_100g' => 8.0, 'fat_g_per_100g' => 7.0, 'fiber_g_per_100g' => 2.5],
        ['name_ar' => 'سليق دجاج', 'name_en' => 'Saleeg Chicken Rice', 'calories_per_100g' => 140, 'protein_g_per_100g' => 8.0, 'carbs_g_per_100g' => 16.0, 'fat_g_per_100g' => 5.0, 'fiber_g_per_100g' => 0.5],
        ['name_ar' => 'جريش', 'name_en' => 'Jareesh (Cracked Wheat Porridge)', 'calories_per_100g' => 110, 'protein_g_per_100g' => 4.0, 'carbs_g_per_100g' => 20.0, 'fat_g_per_100g' => 1.5, 'fiber_g_per_100g' => 3.5],
        ['name_ar' => 'ثريد', 'name_en' => 'Thareed', 'calories_per_100g' => 130, 'protein_g_per_100g' => 7.0, 'carbs_g_per_100g' => 15.0, 'fat_g_per_100g' => 5.0, 'fiber_g_per_100g' => 1.5],
        ['name_ar' => 'حلاوة الجبن', 'name_en' => 'Halawet El Jibn', 'calories_per_100g' => 320, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 55.0, 'fat_g_per_100g' => 9.0, 'fiber_g_per_100g' => 0.5],
        ['name_ar' => 'كنافة', 'name_en' => 'Kunafa', 'calories_per_100g' => 330, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 45.0, 'fat_g_per_100g' => 15.0, 'fiber_g_per_100g' => 1.0],
        ['name_ar' => 'بقلاوة', 'name_en' => 'Baklava', 'calories_per_100g' => 430, 'protein_g_per_100g' => 6.0, 'carbs_g_per_100g' => 48.0, 'fat_g_per_100g' => 25.0, 'fiber_g_per_100g' => 2.5],
        ['name_ar' => 'أم علي', 'name_en' => 'Om Ali', 'calories_per_100g' => 250, 'protein_g_per_100g' => 5.0, 'carbs_g_per_100g' => 30.0, 'fat_g_per_100g' => 12.0, 'fiber_g_per_100g' => 1.0],
        ['name_ar' => 'مهلبية', 'name_en' => 'Muhallabia', 'calories_per_100g' => 120, 'protein_g_per_100g' => 3.0, 'carbs_g_per_100g' => 20.0, 'fat_g_per_100g' => 3.0, 'fiber_g_per_100g' => 0.2],
        ['name_ar' => 'تمر', 'name_en' => 'Dates', 'calories_per_100g' => 277, 'protein_g_per_100g' => 1.8, 'carbs_g_per_100g' => 75.0, 'fat_g_per_100g' => 0.2, 'fiber_g_per_100g' => 6.7],
        ['name_ar' => 'زبادي', 'name_en' => 'Plain Yogurt (Arabic style)', 'calories_per_100g' => 61, 'protein_g_per_100g' => 3.5, 'carbs_g_per_100g' => 4.7, 'fat_g_per_100g' => 3.3, 'fiber_g_per_100g' => 0.0],
        ['name_ar' => 'سلطة يونانية', 'name_en' => 'Greek-Style Salad (Arabic version)', 'calories_per_100g' => 65, 'protein_g_per_100g' => 2.0, 'carbs_g_per_100g' => 5.0, 'fat_g_per_100g' => 4.5, 'fiber_g_per_100g' => 1.5],
        ['name_ar' => 'رقاق بالجبنة', 'name_en' => 'Cheese Rolls (Riqaq)', 'calories_per_100g' => 310, 'protein_g_per_100g' => 10.0, 'carbs_g_per_100g' => 28.0, 'fat_g_per_100g' => 18.0, 'fiber_g_per_100g' => 1.0],
    ];
}
