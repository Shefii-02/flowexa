<?php

namespace App\Modules\WaChat\Support;

/**
 * The "do the variables match the template?" check for the public API. A template like "Hi {{name}}, your order {{order_id}} …" needs both
 * name and order_id; the tokens the system fills itself (otp, expiry, company, date …) are never asked of the caller.
 */
class ApiTemplateVariables
{
    /** Tokens the platform fills in — the caller never has to send these. */
    public const AUTO = ['otp', 'code', 'expiry', 'company', 'company_name', 'business name', 'time', 'date'];

    /** Every {{token}} in a template, de-duplicated, in first-seen order. */
    public static function tokens(string $template): array
    {
        preg_match_all('/\{\{\s*(.+?)\s*\}\}/', $template, $m);
        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /** What the caller must send for this template. */
    public static function expected(string $template): array
    {
        return array_values(array_filter(self::tokens($template), fn ($t) => !in_array(strtolower($t), self::AUTO, true)));
    }

    /** Longest value accepted for one variable — a WhatsApp message body is limited anyway, and this stops a runaway value. */
    public const MAX_LENGTH = 1000;

    /**
     * @param array<string,mixed> $provided  the request's `variables`
     * @return array{ok:bool, expected:string[], missing:string[], invalid:array<string,string>, ignored:string[]}
     *   missing = needed by the template but absent or blank; invalid = sent but not usable (not text/number, too long); ignored = not used by the template.
     */
    public static function check(string $template, array $provided): array
    {
        $expected = self::expected($template);
        $filled = [];     // name (lower-case) → true when it carries a usable value
        $invalid = [];    // name as sent → reason
        foreach ($provided as $k => $v) {
            $name = strtolower(trim((string) $k));
            if (is_array($v) || is_object($v) || is_bool($v)) { $invalid[(string) $k] = 'must be text or a number'; continue; }
            if ($v === null || trim((string) $v) === '') { continue; }
            if (mb_strlen((string) $v) > self::MAX_LENGTH) { $invalid[(string) $k] = 'is too long (max ' . self::MAX_LENGTH . ' characters)'; continue; }
            $filled[$name] = true;
        }
        $missing = array_values(array_filter($expected, fn ($e) => !isset($filled[strtolower($e)])));
        // an invalid value for a variable the template does not use is just ignored; for one it needs it is already reported as missing too
        $needed  = array_map('strtolower', $expected);
        $invalid = array_filter($invalid, fn ($reason, $name) => in_array(strtolower(trim($name)), $needed, true), ARRAY_FILTER_USE_BOTH);
        $known   = array_merge($needed, self::AUTO);
        $ignored = array_values(array_filter(array_map('strval', array_keys($provided)), fn ($k) => !in_array(strtolower(trim($k)), $known, true)));
        return ['ok' => $missing === [] && $invalid === [], 'expected' => $expected, 'missing' => $missing, 'invalid' => $invalid, 'ignored' => $ignored];
    }

    /**
     * The 422 body for a failed check: one plain sentence naming every variable that is wrong, plus an `errors` entry per variable
     * (`variables.<name>`) so a form can mark the exact fields.
     *
     * @param array{missing:string[], invalid:array<string,string>, expected:string[]} $check
     */
    public static function failure(array $check, ?int $templateId, ?string $templateName): array
    {
        $for    = $templateName ? "this template ({$templateName})" : 'this template';
        $errors = [];
        foreach ($check['missing'] as $name) { $errors["variables.{$name}"][] = "The variable \"{$name}\" is missing for {$for}."; }
        foreach ($check['invalid'] as $name => $why) { $errors["variables.{$name}"] = ["The variable \"{$name}\" {$why}."]; }

        $parts = [];
        if ($check['missing']) {
            $list = '"' . implode('", "', $check['missing']) . '"';
            $parts[] = (count($check['missing']) === 1 ? "The variable {$list} is" : "The variables {$list} are") . " missing for {$for}";
        }
        foreach ($check['invalid'] as $name => $why) { $parts[] = "The variable \"{$name}\" {$why}"; }

        return [
            'error'         => implode('. ', $parts) . '.',
            'code'          => 'variables_mismatch',
            'template_id'   => $templateId,
            'template_name' => $templateName,
            'expected'      => $check['expected'],
            'missing'       => $check['missing'],
            'invalid'       => (object) $check['invalid'],
            'errors'        => (object) $errors,
        ];
    }
}
