<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function index()
    {
        return view('landing.contact');
    }

    public function send(Request $request)
    {
        $d = $request->validate([
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'company' => ['nullable', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        // Send email (configure MAIL_* in .env)
        Mail::raw(
            "Name: {$d['name']}\nEmail: {$d['email']}\nPhone: {$d['phone']}\nCompany: {$d['company']}\n\nSubject: {$d['subject']}\n\nMessage:\n{$d['message']}",
            function ($m) use ($d) {
                $m->to(config('landing.contact_email', 'hello@waapi.com'))
                  ->subject("Contact: {$d['subject']} — {$d['name']}");
            }
        );

        // WhatsApp redirect option
        if ($request->has('via_whatsapp')) {
            $msg  = urlencode("Hi! I'm {$d['name']} from {$d['company']}. {$d['message']}");
            $phone= config('landing.whatsapp_number', '918086544828');
            return redirect("https://wa.me/{$phone}?text={$msg}");
        }

        return back()->with('success', 'Your message has been sent! We\'ll reply within 24 hours.');
    }
}
