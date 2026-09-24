<?php

/**
 * DokuWiki Plugin parserfunctions (Action Component)
 *
 * Captures nested functions {{#func: ... #}} before the Lexer and processes
 * them recursively.
 *
 * @author  ChatGPT -- Wed, 02 jul 2025 12:04:42 -0300
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_parserfunctions extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook(
            'PARSER_WIKITEXT_PREPROCESS',
            'BEFORE',
            $this,
            'handlePreprocess'
        );
    }

    public function handlePreprocess(Event $event)
    {
        $text = $event->data;
        $text = $this->processParserFunctions($text);

        $event->data = $text;
    }

    private function processParserFunctions($text)
    {
        // 1. Protects escaped blocks
        $protectedBlocks = [];
        /* The "s" modifier makes . catch line breaks.
         * The "i" modifier ignores case (if someone writes <CODE>).
         */
        $text = preg_replace_callback(
            '/%%.*?%%|<(nowiki|code|file|html)[^>]*>.*?<\/\1>/si',
            function ($matches) use (&$protectedBlocks) {
                $key = '@@ESC' . count($protectedBlocks) . '@@';
                $protectedBlocks[$key] = $matches[0];
                return $key;
            },
            $text
        );

        // 2. Processes functions normally
        $index = 0;
        while (($match = $this->extractBalancedFunction($text)) !== false) {
            $resolved = plugin_load('syntax', 'parserfunctions')->resolveFunction($match);
            $text = str_replace($match, $resolved, $text);
            $index++;
        }

        // 3. Restores protected blocks
        foreach ($protectedBlocks as $key => $original) {
            $text = str_replace($key, $original, $text);
        }

        return $text;
    }

    private function extractBalancedFunction($text)
    {
        $start = strpos($text, '{{#');
        if ($start === false) return false;

        $level = 0;
        $length = strlen($text);
        for ($i = $start; $i < $length - 2; $i++) {
            if (substr($text, $i, 3) === '{{#') {
                $level++;
                $i += 2;
            } elseif (substr($text, $i, 3) === '#}}') {
                $level--;
                $i += 2;

                if ($level === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return false; // Malformed
    }
}
