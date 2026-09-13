<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Exception;

use Hampel\XenForo\Api\Exception\XenForoException;

/**
 * A forum's settings cannot be turned into a client, whether they came from the configuration
 * or were passed to XenForoManager::build().
 *
 * Raised rather than letting a half-configured forum through, because both cases it covers
 * fail somewhere far less obvious. A missing URL reaches the core package as an empty base
 * URI; an acting user with no key beside it produces a request that XenForo answers as a
 * guest, so the call succeeds and quietly returns less than it should.
 */
final class InvalidConfiguration extends XenForoException
{
    /**
     * @param  string|null  $forum  the configured forum's name, or null for settings passed to
     *                              XenForoManager::build()
     */
    public static function missingUrl(?string $forum): self
    {
        if ($forum === null) {
            return new self('The XenForo forum settings passed to XenForoManager::build() have no url.');
        }

        return new self(sprintf(
            'XenForo forum "%s" has no url. Set it in config/xenforo.php, '
            . 'or in the environment if the shipped config is in use.',
            $forum
        ));
    }

    /**
     * @param  string|null  $forum  as for missingUrl()
     */
    public static function actingUserWithoutKey(?string $forum): self
    {
        return new self(sprintf(
            '%s names a user to act as but has no key. Acting as a user needs a super-user key; '
            . 'without one the request would be made as a guest and answered normally.',
            $forum === null
                ? 'The XenForo forum settings passed to XenForoManager::build()'
                : sprintf('XenForo forum "%s"', $forum)
        ));
    }
}
