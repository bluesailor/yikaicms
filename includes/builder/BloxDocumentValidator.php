<?php
/** Blox 文档结构校验：拒绝非法结构，并允许已安装但停用的插件元素原样保留。 */

declare(strict_types=1);

final class BloxDocumentValidator
{
    private const MAX_ELEMENTS = 2000;

    /** @param array<int,mixed> $sections */
    public static function assertValidSections(array $sections): void
    {
        $ids = [];
        $elementCount = 0;
        foreach ($sections as $sectionIndex => $section) {
            $sectionPath = '区块 ' . ($sectionIndex + 1);
            if (!is_array($section)) {
                throw new RuntimeException($sectionPath . ' 结构无效');
            }
            self::rememberId($section['id'] ?? null, $sectionPath, $ids);
            $columns = $section['columns'] ?? [];
            if (!is_array($columns)) {
                throw new RuntimeException($sectionPath . ' 的列结构无效');
            }
            foreach (array_values($columns) as $columnIndex => $column) {
                $columnPath = $sectionPath . ' / 列 ' . ($columnIndex + 1);
                if (!is_array($column)) {
                    throw new RuntimeException($columnPath . ' 结构无效');
                }
                self::rememberId($column['id'] ?? null, $columnPath, $ids);
                $elements = $column['elements'] ?? [];
                if (!is_array($elements)) {
                    throw new RuntimeException($columnPath . ' 的元素结构无效');
                }
                foreach (array_values($elements) as $elementIndex => $element) {
                    self::assertValidElement(
                        $element,
                        $columnPath . ' / 元素 ' . ($elementIndex + 1),
                        null,
                        $ids,
                        $elementCount
                    );
                }
            }
        }
    }

    /**
     * @param array{type:string,allowedChildren:list<string>}|null $parentRule
     * @param array<string,string> $ids
     */
    private static function assertValidElement(
        mixed $node,
        string $path,
        ?array $parentRule,
        array &$ids,
        int &$elementCount
    ): void {
        if (!is_array($node)) {
            throw new RuntimeException($path . ' 结构无效');
        }
        $elementCount++;
        if ($elementCount > self::MAX_ELEMENTS) {
            throw new RuntimeException(__('blox_doc_too_many_elements', ['max' => self::MAX_ELEMENTS]));
        }

        $type = trim((string) ($node['type'] ?? ''));
        $element = $type !== '' ? BuilderRegistry::get($type) : null;
        $declaration = $element === null ? BloxPluginRegistry::declaration($type) : null;
        if ($element === null && $declaration === null) {
            throw new RuntimeException($path . ' 使用未知元素类型“' . ($type !== '' ? $type : '(空)') . '”');
        }

        self::rememberId($node['id'] ?? null, $path, $ids);
        $data = $node['data'] ?? [];
        if (!is_array($data)) {
            throw new RuntimeException($path . ' 的 data 结构无效');
        }

        $isContainer = $element?->isContainer() ?? (bool) ($declaration['container'] ?? false);
        $genericChild = $element?->canBeGenericChild() ?? (bool) ($declaration['genericChild'] ?? false);
        $allowedChildren = $element?->allowedChildren($data) ?? ($declaration['allowedChildren'] ?? []);

        if ($parentRule !== null) {
            $allowed = $parentRule['allowedChildren'];
            $direct = in_array($type, $allowed, true);
            $wildcard = in_array('*', $allowed, true) && !$isContainer && $genericChild;
            if (!$direct && !$wildcard) {
                throw new RuntimeException($path . ' 的“' . $type . '”不能直接放入“' . $parentRule['type'] . '”');
            }
        }

        $children = $data['children'] ?? [];
        if (!is_array($children)) {
            throw new RuntimeException($path . ' 的 children 结构无效');
        }
        if (!$isContainer && $children !== []) {
            throw new RuntimeException($path . ' 的非容器元素“' . $type . '”不能包含子元素');
        }
        if (!$isContainer) {
            return;
        }

        $childParentRule = ['type' => $type, 'allowedChildren' => $allowedChildren];
        foreach (array_values($children) as $childIndex => $child) {
            self::assertValidElement(
                $child,
                $path . ' / 子元素 ' . ($childIndex + 1),
                $childParentRule,
                $ids,
                $elementCount
            );
        }
    }

    /** @param array<string,string> $ids */
    private static function rememberId(mixed $rawId, string $path, array &$ids): void
    {
        if (!is_string($rawId) && !is_int($rawId)) {
            return;
        }
        $id = trim((string) $rawId);
        if ($id === '') {
            return;
        }
        if (isset($ids[$id])) {
            throw new RuntimeException(__('blox_doc_dup_id', ['id' => $id, 'a' => $ids[$id], 'b' => $path]));
        }
        $ids[$id] = $path;
    }
}
