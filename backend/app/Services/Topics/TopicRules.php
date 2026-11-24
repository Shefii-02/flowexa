<?php

namespace App\Services\Topics;

/**
 * Keyword-based topic scoring — the free, instant first pass. Words and phrases are matched whole (so "bill" does not fire inside
 * "billion"), in English, in Malayalam and Hindi written in English letters ("price enthaa", "kaam nahi kar raha"), and in Malayalam /
 * Devanagari script. Each term carries a weight (2 = strong signal, 1 = weak). The newest customer message counts double.
 *
 * It answers "confident" only when one topic clearly leads; otherwise the caller may ask the AI, or keep the best guess flagged low-confidence.
 */
class TopicRules
{
    /** topic => [weight => [terms]] */
    private const TERMS = [
        'lead_enquiry' => [
            2 => ['price', 'prices', 'pricing', 'price list', 'cost', 'quote', 'quotation', 'rate', 'rates', 'charges', 'fees', 'fee', 'how much',
                  'demo', 'trial', 'brochure', 'catalog', 'catalogue', 'package', 'packages', 'offer', 'offers', 'discount', 'emi', 'loan',
                  'admission', 'course', 'enroll', 'enrol', 'site visit', 'buy', 'purchase', 'book now', 'enquiry', 'inquiry', 'interested',
                  'send details', 'share details', 'send me the details', 'send me details', 'the details', 'details pls', 'is this available', 'need details', 'do you have', 'is it available', 'availability', 'want to buy', 'looking for',
                  'vila', 'rate evide', 'ethra aanu', 'ethra aakum', 'ethra rupa', 'demo venam', 'details tharamo', 'details venam', 'kittumo', 'undo', 'venam',
                  'rate entha', 'price entha', 'cost entha', 'fees entha', 'fees ethra', 'fee ethra', 'etra aanu', 'ethra aan', 'ethra varum', 'rate parayamo', 'price parayamo', 'details ayakku', 'details ayachu', 'details onnu ayakku', 'details ayachu tharu', 'details tharumo', 'ariyikkamo', 'ariyan', 'join cheyyan', 'join cheyyanam', 'admission venam', 'admission undo', 'course undo', 'seat undo', 'class undo', 'batch undo', 'demo class', 'online undo', 'available aano', 'stock undo', 'kittumo', 'visit cheyyan', 'call cheyyamo', 'vilikkamo', 'enquiry aanu', 'ente aavasyam', 'enikku venam', 'enikku vendi', 'interested aanu', 'interest undu', 'vilikku', 'whatsapp il ayakku', 'location evide', 'evide aanu', 'address evide',
                  'kitna', 'kitne', 'chahiye', 'details bhejo', 'milega', 'kab milega', 'interested hu', 'price kya', 'rate kya',
                  'വില', 'എത്ര', 'ഡെമോ', 'വേണം', 'ലഭ്യമാണോ', 'ഉണ്ടോ', 'വിവരങ്ങൾ', 'कीमत', 'कितना', 'चाहिए', 'जानकारी', 'डेमो'],
            1 => ['details', 'plans', 'plan', 'information', 'info', 'tell me', 'more about', 'need', 'require', 'wanted', 'available', 'visit'],
        ],
        'technical' => [
            2 => ['not working', 'not work', 'not getting', 'so slow', 'very slow', 'too slow', 'crashing', 'crashes', 'how to install', 'how to use', 'how do i', 'doesnt work', "doesn't work", "isn't working", 'isnt working', 'stopped working', 'error', 'bug', 'crash', 'crashed',
                  'cant login', "can't login", 'cannot login', 'unable to login', 'login problem', 'login issue', 'not able to', 'unable to',
                  'otp not', 'not receiving otp', 'not receiving', 'not loading', 'not opening', 'not connecting', 'not sending', 'not showing', 'not responding',
                  'server down', 'is down', 'sync issue', 'qr code', 'session expired', 'disconnected', 'reset password', 'forgot password', 'password reset',
                  'webhook', 'api key', 'installation', 'how to setup', 'setup help', 'configure', 'stuck', 'hang', 'hangs', 'hanging', 'failed', 'failing',
                  'work cheyyunnilla', 'work aakunnilla', 'work avunnilla', 'open aakunnilla', 'login aakunnilla', 'varunnilla', 'kaanunnilla', 'pattunnilla', 'prashnam', 'error varunnu',
                  'work aavunnilla', 'login aavunnilla', 'open aavunnilla', 'load aavunnilla', 'connect aakunnilla', 'send aakunnilla', 'error kanikkunnu', 'otp varunnilla', 'otp vannilla', 'message povunnilla', 'qr scan', 'problem undu', 'prashnam undu', 'sariyakunnilla', 'sheriyakunnilla', 'engane use cheyyam', 'engane cheyyum', 'engane install', 'app open aakunnilla',
                  'kaam nahi kar raha', 'nahi chal raha', 'nahi khul raha', 'chal nahi raha', 'error aa raha', 'login nahi', 'otp nahi aa raha', 'dikkat',
                  'ശരിയാകുന്നില്ല', 'പ്രശ്നം', 'വർക്ക് ചെയ്യുന്നില്ല', 'തുറക്കുന്നില്ല', 'समस्या', 'काम नहीं कर रहा'],
            1 => ['issue', 'problem', 'slow', 'update', 'install', 'setup', 'login', 'otp', 'app', 'website', 'link', 'help'],
        ],
        'billing' => [
            2 => ['invoice', 'invoices', 'bill', 'billing', 'payment', 'paid', 'refund', 'receipt', 'gst', 'gst number', 'tax invoice', 'transaction', 'upi',
                  'bank details', 'account number', 'amount debited', 'double charged', 'charged twice', 'renewal', 'renew', 'subscription', 'outstanding',
                  'payment failed', 'paid already', 'pay cheythu', 'bill venam', 'refund venam', 'paisa', 'kaasu',
                  'paisa poyi', 'amount poyi', 'refund tharumo', 'refund kittiyilla', 'bill ayakku', 'invoice ayakku', 'invoice venam', 'payment aayilla', 'payment kittiyilla', 'paid aayi', 'gst bill', 'receipt venam', 'bill chahiye', 'paise', 'bhugtan', 'paisa kat gaya',
                  'ബിൽ', 'പേയ്മെന്റ്', 'പണം', 'रसीद', 'भुगतान', 'बिल'],
            1 => ['amount', 'due', 'pay', 'cheque', 'tax', 'charge'],
        ],
        'complaint' => [
            2 => ['complaint', 'complain', 'disappointed', 'worst', 'pathetic', 'very bad', 'bad service', 'poor service', 'unacceptable', 'fraud', 'cheated', 'scam',
                  'useless', 'no response', 'nobody replied', 'not happy', 'unhappy', 'angry', 'escalate', 'consumer court', 'legal action', 'disgusting', 'ridiculous',
                  'fed up', 'still waiting', 'since days', 'again and again', 'worst service', 'waste of money', 'poor quality',
                  'mosham', 'reply illa', 'response illa', 'thattippu', 'kashtam', 'pattichu',
                  'mosham service', 'reply illallo', 'ennum reply illa', 'aarum reply cheythilla', 'cheating aanu', 'kallam', 'pattikkal', 'paranju pattichu', 'complaint undu',
                  'shikayat', 'bakwas', 'dhokha', 'bekar', 'ghatiya', 'koi jawab nahi',
                  'പരാതി', 'മോശം', 'ദേഷ്യം', 'शिकायत', 'धोखा'],
            1 => ['delay', 'delayed', 'late', 'manager', 'bad', 'rude', 'sorry', 'wrong'],
        ],
        'existing_customer' => [
            2 => ['my order', 'when will my', 'my delivery', 'my parcel', 'my shipment', 'order status', 'order id', 'order number', 'my account', 'already bought', 'already purchased', 'i purchased', 'i bought', 'my booking',
                  'booking id', 'track my', 'tracking', 'delivery status', 'when will i get', 'my subscription', 'warranty', 'service request', 'my ticket', 'customer id',
                  'invoice number', 'as discussed', 'as per our', 'last time', 'earlier you', 'my previous', 'ente order', 'ente account', 'order status entha', 'order ethiyilla', 'order vannilla', 'ente booking', 'delivery enn varum', 'enn kittum', 'ente ticket',
                  'mera order', 'mera account', 'order kab aayega', 'मेरा ऑर्डर'],
            1 => ['ticket', 'delivery', 'delivered', 'received', 'shipment', 'dispatch'],
        ],
    ];

    /** Messages with nothing to classify: greetings, thanks, acknowledgements. */
    private const TRIVIAL = '/^(hi+|hello+|hey+|hai+|hlo+|helo|good (morning|afternoon|evening|night)|gm|namaste|namaskaram|vanakkam|ok(ay)?|k|thanks?( you)?|thank u|thx|tnx|yes|no|yep|nope|sure|fine|done|bye|👍|🙏|😊|ok thanks|hmm+|hii+|ji|haan|shari|sheri|ശരി|നന്ദി|धन्यवाद)$/u';

    /** @var array<string,string>|null topic => compiled alternation, built once */
    private static ?array $compiled = null;

    public static function topics(): array
    {
        return array_keys(\App\Models\ConversationTopic::TOPICS);
    }

    private static function normalize(string $text): string
    {
        return self::foldManglish(self::tidy($text));
    }

    /** Lower-cased, punctuation-free text with the spelling left as typed (the greeting list is matched against this). */
    private static function tidy(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = preg_replace("/[^\p{L}\p{M}\p{N}'’\s]+/u", ' ', $t) ?? $t;     // punctuation / emoji → space (apostrophes kept: "can't")
        $t = str_replace('’', "'", $t);
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Malayalam typed in English letters has no fixed spelling (ethra / ethara / etra, kittumo / kittumoo, aakunnilla / akunnilla). Both the message and
     * every keyword go through this same fold, so the common variants meet: doubled vowels and "th"/"dh"/"kk"-style doubles collapse to one letter.
     * Only Latin text is touched (Malayalam / Devanagari script is left alone).
     */
    private static function foldManglish(string $t): string
    {
        if (!preg_match('/[a-z]/', $t)) { return $t; }
        return preg_replace(['/(a|e|i|o|u)\1+/', '/th/', '/dh/', '/(k|t|p|d|n|m|l|r|s)\1+/'], ['$1', 't', 'd', '$1'], $t) ?? $t;
    }

    /** @return array<string,array<int,string>> topic => weight => compiled regex */
    private static function patterns(): array
    {
        if (self::$compiled !== null) { return self::$compiled; }
        $out = [];
        foreach (self::TERMS as $topic => $byWeight) {
            foreach ($byWeight as $weight => $terms) {
                usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));       // longest first so "not working" wins over "working"
                $alt = implode('|', array_map(fn ($t) => preg_quote(self::normalize($t), '/'), $terms));
                // whole words only: not preceded / followed by a letter, a combining mark or a digit
                $out[$topic][$weight] = '/(?<![\p{L}\p{M}\p{N}])(?:' . $alt . ')(?![\p{L}\p{M}\p{N}])/u';
            }
        }
        return self::$compiled = $out;
    }

    /** Score one text. @return array<string,int> topic => points */
    public static function score(string $text): array
    {
        $norm = self::normalize($text);
        $scores = array_fill_keys(array_diff(self::topics(), ['other']), 0);
        if ($norm === '') { return $scores; }
        foreach (self::patterns() as $topic => $byWeight) {
            foreach ($byWeight as $weight => $re) {
                $n = preg_match_all($re, $norm, $m);
                if ($n) { $scores[$topic] += min($n, 2) * $weight; }       // repeating a word does not run up the score
            }
        }
        return $scores;
    }

    /** Did the text contain at least one STRONG (weight-2) word of this topic? Weak words alone ("sorry", "late", "app") never decide a label. */
    private static function hasStrong(string $text, string $topic): bool
    {
        $re = self::patterns()[$topic][2] ?? null;
        return $re !== null && preg_match($re, self::normalize($text)) === 1;
    }

    public static function isTrivial(string $text): bool
    {
        $norm = self::tidy($text);
        return $norm === '' || (mb_strlen($norm) <= 14 && preg_match(self::TRIVIAL, $norm) === 1);
    }

    /**
     * @param string[] $customerTexts what the customer wrote, oldest → newest (the newest counts double)
     * @return array{topic:string, confidence:float, confident:bool, reason:string, scores:array<string,int>}
     */
    public static function classify(array $customerTexts): array
    {
        $texts = array_values(array_filter(array_map('trim', $customerTexts), fn ($t) => $t !== ''));
        if (!$texts || count(array_filter($texts, fn ($t) => !self::isTrivial($t))) === 0) {
            return ['topic' => 'other', 'confidence' => 0.6, 'confident' => true, 'reason' => 'Only greetings / acknowledgements so far', 'scores' => []];
        }

        $total = array_fill_keys(array_diff(self::topics(), ['other']), 0);
        $last = count($texts) - 1;
        foreach ($texts as $i => $text) {
            foreach (self::score($text) as $topic => $pts) { $total[$topic] += $pts * ($i === $last ? 2 : 1); }
        }
        arsort($total);
        [$best, $top] = [array_key_first($total), reset($total)];
        $second = array_values($total)[1] ?? 0;

        $strong = false;
        foreach ($texts as $t) { if (self::hasStrong($t, $best)) { $strong = true; break; } }

        if ($strong && $top >= 3 && $top - $second >= 3) {   // the newest message counts double, so a margin of 3 is a real lead, not one stray word
            return ['topic' => $best, 'confidence' => min(0.95, round(0.55 + 0.05 * $top, 2)), 'confident' => true,
                    'reason' => 'Matched ' . str_replace('_', ' ', $best) . ' wording', 'scores' => $total];
        }
        if ($strong && $top >= 2) {
            return ['topic' => $best, 'confidence' => 0.45, 'confident' => false, 'reason' => 'Weak / mixed signals — best guess ' . str_replace('_', ' ', $best), 'scores' => $total];
        }
        return ['topic' => 'other', 'confidence' => 0.3, 'confident' => false, 'reason' => 'No clear topic words', 'scores' => $total];
    }
}
