<?php

namespace App\Modules\WaChat\Services\Rag;

class LanguageDetector
{
    private const ARABIC_RANGE  = '/[\x{0600}-\x{06FF}]/u';
    private const HINDI_RANGE   = '/[\x{0900}-\x{097F}]/u';
    private const CHINESE_RANGE = '/[\x{4E00}-\x{9FFF}]/u';
    private const LATIN_RANGE   = '/[a-zA-Z]/';

    public function detect(string $text): string
    {
        if (preg_match(self::ARABIC_RANGE, $text))  return 'ar';
        if (preg_match(self::HINDI_RANGE, $text))   return 'hi';
        if (preg_match(self::CHINESE_RANGE, $text)) return 'zh';
        if (preg_match(self::LATIN_RANGE, $text))   return 'en';
        return 'en';
    }

    public function isRtl(string $langCode): bool
    {
        return in_array($langCode, ['ar', 'he', 'fa', 'ur']);
    }
}
