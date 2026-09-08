// Shared shapes for the Audience Sets feature (company-owned, reusable targeting).

export interface IdName { id: string; name: string; audience_size_lower_bound?: number; audience_size_upper_bound?: number; path?: string[] }

export interface AudiencePlacements {
  automatic: boolean
  publisher_platforms?: string[]      // facebook | instagram | audience_network | messenger
  facebook_positions?: string[]
  instagram_positions?: string[]
  device_platforms?: string[]         // mobile | desktop
}

export interface AudienceGeo {
  countries?: string[]
  regions?: { key: string; name: string }[]
  cities?: { key: string; name: string }[]
}

// The normalized shape the builder edits and the backend stores/compiles.
export interface AudienceSetDraft {
  name: string
  description?: string | null
  age_min: number
  age_max: number
  genders: 'all' | 'male' | 'female'
  geo_locations: AudienceGeo
  interests: IdName[]
  behaviors: IdName[]
  exclusions: { interests?: IdName[]; behaviors?: IdName[] }
  placements: AudiencePlacements
  is_favorite?: boolean
}

export interface AudienceSet extends AudienceSetDraft {
  id: number
  source: 'manual' | 'template' | 'from_adset'
  template_id?: number | null
  use_count: number
  reach_min?: number | null
  reach_max?: number | null
  reach_estimated_at?: string | null
  updated_at: string
}

export interface AudienceTemplate {
  id: number
  name: string
  slug: string
  industry?: string
  description?: string
  objective: string
  age_min: number
  age_max: number
  genders: string
  interests?: IdName[] | null
  behaviors?: IdName[] | null
  targeting_json?: Record<string, unknown> | null
  suggested_daily_budget: number
  estimated_reach_min: number
  estimated_reach_max: number
}

export const emptyAudienceDraft = (): AudienceSetDraft => ({
  name: '',
  description: '',
  age_min: 18,
  age_max: 65,
  genders: 'all',
  geo_locations: { countries: ['IN'] },
  interests: [],
  behaviors: [],
  exclusions: { interests: [], behaviors: [] },
  placements: { automatic: true },
})

/** Server row (snake_case, possibly partial) → the builder draft. */
export function toDraft(input: Partial<AudienceSet> | Record<string, unknown>): AudienceSetDraft {
  const s = input as Record<string, unknown>
  const base = emptyAudienceDraft()
  return {
    ...base,
    name: (s.name as string) ?? '',
    description: (s.description as string) ?? '',
    age_min: (s.age_min as number) ?? base.age_min,
    age_max: (s.age_max as number) ?? base.age_max,
    genders: (s.genders as AudienceSetDraft['genders']) ?? 'all',
    geo_locations: (s.geo_locations as AudienceGeo) ?? base.geo_locations,
    interests: (s.interests as IdName[]) ?? [],
    behaviors: (s.behaviors as IdName[]) ?? [],
    exclusions: (s.exclusions as AudienceSetDraft['exclusions']) ?? { interests: [], behaviors: [] },
    placements: (s.placements as AudiencePlacements) ?? { automatic: true },
    is_favorite: Boolean(s.is_favorite),
  }
}
