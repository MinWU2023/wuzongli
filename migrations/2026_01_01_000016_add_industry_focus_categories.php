<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 补齐行业聚焦细分类：挂到已有大类下；同组内中英文相同或高度相似的跳过。
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
            // 一、光电子 / 光电 → optical_telecom_laser
            'optical_telecom_laser' => [
                ['红外热成像设备', 'Infrared Thermal Imaging Equipment'],
                ['光电传感器', 'Photoelectric Sensors'],
                ['MiniLED 背光、显示配套件', 'MiniLED Backlights and Display Supporting Parts'],
            ],
            // 二、消费电子、智能硬件 → consumer_electronics
            'consumer_electronics' => [
                ['骑行电子、运动码表', 'Cycling Electronics and Bike Computers'],
                ['热敏标签打印机、便携标签机', 'Thermal Label Printers and Portable Label Makers'],
                ['DIY 电竞主机、电脑配件', 'DIY Gaming PCs and Computer Accessories'],
                ['机箱、电源', 'Computer Cases and Power Supplies'],
                ['车载电源、便携储能小电源', 'Car Power Supplies and Portable Mini Power Stations'],
                ['健康监测小设备', 'Health Monitoring Gadgets'],
            ],
            // 三、汽车及新能源零部件
            'auto_parts' => [
                ['汽车底盘件', 'Automotive Chassis Parts'],
                ['电机、电控配件', 'Automotive Motor and Electronic Control Parts'],
            ],
            // 四、生物医药、医疗器械、耗材
            'health_medical' => [
                ['原料药、医药中间体', 'API and Pharmaceutical Intermediates'],
                ['植物提取物', 'Plant Extracts'],
            ],
            // 五、工装、功能纺织、劳保防护
            'apparel_textile' => [
                ['厨师服、保洁服', 'Chef and Cleaning Uniforms'],
                ['围裙、劳保围裙', 'Aprons and Work Aprons'],
                ['功能性面料', 'Functional Fabrics'],
                ['阻燃、防穿刺面料', 'Flame-Retardant and Puncture-Resistant Fabrics'],
                ['战术户外面料', 'Tactical Outdoor Fabrics'],
            ],
            'safety_cleaning' => [
                ['反光服饰、安全防护配件', 'Reflective Clothing and Safety Accessories'],
            ],
            // 六、自动化装备、工业设备、机器人
            'robotics_smart_mfg' => [
                ['管道检测机器人', 'Pipeline Inspection Robots'],
                ['非标自动化设备', 'Non-Standard Automation Equipment'],
            ],
            'machinery' => [
                ['无损检测仪器', 'Non-Destructive Testing Instruments'],
                ['输送设备', 'Conveying Equipment'],
                ['工装夹具', 'Jigs and Fixtures'],
                ['工业传感器', 'Industrial Sensors'],
                ['轨道交通配套零部件', 'Rail Transit Supporting Components'],
            ],
            // 七、家居、建材、户外、宠物用品
            'building_materials' => [
                ['墙板', 'Wall Panels'],
                ['硅酸钙板', 'Calcium Silicate Boards'],
                ['建材五金配件', 'Building Hardware Accessories'],
            ],
            // 八、其他分散赛道
            'agriculture_food' => [
                ['食用菌', 'Edible Mushrooms'],
            ],
            'chemicals_materials' => [
                ['功能薄膜', 'Functional Films'],
                ['工程塑料、助剂', 'Engineering Plastics and Additives'],
            ],
            'marine_special_vehicles' => [
                ['船用机电、船用配件', 'Marine Electromechanical Equipment and Ship Parts'],
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
            'optical_telecom_laser.infrared_thermal_imaging_equipment',
            'optical_telecom_laser.photoelectric_sensors',
            'optical_telecom_laser.miniled_backlights_and_display_supporting_parts',
            'consumer_electronics.cycling_electronics_and_bike_computers',
            'consumer_electronics.thermal_label_printers_and_portable_label_makers',
            'consumer_electronics.diy_gaming_pcs_and_computer_accessories',
            'consumer_electronics.computer_cases_and_power_supplies',
            'consumer_electronics.car_power_supplies_and_portable_mini_power_stations',
            'consumer_electronics.health_monitoring_gadgets',
            'auto_parts.automotive_chassis_parts',
            'auto_parts.automotive_motor_and_electronic_control_parts',
            'health_medical.api_and_pharmaceutical_intermediates',
            'health_medical.plant_extracts',
            'apparel_textile.chef_and_cleaning_uniforms',
            'apparel_textile.aprons_and_work_aprons',
            'apparel_textile.functional_fabrics',
            'apparel_textile.flame_retardant_and_puncture_resistant_fabrics',
            'apparel_textile.tactical_outdoor_fabrics',
            'safety_cleaning.reflective_clothing_and_safety_accessories',
            'robotics_smart_mfg.pipeline_inspection_robots',
            'robotics_smart_mfg.non_standard_automation_equipment',
            'machinery.non_destructive_testing_instruments',
            'machinery.conveying_equipment',
            'machinery.jigs_and_fixtures',
            'machinery.industrial_sensors',
            'machinery.rail_transit_supporting_components',
            'building_materials.wall_panels',
            'building_materials.calcium_silicate_boards',
            'building_materials.building_hardware_accessories',
            'agriculture_food.edible_mushrooms',
            'chemicals_materials.functional_films',
            'chemicals_materials.engineering_plastics_and_additives',
            'marine_special_vehicles.marine_electromechanical_equipment_and_ship_parts',
        ];
        if ($codes === []) {
            return;
        }
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
            // 一方名称包含另一方核心名（避免「光模块」与「高速光模块」之类重复入库）
            if ($zhN !== '' && $ezh !== '' && (str_contains($zhN, $ezh) || str_contains($ezh, $zhN))) {
                // 过短公共词（如「设备」「配件」）不视为相似
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
            $slug = substr($slug, 0, 60);
            $slug = rtrim($slug, '_');
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
