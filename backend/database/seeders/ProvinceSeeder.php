<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [];
        $order = 0;
        foreach (require __DIR__.'/data/provinces.php' as $code => [$fa, $ps, $en]) {
            $rows[] = ['id' => (string) Str::uuid(), 'code' => $code, 'name' => $fa, 'name_ps' => $ps, 'name_en' => $en, 'sort_order' => ++$order];
        }

        DB::table('provinces')->upsert($rows, ['code'], ['name', 'name_ps', 'name_en', 'sort_order']);
    }
}
