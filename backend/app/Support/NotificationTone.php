<?php

namespace App\Support;

/** Which sound a notification type plays (see config/notification_tones.php). */
class NotificationTone
{
    /** @return array{key:string, file:string, url:string} */
    public static function forType(string $type): array
    {
        $tones = config('notification_tones.tones');
        $key = config("notification_tones.types.{$type}") ?? config('notification_tones.default');
        if (!isset($tones[$key])) { $key = array_key_first($tones); }
        $file = $tones[$key][0];
        return ['key' => $key, 'file' => $file, 'url' => rtrim((string) config('app.url'), '/') . '/tones/' . $file];
    }

    /** All tones for a settings screen. @return array<int,array{key:string,label:string,url:string}> */
    public static function all(): array
    {
        $base = rtrim((string) config('app.url'), '/') . '/tones/';
        return collect(config('notification_tones.tones'))->map(fn ($t, $k) => ['key' => $k, 'label' => $t[1], 'url' => $base . $t[0]])->values()->all();
    }
}
