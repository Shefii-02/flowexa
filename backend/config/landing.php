<?php
// config/landing.php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp number for the float button and contact redirects
    | Format: country code + number, no + or spaces
    |--------------------------------------------------------------------------
    */
    'whatsapp_number' => env('LANDING_WHATSAPP_NUMBER', '918086544828'),

    /*
    |--------------------------------------------------------------------------
    | Email address shown in footer and contact page
    |--------------------------------------------------------------------------
    */
    'contact_email' => env('LANDING_CONTACT_EMAIL', 'hello@waapi.com'),

    /*
    |--------------------------------------------------------------------------
    | Frontend app URL (React dashboard) — for Login button in navbar
    |--------------------------------------------------------------------------
    */
    'app_url' => env('LANDING_APP_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Company address shown in footer
    |--------------------------------------------------------------------------
    */
    'address' => env('LANDING_ADDRESS', 'Kochi, Kerala, India'),

];


/*
|=============================================================================
| .env additions — add these to your .env file
|=============================================================================

# Landing page config
LANDING_WHATSAPP_NUMBER=918086544828
LANDING_CONTACT_EMAIL=hello@waapi.com
LANDING_APP_URL=http://localhost:5173
LANDING_ADDRESS="Kochi, Kerala, India"

# Razorpay — demo test credentials (replace with live keys in production)
RAZORPAY_KEY=rzp_test_41ll40lMstOJq3
RAZORPAY_SECRET=T9JOZGX4lhIhkp5E1UJiEkOj

# Mail — for contact form submissions
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@waapi.com
MAIL_FROM_NAME="WA SaaS Platform"

|=============================================================================
| services.php additions — add razorpay block
|=============================================================================

'razorpay' => [
    'key'    => env('RAZORPAY_KEY'),
    'secret' => env('RAZORPAY_SECRET'),
],

|=============================================================================
| routes/web.php — add this line
|=============================================================================

require __DIR__.'/landing.php';

|=============================================================================
| composer.json — add razorpay SDK
|=============================================================================

composer require razorpay/razorpay

|=============================================================================
| DEMO Razorpay TEST credentials (already in .env above)
| These are real Razorpay test credentials for development
|=============================================================================

Key:    rzp_test_41ll40lMstOJq3
Secret: T9JOZGX4lhIhkp5E1UJiEkOj

Test card details:
  Card:    4111 1111 1111 1111
  Expiry:  12/28
  CVV:     123
  Name:    Any name
  OTP:     1234

Test UPI:
  UPI ID: success@razorpay

*/
