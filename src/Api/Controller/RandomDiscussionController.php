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

        // Losowanie RÓWNOMIERNE bez skanu OFFSET (który na dużym tagu przegląda i
        // odrzuca ~offset wierszy na każde kliknięcie). Próbkowanie z odrzuceniem po
        // kluczu głównym: losujemy id z [min,max] i przyjmujemy TYLKO realne trafienie.
        // Rozkład jest dowodliwie jednostajny (każde istniejące id ma równe szanse), a
        // usunięte/ukryte/niewidoczne — odfiltrowane w $base — NIGDY nie wracają (nie
        // pasują, więc próbujemy dalej). Dla typowego, gęstego tagu sukces w 1 próbie i
        // jest to O(log n) po PK. Skala: nie rośnie z liczbą dyskusji w tagu.
        $minId = (clone $base)->min('id');
        if ($minId === null) {
            return new JsonResponse(['error' => 'No discussions found for this tag'], 404);
        }
        $minId = (int) $minId;
        $maxId = (int) (clone $base)->max('id');

        $discussion = null;
        $attempts = $maxId > $minId ? 10 : 1;
        for ($i = 0; $i < $attempts; $i++) {
            $candidate = random_int($minId, $maxId);
            $discussion = (clone $base)->where('id', '=', $candidate)->first();
            if ($discussion) {
                break;
            }
        }

        // Fallback dla skrajnie rzadkiego tagu (dużo dziur w id) lub pecha w próbkowaniu —
        // wciąż RÓWNOMIERNY: losowy offset. Wyzwalany rzadko, więc skan nie boli.
        if (!$discussion) {
            $count = (clone $base)->count();
            if ($count > 0) {
                $discussion = $base->orderBy('id')->offset(random_int(0, $count - 1))->first();
            }
        }

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
