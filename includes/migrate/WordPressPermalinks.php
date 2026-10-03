<?php
declare(strict_types=1);

/**
 * 按原站的固定链接设置还原每个条目的网址（WordPress 导入用，纯函数）。
 *
 * - 文章：permalink_structure（%year% %monthnum% %day% %hour% %minute% %second% %post_id% %postname% %category% %author%）
 * - 单页：/父/子/
 * - 商品：WooCommerce product_base（可含 %product_cat%），商品分类 / 标签用 category_base、tag_base
 * - 文章分类 / 标签：category_base（默认 category）、tag_base（默认 tag），分类路径含父级
 * 结尾斜杠跟随 permalink_structure。返回不带语言前缀的路径；非默认语言由调用方加 /xx。
 * 别名保持 WordPress 存的样子（非 ASCII 是小写百分号编码），登记表会统一规范化。
 */
final class WordPressPermalinks
{
    private string $structure;
    private bool $slash;
    private string $categoryBase;
    private string $tagBase;
    private string $productBase;
    private string $productCategoryBase;
    private string $productTagBase;

    /** @param array<array-key,mixed> $woocommercePermalinks */
    public function __construct(string $structure, string $categoryBase = '', string $tagBase = '', array $woocommercePermalinks = [])
    {
        $structure = trim($structure);
        if ($structure === '') throw new InvalidArgumentException('wp_plain_permalinks');   // ?p=123 形式没有可保留的路径
        $this->structure = '/' . ltrim($structure, '/');
        $this->slash = str_ends_with($structure, '/');
        $this->categoryBase = self::base($categoryBase, 'category');
        $this->tagBase = self::base($tagBase, 'tag');
        $this->productBase = '/' . trim((string) ($woocommercePermalinks['product_base'] ?? '') ?: 'product', '/');
        $this->productCategoryBase = self::base((string) ($woocommercePermalinks['category_base'] ?? ''), 'product-category');
        $this->productTagBase = self::base((string) ($woocommercePermalinks['tag_base'] ?? ''), 'product-tag');
    }

    private static function base(string $value, string $default): string
    {
        $value = trim($value, '/ ');
        return $value === '' ? $default : $value;
    }

    private function finish(string $path): string
    {
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        return $path === '/' ? '/' : ($this->slash ? $path . '/' : $path);
    }

    /**
     * @param array<string,mixed> $post posts 行（ID、post_name、post_date）
     * @param string $categoryPath 主分类的路径（父/子），%category% 用
     */
    public function post(array $post, string $categoryPath = '', string $author = ''): string
    {
        $date = strtotime((string) ($post['post_date'] ?? '')) ?: 0;
        $path = strtr($this->structure, [
            '%year%' => date('Y', $date), '%monthnum%' => date('m', $date), '%day%' => date('d', $date),
            '%hour%' => date('H', $date), '%minute%' => date('i', $date), '%second%' => date('s', $date),
            '%post_id%' => (string) (int) ($post['ID'] ?? 0), '%postname%' => (string) ($post['post_name'] ?? ''),
            '%category%' => $categoryPath !== '' ? $categoryPath : 'uncategorized', '%author%' => $author,
        ]);
        return $this->finish($path);
    }

    /** @param list<string> $ancestorSlugs 从顶层到父级 */
    public function page(string $slug, array $ancestorSlugs = []): string
    {
        return $this->finish(implode('/', array_merge($ancestorSlugs, [$slug])));
    }

    public function product(string $slug, string $categoryPath = ''): string
    {
        $base = str_replace('%product_cat%', $categoryPath !== '' ? $categoryPath : 'uncategorized', $this->productBase);
        return $this->finish($base . '/' . $slug);
    }

    /** @param string $termPath 含父级的路径（父/子） */
    public function term(string $taxonomy, string $termPath): string
    {
        $base = match ($taxonomy) {
            'category' => $this->categoryBase,
            'post_tag' => $this->tagBase,
            'product_cat' => $this->productCategoryBase,
            'product_tag' => $this->productTagBase,
            default => throw new InvalidArgumentException('wp_taxonomy_unsupported'),
        };
        return $this->finish($base . '/' . $termPath);
    }
}
