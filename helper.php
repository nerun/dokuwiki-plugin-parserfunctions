<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

/**
 * DokuWiki Plugin parserfunctions (Helper Component)
 *
 * @license  GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author   Daniel "Nerun" Rodrigues <danieldiasr@gmail.com>
 * @created  Tue, 01 jul 2025 15:06:42 -0300
 */

use dokuwiki\Extension\Plugin;

if (!defined('DOKU_INC')) die();

class helper_plugin_parserfunctions extends Plugin
{
    /**
     * Processes raw function input into normalized parameters, handling pipe escapes
     *
     * Safely splits the input string by pipes (`|`) while respecting escaped pipes (`%%|%%`).
     * Performs whitespace trimming on all resulting parameters. This enables DokuWiki's
     * standard pipe syntax while supporting escaped pipes in parameter values.
     *
     * @param string $input The raw function input between delimiters (e.g. "a|b%%|%%c|d")
     *
     * @return array Normalized parameters with:
     *   - Escaped pipes restored (`%%TEMP_PIPE%%` → `%%|%%`)
     *   - Whitespace trimmed from both ends
     *   - Empty strings preserved as valid parameters
     *
     * @example Basic usage:
     *   parseParameters("a|b|c") → ["a", "b", "c"]
     *
     * @example With escaped pipe:
     *   parseParameters("a|b%%|%%c|d") → ["a", "b|c", "d"]
     *
     * @example With whitespace:
     *   parseParameters(" a |  b  ") → ["a", "b"]
     *
     * @example Empty parameters:
     *   parseParameters("a||b") → ["a", "", "b"]
     *
     * @note Preserves DokuWiki's standard %% escape syntax
     * @note Empty strings are valid parameters (unlike array_filter)
     * @note Trims only outer whitespace (inner spaces remain)
     */
    public function parseParameters($input)
    {
        // 1) Replace escaped pipes with temporary marker
        $input = str_replace('%%|%%', '%%TEMP_PIPE%%', $input);

        // 2) Split by unescaped pipes
        $params = explode('|', $input);

        // 3) Restore escaped pipes
        $params = array_map(fn($param) => str_replace('%%TEMP_PIPE%%', '%%|%%', $param), $params);

        // 4) Remove whitespace
        return array_map(trim(...), $params);
    }

    /**
     * Parses parameters for a SWITCH parser function and structures them for evaluation
     *
     * Processes the parameters into cases, test value, and default value, with support for:
     * - Explicit value cases (`case = value`)
     * - Fallthrough behavior (cases without values inherit the last defined value)
     * - Both explicit (`#default = value`) and implicit default values (last parameter)
     * - Whitespace normalization (trim) for all keys and values
     * - Escaped equals signs (`%%=%%`) in values
     *
     * @param array $params The raw parameters from the parser function call:
     *   - First element: The test value to compare against cases
     *   - Subsequent elements: Cases in format "case = value" or fallthrough/default markers
     *
     * @return array Structured data with:
     *   - 'cases': Associative array of [case => value] pairs
     *   - 'test': Normalized test value (with whitespace trimmed)
     *   - 'default': The default value (either explicit #default or last parameter)
     *
     * @example For input [" test ", "a=1", "b", "c=3", "#default=final"]
     *   Returns:
     *     [
     *       'cases' => ['a' => '1', 'b' => '3', 'c' => '3'],
     *       'test' => 'test',
     *       'default' => 'final'
     *     ]
     *
     * @example Fallthrough behavior:
     *   ["val", "a=1", "b", "c=2"] produces:
     *     [
     *       'cases' => ['a' => '1', 'b' => '1', 'c' => '2'],
     *       'test' => 'val',
     *       'default' => '2'
     *     ]
     *
     * @note Escaped equals signs (`%%=%%`) in values are preserved
     * @note All case keys and test values are trimmed of whitespace
     * @note Empty strings are valid as both test values and case values
     */
    public function parseSwitchCases($params)
    {
        $cases = [];
        $default = null;
        $testString = null;
        $lastValue = null;

        foreach ($params as $param) {
            $param = str_replace('%%=%%', '%%TEMP_EQUAL%%', $param);
            $parts = explode('=', $param, 2);
            $parts = array_map(trim(...), $parts);

            if (count($parts) === 2) {
                // Case with explicit value (case = value)
                $parts[1] = str_replace('%%TEMP_EQUAL%%', '%%=%%', $parts[1]);
                $cases[$parts[0]] = $parts[1];
                $lastValue = $parts[1];
            } else {
                // Case without explicit value (fallthrough or default)
                $parts[0] = str_replace('%%TEMP_EQUAL%%', '%%=%%', $parts[0]);

                if ($testString === null) {
                    $testString = trim($parts[0]); // First parameter is the test value
                } elseif (trim($parts[0]) === '#default') {
                    $default = $lastValue; // Explicit default
                } else {
                    $cases[trim($parts[0])] = $lastValue; // Fallthrough - uses last defined value
                }
            }
        }

        return [
            'cases' => $cases,
            'test' => $testString,
            'default' => $default ?? $lastValue // Implicit default is the last value
        ];
    }

    /**
     * Checks for the existence of a folder (namespace) or a file (media or page)
     *
     * Accepts:
     * - Absolute or relative filesystem paths
     * - DokuWiki page/media IDs (e.g. "wiki:start", "wiki:image.png")
     * - DokuWiki namespaces (must end with a colon, e.g. "wiki:")
     *
     * @param string $target The identifier or path to check
     * @return bool True if it exists (file, page, media, or namespace), false otherwise
     */
    public function checkExistence($target)
    {
        // Normalize spaces around ':', transform "wiki : help" → "wiki:help"
        $target = preg_replace('/\s*:\s*/', ':', $target);

        // If it is a real absolute or relative path, test as file or folder
        if (file_exists($target)) {
            return true;
        }

        // If path started with '/', try as relative to DOKU_INC by removing '/'
        if ((string) $target !== '' && $target[0] === '/') {
            $relativePath = ltrim($target, '/');
            $fullPath = DOKU_INC . $relativePath;
            if (file_exists($fullPath)) {
                return true;
            }
        }

        // Try as DokuWiki page
        if (page_exists($target)) {
            return true;
        }

        // Try as DokuWiki media
        if (file_exists(mediaFN($target))) {
            return true;
        }

        // Try as namespace (directory inside data/pages/)
        $namespacePath = str_replace(':', '/', $target);
        $namespaceDir = DOKU_INC . 'data/pages/' . $namespacePath;
        if (is_dir($namespaceDir)) {
            return true;
        }

        return false;
    }

    /**
     * Escape sequence handling (for backwards compatibility)
     *
     * To add more escapes, please refer to:
     * https://www.freeformatter.com/html-entities.html
     *
     * Before 2025-01-18, escape sequences had to use "&&num;NUMBER;" instead of
     * "&#;NUMBER;", because "#" was not escaped.
     *
     * After 2025-01-18, the "#" can be typed directly, and does not need to be
     * escaped. So use the normal spelling for HTML entity codes ("&#61;"
     * instead of "&&num;61;") when adding NEW escapes.
     *
     * Additionally, after 2025-01-18, '=', '|', '{' and '}' signs can be
     * escaped only by wrapping them in '%%', following the standard DokuWiki
     * syntax. So, the escapes below are DEPRECATED, but kept for backwards
     * compatibility.
     *
     */
    public function processEscapes($text)
    {
        // DEPRECATED, but kept for backwards compatibility:
        $escapes = [
            "&&num;61;"  => "=",
            "&&num;123;" => "%%{%%",
            "&&num;124;" => "|",
            "&&num;125;" => "%%}%%",
            "&num;"      => "#" // Always leave this as the last element!
        ];

        foreach ($escapes as $key => $value) {
            $text = str_replace($key, $value, $text);
        }

        return $text;
    }

    /**
     * Format error messages consistently
     */
    public function formatError($type, $function, $messageKey)
    {
        $wrapPluginExists = file_exists(DOKU_INC . 'lib/plugins/wrap');

        $errorMsg = '**' . $this->getLang('error') . ' ' . $function . ': '
                    . $this->getLang($messageKey) . '**';

        if ($wrapPluginExists) {
            return "<wrap $type>$errorMsg</wrap>";
        }

        return $errorMsg;
    }

    /**
     * Evaluates a mathematical expression consistently
     */
    public function evaluateMathExpression($expr)
    {
        $funcName = 'expr';
        $expr = trim($expr);

        // Rejects characters outside the permitted set
        if (
            !preg_match(
                '/^(
                            \s*|
                            \b(?:and|or|xor|not)\b|                # Reserved words first
                            ==|!=|<=|>=|<|>|                       # Comparisons
                            \+|\-|\*|\/|%|                         # Arithmetic
                            \(|\)|                                 # Parentheses
                            &&|\|\||!|                             # Symbolic logics
                            [0-9]+(\.[0-9]+)?([eE][\+\-]?[0-9]+)?  # Numbers with period and exponent
                        )+$/ix',
                $expr
            )
        ) {
            return $this->formatError('alert', $funcName, 'invalid_expression');
        }

        try {
            $expr = preg_replace('/\bnot\b/i', '!', $expr);
            // Simple evaluation
            $result = eval('return (' . $expr . ');');

            if (!is_numeric($result) || is_infinite($result) || is_nan($result)) {
                if (is_bool($result)) {
                    return $result ? 1 : 0;
                }
                return $this->formatError('alert', $funcName, 'undefined_result');
            }

            return $result;
        } catch (Throwable) {
            return $this->formatError('alert', $funcName, 'evaluation_error');
        }
    }
}
