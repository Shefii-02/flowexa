<?php

namespace App\Modules\Hr\Support;

class Geo
{
    /** Great-circle distance between two lat/lng points, in metres (haversine). */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earth = 6_371_000; // metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
