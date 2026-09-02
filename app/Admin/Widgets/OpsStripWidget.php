<?php

namespace App\Admin\Widgets;

use App\Services\Admin\OpsSummaryService;
use Echo\Framework\Admin\Widget;

/**
 * Eight operational scalars in one row.
 *
 * This is the whole of what the old dashboard spent eight cards saying. Each
 * cell links to the module that owns the detail, so nothing is lost — it just
 * stops competing with the analytics for the top of the page.
 */
class OpsStripWidget extends Widget
{
    use ResolvesRange;

    protected string $id = 'ops-strip';
    protected string $title = 'Operations';
    protected string $icon = 'sliders';
    protected string $template = 'admin/widgets/ops-strip.html.twig';
    protected string $group = 'ops';
    protected bool $ranged = true;
    protected int $width = 12;
    protected int $refreshInterval = 60;
    protected int $priority = 300;

    public function __construct(private OpsSummaryService $ops)
    {
    }

    public function getData(): array
    {
        return ['cells' => $this->ops->getStrip($this->range())];
    }
}
