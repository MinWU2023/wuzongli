<?php
declare(strict_types=1);

/**
 * 关键词生成的「独立商城站优先」规则。
 * 由 AiService 在生成关键词时强制追加（模板里没有该标记时），
 * 迁移 2026_01_01_000010 也会把它写入数据库里的 keyword_generation 模板。
 * 标记文字「独立商城站优先策略」用于判断是否已包含，请勿删除。
 * 支持变量：{{city}}、{{target_market}}。
 */
return <<<'RULES'
【独立商城站优先策略（最高优先级，与上文冲突时以本节为准）】
本次生成关键词的唯一目标：让 Google 搜索结果里尽可能多地出现「企业独立站、独立 B2C 商城、独立 B2B 官网/商城」，而不是 Alibaba、Made-in-China 等平台、黄页目录、榜单、新闻或论坛。
1. 关键词要模仿“海外买家直接寻找品牌商店或工厂官网”的搜索，大量使用独立站特征词：online store、official store、official website、shop、shop now、buy online、free shipping、worldwide shipping、add to cart、our products、new arrivals、collection、factory direct、brand、wholesale price list、request a quote、MOQ、custom OEM ODM。
2. 优先结构：{{city}} + 具体单品产品词 + 独立站特征词，例如“Wuhan bluetooth earbuds official store”“Wuhan LED strip lights factory direct shop”。产品词必须具体到单品，不要用 electronics、products、goods 这类泛词。
3. 每条关键词 3–10 个单词，偏长尾、带采购或购买意图。
4. 至少 70% 的关键词必须带上述独立站特征词，intent 使用 dtc / official / manufacturer / buyer；其余才可使用 industry。
5. 多用 manufacturer、factory、brand、official、store、shop、OEM、ODM；少用或不用 supplier、exporter、trading company（容易搜出 B2B 平台）。
6. 严禁出现会引出平台和目录的词：directory、list of、top 10、best、ranking、yellow pages、marketplace、B2B platform、wholesale platform、suppliers list、news、forum、jobs、review、alibaba、made-in-china、amazon、ebay、aliexpress、global sources。
7. 同一产品可以换不同的独立站特征词写成多条关键词，但整体不得重复，也不要把全部关键词集中在同一个产品上。
8. 仍然不得使用 site:、引号、减号、OR 等运算符，不要编造具体企业名称。
RULES;
