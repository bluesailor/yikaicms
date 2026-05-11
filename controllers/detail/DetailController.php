<?php
/**
 * Yikai CMS — base class for detail-page controllers.
 *
 * Mirrors controllers/list/ListController so future maintainers see one
 * consistent pattern. Each detail entry-point (detail.php, product.php,
 * job_detail.php, download.php) gets its own subclass.
 */

declare(strict_types=1);

abstract class DetailController
{
    /**
     * Build view-model variables for the requested record.
     *
     * Returns null when the record can't be resolved — caller should
     * 404. Returning an array means the caller should extract() it and
     * render the view.
     *
     * @return array<string,mixed>|null
     */
    abstract public function prepare(int $id): ?array;
}
