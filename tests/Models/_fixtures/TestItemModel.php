<?php
/**
 * Synthetic Model subclass for exercising Model.php in isolation.
 * Keeps tests independent of the real CMS schema.
 */

declare(strict_types=1);

class TestItemModel extends Model
{
    protected string $table        = 'test_items';
    protected string $primaryKey   = 'id';
    protected string $defaultOrder = 'id DESC';
}
