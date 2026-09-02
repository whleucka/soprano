<?php

namespace Echo\Framework\Admin;

abstract class Widget
{
    protected string $id;
    protected string $title;
    protected string $icon = '';
    protected string $template;
    protected int $width = 6;
    protected int $refreshInterval = 0;
    protected int $cacheTtl = 0;
    protected int $priority = 100; // Lower = higher priority (displayed first)

    /**
     * Which band of the dashboard this widget belongs to. The dashboard renders
     * groups in its own order rather than one flat priority-sorted list, so a
     * new widget lands in the right band without renumbering everything else's
     * priority to squeeze it in.
     */
    protected string $group = 'ops';

    /**
     * Whether getData() reads $this->range. Ranged widgets re-render when the
     * page's range selector changes and cache per range; unranged ones are
     * rendered once and left alone.
     */
    protected bool $ranged = false;

    protected ?WidgetRange $range = null;

    /**
     * Get the widget data
     */
    abstract public function getData(): array;

    /**
     * Bind a time window to this widget.
     *
     * Widgets are registry singletons, so this mutates rather than clones —
     * a request renders each widget at most once, and cloning would defeat the
     * per-instance memoisation the analytics services rely on.
     */
    public function withRange(?WidgetRange $range): static
    {
        $this->range = $range;
        return $this;
    }

    /**
     * Render the widget
     */
    public function render(): string
    {
        // Ranged widgets cache per window. Without the suffix a "24 hours"
        // request happily serves the cached "7 days" HTML, which looks like a
        // broken range selector rather than a caching bug.
        $cacheKey = 'widget_' . $this->id;
        if ($this->ranged && $this->range) {
            $cacheKey .= '_' . $this->range->rangeKey();
        }

        if ($this->cacheTtl > 0) {
            $cached = $this->getCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $data = $this->getData();

        $html = twig()->render($this->template, [
            'widget' => [
                'id' => $this->id,
                'title' => $this->title,
                'icon' => $this->icon,
                'width' => $this->width,
                'refresh_interval' => $this->refreshInterval,
                'group' => $this->group,
                'ranged' => $this->ranged,
                'range' => $this->range ? [
                    'key' => $this->range->rangeKey(),
                    'label' => $this->range->rangeLabel(),
                ] : null,
            ],
            'data' => $data,
        ]);

        if ($this->cacheTtl > 0) {
            $this->setCache($cacheKey, $html, $this->cacheTtl);
        }

        return $html;
    }

    /**
     * Get widget ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get widget title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Get widget icon
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * Get widget width (Bootstrap grid columns 1-12)
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Get refresh interval in seconds
     */
    public function getRefreshInterval(): int
    {
        return $this->refreshInterval;
    }

    /**
     * Get widget priority (lower = displayed first)
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the dashboard band this widget belongs to
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Whether this widget's data depends on the selected time range
     */
    public function isRanged(): bool
    {
        return $this->ranged;
    }

    /**
     * Get cache from file
     */
    private function getCache(string $key): ?string
    {
        $cacheDir = config('paths.cache') ?? sys_get_temp_dir();
        $cacheFile = $cacheDir . '/widget_' . md5($key) . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $content = file_get_contents($cacheFile);
        $data = json_decode($content, true);

        if ($data === null || !isset($data['expires']) || $data['expires'] < time()) {
            @unlink($cacheFile);
            return null;
        }

        return $data['value'];
    }

    /**
     * Set cache to file
     */
    private function setCache(string $key, string $value, int $ttl): void
    {
        $cacheDir = config('paths.cache') ?? sys_get_temp_dir();

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . '/widget_' . md5($key) . '.cache';

        $data = [
            'expires' => time() + $ttl,
            'value' => $value,
        ];

        file_put_contents($cacheFile, json_encode($data));
    }
}
