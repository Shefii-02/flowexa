<?php

/**
 * Meta pre-approved WhatsApp *authentication* templates.
 *
 * Shape: name => [ language => localised body ]
 * Languages: en (English), ml (Malayalam), ar (Arabic), hi (Hindi).
 *
 * Loaded by {@see \Database\Seeders\PrebuiltTemplateSeeder} with type "auth".
 */

return [
    'delivery_code_1' => [
        'en' => 'Your order is arriving soon. {{code}} is your verification code. Please show this to the delivery associate.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ ഉടൻ വരുന്നു. {{code}} ആണ് നിങ്ങളുടെ വെരിഫിക്കേഷൻ കോഡ്. ദയവായി ഇത് ഡെലിവറി അസോസിയേറ്റിനെ കാണിക്കുക.',
        'ar' => 'طلبك سيصل قريبا. {{code}} هو رمز التحقق الخاص بك. يرجى إظهار هذا إلى مساعد التوصيل.',
        'hi' => 'आपका आदेश जल्द ही आ रहा है। {{code}} आपका सत्यापन कोड है। कृपया इसे डिलिवरी सहयोगी को दिखाएं।',
    ],
    'delivery_code_2' => [
        'en' => 'Hi {{text}},

Your {{text}} order is currently pending shipment. The estimated delivery date is {{date}}, with delivery scheduled between {{date}} and {{date}}.

Please note, the delivery person may ask for your delivery code {{code}} upon arrival.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ {{text}} ഓർഡർ നിലവിൽ ഷിപ്പ് മെന്റ് തീർപ്പാക്കിയിട്ടില്ല. ഏകദേശ ഡെലിവറി തീയതി {{date}} ആണ്, {{date}} നും {{date}} നും ഇടയിലുള്ള ഡെലിവറി ഷെഡ്യൂൾ ചെയ്തത്

ദയവായി ശ്രദ്ധിക്കുക, ഡെലിവറി ചെയ്യുന്ന വ്യക്തി നിങ്ങളുടെ ഡെലിവറി കോഡ് {{code}} ആവശ്യപ്പെട്ടേക്കാം.',
        'ar' => 'مرحبا {{text}}،

طلبك {{text}} في انتظار الشحن حاليًا. التاريخ التقديري للتسليم هو {{date}}، مع تحديد موعد التسليم بين {{date}} و {{date}}.

يرجى ملاحظة أنه يمكن لموظف التوصيل طلب رمز التوصيل الخاص بك {{code}} عند الوصول.',
        'hi' => 'हाय {{text}}

आपका {{text}} ऑर्डर वर्तमान में शिपमेंट लंबित है। अनुमानित वितरण तिथि {{date}} है, वितरण {{date}} और {{date}} के बीच निर्धारित है।

कृपया ध्यान दें, डिलीवरी व्यक्ति आपके आगमन पर आपके डिलीवरी कोड {{code}} के लिए पूछ सकता है।',
    ],
    'delivery_code_3' => [
        'en' => 'Your order is on its way and scheduled to arrive at {{date}}. Please show verification code {{code}} to receive your order.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ വരുന്നു, {{date}} ന് എത്താൻ ഷെഡ്യൂൾ ചെയ്യുന്നു. നിങ്ങളുടെ ഓർഡർ സ്വീകരിക്കുന്നതിന് പരിശോധിച്ചുറപ്പിക്കൽ കോഡ് {{code}} കാണിക്കുക.',
        'ar' => 'طلبك في طريقه ومن المقرر أن يصل في {{date}}. يرجى إظهار رمز التحقق {{code}} لاستلام طلبك.',
        'hi' => 'आपका आदेश अपने रास्ते पर है और {{date}} पर आने वाला है। अपना ऑर्डर प्राप्त करने के लिए कृपया सत्यापन कोड {{code}} दिखाएँ।',
    ],
    'delivery_code_4' => [
        'en' => 'Please share code {{code}} with delivery agent after verifying the package.',
        'ml' => 'പാക്കേജ് പരിശോധിച്ചുറപ്പിച്ച ശേഷം ഡെലിവറി ഏജന്റുമായി {{code}} കോഡ് പങ്കിടുക.',
        'ar' => 'يرجى مشاركة الرمز {{code}} مع وكيل التوصيل بعد التحقق من الطرد.',
        'hi' => 'कृपया पैकेज की जांच के बाद डिलीवरी एजेंट के साथ कोड {{code}} साझा करें।',
    ],
    'delivery_code_5' => [
        'en' => 'Your order of {{number}} items (Order ID: {{text}}) is out for delivery today.

Please provide the code {{code}} to the delivery agent to receive your order.',
        'ml' => '{{number}} ഇനങ്ങളുടെ നിങ്ങളുടെ ഓർഡർ (Order ID: {{text}}) ഇന്ന് ഡെലിവറിക്ക് തയ്യാറാണ്.

നിങ്ങളുടെ ഓർഡർ സ്വീകരിക്കുന്നതിന് ഡെലിവറി ഏജന്റിന് {{code}} എന്ന കോഡ് നൽകുക.',
        'ar' => 'طلبك من {{number}} العناصر (معرف الطلب: {{text}}) خارج للتسليم اليوم.

يرجى توفير الكود {{code}} لمندوب التوصيل لاستلام طلبك.',
        'hi' => '{{number}} आइटम का आपका ऑर्डर (ऑर्डर आईडी: {{text}}) आज डिलीवरी के लिए बाहर है।

कृपया अपना ऑर्डर प्राप्त करने के लिए डिलीवरी एजेंट को {{code}} कोड प्रदान करें।',
    ],
    'delivery_code_6' => [
        'en' => 'Your order {{text}} is on its way and scheduled to arrive {{date}}. Please show verification code {{code}} to receive your order.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ {{text}} വരുന്നു, {{date}} വരാൻ ഷെഡ്യൂൾ ചെയ്യുന്നു. നിങ്ങളുടെ ഓർഡർ സ്വീകരിക്കുന്നതിന് പരിശോധിച്ചുറപ്പിക്കൽ കോഡ് {{code}} കാണിക്കുക.',
        'ar' => 'طلبك {{text}} في طريقه ومن المقرر أن يصل {{date}}. يرجى إظهار رمز التحقق {{code}} لاستلام طلبك.',
        'hi' => 'आपका आदेश {{text}} अपने रास्ते पर है और आने वाला है {{date}}। अपना ऑर्डर प्राप्त करने के लिए कृपया सत्यापन कोड {{code}} दिखाएँ।',
    ],
    'in_person_banking_user_verification' => [
        'en' => 'Your personal data will be updated, provide the account executive with the verification code: {{code}}',
        'ml' => 'നിങ്ങളുടെ വ്യക്തിഗത ഡാറ്റ അപ് ഡേറ്റ് ചെയ്യും, അക്കൗണ്ട് എക്സിക്യൂട്ടീവിന് പരിശോധിച്ചുറപ്പിക്കൽ കോഡ് നൽകുക: {{code}}',
        'ar' => 'سيتم تحديث بياناتك الشخصية، قم بتزويد المسؤول التنفيذي للحساب بكود التحقق: {{code}}',
        'hi' => 'आपका व्यक्तिगत डेटा अपडेट किया जाएगा, खाता कार्यकारी को सत्यापन कोड प्रदान करें: {{code}}',
    ],
    'temporary_password' => [
        'en' => 'Your temporary password is {{text}}. Please log in and update it as soon as possible.',
        'ml' => 'നിങ്ങളുടെ താൽക്കാലിക പാസ് വേഡ് {{text}} ആണ്. ദയവായി ലോഗിൻ ചെയ്ത് എത്രയും പെട്ടെന്ന് അപ് ഡേറ്റ് ചെയ്യുക.',
        'ar' => 'كلمة المرور المؤقتة الخاصة بك هي {{text}}. يرجى تسجيل الدخول وتحديثه في أقرب وقت ممكن.',
        'hi' => 'आपका अस्थायी पासवर्ड {{text}} है। कृपया जल्द से जल्द लॉग इन करें और इसे अपडेट करें।',
    ],
    'verify_account' => [
        'en' => 'This OTP code is for {{text}} your {{text}} account and linking it to {{text}}.
OTP: {{number}}
Do not share it with anyone, even to {{text}}, or they\'ll be able to access your account.',
        'ml' => 'ഈ OTP കോഡ് നിങ്ങളുടെ {{text}} അക്കൗണ്ട് {{text}}-ലേക്ക് ലിങ്ക് ചെയ്യുന്നതിനും വേണ്ടിയാണ്.
ഒടിപി: {{text}}
{{number}} ലേക്ക് പോലും ഇത് ആരുമായും പങ്കിടരുത്, അല്ലെങ്കിൽ അവർക്ക് നിങ്ങളുടെ അക്കൗണ്ട് ആക്സസ് ചെയ്യാൻ കഴിയും.',
        'ar' => 'رمز OTP هذا مخصص لـ {{text}} حسابك {{text}} وربطه بـ {{text}}.
OTP: {{number}}
لا تشاركها مع أي شخص، حتى مع {{number}}، وإلا سيتمكنون من الوصول إلى حسابك.',
        'hi' => 'यह OTP कोड आपके {{text}} खाते को {{text}} और इसे {{text}} से लिंक करने के लिए है।
OTP: {{number}}
इसे किसी के साथ शेयर न करें, यहां तक कि {{text}} तक भी, नहीं तो वे आपके अकाउंट को एक्सेस कर सकेंगे।',
    ],
    'verify_account_2' => [
        'en' => 'This code is for {{text}} your {{text}} account and linking it to {{text}}. Code: {{number}}
Do not share it.',
        'ml' => 'നിങ്ങളുടെ {{text}} അക്കൗണ്ട് {{text}} ആയി ലിങ്ക് ചെയ്യുന്നതിനും വേണ്ടിയാണ് ഈ കോഡ്. കോഡ്: {{text}}
ഇത് ഷെയർ ചെയ്യരുത്.',
        'ar' => 'هذا الرمز مخصص لـ {{text}} حسابك على {{text}} وربطه بـ {{text}}. الرمز: {{number}}
لا تشاركه.',
        'hi' => 'यह कोड आपके {{text}} खाते को {{text}} और इसे {{text}} से लिंक करने के लिए है। कोड: {{number}}
इसे शेयर मत करना।',
    ],
    'verify_code' => [
        'en' => 'OTP Code: {{code}}. This is your OTP for {{text}}. The OTP is valid for {{text}}. Call {{phone}} if you did not perform this request.',
        'ml' => 'ഒടിപി കോഡ്: {{code}}. {{text}} നിങ്ങളുടെ ഒടിപി ഇതാണ്. OTP {{text}} ന് സാധുതയുള്ളതാണ്. നിങ്ങൾ ഈ അഭ്യർത്ഥന നടത്തിയില്ലെങ്കിൽ {{phone}} വിളിക്കുക.',
        'ar' => 'رمز التحقق لمرة واحدة (OTP): {{code}}. هذا هو رمز التحقق الخاص بك لـ {{text}}. الرمز صالح لمدة {{text}}. اتصل على الرقم {{phone}} إذا لم تقم بهذا الطلب.',
        'hi' => 'OTP कोड: {{code}}. यह {{text}} के लिए आपका OTP है। OTP {{text}} के लिए वैध है। कॉल करें {{phone}} अगर आपने यह अनुरोध नहीं किया है।',
    ],
    'verify_code_1' => [
        'en' => '{{code}} is your verification code.',
        'ml' => '{{code}} ആണ് നിങ്ങളുടെ വെരിഫിക്കേഷൻ കോഡ്.',
        'ar' => '{{code}} هو رمز التحقق الخاص بك.',
        'hi' => '{{code}} आपका सत्यापन कोड है।',
    ],
    'verify_otp_usecase' => [
        'en' => 'OTP Code: {{code}}. This is your OTP code for {{text}}. For your security, do not share this code.',
        'ml' => 'ഒടിപി കോഡ്: {{code}}. {{text}} എന്നതിനുള്ള നിങ്ങളുടെ OTP കോഡ് ഇതാണ്. നിങ്ങളുടെ സുരക്ഷയ്ക്കായി, ഈ കോഡ് പങ്കിടരുത്.',
        'ar' => 'رمز OTP: {{code}}. هذا هو رمز OTP الخاص بك لـ {{text}}. للحفاظ على أمانك، لا تشارك هذا الرمز.',
        'hi' => 'OTP कोड: {{code}}. यह {{text}} के लिए आपका OTP कोड है। अपनी सुरक्षा के लिए इस कोड को शेयर न करें।',
    ],
    'verify_password_recovery' => [
        'en' => '{{code}} is your password recovery code.',
        'ml' => '{{code}} ആണ് നിങ്ങളുടെ പാസ് വേഡ് വീണ്ടെടുക്കൽ കോഡ് ആണ്.',
        'ar' => '{{code}} هو رمز استرداد كلمة المرور الخاصة بك.',
        'hi' => '{{code}} आपका पासवर्ड वसूली कोड है।',
    ],
    'verify_transaction_1' => [
        'en' => 'Use code {{code}} to authorize your transaction.',
        'ml' => 'നിങ്ങളുടെ ഇടപാട് അംഗീകരിക്കാൻ {{code}} കോഡ് ഉപയോഗിക്കുക.',
        'ar' => 'استخدم الرمز {{code}} للتصريح بمعاملتك.',
        'hi' => 'अपने लेनदेन को अधिकृत करने के लिए {{code}} कोड का उपयोग करें।',
    ],
    'verify_transaction_2' => [
        'en' => 'Use code {{code}} to verify your transaction of {{amount}}.',
        'ml' => '{{code}} ന്റെ നിങ്ങളുടെ ഇടപാട് പരിശോധിച്ചുറപ്പിക്കാൻ {{amount}} കോഡ് ഉപയോഗിക്കുക.',
        'ar' => 'استخدم الرمز {{code}} للتحقق من معاملتك بقيمة {{amount}}.',
        'hi' => '{{amount}} के अपने लेनदेन को सत्यापित करने के लिए कोड {{code}} का उपयोग करें।',
    ],
    'verify_transaction_3' => [
        'en' => 'Use code {{code}} to verify your transaction of {{amount}} on your card ending in {{card number}}.',
        'ml' => '{{code}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ കാർഡിൽ {{amount}} എന്ന നിങ്ങളുടെ ഇടപാട് പരിശോധിച്ചുറപ്പിക്കാൻ {{card number}} കോഡ് ഉപയോഗിക്കുക.',
        'ar' => 'استخدم الرمز {{code}} للتحقق من معاملتك بقيمة {{amount}} على بطاقتك التي تنتهي بـ {{card number}}.',
        'hi' => 'अपने {{card number}} में समाप्त हो रहे कार्ड पर {{amount}} के लेनदेन को सत्यापित करने के लिए कोड {{code}} का उपयोग करें।',
    ],
    'verify_transaction_4' => [
        'en' => 'Use code {{code}} to verify your transaction of {{amount}} on your account ending in {{number}}.',
        'ml' => '{{code}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ അക്കൗണ്ടിൽ {{amount}} എന്ന നിങ്ങളുടെ ഇടപാട് പരിശോധിച്ചുറപ്പിക്കാൻ {{number}} കോഡ് ഉപയോഗിക്കുക.',
        'ar' => 'استخدم الرمز {{code}} للتحقق من معاملتك بقيمة {{amount}} على حسابك الذي ينتهي بـ {{number}}.',
        'hi' => 'अपने {{number}} में समाप्त हो रहे खाते पर {{amount}} के लेनदेन को सत्यापित करने के लिए {{code}} कोड का उपयोग करें।',
    ],
    'verify_transfer_1' => [
        'en' => '{{code}} is your verification code for transfer of {{amount}}.',
        'ml' => '{{code}} ആണ് {{amount}} കൈമാറാനുള്ള നിങ്ങളുടെ വെരിഫിക്കേഷൻ കോഡ്.',
        'ar' => '{{code}} هو رمز التحقق الخاص بك لنقل {{amount}}.',
        'hi' => '{{code}} {{amount}} के हस्तांतरण के लिए आपका सत्यापन कोड है।',
    ],
];
