<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The facade's @method annotations are the only types a call through it has.
 *
 * XenForo::users() goes through the manager's __call() and returns mixed; what makes
 * `XenForo::users()->get(1)` analysable is the annotation, not the code. So an accessor
 * added to the core package's Client in a later release is a call that works at runtime and
 * loses its type silently - the failure mode a facade is most prone to, and the one nothing
 * else here would report.
 *
 * Deliberately not a Testbench test: it reads classes, boots nothing, and is quicker to
 * reach for when the core package is upgraded.
 */
final class FacadeConformanceTest extends BaseTestCase
{
    #[Test]
    public function every_client_method_is_annotated_on_the_facade(): void
    {
        $missing = array_diff($this->clientMethods(), $this->annotatedMethods());

        $this->assertSame([], array_values($missing), sprintf(
            'The core package has %s that the facade does not annotate. Add a @method line '
            . 'for each to %s, or calls through the facade lose their return type.',
            implode(', ', $missing),
            XenForo::class
        ));
    }

    #[Test]
    public function the_facade_annotates_nothing_that_no_longer_exists(): void
    {
        $known = array_merge($this->clientMethods(), $this->managerMethods());
        $stale = array_diff($this->annotatedMethods(), $known);

        $this->assertSame([], array_values($stale), sprintf(
            'The facade annotates %s, which neither %s nor %s has.',
            implode(', ', $stale),
            Client::class,
            XenForoManager::class
        ));
    }

    #[Test]
    public function every_annotated_return_type_resolves_to_a_real_class(): void
    {
        // A @method line naming a class that does not exist is invisible at runtime and
        // silently useless to static analysis, which is the same failure as not having one.
        $doc = (string) (new ReflectionClass(XenForo::class))->getDocComment();

        preg_match_all('/@method static ([^ ]+) /', $doc, $matches);

        foreach ($matches[1] as $type) {
            if (in_array($type, ['string', 'int', 'bool', 'void', 'mixed'], true) || str_contains($type, '<')) {
                continue;
            }

            $this->assertTrue(
                class_exists($this->resolve($type)) || interface_exists($this->resolve($type)),
                sprintf('The facade annotates a return type that does not exist: %s', $type)
            );
        }
    }

    /**
     * @return list<string>
     */
    private function annotatedMethods(): array
    {
        $doc = (string) (new ReflectionClass(XenForo::class))->getDocComment();

        preg_match_all('/@method static [^ ]+ ([A-Za-z0-9_]+)\(/', $doc, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function clientMethods(): array
    {
        return $this->publicMethods(Client::class);
    }

    /**
     * @return list<string>
     */
    private function managerMethods(): array
    {
        return array_values(array_diff($this->publicMethods(XenForoManager::class), ['__call']));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function publicMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isConstructor() && ! $method->isStatic()) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Annotations are written short, against the facade's own imports.
     */
    private function resolve(string $type): string
    {
        $doc = (string) file_get_contents((string) (new ReflectionClass(XenForo::class))->getFileName());

        preg_match_all('/^use ([^;]+);$/m', $doc, $matches);

        foreach ($matches[1] as $import) {
            if (str_ends_with($import, '\\' . $type)) {
                return $import;
            }
        }

        return $type;
    }
}
