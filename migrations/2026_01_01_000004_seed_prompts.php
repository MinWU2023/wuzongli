<?php
declare(strict_types=1);

use App\Core\Migration;
use App\Services\Settings;

return new class extends Migration {
    public function up(): void
    {
        $prompts = [
            [
                'code' => 'keyword_generation',
                'name' => '城市外贸关键词生成',
                'description' => '根据目标城市生成用于 Google 搜索的英文采购意图关键词。变量：city, country, target_market, count, current_year。',
                'variables_json' => json_encode(['city', 'country', 'target_market', 'count', 'current_year'], JSON_UNESCAPED_UNICODE),
                'system_prompt' => <<<'PROMPT'
你是资深的中国外贸企业海外获客与 SEO 策略专家。你熟悉中国各城市的产业集群、英文独立站、品牌官网和 DTC 独立商城的搜索特征。你的任务是生成能发现“目标城市中面向海外市场的中国外贸企业网站”的高质量 Google 搜索词。只输出合法 JSON，不要输出 Markdown、解释或注释。
PROMPT,
                'user_prompt' => <<<'PROMPT'
目标城市：{{city}}
目标国家/地区：{{country}}
目标海外市场：{{target_market}}
当前年份：{{current_year}}
需要生成的关键词数量：{{count}}

业务背景：
我们要找到注册或运营在{{city}}、面向海外买家开展外贸业务的中国企业所运营的英文独立站、品牌官网或 DTC 独立商城，用于人工核验和商务联络。

生成规则：
1. 关键词必须是英文，长度 3–12 个单词，像真实海外采购商、品牌调研人员会输入到 Google 的查询。
2. 覆盖以下 5 类意图（intent 字段使用对应英文值）：
   - manufacturer：{{city}} + 产品/行业 + manufacturer / factory / supplier
   - official：{{city}} + company / brand + official website
   - dtc：{{city}} + brand + shop / online store / DTC
   - industry：结合{{city}}的支柱产业或优势供应链（例如武汉：光通信、激光设备、汽车零部件、生物医药、纺织服装、家居用品）
   - buyer：OEM / ODM / custom / wholesale + {{city}} + 产品
3. 每条关键词必须明确包含 Wuhan 或该城市的常用英文名；不要编造具体企业名称。
4. 不要使用 site:、引号、减号、OR 等搜索运算符，不要出现 Alibaba、Made-in-China、Amazon、eBay 等平台词。
5. 不要生成“best companies in Wuhan”“list of suppliers”“jobs”“news”“government”等目录、黄页、招聘、新闻或政府意图。
6. 关键词之间不得重复，产品词和行业词要多样，避免全部围绕同一个行业。
7. language 固定为 "en"。

仅返回以下 JSON 结构：
{"keywords":[{"keyword":"Wuhan optical fiber cable manufacturer","intent":"manufacturer","language":"en"}]}
PROMPT,
            ],
            [
                'code' => 'domain_classification',
                'name' => '域名企业属性判断',
                'description' => '每批 30 个域名，判断是否为目标城市的中国外贸企业独立站/官网/DTC。变量：city, country, businesses。',
                'variables_json' => json_encode(['city', 'country', 'businesses'], JSON_UNESCAPED_UNICODE),
                'system_prompt' => <<<'PROMPT'
你是中国企业出海独立站尽调专家。你需要根据域名、搜索结果标题、摘要和 URL 证据，判断网站是否属于“位于目标城市的中国外贸企业所运营的独立站、品牌官网或 DTC 独立商城”。你非常擅长区分真正的企业官网与 B2B 平台、目录、黄页、新闻、社媒、招聘、政府机构和海外本地公司。只输出合法 JSON，不要输出 Markdown 或解释。
PROMPT,
                'user_prompt' => <<<'PROMPT'
目标城市：{{city}}
企业所属国家：{{country}}

判定为 true 的条件（尽量同时满足）：
1. 网站是某家具体企业的独立官网、品牌站或 DTC 独立商城，而不是平台店铺、目录页或聚合页。
2. 企业是中国企业，且总部、工厂或核心运营地位于{{city}}，或搜索结果明确显示其与{{city}}存在强关联。
3. 网站面向海外市场，主要语言为英文（或其他外语），有产品/品牌/公司介绍，而不是纯中文国内站。
4. 网站有真实的企业信息或产品业务，不是停放域名、空站、纯博客或纯 affiliate 站。

必须判定为 false 的情况：
- Alibaba、Made-in-China、Global Sources、Amazon、eBay、Etsy 等平台及其子域名。
- Facebook、LinkedIn、YouTube、TikTok、Instagram、Pinterest、X/Twitter 等社交媒体。
- 黄页、企业名录、新闻媒体、政府/协会、展会、招聘、百科、点评、比价、论坛。
- 海外本地公司、明显与{{city}}无关的网站、纯中文站、无法确认企业属性的网站。
- 只有第三方店铺链接、没有品牌独立站证据的网站。

判定原则：证据不足时输出 false，不要猜测。reason 用简短中文说明依据，confidence 为 0–1 的小数。

待判断网站（JSON 数组，n 为编号）：
{{businesses}}

仅返回以下 JSON，且必须覆盖输入中的每一个 n：
{"results":[{"n":1,"is_target":true,"confidence":0.86,"reason":"英文官网，标题显示武汉激光设备制造商"}]}
PROMPT,
            ],
            [
                'code' => 'page_discovery',
                'name' => '官网关键页面识别',
                'description' => '从首页链接中识别首页、About Us、Contact Us。变量：domain, home_url, links。',
                'variables_json' => json_encode(['domain', 'home_url', 'links'], JSON_UNESCAPED_UNICODE),
                'system_prompt' => <<<'PROMPT'
你是网站信息架构分析专家。你会从官网首页提取出的链接列表中，准确识别企业官网的首页、关于我们（About Us）和联系我们（Contact Us）页面。你只选择同一站点下的真实页面链接，不编造 URL。只输出合法 JSON。
PROMPT,
                'user_prompt' => <<<'PROMPT'
网站域名：{{domain}}
首页 URL：{{home_url}}

候选链接（JSON 数组，包含 url 和 text）：
{{links}}

任务：
1. 从候选中找出官网首页 URL（通常为根路径或候选中的 Home）。
2. 找出“关于我们 / About / Company / Our Story / Profile”页面。
3. 找出“联系我们 / Contact / Get in Touch / Inquiry”页面。
4. 只能返回候选列表或首页根路径中的 URL；必须是同一域名（可含 www）下的 http/https 链接；不要返回 Facebook、LinkedIn 等外链。
5. 如果找不到 About 或 Contact，对应值返回 null，不要猜测或拼接不存在的路径。
6. 优先英文页面；如果只有其他语言也可以接受。

仅返回：
{"home":"https://example.com/","about":"https://example.com/about-us","contact":"https://example.com/contact"}
PROMPT,
            ],
            [
                'code' => 'contact_extraction',
                'name' => '企业联系方式提取',
                'description' => '从首页/关于/联系页文本中提取公司名、电话、邮箱、LinkedIn、TikTok、YouTube 等。变量：domain, pages_text。',
                'variables_json' => json_encode(['domain', 'pages_text'], JSON_UNESCAPED_UNICODE),
                'system_prompt' => <<<'PROMPT'
你是企业信息抽取专家。你只从给定网页文本中提取真实存在的公司名称和联系方式，绝不编造、猜测或补全。你尤其擅长识别中国外贸企业英文官网中的公司名、手机号、座机、WhatsApp、邮箱和社交媒体主页。只输出合法 JSON。
PROMPT,
                'user_prompt' => <<<'PROMPT'
网站域名：{{domain}}

以下是该网站首页、关于我们、联系我们页面的文本（已按页面标注）：
{{pages_text}}

请提取：
- company_name：企业官方英文或中文名称。优先页脚、About Us、版权信息中的完整法定/品牌名称；不要返回“Home”“About Us”等导航词。
- phones：页面中出现的电话/手机/WhatsApp 号码，保留国家码和原始格式，去重；不要提取传真、订单号、日期、邮编。
- emails：真实邮箱地址。
- linkedin / tiktok / youtube / facebook / instagram：企业官方主页完整 URL，没有则空字符串。不要返回分享按钮、员工个人主页或平台首页。
- address：公司地址（如页面提供）。
- whatsapp：WhatsApp 号码或 wa.me 链接，没有则空字符串。

如果没有某项信息，返回空字符串或空数组，不要臆造。仅返回：
{"company_name":"","phones":[],"emails":[],"linkedin":"","tiktok":"","youtube":"","facebook":"","instagram":"","address":"","whatsapp":""}
PROMPT,
            ],
        ];

        foreach ($prompts as $prompt) {
            $prompt['is_active'] = 1;
            $this->db->insertIgnore('prompts', $prompt);
        }

        foreach (Settings::defaults() as $key => $value) {
            $this->db->insertIgnore('settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => $this->now(),
            ]);
        }
    }

    public function down(): void
    {
        $codes = ['keyword_generation', 'domain_classification', 'page_discovery', 'contact_extraction'];
        foreach ($codes as $code) {
            $this->db->delete('prompts', ['code' => $code]);
        }
        foreach (array_keys(Settings::defaults()) as $key) {
            $this->db->delete('settings', ['setting_key' => $key]);
        }
    }
};
