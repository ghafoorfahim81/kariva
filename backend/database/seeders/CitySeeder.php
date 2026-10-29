<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $provinceIds = DB::table('provinces')->pluck('id', 'code');

        $rows = [];
        foreach (require __DIR__.'/data/cities.php' as $code => $cities) {
            foreach ($cities as $i => [$fa, $ps, $en]) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'province_id' => $provinceIds[$code],
                    'name' => $fa,
                    'name_ps' => $ps,
                    'name_en' => $en,
                    'sort_order' => $i,
                ];
            }
        }

        DB::table('cities')->upsert($rows, ['province_id', 'name'], ['name_ps', 'name_en', 'sort_order']);
    }
}
