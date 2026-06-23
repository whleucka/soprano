<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\PodcastService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class PodcastsController extends Controller
{
    public function __construct(
        private PodcastService $podcasts,
    ) {}

    #[Get("/podcasts", "podcasts.index")]
    public function index(): string
    {
        return $this->render("podcasts/index.html.twig", [
            "liked" => $this->podcasts->getLikedPodcasts(),
        ]);
    }

    #[Get("/podcasts/search", "podcasts.search")]
    public function search(): string
    {
        $q      = (string) request()->get->get("q");
        $offset = (int) request()->get->get("offset");

        return $this->render("podcasts/results.html.twig", [
            "results" => $this->podcasts->search($q, $offset),
            // offset > 0 means a "load more" click → emit an appendable fragment.
            "append"  => $offset > 0,
        ]);
    }

    #[Get("/podcasts/{hash}", "podcasts.show")]
    public function show(string $hash): string
    {
        $podcast = $this->podcasts->getPodcast($hash);
        if (!$podcast) {
            return $this->pageNotFound();
        }

        return $this->render("podcasts/show.html.twig", [
            "podcast" => $podcast,
        ]);
    }

    #[Get("/podcasts/{hash}/episodes", "podcasts.episodes")]
    public function episodes(string $hash): string
    {
        $next = request()->get->get("next");
        $podcast = $this->podcasts->getPodcast(
            $hash,
            ($next !== null && $next !== '') ? (int) $next : null,
        );
        if (!$podcast) {
            return "";
        }

        return $this->render("podcasts/episodes.html.twig", [
            "podcast" => $podcast,
        ]);
    }

    #[Get("/podcasts/{hash}/like", "podcasts.like")]
    public function like(string $hash): string
    {
        return $this->render("components/like-btn.html.twig", [
            "liked"    => $this->podcasts->isLiked($hash),
            "like_uri" => uri('podcasts.like-toggle', $hash),
        ]);
    }

    #[Get("/podcasts/{hash}/like-toggle", "podcasts.like-toggle")]
    public function likeToggle(string $hash): string
    {
        $this->podcasts->toggleLike($hash);
        $this->hxTrigger("like-$hash, podcastLikes");
        return $this->like($hash);
    }
}
