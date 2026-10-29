<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Raw-SQL fixtures: constraint tests talk to the database directly, with no model rules in between. */
trait Fixtures
{
    protected int $seq = 0;

    protected function phone(): string
    {
        return '+937'.str_pad((string) (++$this->seq), 8, '0', STR_PAD_LEFT);
    }

    protected function insert(string $table, array $row): string
    {
        $row = ['id' => (string) Str::uuid()] + $row;
        DB::table($table)->insert($row);

        return $row['id'];
    }

    /** A user together with its personal actor. */
    protected function person(array $user = [], array $actor = []): array
    {
        $userId = $this->insert('users', $user + ['phone_e164' => $this->phone()]);
        $actorId = $this->insert('actors', $actor + [
            'type' => 'person', 'personal_user_id' => $userId, 'display_name' => 'Person '.$this->seq,
        ]);
        DB::table('actor_members')->insert(['actor_id' => $actorId, 'user_id' => $userId, 'role' => 'owner', 'is_primary' => true]);

        return ['user' => $userId, 'actor' => $actorId];
    }

    /** A business page created by a fresh person, who becomes its primary owner. */
    protected function business(?array $owner = null, array $actor = []): array
    {
        $owner ??= $this->person();
        $actorId = $this->insert('actors', $actor + ['type' => 'business', 'display_name' => 'Shop '.(++$this->seq)]);
        DB::table('actor_members')->insert(['actor_id' => $actorId, 'user_id' => $owner['user'], 'role' => 'owner', 'is_primary' => true]);

        return ['user' => $owner['user'], 'actor' => $actorId];
    }

    protected function member(string $actorId, string $role): string
    {
        $user = $this->person();
        DB::table('actor_members')->insert(['actor_id' => $actorId, 'user_id' => $user['user'], 'role' => $role]);

        return $user['user'];
    }

    protected function province(): string
    {
        return $this->insert('provinces', ['code' => 'p'.(++$this->seq), 'name' => 'ولایت '.$this->seq]);
    }

    protected function city(?string $provinceId = null): string
    {
        return $this->insert('cities', ['province_id' => $provinceId ?? $this->province(), 'name' => 'شهر '.(++$this->seq)]);
    }

    protected function category(?string $parentId = null): string
    {
        return $this->insert('categories', ['parent_id' => $parentId, 'slug' => 'c'.(++$this->seq), 'name' => 'دسته '.$this->seq]);
    }

    protected function service(string $actorId, array $overrides = []): string
    {
        return $this->insert('services', $overrides + [
            'actor_id' => $actorId, 'category_id' => $this->category(), 'title' => 'خدمت '.(++$this->seq),
            'pricing_type' => 'fixed', 'price_amount' => 100, 'currency' => 'AFN', 'price_unit' => 'hour', 'status' => 'active',
        ]);
    }

    protected function need(string $actorId, array $overrides = []): string
    {
        return $this->insert('needs', $overrides + [
            'actor_id' => $actorId, 'category_id' => $this->category(), 'kind' => 'service', 'title' => 'نیاز '.(++$this->seq),
        ]);
    }

    protected function offer(string $needId, string $providerActorId, array $overrides = []): string
    {
        return $this->insert('offers', $overrides + [
            'need_id' => $needId, 'provider_actor_id' => $providerActorId, 'amount' => 500, 'currency' => 'AFN', 'price_unit' => 'project',
        ]);
    }

    /** A plain direct order in status "requested" between two fresh persons unless parties are passed. */
    protected function order(?string $customerActor = null, ?string $providerActor = null, array $overrides = []): string
    {
        return $this->insert('orders', $overrides + [
            'origin' => 'direct',
            'customer_actor_id' => $customerActor ?? $this->person()['actor'],
            'provider_actor_id' => $providerActor ?? $this->person()['actor'],
            'title_snapshot' => 'سفارش '.(++$this->seq),
            'status' => 'requested',
        ]);
    }

    /** Walks an order through legal transitions up to the wanted status. */
    protected function advance(string $orderId, string $to): void
    {
        $paths = [
            'quoted' => ['quoted'],
            'accepted' => ['quoted', 'accepted'],
            'provider_done' => ['quoted', 'accepted', 'provider_done'],
            'completed' => ['quoted', 'accepted', 'provider_done', 'completed'],
        ];
        foreach ($paths[$to] as $status) {
            $set = ['status' => $status];
            if ($status === 'accepted') {
                $set += ['agreed_amount' => 1000, 'currency' => 'AFN', 'price_unit' => 'project'];
            }
            if ($status === 'completed') {
                $set['completed_at'] = now();
            }
            DB::table('orders')->where('id', $orderId)->update($set);
        }
    }
}
