<?php

namespace App\Http\Controllers\Admin\Modules;

use App\Services\Admin\AnalyticsRange;
use App\Services\Admin\PlaybackAnalyticsService;
use Echo\Framework\Admin\NoTableTrait;
use Echo\Framework\Admin\WidgetRegistry;
use Echo\Framework\Http\ModuleController;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(pathPrefix: "/dashboard", namePrefix: "dashboard", middleware: ["max_requests" => 0])]
class DashboardController extends ModuleController
{
    use NoTableTrait;
    protected string $tableName = "";

    private ?AnalyticsRange $range = null;

    public function __construct(private PlaybackAnalyticsService $analytics)
    {
        parent::__construct();
    }

    /**
     * Resolve the window from the query string, remembering the choice.
     *
     * The range is sticky per session so coming back to the dashboard shows
     * the window you were last looking at, and so the individual widget
     * refresh URLs still land in the right window if their `?range=` is ever
     * lost in a swap.
     */
    private function range(): AnalyticsRange
    {
        if ($this->range !== null) {
            return $this->range;
        }

        $requested = request()->get->get('range');

        if ($requested !== null && isset(AnalyticsRange::OPTIONS[$requested])) {
            session()->set('dashboard_range', $requested);
        }

        return $this->range = AnalyticsRange::from(
            $requested ?? session()->get('dashboard_range'),
            $this->analytics->earliestPlay(),
        );
    }

    /**
     * The range selector and all three bands.
     *
     * Rendered as one response rather than three lazily-loaded bands: the
     * per-request memoisation in PlaybackAnalyticsService means the KPI strip
     * and the trend chart share their series query, which three separate
     * requests would each pay for again.
     */
    #[Get("/body", "body")]
    public function body(): string
    {
        return $this->render('admin/dashboard-body.html.twig', $this->bandData());
    }

    /**
     * @return array<string, mixed>
     */
    private function bandData(): array
    {
        $range = $this->range();

        return [
            'range' => $range,
            'range_options' => AnalyticsRange::OPTIONS,
            'dashboard_url' => uri('dashboard.admin.index'),
            'bands' => [
                'kpi' => WidgetRegistry::renderGroup('kpi', $range),
                'listening' => WidgetRegistry::renderGroup('listening', $range),
                'ops' => WidgetRegistry::renderGroup('ops', $range),
            ],
        ];
    }

    #[Get("/widgets/{id}", "widgets.render")]
    public function renderWidget(string $id): string
    {
        $widget = WidgetRegistry::get($id);

        if (!$widget) {
            return '<div class="alert alert-danger">Widget not found</div>';
        }

        return $widget->withRange($this->range())->render();
    }

    #[Get("/widgets", "widgets.all")]
    public function renderAllWidgets(): string
    {
        return WidgetRegistry::renderAll($this->range());
    }

    protected function renderTable(): string
    {
        return $this->render("admin/dashboard.html.twig", [
            ...$this->getCommonData(),
            ...$this->bandData(),
        ]);
    }
}
