<?php

declare(strict_types=1);

namespace Demo\Tests;

use PHPUnit\Framework\TestCase;

/** The code rules of slimfony/core apply to the demo too, checked by a machine. */
final class ArchitectureTest extends TestCase
{
    /**
     * @return array<string, class-string> file => class name
     */
    private static function classes(): array
    {
        $root = (string) realpath(__DIR__ . '/../src');
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $name = 'Demo\\' . str_replace('/', '\\', str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1, -4)));
                self::assertTrue(class_exists($name) || interface_exists($name), $name . ' does not match its file name (PSR-4)');
                $classes[$file->getPathname()] = $name;
            }
        }
        self::assertNotEmpty($classes);

        return $classes;
    }

    public function testEveryFileDeclaresStrictTypes(): void
    {
        foreach (array_keys(self::classes()) as $file) {
            self::assertMatchesRegularExpression('/^<\?php\s+declare\(strict_types=1\);/', (string) file_get_contents($file), $file);
        }
    }

    public function testEveryClassIsFinalAndEveryImplementedMethodIsMarkedWithOverride(): void
    {
        foreach (self::classes() as $name) {
            $class = new \ReflectionClass($name);
            if ($class->isInterface()) {
                continue;
            }
            self::assertTrue($class->isFinal(), $name . ' must be final');
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || $method->isConstructor() || $method->isPrivate()) {
                    continue;
                }
                $inherited = [] !== array_filter($class->getInterfaces(), static fn(\ReflectionClass $i): bool => $i->hasMethod($method->getName()));
                if ($inherited) {
                    self::assertNotEmpty($method->getAttributes(\Override::class), \sprintf('%s::%s() needs #[\Override]', $name, $method->getName()));
                }
            }
        }
    }
}
