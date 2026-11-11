<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 杭州外贸座谈聚焦赛道细分类：挂到已有大类；同组内中英文相同或高度相似的跳过。
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
            // 1. 小家电 / 厨房家电（已有小家电、空气炸锅等，只补更细的）
            'home_kitchen' => [
                ['破壁机、料理机', 'Blenders and Food Processors'],
                ['小型制冷家电', 'Compact Refrigeration Appliances'],
            ],
            // 2. 安防 / 智能家居（摄像头、门锁、门禁已有）
            'smart_home_security' => [
                ['NVR 录像机', 'NVR Recorders'],
                ['安防配套配件', 'Security Supporting Accessories'],
            ],
            // 3. 纺织服装（运动服/泳装/面料辅料已有）
            'apparel_textile' => [
                ['时装、成衣贴牌', 'Fashion Apparel OEM and ODM'],
            ],
            // 4. 家具 / 庭院 / 园艺（户外家具、收纳、园林工具已有）
            'garden_outdoor_living' => [
                ['庭院遮阳用品', 'Patio Shade and Awning Products'],
            ],
            // 5. 骑行（电动自行车、自行车配件、骑行装备已有）
            'vehicles_mobility' => [
                ['电助力自行车配件', 'E-bike Parts and Accessories'],
                ['童车', 'Kids Bicycles'],
            ],
            // 6. 光伏 / 储能配套（组件、逆变器、便携储能、支架、电芯已有）
            'new_energy' => [
                ['储能电池包 PACK', 'Energy Storage Battery Packs'],
                ['光伏配套配件', 'PV Supporting Components'],
            ],
            // 7. 五金工具 — 手动/电动/紧固件/园林工具已覆盖，不重复
            // 8. 智能穿戴 / AR（手表手环、蓝牙耳机、VR/AR 已有）
            'consumer_electronics' => [
                ['AR 智能眼镜', 'AR Smart Glasses'],
            ],
            // 9. 礼品节庆家居装饰 — 礼品/节日/工艺品/香薰/餐具已覆盖，不重复
            // 10. 汽车 / 新能源零部件（灯具、线束、底盘、电控已有）
            'auto_parts' => [
                ['新能源汽车零部件', 'New Energy Vehicle Components'],
                ['充电桩配件', 'EV Charger Accessories'],
            ],
            // 11. 机器人 / 自动化（工业机器人、管道检测、非标自动化已有）
            'robotics_smart_mfg' => [
                ['仿生机器人', 'Bionic Robots'],
                ['巡检机器人', 'Inspection Robots'],
            ],
            // 12. 锂电池电芯 — 已有，跳过
            // 13. 新能源整车 — 已有，跳过
            // 14. 医疗器械 / 健康护理（口罩耗材、家用器械已有）
            'health_medical' => [
                ['一类二类医疗器械', 'Class I and II Medical Devices'],
                ['健康护理用品', 'Health Care and Nursing Products'],
            ],
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
            'home_kitchen.blenders_and_food_processors',
            'home_kitchen.compact_refrigeration_appliances',
            'smart_home_security.nvr_recorders',
            'smart_home_security.security_supporting_accessories',
            'apparel_textile.fashion_apparel_oem_and_odm',
            'garden_outdoor_living.patio_shade_and_awning_products',
            'vehicles_mobility.e_bike_parts_and_accessories',
            'vehicles_mobility.kids_bicycles',
            'new_energy.energy_storage_battery_packs',
            'new_energy.pv_supporting_components',
            'consumer_electronics.ar_smart_glasses',
            'auto_parts.new_energy_vehicle_components',
            'auto_parts.ev_charger_accessories',
            'robotics_smart_mfg.bionic_robots',
            'robotics_smart_mfg.inspection_robots',
            'health_medical.class_i_and_ii_medical_devices',
            'health_medical.health_care_and_nursing_products',
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
