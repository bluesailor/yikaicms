<?php
/**
 * Yikai CMS — base class for /list.php sub-controllers.
 *
 * Refactor plan: docs/refactor-list-detail-plan.md.
 *
 * Each channel type (product / download / job / case / list / article)
 * gets its own subclass that knows two things:
 *   - prepare()   builds the view model (an associative array of vars)
 *   - viewName()  names the partial under views/list/<name>.php
 *
 * The thin list.php dispatcher pulls a controller by channel type, calls
 * prepare(), extract()s the result, then includes the view.
 *
 * Subclasses must remain free of HTML output — they only fetch + shape
 * data. Keeping them this way means each can be unit-tested with the
 * SQLite in-memory harness in tests/Models without spinning up Apache.
 */

declare(strict_types=1);

abstract class ListController
{
    /**
     * Build view model variables for this channel.
     *
     * @param array<string,mixed> $channel  the resolved channel row
     * @param array<string,mixed> $request  filtered request inputs
     *                                      (page, perPage, keyword, etc.)
     * @return array<string,mixed>
     */
    abstract public function prepare(array $channel, array $request): array;

    /**
     * Name of the view partial under views/list/. Defaults to the class
     * basename, lowercase, minus the trailing "Controller".
     */
    public function viewName(): string
    {
        $base = (new \ReflectionClass($this))->getShortName();
        $name = preg_replace('/Controller$/', '', $base);
        return strtolower($name);
    }

    /**
     * If true, the dispatcher should skip prepare()+view rendering and
     * trust the controller to have already issued a redirect or include.
     * Used by page → page.php and link → 302 cases.
     */
    public function shortCircuit(array $channel): bool
    {
        return false;
    }
}
