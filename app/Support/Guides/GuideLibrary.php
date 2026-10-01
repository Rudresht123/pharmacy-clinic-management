<?php

namespace App\Support\Guides;

use Illuminate\Support\Carbon;

/**
 * The guides in `docs/guides`, as the in-app Guide section reads them.
 *
 * THE FOLDER IS THE LIST. A guide is an `.html` file there, with the PDF built
 * from it beside it (`php docs/guides/build.php`). Adding one is adding the
 * file — there is no registry to forget to update, and the title and summary
 * shown in the app are the page's own `<title>` and description meta.
 *
 * A SLUG IS A FILE NAME, checked twice: it must look like one (lowercase,
 * digits, hyphens) and it must be one of the files actually listed. A request
 * can therefore never name a path — `../../.env` is not a slug, and a slug
 * that is not in the folder is not a guide.
 */
class GuideLibrary
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]*$/';

    public function __construct(
        private readonly ?string $directory = null,
    ) {}

    /**
     * @return list<array{slug: string, title: string, description: string, has_pdf: bool, updated_at: string}>
     */
    public function all(): array
    {
        $guides = [];

        foreach (glob($this->directory().'/*.html') ?: [] as $path) {
            $slug = basename($path, '.html');

            if (preg_match(self::SLUG, $slug) !== 1) {
                continue;
            }

            $guides[] = $this->describe($slug, $path);
        }

        usort($guides, fn (array $a, array $b) => strcmp($a['title'], $b['title']));

        return $guides;
    }

    /**
     * One guide with its page, or null when there is no such guide.
     *
     * @return array{slug: string, title: string, description: string, has_pdf: bool, updated_at: string, html: string}|null
     */
    public function find(string $slug): ?array
    {
        $path = $this->pathFor($slug, 'html');

        if ($path === null) {
            return null;
        }

        return $this->describe($slug, $path) + ['html' => (string) file_get_contents($path)];
    }

    /** The built PDF of a guide, or null when it has none. */
    public function pdf(string $slug): ?string
    {
        return $this->pathFor($slug, 'html') === null ? null : $this->pathFor($slug, 'pdf');
    }

    private function pathFor(string $slug, string $extension): ?string
    {
        if (preg_match(self::SLUG, $slug) !== 1) {
            return null;
        }

        $path = $this->directory().'/'.$slug.'.'.$extension;

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{slug: string, title: string, description: string, has_pdf: bool, updated_at: string}
     */
    private function describe(string $slug, string $path): array
    {
        $html = (string) file_get_contents($path);

        return [
            'slug' => $slug,
            'title' => $this->match('/<title>(.*?)<\/title>/si', $html) ?: $slug,
            'description' => $this->match('/<meta\s+name="description"\s+content="(.*?)"/si', $html),
            'has_pdf' => is_file($this->directory().'/'.$slug.'.pdf'),
            'updated_at' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
        ];
    }

    private function match(string $pattern, string $html): string
    {
        return preg_match($pattern, $html, $found) === 1
            ? trim(html_entity_decode(strip_tags($found[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '';
    }

    private function directory(): string
    {
        return $this->directory ?? base_path('docs/guides');
    }
}
