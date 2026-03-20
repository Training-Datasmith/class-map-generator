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
 * @internal
 */
class Php_File_Cleaner
{
    /** @var array<array{name: string, length: int, pattern: non-empty-string}> */
    private static $type_config;
    /** @var non-empty-string */
    private static $rest_pattern;
    /**
     * @readonly
     * @var string
     */
    private $contents;
    /**
     * @readonly
     * @var int
     */
    private $len;
    /**
     * @readonly
     * @var int
     */
    private $max_matches;
    /** @var int */
    private $index = 0;
    /**
     * @param string[] $types
     */
    public static function set_type_config(array $types): void
    {
        foreach ($types as $type) {
            self::$type_config[$type[0]] = ['name' => $type, 'length' => \strlen($type), 'pattern' => '{.\b(?<![\$:>])' . $type . '\s++[a-zA-Z_\x7f-\xff:][a-zA-Z0-9_\x7f-\xff:\-]*+}Ais'];
        }
        self::$rest_pattern = '{[^?"\'</' . implode('', array_keys(self::$type_config)) . ']+}A';
    }
    public function __construct(string $contents, int $max_matches)
    {
        $this->contents = $contents;
        $this->len = \strlen($this->contents);
        $this->max_matches = $max_matches;
    }
    public function clean(): string
    {
        $clean = '';
        while ($this->index < $this->len) {
            $this->skip_to_php();
            $clean .= '<?';
            while ($this->index < $this->len) {
                $char = $this->contents[$this->index];
                if ($char === '?' && $this->peek('>')) {
                    $clean .= '?>';
                    $this->index += 2;
                    continue 2;
                }
                if ($char === '"') {
                    $this->skip_string('"');
                    $clean .= 'null';
                    continue;
                }
                if ($char === "'") {
                    $this->skip_string("'");
                    $clean .= 'null';
                    continue;
                }
                if ($char === '<' && $this->peek('<') && $this->match('{<<<[ \t]*+([\'"]?)([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*+)\1(?:\r\n|\n|\r)}A', $match)) {
                    $this->index += \strlen($match[0]);
                    $this->skip_heredoc($match[2]);
                    $clean .= 'null';
                    continue;
                }
                if ($char === '/') {
                    if ($this->peek('/')) {
                        $this->skip_to_newline();
                        continue;
                    }
                    if ($this->peek('*')) {
                        $this->skip_comment();
                        continue;
                    }
                }
                if ($this->max_matches === 1 && isset(self::$type_config[$char])) {
                    $type = self::$type_config[$char];
                    if (\substr($this->contents, $this->index, $type['length']) === $type['name'] && Preg::is_match($type['pattern'], $this->contents, $match, 0, $this->index - 1)) {
                        return $clean . $match[0];
                    }
                }
                $this->index += 1;
                if ($this->match(self::$rest_pattern, $match)) {
                    $clean .= $char . $match[0];
                    $this->index += \strlen($match[0]);
                } else {
                    $clean .= $char;
                }
            }
        }
        return $clean;
    }
    private function skip_to_php(): void
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '<' && $this->peek('?')) {
                $this->index += 2;
                break;
            }
            $this->index += 1;
        }
    }
    private function skip_string(string $delimiter): void
    {
        $this->index += 1;
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '\\' && ($this->peek('\\') || $this->peek($delimiter))) {
                $this->index += 2;
                continue;
            }
            if ($this->contents[$this->index] === $delimiter) {
                $this->index += 1;
                break;
            }
            $this->index += 1;
        }
    }
    private function skip_comment(): void
    {
        $this->index += 2;
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '*' && $this->peek('/')) {
                $this->index += 2;
                break;
            }
            $this->index += 1;
        }
    }
    private function skip_to_newline(): void
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === "\r" || $this->contents[$this->index] === "\n") {
                return;
            }
            $this->index += 1;
        }
    }
    private function skip_heredoc(string $delimiter): void
    {
        $first_delimiter_char = $delimiter[0];
        $delimiter_length = \strlen($delimiter);
        $delimiter_pattern = '{' . preg_quote($delimiter) . '(?![a-zA-Z0-9_\x80-\xff])}A';
        while ($this->index < $this->len) {
            // check if we find the delimiter after some spaces/tabs
            switch ($this->contents[$this->index]) {
                case "\t":
                case ' ':
                    $this->index += 1;
                    continue 2;
                case $first_delimiter_char:
                    if (\substr($this->contents, $this->index, $delimiter_length) === $delimiter && $this->match($delimiter_pattern)) {
                        $this->index += $delimiter_length;
                        return;
                    }
                    break;
            }
            // skip the rest of the line
            while ($this->index < $this->len) {
                $this->skip_to_newline();
                // skip newlines
                while ($this->index < $this->len && ($this->contents[$this->index] === "\r" || $this->contents[$this->index] === "\n")) {
                    $this->index += 1;
                }
                break;
            }
        }
    }
    private function peek(string $char): bool
    {
        return $this->index + 1 < $this->len && $this->contents[$this->index + 1] === $char;
    }
    /**
     * @param non-empty-string $regex
     * @param null|array<mixed> $match
     * @param-out array<int|string, string> $match
     */
    private function match(string $regex, ?array &$match = null): bool
    {
        return Preg::is_match_strict_groups($regex, $this->contents, $match, 0, $this->index);
    }
}