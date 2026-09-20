<?php
/**
 * 文档保存后的反向索引统一维护点（v1.23 类引用 + v1.25 查询引用）。
 * 新增全局实体的用量索引时在这里挂接，别再去改五条保存链路。
 */

declare(strict_types=1);

final class BloxDocumentIndexes
{
    /** @param array<int,mixed> $sections */
    public static function update(string $docKey, array $sections): void
    {
        BloxGlobalClasses::replaceDocumentRefs($docKey, BloxGlobalClasses::collectReferences($sections));
        BloxGlobalQueries::replaceDocumentRefs($docKey, BloxGlobalQueries::collectReferences($sections));
    }
}
