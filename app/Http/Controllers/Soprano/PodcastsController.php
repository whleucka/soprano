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
            "genres"      => $this->podcasts->genres(),
            "liked"       => $this->podcasts->getLikedPodcasts(),
            "in_progress" => $this->podcasts->getInProgress(),
        ]);
    }

    #[Get("/podcasts/search", "podcasts.search")]
    public function search(): string
    {
        $q      = (string) request()->get->get("q");
        $offset = (int) request()->get->get("offset");
        $res    = $this->podcasts->search($q, $offset);

        $more = $res['next_offset'] !== null
            ? uri('podcasts.search') . '?q=' . urlencode($q) . '&offset=' . $res['next_offset']
            : null;

        return $this->render("podcasts/results.html.twig", [
            "r" => [
                "items"         => $res['items'],
                "icon"          => "bi-search",
                "title"         => 'Results for “' . $q . '”',
                "more_uri"      => $more,
                "empty_message" => $q !== '' ? 'No podcasts found for “' . $q . '”' : null,
            ],
            // offset > 0 means a "load more" click → emit an appendable fragment.
            "append" => $offset > 0,
        ]);
    }

    #[Get("/podcasts/best", "podcasts.best")]
    public function best(): string
    {
        $genre   = request()->get->get("genre");
        $genreId = ($genre !== null && $genre !== '') ? (string) $genre : null;
        $page    = max(1, (int) request()->get->get("page"));
        $res     = $this->podcasts->best($genreId, $page);

        $more = $res['next_page'] !== null
            ? uri('podcasts.best') . '?' . http_build_query(array_filter([
                'genre' => $genreId,
                'page'  => $res['next_page'],
            ]))
            : null;

        return $this->render("podcasts/results.html.twig", [
            "r" => [
                "items"         => $res['items'],
                "icon"          => "bi-star-fill",
                "title"         => $res['genre_name'] ? 'Best ' . $res['genre_name'] . ' Podcasts' : 'Best Podcasts',
                "more_uri"      => $more,
                "empty_message" => 'No podcasts available right now',
            ],
            // page > 1 means a "load more" click → emit an appendable fragment.
            "append" => $page > 1,
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
