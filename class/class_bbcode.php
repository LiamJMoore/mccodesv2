<?php
declare(strict_types=1);

/**
 * BBCode wrapper class used in the forum
 */
class bbcode
{
    private static ?self $inst = null;
    public ?bbcode_engine $engine = null;

    public function __construct()
    {
        require __DIR__ . '/bbcode_engine.php';
        $this->engine = $this->getEngine();
        $this->engine->cust_tag('/</', '&lt;');
        $this->engine->cust_tag('/>/', '&gt;');
        $this->engine->cust_tag("/\r\n/", "\n");
        $this->engine->cust_tag("/\r/", "\n");
        $this->engine->cust_tag("/\n/", '&nbrlb;');
        $this->engine->simple_bbcode_tag('b');
        $this->engine->simple_bbcode_tag('i');
        $this->engine->simple_bbcode_tag('u');
        $this->engine->simple_bbcode_tag('s');
        $this->engine->simple_bbcode_tag('sub');
        $this->engine->simple_bbcode_tag('sup');
        $this->engine->simple_bbcode_tag('big');
        $this->engine->simple_bbcode_tag('small');
        $this->engine->cust_tag('/\[ul\](.+?)\[\/ul\]/is',
            "<table><tr><td align='left'><ul>\\1</ul></td></tr></table>");
        $this->engine->cust_tag('/\[ol\](.+?)\[\/ol\]/is',
            "<table><tr><td align='left'><ol>\\1</ol></td></tr></table>");
        $this->engine->cust_tag('/\[list\](.+?)\[\/list\]/is',
            "<table><tr><td align='left'><ul>\\1</ul></td></tr></table>");
        $this->engine->cust_tag('/\[olist\](.+?)\[\/olist\]/is',
            "<table><tr><td align='left'><ol>\\1</ol></td></tr></table>");
        $this->engine->adv_bbcode_tag('item', 'li');
        $this->engine->adv_option_tag('font', 'font', 'face');
        $this->engine->adv_option_tag('size', 'font', 'size');
        $this->engine->adv_option_tag('url', 'a', 'href');
        $this->engine->adv_option_tag('color', 'font', 'color');
        $this->engine->adv_option_tag('style', 'span', 'style');
        $this->engine->cust_tag('/\(c\)/', '&copy;');
        $this->engine->cust_tag('/\(tm\)/', '&#153;');
        $this->engine->cust_tag('/\(r\)/', '&reg;');
        $this->engine->adv_option_tag_em('email', 'a', 'href');
        $this->engine->adv_bbcode_att_em('email', 'a', 'href');
        $this->engine->cust_tag('/\[left\](.+?)\[\/left\]/i',
            "<div align='left'>\\1</div>");
        $this->engine->cust_tag('/\[center\](.+?)\[\/center\]/i',
            "<div align='center'>\\1</div>");
        $this->engine->cust_tag('/\[right\](.+?)\[\/right\]/i',
            "<div align='right'>\\1</div>");
        $this->engine->cust_tag('/\[quote=(.+?)\]/i',
            "<div class='quotetop'>QUOTE (\\1)</div><div class='quotemain'>");
        $this->engine->cust_tag('/\[quote\]/i',
            "<div class='quotetop'>QUOTE</div><div class='quotemain'>");
        $this->engine->cust_tag('/\[\/quote\]/i', '</div>');
        $this->engine->cust_tag('/\[code\](.+?)\[\/code\]/i',
            "<div class='codetop'>CODE</div><div class='codemain'><code>\\1</code></div>");
        $this->engine->cust_tag('/\[codebox\](.+?)\[\/codebox\]/i',
            "<div class='codetop'>CODE</div><div class='codemain' style='height:200px;white-space:pre;overflow:auto'>\\1</div>");
        $this->engine->cust_tag_with_callback('/\[img=(.+?)\]/i', 'check_image');
        $this->engine->cust_tag_with_callback('/\[img](.+?)\[\/img\]/i',
            'check_image');
        $this->engine->cust_tag('/&nbrlb;/', '<br />');
        $this->engine->cust_tag_with_callback('/\[userbox\]([0-9]+)\[\/userbox\]/i',
            'userBox');
        $this->engine->cust_tag('/\[hr\]/is', '<hr />');
        $this->engine->cust_tag('/\[\*\]/', '<li>');
    }

    private function getEngine(): ?bbcode_engine
    {
        if ($this->engine === null) {
            $this->engine = bbcode_engine::getInstance();
        }
        return $this->engine;
    }

    public static function getInstance(): ?self
    {
        if (self::$inst === null) {
            self::$inst = new self();
        }
        return self::$inst;
    }

    /**
     * @param $html
     * @return array|string|null
     */
    public function bbcode_parse($html): array|string|null
    {
        $html =
            str_ireplace(
                ['javascript:', 'document.', 'onClick',
                    'onDblClick', 'onLoad', 'onMouseOver',
                    'onBlur', 'onChange', 'onFocus', 'onkeydown',
                    'onkeypress', 'onkeyup', 'onmousedown',
                    'onmouseup', 'onmouseout', 'onmousemove',
                    'onresize', 'onscroll'], '', $html);
        $html =
            str_replace(['"', "'"], ['&quot;', '&#39;'], $html);
        return $this->make_safe((string)$this->engine->parse_bbcode($this->quote_corrector($html)));
    }

    /**
     * Engine v3. The tags above put what a player typed straight into href and style attributes, guarded only by a
     * list of words to remove, which is easy to get round: [url=jav&#x61;script:...] became a working script link,
     * and [style=...] accepted any CSS at all (a full-page overlay, a tracking image). This goes over the finished
     * HTML: a link must be a plain http or https address, and a style may only set a short list of harmless things.
     */
    private function make_safe(string $html): string
    {
        $html = (string)preg_replace_callback('/<a href=\'([^\']*)\'>/i', static function (array $m): string {
            $raw = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos(trim($raw), 'mailto:') === 0 && preg_match('/^mailto:[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$/', trim($raw))) {
                return "<a href='" . htmlspecialchars(trim($raw), ENT_QUOTES, 'ISO-8859-1') . "'>";
            }
            $url = function_exists('safe_url') ? safe_url(preg_match('~^[a-z][a-z0-9+.-]*:~i', trim($raw)) ? $m[1] : 'https://' . $m[1]) : '';
            return $url === '' ? '<a>' : "<a href='{$url}' rel='nofollow noopener noreferrer' target='_blank'>";
        }, $html);
        $html = (string)preg_replace_callback('/<span style=\'([^\']*)\'>/i', static function (array $m): string {
            $keep = [];
            foreach (explode(';', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $rule) {
                if (preg_match('/^\s*(color|background-color|font-weight|font-style|text-decoration|font-size|text-align)\s*:\s*([#A-Za-z0-9 .%-]{1,40})\s*$/', $rule, $r)
                    && !preg_match('/^font-size$/i', $r[1]) || (isset($r[1]) && strtolower($r[1]) === 'font-size' && preg_match('/^\s*(\d{1,2}(px|pt)|\d{2,3}%|small|medium|large|x-large)\s*$/', $r[2]))) {
                    $keep[] = strtolower($r[1]) . ':' . trim($r[2]);
                }
            }
            return $keep ? "<span style='" . implode(';', $keep) . "'>" : '<span>';
        }, $html);
        // [font], [size] and [color] make <font ...='value'>: letters, digits, spaces, # and commas are all those need
        return (string)preg_replace_callback('/<font (face|size|color)=\'([^\']*)\'>/i', static function (array $m): string {
            return preg_match('/^[#A-Za-z0-9 ,+-]{1,40}$/', $m[2]) ? $m[0] : '<font>';
        }, $html);
    }

    /**
     * @param $in
     * @return string
     */
    public function quote_corrector($in): string
    {
        $quotes       = substr_count($in, '[/quote]');
        $quote_starts = substr_count($in, '[quote');
        if ($quote_starts > $quotes) {
            return $in . str_repeat('[/quote]', $quote_starts - $quotes);
        } elseif ($quotes > $quote_starts) {
            $so   = 0;
            $poss = [];
            for ($i = 0; $i < $quotes; $i++) {
                $kx     = strpos($in, '[/quote]', $so);
                $so     = $kx;
                $poss[] = $kx;
            }
            while ($quotes > $quote_starts) {
                $num = $quotes - 1;
                $in  =
                    substr($in, 0, $poss[$num])
                    . ($poss[$num] + 8 >= strlen($in) ? ''
                        : substr($in, $poss[$num] + 8));
                $quotes--;
            }
            return $in;
        } else {
            return $in;
        }
    }
}
