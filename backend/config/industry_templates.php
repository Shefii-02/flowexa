<?php

/*
|--------------------------------------------------------------------------
| Industry templates
|--------------------------------------------------------------------------
| Pre-built verticals for the AI sales agent. Each template defines:
|  - listing_type        : what the catalog holds for this vertical
|  - attribute_schema    : the structured fields a listing carries (and which are "matchable"
|                          so ListingMatcher scores them against a lead's stated requirements)
|  - qualification_fields: what the agent must collect from a visitor before the lead is "qualified"
|  - question_flow       : the natural questions the agent works through (order matters)
|  - agent_prompt        : vertical-specific guidance appended to the agent system prompt
|
| "generic" is the fallback for any other business type — free-form attributes, minimal flow.
*/

return [

    'real_estate' => [
        'name'          => 'Real Estate',
        'listing_type'  => 'property',
        'attribute_schema' => [
            ['key' => 'transaction',    'label' => 'For',            'type' => 'enum', 'options' => ['sale', 'rent'], 'matchable' => true],
            ['key' => 'property_type',  'label' => 'Property type',   'type' => 'enum', 'options' => ['apartment', 'villa', 'plot', 'commercial', 'house'], 'matchable' => true],
            ['key' => 'bedrooms',       'label' => 'Bedrooms (BHK)',  'type' => 'number', 'matchable' => true, 'tolerance' => 1],
            ['key' => 'bathrooms',      'label' => 'Bathrooms',       'type' => 'number'],
            ['key' => 'area_sqft',      'label' => 'Built-up area (sq ft)', 'type' => 'number'],
            ['key' => 'furnishing',     'label' => 'Furnishing',      'type' => 'enum', 'options' => ['unfurnished', 'semi', 'furnished']],
            ['key' => 'parking',        'label' => 'Parking included', 'type' => 'boolean'],
            ['key' => 'possession',     'label' => 'Possession',      'type' => 'text'],
            ['key' => 'facing',         'label' => 'Facing',          'type' => 'text'],
        ],
        'qualification_fields' => [
            ['key' => 'name',        'label' => 'Name',          'type' => 'text',   'required' => true],
            ['key' => 'phone',       'label' => 'Phone',         'type' => 'phone',  'required' => true],
            ['key' => 'location',    'label' => 'Preferred area', 'type' => 'text',  'required' => true],
            ['key' => 'budget',      'label' => 'Budget',        'type' => 'number', 'required' => true],
            ['key' => 'bedrooms',    'label' => 'BHK needed',    'type' => 'number', 'required' => true],
            ['key' => 'timeline',    'label' => 'When to move',  'type' => 'text',   'required' => false],
        ],
        'question_flow' => [
            'Are you looking to buy or rent?',
            'Which area or locality do you prefer?',
            'How many bedrooms (BHK) do you need?',
            "What's your budget range?",
            'When are you planning to move / purchase?',
            'Can I get your name and phone number so our team can share matching options?',
        ],
        'agent_prompt' => 'You are helping a property buyer/renter. Match their area, budget and BHK to the live '
                        . 'listings and share the 1-3 best fits with key details. Offer to book a site visit once '
                        . 'they show interest. Answer property-specific questions (parking, possession, facing) only '
                        . 'from the listing data.',
        'lead_source' => 'website_widget',
    ],

    'health_clinic' => [
        'name'         => 'Health Clinic',
        'listing_type' => 'service',
        'attribute_schema' => [
            ['key' => 'department',   'label' => 'Department / specialty', 'type' => 'text',   'matchable' => true],
            ['key' => 'doctor',      'label' => 'Doctor',                 'type' => 'text',   'matchable' => true],
            ['key' => 'consultation_fee', 'label' => 'Consultation fee',  'type' => 'number'],
            ['key' => 'duration_min', 'label' => 'Slot length (min)',     'type' => 'number'],
            ['key' => 'insurance',    'label' => 'Insurance accepted',    'type' => 'boolean'],
            ['key' => 'availability', 'label' => 'Available days/times',   'type' => 'text'],
            ['key' => 'languages',    'label' => 'Languages',             'type' => 'text'],
        ],
        'qualification_fields' => [
            ['key' => 'name',       'label' => 'Patient name', 'type' => 'text',  'required' => true],
            ['key' => 'phone',      'label' => 'Phone',        'type' => 'phone', 'required' => true],
            ['key' => 'department', 'label' => 'Department needed', 'type' => 'text', 'required' => true],
            ['key' => 'preferred_time', 'label' => 'Preferred date/time', 'type' => 'text', 'required' => true],
            ['key' => 'concern',    'label' => 'Reason for visit', 'type' => 'text', 'required' => false],
        ],
        'question_flow' => [
            'Which department or specialist do you need to see?',
            'Do you have a preferred doctor?',
            'What day and time works for you?',
            'Can I have your name and phone number to confirm the appointment?',
        ],
        'agent_prompt' => 'You are a clinic front-desk assistant. Help patients find the right department/doctor, '
                        . 'quote fees and answer insurance questions ONLY from the configured data, and collect '
                        . 'their preferred slot + contact so the desk can confirm the appointment. Never give '
                        . 'medical advice — for symptoms, recommend booking a consultation.',
        'lead_source' => 'website_widget',
    ],

    'education' => [
        'name'         => 'Education & Coaching',
        'listing_type' => 'course',
        'attribute_schema' => [
            ['key' => 'category',     'label' => 'Category',        'type' => 'text',   'matchable' => true],
            ['key' => 'level',        'label' => 'Level',           'type' => 'enum', 'options' => ['beginner', 'intermediate', 'advanced'], 'matchable' => true],
            ['key' => 'mode',         'label' => 'Mode',            'type' => 'enum', 'options' => ['online', 'offline', 'hybrid'], 'matchable' => true],
            ['key' => 'duration',     'label' => 'Duration',        'type' => 'text'],
            ['key' => 'batch_timing', 'label' => 'Batch timings',   'type' => 'text'],
            ['key' => 'start_date',   'label' => 'Next start date', 'type' => 'text'],
            ['key' => 'total_fee',    'label' => 'Total fee',       'type' => 'number'],
            ['key' => 'emi_available', 'label' => 'EMI available',  'type' => 'boolean'],
            ['key' => 'eligibility',  'label' => 'Eligibility',     'type' => 'text'],
        ],
        'qualification_fields' => [
            ['key' => 'name',      'label' => 'Student / parent name', 'type' => 'text',  'required' => true],
            ['key' => 'phone',     'label' => 'Phone',                'type' => 'phone', 'required' => true],
            ['key' => 'course_interest', 'label' => 'Course of interest', 'type' => 'text', 'required' => true],
            ['key' => 'preferred_mode', 'label' => 'Online / offline', 'type' => 'text', 'required' => false],
            ['key' => 'start_timeline',  'label' => 'When to start',   'type' => 'text', 'required' => false],
        ],
        'question_flow' => [
            'Which course or subject are you interested in?',
            'Do you prefer online or offline (classroom) batches?',
            'When would you like to start?',
            'Can I get your name and phone number so a counselor can share the details and fee options?',
        ],
        'agent_prompt' => 'You are an admissions assistant. Answer course content, batch timing, start date and '
                        . 'eligibility questions from the catalog. Handle fee and EMI questions sensitively and '
                        . 'always capture the lead so a counselor can follow up. Guide interested students through '
                        . 'the enrolment steps.',
        'lead_source' => 'website_widget',
    ],

    'generic' => [
        'name'         => 'Other business',
        'listing_type' => 'product',
        'attribute_schema' => [
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'matchable' => true],
            ['key' => 'brand',    'label' => 'Brand',    'type' => 'text'],
            ['key' => 'specs',    'label' => 'Key details', 'type' => 'text'],
        ],
        'qualification_fields' => [
            ['key' => 'name',        'label' => 'Name',           'type' => 'text',  'required' => true],
            ['key' => 'phone',       'label' => 'Phone',          'type' => 'phone', 'required' => true],
            ['key' => 'requirement', 'label' => 'What they need',  'type' => 'text',  'required' => true],
            ['key' => 'budget',      'label' => 'Budget',         'type' => 'number', 'required' => false],
        ],
        'question_flow' => [
            'What exactly are you looking for?',
            'Do you have a budget in mind?',
            'Can I get your name and phone number so our team can help you further?',
        ],
        'agent_prompt' => 'Understand what the customer needs, match it to the catalog, and capture their name + '
                        . 'phone + requirement so the team can follow up.',
        'lead_source' => 'website_widget',
    ],

];
