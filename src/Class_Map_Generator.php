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
/*
 * This file was initially based on a version from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 */
namespace Composer\Class_Map_Generator;

use Composer\Pcre\Preg;
use Symfony\Component\Finder\Finder;
/**
 * ClassMapGenerator
 *
 * @author Gyula Sallai <salla016@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Class_Map_Generator
{
    /**
     * @var list<string>
     */
    private $extensions;
    /**
     * @var FileList|null
     */
    private $scanned_files;
    /**
     * @var ClassMap
     */
    private $class_map;
    /**
     * @var non-empty-string
     */
    private $stream_wrappers_regex;
    /**
     * @param list<string> $extensions File extensions to scan for classes in the given paths
     */
    public function __construct(array $extensions = ['php', 'inc'])
    {
        $this->extensions = $extensions;
        $this->class_map = new Class_Map();
        $this->stream_wrappers_regex = sprintf('{^(?:%s)://}', implode('|', array_map('preg_quote', stream_get_wrappers())));
    }
    /**
     * Enables deduplication so that the same class file is never scanned twice across multiple
     * {@see self::scan_paths()} calls on overlapping directory trees.
     *
     * Pass your own {@see File_List} instance to share the seen-file registry across multiple
     * generator instances; omit the argument to use a new private registry.
     *
     * @param File_List|null $scanned_files optional shared file registry; a new one is created if null
     *
     * @return static fluent interface
     */
    public function avoid_duplicate_scans(?File_List $scanned_files = null): static
    {
        $this->scanned_files = $scanned_files ?? new File_List();
        return $this;
    }
    /**
     * Iterate over all files in the given directory searching for classes
     *
     * @param string|\Traversable<\SplFileInfo>|array<\SplFileInfo> $path The path to search in or an array/traversable of SplFileInfo (e.g. symfony/finder instance)
     * @return array<class-string, non-empty-string> A class map array
     *
     * @throws \RuntimeException When the path is neither an existing file nor directory
     */
    /** @param string|\Traversable<\SplFileInfo>|array<\SplFileInfo> $path */
    public static function create_map($path): array
    {
        $generator = new self();
        $generator->scan_paths($path);
        return $generator->get_class_map()->get_map();
    }
    /**
     * Returns the accumulated class map built by previous {@see self::scan_paths()} calls.
     *
     * @return Class_Map the class map containing all discovered class-to-file mappings
     */
    public function get_class_map(): Class_Map
    {
        return $this->class_map;
    }
    /**
     * Iterate over all files in the given directory searching for classes
     *
     * @param string|\Traversable<\SplFileInfo>|array<\SplFileInfo> $path         The path to search in or an array/traversable of SplFileInfo (e.g. symfony/finder instance)
     * @param non-empty-string|null                                 $excluded     Regex that matches file paths to be excluded from the classmap
     * @param 'classmap'|'psr-0'|'psr-4'                            $autoloadType Optional autoload standard to use mapping rules with the namespace instead of purely doing a classmap
     * @param string|null                                           $namespace    Optional namespace prefix to filter by, only for psr-0/psr-4 autoloading
     * @param array<string>                                         $excludedDirs Optional dirs to exclude from search relative to $path
     *
     * @throws \RuntimeException When the path is neither an existing file nor directory
     */
    /** @param string|\Traversable<\SplFileInfo>|array<\SplFileInfo> $path */
    public function scan_paths($path, ?string $excluded = null, string $autoload_type = 'classmap', ?string $namespace = null, array $excluded_dirs = []): void
    {
        if (!in_array($autoload_type, ['psr-0', 'psr-4', 'classmap'], true)) {
            throw new \InvalidArgumentException('$autoloadType must be one of: "psr-0", "psr-4" or "classmap"');
        }
        if ('classmap' !== $autoload_type) {
            if (!is_string($path)) {
                throw new \InvalidArgumentException('$path must be a string when specifying a psr-0 or psr-4 autoload type');
            }
            if (!is_string($namespace)) {
                throw new \InvalidArgumentException('$namespace must be given (even if it is an empty string if you do not want to filter) when specifying a psr-0 or psr-4 autoload type');
            }
            $base_path = $path;
        }
        if (is_string($path)) {
            if (is_file($path)) {
                $path = [new \Spl_File_Info($path)];
            } elseif (is_dir($path) || strpos($path, '*') !== false) {
                $path = Finder::create()->files()->follow_links()->name('/\.(?:' . implode('|', array_map('preg_quote', $this->extensions)) . ')$/')->in($path)->exclude($excluded_dirs);
            } else {
                throw new \RuntimeException('Could not scan for classes inside "' . $path . '" which does not appear to be a file nor a folder');
            }
        }
        $cwd = realpath(self::get_cwd());
        foreach ($path as $file) {
            $file_path = $file->get_pathname();
            if (!in_array(pathinfo($file_path, PATHINFO_EXTENSION), $this->extensions, true)) {
                continue;
            }
            $is_stream_wrapper_path = Preg::is_match($this->stream_wrappers_regex, $file_path);
            if (!self::is_absolute_path($file_path) && !$is_stream_wrapper_path) {
                $file_path = $cwd . '/' . $file_path;
                $file_path = self::normalize_path($file_path);
            } else {
                $file_path = Preg::replace('{(?<!:)[\\\\/]{2,}}', '/', $file_path);
            }
            if ('' === $file_path) {
                throw new \LogicException('Got an empty $filePath for ' . $file->get_pathname());
            }
            $real_path = $is_stream_wrapper_path ? $file_path : realpath($file_path);
            // fallback just in case but this really should not happen
            if (false === $real_path) {
                throw new \RuntimeException('realpath of ' . $file_path . ' failed to resolve, got false');
            }
            // if a list of scanned files is given, avoid scanning twice the same file to save cycles and avoid generating warnings
            // in case a PSR-0/4 declaration follows another more specific one, or a classmap declaration, which covered this file already
            if ($this->scanned_files !== null && $this->scanned_files->contains($real_path)) {
                continue;
            }
            // check the realpath of the file against the excluded paths as the path might be a symlink and the excluded path is realpath'd so symlink are resolved
            if (null !== $excluded && Preg::is_match($excluded, strtr($real_path, '\\', '/'))) {
                continue;
            }
            // check non-realpath of file for directories symlink in project dir
            if (null !== $excluded && Preg::is_match($excluded, strtr($file_path, '\\', '/'))) {
                continue;
            }
            $classes = Php_File_Parser::find_classes($file_path);
            if ('classmap' !== $autoload_type && isset($namespace)) {
                $classes = $this->filter_by_namespace($classes, $file_path, $namespace, $autoload_type, $base_path);
                // if no valid class was found in the file then we do not mark it as scanned as it might still be matched by another rule later
                if (\count($classes) > 0 && $this->scanned_files !== null) {
                    $this->scanned_files->add($real_path);
                }
            } elseif ($this->scanned_files !== null) {
                // classmap autoload rules always collect all classes so for these we definitely do not want to scan again
                $this->scanned_files->add($real_path);
            }
            foreach ($classes as $class) {
                if (!$this->class_map->has_class($class)) {
                    $this->class_map->add_class($class, $file_path);
                } elseif ($file_path !== $this->class_map->get_class_path($class)) {
                    $this->class_map->add_ambiguous_class($class, $file_path);
                }
            }
        }
    }
    /**
     * Remove classes which could not have been loaded by namespace autoloaders
     *
     * @param  array<int, class-string> $classes       found classes in given file
     * @param  string                   $filePath      current file
     * @param  string                   $baseNamespace prefix of given autoload mapping
     * @param  'psr-0'|'psr-4'          $namespaceType
     * @param  string                   $basePath      root directory of given autoload mapping
     * @return array<int, class-string> valid classes
     *
     * @throws \InvalidArgumentException When namespaceType is neither psr-0 nor psr-4
     */
    private function filter_by_namespace(array $classes, string $file_path, string $base_namespace, string $namespace_type, string $base_path): array
    {
        $valid_classes = [];
        $rejected_classes = [];
        $real_sub_path = substr($file_path, strlen($base_path) + 1);
        $dot_position = strrpos($real_sub_path, '.');
        $real_sub_path = substr($real_sub_path, 0, $dot_position === false ? PHP_INT_MAX : $dot_position);
        foreach ($classes as $class) {
            // transform class name to file path and validate
            if ('psr-0' === $namespace_type) {
                $namespace_length = strrpos($class, '\\');
                if (false !== $namespace_length) {
                    $namespace = substr($class, 0, $namespace_length + 1);
                    $class_name = substr($class, $namespace_length + 1);
                    $sub_path = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . str_replace('_', DIRECTORY_SEPARATOR, $class_name);
                } else {
                    $sub_path = str_replace('_', DIRECTORY_SEPARATOR, $class);
                }
            } elseif ('psr-4' === $namespace_type) {
                $sub_namespace = '' !== $base_namespace ? substr($class, strlen($base_namespace)) : $class;
                $sub_path = str_replace('\\', DIRECTORY_SEPARATOR, $sub_namespace);
            } else {
                throw new \InvalidArgumentException('$namespaceType must be "psr-0" or "psr-4"');
            }
            if ($sub_path === $real_sub_path) {
                $valid_classes[] = $class;
            } else {
                $rejected_classes[] = $class;
            }
        }
        // warn only if no valid classes, else silently skip invalid
        if (\count($valid_classes) === 0) {
            $cwd = realpath(self::get_cwd());
            if ($cwd === false) {
                $cwd = self::get_cwd();
            }
            $cwd = self::normalize_path($cwd);
            $short_path = Preg::replace('{^' . preg_quote($cwd) . '}', '.', self::normalize_path($file_path), 1);
            $short_base_path = Preg::replace('{^' . preg_quote($cwd) . '}', '.', self::normalize_path($base_path), 1);
            foreach ($rejected_classes as $class) {
                $this->class_map->add_psr_violation("Class {$class} located in {$short_path} does not comply with {$namespace_type} autoloading standard (rule: {$base_namespace} => {$short_base_path}). Skipping.", $class, $file_path);
            }
            return [];
        }
        return $valid_classes;
    }
    /**
     * Checks if the given path is absolute
     *
     * @see Composer\Util\Filesystem::isAbsolutePath
     */
    private static function is_absolute_path(string $path): bool
    {
        return strpos($path, '/') === 0 || substr($path, 1, 1) === ':' || strpos($path, '\\\\') === 0;
    }
    /**
     * Normalize a path. This replaces backslashes with slashes, removes ending
     * slash and collapses redundant separators and up-level references.
     *
     * @see Composer\Util\Filesystem::normalizePath
     *
     * @param  string $path Path to the file or directory
     */
    private static function normalize_path(string $path): string
    {
        $parts = [];
        $path = strtr($path, '\\', '/');
        $prefix = '';
        $absolute = '';
        // extract windows UNC paths e.g. \\foo\bar
        if (strpos($path, '//') === 0 && \strlen($path) > 2) {
            $absolute = '//';
            $path = substr($path, 2);
        }
        // extract a prefix being a protocol://, protocol:, protocol://drive: or simply drive:
        if (Preg::is_match_strict_groups('{^( [0-9a-z]{2,}+: (?: // (?: [a-z]: )? )? | [a-z]: )}ix', $path, $match)) {
            $prefix = $match[1];
            $path = substr($path, \strlen($prefix));
        }
        if (strpos($path, '/') === 0) {
            $absolute = '/';
            $path = substr($path, 1);
        }
        $up = false;
        foreach (explode('/', $path) as $chunk) {
            if ('..' === $chunk && (\strlen($absolute) > 0 || $up)) {
                array_pop($parts);
                $up = !(\count($parts) === 0 || '..' === end($parts));
            } elseif ('.' !== $chunk && '' !== $chunk) {
                $parts[] = $chunk;
                $up = '..' !== $chunk;
            }
        }
        // ensure c: is normalized to C:
        $prefix = Preg::replace_callback('{(?:^|://)[a-z]:$}i', function (array $m) {
            return strtoupper((string) $m[0]);
        }, $prefix);
        return $prefix . $absolute . implode('/', $parts);
    }
    /**
     * @see Composer\Util\Platform::getCwd
     */
    private static function get_cwd(): string
    {
        $cwd = getcwd();
        if (false === $cwd) {
            throw new \RuntimeException('Could not determine the current working directory');
        }
        return $cwd;
    }
}