<?php

namespace Database\Seeders;

use App\Models\PrebuiltTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the shared library of Meta pre-approved WhatsApp message templates.
 *
 * The template bodies live in per-category data files under
 * {@see database/seeders/data}, each returning
 *   name => [ language => localised body ]
 *
 * Categories:
 *  - auth     : authentication / OTP templates
 *  - utility  : transactional utility templates (orders, payments, appointments…)
 *
 * `type` is stored as a plain string (not an enum) so more categories can be
 * added by dropping in another data file — no migration needed.
 *
 * Idempotent — re-running refreshes `type`/`content`/`variables`/`status` for
 * each (name, language) pair. `variables` is derived from the {{placeholder}}
 * tokens in `content`, in order of first appearance, de-duplicated.
 */
class PrebuiltTemplateSeeder extends Seeder
{
    /**
     * type => path to the data file returning `name => [language => body]`.
     */
    private const SOURCES = [
        'auth'    => __DIR__ . '/data/prebuilt_auth_templates.php',
        'utility' => __DIR__ . '/data/prebuilt_utility_templates.php',
    ];

    public function run(): void
    {
        foreach (self::SOURCES as $type => $path) {
            $templates = require $path;

            foreach ($templates as $name => $byLanguage) {
                foreach ($byLanguage as $language => $content) {
                    PrebuiltTemplate::updateOrCreate(
                        ['name' => $name, 'language' => $language],
                        [
                            'type'      => $type,
                            'content'   => $content,
                            'variables' => PrebuiltTemplate::extractVariables($content),
                            'status'    => 'active',
                        ],
                    );
                }
            }
        }

        $this->command?->info('Seeded ' . PrebuiltTemplate::count() . ' prebuilt templates.');
    }
}
