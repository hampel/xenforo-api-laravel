<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Exception;

use Hampel\XenForo\Api\Exception\XenForoException;

/**
 * A forum was asked for by a name that is not in the configuration.
 *
 * Extends the core package's base exception, so an application already catching
 * XenForoException - or ExceptionInterface - catches a misconfigured connection name
 * alongside every other way a call can fail.
 */
final class UnknownForum extends XenForoException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'XenForo forum "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No forums are configured; add one under xenforo.forums.'
                : 'Configured forums: ' . implode(', ', $configured) . '.'
        ));
    }
}
