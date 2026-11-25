<?php

namespace App\Support;

/**
 * Is a WhatsApp chat id ONE person? Only a one-to-one chat may become a lead — never a group ("…@g.us"), a broadcast list or Status
 * ("…@broadcast"), a channel ("…@newsletter") or a community. An individual is "…@c.us", "…@s.whatsapp.net" or a privacy id "…@lid".
 */
class WaChatId
{
    private const INDIVIDUAL = ['c.us', 's.whatsapp.net', 'lid'];

    public static function isIndividual(?string $chatId): bool
    {
        $chatId = trim((string) $chatId);
        if ($chatId === '') { return false; }
        if (!str_contains($chatId, '@')) { return true; }   // a bare number (a channel that has no chat ids, like WA Cloud) is a person
        [$user, $server] = explode('@', $chatId, 2);
        // an old group id looks like "9198…-1612345678@g.us"; a hyphen never appears in one person's id
        return in_array(strtolower($server), self::INDIVIDUAL, true) && $user !== '' && !str_contains($user, '-') && strtolower($user) !== 'status';
    }
}
