<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadCategory extends Model
{
    protected $fillable = [
        'company_id', 'name', 'color',
        'description', 'leads_count', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
        'leads_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'lead_category_id');
    }

    // Auto-sync leads_count when leads are created/deleted
    public function syncLeadsCount(): void
    {
        $this->update(['leads_count' => $this->leads()->count()]);
    }
}


// ════════════════════════════════════════════════════════════════════════════
// SEEDER — default categories auto-created with each company
// Call this from CompanySetupService::setupNewCompany()
// ════════════════════════════════════════════════════════════════════════════

class LeadCategorySeeder
{
    public static function seedForCompany(int $companyId): void
    {
        $defaults = [
            ['name' => 'General Enquiry',    'color' => '#6366f1', 'sort_order' => 0],
            ['name' => 'Product Demo',        'color' => '#1D9E75', 'sort_order' => 1],
            ['name' => 'Pricing Enquiry',     'color' => '#f59e0b', 'sort_order' => 2],
            ['name' => 'Support Request',     'color' => '#ef4444', 'sort_order' => 3],
            ['name' => 'Partnership',         'color' => '#8b5cf6', 'sort_order' => 4],
        ];

        foreach ($defaults as $cat) {
            LeadCategory::firstOrCreate(
                ['company_id' => $companyId, 'name' => $cat['name']],
                array_merge($cat, ['company_id' => $companyId])
            );
        }
    }
}
