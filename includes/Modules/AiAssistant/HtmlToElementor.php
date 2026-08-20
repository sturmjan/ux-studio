<?php
/**
 * Deterministic HTML -> Elementor JSON structure converter.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/HtmlToElementor.php). Pure transformation logic: parses HTML
 * (plus inline styles / <style> tag CSS rules / Tailwind utility classes)
 * and builds the array of Elementor containers/widgets that callers persist
 * to the `_elementor_data` post meta. No WordPress-specific dependency
 * beyond wp_remote_get() (URL import), attachment_url_to_postid() (media
 * matching) and home_url()/wp_rand() helpers.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Mapping principle (see class report for detail):
 * - Headings (h1-h6)              -> widget "heading"
 * - img                           -> widget "image"
 * - hr                            -> widget "divider"
 * - video / YouTube-Vimeo iframe  -> widget "video"
 * - other iframe                  -> widget "html"
 * - Font Awesome i/span           -> widget "icon"
 * - standalone top-level <a>      -> widget "button"
 * - section/article/main/... /
 *   div with block children or
 *   flex/grid layout              -> elType "container" (recursion)
 * - p/ul/ol/blockquote/table/...  -> widget "text-editor" (kept as raw HTML)
 * - inline tags (span/strong/...) -> merged into the surrounding text buffer
 * - unknown elements               -> fallback widget "text-editor"
 * Every top-level result is wrapped in a full-width "container" element.
 * CSS (inline style + <style> tag rules with basic selector/specificity
 * matching + a Tailwind utility-class table) is optionally translated into
 * Elementor widget/container settings (colors, spacing, typography,
 * flex/grid, borders, shadows...).
 */
class HtmlToElementor
{
    /** Inline elementy, které se slučují do text-editor widgetu */
    private const INLINE_TAGS = [
        'span', 'strong', 'b', 'em', 'i', 'u', 'small', 'sub', 'sup',
        'mark', 'abbr', 'code', 'kbd', 'var', 'cite', 'q', 'dfn', 'time',
        'br', 'wbr', 'a', 's', 'del', 'ins',
    ];

    /** Strukturální elementy → kontejner */
    private const CONTAINER_TAGS = [
        'section', 'article', 'main', 'aside', 'header', 'footer', 'nav',
    ];

    /** Block elementy které se preservují jako text-editor */
    private const TEXT_BLOCK_TAGS = [
        'p', 'ul', 'ol', 'blockquote', 'pre', 'table', 'dl', 'figure', 'figcaption', 'address',
    ];

    /** CSS pravidla extrahovaná ze style tagů (selector => properties) */
    private static array $css_rules = [];

    /** CSS proměnné (:root / html) */
    private static array $css_vars = [];

    /**
     * Převede HTML na Elementor strukturu.
     *
     * @param string $html HTML obsah
     * @param array $options Volby: wrap_in_container (default true), use_css_rules (default false)
     * @return array Pole Elementor elementů
     */
    public static function convert(string $html, array $options = []): array
    {
        $html = trim($html);
        if (empty($html)) {
            return [];
        }

        $useCss = !empty($options['use_css_rules']);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return [];
        }

        $widgets = self::process_children($body, $doc, $useCss);

        if (empty($widgets)) {
            return [];
        }

        // Zabalit do root kontejneru
        $wrapInContainer = $options['wrap_in_container'] ?? true;
        if (!$wrapInContainer) {
            return $widgets;
        }

        // Každý top-level element zabalit do full-width kontejneru
        // Sekce/kontejnery dostanou content_width: full (pozadí na celou šířku)
        // Widgety se zabalí do vlastního full-width kontejneru
        $result = [];
        foreach ($widgets as $widget) {
            if (($widget['elType'] ?? '') === 'container') {
                // Kontejner - nastavit full width, pokud nemá explicitně boxed (z max-width)
                if (!isset($widget['settings']['content_width'])) {
                    $widget['settings']['content_width'] = 'full';
                }
                $result[] = $widget;
            } else {
                // Widget (heading, text-editor, image...) - zabalit do full-width kontejneru
                $result[] = [
                    'id' => self::generate_id(),
                    'elType' => 'container',
                    'settings' => ['content_width' => 'full'],
                    'elements' => [$widget],
                ];
            }
        }

        return $result;
    }

    /**
     * Zpracuje child nodes elementu a vrátí pole Elementor widgetů.
     */
    private static function process_children(\DOMNode $parent, \DOMDocument $doc, bool $useCss = false): array
    {
        $widgets = [];
        $textBuffer = '';

        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = $node->textContent;
                if (trim($text) !== '') {
                    $textBuffer .= htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                }
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var \DOMElement $node */
            $tag = strtolower($node->tagName);

            // Inline elementy → přidat do text bufferu
            if (in_array($tag, self::INLINE_TAGS) && !self::is_icon_element($node)) {
                $textBuffer .= self::get_outer_html($node, $doc);
                continue;
            }

            // Flush text buffer před block elementem
            if ($textBuffer !== '') {
                $widgets[] = self::create_text_editor('<p>' . $textBuffer . '</p>');
                $textBuffer = '';
            }

            // Computed styles pro aktuální element (CSS rules + Tailwind + inline styly)
            $computed = $useCss ? self::get_computed_styles($node) : [];

            // Headings h1-h6
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $widget = self::create_heading($node, $m[1], $computed);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Obrázky
            if ($tag === 'img') {
                $widget = self::create_image($node, $computed);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Oddělovač
            if ($tag === 'hr') {
                $widgets[] = self::create_divider($computed);
                continue;
            }

            // Video
            if ($tag === 'video') {
                $widget = self::create_video($node);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Iframe (YouTube/Vimeo → video, ostatní → html)
            if ($tag === 'iframe') {
                $widget = self::create_from_iframe($node, $doc);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Ikona (i/span s FA třídami)
            if (self::is_icon_element($node)) {
                $widget = self::create_icon($node);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Strukturální elementy → kontejner
            if (in_array($tag, self::CONTAINER_TAGS)) {
                $widget = self::create_container($node, $doc, $useCss, $computed);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Div - kontejner pokud má block children nebo je flex/grid layout
            if ($tag === 'div') {
                $isLayout = false;
                if (!empty($computed)) {
                    $disp = $computed['display'] ?? '';
                    $isLayout = in_array($disp, ['flex', 'inline-flex', 'grid']);
                }
                if (self::has_block_children($node) || $isLayout) {
                    $widget = self::create_container($node, $doc, $useCss, $computed);
                    if ($widget) {
                        $widgets[] = $widget;
                    }
                } else {
                    $html = self::get_inner_html($node, $doc);
                    if (trim(strip_tags($html)) !== '') {
                        $widget = self::create_text_editor($html, $computed);
                        $widgets[] = $widget;
                    }
                }
                continue;
            }

            // Standalone link → button (pokud jen text, ne celý odstavec)
            if ($tag === 'a' && !self::has_block_children($node) && $node->parentNode->tagName === 'body') {
                $widget = self::create_button($node, $computed);
                if ($widget) {
                    $widgets[] = $widget;
                }
                continue;
            }

            // Text block elementy (p, ul, ol, blockquote, table, pre...) → text-editor
            if (in_array($tag, self::TEXT_BLOCK_TAGS)) {
                $html = self::get_outer_html($node, $doc);
                if (trim(strip_tags($html)) !== '') {
                    $widgets[] = self::create_text_editor($html, $computed);
                }
                continue;
            }

            // Fallback: neznámý element → text-editor s celým HTML
            $html = self::get_outer_html($node, $doc);
            if (trim(strip_tags($html)) !== '') {
                $widgets[] = self::create_text_editor($html, $computed);
            }
        }

        // Flush zbylý text buffer
        if ($textBuffer !== '') {
            $widgets[] = self::create_text_editor('<p>' . $textBuffer . '</p>');
        }

        return $widgets;
    }

    // ==================== Widget Factory metody ====================

    private static function create_heading(\DOMElement $el, string $level, array $computed = []): array
    {
        $title = trim($el->textContent);
        if (empty($title)) {
            return [];
        }

        $settings = [
            'title' => $title,
            'header_size' => 'h' . $level,
        ];

        // Aplikovat computed CSS styles
        if (!empty($computed)) {
            $cssSettings = self::styles_to_elementor_settings($computed, 'heading');
            $settings = array_merge($settings, $cssSettings);
        } else {
            // Fallback - extrakce inline stylů
            $styles = self::extract_styles($el);
            if (!empty($styles['color'])) {
                $settings['title_color'] = $styles['color'];
            }
            if (!empty($styles['text-align'])) {
                $settings['align'] = $styles['text-align'];
            }
            if (!empty($styles['font-size'])) {
                $settings['typography_typography'] = 'custom';
                $settings['typography_font_size'] = ['size' => (int) $styles['font-size'], 'unit' => 'px'];
            }
            if (!empty($styles['font-weight'])) {
                $settings['typography_typography'] = 'custom';
                $settings['typography_font_weight'] = $styles['font-weight'];
            }
            if (!empty($styles['font-family'])) {
                $settings['typography_typography'] = 'custom';
                $settings['typography_font_family'] = $styles['font-family'];
            }
        }

        return self::make_widget('heading', $settings);
    }

    private static function create_text_editor(string $html, array $computed = []): array
    {
        $settings = ['editor' => $html];

        if (!empty($computed)) {
            $cssSettings = self::styles_to_elementor_settings($computed, 'text-editor');
            $settings = array_merge($settings, $cssSettings);
        }

        return self::make_widget('text-editor', $settings);
    }

    private static function create_image(\DOMElement $el, array $computed = []): array
    {
        $src = $el->getAttribute('src');
        if (empty($src)) {
            return [];
        }

        $settings = [
            'image' => ['url' => $src, 'id' => '', 'alt' => $el->getAttribute('alt') ?: ''],
        ];

        // Pokud je alt text, použít jako caption
        $alt = $el->getAttribute('alt');
        if (!empty($alt)) {
            $settings['caption'] = $alt;
        }

        // Zkusit najít obrázek v media library
        $attachmentId = self::find_media_by_url($src);
        if ($attachmentId) {
            $settings['image']['id'] = $attachmentId;
        }

        if (!empty($computed)) {
            $cssSettings = self::styles_to_elementor_settings($computed, 'image');
            $settings = array_merge($settings, $cssSettings);
        }

        return self::make_widget('image', $settings);
    }

    private static function create_divider(array $computed = []): array
    {
        $settings = ['style' => 'solid'];

        if (!empty($computed)) {
            $borderColor = $computed['border-color'] ?? $computed['color'] ?? null;
            if ($borderColor) {
                $normalized = self::normalize_color($borderColor);
                if ($normalized) {
                    $settings['color'] = $normalized;
                }
            }
        }

        return self::make_widget('divider', $settings);
    }

    private static function create_video(\DOMElement $el): array
    {
        $src = $el->getAttribute('src');
        if (empty($src)) {
            // Zkusit <source> child
            $source = $el->getElementsByTagName('source')->item(0);
            if ($source) {
                $src = $source->getAttribute('src');
            }
        }

        if (empty($src)) {
            return [];
        }

        return self::make_widget('video', [
            'video_type' => 'hosted',
            'hosted_url' => ['url' => $src],
        ]);
    }

    private static function create_from_iframe(\DOMElement $el, \DOMDocument $doc): array
    {
        $src = $el->getAttribute('src');
        if (empty($src)) {
            return [];
        }

        // YouTube
        if (preg_match('/(youtube\.com|youtu\.be)/', $src)) {
            // Převést embed URL na normální
            $videoUrl = preg_replace('#/embed/([^?]+).*#', '/watch?v=$1', $src);
            if (strpos($src, 'youtu.be') !== false) {
                $videoUrl = $src;
            }
            return self::make_widget('video', [
                'video_type' => 'youtube',
                'youtube_url' => $videoUrl,
            ]);
        }

        // Vimeo
        if (preg_match('/vimeo\.com/', $src)) {
            return self::make_widget('video', [
                'video_type' => 'vimeo',
                'vimeo_url' => $src,
            ]);
        }

        // Ostatní iframy → HTML widget
        $iframeHtml = self::get_outer_html($el, $doc);
        return self::make_widget('html', ['html' => $iframeHtml]);
    }

    private static function create_icon(\DOMElement $el): array
    {
        $classes = $el->getAttribute('class');
        $iconInfo = self::parse_icon_classes($classes);

        if (!$iconInfo) {
            return [];
        }

        return self::make_widget('icon', [
            'selected_icon' => [
                'value' => $iconInfo['value'],
                'library' => $iconInfo['library'],
            ],
            'view' => 'default',
        ]);
    }

    private static function create_button(\DOMElement $el, array $computed = []): array
    {
        $text = trim($el->textContent);
        $href = $el->getAttribute('href');

        if (empty($text) || empty($href)) {
            return [];
        }

        $settings = [
            'text' => $text,
            'link' => [
                'url' => $href,
                'is_external' => (strpos($href, 'http') === 0 && strpos($href, home_url()) !== 0) ? 'on' : '',
                'nofollow' => '',
            ],
        ];

        if (!empty($computed)) {
            $cssSettings = self::styles_to_elementor_settings($computed, 'button');
            // Mapovat specifické button styly
            $bgColor = $computed['background-color'] ?? $computed['background'] ?? null;
            if ($bgColor) {
                $normalized = self::normalize_color($bgColor);
                if ($normalized) {
                    $settings['button_background_color'] = $normalized;
                }
            }
            // Border radius pro button
            if (!empty($cssSettings['_border_radius'])) {
                $settings['border_radius'] = $cssSettings['_border_radius'];
                unset($cssSettings['_border_radius']);
            }
            // Typography a další
            foreach (['typography_typography', 'typography_font_size', 'typography_font_weight', 'typography_font_family'] as $key) {
                if (isset($cssSettings[$key])) {
                    $settings[$key] = $cssSettings[$key];
                }
            }
            if (!empty($cssSettings['button_text_color'])) {
                $settings['button_text_color'] = $cssSettings['button_text_color'];
            }
            if (!empty($cssSettings['padding'])) {
                $settings['text_padding'] = $cssSettings['padding'];
            }
        }

        return self::make_widget('button', $settings);
    }

    private static function create_container(\DOMElement $el, \DOMDocument $doc, bool $useCss = false, array $computed = []): array
    {
        $children = self::process_children($el, $doc, $useCss);
        if (empty($children)) {
            return [];
        }

        $settings = [];

        if (!empty($computed)) {
            $cssSettings = self::styles_to_elementor_settings($computed, 'container');
            $settings = array_merge($settings, $cssSettings);

            // Grid columns → nastavit šířku child elementů
            $gridCols = self::detect_grid_columns($computed);
            if ($gridCols > 0) {
                $childWidth = floor(100 / $gridCols);
                foreach ($children as &$child) {
                    if (!isset($child['settings'])) {
                        $child['settings'] = [];
                    }
                    if (is_array($child['settings'])) {
                        $child['settings']['_element_width'] = 'initial';
                        $child['settings']['_element_custom_width'] = ['size' => $childWidth, 'unit' => '%'];
                    }
                }
                unset($child);
            }
        } else {
            // Fallback - extrakce inline stylů
            $styles = self::extract_styles($el);
            if (!empty($styles['background-color'])) {
                $settings['background_color'] = $styles['background-color'];
                $settings['background_background'] = 'classic';
            }
            if (!empty($styles['text-align'])) {
                $settings['content_position'] = $styles['text-align'];
            }
        }

        return [
            'id' => self::generate_id(),
            'elType' => 'container',
            'settings' => !empty($settings) ? $settings : [],
            'elements' => $children,
        ];
    }

    /**
     * Detekuje počet grid sloupců z CSS grid-template-columns.
     */
    private static function detect_grid_columns(array $computed): int
    {
        $gridCols = $computed['grid-template-columns'] ?? '';
        if (empty($gridCols)) {
            return 0;
        }

        // repeat(N, 1fr) → N
        if (preg_match('/repeat\(\s*(\d+)/', $gridCols, $m)) {
            return (int) $m[1];
        }

        // Počet "1fr" výskytů
        $count = preg_match_all('/\d*fr/', $gridCols);
        if ($count > 1) {
            return $count;
        }

        return 0;
    }

    // ==================== CSS Parsing & Matching ====================

    /**
     * Stáhne externí CSS soubory z <link rel="stylesheet"> a vloží jako <style> tagy.
     */
    private static function fetch_external_css(\DOMDocument $doc, string $baseUrl): void
    {
        $parsedBase = parse_url($baseUrl);
        $baseOrigin = ($parsedBase['scheme'] ?? 'http') . '://' . ($parsedBase['host'] ?? '');
        $basePath = rtrim($parsedBase['path'] ?? '', '/');

        $links = $doc->getElementsByTagName('link');
        $cssUrls = [];

        for ($i = 0; $i < $links->length; $i++) {
            $link = $links->item($i);
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if ($rel !== 'stylesheet') {
                continue;
            }
            $href = $link->getAttribute('href');
            if (empty($href) || strpos($href, 'data:') === 0) {
                continue;
            }
            // Skip CDN frameworky (Bootstrap, Tailwind compiled, Font Awesome) - příliš velké
            if (preg_match('/(cdn\.jsdelivr|cdnjs\.cloudflare|unpkg\.com|fonts\.googleapis|use\.fontawesome)/i', $href)) {
                continue;
            }
            // Resolve relative URL
            if (strpos($href, '//') === 0) {
                $href = ($parsedBase['scheme'] ?? 'https') . ':' . $href;
            } elseif (strpos($href, 'http') !== 0) {
                $href = (strpos($href, '/') === 0)
                    ? $baseOrigin . $href
                    : $baseOrigin . $basePath . '/' . $href;
            }
            $cssUrls[] = $href;
        }

        // Max 5 externích CSS, abychom nezpomalovali import
        $cssUrls = array_slice($cssUrls, 0, 5);

        foreach ($cssUrls as $cssUrl) {
            $response = wp_remote_get($cssUrl, [
                'timeout' => 5,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }

            $cssContent = wp_remote_retrieve_body($response);
            if (empty($cssContent) || strlen($cssContent) > 500000) {
                continue; // Skip soubory > 500KB
            }

            // Resolve relative URL v CSS (url(...))
            $cssBasePath = dirname($cssUrl);
            $cssContent = preg_replace_callback('/url\(["\']?([^)"\']+)["\']?\)/', function ($m) use ($cssBasePath) {
                $src = $m[1];
                if (strpos($src, 'data:') === 0 || strpos($src, 'http') === 0 || strpos($src, '//') === 0) {
                    return $m[0];
                }
                if (strpos($src, '/') === 0) {
                    $parsed = parse_url($cssBasePath);
                    $origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
                    return "url('{$origin}{$src}')";
                }
                return "url('{$cssBasePath}/{$src}')";
            }, $cssContent);

            // Vložit jako <style> tag do dokumentu
            $styleEl = $doc->createElement('style', '');
            $styleEl->appendChild($doc->createTextNode($cssContent));
            $head = $doc->getElementsByTagName('head')->item(0);
            if ($head) {
                $head->appendChild($styleEl);
            } else {
                $body = $doc->getElementsByTagName('body')->item(0);
                if ($body) {
                    $body->insertBefore($styleEl, $body->firstChild);
                }
            }
        }
    }

    /**
     * Parsuje CSS pravidla ze všech <style> tagů v dokumentu.
     * Ukládá do self::$css_rules a self::$css_vars.
     */
    private static function parse_css_from_document(\DOMDocument $doc): void
    {
        self::$css_rules = [];
        self::$css_vars = [];

        $styleTags = $doc->getElementsByTagName('style');
        $allCss = '';

        for ($i = 0; $i < $styleTags->length; $i++) {
            $allCss .= $styleTags->item($i)->textContent . "\n";
        }

        if (empty($allCss)) {
            return;
        }

        // Odstranit komentáře
        $allCss = preg_replace('/\/\*.*?\*\//s', '', $allCss);

        // Extrahovat pravidla z @media min-width bloků (desktop styly pro mobile-first weby)
        // Skip max-width (mobilní breakpointy) - chceme jen desktop layout
        if (preg_match_all('/@media\s*\([^)]*min-width\s*:\s*(\d+)px[^)]*\)\s*\{((?:[^{}]*\{[^}]*\})*[^}]*)\}/s', $allCss, $mediaMatches, PREG_SET_ORDER)) {
            foreach ($mediaMatches as $mm) {
                $minWidth = (int) $mm[1];
                if ($minWidth >= 768) {
                    $allCss .= "\n" . $mm[2];
                }
            }
        }

        // Odstranit @media, @keyframes, @font-face a další @ bloky
        $allCss = preg_replace('/@(media|keyframes|font-face|supports|import|charset|layer)[^{]*\{(?:[^{}]*\{[^}]*\})*[^}]*\}/s', '', $allCss);

        // Extrahovat CSS proměnné z :root a html
        if (preg_match_all('/(?::root|html)\s*\{([^}]+)\}/s', $allCss, $rootMatches)) {
            foreach ($rootMatches[1] as $block) {
                self::parse_css_variables($block);
            }
        }

        // Parsovat pravidla: selector { properties }
        if (preg_match_all('/([^{@]+)\{([^}]+)\}/s', $allCss, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $selectorGroup = trim($match[1]);
                $properties = self::parse_css_properties($match[2]);

                if (empty($properties)) {
                    continue;
                }

                // Rozdělit grouped selektory (h1, h2 { ... })
                $selectors = explode(',', $selectorGroup);
                foreach ($selectors as $selector) {
                    $selector = trim($selector);
                    if (empty($selector) || $selector === ':root' || $selector === 'html') {
                        continue;
                    }
                    // Přeskočit pseudo-elementy a pseudo-selektory
                    if (strpos($selector, '::') !== false) {
                        continue;
                    }
                    // Odstranit pseudo-class (hover, focus, atd.)
                    $selector = preg_replace('/:(?:hover|focus|active|visited|first-child|last-child|nth-child\([^)]*\)|not\([^)]*\))/', '', $selector);
                    $selector = trim($selector);
                    if (empty($selector)) {
                        continue;
                    }

                    if (!isset(self::$css_rules[$selector])) {
                        self::$css_rules[$selector] = $properties;
                    } else {
                        self::$css_rules[$selector] = array_merge(self::$css_rules[$selector], $properties);
                    }
                }
            }
        }
    }

    /**
     * Parsuje CSS proměnné z bloku vlastností.
     */
    private static function parse_css_variables(string $block): void
    {
        $pairs = explode(';', $block);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair) || strpos($pair, '--') !== 0) {
                continue;
            }
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $val = trim($parts[1]);
                self::$css_vars[$name] = $val;
            }
        }
    }

    /**
     * Parsuje CSS property string na asociativní pole.
     */
    private static function parse_css_properties(string $block): array
    {
        $result = [];
        $pairs = explode(';', $block);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }
            $colonPos = strpos($pair, ':');
            if ($colonPos === false) {
                continue;
            }
            $prop = trim(strtolower(substr($pair, 0, $colonPos)));
            $val = trim(substr($pair, $colonPos + 1));

            // Odstranit !important
            $val = str_replace('!important', '', $val);
            $val = trim($val);

            // Skip CSS proměnné (definice)
            if (strpos($prop, '--') === 0) {
                continue;
            }

            // Resolnout CSS var() reference
            $val = self::resolve_css_var($val);

            if (!empty($val) && $val !== 'inherit' && $val !== 'initial' && $val !== 'unset') {
                $result[$prop] = $val;
            }
        }

        return $result;
    }

    /**
     * Resoluje var(--name, fallback) na skutečnou hodnotu.
     */
    private static function resolve_css_var(string $val): string
    {
        if (strpos($val, 'var(') === false) {
            return $val;
        }

        return preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\s*\)/', function ($m) {
            $varName = $m[1];
            $fallback = isset($m[2]) ? trim($m[2]) : '';

            if (isset(self::$css_vars[$varName])) {
                $resolved = self::$css_vars[$varName];
                // Rekurzivní resoluce (var uvnitř var)
                if (strpos($resolved, 'var(') !== false) {
                    return self::resolve_css_var($resolved);
                }
                return $resolved;
            }

            return $fallback ?: '';
        }, $val);
    }

    /**
     * Vrátí computed styles pro daný element - sloučení CSS pravidel + Tailwind + inline styles.
     * Specifičnost: tag(1) < .class(10) < Tailwind(15) < #id(100) < inline(1000)
     */
    private static function get_computed_styles(\DOMElement $el): array
    {
        $computed = [];

        // 1. CSS pravidla ze <style> tagů (specifičnost dle selektoru)
        foreach (self::$css_rules as $selector => $properties) {
            if (self::selector_matches_element($selector, $el)) {
                $specificity = self::get_specificity($selector);
                foreach ($properties as $prop => $val) {
                    if (!isset($computed[$prop]) || $specificity >= $computed[$prop]['specificity']) {
                        $computed[$prop] = ['value' => $val, 'specificity' => $specificity];
                    }
                }
            }
        }

        // 2. Tailwind utility classes (specifičnost 15 - nad class, pod ID)
        $tailwindStyles = self::parse_tailwind_classes($el);
        foreach ($tailwindStyles as $prop => $val) {
            if (!isset($computed[$prop]) || 15 >= $computed[$prop]['specificity']) {
                $computed[$prop] = ['value' => $val, 'specificity' => 15];
            }
        }

        // 3. Inline styly (nejvyšší specifičnost 1000)
        $inlineStyle = $el->getAttribute('style');
        if (!empty($inlineStyle)) {
            $inlineProps = self::parse_css_properties($inlineStyle);
            foreach ($inlineProps as $prop => $val) {
                $computed[$prop] = ['value' => $val, 'specificity' => 1000];
            }
        }

        // Vrátit jen hodnoty (bez specifičnosti)
        $result = [];
        foreach ($computed as $prop => $data) {
            $result[$prop] = $data['value'];
        }

        return $result;
    }

    /**
     * Vypočítá specifičnost CSS selektoru.
     */
    private static function get_specificity(string $selector): int
    {
        $ids = preg_match_all('/#/', $selector);
        $classes = preg_match_all('/\./', $selector);
        $attrs = preg_match_all('/\[/', $selector);
        // Počítat tagy (slova co nejsou za # nebo .)
        $tags = preg_match_all('/(?:^|[\s>+~])(\w+)/', $selector);

        return ($ids * 100) + (($classes + $attrs) * 10) + $tags;
    }

    /**
     * Testuje zda CSS selektor odpovídá danému DOM elementu.
     * Podporuje: tag, .class, #id, složené (tag.class#id), descendant (A B), child (A > B)
     */
    private static function selector_matches_element(string $selector, \DOMElement $el): bool
    {
        $selector = trim($selector);

        // Descendant selector: "div .foo" nebo child selector: "div > .foo"
        // Zpracovat zprava doleva
        if (preg_match('/\s/', $selector)) {
            // Rozdělit na části
            $parts = preg_split('/\s*>\s*|\s+/', $selector);
            $parts = array_values(array_filter($parts, fn($p) => trim($p) !== ''));

            if (empty($parts)) {
                return false;
            }

            // Poslední část musí matchovat aktuální element
            $lastPart = array_pop($parts);
            if (!self::simple_selector_matches($lastPart, $el)) {
                return false;
            }

            // Pokud jsou další části, musí matchovat některý ancestor
            if (!empty($parts)) {
                $isChild = strpos($selector, '>') !== false;
                $current = $el->parentNode;

                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    $found = false;
                    while ($current && $current instanceof \DOMElement) {
                        if (self::simple_selector_matches($parts[$i], $current)) {
                            $found = true;
                            $current = $current->parentNode;
                            break;
                        }
                        if ($isChild) {
                            break; // Child combinator - jen přímý rodič
                        }
                        $current = $current->parentNode;
                    }
                    if (!$found) {
                        return false;
                    }
                }
            }

            return true;
        }

        return self::simple_selector_matches($selector, $el);
    }

    /**
     * Testuje jednoduchý selektor (bez mezer) proti elementu.
     * Podporuje: tag, .class, #id, tag.class, .class1.class2, tag#id.class
     */
    private static function simple_selector_matches(string $selector, \DOMElement $el): bool
    {
        $selector = trim($selector);
        if (empty($selector) || $selector === '*') {
            return $selector === '*';
        }

        $tag = strtolower($el->tagName);
        $classes = array_filter(explode(' ', $el->getAttribute('class')));
        $id = $el->getAttribute('id');

        // Rozdělit selektor na části: tag, #id, .class
        $requiredTag = null;
        $requiredId = null;
        $requiredClasses = [];

        // Extrahovat tag (na začátku, před # nebo .)
        if (preg_match('/^(\w+)/', $selector, $m)) {
            $requiredTag = strtolower($m[1]);
        }
        // Extrahovat ID
        if (preg_match('/#([\w-]+)/', $selector, $m)) {
            $requiredId = $m[1];
        }
        // Extrahovat třídy
        if (preg_match_all('/\.([\w-]+)/', $selector, $m)) {
            $requiredClasses = $m[1];
        }

        // Attribut selektor [attr="val"]
        if (preg_match('/\[([\w-]+)(?:([~|^$*]?=)"?([^"\]]*)"?)?\]/', $selector, $m)) {
            $attrName = $m[1];
            $attrVal = $el->getAttribute($attrName);
            if (empty($m[2])) {
                // Jen existence atributu
                if (!$el->hasAttribute($attrName)) {
                    return false;
                }
            } else {
                $expected = $m[3] ?? '';
                switch ($m[2]) {
                    case '=':
                        if ($attrVal !== $expected) return false;
                        break;
                    case '~=':
                        if (!in_array($expected, explode(' ', $attrVal))) return false;
                        break;
                    case '*=':
                        if (strpos($attrVal, $expected) === false) return false;
                        break;
                    case '^=':
                        if (strpos($attrVal, $expected) !== 0) return false;
                        break;
                    case '$=':
                        if (substr($attrVal, -strlen($expected)) !== $expected) return false;
                        break;
                }
            }
        }

        // Ověřit tag
        if ($requiredTag && $requiredTag !== $tag) {
            return false;
        }
        // Ověřit ID
        if ($requiredId && $requiredId !== $id) {
            return false;
        }
        // Ověřit třídy
        foreach ($requiredClasses as $cls) {
            if (!in_array($cls, $classes)) {
                return false;
            }
        }

        // Musí být alespoň jeden požadavek
        return $requiredTag || $requiredId || !empty($requiredClasses);
    }

    // ==================== CSS → Elementor Mapping ====================

    /**
     * Převede computed CSS styles na Elementor settings.
     */
    private static function styles_to_elementor_settings(array $styles, string $widgetType = ''): array
    {
        $settings = [];

        // Background
        $bgColor = $styles['background-color'] ?? null;
        $bgImage = $styles['background-image'] ?? null;
        $bg = $styles['background'] ?? null;

        if ($bg && !$bgColor) {
            // Extrahovat barvu z background shorthand
            $color = self::normalize_color($bg);
            if ($color) {
                $bgColor = $color;
            }
            // Gradient
            if (preg_match('/linear-gradient\(([^)]+)\)/', $bg, $gm)) {
                $settings['background_background'] = 'gradient';
                $gradientParts = explode(',', $gm[1]);
                $colors = [];
                foreach ($gradientParts as $part) {
                    $color = self::normalize_color(trim($part));
                    if ($color) {
                        $colors[] = $color;
                    }
                }
                if (count($colors) >= 2) {
                    $settings['background_color'] = $colors[0];
                    $settings['background_color_b'] = $colors[1];
                }
            }
        }

        if ($bgColor && !isset($settings['background_background'])) {
            $normalized = self::normalize_color($bgColor);
            if ($normalized && $normalized !== '#ffffff' && $normalized !== '#fff') {
                $settings['background_color'] = $normalized;
                $settings['background_background'] = 'classic';
            }
        }

        if ($bgImage && preg_match('/url\(["\']?([^)"\']+)["\']?\)/', $bgImage, $m)) {
            $settings['background_image'] = ['url' => $m[1], 'id' => ''];
            $settings['background_background'] = 'classic';

            $bgSize = $styles['background-size'] ?? '';
            if ($bgSize === 'cover') {
                $settings['background_size'] = 'cover';
            } elseif ($bgSize === 'contain') {
                $settings['background_size'] = 'contain';
            }

            $bgPosition = $styles['background-position'] ?? '';
            if ($bgPosition) {
                $settings['background_position'] = str_replace(' ', ' ', $bgPosition);
            }
        }

        // Typography
        $fontSize = $styles['font-size'] ?? null;
        $fontWeight = $styles['font-weight'] ?? null;
        $fontFamily = $styles['font-family'] ?? null;
        $lineHeight = $styles['line-height'] ?? null;
        $letterSpacing = $styles['letter-spacing'] ?? null;
        $textTransform = $styles['text-transform'] ?? null;

        $hasTypography = $fontSize || $fontWeight || $fontFamily || $lineHeight || $letterSpacing || $textTransform;
        if ($hasTypography) {
            $settings['typography_typography'] = 'custom';
        }

        if ($fontSize) {
            $sizeVal = (float) preg_replace('/[^0-9.]/', '', $fontSize);
            $unit = 'px';
            if (strpos($fontSize, 'rem') !== false) {
                $sizeVal = $sizeVal * 16;
            } elseif (strpos($fontSize, 'em') !== false) {
                $sizeVal = $sizeVal * 16;
            } elseif (strpos($fontSize, 'vw') !== false) {
                $unit = 'vw';
            }
            $settings['typography_font_size'] = ['size' => (int) $sizeVal, 'unit' => $unit];
        }

        if ($fontWeight) {
            $settings['typography_font_weight'] = $fontWeight;
        }

        if ($fontFamily) {
            $settings['typography_font_family'] = trim($fontFamily, "'\" ");
            // Odstranit fallback fonty
            if (strpos($settings['typography_font_family'], ',') !== false) {
                $settings['typography_font_family'] = trim(explode(',', $settings['typography_font_family'])[0], "'\" ");
            }
        }

        if ($lineHeight) {
            $lhVal = (float) preg_replace('/[^0-9.]/', '', $lineHeight);
            $lhUnit = 'em';
            if (strpos($lineHeight, 'px') !== false) {
                $lhUnit = 'px';
            } elseif (strpos($lineHeight, '%') !== false) {
                $lhUnit = '%';
            }
            $settings['typography_line_height'] = ['size' => $lhVal, 'unit' => $lhUnit];
        }

        if ($letterSpacing) {
            $settings['typography_letter_spacing'] = [
                'size' => (float) preg_replace('/[^0-9.-]/', '', $letterSpacing),
                'unit' => 'px',
            ];
        }

        if ($textTransform) {
            $settings['typography_text_transform'] = $textTransform;
        }

        // Barvy
        $color = $styles['color'] ?? null;
        if ($color) {
            $normalizedColor = self::normalize_color($color);
            if ($normalizedColor) {
                // Mapovat na správný klíč podle typu widgetu
                if ($widgetType === 'heading') {
                    $settings['title_color'] = $normalizedColor;
                } elseif ($widgetType === 'button') {
                    $settings['button_text_color'] = $normalizedColor;
                } elseif ($widgetType === 'text-editor') {
                    $settings['text_color'] = $normalizedColor;
                } else {
                    $settings['color'] = $normalizedColor;
                }
            }
        }

        // Text align
        $textAlign = $styles['text-align'] ?? null;
        if ($textAlign && in_array($textAlign, ['left', 'center', 'right', 'justify'])) {
            $settings['align'] = $textAlign;
        }

        // Padding
        $padding = self::extract_spacing($styles, 'padding');
        if ($padding) {
            $settings['padding'] = $padding;
        }

        // Margin
        $margin = self::extract_spacing($styles, 'margin');
        if ($margin) {
            $settings['margin'] = $margin;
        }

        // Border
        $borderWidth = $styles['border-width'] ?? $styles['border'] ?? null;
        $borderColor = $styles['border-color'] ?? null;
        $borderStyle = $styles['border-style'] ?? null;

        if ($styles['border'] ?? null) {
            // Parsovat border shorthand: 1px solid #ccc
            if (preg_match('/(\d+)px\s+(solid|dashed|dotted|double|none)\s+(#[\w]+|rgba?\([^)]+\)|\w+)/', $styles['border'], $bm)) {
                $settings['border_border'] = $bm[2];
                $settings['border_width'] = [
                    'top' => (int) $bm[1], 'right' => (int) $bm[1],
                    'bottom' => (int) $bm[1], 'left' => (int) $bm[1],
                    'unit' => 'px', 'isLinked' => true,
                ];
                $borderColorNorm = self::normalize_color($bm[3]);
                if ($borderColorNorm) {
                    $settings['border_color'] = $borderColorNorm;
                }
            }
        } elseif ($borderStyle && $borderStyle !== 'none') {
            $settings['border_border'] = $borderStyle;
        }

        // Border radius
        $borderRadius = $styles['border-radius'] ?? null;
        if ($borderRadius) {
            $radius = (int) preg_replace('/[^0-9]/', '', $borderRadius);
            if ($radius > 0) {
                $settings['_border_radius'] = [
                    'top' => $radius, 'right' => $radius,
                    'bottom' => $radius, 'left' => $radius,
                    'unit' => 'px', 'isLinked' => true,
                ];
            }
        }

        // Box shadow
        $boxShadow = $styles['box-shadow'] ?? null;
        if ($boxShadow && $boxShadow !== 'none') {
            if (preg_match('/([\d.]+)px\s+([\d.]+)px\s+([\d.]+)px(?:\s+([\d.]+)px)?\s+(#[\w]+|rgba?\([^)]+\)|\w+)/', $boxShadow, $sm)) {
                $settings['box_shadow_box_shadow_type'] = 'yes';
                $settings['box_shadow_box_shadow'] = [
                    'horizontal' => (int) $sm[1],
                    'vertical' => (int) $sm[2],
                    'blur' => (int) $sm[3],
                    'spread' => isset($sm[4]) ? (int) $sm[4] : 0,
                    'color' => self::normalize_color($sm[5]) ?: 'rgba(0,0,0,0.2)',
                ];
            }
        }

        // Flexbox / Grid layout (pro kontejnery)
        $display = $styles['display'] ?? null;
        $flexDirection = $styles['flex-direction'] ?? null;
        $justifyContent = $styles['justify-content'] ?? null;
        $alignItems = $styles['align-items'] ?? null;
        $gap = $styles['gap'] ?? null;
        $flexWrap = $styles['flex-wrap'] ?? null;
        $gridCols = $styles['grid-template-columns'] ?? null;

        $isFlexOrGrid = ($display === 'flex' || $display === 'inline-flex' || $display === 'grid'
            || $flexDirection || $justifyContent || $alignItems || $gridCols);

        if ($isFlexOrGrid) {
            // Grid → mapovat na flex row (Elementor nemá nativní grid)
            if ($display === 'grid' || $gridCols) {
                $settings['flex_direction'] = 'row';
                $settings['flex_wrap'] = 'wrap';
            } elseif ($flexDirection) {
                $dirMap = ['row' => 'row', 'column' => 'column', 'row-reverse' => 'row', 'column-reverse' => 'column'];
                $settings['flex_direction'] = $dirMap[$flexDirection] ?? 'column';
            } elseif ($display === 'flex' || $display === 'inline-flex') {
                // CSS default pro flex je row, Elementor default je column → explicitně nastavit
                $settings['flex_direction'] = 'row';
            }

            if ($justifyContent) {
                $jcMap = [
                    'flex-start' => 'flex-start', 'start' => 'flex-start',
                    'flex-end' => 'flex-end', 'end' => 'flex-end',
                    'center' => 'center', 'space-between' => 'space-between',
                    'space-around' => 'space-around', 'space-evenly' => 'space-evenly',
                ];
                $settings['flex_justify_content'] = $jcMap[$justifyContent] ?? '';
            }
            if ($alignItems) {
                $aiMap = [
                    'flex-start' => 'flex-start', 'start' => 'flex-start',
                    'flex-end' => 'flex-end', 'end' => 'flex-end',
                    'center' => 'center', 'stretch' => 'stretch', 'baseline' => 'baseline',
                ];
                $settings['flex_align_items'] = $aiMap[$alignItems] ?? '';
            }
            if ($flexWrap === 'wrap' || $display === 'grid') {
                $settings['flex_wrap'] = 'wrap';
            }
        }

        if ($gap) {
            $gapVal = (int) preg_replace('/[^0-9]/', '', $gap);
            if ($gapVal > 0) {
                $settings['flex_gap'] = ['size' => $gapVal, 'unit' => 'px'];
            }
        }

        // Rozměry
        $width = $styles['width'] ?? null;
        $maxWidth = $styles['max-width'] ?? null;
        $minHeight = $styles['min-height'] ?? null;
        $height = $styles['height'] ?? null;

        if ($maxWidth) {
            $mwVal = (int) preg_replace('/[^0-9]/', '', $maxWidth);
            if ($mwVal > 0 && $mwVal < 2000) {
                $settings['content_width'] = 'boxed';
                $settings['boxed_width'] = ['size' => $mwVal, 'unit' => 'px'];
            }
        }

        if ($minHeight) {
            $mhVal = (int) preg_replace('/[^0-9]/', '', $minHeight);
            if ($mhVal > 0) {
                $settings['min_height'] = ['size' => $mhVal, 'unit' => 'px'];
            }
        }

        // Opacity
        $opacity = $styles['opacity'] ?? null;
        if ($opacity && $opacity !== '1') {
            $settings['_opacity'] = ['size' => (float) $opacity];
        }

        return $settings;
    }

    /**
     * Extrahuje padding/margin z CSS do Elementor formátu.
     */
    private static function extract_spacing(array $styles, string $type): ?array
    {
        $shorthand = $styles[$type] ?? null;
        $top = $styles["{$type}-top"] ?? null;
        $right = $styles["{$type}-right"] ?? null;
        $bottom = $styles["{$type}-bottom"] ?? null;
        $left = $styles["{$type}-left"] ?? null;

        if ($shorthand) {
            $parts = preg_split('/\s+/', $shorthand);
            $values = array_map(fn($v) => (int) preg_replace('/[^0-9-]/', '', $v), $parts);
            switch (count($values)) {
                case 1:
                    $top = $right = $bottom = $left = $values[0];
                    break;
                case 2:
                    $top = $bottom = $values[0];
                    $right = $left = $values[1];
                    break;
                case 3:
                    $top = $values[0];
                    $right = $left = $values[1];
                    $bottom = $values[2];
                    break;
                case 4:
                    $top = $values[0];
                    $right = $values[1];
                    $bottom = $values[2];
                    $left = $values[3];
                    break;
            }
        }

        if ($top !== null || $right !== null || $bottom !== null || $left !== null) {
            $t = $top !== null ? (int) preg_replace('/[^0-9-]/', '', (string) $top) : 0;
            $r = $right !== null ? (int) preg_replace('/[^0-9-]/', '', (string) $right) : 0;
            $b = $bottom !== null ? (int) preg_replace('/[^0-9-]/', '', (string) $bottom) : 0;
            $l = $left !== null ? (int) preg_replace('/[^0-9-]/', '', (string) $left) : 0;

            if ($t || $r || $b || $l) {
                return [
                    'top' => (string) $t, 'right' => (string) $r,
                    'bottom' => (string) $b, 'left' => (string) $l,
                    'unit' => 'px', 'isLinked' => ($t === $r && $r === $b && $b === $l),
                ];
            }
        }

        return null;
    }

    // ==================== Tailwind CSS Parsing ====================

    /**
     * Parsuje Tailwind utility classes z elementu a vrací CSS property/value páry.
     */
    private static function parse_tailwind_classes(\DOMElement $el): array
    {
        $classAttr = $el->getAttribute('class');
        if (empty($classAttr)) {
            return [];
        }

        $classes = preg_split('/\s+/', trim($classAttr));
        $styles = [];

        foreach ($classes as $class) {
            // Skip responsive/state prefixes (sm:, md:, hover:, focus:, dark:, etc.)
            if (strpos($class, ':') !== false) {
                continue;
            }
            // Skip negative classes
            if (strpos($class, '-') === 0 && !preg_match('/^-(m|p|top|right|bottom|left|translate|rotate|skew|scale)/', $class)) {
                continue;
            }
            self::parse_tailwind_class($class, $styles);
        }

        return $styles;
    }

    /**
     * Parsuje jednu Tailwind třídu a přidá do pole stylů.
     */
    private static function parse_tailwind_class(string $class, array &$s): void
    {
        // === Background ===
        if (preg_match('/^bg-([\w]+)-([\d]+)$/', $class, $m)) {
            $c = self::tailwind_color($m[1], $m[2]);
            if ($c) { $s['background-color'] = $c; }
            return;
        }
        if ($class === 'bg-white') { $s['background-color'] = '#ffffff'; return; }
        if ($class === 'bg-black') { $s['background-color'] = '#000000'; return; }
        if ($class === 'bg-transparent') { $s['background-color'] = 'transparent'; return; }
        // Gradient
        if ($class === 'bg-gradient-to-r') { $s['background'] = 'linear-gradient(to right, var(--tw-gradient-stops))'; return; }
        if ($class === 'bg-gradient-to-l') { $s['background'] = 'linear-gradient(to left, var(--tw-gradient-stops))'; return; }
        if ($class === 'bg-gradient-to-t') { $s['background'] = 'linear-gradient(to top, var(--tw-gradient-stops))'; return; }
        if ($class === 'bg-gradient-to-b') { $s['background'] = 'linear-gradient(to bottom, var(--tw-gradient-stops))'; return; }
        if ($class === 'bg-gradient-to-br') { $s['background'] = 'linear-gradient(to bottom right, var(--tw-gradient-stops))'; return; }

        // === Text colors ===
        if (preg_match('/^text-([\w]+)-([\d]+)$/', $class, $m)) {
            $c = self::tailwind_color($m[1], $m[2]);
            if ($c) { $s['color'] = $c; }
            return;
        }
        if ($class === 'text-white') { $s['color'] = '#ffffff'; return; }
        if ($class === 'text-black') { $s['color'] = '#000000'; return; }
        if ($class === 'text-transparent') { $s['color'] = 'transparent'; return; }

        // === Font sizes ===
        $fontSizes = [
            'text-xs' => '12', 'text-sm' => '14', 'text-base' => '16',
            'text-lg' => '18', 'text-xl' => '20', 'text-2xl' => '24',
            'text-3xl' => '30', 'text-4xl' => '36', 'text-5xl' => '48',
            'text-6xl' => '60', 'text-7xl' => '72', 'text-8xl' => '96', 'text-9xl' => '128',
        ];
        if (isset($fontSizes[$class])) { $s['font-size'] = $fontSizes[$class] . 'px'; return; }

        // === Font weight ===
        $fontWeights = [
            'font-thin' => '100', 'font-extralight' => '200', 'font-light' => '300',
            'font-normal' => '400', 'font-medium' => '500', 'font-semibold' => '600',
            'font-bold' => '700', 'font-extrabold' => '800', 'font-black' => '900',
        ];
        if (isset($fontWeights[$class])) { $s['font-weight'] = $fontWeights[$class]; return; }

        // === Text alignment ===
        if ($class === 'text-left') { $s['text-align'] = 'left'; return; }
        if ($class === 'text-center') { $s['text-align'] = 'center'; return; }
        if ($class === 'text-right') { $s['text-align'] = 'right'; return; }
        if ($class === 'text-justify') { $s['text-align'] = 'justify'; return; }

        // === Text transform ===
        if ($class === 'uppercase') { $s['text-transform'] = 'uppercase'; return; }
        if ($class === 'lowercase') { $s['text-transform'] = 'lowercase'; return; }
        if ($class === 'capitalize') { $s['text-transform'] = 'capitalize'; return; }
        if ($class === 'normal-case') { $s['text-transform'] = 'none'; return; }

        // === Display ===
        if ($class === 'flex') { $s['display'] = 'flex'; return; }
        if ($class === 'inline-flex') { $s['display'] = 'inline-flex'; return; }
        if ($class === 'grid') { $s['display'] = 'grid'; return; }
        if ($class === 'hidden') { $s['display'] = 'none'; return; }
        if ($class === 'block') { $s['display'] = 'block'; return; }
        if ($class === 'inline-block') { $s['display'] = 'inline-block'; return; }
        if ($class === 'inline') { $s['display'] = 'inline'; return; }

        // === Flex direction ===
        if ($class === 'flex-row') { $s['flex-direction'] = 'row'; return; }
        if ($class === 'flex-col') { $s['flex-direction'] = 'column'; return; }
        if ($class === 'flex-row-reverse') { $s['flex-direction'] = 'row-reverse'; return; }
        if ($class === 'flex-col-reverse') { $s['flex-direction'] = 'column-reverse'; return; }
        if ($class === 'flex-wrap') { $s['flex-wrap'] = 'wrap'; return; }
        if ($class === 'flex-nowrap') { $s['flex-wrap'] = 'nowrap'; return; }

        // === Justify content ===
        if ($class === 'justify-start') { $s['justify-content'] = 'flex-start'; return; }
        if ($class === 'justify-end') { $s['justify-content'] = 'flex-end'; return; }
        if ($class === 'justify-center') { $s['justify-content'] = 'center'; return; }
        if ($class === 'justify-between') { $s['justify-content'] = 'space-between'; return; }
        if ($class === 'justify-around') { $s['justify-content'] = 'space-around'; return; }
        if ($class === 'justify-evenly') { $s['justify-content'] = 'space-evenly'; return; }

        // === Align items ===
        if ($class === 'items-start') { $s['align-items'] = 'flex-start'; return; }
        if ($class === 'items-end') { $s['align-items'] = 'flex-end'; return; }
        if ($class === 'items-center') { $s['align-items'] = 'center'; return; }
        if ($class === 'items-stretch') { $s['align-items'] = 'stretch'; return; }
        if ($class === 'items-baseline') { $s['align-items'] = 'baseline'; return; }

        // === Gap ===
        if (preg_match('/^gap-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $s['gap'] = ((float) $m[1] * 4) . 'px'; return;
        }
        if (preg_match('/^gap-x-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $s['column-gap'] = ((float) $m[1] * 4) . 'px'; return;
        }
        if (preg_match('/^gap-y-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $s['row-gap'] = ((float) $m[1] * 4) . 'px'; return;
        }

        // === Padding ===
        if (preg_match('/^p-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['padding-top'] = $s['padding-right'] = $s['padding-bottom'] = $s['padding-left'] = $v; return;
        }
        if (preg_match('/^px-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['padding-left'] = $s['padding-right'] = $v; return;
        }
        if (preg_match('/^py-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['padding-top'] = $s['padding-bottom'] = $v; return;
        }
        if (preg_match('/^pt-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['padding-top'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^pr-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['padding-right'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^pb-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['padding-bottom'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^pl-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['padding-left'] = ((float) $m[1] * 4) . 'px'; return; }

        // === Margin ===
        if ($class === 'mx-auto') { $s['margin-left'] = 'auto'; $s['margin-right'] = 'auto'; return; }
        if (preg_match('/^m-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['margin-top'] = $s['margin-right'] = $s['margin-bottom'] = $s['margin-left'] = $v; return;
        }
        if (preg_match('/^mx-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['margin-left'] = $s['margin-right'] = $v; return;
        }
        if (preg_match('/^my-(\d+(?:\.\d+)?)$/', $class, $m)) {
            $v = ((float) $m[1] * 4) . 'px';
            $s['margin-top'] = $s['margin-bottom'] = $v; return;
        }
        if (preg_match('/^mt-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['margin-top'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^mr-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['margin-right'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^mb-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['margin-bottom'] = ((float) $m[1] * 4) . 'px'; return; }
        if (preg_match('/^ml-(\d+(?:\.\d+)?)$/', $class, $m)) { $s['margin-left'] = ((float) $m[1] * 4) . 'px'; return; }

        // === Border radius ===
        $radii = [
            'rounded-none' => '0', 'rounded-sm' => '2', 'rounded' => '4',
            'rounded-md' => '6', 'rounded-lg' => '8', 'rounded-xl' => '12',
            'rounded-2xl' => '16', 'rounded-3xl' => '24', 'rounded-full' => '9999',
        ];
        if (isset($radii[$class])) { $s['border-radius'] = $radii[$class] . 'px'; return; }

        // === Box shadow ===
        $shadows = [
            'shadow-sm' => '0 1px 2px 0 rgba(0,0,0,0.05)',
            'shadow' => '0 1px 3px 0 rgba(0,0,0,0.1)',
            'shadow-md' => '0 4px 6px -1px rgba(0,0,0,0.1)',
            'shadow-lg' => '0 10px 15px -3px rgba(0,0,0,0.1)',
            'shadow-xl' => '0 20px 25px -5px rgba(0,0,0,0.1)',
            'shadow-2xl' => '0 25px 50px -12px rgba(0,0,0,0.25)',
            'shadow-none' => 'none',
        ];
        if (isset($shadows[$class])) { $s['box-shadow'] = $shadows[$class]; return; }

        // === Width ===
        if ($class === 'w-full') { $s['width'] = '100%'; return; }
        if ($class === 'w-auto') { $s['width'] = 'auto'; return; }
        if ($class === 'w-screen') { $s['width'] = '100vw'; return; }
        if (preg_match('/^w-(\d+)$/', $class, $m) && (int) $m[1] <= 96) {
            $s['width'] = ((int) $m[1] * 4) . 'px'; return;
        }
        // Fractional widths
        if ($class === 'w-1/2') { $s['width'] = '50%'; return; }
        if ($class === 'w-1/3') { $s['width'] = '33.333%'; return; }
        if ($class === 'w-2/3') { $s['width'] = '66.667%'; return; }
        if ($class === 'w-1/4') { $s['width'] = '25%'; return; }
        if ($class === 'w-3/4') { $s['width'] = '75%'; return; }

        // === Max width ===
        $maxWidths = [
            'max-w-xs' => '320', 'max-w-sm' => '384', 'max-w-md' => '448',
            'max-w-lg' => '512', 'max-w-xl' => '576', 'max-w-2xl' => '672',
            'max-w-3xl' => '768', 'max-w-4xl' => '896', 'max-w-5xl' => '1024',
            'max-w-6xl' => '1152', 'max-w-7xl' => '1280', 'max-w-full' => '100%',
            'max-w-screen-sm' => '640', 'max-w-screen-md' => '768',
            'max-w-screen-lg' => '1024', 'max-w-screen-xl' => '1280',
            'max-w-screen-2xl' => '1536',
        ];
        if (isset($maxWidths[$class])) {
            $v = $maxWidths[$class];
            $s['max-width'] = is_numeric($v) ? $v . 'px' : $v;
            return;
        }

        // === Height ===
        if ($class === 'h-full') { $s['height'] = '100%'; return; }
        if ($class === 'h-screen') { $s['height'] = '100vh'; return; }
        if ($class === 'h-auto') { $s['height'] = 'auto'; return; }
        if (preg_match('/^h-(\d+)$/', $class, $m) && (int) $m[1] <= 96) {
            $s['height'] = ((int) $m[1] * 4) . 'px'; return;
        }
        if ($class === 'min-h-screen') { $s['min-height'] = '100vh'; return; }
        if (preg_match('/^min-h-(\d+)$/', $class, $m)) {
            $s['min-height'] = ((int) $m[1] * 4) . 'px'; return;
        }

        // === Border ===
        if ($class === 'border') { $s['border-width'] = '1px'; $s['border-style'] = 'solid'; $s['border-color'] = '#e5e7eb'; return; }
        if ($class === 'border-0') { $s['border-width'] = '0'; return; }
        if ($class === 'border-2') { $s['border-width'] = '2px'; $s['border-style'] = 'solid'; return; }
        if ($class === 'border-4') { $s['border-width'] = '4px'; $s['border-style'] = 'solid'; return; }
        if (preg_match('/^border-([\w]+)-([\d]+)$/', $class, $m)) {
            $c = self::tailwind_color($m[1], $m[2]);
            if ($c) { $s['border-color'] = $c; }
            return;
        }
        if ($class === 'border-white') { $s['border-color'] = '#ffffff'; return; }
        if ($class === 'border-black') { $s['border-color'] = '#000000'; return; }
        if ($class === 'border-transparent') { $s['border-color'] = 'transparent'; return; }
        // Border sides
        if ($class === 'border-t') { $s['border-top-width'] = '1px'; $s['border-style'] = 'solid'; return; }
        if ($class === 'border-b') { $s['border-bottom-width'] = '1px'; $s['border-style'] = 'solid'; return; }

        // === Opacity ===
        if (preg_match('/^opacity-(\d+)$/', $class, $m)) {
            $s['opacity'] = ((int) $m[1]) / 100; return;
        }

        // === Line height ===
        $lineHeights = [
            'leading-none' => '1', 'leading-tight' => '1.25', 'leading-snug' => '1.375',
            'leading-normal' => '1.5', 'leading-relaxed' => '1.625', 'leading-loose' => '2',
        ];
        if (isset($lineHeights[$class])) { $s['line-height'] = $lineHeights[$class]; return; }
        if (preg_match('/^leading-(\d+)$/', $class, $m)) {
            $s['line-height'] = ((int) $m[1] * 4) . 'px'; return;
        }

        // === Letter spacing ===
        $spacings = [
            'tracking-tighter' => '-0.05em', 'tracking-tight' => '-0.025em',
            'tracking-normal' => '0', 'tracking-wide' => '0.025em',
            'tracking-wider' => '0.05em', 'tracking-widest' => '0.1em',
        ];
        if (isset($spacings[$class])) { $s['letter-spacing'] = $spacings[$class]; return; }

        // === Grid ===
        if (preg_match('/^grid-cols-(\d+)$/', $class, $m)) {
            $s['display'] = 'grid';
            return;
        }

        // === Overflow ===
        if ($class === 'overflow-hidden') { $s['overflow'] = 'hidden'; return; }
        if ($class === 'overflow-auto') { $s['overflow'] = 'auto'; return; }

        // === Position ===
        if ($class === 'relative') { $s['position'] = 'relative'; return; }
        if ($class === 'absolute') { $s['position'] = 'absolute'; return; }
        if ($class === 'fixed') { $s['position'] = 'fixed'; return; }
        if ($class === 'sticky') { $s['position'] = 'sticky'; return; }

        // === Text decoration ===
        if ($class === 'underline') { $s['text-decoration'] = 'underline'; return; }
        if ($class === 'line-through') { $s['text-decoration'] = 'line-through'; return; }
        if ($class === 'no-underline') { $s['text-decoration'] = 'none'; return; }

        // === Object fit ===
        if ($class === 'object-cover') { $s['object-fit'] = 'cover'; return; }
        if ($class === 'object-contain') { $s['object-fit'] = 'contain'; return; }
        if ($class === 'object-fill') { $s['object-fit'] = 'fill'; return; }

        // === Whitespace ===
        if ($class === 'whitespace-nowrap') { $s['white-space'] = 'nowrap'; return; }
        if ($class === 'whitespace-normal') { $s['white-space'] = 'normal'; return; }
    }

    /**
     * Vrátí hex barvu pro Tailwind color name + shade.
     */
    private static function tailwind_color(string $name, string $shade): ?string
    {
        static $colors = null;
        if ($colors === null) {
            $colors = [
                'slate' => ['50' => '#f8fafc', '100' => '#f1f5f9', '200' => '#e2e8f0', '300' => '#cbd5e1', '400' => '#94a3b8', '500' => '#64748b', '600' => '#475569', '700' => '#334155', '800' => '#1e293b', '900' => '#0f172a', '950' => '#020617'],
                'gray' => ['50' => '#f9fafb', '100' => '#f3f4f6', '200' => '#e5e7eb', '300' => '#d1d5db', '400' => '#9ca3af', '500' => '#6b7280', '600' => '#4b5563', '700' => '#374151', '800' => '#1f2937', '900' => '#111827', '950' => '#030712'],
                'zinc' => ['50' => '#fafafa', '100' => '#f4f4f5', '200' => '#e4e4e7', '300' => '#d4d4d8', '400' => '#a1a1aa', '500' => '#71717a', '600' => '#52525b', '700' => '#3f3f46', '800' => '#27272a', '900' => '#18181b', '950' => '#09090b'],
                'neutral' => ['50' => '#fafafa', '100' => '#f5f5f5', '200' => '#e5e5e5', '300' => '#d4d4d4', '400' => '#a3a3a3', '500' => '#737373', '600' => '#525252', '700' => '#404040', '800' => '#262626', '900' => '#171717', '950' => '#0a0a0a'],
                'stone' => ['50' => '#fafaf9', '100' => '#f5f5f4', '200' => '#e7e5e4', '300' => '#d6d3d1', '400' => '#a8a29e', '500' => '#78716c', '600' => '#57534e', '700' => '#44403c', '800' => '#292524', '900' => '#1c1917', '950' => '#0c0a09'],
                'red' => ['50' => '#fef2f2', '100' => '#fee2e2', '200' => '#fecaca', '300' => '#fca5a5', '400' => '#f87171', '500' => '#ef4444', '600' => '#dc2626', '700' => '#b91c1c', '800' => '#991b1b', '900' => '#7f1d1d', '950' => '#450a0a'],
                'orange' => ['50' => '#fff7ed', '100' => '#ffedd5', '200' => '#fed7aa', '300' => '#fdba74', '400' => '#fb923c', '500' => '#f97316', '600' => '#ea580c', '700' => '#c2410c', '800' => '#9a3412', '900' => '#7c2d12', '950' => '#431407'],
                'amber' => ['50' => '#fffbeb', '100' => '#fef3c7', '200' => '#fde68a', '300' => '#fcd34d', '400' => '#fbbf24', '500' => '#f59e0b', '600' => '#d97706', '700' => '#b45309', '800' => '#92400e', '900' => '#78350f', '950' => '#451a03'],
                'yellow' => ['50' => '#fefce8', '100' => '#fef9c3', '200' => '#fef08a', '300' => '#fde047', '400' => '#facc15', '500' => '#eab308', '600' => '#ca8a04', '700' => '#a16207', '800' => '#854d0e', '900' => '#713f12', '950' => '#422006'],
                'lime' => ['50' => '#f7fee7', '100' => '#ecfccb', '200' => '#d9f99d', '300' => '#bef264', '400' => '#a3e635', '500' => '#84cc16', '600' => '#65a30d', '700' => '#4d7c0f', '800' => '#3f6212', '900' => '#365314', '950' => '#1a2e05'],
                'green' => ['50' => '#f0fdf4', '100' => '#dcfce7', '200' => '#bbf7d0', '300' => '#86efac', '400' => '#4ade80', '500' => '#22c55e', '600' => '#16a34a', '700' => '#15803d', '800' => '#166534', '900' => '#14532d', '950' => '#052e16'],
                'emerald' => ['50' => '#ecfdf5', '100' => '#d1fae5', '200' => '#a7f3d0', '300' => '#6ee7b7', '400' => '#34d399', '500' => '#10b981', '600' => '#059669', '700' => '#047857', '800' => '#065f46', '900' => '#064e3b', '950' => '#022c22'],
                'teal' => ['50' => '#f0fdfa', '100' => '#ccfbf1', '200' => '#99f6e4', '300' => '#5eead4', '400' => '#2dd4bf', '500' => '#14b8a6', '600' => '#0d9488', '700' => '#0f766e', '800' => '#115e59', '900' => '#134e4a', '950' => '#042f2e'],
                'cyan' => ['50' => '#ecfeff', '100' => '#cffafe', '200' => '#a5f3fc', '300' => '#67e8f9', '400' => '#22d3ee', '500' => '#06b6d4', '600' => '#0891b2', '700' => '#0e7490', '800' => '#155e75', '900' => '#164e63', '950' => '#083344'],
                'sky' => ['50' => '#f0f9ff', '100' => '#e0f2fe', '200' => '#bae6fd', '300' => '#7dd3fc', '400' => '#38bdf8', '500' => '#0ea5e9', '600' => '#0284c7', '700' => '#0369a1', '800' => '#075985', '900' => '#0c4a6e', '950' => '#082f49'],
                'blue' => ['50' => '#eff6ff', '100' => '#dbeafe', '200' => '#bfdbfe', '300' => '#93c5fd', '400' => '#60a5fa', '500' => '#3b82f6', '600' => '#2563eb', '700' => '#1d4ed8', '800' => '#1e40af', '900' => '#1e3a8a', '950' => '#172554'],
                'indigo' => ['50' => '#eef2ff', '100' => '#e0e7ff', '200' => '#c7d2fe', '300' => '#a5b4fc', '400' => '#818cf8', '500' => '#6366f1', '600' => '#4f46e5', '700' => '#4338ca', '800' => '#3730a3', '900' => '#312e81', '950' => '#1e1b4b'],
                'violet' => ['50' => '#f5f3ff', '100' => '#ede9fe', '200' => '#ddd6fe', '300' => '#c4b5fd', '400' => '#a78bfa', '500' => '#8b5cf6', '600' => '#7c3aed', '700' => '#6d28d9', '800' => '#5b21b6', '900' => '#4c1d95', '950' => '#2e1065'],
                'purple' => ['50' => '#faf5ff', '100' => '#f3e8ff', '200' => '#e9d5ff', '300' => '#d8b4fe', '400' => '#c084fc', '500' => '#a855f7', '600' => '#9333ea', '700' => '#7e22ce', '800' => '#6b21a8', '900' => '#581c87', '950' => '#3b0764'],
                'fuchsia' => ['50' => '#fdf4ff', '100' => '#fae8ff', '200' => '#f5d0fe', '300' => '#f0abfc', '400' => '#e879f9', '500' => '#d946ef', '600' => '#c026d3', '700' => '#a21caf', '800' => '#86198f', '900' => '#701a75', '950' => '#4a044e'],
                'pink' => ['50' => '#fdf2f8', '100' => '#fce7f3', '200' => '#fbcfe8', '300' => '#f9a8d4', '400' => '#f472b6', '500' => '#ec4899', '600' => '#db2777', '700' => '#be185d', '800' => '#9d174d', '900' => '#831843', '950' => '#500724'],
                'rose' => ['50' => '#fff1f2', '100' => '#ffe4e6', '200' => '#fecdd3', '300' => '#fda4af', '400' => '#fb7185', '500' => '#f43f5e', '600' => '#e11d48', '700' => '#be123c', '800' => '#9f1239', '900' => '#881337', '950' => '#4c0519'],
            ];
        }
        return $colors[$name][$shade] ?? null;
    }

    // ==================== Helper metody ====================

    /**
     * Vytvoří Elementor widget strukturu.
     */
    private static function make_widget(string $type, array $settings): array
    {
        return [
            'id' => self::generate_id(),
            'elType' => 'widget',
            'widgetType' => $type,
            'settings' => $settings,
            'elements' => [],
        ];
    }

    /**
     * Generuje Elementor-kompatibilní ID (nativní Elementor pattern).
     */
    private static function generate_id(): string
    {
        return dechex(rand(0x1000000, 0xFFFFFFF));
    }

    /**
     * Zjistí zda element je Font Awesome ikona.
     */
    private static function is_icon_element(\DOMElement $el): bool
    {
        $tag = strtolower($el->tagName);
        if ($tag !== 'i' && $tag !== 'span') {
            return false;
        }
        $classes = $el->getAttribute('class');
        // Font Awesome: fa, fas, far, fal, fab, fad
        return (bool) preg_match('/\b(fa[srlbd]?)\s+fa-[\w-]+/', $classes);
    }

    /**
     * Parsuje Font Awesome třídy na Elementor icon formát.
     */
    private static function parse_icon_classes(string $classes): ?array
    {
        // Font Awesome 5+: fas fa-home, far fa-envelope, fab fa-github
        if (preg_match('/\b(fa[srlbd]?)\s+(fa-[\w-]+)/', $classes, $m)) {
            $library = 'fa-solid';
            $prefixMap = [
                'fas' => 'fa-solid',
                'far' => 'fa-regular',
                'fab' => 'fa-brands',
                'fal' => 'fa-light',
                'fad' => 'fa-duotone',
                'fa' => 'fa-solid',
            ];
            $library = $prefixMap[$m[1]] ?? 'fa-solid';
            return [
                'value' => $m[1] . ' ' . $m[2],
                'library' => $library,
            ];
        }

        return null;
    }

    /**
     * Extrahuje základní inline styly z HTML elementu.
     */
    private static function extract_styles(\DOMElement $el): array
    {
        $style = $el->getAttribute('style');
        if (empty($style)) {
            return [];
        }

        $result = [];
        $pairs = explode(';', $style);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $prop = trim(strtolower($parts[0]));
            $val = trim($parts[1]);

            switch ($prop) {
                case 'color':
                    $result['color'] = self::normalize_color($val);
                    break;
                case 'background-color':
                case 'background':
                    $color = self::normalize_color($val);
                    if ($color) {
                        $result['background-color'] = $color;
                    }
                    break;
                case 'font-size':
                    $result['font-size'] = (int) preg_replace('/[^0-9]/', '', $val);
                    break;
                case 'font-weight':
                    $result['font-weight'] = $val;
                    break;
                case 'font-family':
                    $result['font-family'] = trim($val, "'\" ");
                    break;
                case 'text-align':
                    $result['text-align'] = $val;
                    break;
            }
        }

        return $result;
    }

    /**
     * Normalizuje CSS barvu na hex.
     */
    private static function normalize_color(string $val): ?string
    {
        $val = trim($val);
        // Už je hex
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
            return $val;
        }
        // rgb(r, g, b)
        if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $val, $m)) {
            return sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // Pojmenované barvy (základní)
        $named = [
            'red' => '#ff0000', 'blue' => '#0000ff', 'green' => '#008000',
            'black' => '#000000', 'white' => '#ffffff', 'gray' => '#808080',
            'grey' => '#808080', 'yellow' => '#ffff00', 'orange' => '#ffa500',
            'purple' => '#800080', 'pink' => '#ffc0cb', 'brown' => '#a52a2a',
        ];
        return $named[strtolower($val)] ?? null;
    }

    /**
     * Zjistí zda element má block-level children.
     */
    private static function has_block_children(\DOMNode $el): bool
    {
        $blockTags = array_merge(
            ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'ul', 'ol', 'table', 'hr', 'img', 'video', 'iframe', 'pre', 'blockquote', 'figure'],
            self::CONTAINER_TAGS
        );

        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->tagName), $blockTags)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vrátí outer HTML elementu.
     */
    private static function get_outer_html(\DOMElement $el, \DOMDocument $doc): string
    {
        return $doc->saveHTML($el) ?: '';
    }

    /**
     * Vrátí inner HTML elementu.
     */
    private static function get_inner_html(\DOMElement $el, \DOMDocument $doc): string
    {
        $html = '';
        foreach ($el->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }

    /**
     * Zkusí najít obrázek v media library podle URL.
     */
    private static function find_media_by_url(string $url): int
    {
        global $wpdb;

        // Absolutní URL
        $attachmentId = attachment_url_to_postid($url);
        if ($attachmentId) {
            return $attachmentId;
        }

        // Relativní URL - zkusit s site URL
        if (strpos($url, 'http') !== 0) {
            $fullUrl = home_url($url);
            $attachmentId = attachment_url_to_postid($fullUrl);
            if ($attachmentId) {
                return $attachmentId;
            }
        }

        return 0;
    }

    /**
     * Stáhne HTML z URL, extrahuje hlavní obsah a převede na Elementor strukturu.
     *
     * @param string $url URL stránky k importu
     * @param array $options Volby: wrap_in_container, selector (CSS selektor hlavního obsahu)
     * @return array ['elements' => array, 'title' => string, 'meta_description' => string]
     * @throws \RuntimeException Pokud se stažení nezdaří
     */
    public static function convert_from_url(string $url, array $options = []): array
    {
        // Stáhnout HTML
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Nepodařilo se stáhnout stránku: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            throw new \RuntimeException("Stránka vrátila HTTP {$statusCode}");
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            throw new \RuntimeException('Stránka je prázdná');
        }

        // Extrahovat obsah (parsuje CSS ze style tagů a uchovává v self::$css_rules)
        $extracted = self::extract_from_full_page($html, $url, $options);

        // Převést na Elementor (CSS pravidla jsou v self::$css_rules z extract_from_full_page)
        $elements = self::convert($extracted['content'], array_merge($options, [
            'use_css_rules' => true,
        ]));

        // Vyčistit static state
        self::$css_rules = [];
        self::$css_vars = [];

        return [
            'elements' => $elements,
            'title' => $extracted['title'],
            'meta_description' => $extracted['meta_description'],
        ];
    }

    /**
     * Extrahuje hlavní obsah z kompletní HTML stránky.
     * Odstraní navigaci, footer, skripty, styly a vrátí čistý obsah.
     */
    public static function extract_from_full_page(string $html, string $baseUrl = '', array $options = []): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Extrahovat titulek
        $title = '';
        $titleEl = $doc->getElementsByTagName('title')->item(0);
        if ($titleEl) {
            $title = trim($titleEl->textContent);
        }

        // Extrahovat meta description
        $metaDesc = '';
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $metaDesc = $meta->getAttribute('content');
                break;
            }
        }

        // Stáhnout externí CSS soubory a vložit jako <style> tagy
        if (!empty($baseUrl)) {
            self::fetch_external_css($doc, $baseUrl);
        }

        // Parsovat CSS z <style> tagů (včetně nově vložených externích) PŘED jejich odstraněním
        self::parse_css_from_document($doc);

        // Odstranit nepotřebné elementy
        $removeTags = ['script', 'style', 'link', 'noscript', 'svg', 'head'];
        foreach ($removeTags as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $nodes->length; $i++) {
                $toRemove[] = $nodes->item($i);
            }
            foreach ($toRemove as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        // Odstranit elementy podle role/class/id (navigace, footer, sidebar)
        $xpath = new \DOMXPath($doc);
        $removeQueries = [
            // Navigace
            '//nav',
            '//*[contains(@class, "navbar")]',
            '//*[contains(@class, "nav-")]',
            '//*[contains(@class, "menu")]',
            '//*[contains(@id, "menu")]',
            // Header (jen navigační, ne obsahový)
            '//header[.//nav]',
            // Footer
            '//footer',
            '//*[contains(@class, "footer")]',
            '//*[contains(@id, "footer")]',
            // Sidebar
            '//aside',
            '//*[contains(@class, "sidebar")]',
            // Cookie bannery, popupy
            '//*[contains(@class, "cookie")]',
            '//*[contains(@class, "popup")]',
            '//*[contains(@class, "modal")]',
            // Skryté elementy
            '//*[@style and contains(@style, "display:none")]',
            '//*[@style and contains(@style, "display: none")]',
            '//*[@hidden]',
            '//*[@aria-hidden="true"]',
        ];

        foreach ($removeQueries as $query) {
            try {
                $nodes = $xpath->query($query);
                if ($nodes) {
                    $toRemove = [];
                    foreach ($nodes as $node) {
                        $toRemove[] = $node;
                    }
                    foreach ($toRemove as $node) {
                        if ($node->parentNode) {
                            $node->parentNode->removeChild($node);
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Najít hlavní obsah - custom selektor nebo automatická detekce
        $contentNode = null;
        $customSelector = $options['selector'] ?? '';

        if (!empty($customSelector)) {
            // Jednoduchý CSS selektor → XPath (podporuje #id, .class, tag)
            $xpathQuery = self::css_to_xpath($customSelector);
            if ($xpathQuery) {
                $nodes = $xpath->query($xpathQuery);
                if ($nodes && $nodes->length > 0) {
                    $contentNode = $nodes->item(0);
                }
            }
        }

        if (!$contentNode) {
            // Automatická detekce hlavního obsahu
            $candidates = [
                '//main',
                '//*[@id="content"]',
                '//*[@id="main-content"]',
                '//*[@id="main"]',
                '//*[@id="page-content"]',
                '//*[@role="main"]',
                '//*[contains(@class, "main-content")]',
                '//*[contains(@class, "page-content")]',
                '//*[contains(@class, "content-area")]',
                '//*[contains(@class, "site-content")]',
                '//*[contains(@class, "entry-content")]',
                '//article',
            ];

            foreach ($candidates as $query) {
                try {
                    $nodes = $xpath->query($query);
                    if ($nodes && $nodes->length > 0) {
                        $contentNode = $nodes->item(0);
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        // Fallback - body
        if (!$contentNode) {
            $contentNode = $doc->getElementsByTagName('body')->item(0);
        }

        if (!$contentNode) {
            return ['content' => '', 'title' => $title, 'meta_description' => $metaDesc];
        }

        // Převést relativní URL na absolutní
        if (!empty($baseUrl)) {
            self::resolve_relative_urls($contentNode, $baseUrl, $doc);
        }

        // Extrahovat HTML obsah
        $content = '';
        foreach ($contentNode->childNodes as $child) {
            $content .= $doc->saveHTML($child);
        }

        // Vyčistit prázdné řádky a whitespace
        $content = preg_replace('/\n\s*\n/', "\n", $content);
        $content = trim($content);

        return [
            'content' => $content,
            'title' => $title,
            'meta_description' => $metaDesc,
        ];
    }

    /**
     * Převede relativní URL (src, href) na absolutní.
     */
    private static function resolve_relative_urls(\DOMNode $node, string $baseUrl, \DOMDocument $doc): void
    {
        $parsedBase = parse_url($baseUrl);
        $baseOrigin = ($parsedBase['scheme'] ?? 'http') . '://' . ($parsedBase['host'] ?? '');
        $basePath = rtrim($parsedBase['path'] ?? '', '/');

        // Atributy s URL
        $urlAttrs = ['src', 'href', 'poster', 'data-src', 'data-bg', 'srcset'];

        if ($node instanceof \DOMElement) {
            foreach ($urlAttrs as $attr) {
                $val = $node->getAttribute($attr);
                if (empty($val) || strpos($val, 'data:') === 0 || strpos($val, '#') === 0 || strpos($val, 'javascript:') === 0) {
                    continue;
                }
                if (strpos($val, 'http://') === 0 || strpos($val, 'https://') === 0 || strpos($val, '//') === 0) {
                    continue; // Už absolutní
                }

                if ($attr === 'srcset') {
                    // srcset: "image1.jpg 1x, image2.jpg 2x"
                    $resolved = preg_replace_callback('/([^\s,]+)(\s+[^,]*)?/', function ($m) use ($baseOrigin, $basePath) {
                        $src = $m[1];
                        if (strpos($src, 'http') === 0 || strpos($src, '//') === 0) {
                            return $m[0];
                        }
                        if (strpos($src, '/') === 0) {
                            $src = $baseOrigin . $src;
                        } else {
                            $src = $baseOrigin . $basePath . '/' . $src;
                        }
                        return $src . ($m[2] ?? '');
                    }, $val);
                    $node->setAttribute($attr, $resolved);
                } else {
                    if (strpos($val, '/') === 0) {
                        $node->setAttribute($attr, $baseOrigin . $val);
                    } else {
                        $node->setAttribute($attr, $baseOrigin . $basePath . '/' . $val);
                    }
                }
            }

            // Inline style background-image
            $style = $node->getAttribute('style');
            if (!empty($style) && strpos($style, 'url(') !== false) {
                $resolved = preg_replace_callback('/url\(["\']?([^)"\']+)["\']?\)/', function ($m) use ($baseOrigin, $basePath) {
                    $src = $m[1];
                    if (strpos($src, 'http') === 0 || strpos($src, 'data:') === 0 || strpos($src, '//') === 0) {
                        return $m[0];
                    }
                    if (strpos($src, '/') === 0) {
                        return "url('{$baseOrigin}{$src}')";
                    }
                    return "url('{$baseOrigin}{$basePath}/{$src}')";
                }, $style);
                $node->setAttribute('style', $resolved);
            }
        }

        // Rekurzivně pro child nodes
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                self::resolve_relative_urls($child, $baseUrl, $doc);
            }
        }
    }

    /**
     * Jednoduchý CSS selektor → XPath konverze.
     * Podporuje: #id, .class, tag, tag.class, tag#id
     */
    private static function css_to_xpath(string $selector): ?string
    {
        $selector = trim($selector);
        if (empty($selector)) {
            return null;
        }

        // #id
        if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
            return "//*[@id=\"{$m[1]}\"]";
        }
        // .class
        if (preg_match('/^\.([\w-]+)$/', $selector, $m)) {
            return "//*[contains(@class, \"{$m[1]}\")]";
        }
        // tag#id
        if (preg_match('/^(\w+)#([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[@id=\"{$m[2]}\"]";
        }
        // tag.class
        if (preg_match('/^(\w+)\.([\w-]+)$/', $selector, $m)) {
            return "//{$m[1]}[contains(@class, \"{$m[2]}\")]";
        }
        // tag
        if (preg_match('/^(\w+)$/', $selector, $m)) {
            return "//{$m[1]}";
        }

        return null;
    }

    /**
     * Spočítá widgety v Elementor struktuře (rekurzivně).
     */
    public static function count_widgets(array $elements): int
    {
        $count = 0;
        foreach ($elements as $el) {
            if (($el['elType'] ?? '') === 'widget') {
                $count++;
            }
            if (!empty($el['elements'])) {
                $count += self::count_widgets($el['elements']);
            }
        }
        return $count;
    }
}
