<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 青岛外贸座谈聚焦赛道细分类：挂到已有大类；同组内中英文相同或高度相似的跳过。
 */
return new class extends Migration {
    public function up(): void
    {
        $groupsTable = $this->table('product_category_groups');
        $itemsTable = $this->table('product_categories');

        try {
            $this->db->value("SELECT 1 FROM {$groupsTable} LIMIT 1");
        } catch (\Throwable) {
            return;
        }

        /** @var array<string, array<int, array{0:string,1:string}>> $seed */
        $seed = [
            // 1. 针织/童装/运动服/面料 — 已有，跳过
            // 2. 橡胶制品/轮胎 — 补管带类细项（橡胶制品、轮胎、密封件已有）
            'plastics_rubber' => [
                ['橡胶管、胶垫', 'Rubber Hoses and Pads'],
                ['橡胶输送带', 'Rubber Conveyor Belts'],
            ],
            // 3. 假睫毛/假发 — 已有，跳过
            // 4. 制帽（帽子已有，补运动帽/针织帽）
            'apparel_textile' => [
                ['运动帽、棒球帽', 'Sports Caps and Baseball Caps'],
                ['针织帽', 'Knit Caps'],
            ],
            // 5. 汽配/卡车/工程机械 — 刹车滤清线束车灯底盘卡车件已有，补挂车配件
            'auto_parts' => [
                ['挂车配件', 'Trailer Parts and Accessories'],
            ],
            // 6. 家电配套 / 制冷 / 商用冷柜
            'home_kitchen' => [
                ['家电配套配件', 'Home Appliance Components'],
            ],
            'machinery' => [
                ['制冷配件', 'Refrigeration Components and Parts'],
            ],
            'commercial_equipment' => [
                ['商用冷柜', 'Commercial Refrigerators and Freezers'],
            ],
            // 7. 五金/劳保/园林/索具/紧固件 — 已有，跳过
            // 8. 水产 / 冷冻果蔬 / 预制食品
            'agriculture_food' => [
                ['冷冻果蔬', 'Frozen Fruits and Vegetables'],
                ['预制调理食品', 'Prepared and Ready-to-Cook Foods'],
                ['即食海产品', 'Ready-to-Eat Seafood'],
            ],
            // 9. 户外 / 帐篷已有，补睡袋、沙滩用品
            'sports_outdoor' => [
                ['睡袋', 'Sleeping Bags'],
                ['沙滩用品', 'Beach Products'],
            ],
            // 10. 家具/工艺品/节日礼品 — 已有，跳过
            // 11. 通用机械/环保除尘/包装机械 — 已有，跳过
            // 12. 光伏储能配电 — 已有，跳过
            // 13. 医疗耗材防护 — 已有，跳过
            // 14. 船舶游艇配件 — 已有，跳过
        ];

        $now = $this->now();
        foreach ($seed as $groupCode => $items) {
            $group = $this->db->fetch(
                "SELECT `id`, `code` FROM {$groupsTable} WHERE `code` = ? LIMIT 1",
                [$groupCode]
            );
            if ($group === null) {
                continue;
            }
            $groupId = (int) $group['id'];
            $existing = $this->db->fetchAll(
                "SELECT `code`, `name_zh`, `name_en` FROM {$itemsTable} WHERE `group_id` = ?",
                [$groupId]
            );
            $maxSort = (int) ($this->db->value(
                "SELECT COALESCE(MAX(`sort_order`), 0) FROM {$itemsTable} WHERE `group_id` = ?",
                [$groupId]
            ) ?? 0);
            $sort = $maxSort + 10;

            foreach ($items as [$zh, $en]) {
                if ($this->isSimilar($zh, $en, $existing)) {
                    continue;
                }
                $code = $this->uniqueItemCode($groupCode, $en, $itemsTable);
                $ok = $this->db->insertIgnore('product_categories', [
                    'group_id' => $groupId,
                    'code' => $code,
                    'name_zh' => $zh,
                    'name_en' => $en,
                    'sort_order' => $sort,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($ok) {
                    $existing[] = ['code' => $code, 'name_zh' => $zh, 'name_en' => $en];
                    $sort += 10;
                }
            }
        }
    }

    public function down(): void
    {
        $codes = [
            'plastics_rubber.rubber_hoses_and_pads',
            'plastics_rubber.rubber_conveyor_belts',
            'apparel_textile.sports_caps_and_baseball_caps',
            'apparel_textile.knit_caps',
            'auto_parts.trailer_parts_and_accessories',
            'home_kitchen.home_appliance_components',
            'machinery.refrigeration_components_and_parts',
            'commercial_equipment.commercial_refrigerators_and_freezers',
            'agriculture_food.frozen_fruits_and_vegetables',
            'agriculture_food.prepared_and_ready_to_cook_foods',
            'agriculture_food.ready_to_eat_seafood',
            'sports_outdoor.sleeping_bags',
            'sports_outdoor.beach_products',
        ];
        $table = $this->table('product_categories');
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $this->db->query("DELETE FROM {$table} WHERE `code` IN ({$placeholders})", $codes);
    }

    /**
     * @param array<int, array{code?:string,name_zh:string,name_en:string}> $existing
     */
    private function isSimilar(string $zh, string $en, array $existing): bool
    {
        $zhN = $this->normZh($zh);
        $enN = $this->normEn($en);
        foreach ($existing as $row) {
            $ezh = $this->normZh((string) ($row['name_zh'] ?? ''));
            $een = $this->normEn((string) ($row['name_en'] ?? ''));
            if ($zhN !== '' && $zhN === $ezh) {
                return true;
            }
            if ($enN !== '' && $enN === $een) {
                return true;
            }
            if ($zhN !== '' && $ezh !== '' && (str_contains($zhN, $ezh) || str_contains($ezh, $zhN))) {
                $shorter = mb_strlen($zhN) <= mb_strlen($ezh) ? $zhN : $ezh;
                if (mb_strlen($shorter) >= 4) {
                    return true;
                }
            }
            if ($enN !== '' && $een !== '' && min(strlen($enN), strlen($een)) >= 10) {
                if (str_contains($enN, $een) || str_contains($een, $enN)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function normZh(string $text): string
    {
        $text = mb_strtolower(trim($text));
        return (string) preg_replace('/[\s\/、,，\-_|（）()]+/u', '', $text);
    }

    private function normEn(string $text): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', $text));
    }

    private function slug(string $text): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_'));
        if ($slug === '') {
            $slug = 'item';
        }
        if (strlen($slug) > 60) {
            $slug = rtrim(substr($slug, 0, 60), '_');
        }
        return $slug;
    }

    private function uniqueItemCode(string $groupCode, string $en, string $itemsTable): string
    {
        $base = $groupCode . '.' . $this->slug($en);
        $code = $base;
        $n = 2;
        while (true) {
            $exists = (int) ($this->db->value(
                "SELECT COUNT(*) FROM {$itemsTable} WHERE `code` = ?",
                [$code]
            ) ?? 0);
            if ($exists === 0) {
                return $code;
            }
            $code = $base . '_' . $n++;
        }
    }
};
