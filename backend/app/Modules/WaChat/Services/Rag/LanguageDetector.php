<?php

namespace App\Modules\WaChat\Services\Rag;

class LanguageDetector
{
    private const ARABIC_RANGE     = '/[\x{0600}-\x{06FF}]/u';
    private const DEVANAGARI_RANGE = '/[\x{0900}-\x{097F}]/u'; // Hindi, Marathi
    private const BENGALI_RANGE    = '/[\x{0980}-\x{09FF}]/u';
    private const GURMUKHI_RANGE   = '/[\x{0A00}-\x{0A7F}]/u'; // Punjabi
    private const GUJARATI_RANGE   = '/[\x{0A80}-\x{0AFF}]/u';
    private const TAMIL_RANGE      = '/[\x{0B80}-\x{0BFF}]/u';
    private const TELUGU_RANGE     = '/[\x{0C00}-\x{0C7F}]/u';
    private const KANNADA_RANGE    = '/[\x{0C80}-\x{0CFF}]/u';
    private const MALAYALAM_RANGE  = '/[\x{0D00}-\x{0D7F}]/u';
    private const CHINESE_RANGE    = '/[\x{4E00}-\x{9FFF}]/u';
    private const LATIN_RANGE      = '/[a-zA-Z]/';

    /**
     * Manglish — Malayalam spoken/typed using English (Latin) letters instead of the
     * Malayalam script — is extremely common in real WhatsApp chat from Kerala, and it
     * can't be caught by a Unicode script range check the way Malayalam-script text can,
     * since it's written with the same a-z letters as English. This is a heuristic word-list
     * match instead: a real language model (fastText/langdetect-style) would do this properly,
     * but for short WhatsApp messages a curated keyword check is simple, dependency-free, and
     * catches the common case well enough to be worth having.
     *
     * DISTINCTIVE: words with no real chance of appearing in ordinary English text — a
     * single match is enough to call it Manglish.
     * COMMON: shorter/more ambiguous words that could theoretically collide with English or
     * other romanized text — two or more matches are required before trusting them alone.
     */
    private const MANGLISH_DISTINCTIVE = [
        'vilayariyanam', 'sherikkum', 'ningalude', 'ningalkku', 'sukhamano', 'cheyyumo',
        'sadhikumo', 'evideya', 'njangalude', 'parayamo', 'ariyilla', 'pattilla', 'entha',
        'ethra', 'engane', 'kittumo', 'vilanu',
    ];

    private const MANGLISH_COMMON = [
        'eniku', 'enik', 'enikku', 'ningal', 'njan', 'njangal', 'vendam', 'venam', 'vere',
        'onnu', 'onnum', 'ippo', 'ippol', 'undo', 'aano', 'aanu', 'alle', 'poyi', 'vannu',
        'cheyyam', 'cheyyum', 'nalla', 'pattumo', 'evide', 'vila',
    ];

    /**
     * Human-readable reply-language instructions, keyed by the code detect() returns.
     * A raw ISO-ish code like "ml-Latn" tells an LLM nothing on its own — this is what
     * actually goes into the system prompt so the model knows not just *which* language,
     * but *which script* to answer in (this matters specifically for Manglish, where the
     * language is Malayalam but the expected reply script is still Latin letters, not the
     * Malayalam script — get that wrong and a Manglish question gets an unreadable
     * native-script reply, or a reply in Malayalam script gets Latin-script gibberish back).
     */
    private const LABELS = [
        'en'     => 'English',
        'ar'     => 'Arabic',
        'hi'     => 'Hindi (Devanagari script)',
        'bn'     => 'Bengali',
        'pa'     => 'Punjabi (Gurmukhi script)',
        'gu'     => 'Gujarati',
        'ta'     => 'Tamil',
        'te'     => 'Telugu',
        'kn'     => 'Kannada',
        'ml'     => 'Malayalam (native Malayalam script)',
        'ml-Latn' => 'Manglish — Malayalam words spelled out using English/Latin letters, the way it is commonly typed in WhatsApp chat. Reply the same way: Malayalam phrasing in Latin letters, NOT the Malayalam script',
        'zh'     => 'Chinese',
    ];

    public function detect(string $text): string
    {
        if (preg_match(self::ARABIC_RANGE, $text))     return 'ar';
        if (preg_match(self::DEVANAGARI_RANGE, $text)) return 'hi';
        if (preg_match(self::BENGALI_RANGE, $text))    return 'bn';
        if (preg_match(self::GURMUKHI_RANGE, $text))   return 'pa';
        if (preg_match(self::GUJARATI_RANGE, $text))   return 'gu';
        if (preg_match(self::TAMIL_RANGE, $text))      return 'ta';
        if (preg_match(self::TELUGU_RANGE, $text))     return 'te';
        if (preg_match(self::KANNADA_RANGE, $text))    return 'kn';
        if (preg_match(self::MALAYALAM_RANGE, $text))  return 'ml';
        if (preg_match(self::CHINESE_RANGE, $text))    return 'zh';

        if (preg_match(self::LATIN_RANGE, $text)) {
            return $this->looksLikeManglish($text) ? 'ml-Latn' : 'en';
        }

        return 'en';
    }

    private function looksLikeManglish(string $text): bool
    {
        $lower = mb_strtolower($text);
        $hit   = fn (string $word) => (bool) preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lower);

        foreach (self::MANGLISH_DISTINCTIVE as $word) {
            if ($hit($word)) return true;
        }

        $commonHits = 0;
        foreach (self::MANGLISH_COMMON as $word) {
            if ($hit($word) && ++$commonHits >= 2) return true;
        }

        return false;
    }

    /** Human-readable instruction for the reply-language, for use in an LLM system prompt. */
    public function label(string $langCode): string
    {
        return self::LABELS[$langCode] ?? $langCode;
    }

    public function isRtl(string $langCode): bool
    {
        return in_array($langCode, ['ar', 'he', 'fa', 'ur']);
    }
}
