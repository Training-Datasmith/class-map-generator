<?php

declare (strict_types=1);
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Composer\Class_Map_Generator;

use Composer\Pcre\Preg;
/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Class_Map implements \Countable
{
    /**
     * @var array<class-string, non-empty-string>
     */
    public $map = [];
    /**
     * @var array<class-string, array<non-empty-string>>
     */
    private $ambiguous_classes = [];
    /**
     * @var array<string, array<array{warning: string, className: string}>>
     */
    private $psr_violations = [];
    /**
     * Returns the class map, which is a list of paths indexed by class name
     *
     * @return array<class-string, non-empty-string>
     */
    public function get_map(): array
    {
        return $this->map;
    }
    /**
     * Returns warning strings containing details about PSR-0/4 violations that were detected
     *
     * Violations are for ex a class which is in the wrong file/directory and thus should not be
     * found using psr-0/psr-4 autoloading but was found by the ClassMapGenerator as it scans all files.
     *
     * This is only happening when scanning paths using psr-0/psr-4 autoload type. Classmap type
     * always accepts every class as it finds it.
     *
     * @return string[]
     */
    public function get_psr_violations(): array
    {
        if (\count($this->psr_violations) === 0) {
            return [];
        }
        return array_map(static function (array $violation): string {
            return $violation['warning'];
        }, array_merge(...array_values($this->psr_violations)));
    }
    /**
     * A map of class names to their list of ambiguous paths
     *
     * This occurs when the same class can be found in several files
     *
     * To get the path the class is being mapped to, call getClassPath
     *
     * By default, paths that contain test(s), fixture(s), example(s) or stub(s) are ignored
     * as those are typically not problematic when they're dummy classes in the tests folder.
     * If you want to get these back as well you can pass false to $duplicatesFilter. Or
     * you can pass your own pattern to exclude if you need to change the default.
     *
     * @param non-empty-string|false $duplicatesFilter
     *
     * @return array<class-string, array<non-empty-string>>
     */
    public function get_ambiguous_classes($duplicates_filter = '{/(test|fixture|example|stub)s?/}i'): array
    {
        if (false === $duplicates_filter) {
            return $this->ambiguous_classes;
        }
        if (true === $duplicates_filter) {
            throw new \InvalidArgumentException('$duplicatesFilter should be false or a string with a valid regex, got true.');
        }
        $ambiguous_classes = [];
        foreach ($this->ambiguous_classes as $class => $paths) {
            $paths = array_filter($paths, function ($path) use ($duplicates_filter): bool {
                return !Preg::is_match($duplicates_filter, strtr($path, '\\', '/'));
            });
            if (\count($paths) > 0) {
                $ambiguous_classes[$class] = array_values($paths);
            }
        }
        return $ambiguous_classes;
    }
    /**
     * Sorts the class map alphabetically by class names
     */
    public function sort(): void
    {
        ksort($this->map);
    }
    /**
     * @param class-string $className
     * @param non-empty-string $path
     */
    public function add_class(string $class_name, string $path): void
    {
        unset($this->psr_violations[strtr($path, '\\', '/')]);
        $this->map[$class_name] = $path;
    }
    /**
     * @param class-string $className
     * @return non-empty-string
     */
    public function get_class_path(string $class_name): string
    {
        if (!isset($this->map[$class_name])) {
            throw new \OutOfBoundsException('Class ' . $class_name . ' is not present in the map');
        }
        return $this->map[$class_name];
    }
    /**
     * @param class-string $className
     */
    public function has_class(string $class_name): bool
    {
        return isset($this->map[$class_name]);
    }
    public function add_psr_violation(string $warning, string $class_name, string $path): void
    {
        $path = rtrim(strtr($path, '\\', '/'), '/');
        $this->psr_violations[$path][] = ['warning' => $warning, 'className' => $class_name];
    }
    public function clear_psr_violations_by_path(string $path_prefix): void
    {
        $path_prefix = rtrim(strtr($path_prefix, '\\', '/'), '/');
        foreach ($this->psr_violations as $path => $violations) {
            if ($path === $path_prefix || 0 === \strpos($path, $path_prefix . '/')) {
                unset($this->psr_violations[$path]);
            }
        }
    }
    /**
     * @param class-string $className
     * @param non-empty-string $path
     */
    public function add_ambiguous_class(string $class_name, string $path): void
    {
        $this->ambiguous_classes[$class_name][] = $path;
    }
    public function count(): int
    {
        return \count($this->map);
    }
    /**
     * Get the raw psr violations
     *
     * This is a map of filepath to an associative array of the warning string
     * and the offending class name.
     * @return array<string, array<array{warning: string, className: string}>>
     */
    public function get_raw_psr_violations(): array
    {
        return $this->psr_violations;
    }
}