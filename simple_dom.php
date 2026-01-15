<?php
// Lightweight DOM wrapper using DOMDocument + DOMXPath
// Provides minimal API compatible with simple_html_dom usage in our scrapers:
// - str_get_html($html) -> returns DOMWrapper
// - $dom->find($selector, $index=null) -> array of DOMElementWrapper or single element when index used
// - $el->find(...)
// - $el->plaintext, $el->href, $el->src, $el->outertext

class DOMElementWrapper {
    public $node;
    protected $xpath;
    public function __construct($node, $xpath) {
        $this->node = $node;
        $this->xpath = $xpath;
    }
    public function find($selector, $index = null) {
        $xpathQuery = DOMWrapper::selector_to_xpath($selector, true);
        $nodes = $this->xpath->query($xpathQuery, $this->node);
        $out = [];
        foreach ($nodes as $n) $out[] = new DOMElementWrapper($n, $this->xpath);
        if ($index === null) return $out;
        return $out[$index] ?? null;
    }
    public function __get($name) {
        // common attributes or properties
        if ($name === 'plaintext') return trim($this->node->textContent ?? '');
        if ($name === 'outertext') {
            return $this->node->ownerDocument->saveHTML($this->node);
        }
        if (method_exists($this->node, 'hasAttribute') && $this->node->hasAttribute($name)) return $this->node->getAttribute($name);
        // special attrs mapping
        if (in_array($name, ['href','src','id','class'])) {
            return $this->node->getAttribute($name) ?: null;
        }
        return null;
    }
}

class DOMWrapper {
    public $doc;
    protected $xpath;
    public function __construct($html) {
        $this->doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // enforce UTF-8
        $this->doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($this->doc);
    }
    public function find($selector, $index = null) {
        $xpathQuery = self::selector_to_xpath($selector, false);
        $nodes = $this->xpath->query($xpathQuery);
        $out = [];
        foreach ($nodes as $n) $out[] = new DOMElementWrapper($n, $this->xpath);
        if ($index === null) return $out;
        return $out[$index] ?? null;
    }
    public function __get($name) {
        if ($name === 'plaintext') return trim($this->doc->textContent ?? '');
        return null;
    }

    // very small CSS->XPath converter supporting: tag, .class, #id, descendant (space)
    public static function selector_to_xpath($selector, $relative = true) {
        $selector = trim($selector);
        if ($selector === '') return $relative ? './/*' : '//*';
        $parts = preg_split('/\s+/', $selector);
        $xpathParts = [];
        foreach ($parts as $part) {
            $seg = '*';
            // id
            if (strpos($part, '#') !== false) {
                list($tag, $id) = array_pad(explode('#', $part, 2), 2, null);
                $tag = $tag ?: '*';
                $xpathParts[] = sprintf('%s[@id="%s"]', $tag, $id);
                continue;
            }
            // class (tag.class or .class)
            if (strpos($part, '.') !== false) {
                list($tag, $class) = array_pad(explode('.', $part, 2), 2, null);
                $tag = $tag ?: '*';
                $xpathParts[] = sprintf('%s[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $tag, $class);
                continue;
            }
            // attribute selectors like [attr=value] or [attr*=value]
            if (preg_match('/^\[([a-zA-Z0-9_:-]+)(\*?=)([^\]]+)\]$/', $part, $ma)) {
                $attr = $ma[1];
                $op = $ma[2];
                $val = $ma[3];
                $val = trim($val, "'\" ");
                if ($op === '=') {
                    $xpathParts[] = sprintf('*[@%s="%s"]', $attr, $val);
                } else if ($op === '*=') {
                    $xpathParts[] = sprintf('*[contains(@%s, "%s")]', $attr, $val);
                } else {
                    $xpathParts[] = '*';
                }
                continue;
            }
            // attribute-like or unsupported token: fallback to tag name
            $xpathParts[] = $part;
        }
        $sep = $relative ? './/' : '//';
        $xpath = $sep . implode('//', $xpathParts);
        return $xpath;
    }
}

function str_get_html($html) {
    return new DOMWrapper($html);
}

?>
