<?php

namespace TryHackX\HomepageBlocks\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\HomepageBlocks\Concerns\BuildsGuardResponse;
use TryHackX\HomepageBlocks\Security\RecaptchaGuard;

class RandomDiscussionController implements RequestHandlerInterface
{
    use BuildsGuardResponse;

    public function __construct(
        protected RecaptchaGuard $guard
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Bramka ochrony (zakres: random) — punkty / captcha / blokada IP.
        $result = $this->guard->verify($request, 'random');
        if ($error = $this->guardError($result)) {
            return $error;
        }

        $tagSlug = $request->getQueryParams()['tag'] ?? null;
        if (!$tagSlug) {
            return new JsonResponse(['error' => 'Tag slug is required'], 400);
        }

        $tag = Tag::where('slug', $tagSlug)->first();
        if (!$tag) {
            return new JsonResponse(['error' => 'Tag not found'], 404);
        }

        // Wszystkie ID tagów (rodzic + dzieci) dla szerszego dopasowania.
        $tagIds = [$tag->id];
        $children = Tag::where('parent_id', $tag->id)->pluck('id')->toArray();
        $tagIds = array_merge($tagIds, $children);

        $actor = RequestUtil::getActor($request);

        // Baza zapytania — RESPEKTUJEMY widoczność aktora (uprawnienia do tagów,
        // moderację/zatwierdzanie, ukryte/prywatne) zamiast tylko is_private/hidden_at.
        $base = Discussion::query()
            ->whereVisibleTo($actor)
            ->where('is_private', false)
            ->whereHas('tags', function ($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            });

        // Losowanie bez ORDER BY RAND() (pełny filesort na dużych tabelach):
        // policz pasujące i pobierz losowy offset przy deterministycznym porządku.
        $count = (clone $base)->count();
        if ($count === 0) {
            return new JsonResponse(['error' => 'No discussions found for this tag'], 404);
        }

        $offset = random_int(0, $count - 1);
        $discussion = $base->orderBy('id')->offset($offset)->first();

        if (!$discussion) {
            return new JsonResponse(['error' => 'No discussions found for this tag'], 404);
        }

        return new JsonResponse([
            'data' => [
                'id' => $discussion->id,
                'slug' => $discussion->slug,
                'title' => $discussion->title,
            ],
            'balance' => $result['balance'] ?? null,
            'cost' => $result['cost'] ?? null,
            'refilled' => $result['refilled'] ?? false,
        ]);
    }
}
