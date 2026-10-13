<?php

namespace TryHackX\HomepageBlocks\Advanced;

/**
 * Serializes `tryhackxHomepageAdv` for the forum (see AdvancedSettings::forumPayload()).
 * Invoked by Extend\Settings::serializeToForum with the master switch's raw value.
 */
class ForumPayload
{
    public function __construct(private AdvancedSettings $adv)
    {
    }

    public function __invoke(mixed $value): ?array
    {
        return $this->adv->forumPayload();
    }
}
