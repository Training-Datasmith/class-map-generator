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
use RuntimeException;
/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Php_File_Parser
{
    /**
     * Extract the classes in the given file
     *
     * @param  string            $path The file to check
     * @throws RuntimeException
     * @return list<class-string> The found classes
     */
    public static function find_classes(string $path): array
    {
        $extra_types = self::get_extra_types();
        if (!function_exists('php_strip_whitespace')) {
            throw new RuntimeException('Classmap generation relies on the php_strip_whitespace function, but it has been disabled by the disable_functions directive.');
        }
        // Use @ here instead of Silencer to actively suppress 'unhelpful' output
        // @link https://github.com/composer/composer/pull/4886
        $contents = @php_strip_whitespace($path);
        if ('' === $contents) {
            if (!file_exists($path)) {
                $message = 'File at "%s" does not exist, check your classmap definitions';
            } elseif (!self::is_readable($path)) {
                $message = 'File at "%s" is not readable, check its permissions';
            } elseif ('' === trim((string) file_get_contents($path))) {
                // The input file was really empty and thus contains no classes
                return [];
            } else {
                $message = 'File at "%s" could not be parsed as PHP, it may be binary or corrupted';
            }
            $error = error_get_last();
            if (isset($error['message'])) {
                $message .= PHP_EOL . 'The following message may be helpful:' . PHP_EOL . $error['message'];
            }
            throw new RuntimeException(sprintf($message, $path));
        }
        // return early if there is no chance of matching anything in this file
        Preg::match_all_strict_groups('{\b(?:class|interface|trait' . $extra_types . ')\s}i', $contents, $matches);
        if ([] === $matches[0]) {
            return [];
        }
        $p = new Php_File_Cleaner($contents, count($matches[0]));
        $contents = $p->clean();
        unset($p);
        Preg::match_all('{
            (?:
                 \b(?<![\\\\$:>])(?P<type>class|interface|trait' . $extra_types . ') \s++ (?P<name>[a-zA-Z_\x7f-\xff:][a-zA-Z0-9_\x7f-\xff:\-]*+)
               | \b(?<![\\\\$:>])(?P<ns>namespace) (?P<nsname>\s++[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+(?:\s*+\\\\\\s*+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*+)*+)? \s*+ [\{;]
            )
        }ix', $contents, $matches);
        $classes = [];
        $namespace = '';
        for ($i = 0, $len = count($matches['type']); $i < $len; ++$i) {
            if (isset($matches['ns'][$i]) && $matches['ns'][$i] !== '') {
                $namespace = str_replace([' ', "\t", "\r", "\n"], '', (string) $matches['nsname'][$i]) . '\\';
            } else {
                $name = $matches['name'][$i];
                assert(is_string($name));
                // skip anon classes extending/implementing
                if ($name === 'extends') {
                    continue;
                }
                if ($name === 'implements') {
                    continue;
                }
                if ($name[0] === ':') {
                    // This is an XHP class, https://github.com/facebook/xhp
                    $name = 'xhp' . substr(str_replace(['-', ':'], ['_', '__'], $name), 1);
                } elseif (strtolower((string) $matches['type'][$i]) === 'enum') {
                    // something like:
                    //   enum Foo: int { HERP = '123'; }
                    // The regex above captures the colon, which isn't part of
                    // the class name.
                    // or:
                    //   enum Foo:int { HERP = '123'; }
                    // The regex above captures the colon and type, which isn't part of
                    // the class name.
                    $colon_pos = strrpos($name, ':');
                    if (false !== $colon_pos) {
                        $name = substr($name, 0, $colon_pos);
                    }
                }
                /** @var class-string */
                $class_name = ltrim($namespace . $name, '\\');
                $classes[] = $class_name;
            }
        }
        return $classes;
    }
    private static function get_extra_types(): string
    {
        static $extra_types = null;
        if (null === $extra_types) {
            $extra_types = '';
            $extra_types_array = [];
            if (PHP_VERSION_ID >= 80100 || defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.3', '>=')) {
                $extra_types .= '|enum';
                $extra_types_array = ['enum'];
            }
            Php_File_Cleaner::set_type_config(array_merge(['class', 'interface', 'trait'], $extra_types_array));
        }
        return $extra_types;
    }
    /**
     * Cross-platform safe version of is_readable()
     *
     * This will also check for readability by reading the file as is_readable can not be trusted on network-mounts
     * and \\wsl$ paths. See https://github.com/composer/composer/issues/8231 and https://bugs.php.net/bug.php?id=68926
     *
     * @see Composer\Util\Filesystem::isReadable
     *
     * @return bool
     */
    private static function is_readable(string $path)
    {
        if (is_readable($path)) {
            return true;
        }
        if (is_file($path)) {
            return false !== @file_get_contents($path, false, null, 0, 1);
        }
        // assume false otherwise
        return false;
    }
}