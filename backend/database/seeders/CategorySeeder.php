<?php

namespace Database\Seeders;

use App\Support\Text;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $order = 0;
            foreach (require __DIR__.'/data/categories.php' as $slug => $root) {
                [$fa, $ps, $en] = $root['name'];
                $parentId = $this->upsert($slug, null, $fa, $ps, $en, $root['icon'], $root['aliases'], ++$order);

                $childOrder = 0;
                foreach ($root['children'] as $childSlug => [$cfa, $cps, $cen, $icon, $aliases]) {
                    $this->upsert($childSlug, $parentId, $cfa, $cps, $cen, $icon, $aliases, ++$childOrder);
                }
            }
        });
    }

    private function upsert(string $slug, ?string $parentId, string $fa, ?string $ps, string $en, string $icon, array $aliases, int $order): string
    {
        // Aliases are stored normalized (Arabic letters and digits fixed) so the GIN index matches what users type.
        $aliases = array_values(array_unique(array_map(Text::normalize(...), $aliases)));
        $literal = '{'.implode(',', array_map(fn ($a) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $a).'"', $aliases)).'}';

        DB::table('categories')->upsert([[
            'id' => (string) Str::uuid(),
            'parent_id' => $parentId,
            'slug' => $slug,
            'name' => $fa,
            'name_ps' => $ps,
            'name_en' => $en,
            'icon' => $icon,
            'aliases' => $literal,
            'sort_order' => $order,
        ]], ['slug'], ['parent_id', 'name', 'name_ps', 'name_en', 'icon', 'aliases', 'sort_order']);

        return DB::table('categories')->where('slug', $slug)->value('id');
    }
}
