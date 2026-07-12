<?php
/**
 * Yikai CMS — product.php 的数据装配控制器（产品详情）。
 *
 * 载入已发布产品、自增浏览量、解析分类 / 相关 / 上一个下一个产品，
 * 并把图片组（JSON 或换行分隔）、规格参数（JSON 或 key:value 行）解析成数组。
 * id<=0 或产品不存在/未发布时返回 null，由 product.php 决定 404。
 *
 * 与 detail.php / article.php 同款 controller 模式，由 ProductDetailControllerTest 守护。
 */

declare(strict_types=1);

require_once __DIR__ . '/DetailController.php';

final class ProductDetailController extends DetailController
{
    /**
     * @return array<string,mixed>|null
     */
    public function prepare(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $product = productModel()->getPublished($id);
        if (!$product) {
            return null;
        }

        // 副作用：每次渲染自增一次浏览量。
        addProductViews($id);

        $categoryId = (int) $product['category_id'];

        $productCategory = $categoryId > 0 ? getProductCategory($categoryId) : null;
        $relatedProducts = $categoryId > 0 ? productModel()->getRelated($categoryId, $id) : [];
        $prevProduct     = productModel()->getPrev($categoryId, $id);
        $nextProduct     = productModel()->getNext($categoryId, $id);

        return [
            'product'         => $product,
            'productCategory' => $productCategory,
            'relatedProducts' => $relatedProducts,
            'prevProduct'     => $prevProduct,
            'nextProduct'     => $nextProduct,
            'productImages'   => $this->parseImages($product),
            'specs'           => $this->parseSpecs((string) ($product['specs'] ?? '')),
        ];
    }

    /**
     * 图片组：兼容 JSON 数组与换行分隔两种格式；封面图补到开头。
     * @param array<string,mixed> $product
     * @return list<string>
     */
    private function parseImages(array $product): array
    {
        $images = [];
        if (!empty($product['images'])) {
            $decoded = json_decode((string) $product['images'], true);
            if (is_array($decoded)) {
                $images = $decoded;
            } else {
                $images = array_filter(array_map('trim', explode("\n", (string) $product['images'])));
            }
        }
        if (!empty($product['cover']) && !in_array($product['cover'], $images, true)) {
            array_unshift($images, $product['cover']);
        }
        return array_values($images);
    }

    /**
     * 规格参数：优先 JSON；否则按行解析 key:value。
     * @return array<int,array{name:string,value:string}>|array<string,mixed>
     */
    private function parseSpecs(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $specsData = json_decode($raw, true);
        if ($specsData) {
            return $specsData;
        }
        $specs = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $specs[] = ['name' => trim($key), 'value' => trim($value)];
            }
        }
        return $specs;
    }
}
