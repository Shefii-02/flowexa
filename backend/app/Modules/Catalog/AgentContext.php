<?php

namespace App\Modules\Catalog;

use App\Models\Company;
use App\Models\Listing;
use App\Modules\WaChat\Services\Rag\QueryAgent;

/**
 * Builds the "what the AI knows about this company" block shared by every agent (website widget,
 * Instagram DMs, WhatsApp): the relevant slice of the knowledge base for the current question, plus
 * the live catalog. One place so all channels answer with the same facts.
 */
class AgentContext
{
    private const CATALOG_CHAR_CAP = 5000;
    private const KB_CHAR_CAP      = 3500;

    public function __construct(private readonly QueryAgent $queryAgent) {}

    /**
     * @param string $query  the visitor's latest message — drives knowledge-base retrieval
     */
    public function build(Company $company, string $query, ?string $industryTemplate = null): string
    {
        $template = $industryTemplate ?: ($company->industry_template ?: 'generic');

        $sections = [];

        $kb = $this->knowledge($company->id, $query);
        if ($kb !== '') {
            $sections[] = "COMPANY KNOWLEDGE:\n{$kb}";
        }

        $sections[] = "LIVE CATALOG:\n" . $this->catalog($company->id, IndustryTemplates::listingType($template));

        return implode("\n\n", $sections);
    }

    /** Just the catalog block — used where knowledge retrieval isn't wanted. */
    public function catalog(int $companyId, string $type): string
    {
        $rows = Listing::where('company_id', $companyId)->where('type', $type)->active()
            ->orderBy('sort_order')->limit(60)->get();

        if ($rows->isEmpty()) {
            return "(no {$type}s configured yet — collect the customer's requirements and phone number so the team can follow up)";
        }

        $out = '';
        foreach ($rows as $l) {
            $attrs = collect($l->attributes ?? [])
                ->reject(fn ($v, $k) => str_starts_with($k, 'instagram_'))
                ->map(fn ($v, $k) => "$k=" . (is_bool($v) ? ($v ? 'yes' : 'no') : $v))->implode(', ');
            $line = "• {$l->summaryLine()}"
                . ($l->description ? ' — ' . mb_substr($l->description, 0, 180) : '')
                . ($attrs ? " [{$attrs}]" : '') . "\n";
            if (mb_strlen($out) + mb_strlen($line) > self::CATALOG_CHAR_CAP) break;
            $out .= $line;
        }
        return rtrim($out);
    }

    /** Top knowledge-base chunks for the query (TF-IDF; no external embedding call). */
    private function knowledge(int $companyId, string $query): string
    {
        try {
            $hits = $this->queryAgent->retrieve($query, $companyId);
        } catch (\Throwable) {
            return '';
        }

        $out = '';
        foreach ($hits as $hit) {
            $chunk = trim((string) ($hit['chunk']->content ?? ''));
            if ($chunk === '') continue;
            if (mb_strlen($out) + mb_strlen($chunk) > self::KB_CHAR_CAP) break;
            $out .= $chunk . "\n---\n";
        }
        return rtrim($out, "-\n ");
    }
}
