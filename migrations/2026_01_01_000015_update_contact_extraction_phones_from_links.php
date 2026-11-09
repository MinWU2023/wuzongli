<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 联系方式提取：电话只由代码从 tel:/WhatsApp href 采集，提示词不再让 AI 猜号码。
 */
return new class extends Migration {
    public function up(): void
    {
        $system = '你是企业信息抽取专家。你只从给定网页文本中提取真实存在的公司名称、邮箱、地址与社交媒体主页，绝不编造。电话与 WhatsApp 由系统从页面链接提取，你不要从正文猜号码。只输出合法 JSON。';
        $user = <<<'PROMPT'
网站域名：{{domain}}

以下是该网站首页、关于我们、联系我们页面的文本（已按页面标注）：
{{pages_text}}

请提取：
- company_name：企业官方英文或中文名称。优先页脚、About Us、版权信息中的完整法定/品牌名称；不要返回“Home”“About Us”等导航词。
- phones：固定返回空数组 []。电话号码由系统只从页面 href="tel:" 与 WhatsApp 链接提取，不要从正文猜号码。
- emails：真实邮箱地址。
- linkedin / tiktok / youtube / facebook / instagram：企业官方主页完整 URL，没有则空字符串。不要返回分享按钮、员工个人主页或平台首页。
- address：公司地址（如页面提供）。
- whatsapp：固定返回空字符串。WhatsApp 由系统只从 href 链接提取。

如果没有某项信息，返回空字符串或空数组，不要臆造。仅返回：
{"company_name":"","phones":[],"emails":[],"linkedin":"","tiktok":"","youtube":"","facebook":"","instagram":"","address":"","whatsapp":""}
PROMPT;

        $this->db->update('prompts', [
            'system_prompt' => $system,
            'user_prompt' => $user,
            'description' => '从首页/关于/联系页提取公司名、邮箱、社媒；电话仅由系统从 tel:/WhatsApp 链接提取。变量：domain, pages_text。',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['code' => 'contact_extraction']);
    }

    public function down(): void
    {
        // Keep the stricter prompt; rolling back would reintroduce hallucinated phones.
    }
};
