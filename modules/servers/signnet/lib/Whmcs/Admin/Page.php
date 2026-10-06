<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Admin;

/**
 * One tab of the addon's admin area.
 */
interface Page
{
    /**
     * @return string The page's HTML, below the tabs.
     */
    public function render(AdminRequest $request): string;
}
