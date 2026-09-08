<?php

/**
 * Meta pre-approved WhatsApp *utility* templates (orders, payments, deliveries,
 * appointments, service alerts and similar transactional messages).
 *
 * Shape: name => [ language => localised body ]
 * Languages: en (English), ml (Malayalam), ar (Arabic), hi (Hindi).
 * Non-English bodies are best-effort translations of the English source.
 *
 * Loaded by {@see \Database\Seeders\PrebuiltTemplateSeeder} with type "utility".
 */

return [
    'account_creation_confirmation_3' => [
        'en' => 'Hi {{text}},

Your new account has been created successfully.

Please verify {{text}} to complete your profile.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ പുതിയ അക്കൗണ്ട് വിജയകരമായി സൃഷ്ടിച്ചു.

നിങ്ങളുടെ പ്രൊഫൈൽ പൂർത്തിയാക്കാൻ {{text}} പരിശോധിച്ചുറപ്പിക്കുക.',
        'ar' => 'مرحبا {{text}}،

تم إنشاء حسابك الجديد بنجاح.

يرجى التحقق من {{text}} لإكمال ملفك الشخصي.',
        'hi' => 'नमस्ते {{text}},

आपका नया खाता सफलतापूर्वक बना दिया गया है।

अपनी प्रोफ़ाइल पूरी करने के लिए कृपया {{text}} सत्यापित करें।',
    ],
    'address_update' => [
        'en' => 'Hi {{text}}, your delivery address has been successfully updated to {{text}}. Contact {{text}} for any inquiries.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഡെലിവറി വിലാസം {{text}} ആയി വിജയകരമായി അപ്ഡേറ്റ് ചെയ്തു. എന്തെങ്കിലും അന്വേഷണങ്ങൾക്ക് {{text}} ബന്ധപ്പെടുക.',
        'ar' => 'مرحبا {{text}}، تم تحديث عنوان التسليم الخاص بك بنجاح إلى {{text}}. تواصل مع {{text}} لأي استفسارات.',
        'hi' => 'नमस्ते {{text}}, आपका डिलीवरी पता सफलतापूर्वक {{text}} में अपडेट कर दिया गया है। किसी भी पूछताछ के लिए {{text}} से संपर्क करें।',
    ],
    'appointment_cancellation_1' => [
        'en' => 'Hello {{text}},

Your upcoming appointment with {{business name}} on {{date}} at {{text}} has been canceled.

Let us know if you have any questions or need to reschedule.',
        'ml' => 'ഹലോ {{text}},

{{date}} ന് {{text}} ന് {{business name}} യുമായുള്ള നിങ്ങളുടെ വരാനിരിക്കുന്ന അപ്പോയിന്റ്മെന്റ് റദ്ദാക്കി.

നിങ്ങൾക്ക് എന്തെങ്കിലും ചോദ്യങ്ങളുണ്ടെങ്കിലോ വീണ്ടും ഷെഡ്യൂൾ ചെയ്യണമെങ്കിലോ ഞങ്ങളെ അറിയിക്കുക.',
        'ar' => 'مرحبا {{text}}،

تم إلغاء موعدك القادم مع {{business name}} في {{date}} الساعة {{text}}.

أخبرنا إذا كان لديك أي أسئلة أو إذا كنت بحاجة إلى إعادة الجدولة.',
        'hi' => 'नमस्ते {{text}},

{{date}} को {{text}} बजे {{business name}} के साथ आपका आगामी अपॉइंटमेंट रद्द कर दिया गया है।

यदि आपके कोई प्रश्न हैं या आपको पुनर्निर्धारण की आवश्यकता है तो हमें बताएं।',
    ],
    'appointment_cancelled' => [
        'en' => 'Hi {{text}},
Your appointment on {{text}} has been cancelled. We hope to see you another time.',
        'ml' => 'ഹായ് {{text}},
{{text}} ലെ നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റ് റദ്ദാക്കി. മറ്റൊരു സമയത്ത് നിങ്ങളെ കാണാൻ ഞങ്ങൾ പ്രതീക്ഷിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}،
تم إلغاء موعدك في {{text}}. نأمل أن نراك في وقت آخر.',
        'hi' => 'नमस्ते {{text}},
{{text}} को आपका अपॉइंटमेंट रद्द कर दिया गया है। हमें आशा है कि हम आपको किसी और समय देखेंगे।',
    ],
    'appointment_confirmation_1' => [
        'en' => 'Hello {{text}},

Thank you for booking with {{business name}}.

Your appointment for {{text}} on {{date}} at {{text}} is confirmed.',
        'ml' => 'ഹലോ {{text}},

{{business name}} യുമായി ബുക്ക് ചെയ്തതിന് നന്ദി.

{{date}} ന് {{text}} ന് {{text}} നുള്ള നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റ് സ്ഥിരീകരിച്ചു.',
        'ar' => 'مرحبا {{text}}،

شكرا لحجزك مع {{business name}}.

تم تأكيد موعدك لـ {{text}} في {{date}} الساعة {{text}}.',
        'hi' => 'नमस्ते {{text}},

{{business name}} के साथ बुकिंग के लिए धन्यवाद।

{{date}} को {{text}} बजे {{text}} के लिए आपका अपॉइंटमेंट पुष्टि हो गया है।',
    ],
    'appointment_confirmed' => [
        'en' => 'Hi {{text}},
Your appointment is scheduled for {{text}}.

Service: {{text}}
Confirmation number: {{text}}

We\'re looking forward to your visit.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റ് {{text}} ന് ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു.

സേവനം: {{text}}
സ്ഥിരീകരണ നമ്പർ: {{text}}

നിങ്ങളുടെ സന്ദർശനത്തിനായി ഞങ്ങൾ കാത്തിരിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}،
تم تحديد موعدك في {{text}}.

الخدمة: {{text}}
رقم التأكيد: {{text}}

نتطلع إلى زيارتك.',
        'hi' => 'नमस्ते {{text}},
आपका अपॉइंटमेंट {{text}} के लिए निर्धारित है।

सेवा: {{text}}
पुष्टि संख्या: {{text}}

हम आपकी यात्रा की प्रतीक्षा कर रहे हैं।',
    ],
    'appointment_reminder' => [
        'en' => 'Reminder: Our technician will visit your location on {{date}} at {{text}} for your broadband installation. Please be available.',
        'ml' => 'ഓർമ്മപ്പെടുത്തൽ: നിങ്ങളുടെ ബ്രോഡ്ബാൻഡ് ഇൻസ്റ്റാളേഷനായി ഞങ്ങളുടെ ടെക്നീഷ്യൻ {{date}} ന് {{text}} ന് നിങ്ങളുടെ സ്ഥലം സന്ദർശിക്കും. ദയവായി ലഭ്യമായിരിക്കുക.',
        'ar' => 'تذكير: سيزور الفني موقعك في {{date}} الساعة {{text}} لتركيب النطاق العريض الخاص بك. يرجى التواجد.',
        'hi' => 'अनुस्मारक: हमारा तकनीशियन आपके ब्रॉडबैंड इंस्टॉलेशन के लिए {{date}} को {{text}} बजे आपके स्थान पर आएगा। कृपया उपलब्ध रहें।',
    ],
    'appointment_reminder_2' => [
        'en' => 'Hello {{text}},

This is a reminder about your upcoming appointment with {{business name}} on {{date}} at {{text}}.

We look forward to seeing you!',
        'ml' => 'ഹലോ {{text}},

{{date}} ന് {{text}} ന് {{business name}} യുമായുള്ള നിങ്ങളുടെ വരാനിരിക്കുന്ന അപ്പോയിന്റ്മെന്റിനെക്കുറിച്ചുള്ള ഒരു ഓർമ്മപ്പെടുത്തലാണിത്.

നിങ്ങളെ കാണാൻ ഞങ്ങൾ കാത്തിരിക്കുന്നു!',
        'ar' => 'مرحبا {{text}}،

هذا تذكير بموعدك القادم مع {{business name}} في {{date}} الساعة {{text}}.

نتطلع إلى رؤيتك!',
        'hi' => 'नमस्ते {{text}},

यह {{date}} को {{text}} बजे {{business name}} के साथ आपके आगामी अपॉइंटमेंट के बारे में एक अनुस्मारक है।

हम आपसे मिलने के लिए उत्सुक हैं!',
    ],
    'appointment_reschedule_1' => [
        'en' => 'Hello {{text}},

Your upcoming appointment with {{business name}} has been rescheduled for {{date}} at {{text}}.

We look forward to seeing you!',
        'ml' => 'ഹലോ {{text}},

{{business name}} യുമായുള്ള നിങ്ങളുടെ വരാനിരിക്കുന്ന അപ്പോയിന്റ്മെന്റ് {{date}} ന് {{text}} ന് വീണ്ടും ഷെഡ്യൂൾ ചെയ്തു.

നിങ്ങളെ കാണാൻ ഞങ്ങൾ കാത്തിരിക്കുന്നു!',
        'ar' => 'مرحبا {{text}}،

تمت إعادة جدولة موعدك القادم مع {{business name}} إلى {{date}} الساعة {{text}}.

نتطلع إلى رؤيتك!',
        'hi' => 'नमस्ते {{text}},

{{business name}} के साथ आपका आगामी अपॉइंटमेंट {{date}} को {{text}} बजे के लिए पुनर्निर्धारित किया गया है।

हम आपसे मिलने के लिए उत्सुक हैं!',
    ],
    'appointment_rescheduled' => [
        'en' => 'Hi {{text}},
Your appointment has been rescheduled to {{text}}.

Service: {{text}}
Confirmation number: {{text}}

We\'re looking forward to your visit.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റ് {{text}} ലേക്ക് വീണ്ടും ഷെഡ്യൂൾ ചെയ്തു.

സേവനം: {{text}}
സ്ഥിരീകരണ നമ്പർ: {{text}}

നിങ്ങളുടെ സന്ദർശനത്തിനായി ഞങ്ങൾ കാത്തിരിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}،
تمت إعادة جدولة موعدك إلى {{text}}.

الخدمة: {{text}}
رقم التأكيد: {{text}}

نتطلع إلى زيارتك.',
        'hi' => 'नमस्ते {{text}},
आपका अपॉइंटमेंट {{text}} के लिए पुनर्निर्धारित किया गया है।

सेवा: {{text}}
पुष्टि संख्या: {{text}}

हम आपकी यात्रा की प्रतीक्षा कर रहे हैं।',
    ],
    'appointment_scheduling' => [
        'en' => 'Hi {{text}}, we\'re scheduling a technician visit for your {{text}} on {{date}} between {{text}} and {{text}}. Please confirm if this time slot works for you.',
        'ml' => 'ഹായ് {{text}}, {{date}} ന് {{text}} നും {{text}} നും ഇടയിൽ നിങ്ങളുടെ {{text}} നായി ഞങ്ങൾ ഒരു ടെക്നീഷ്യൻ സന്ദർശനം ഷെഡ്യൂൾ ചെയ്യുന്നു. ഈ സമയം നിങ്ങൾക്ക് അനുയോജ്യമാണോ എന്ന് സ്ഥിരീകരിക്കുക.',
        'ar' => 'مرحبا {{text}}، نقوم بجدولة زيارة فني لـ {{text}} الخاص بك في {{date}} بين {{text}} و {{text}}. يرجى التأكيد إذا كان هذا الموعد مناسبا لك.',
        'hi' => 'नमस्ते {{text}}, हम {{date}} को {{text}} और {{text}} के बीच आपके {{text}} के लिए एक तकनीशियन विज़िट निर्धारित कर रहे हैं। कृपया पुष्टि करें कि क्या यह समय आपके लिए उपयुक्त है।',
    ],
    'appointment_scheduling_address' => [
        'en' => 'Hi {{text}}, we\'re scheduling a technician visit to {{address}} on {{date}} between {{text}} and {{text}}. Please confirm if this time slot works for you.',
        'ml' => 'ഹായ് {{text}}, {{date}} ന് {{text}} നും {{text}} നും ഇടയിൽ {{address}} ലേക്ക് ഞങ്ങൾ ഒരു ടെക്നീഷ്യൻ സന്ദർശനം ഷെഡ്യൂൾ ചെയ്യുന്നു. ഈ സമയം നിങ്ങൾക്ക് അനുയോജ്യമാണോ എന്ന് സ്ഥിരീകരിക്കുക.',
        'ar' => 'مرحبا {{text}}، نقوم بجدولة زيارة فني إلى {{address}} في {{date}} بين {{text}} و {{text}}. يرجى التأكيد إذا كان هذا الموعد مناسبا لك.',
        'hi' => 'नमस्ते {{text}}, हम {{date}} को {{text}} और {{text}} के बीच {{address}} पर एक तकनीशियन विज़िट निर्धारित कर रहे हैं। कृपया पुष्टि करें कि क्या यह समय आपके लिए उपयुक्त है।',
    ],
    'auto_pay_reminder_1' => [
        'en' => 'Hi {{text}},

Your automatic payment for {{text}} is scheduled on {{date}} for {{amount}}.

Kindly ensure your balance is sufficient to avoid {{text}} fees.',
        'ml' => 'ഹായ് {{text}},

{{text}} നുള്ള നിങ്ങളുടെ ഓട്ടോമാറ്റിക് പേയ്മെന്റ് {{date}} ന് {{amount}} ന് ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു.

{{text}} ഫീസ് ഒഴിവാക്കാൻ നിങ്ങളുടെ ബാലൻസ് മതിയായതാണെന്ന് ഉറപ്പാക്കുക.',
        'ar' => 'مرحبا {{text}}،

تمت جدولة الدفع التلقائي لـ {{text}} في {{date}} بمبلغ {{amount}}.

يرجى التأكد من أن رصيدك كافٍ لتجنب رسوم {{text}}.',
        'hi' => 'नमस्ते {{text}},

{{text}} के लिए आपका स्वचालित भुगतान {{date}} को {{amount}} के लिए निर्धारित है।

कृपया सुनिश्चित करें कि {{text}} शुल्क से बचने के लिए आपका बैलेंस पर्याप्त है।',
    ],
    'auto_pay_reminder_2' => [
        'en' => 'Hi {{text}}, this is to remind you of your upcoming auto-pay:

Date: {{date}}
Account: {{text}}
Amount: {{amount}}

Thank you and have a nice day.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ വരാനിരിക്കുന്ന ഓട്ടോ-പേ ഓർമ്മിപ്പിക്കാനാണിത്:

തീയതി: {{date}}
അക്കൗണ്ട്: {{text}}
തുക: {{amount}}

നന്ദി, നല്ലൊരു ദിവസം ആശംസിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}، هذا لتذكيرك بالدفع التلقائي القادم:

التاريخ: {{date}}
الحساب: {{text}}
المبلغ: {{amount}}

شكرا لك ويوم سعيد.',
        'hi' => 'नमस्ते {{text}}, यह आपको आपके आगामी ऑटो-पे की याद दिलाने के लिए है:

तिथि: {{date}}
खाता: {{text}}
राशि: {{amount}}

धन्यवाद और आपका दिन शुभ हो।',
    ],
    'auto_pay_reminder_3' => [
        'en' => 'Reminder:

Your scheduled payment for your {{text}} card ending in {{number}} is scheduled for {{date}}.

Thank you.',
        'ml' => 'ഓർമ്മപ്പെടുത്തൽ:

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള നിങ്ങളുടെ ഷെഡ്യൂൾ ചെയ്ത പേയ്മെന്റ് {{date}} ന് ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു.

നന്ദി.',
        'ar' => 'تذكير:

تمت جدولة الدفعة المقررة لبطاقتك {{text}} المنتهية بـ {{number}} في {{date}}.

شكرا لك.',
        'hi' => 'अनुस्मारक:

{{number}} में समाप्त होने वाले आपके {{text}} कार्ड के लिए आपका निर्धारित भुगतान {{date}} के लिए निर्धारित है।

धन्यवाद।',
    ],
    'call_permission_request_1' => [
        'en' => 'Would you like to receive a call from one of our representatives?

You can update your preference at any time in the business profile.',
        'ml' => 'ഞങ്ങളുടെ പ്രതിനിധികളിൽ ഒരാളിൽ നിന്ന് ഒരു കോൾ സ്വീകരിക്കാൻ നിങ്ങൾ ആഗ്രഹിക്കുന്നുണ്ടോ?

ബിസിനസ് പ്രൊഫൈലിൽ എപ്പോൾ വേണമെങ്കിലും നിങ്ങളുടെ മുൻഗണന അപ്ഡേറ്റ് ചെയ്യാം.',
        'ar' => 'هل ترغب في تلقي مكالمة من أحد ممثلينا؟

يمكنك تحديث تفضيلك في أي وقت في ملف العمل التجاري.',
        'hi' => 'क्या आप हमारे किसी प्रतिनिधि से कॉल प्राप्त करना चाहेंगे?

आप व्यवसाय प्रोफ़ाइल में किसी भी समय अपनी पसंद अपडेट कर सकते हैं।',
    ],
    'cancellation_confirmation' => [
        'en' => 'Hi {{text}}, we\'ve received a cancellation request for your {{text}}. Reply with YES to be assigned a new agent or NO to cancel.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} നായി ഒരു റദ്ദാക്കൽ അഭ്യർത്ഥന ഞങ്ങൾക്ക് ലഭിച്ചു. ഒരു പുതിയ ഏജന്റിനെ നിയോഗിക്കാൻ YES എന്നും റദ്ദാക്കാൻ NO എന്നും മറുപടി നൽകുക.',
        'ar' => 'مرحبا {{text}}، لقد استلمنا طلب إلغاء لـ {{text}} الخاص بك. أرسل YES لتعيين وكيل جديد أو NO للإلغاء.',
        'hi' => 'नमस्ते {{text}}, हमें आपके {{text}} के लिए एक रद्दीकरण अनुरोध प्राप्त हुआ है। नया एजेंट नियुक्त करने के लिए YES या रद्द करने के लिए NO के साथ उत्तर दें।',
    ],
    'card_transaction_alert_1' => [
        'en' => 'This is to notify you that your {{text}} card ending in {{number}} was charged {{amount}} by {{text}}.',
        'ml' => '{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിൽ {{text}} {{amount}} ഈടാക്കി എന്ന് അറിയിക്കാനാണിത്.',
        'ar' => 'هذا لإعلامك بأن بطاقتك {{text}} المنتهية بـ {{number}} تم خصم {{amount}} منها بواسطة {{text}}.',
        'hi' => 'यह आपको सूचित करने के लिए है कि {{number}} में समाप्त होने वाले आपके {{text}} कार्ड से {{text}} द्वारा {{amount}} शुल्क लिया गया।',
    ],
    'card_transaction_alert_2' => [
        'en' => 'Thank you for using your {{text}} card. This confirms your purchase on {{date}} for {{amount}} at {{text}}.',
        'ml' => 'നിങ്ങളുടെ {{text}} കാർഡ് ഉപയോഗിച്ചതിന് നന്ദി. {{date}} ന് {{text}} ൽ {{amount}} നുള്ള നിങ്ങളുടെ വാങ്ങൽ ഇത് സ്ഥിരീകരിക്കുന്നു.',
        'ar' => 'شكرا لاستخدامك بطاقة {{text}}. هذا يؤكد عملية الشراء في {{date}} بمبلغ {{amount}} في {{text}}.',
        'hi' => 'अपने {{text}} कार्ड का उपयोग करने के लिए धन्यवाद। यह {{date}} को {{text}} पर {{amount}} की आपकी खरीद की पुष्टि करता है।',
    ],
    'crisis_response_1' => [
        'en' => 'We activated support services for the {{text}} in the {{text}} area. Please take the following precautions if you live in {{text}} or surrounding areas: {{text}}, {{text}}, {{text}}.',
        'ml' => '{{text}} ഏരിയയിലെ {{text}} നായി ഞങ്ങൾ പിന്തുണാ സേവനങ്ങൾ സജീവമാക്കി. നിങ്ങൾ {{text}} ലോ പരിസര പ്രദേശങ്ങളിലോ താമസിക്കുന്നുവെങ്കിൽ ഇനിപ്പറയുന്ന മുൻകരുതലുകൾ എടുക്കുക: {{text}}, {{text}}, {{text}}.',
        'ar' => 'قمنا بتفعيل خدمات الدعم لـ {{text}} في منطقة {{text}}. يرجى اتخاذ الاحتياطات التالية إذا كنت تعيش في {{text}} أو المناطق المحيطة: {{text}}، {{text}}، {{text}}.',
        'hi' => 'हमने {{text}} क्षेत्र में {{text}} के लिए सहायता सेवाएं सक्रिय कीं। यदि आप {{text}} या आसपास के क्षेत्रों में रहते हैं तो कृपया निम्नलिखित सावधानियां बरतें: {{text}}, {{text}}, {{text}}।',
    ],
    'crisis_response_2' => [
        'en' => 'There is an active {{text}} in the {{text}} area. For live updates on the {{text}} alert in the {{text}} area, visit our website {{URL}}.',
        'ml' => '{{text}} ഏരിയയിൽ സജീവമായ ഒരു {{text}} ഉണ്ട്. {{text}} ഏരിയയിലെ {{text}} അലേർട്ടിനെക്കുറിച്ചുള്ള തത്സമയ അപ്ഡേറ്റുകൾക്ക്, ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} സന്ദർശിക്കുക.',
        'ar' => 'يوجد {{text}} نشط في منطقة {{text}}. للحصول على تحديثات مباشرة حول تنبيه {{text}} في منطقة {{text}}، تفضل بزيارة موقعنا {{URL}}.',
        'hi' => '{{text}} क्षेत्र में एक सक्रिय {{text}} है। {{text}} क्षेत्र में {{text}} अलर्ट पर लाइव अपडेट के लिए, हमारी वेबसाइट {{URL}} पर जाएं।',
    ],
    'delivery_confirmation_1' => [
        'en' => 'Hi {{text}}, your order {{text}} was delivered successfully.

You can manage your order below.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} വിജയകരമായി എത്തിച്ചു.

നിങ്ങളുടെ ഓർഡർ താഴെ കൈകാര്യം ചെയ്യാം.',
        'ar' => 'مرحبا {{text}}، تم تسليم طلبك {{text}} بنجاح.

يمكنك إدارة طلبك أدناه.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर {{text}} सफलतापूर्वक डिलीवर कर दिया गया।

आप नीचे अपना ऑर्डर प्रबंधित कर सकते हैं।',
    ],
    'delivery_confirmation_2' => [
        'en' => 'Hi {{text}}, your order {{text}} was delivered.

Need to return or replace an item?
Click to manage your order.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} എത്തിച്ചു.

ഒരു ഇനം തിരികെ നൽകുകയോ മാറ്റുകയോ ചെയ്യണോ?
നിങ്ങളുടെ ഓർഡർ കൈകാര്യം ചെയ്യാൻ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، تم تسليم طلبك {{text}}.

هل تحتاج إلى إرجاع أو استبدال عنصر؟
انقر لإدارة طلبك.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर {{text}} डिलीवर कर दिया गया।

किसी वस्तु को वापस करना या बदलना है?
अपना ऑर्डर प्रबंधित करने के लिए क्लिक करें।',
    ],
    'delivery_confirmation_3' => [
        'en' => '{{text}}, your order {{text}} was delivered on {{date}}.

If you need to return or replace item(s), please click below.',
        'ml' => '{{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} {{date}} ന് എത്തിച്ചു.

ഇനം(ങ്ങൾ) തിരികെ നൽകുകയോ മാറ്റുകയോ ചെയ്യണമെങ്കിൽ, ദയവായി താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => '{{text}}، تم تسليم طلبك {{text}} في {{date}}.

إذا كنت بحاجة إلى إرجاع أو استبدال العنصر (العناصر)، يرجى النقر أدناه.',
        'hi' => '{{text}}, आपका ऑर्डर {{text}} {{date}} को डिलीवर किया गया।

यदि आपको वस्तु(एं) वापस करने या बदलने की आवश्यकता है, तो कृपया नीचे क्लिक करें।',
    ],
    'delivery_confirmation_4' => [
        'en' => '{{text}}, your order was successfully delivered on {{date}}.

Thank you for your purchase.',
        'ml' => '{{text}}, നിങ്ങളുടെ ഓർഡർ {{date}} ന് വിജയകരമായി എത്തിച്ചു.

നിങ്ങളുടെ വാങ്ങലിന് നന്ദി.',
        'ar' => '{{text}}، تم تسليم طلبك بنجاح في {{date}}.

شكرا لشرائك.',
        'hi' => '{{text}}, आपका ऑर्डर {{date}} को सफलतापूर्वक डिलीवर किया गया।

आपकी खरीद के लिए धन्यवाद।',
    ],
    'delivery_confirmation_5' => [
        'en' => 'Hi {{text}},

Great news! Your order {{text}} was delivered.',
        'ml' => 'ഹായ് {{text}},

സന്തോഷവാർത്ത! നിങ്ങളുടെ ഓർഡർ {{text}} എത്തിച്ചു.',
        'ar' => 'مرحبا {{text}}،

أخبار رائعة! تم تسليم طلبك {{text}}.',
        'hi' => 'नमस्ते {{text}},

बढ़िया खबर! आपका ऑर्डर {{text}} डिलीवर कर दिया गया।',
    ],
    'delivery_failed_1' => [
        'en' => 'Hi {{text}},

We attempted to deliver your order on {{date}} but were not successful.

Please contact us at {{phone}} to arrange re-delivery.

Thank you.',
        'ml' => 'ഹായ് {{text}},

{{date}} ന് നിങ്ങളുടെ ഓർഡർ എത്തിക്കാൻ ഞങ്ങൾ ശ്രമിച്ചെങ്കിലും വിജയിച്ചില്ല.

വീണ്ടും ഡെലിവറി ക്രമീകരിക്കാൻ {{phone}} എന്ന നമ്പറിൽ ഞങ്ങളെ ബന്ധപ്പെടുക.

നന്ദി.',
        'ar' => 'مرحبا {{text}}،

حاولنا تسليم طلبك في {{date}} ولكن لم ننجح.

يرجى الاتصال بنا على {{phone}} لترتيب إعادة التسليم.

شكرا لك.',
        'hi' => 'नमस्ते {{text}},

हमने {{date}} को आपका ऑर्डर डिलीवर करने का प्रयास किया लेकिन सफल नहीं हुए।

पुनः डिलीवरी की व्यवस्था करने के लिए कृपया {{phone}} पर हमसे संपर्क करें।

धन्यवाद।',
    ],
    'delivery_failed_2' => [
        'en' => 'We were unable to deliver order {{text}} today.

Please {{text}} to schedule another delivery attempt.',
        'ml' => 'ഇന്ന് {{text}} ഓർഡർ എത്തിക്കാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല.

മറ്റൊരു ഡെലിവറി ശ്രമം ഷെഡ്യൂൾ ചെയ്യാൻ ദയവായി {{text}}.',
        'ar' => 'لم نتمكن من تسليم الطلب {{text}} اليوم.

يرجى {{text}} لجدولة محاولة تسليم أخرى.',
        'hi' => 'हम आज ऑर्डर {{text}} डिलीवर करने में असमर्थ रहे।

एक और डिलीवरी प्रयास निर्धारित करने के लिए कृपया {{text}}।',
    ],
    'delivery_failed_form_1' => [
        'en' => 'We were unable to deliver order {{text}} today.

Please {{text}} to schedule another delivery attempt.',
        'ml' => 'ഇന്ന് {{text}} ഓർഡർ എത്തിക്കാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല.

മറ്റൊരു ഡെലിവറി ശ്രമം ഷെഡ്യൂൾ ചെയ്യാൻ ദയവായി {{text}}.',
        'ar' => 'لم نتمكن من تسليم الطلب {{text}} اليوم.

يرجى {{text}} لجدولة محاولة تسليم أخرى.',
        'hi' => 'हम आज ऑर्डर {{text}} डिलीवर करने में असमर्थ रहे।

एक और डिलीवरी प्रयास निर्धारित करने के लिए कृपया {{text}}।',
    ],
    'delivery_update_1' => [
        'en' => 'Hi {{text}}, your order {{text}} is on its way and should arrive soon.

Estimated delivery:  {{text}}

We will provide an update when your order is delivered.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} വരുന്നു, ഉടൻ എത്തും.

ഏകദേശ ഡെലിവറി {{text}} ആണ്.

നിങ്ങളുടെ ഓർഡർ എത്തിക്കുമ്പോൾ ഞങ്ങൾ ഒരു അപ്ഡേറ്റ് നൽകും.',
        'ar' => 'مرحبا {{text}}، طلبك {{text}} في طريقه إليك وسيصل قريبا.

التسليم المتوقع هو {{text}}.

سنقدم تحديثا عند تسليم طلبك.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर {{text}} रास्ते में है और जल्द ही आ जाना चाहिए।

अनुमानित डिलीवरी {{text}} है।

जब आपका ऑर्डर डिलीवर हो जाएगा तो हम एक अपडेट देंगे।',
    ],
    'delivery_update_2' => [
        'en' => 'Hi {{text}}, our order {{text}} is out for delivery!

It should be delivered {{text}} between {{text}} and {{text}}.',
        'ml' => 'ഹായ് {{text}}, നമ്മുടെ ഓർഡർ {{text}} ഡെലിവറിക്ക് പുറപ്പെട്ടു!

ഇത് {{text}} ന് {{text}} നും {{text}} നും ഇടയിൽ എത്തിക്കണം.',
        'ar' => 'مرحبا {{text}}، طلبنا {{text}} خرج للتسليم!

من المفترض أن يتم تسليمه {{text}} بين {{text}} و {{text}}.',
        'hi' => 'नमस्ते {{text}}, हमारा ऑर्डर {{text}} डिलीवरी के लिए निकल चुका है!

इसे {{text}} को {{text}} और {{text}} के बीच डिलीवर किया जाना चाहिए।',
    ],
    'delivery_update_3' => [
        'en' => 'Your order {{text}} is out for delivery!

It should arrive by {{date}}.

Thank you for your business.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ {{text}} ഡെലിവറിക്ക് പുറപ്പെട്ടു!

ഇത് {{date}} നകം എത്തണം.

നിങ്ങളുടെ ബിസിനസിന് നന്ദി.',
        'ar' => 'طلبك {{text}} خرج للتسليم!

من المفترض أن يصل بحلول {{date}}.

شكرا لتعاملك معنا.',
        'hi' => 'आपका ऑर्डर {{text}} डिलीवरी के लिए निकल चुका है!

यह {{date}} तक आ जाना चाहिए।

आपके व्यापार के लिए धन्यवाद।',
    ],
    'delivery_update_4' => [
        'en' => 'Your order {{text}} is out for delivery and is expected to arrive by {{date}}.

Thank you for your business.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ {{text}} ഡെലിവറിക്ക് പുറപ്പെട്ടു, {{date}} നകം എത്തുമെന്ന് പ്രതീക്ഷിക്കുന്നു.

നിങ്ങളുടെ ബിസിനസിന് നന്ദി.',
        'ar' => 'طلبك {{text}} خرج للتسليم ومن المتوقع أن يصل بحلول {{date}}.

شكرا لتعاملك معنا.',
        'hi' => 'आपका ऑर्डर {{text}} डिलीवरी के लिए निकल चुका है और {{date}} तक आने की उम्मीद है।

आपके व्यापार के लिए धन्यवाद।',
    ],
    'device_recovery' => [
        'en' => 'Hi {{text}}, your broadband connection has been disconnected. To return your device, please follow these steps:
{{text}}
You can also contact us at {{text}} for assistance.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ബ്രോഡ്ബാൻഡ് കണക്ഷൻ വിച്ഛേദിച്ചു. നിങ്ങളുടെ ഉപകരണം തിരികെ നൽകാൻ, ഈ ഘട്ടങ്ങൾ പിന്തുടരുക:
{{text}}
സഹായത്തിനായി നിങ്ങൾക്ക് {{text}} എന്ന നമ്പറിലും ഞങ്ങളെ ബന്ധപ്പെടാം.',
        'ar' => 'مرحبا {{text}}، تم قطع اتصال النطاق العريض الخاص بك. لإرجاع جهازك، يرجى اتباع الخطوات التالية:
{{text}}
يمكنك أيضا الاتصال بنا على {{text}} للحصول على المساعدة.',
        'hi' => 'नमस्ते {{text}}, आपका ब्रॉडबैंड कनेक्शन डिस्कनेक्ट कर दिया गया है। अपना डिवाइस वापस करने के लिए, कृपया इन चरणों का पालन करें:
{{text}}
आप सहायता के लिए {{text}} पर भी हमसे संपर्क कर सकते हैं।',
    ],
    'disbursement_balance_1' => [
        'en' => 'Your {{text}} disbursement balance is {{amount}}. Kindly note it will expire on {{date}}. New {{text}} disbursements will be announced on a monthly basis. Use the URL below to review the disbursement schedule and to register or change your enrollment status.',
        'ml' => 'നിങ്ങളുടെ {{text}} വിതരണ ബാലൻസ് {{amount}} ആണ്. ഇത് {{date}} ന് കാലഹരണപ്പെടും എന്ന് ശ്രദ്ധിക്കുക. പുതിയ {{text}} വിതരണങ്ങൾ പ്രതിമാസം പ്രഖ്യാപിക്കും. വിതരണ ഷെഡ്യൂൾ അവലോകനം ചെയ്യാനും നിങ്ങളുടെ എൻറോൾമെന്റ് സ്ഥിതി രജിസ്റ്റർ ചെയ്യാനോ മാറ്റാനോ താഴെയുള്ള URL ഉപയോഗിക്കുക.',
        'ar' => 'رصيد صرف {{text}} الخاص بك هو {{amount}}. يرجى ملاحظة أنه سينتهي في {{date}}. سيتم الإعلان عن عمليات صرف {{text}} الجديدة على أساس شهري. استخدم الرابط أدناه لمراجعة جدول الصرف ولتسجيل أو تغيير حالة تسجيلك.',
        'hi' => 'आपका {{text}} संवितरण शेष {{amount}} है। कृपया ध्यान दें कि यह {{date}} को समाप्त हो जाएगा। नए {{text}} संवितरण की घोषणा मासिक आधार पर की जाएगी। संवितरण अनुसूची की समीक्षा करने और अपनी नामांकन स्थिति को पंजीकृत करने या बदलने के लिए नीचे दिए गए URL का उपयोग करें।',
    ],
    'disbursement_voucher_1' => [
        'en' => '{{text}} vouchers are here! You are eligible to claim your voucher at {{text}} or online via our website {{URL}}. Vouchers are valid until {{date}}. Vouchers can be used at many locations. Refer to our website {{URL}} for a comprehensive list of all locations that accept vouchers.',
        'ml' => '{{text}} വൗച്ചറുകൾ എത്തി! {{text}} ലോ ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} വഴി ഓൺലൈനിലോ നിങ്ങളുടെ വൗച്ചർ ക്ലെയിം ചെയ്യാൻ നിങ്ങൾ യോഗ്യനാണ്. വൗച്ചറുകൾ {{date}} വരെ സാധുതയുള്ളതാണ്. വൗച്ചറുകൾ പല സ്ഥലങ്ങളിലും ഉപയോഗിക്കാം. വൗച്ചറുകൾ സ്വീകരിക്കുന്ന എല്ലാ സ്ഥലങ്ങളുടെയും സമ്പൂർണ്ണ ലിസ്റ്റിനായി ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} കാണുക.',
        'ar' => 'قسائم {{text}} هنا! أنت مؤهل للمطالبة بقسيمتك في {{text}} أو عبر الإنترنت من خلال موقعنا {{URL}}. القسائم صالحة حتى {{date}}. يمكن استخدام القسائم في العديد من المواقع. راجع موقعنا {{URL}} للحصول على قائمة شاملة بجميع المواقع التي تقبل القسائم.',
        'hi' => '{{text}} वाउचर आ गए हैं! आप {{text}} पर या हमारी वेबसाइट {{URL}} के माध्यम से ऑनलाइन अपना वाउचर प्राप्त करने के पात्र हैं। वाउचर {{date}} तक वैध हैं। वाउचर कई स्थानों पर उपयोग किए जा सकते हैं। वाउचर स्वीकार करने वाले सभी स्थानों की विस्तृत सूची के लिए हमारी वेबसाइट {{URL}} देखें।',
    ],
    'event_details_reminder_1' => [
        'en' => 'Reminder: You RSVP’ed to {{text}} by {{text}}.

The event starts on {{date}} at {{text}} at {{address}} location.',
        'ml' => 'ഓർമ്മപ്പെടുത്തൽ: {{text}} വഴി നിങ്ങൾ {{text}} ലേക്ക് RSVP ചെയ്തു.

ഇവന്റ് {{date}} ന് {{text}} ന് {{address}} ലൊക്കേഷനിൽ ആരംഭിക്കുന്നു.',
        'ar' => 'تذكير: لقد أكدت حضورك إلى {{text}} بحلول {{text}}.

يبدأ الحدث في {{date}} الساعة {{text}} في موقع {{address}}.',
        'hi' => 'अनुस्मारक: आपने {{text}} तक {{text}} के लिए RSVP किया।

कार्यक्रम {{date}} को {{text}} बजे {{address}} स्थान पर शुरू होता है।',
    ],
    'event_details_reminder_2' => [
        'en' => 'Reminder: {{text}} is coming up and you have RSVP’ed to this event by {{text}}.

See you at {{text}} at {{text}} local time.',
        'ml' => 'ഓർമ്മപ്പെടുത്തൽ: {{text}} വരാൻ പോകുന്നു, {{text}} വഴി നിങ്ങൾ ഈ ഇവന്റിലേക്ക് RSVP ചെയ്തിട്ടുണ്ട്.

{{text}} പ്രാദേശിക സമയത്ത് {{text}} ൽ കാണാം.',
        'ar' => 'تذكير: {{text}} قادم وقد أكدت حضورك لهذا الحدث بحلول {{text}}.

نراك في {{text}} في تمام الساعة {{text}} بالتوقيت المحلي.',
        'hi' => 'अनुस्मारक: {{text}} आने वाला है और आपने {{text}} तक इस कार्यक्रम के लिए RSVP किया है।

स्थानीय समयानुसार {{text}} बजे {{text}} पर मिलते हैं।',
    ],
    'event_rsvp_confirmation_1' => [
        'en' => 'Thank you for RSVP’ing to {{text}} by {{text}}.

See you on {{date}} at {{text}} local time.',
        'ml' => '{{text}} വഴി {{text}} ലേക്ക് RSVP ചെയ്തതിന് നന്ദി.

{{text}} പ്രാദേശിക സമയത്ത് {{text}} ന് കാണാം.',
        'ar' => 'شكرا لتأكيد حضورك إلى {{text}} بحلول {{text}}.

نراك في {{text}} في تمام الساعة {{text}} بالتوقيت المحلي.',
        'hi' => '{{text}} तक {{text}} के लिए RSVP करने के लिए धन्यवाद।

स्थानीय समयानुसार {{text}} बजे {{text}} को मिलते हैं।',
    ],
    'event_rsvp_confirmation_2' => [
        'en' => 'Your RSVP for {{text}} by {{text}} is confirmed!

Thanks!',
        'ml' => '{{text}} വഴി {{text}} നുള്ള നിങ്ങളുടെ RSVP സ്ഥിരീകരിച്ചു!

നന്ദി!',
        'ar' => 'تم تأكيد حضورك لـ {{text}} بحلول {{text}}!

شكرا!',
        'hi' => '{{text}} तक {{text}} के लिए आपका RSVP पुष्टि हो गया है!

धन्यवाद!',
    ],
    'feedback_collection' => [
        'en' => 'Hi {{text}}, the service request we completed on {{date}} is now closed. Please rate your experience from 1-5 and share any feedback to help us improve.',
        'ml' => 'ഹായ് {{text}}, {{date}} ന് ഞങ്ങൾ പൂർത്തിയാക്കിയ സേവന അഭ്യർത്ഥന ഇപ്പോൾ ക്ലോസ് ചെയ്തു. നിങ്ങളുടെ അനുഭവം 1-5 വരെ റേറ്റ് ചെയ്യുകയും ഞങ്ങളെ മെച്ചപ്പെടുത്താൻ സഹായിക്കുന്ന ഫീഡ്ബാക്ക് പങ്കിടുകയും ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، طلب الخدمة الذي أكملناه في {{date}} مغلق الآن. يرجى تقييم تجربتك من 1 إلى 5 ومشاركة أي ملاحظات لمساعدتنا على التحسين.',
        'hi' => 'नमस्ते {{text}}, {{date}} को हमने जो सेवा अनुरोध पूरा किया वह अब बंद हो गया है। कृपया अपने अनुभव को 1-5 तक रेट करें और हमें सुधारने में मदद करने के लिए कोई भी प्रतिक्रिया साझा करें।',
    ],
    'feedback_survey_1' => [
        'en' => 'Hi {{text}},

Thank you for your recent {{text}} on {{date}}.

We value your feedback and would appreciate you sharing more about your experience with us at the link below.

This should only take {{number}} minutes. We appreciate your time.',
        'ml' => 'ഹായ് {{text}},

{{date}} ന് നിങ്ങളുടെ സമീപകാല {{text}} ന് നന്ദി.

ഞങ്ങൾ നിങ്ങളുടെ ഫീഡ്ബാക്ക് വിലമതിക്കുന്നു, താഴെയുള്ള ലിങ്കിൽ ഞങ്ങളുമായുള്ള നിങ്ങളുടെ അനുഭവത്തെക്കുറിച്ച് കൂടുതൽ പങ്കിടുന്നത് ഞങ്ങൾ അഭിനന്ദിക്കുന്നു.

ഇതിന് {{number}} മിനിറ്റ് മാത്രമേ എടുക്കൂ. നിങ്ങളുടെ സമയത്തിന് നന്ദി.',
        'ar' => 'مرحبا {{text}}،

شكرا لـ {{text}} الأخير في {{date}}.

نحن نقدر ملاحظاتك ونرحب بمشاركتك المزيد عن تجربتك معنا عبر الرابط أدناه.

لن يستغرق هذا سوى {{number}} دقائق. نحن نقدر وقتك.',
        'hi' => 'नमस्ते {{text}},

{{date}} को आपके हाल के {{text}} के लिए धन्यवाद।

हम आपकी प्रतिक्रिया को महत्व देते हैं और नीचे दिए गए लिंक पर हमारे साथ आपके अनुभव के बारे में अधिक साझा करने की सराहना करेंगे।

इसमें केवल {{number}} मिनट लगने चाहिए। हम आपके समय की सराहना करते हैं।',
    ],
    'feedback_survey_2' => [
        'en' => 'Thank you for visiting us at {{address}} on {{date}}.

We value your feedback.

Please fill out this short survey to let us know how we can continue to improve.',
        'ml' => '{{date}} ന് {{address}} ൽ ഞങ്ങളെ സന്ദർശിച്ചതിന് നന്ദി.

ഞങ്ങൾ നിങ്ങളുടെ ഫീഡ്ബാക്ക് വിലമതിക്കുന്നു.

ഞങ്ങൾക്ക് എങ്ങനെ മെച്ചപ്പെടുത്തുന്നത് തുടരാം എന്ന് അറിയിക്കാൻ ഈ ചെറിയ സർവേ പൂരിപ്പിക്കുക.',
        'ar' => 'شكرا لزيارتك لنا في {{address}} في {{date}}.

نحن نقدر ملاحظاتك.

يرجى ملء هذا الاستطلاع القصير لإخبارنا بكيفية مواصلة التحسين.',
        'hi' => '{{date}} को {{address}} पर हमारे यहाँ आने के लिए धन्यवाद।

हम आपकी प्रतिक्रिया को महत्व देते हैं।

कृपया यह छोटा सर्वेक्षण भरें ताकि हमें बताएं कि हम कैसे सुधार करना जारी रख सकते हैं।',
    ],
    'feedback_survey_form_1' => [
        'en' => 'Your feedback is important to us.

Please take a quick survey about your recent {{text}} experience.',
        'ml' => 'നിങ്ങളുടെ ഫീഡ്ബാക്ക് ഞങ്ങൾക്ക് പ്രധാനമാണ്.

നിങ്ങളുടെ സമീപകാല {{text}} അനുഭവത്തെക്കുറിച്ച് ഒരു ദ്രുത സർവേ എടുക്കുക.',
        'ar' => 'ملاحظاتك مهمة بالنسبة لنا.

يرجى إجراء استطلاع سريع حول تجربة {{text}} الأخيرة.',
        'hi' => 'आपकी प्रतिक्रिया हमारे लिए महत्वपूर्ण है।

कृपया अपने हाल के {{text}} अनुभव के बारे में एक त्वरित सर्वेक्षण करें।',
    ],
    'feedback_survey_form_2' => [
        'en' => 'At {{text}}, we value customer feedback and use it to continually improve our {{text}}.

Please fill out a short {{text}}, linked below, to let us know more about your recent {{text}} with us.

Thank you in advance.',
        'ml' => '{{text}} ൽ, ഞങ്ങൾ ഉപഭോക്തൃ ഫീഡ്ബാക്ക് വിലമതിക്കുകയും ഞങ്ങളുടെ {{text}} തുടർച്ചയായി മെച്ചപ്പെടുത്താൻ അത് ഉപയോഗിക്കുകയും ചെയ്യുന്നു.

ഞങ്ങളുമായുള്ള നിങ്ങളുടെ സമീപകാല {{text}} നെക്കുറിച്ച് കൂടുതൽ അറിയിക്കാൻ താഴെ ലിങ്ക് ചെയ്ത ഒരു ചെറിയ {{text}} പൂരിപ്പിക്കുക.

മുൻകൂട്ടി നന്ദി.',
        'ar' => 'في {{text}}، نحن نقدر ملاحظات العملاء ونستخدمها لتحسين {{text}} الخاص بنا باستمرار.

يرجى ملء {{text}} قصير، المرتبط أدناه، لإخبارنا المزيد عن {{text}} الأخير معنا.

شكرا لك مقدما.',
        'hi' => '{{text}} में, हम ग्राहक प्रतिक्रिया को महत्व देते हैं और इसका उपयोग अपने {{text}} में लगातार सुधार करने के लिए करते हैं।

हमारे साथ अपने हाल के {{text}} के बारे में हमें और बताने के लिए कृपया नीचे लिंक किया गया एक छोटा {{text}} भरें।

अग्रिम धन्यवाद।',
    ],
    'followup_missed_calls' => [
        'en' => 'Hi {{text}}, we missed your call. Please let us know if you\'re available to reschedule.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ കോൾ ഞങ്ങൾക്ക് നഷ്ടമായി. വീണ്ടും ഷെഡ്യൂൾ ചെയ്യാൻ നിങ്ങൾ ലഭ്യമാണോ എന്ന് അറിയിക്കുക.',
        'ar' => 'مرحبا {{text}}، فاتتنا مكالمتك. يرجى إخبارنا إذا كنت متاحا لإعادة الجدولة.',
        'hi' => 'नमस्ते {{text}}, हमसे आपकी कॉल छूट गई। कृपया हमें बताएं कि क्या आप पुनर्निर्धारण के लिए उपलब्ध हैं।',
    ],
    'fraud_alert_1' => [
        'en' => 'Hello {{text}},

We noticed a {{text}} transaction on your {{text}} from {{text}} for {{amount}}.

If you didn\'t make this transaction, please call us {{text}} at {{phone}}.

You can also click below to freeze your {{text}}.

Thank you!',
        'ml' => 'ഹലോ {{text}},

{{amount}} ന് {{text}} ൽ നിന്ന് നിങ്ങളുടെ {{text}} ൽ ഒരു {{text}} ഇടപാട് ഞങ്ങൾ ശ്രദ്ധിച്ചു.

നിങ്ങൾ ഈ ഇടപാട് നടത്തിയില്ലെങ്കിൽ, {{phone}} എന്ന നമ്പറിൽ {{text}} ഞങ്ങളെ വിളിക്കുക.

നിങ്ങളുടെ {{text}} ഫ്രീസ് ചെയ്യാൻ താഴെ ക്ലിക്ക് ചെയ്യാം.

നന്ദി!',
        'ar' => 'مرحبا {{text}}،

لاحظنا معاملة {{text}} على {{text}} الخاص بك من {{text}} بمبلغ {{amount}}.

إذا لم تقم بهذه المعاملة، يرجى الاتصال بنا {{text}} على {{phone}}.

يمكنك أيضا النقر أدناه لتجميد {{text}} الخاص بك.

شكرا لك!',
        'hi' => 'नमस्ते {{text}},

हमने {{amount}} के लिए {{text}} से आपके {{text}} पर एक {{text}} लेनदेन देखा।

यदि आपने यह लेनदेन नहीं किया है, तो कृपया हमें {{phone}} पर {{text}} कॉल करें।

आप अपना {{text}} फ्रीज करने के लिए नीचे क्लिक भी कर सकते हैं।

धन्यवाद!',
    ],
    'fraud_alert_2' => [
        'en' => 'Hi {{text}},

This is {{text}}.

We identified a {{text}} transaction on your {{text}} card ending in {{number}}:

Date: {{date}}
Merchant: {{text}}
Amount: {{amount}}

Did you make this purchase?',
        'ml' => 'ഹായ് {{text}},

ഇത് {{text}} ആണ്.

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിൽ ഒരു {{text}} ഇടപാട് ഞങ്ങൾ കണ്ടെത്തി:

തീയതി: {{date}}
വ്യാപാരി: {{text}}
തുക: {{amount}}

നിങ്ങളാണോ ഈ വാങ്ങൽ നടത്തിയത്?',
        'ar' => 'مرحبا {{text}}،

معك {{text}}.

اكتشفنا معاملة {{text}} على بطاقتك {{text}} المنتهية بـ {{number}}:

التاريخ: {{date}}
التاجر: {{text}}
المبلغ: {{amount}}

هل قمت بهذا الشراء؟',
        'hi' => 'नमस्ते {{text}},

यह {{text}} है।

हमने {{number}} में समाप्त होने वाले आपके {{text}} कार्ड पर एक {{text}} लेनदेन की पहचान की:

तिथि: {{date}}
व्यापारी: {{text}}
राशि: {{amount}}

क्या आपने यह खरीद की?',
    ],
    'fraud_alert_3' => [
        'en' => 'Hi {{text}},

We noticed a {{text}} charge on your {{text}} account.

Please verify the details of this transaction.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ {{text}} അക്കൗണ്ടിൽ ഒരു {{text}} ചാർജ് ഞങ്ങൾ ശ്രദ്ധിച്ചു.

ഈ ഇടപാടിന്റെ വിശദാംശങ്ങൾ പരിശോധിച്ചുറപ്പിക്കുക.',
        'ar' => 'مرحبا {{text}}،

لاحظنا رسوم {{text}} على حساب {{text}} الخاص بك.

يرجى التحقق من تفاصيل هذه المعاملة.',
        'hi' => 'नमस्ते {{text}},

हमने आपके {{text}} खाते पर एक {{text}} शुल्क देखा।

कृपया इस लेनदेन के विवरण को सत्यापित करें।',
    ],
    'fraud_alert_4' => [
        'en' => 'Hello {{text}},

We detected a suspicious transaction on your {{text}} card ending in {{number}}.

Please verify if this was you.',
        'ml' => 'ഹലോ {{text}},

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിൽ സംശയാസ്പദമായ ഒരു ഇടപാട് ഞങ്ങൾ കണ്ടെത്തി.

ഇത് നിങ്ങളായിരുന്നോ എന്ന് പരിശോധിച്ചുറപ്പിക്കുക.',
        'ar' => 'مرحبا {{text}}،

اكتشفنا معاملة مشبوهة على بطاقتك {{text}} المنتهية بـ {{number}}.

يرجى التحقق مما إذا كان هذا أنت.',
        'hi' => 'नमस्ते {{text}},

हमने {{number}} में समाप्त होने वाले आपके {{text}} कार्ड पर एक संदिग्ध लेनदेन का पता लगाया।

कृपया सत्यापित करें कि क्या यह आप थे।',
    ],
    'fraud_awareness_1' => [
        'en' => 'We have detected an increase in {{text}}. To protect your card ending in {{card number}}, please consider updating your PIN. Click below to see the step-by-step. Do not click on unofficial links. {{business name}} will never send you SMS messages asking for your personal information or banking details.',
        'ml' => '{{text}} ൽ വർദ്ധനവ് ഞങ്ങൾ കണ്ടെത്തി. {{card number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ കാർഡ് സംരക്ഷിക്കാൻ, നിങ്ങളുടെ പിൻ അപ്ഡേറ്റ് ചെയ്യുന്നത് പരിഗണിക്കുക. ഘട്ടം ഘട്ടമായുള്ള നിർദ്ദേശങ്ങൾ കാണാൻ താഴെ ക്ലിക്ക് ചെയ്യുക. ഔദ്യോഗികമല്ലാത്ത ലിങ്കുകളിൽ ക്ലിക്ക് ചെയ്യരുത്. {{business name}} ഒരിക്കലും നിങ്ങളുടെ വ്യക്തിഗത വിവരങ്ങളോ ബാങ്കിംഗ് വിശദാംശങ്ങളോ ചോദിച്ചുകൊണ്ട് SMS സന്ദേശങ്ങൾ അയയ്ക്കില്ല.',
        'ar' => 'لقد اكتشفنا زيادة في {{text}}. لحماية بطاقتك المنتهية بـ {{card number}}، يرجى التفكير في تحديث رقم التعريف الشخصي الخاص بك. انقر أدناه لمشاهدة الخطوات. لا تنقر على الروابط غير الرسمية. لن ترسل لك {{business name}} أبدا رسائل SMS تطلب معلوماتك الشخصية أو التفاصيل المصرفية.',
        'hi' => 'हमने {{text}} में वृद्धि का पता लगाया है। {{card number}} में समाप्त होने वाले अपने कार्ड की सुरक्षा के लिए, कृपया अपना पिन अपडेट करने पर विचार करें। चरण-दर-चरण देखने के लिए नीचे क्लिक करें। अनौपचारिक लिंक पर क्लिक न करें। {{business name}} आपको कभी भी आपकी व्यक्तिगत जानकारी या बैंकिंग विवरण मांगने वाले SMS संदेश नहीं भेजेगा।',
    ],
    'group_invite_link' => [
        'en' => 'Hi {{text}}, your request for {{text}} service from {{text}} was successfully received!

You can start the service by clicking and joining the group below.
{{group_id}}

Thank you!',
        'ml' => 'ഹായ് {{text}}, {{text}} ൽ നിന്നുള്ള {{text}} സേവനത്തിനുള്ള നിങ്ങളുടെ അഭ്യർത്ഥന വിജയകരമായി ലഭിച്ചു!

താഴെയുള്ള ഗ്രൂപ്പിൽ ക്ലിക്ക് ചെയ്ത് ചേർന്ന് നിങ്ങൾക്ക് സേവനം ആരംഭിക്കാം.
{{group_id}}

നന്ദി!',
        'ar' => 'مرحبا {{text}}، تم استلام طلبك لخدمة {{text}} من {{text}} بنجاح!

يمكنك بدء الخدمة بالنقر والانضمام إلى المجموعة أدناه.
{{group_id}}

شكرا لك!',
        'hi' => 'नमस्ते {{text}}, {{text}} से {{text}} सेवा के लिए आपका अनुरोध सफलतापूर्वक प्राप्त हो गया!

आप नीचे दिए गए समूह पर क्लिक करके और उसमें शामिल होकर सेवा शुरू कर सकते हैं।
{{group_id}}

धन्यवाद!',
    ],
    'group_invite_link_concise' => [
        'en' => 'Your {{text}} request with {{text}} is confirmed. Please join the WhatsApp group to start:
{{group_id}} Thank you!',
        'ml' => '{{text}} യുമായുള്ള നിങ്ങളുടെ {{text}} അഭ്യർത്ഥന സ്ഥിരീകരിച്ചു. ആരംഭിക്കാൻ WhatsApp ഗ്രൂപ്പിൽ ചേരുക:
{{group_id}} നന്ദി!',
        'ar' => 'تم تأكيد طلب {{text}} الخاص بك مع {{text}}. يرجى الانضمام إلى مجموعة WhatsApp للبدء:
{{group_id}} شكرا لك!',
        'hi' => '{{text}} के साथ आपका {{text}} अनुरोध पुष्टि हो गया है। शुरू करने के लिए कृपया WhatsApp समूह में शामिल हों:
{{group_id}} धन्यवाद!',
    ],
    'group_invite_link_detailed' => [
        'en' => 'Hi {{text}},
We are pleased to inform you that your request for {{text}} from {{text}} has been successfully received.

To facilitate your session, we have created a dedicated WhatsApp group. Please join the group using the link below to proceed with your request:
{{group_id}}

Thank you for using our service!',
        'ml' => 'ഹായ് {{text}},
{{text}} ൽ നിന്നുള്ള {{text}} നുള്ള നിങ്ങളുടെ അഭ്യർത്ഥന വിജയകരമായി ലഭിച്ചു എന്ന് അറിയിക്കുന്നതിൽ ഞങ്ങൾക്ക് സന്തോഷമുണ്ട്.

നിങ്ങളുടെ സെഷൻ സുഗമമാക്കാൻ, ഞങ്ങൾ ഒരു പ്രത്യേക WhatsApp ഗ്രൂപ്പ് സൃഷ്ടിച്ചു. നിങ്ങളുടെ അഭ്യർത്ഥനയുമായി മുന്നോട്ട് പോകാൻ താഴെയുള്ള ലിങ്ക് ഉപയോഗിച്ച് ഗ്രൂപ്പിൽ ചേരുക:
{{group_id}}

ഞങ്ങളുടെ സേവനം ഉപയോഗിച്ചതിന് നന്ദി!',
        'ar' => 'مرحبا {{text}}،
يسعدنا إبلاغك بأنه تم استلام طلبك لـ {{text}} من {{text}} بنجاح.

لتسهيل جلستك، أنشأنا مجموعة WhatsApp مخصصة. يرجى الانضمام إلى المجموعة باستخدام الرابط أدناه لمتابعة طلبك:
{{group_id}}

شكرا لاستخدامك خدمتنا!',
        'hi' => 'नमस्ते {{text}},
हमें आपको यह सूचित करते हुए खुशी हो रही है कि {{text}} से {{text}} के लिए आपका अनुरोध सफलतापूर्वक प्राप्त हो गया है।

आपके सत्र को सुविधाजनक बनाने के लिए, हमने एक समर्पित WhatsApp समूह बनाया है। अपने अनुरोध के साथ आगे बढ़ने के लिए कृपया नीचे दिए गए लिंक का उपयोग करके समूह में शामिल हों:
{{group_id}}

हमारी सेवा का उपयोग करने के लिए धन्यवाद!',
    ],
    'health_awareness_1' => [
        'en' => 'Stay up-to-date with your health. Stop by {{text}} by {{date}} to get your free {{text}}. Bring your {{text}} and {{text}}.',
        'ml' => 'നിങ്ങളുടെ ആരോഗ്യം കാലികമായി സൂക്ഷിക്കുക. നിങ്ങളുടെ സൗജന്യ {{text}} ലഭിക്കാൻ {{date}} നകം {{text}} ൽ എത്തുക. നിങ്ങളുടെ {{text}} ഉം {{text}} ഉം കൊണ്ടുവരിക.',
        'ar' => 'ابق على اطلاع بصحتك. توقف عند {{text}} بحلول {{date}} للحصول على {{text}} المجاني الخاص بك. أحضر {{text}} و {{text}} الخاص بك.',
        'hi' => 'अपने स्वास्थ्य के साथ अद्यतित रहें। अपना मुफ्त {{text}} पाने के लिए {{date}} तक {{text}} पर आएं। अपना {{text}} और {{text}} लाएं।',
    ],
    'health_emergency_1' => [
        'en' => 'The {{text}} has just declared a health emergency due to {{text}}. To learn more about {{text}} and precautions to take, use the URL below. We will follow up with more details once available.',
        'ml' => '{{text}} കാരണം {{text}} ഒരു ആരോഗ്യ അടിയന്തരാവസ്ഥ പ്രഖ്യാപിച്ചു. {{text}} നെക്കുറിച്ചും എടുക്കേണ്ട മുൻകരുതലുകളെക്കുറിച്ചും കൂടുതലറിയാൻ, താഴെയുള്ള URL ഉപയോഗിക്കുക. ലഭ്യമാകുമ്പോൾ ഞങ്ങൾ കൂടുതൽ വിവരങ്ങൾ നൽകും.',
        'ar' => 'أعلنت {{text}} للتو حالة طوارئ صحية بسبب {{text}}. لمعرفة المزيد عن {{text}} والاحتياطات الواجب اتخاذها، استخدم الرابط أدناه. سنتابع بمزيد من التفاصيل بمجرد توفرها.',
        'hi' => '{{text}} ने {{text}} के कारण अभी-अभी स्वास्थ्य आपातकाल घोषित किया है। {{text}} और बरती जाने वाली सावधानियों के बारे में अधिक जानने के लिए, नीचे दिए गए URL का उपयोग करें। उपलब्ध होने पर हम अधिक विवरण के साथ फॉलो अप करेंगे।',
    ],
    'health_emergency_2' => [
        'en' => 'A health emergency due to {{text}} has been declared in the {{text}} area. Live updates available on our website {{URL}}.',
        'ml' => '{{text}} കാരണം {{text}} ഏരിയയിൽ ഒരു ആരോഗ്യ അടിയന്തരാവസ്ഥ പ്രഖ്യാപിച്ചു. ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} ൽ തത്സമയ അപ്ഡേറ്റുകൾ ലഭ്യമാണ്.',
        'ar' => 'تم إعلان حالة طوارئ صحية بسبب {{text}} في منطقة {{text}}. التحديثات المباشرة متاحة على موقعنا {{URL}}.',
        'hi' => '{{text}} के कारण {{text}} क्षेत्र में स्वास्थ्य आपातकाल घोषित किया गया है। हमारी वेबसाइट {{URL}} पर लाइव अपडेट उपलब्ध हैं।',
    ],
    'identity_compliance_1' => [
        'en' => 'This is to notify you that you need to upgrade to a {{text}} by {{date}}. To avoid any inconveniences when travelling, please ensure you make an appointment at your local {{text}}. To find the office closest to you, use our website {{URL}}.',
        'ml' => '{{date}} നകം നിങ്ങൾ ഒരു {{text}} ലേക്ക് അപ്ഗ്രേഡ് ചെയ്യേണ്ടതുണ്ട് എന്ന് അറിയിക്കാനാണിത്. യാത്ര ചെയ്യുമ്പോൾ ബുദ്ധിമുട്ടുകൾ ഒഴിവാക്കാൻ, നിങ്ങളുടെ പ്രാദേശിക {{text}} ൽ ഒരു അപ്പോയിന്റ്മെന്റ് എടുക്കുന്നുവെന്ന് ഉറപ്പാക്കുക. നിങ്ങളോട് ഏറ്റവും അടുത്തുള്ള ഓഫീസ് കണ്ടെത്താൻ, ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} ഉപയോഗിക്കുക.',
        'ar' => 'هذا لإعلامك بأنك بحاجة إلى الترقية إلى {{text}} بحلول {{date}}. لتجنب أي إزعاج عند السفر، يرجى التأكد من حجز موعد في {{text}} المحلي الخاص بك. للعثور على أقرب مكتب إليك، استخدم موقعنا {{URL}}.',
        'hi' => 'यह आपको सूचित करने के लिए है कि आपको {{date}} तक {{text}} में अपग्रेड करना होगा। यात्रा करते समय किसी भी असुविधा से बचने के लिए, कृपया सुनिश्चित करें कि आप अपने स्थानीय {{text}} पर अपॉइंटमेंट लें। अपने निकटतम कार्यालय को खोजने के लिए, हमारी वेबसाइट {{URL}} का उपयोग करें।',
    ],
    'identity_compliance_2' => [
        'en' => 'Upgraded {{text}} are now required when traveling at airports. For more information on how to upgrade, use the link below.',
        'ml' => 'വിമാനത്താവളങ്ങളിൽ യാത്ര ചെയ്യുമ്പോൾ അപ്ഗ്രേഡ് ചെയ്ത {{text}} ഇപ്പോൾ ആവശ്യമാണ്. എങ്ങനെ അപ്ഗ്രേഡ് ചെയ്യാം എന്നതിനെക്കുറിച്ചുള്ള കൂടുതൽ വിവരങ്ങൾക്ക്, താഴെയുള്ള ലിങ്ക് ഉപയോഗിക്കുക.',
        'ar' => '{{text}} المطور مطلوب الآن عند السفر في المطارات. لمزيد من المعلومات حول كيفية الترقية، استخدم الرابط أدناه.',
        'hi' => 'हवाई अड्डों पर यात्रा करते समय अब अपग्रेड किए गए {{text}} की आवश्यकता होती है। अपग्रेड करने के तरीके के बारे में अधिक जानकारी के लिए, नीचे दिए गए लिंक का उपयोग करें।',
    ],
    'installation_complete' => [
        'en' => 'Hi {{text}}, your {{text}} installation is complete! Our technician has configured your connection, and you\'re now ready to go online. If you have any issues, feel free to reply or contact {{text}} for assistance.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} ഇൻസ്റ്റാളേഷൻ പൂർത്തിയായി! ഞങ്ങളുടെ ടെക്നീഷ്യൻ നിങ്ങളുടെ കണക്ഷൻ കോൺഫിഗർ ചെയ്തു, നിങ്ങൾ ഇപ്പോൾ ഓൺലൈനിൽ പോകാൻ തയ്യാറാണ്. നിങ്ങൾക്ക് എന്തെങ്കിലും പ്രശ്നങ്ങളുണ്ടെങ്കിൽ, മറുപടി നൽകുകയോ സഹായത്തിനായി {{text}} ബന്ധപ്പെടുകയോ ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، اكتمل تركيب {{text}} الخاص بك! قام الفني لدينا بتكوين اتصالك، وأنت الآن جاهز للاتصال بالإنترنت. إذا كانت لديك أي مشكلات، فلا تتردد في الرد أو الاتصال بـ {{text}} للحصول على المساعدة.',
        'hi' => 'नमस्ते {{text}}, आपका {{text}} इंस्टॉलेशन पूरा हो गया है! हमारे तकनीशियन ने आपका कनेक्शन कॉन्फ़िगर कर दिया है, और अब आप ऑनलाइन जाने के लिए तैयार हैं। यदि आपको कोई समस्या है, तो बेझिझक जवाब दें या सहायता के लिए {{text}} से संपर्क करें।',
    ],
    'low_balance_warning_1' => [
        'en' => 'Hi {{text}},

This is to notify you that your {{text}} in your {{text}} account ending in {{number}} is below your pre-set {{text}} of {{amount}}.

Click below to add funds or call us.',
        'ml' => 'ഹായ് {{text}},

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} അക്കൗണ്ടിലെ നിങ്ങളുടെ {{text}} {{amount}} എന്ന നിങ്ങളുടെ മുൻകൂട്ടി സജ്ജമാക്കിയ {{text}} നേക്കാൾ താഴെയാണ് എന്ന് അറിയിക്കാനാണിത്.

ഫണ്ട് ചേർക്കാൻ താഴെ ക്ലിക്ക് ചെയ്യുക അല്ലെങ്കിൽ ഞങ്ങളെ വിളിക്കുക.',
        'ar' => 'مرحبا {{text}}،

هذا لإعلامك بأن {{text}} الخاص بك في حساب {{text}} المنتهي بـ {{number}} أقل من {{text}} المحدد مسبقا وقدره {{amount}}.

انقر أدناه لإضافة الأموال أو اتصل بنا.',
        'hi' => 'नमस्ते {{text}},

यह आपको सूचित करने के लिए है कि {{number}} में समाप्त होने वाले आपके {{text}} खाते में आपका {{text}} {{amount}} की आपकी पूर्व-निर्धारित {{text}} से कम है।

धन जोड़ने के लिए नीचे क्लिक करें या हमें कॉल करें।',
    ],
    'low_balance_warning_2' => [
        'en' => 'Hi {{text}}, Available funds in your {{text}} account ending in {{number}} are below your pre-set {{amount}} limit.',
        'ml' => 'ഹായ് {{text}}, {{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} അക്കൗണ്ടിലെ ലഭ്യമായ ഫണ്ട് നിങ്ങളുടെ മുൻകൂട്ടി സജ്ജമാക്കിയ {{amount}} പരിധിയേക്കാൾ താഴെയാണ്.',
        'ar' => 'مرحبا {{text}}، الأموال المتاحة في حساب {{text}} الخاص بك المنتهي بـ {{number}} أقل من حد {{amount}} المحدد مسبقا.',
        'hi' => 'नमस्ते {{text}}, {{number}} में समाप्त होने वाले आपके {{text}} खाते में उपलब्ध धनराशि आपकी पूर्व-निर्धारित {{amount}} सीमा से कम है।',
    ],
    'low_balance_warning_3' => [
        'en' => 'Hi {{text}}, your mobile balance is {{amount}}. Please recharge to avoid interruption.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ മൊബൈൽ ബാലൻസ് {{amount}} ആണ്. തടസ്സം ഒഴിവാക്കാൻ ദയവായി റീചാർജ് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، رصيد هاتفك المحمول هو {{amount}}. يرجى إعادة الشحن لتجنب الانقطاع.',
        'hi' => 'नमस्ते {{text}}, आपका मोबाइल बैलेंस {{amount}} है। कृपया रुकावट से बचने के लिए रिचार्ज करें।',
    ],
    'missed_appointment' => [
        'en' => 'Hi {{text}}, we missed you at your scheduled {{text}} appointment on {{date}}. Please reply to reschedule or contact {{text}} to book a new appointment.',
        'ml' => 'ഹായ് {{text}}, {{date}} ന് നിങ്ങളുടെ ഷെഡ്യൂൾ ചെയ്ത {{text}} അപ്പോയിന്റ്മെന്റിൽ നിങ്ങളെ ഞങ്ങൾക്ക് നഷ്ടമായി. വീണ്ടും ഷെഡ്യൂൾ ചെയ്യാൻ മറുപടി നൽകുകയോ ഒരു പുതിയ അപ്പോയിന്റ്മെന്റ് ബുക്ക് ചെയ്യാൻ {{text}} ബന്ധപ്പെടുകയോ ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، افتقدناك في موعدك المحدد {{text}} في {{date}}. يرجى الرد لإعادة الجدولة أو الاتصال بـ {{text}} لحجز موعد جديد.',
        'hi' => 'नमस्ते {{text}}, {{date}} को आपके निर्धारित {{text}} अपॉइंटमेंट पर हमें आपकी कमी महसूस हुई। कृपया पुनर्निर्धारण के लिए उत्तर दें या नया अपॉइंटमेंट बुक करने के लिए {{text}} से संपर्क करें।',
    ],
    'network_troubleshooting' => [
        'en' => 'Hi, we understand you might be experiencing network troubles at {{text}}.
You can try these simple steps:
Step 1: {{text}},
Step 2: {{text}},
Step 3: {{text}}.
Need more help? Contact: {{text}} or view details.',
        'ml' => 'ഹായ്, {{text}} ൽ നിങ്ങൾക്ക് നെറ്റ്‌വർക്ക് പ്രശ്നങ്ങൾ അനുഭവപ്പെടുന്നുണ്ടാകാമെന്ന് ഞങ്ങൾക്ക് മനസ്സിലാകുന്നു.
നിങ്ങൾക്ക് ഈ ലളിതമായ ഘട്ടങ്ങൾ പരീക്ഷിക്കാം:
ഘട്ടം 1: {{text}},
ഘട്ടം 2: {{text}},
ഘട്ടം 3: {{text}}.
കൂടുതൽ സഹായം വേണോ? ബന്ധപ്പെടുക: {{text}} അല്ലെങ്കിൽ വിശദാംശങ്ങൾ കാണുക.',
        'ar' => 'مرحبا، نحن نتفهم أنك قد تواجه مشكلات في الشبكة في {{text}}.
يمكنك تجربة هذه الخطوات البسيطة:
الخطوة 1: {{text}}،
الخطوة 2: {{text}}،
الخطوة 3: {{text}}.
هل تحتاج إلى مزيد من المساعدة؟ اتصل بـ: {{text}} أو اعرض التفاصيل.',
        'hi' => 'नमस्ते, हम समझते हैं कि आपको {{text}} पर नेटवर्क समस्याओं का सामना करना पड़ सकता है।
आप इन सरल चरणों को आज़मा सकते हैं:
चरण 1: {{text}},
चरण 2: {{text}},
चरण 3: {{text}}.
अधिक मदद चाहिए? संपर्क करें: {{text}} या विवरण देखें।',
    ],
    'operation_disruption_1' => [
        'en' => 'This is to notify you that {{text}} at our {{text}} station are halted due to {{text}}. Please avoid the area as we work to rectify. Click the URL below to see alternate {{text}} and/or {{text}} where service is available and running. Live updates on our site, available below.',
        'ml' => '{{text}} കാരണം ഞങ്ങളുടെ {{text}} സ്റ്റേഷനിലെ {{text}} നിർത്തിവച്ചിരിക്കുന്നു എന്ന് അറിയിക്കാനാണിത്. ഞങ്ങൾ ശരിയാക്കാൻ പ്രവർത്തിക്കുന്നതിനാൽ ദയവായി പ്രദേശം ഒഴിവാക്കുക. സേവനം ലഭ്യമായതും പ്രവർത്തിക്കുന്നതുമായ ഇതര {{text}} കൂടാതെ/അല്ലെങ്കിൽ {{text}} കാണാൻ താഴെയുള്ള URL ക്ലിക്ക് ചെയ്യുക. ഞങ്ങളുടെ സൈറ്റിലെ തത്സമയ അപ്ഡേറ്റുകൾ, താഴെ ലഭ്യമാണ്.',
        'ar' => 'هذا لإعلامك بأن {{text}} في محطة {{text}} الخاصة بنا متوقفة بسبب {{text}}. يرجى تجنب المنطقة بينما نعمل على الإصلاح. انقر على الرابط أدناه لمشاهدة {{text}} و/أو {{text}} البديلة حيث تكون الخدمة متاحة وتعمل. التحديثات المباشرة على موقعنا، متاحة أدناه.',
        'hi' => 'यह आपको सूचित करने के लिए है कि {{text}} के कारण हमारे {{text}} स्टेशन पर {{text}} रुक गया है। जब हम सुधार के लिए काम कर रहे हैं, कृपया क्षेत्र से बचें। वैकल्पिक {{text}} और/या {{text}} देखने के लिए नीचे दिए गए URL पर क्लिक करें जहां सेवा उपलब्ध और चालू है। हमारी साइट पर लाइव अपडेट, नीचे उपलब्ध हैं।',
    ],
    'operation_disruption_2' => [
        'en' => 'Regular maintenance for {{text}} is scheduled for {{date}} and the station in the {{text}} area will be closed until {{date}}. Please plan to use an alternate station if you plan to travel. Click the URL below to see alternate {{text}} and/or {{text}} where service will be available and running.',
        'ml' => '{{text}} നുള്ള പതിവ് പരിപാലനം {{date}} ന് ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു, {{text}} ഏരിയയിലെ സ്റ്റേഷൻ {{date}} വരെ അടച്ചിരിക്കും. നിങ്ങൾ യാത്ര ചെയ്യാൻ പദ്ധതിയിടുകയാണെങ്കിൽ ഒരു ഇതര സ്റ്റേഷൻ ഉപയോഗിക്കാൻ പദ്ധതിയിടുക. സേവനം ലഭ്യമായതും പ്രവർത്തിക്കുന്നതുമായ ഇതര {{text}} കൂടാതെ/അല്ലെങ്കിൽ {{text}} കാണാൻ താഴെയുള്ള URL ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'الصيانة الدورية لـ {{text}} مقررة في {{date}} وستكون المحطة في منطقة {{text}} مغلقة حتى {{date}}. يرجى التخطيط لاستخدام محطة بديلة إذا كنت تخطط للسفر. انقر على الرابط أدناه لمشاهدة {{text}} و/أو {{text}} البديلة حيث ستكون الخدمة متاحة وتعمل.',
        'hi' => '{{text}} के लिए नियमित रखरखाव {{date}} के लिए निर्धारित है और {{text}} क्षेत्र का स्टेशन {{date}} तक बंद रहेगा। यदि आप यात्रा करने की योजना बना रहे हैं तो कृपया वैकल्पिक स्टेशन का उपयोग करने की योजना बनाएं। वैकल्पिक {{text}} और/या {{text}} देखने के लिए नीचे दिए गए URL पर क्लिक करें जहां सेवा उपलब्ध और चालू होगी।',
    ],
    'order_action_required_1' => [
        'en' => 'Hi {{text}}, before we can process your order {{text}}, we need to verify some information.

Please contact us at your earliest convenience.

Thank you.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} പ്രോസസ് ചെയ്യുന്നതിന് മുമ്പ്, ചില വിവരങ്ങൾ പരിശോധിച്ചുറപ്പിക്കേണ്ടതുണ്ട്.

നിങ്ങൾക്ക് സൗകര്യമുള്ള എത്രയും വേഗം ഞങ്ങളെ ബന്ധപ്പെടുക.

നന്ദി.',
        'ar' => 'مرحبا {{text}}، قبل أن نتمكن من معالجة طلبك {{text}}، نحتاج إلى التحقق من بعض المعلومات.

يرجى الاتصال بنا في أقرب وقت ممكن.

شكرا لك.',
        'hi' => 'नमस्ते {{text}}, इससे पहले कि हम आपके ऑर्डर {{text}} को संसाधित कर सकें, हमें कुछ जानकारी सत्यापित करने की आवश्यकता है।

कृपया जल्द से जल्द हमसे संपर्क करें।

धन्यवाद।',
    ],
    'order_action_required_2' => [
        'en' => 'We were unable to process your order {{text}}.

Please call us at {{phone}} for next steps.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ {{text}} പ്രോസസ് ചെയ്യാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല.

അടുത്ത ഘട്ടങ്ങൾക്കായി {{phone}} എന്ന നമ്പറിൽ ഞങ്ങളെ വിളിക്കുക.',
        'ar' => 'لم نتمكن من معالجة طلبك {{text}}.

يرجى الاتصال بنا على {{phone}} للخطوات التالية.',
        'hi' => 'हम आपके ऑर्डर {{text}} को संसाधित करने में असमर्थ रहे।

अगले चरणों के लिए कृपया हमें {{phone}} पर कॉल करें।',
    ],
    'order_canceled_1' => [
        'en' => '{{text}}, your order {{text}} has been successfully canceled.

Your refund will be processed in {{number}} business days.

Thank you.',
        'ml' => '{{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} വിജയകരമായി റദ്ദാക്കി.

നിങ്ങളുടെ റീഫണ്ട് {{number}} പ്രവൃത്തി ദിവസങ്ങൾക്കുള്ളിൽ പ്രോസസ് ചെയ്യും.

നന്ദി.',
        'ar' => '{{text}}، تم إلغاء طلبك {{text}} بنجاح.

ستتم معالجة استردادك خلال {{number}} أيام عمل.

شكرا لك.',
        'hi' => '{{text}}, आपका ऑर्डर {{text}} सफलतापूर्वक रद्द कर दिया गया है।

आपका रिफंड {{number}} व्यावसायिक दिनों में संसाधित किया जाएगा।

धन्यवाद।',
    ],
    'order_canceled_2' => [
        'en' => '{{text}}, per your request, we have canceled your order {{text}}.

Your {{text}} will be processed in {{number}} business days.

You can track this below.',
        'ml' => '{{text}}, നിങ്ങളുടെ അഭ്യർത്ഥന പ്രകാരം, ഞങ്ങൾ നിങ്ങളുടെ ഓർഡർ {{text}} റദ്ദാക്കി.

നിങ്ങളുടെ {{text}} {{number}} പ്രവൃത്തി ദിവസങ്ങൾക്കുള്ളിൽ പ്രോസസ് ചെയ്യും.

നിങ്ങൾക്ക് ഇത് താഴെ ട്രാക്ക് ചെയ്യാം.',
        'ar' => '{{text}}، بناء على طلبك، قمنا بإلغاء طلبك {{text}}.

ستتم معالجة {{text}} الخاص بك خلال {{number}} أيام عمل.

يمكنك تتبع ذلك أدناه.',
        'hi' => '{{text}}, आपके अनुरोध के अनुसार, हमने आपका ऑर्डर {{text}} रद्द कर दिया है।

आपका {{text}} {{number}} व्यावसायिक दिनों में संसाधित किया जाएगा।

आप इसे नीचे ट्रैक कर सकते हैं।',
    ],
    'order_canceled_3' => [
        'en' => 'Hi, this is to confirm we have successfully canceled your recent order {{text}}.

Thank you.',
        'ml' => 'ഹായ്, നിങ്ങളുടെ സമീപകാല ഓർഡർ {{text}} ഞങ്ങൾ വിജയകരമായി റദ്ദാക്കി എന്ന് സ്ഥിരീകരിക്കാനാണിത്.

നന്ദി.',
        'ar' => 'مرحبا، هذا لتأكيد أننا ألغينا طلبك الأخير {{text}} بنجاح.

شكرا لك.',
        'hi' => 'नमस्ते, यह पुष्टि करने के लिए है कि हमने आपके हाल के ऑर्डर {{text}} को सफलतापूर्वक रद्द कर दिया है।

धन्यवाद।',
    ],
    'order_canceled_4' => [
        'en' => 'Hello {{text}},

Your order {{text}} has been canceled.

A refund will be issued to your original payment method soon.',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} റദ്ദാക്കി.

നിങ്ങളുടെ യഥാർത്ഥ പേയ്മെന്റ് രീതിയിലേക്ക് ഉടൻ ഒരു റീഫണ്ട് നൽകും.',
        'ar' => 'مرحبا {{text}}،

تم إلغاء طلبك {{text}}.

سيتم إصدار استرداد إلى طريقة الدفع الأصلية الخاصة بك قريبا.',
        'hi' => 'नमस्ते {{text}},

आपका ऑर्डर {{text}} रद्द कर दिया गया है।

आपकी मूल भुगतान विधि में जल्द ही रिफंड जारी किया जाएगा।',
    ],
    'order_confirm_auto_schedule' => [
        'en' => 'Hi {{text}}, your {{text}} order has been successfully placed! We\'ve scheduled an appointment for {{date}} at your preferred location. Please confirm if this time slot works for you.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} ഓർഡർ വിജയകരമായി നൽകി! നിങ്ങൾ ഇഷ്ടപ്പെടുന്ന സ്ഥലത്ത് {{date}} ന് ഞങ്ങൾ ഒരു അപ്പോയിന്റ്മെന്റ് ഷെഡ്യൂൾ ചെയ്തു. ഈ സമയം നിങ്ങൾക്ക് അനുയോജ്യമാണോ എന്ന് സ്ഥിരീകരിക്കുക.',
        'ar' => 'مرحبا {{text}}، تم تقديم طلب {{text}} الخاص بك بنجاح! لقد حددنا موعدا في {{date}} في موقعك المفضل. يرجى التأكيد إذا كان هذا الموعد مناسبا لك.',
        'hi' => 'नमस्ते {{text}}, आपका {{text}} ऑर्डर सफलतापूर्वक दिया गया है! हमने आपके पसंदीदा स्थान पर {{date}} के लिए एक अपॉइंटमेंट निर्धारित किया है। कृपया पुष्टि करें कि क्या यह समय आपके लिए उपयुक्त है।',
    ],
    'order_confirm_manual_schedule' => [
        'en' => 'Hi {{text}}, your {{text}} order is placed! Reply *Schedule* to pick a time slot for your appointment.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} ഓർഡർ നൽകി! നിങ്ങളുടെ അപ്പോയിന്റ്മെന്റിനുള്ള ഒരു സമയം തിരഞ്ഞെടുക്കാൻ *Schedule* എന്ന് മറുപടി നൽകുക.',
        'ar' => 'مرحبا {{text}}، تم تقديم طلب {{text}} الخاص بك! أرسل *Schedule* لاختيار موعد لموعدك.',
        'hi' => 'नमस्ते {{text}}, आपका {{text}} ऑर्डर दे दिया गया है! अपने अपॉइंटमेंट के लिए समय स्लॉट चुनने के लिए *Schedule* का उत्तर दें।',
    ],
    'order_confirmed' => [
        'en' => 'Hi {{text}},
We\'re getting your order {{text}} ready and will let you know when it\'s on the way.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങൾ തയ്യാറാക്കുന്നു, അത് വഴിയിലാകുമ്പോൾ നിങ്ങളെ അറിയിക്കും.',
        'ar' => 'مرحبا {{text}}،
نحن نجهز طلبك {{text}} وسنخبرك عندما يكون في الطريق.',
        'hi' => 'नमस्ते {{text}},
हम आपका ऑर्डर {{text}} तैयार कर रहे हैं और आपको बताएंगे कि यह कब रास्ते में है।',
    ],
    'order_delay_1' => [
        'en' => 'Hi {{text}}, there is a {{text}} in {{text}} your order {{text}}.

We\'re working to resolve it as soon as possible.

We will follow up with an update.

We apologize for any inconvenience.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} {{text}} ചെയ്യുന്നതിൽ ഒരു {{text}} ഉണ്ട്.

എത്രയും വേഗം അത് പരിഹരിക്കാൻ ഞങ്ങൾ പ്രവർത്തിക്കുന്നു.

ഒരു അപ്ഡേറ്റുമായി ഞങ്ങൾ ബന്ധപ്പെടും.

എന്തെങ്കിലും ബുദ്ധിമുട്ടിന് ഞങ്ങൾ ക്ഷമ ചോദിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}، هناك {{text}} في {{text}} طلبك {{text}}.

نحن نعمل على حلها في أقرب وقت ممكن.

سنتابع بتحديث.

نعتذر عن أي إزعاج.',
        'hi' => 'नमस्ते {{text}}, आपके ऑर्डर {{text}} को {{text}} करने में एक {{text}} है।

हम इसे जल्द से जल्द हल करने के लिए काम कर रहे हैं।

हम एक अपडेट के साथ फॉलो अप करेंगे।

किसी भी असुविधा के लिए हम क्षमा चाहते हैं।',
    ],
    'order_delay_2' => [
        'en' => 'Hi {{text}},

Item(s) from your recent order {{text}} are out-of-stock. We will notify you as soon as your item(s) ship.

If you prefer not to wait, please click below to {{text}} your order.

We apologize for any inconvenience.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ സമീപകാല ഓർഡർ {{text}} ലെ ഇനം(ങ്ങൾ) സ്റ്റോക്കില്ല. നിങ്ങളുടെ ഇനം(ങ്ങൾ) ഷിപ്പ് ചെയ്യുന്ന ഉടൻ ഞങ്ങൾ നിങ്ങളെ അറിയിക്കും.

കാത്തിരിക്കാൻ ആഗ്രഹിക്കുന്നില്ലെങ്കിൽ, നിങ്ങളുടെ ഓർഡർ {{text}} ചെയ്യാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.

എന്തെങ്കിലും ബുദ്ധിമുട്ടിന് ഞങ്ങൾ ക്ഷമ ചോദിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}،

العنصر (العناصر) من طلبك الأخير {{text}} غير متوفرة في المخزون. سنخطرك بمجرد شحن العنصر (العناصر) الخاص بك.

إذا كنت تفضل عدم الانتظار، يرجى النقر أدناه لـ {{text}} طلبك.

نعتذر عن أي إزعاج.',
        'hi' => 'नमस्ते {{text}},

आपके हाल के ऑर्डर {{text}} की वस्तु(एं) स्टॉक में नहीं हैं। जैसे ही आपकी वस्तु(एं) शिप होंगी, हम आपको सूचित करेंगे।

यदि आप प्रतीक्षा नहीं करना चाहते हैं, तो कृपया अपने ऑर्डर को {{text}} करने के लिए नीचे क्लिक करें।

किसी भी असुविधा के लिए हम क्षमा चाहते हैं।',
    ],
    'order_delivered' => [
        'en' => 'Hi {{text}},
Your {{text}} has been delivered. Thank you for shopping with us.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ {{text}} എത്തിച്ചു. ഞങ്ങളോടൊപ്പം ഷോപ്പിംഗ് ചെയ്തതിന് നന്ദി.',
        'ar' => 'مرحبا {{text}}،
تم تسليم {{text}} الخاص بك. شكرا للتسوق معنا.',
        'hi' => 'नमस्ते {{text}},
आपका {{text}} डिलीवर कर दिया गया है। हमारे साथ खरीदारी करने के लिए धन्यवाद।',
    ],
    'order_management_1' => [
        'en' => 'Hi {{text}},

Thank you for your {{text}}! Your order number is {{text}}.

We\'ll start getting {{text}} ready to ship.

Estimated delivery: {{date}}

We will let you know when your order ships.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ {{text}} ന് നന്ദി! നിങ്ങളുടെ ഓർഡർ നമ്പർ {{text}} ആണ്.

{{text}} ഷിപ്പ് ചെയ്യാൻ ഞങ്ങൾ തയ്യാറാക്കാൻ തുടങ്ങും.

ഏകദേശ ഡെലിവറി: {{date}}

നിങ്ങളുടെ ഓർഡർ ഷിപ്പ് ചെയ്യുമ്പോൾ ഞങ്ങൾ നിങ്ങളെ അറിയിക്കും.',
        'ar' => 'مرحبا {{text}}،

شكرا لـ {{text}} الخاص بك! رقم طلبك هو {{text}}.

سنبدأ في تجهيز {{text}} للشحن.

التسليم المتوقع: {{date}}

سنخبرك عند شحن طلبك.',
        'hi' => 'नमस्ते {{text}},

आपके {{text}} के लिए धन्यवाद! आपका ऑर्डर नंबर {{text}} है।

हम {{text}} को शिप करने के लिए तैयार करना शुरू करेंगे।

अनुमानित डिलीवरी: {{date}}

जब आपका ऑर्डर शिप होगा तो हम आपको बताएंगे।',
    ],
    'order_management_2' => [
        'en' => 'Hi {{text}}, your order is confirmed and your order number is {{text}}.

Estimated delivery: {{date}}.

We will follow up with more details as we prepare your order for shipment.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ സ്ഥിരീകരിച്ചു, നിങ്ങളുടെ ഓർഡർ നമ്പർ {{text}} ആണ്.

ഏകദേശ ഡെലിവറി: {{date}}.

ഷിപ്പ്മെന്റിനായി നിങ്ങളുടെ ഓർഡർ തയ്യാറാക്കുമ്പോൾ ഞങ്ങൾ കൂടുതൽ വിശദാംശങ്ങളുമായി ബന്ധപ്പെടും.',
        'ar' => 'مرحبا {{text}}، تم تأكيد طلبك ورقم طلبك هو {{text}}.

التسليم المتوقع: {{date}}.

سنتابع بمزيد من التفاصيل أثناء تجهيز طلبك للشحن.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर पुष्टि हो गया है और आपका ऑर्डर नंबर {{text}} है।

अनुमानित डिलीवरी: {{date}}।

जब हम आपके ऑर्डर को शिपमेंट के लिए तैयार करेंगे तो हम अधिक विवरण के साथ फॉलो अप करेंगे।',
    ],
    'order_management_3' => [
        'en' => 'Hi {{text}}, we\'ve received your order.

Your order number is {{text}}.

Estimated delivery: {{date}}.

Click below to manage your order.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ ഞങ്ങൾക്ക് ലഭിച്ചു.

നിങ്ങളുടെ ഓർഡർ നമ്പർ {{text}} ആണ്.

ഏകദേശ ഡെലിവറി: {{date}}.

നിങ്ങളുടെ ഓർഡർ കൈകാര്യം ചെയ്യാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، لقد استلمنا طلبك.

رقم طلبك هو {{text}}.

التسليم المتوقع: {{date}}.

انقر أدناه لإدارة طلبك.',
        'hi' => 'नमस्ते {{text}}, हमें आपका ऑर्डर मिल गया है।

आपका ऑर्डर नंबर {{text}} है।

अनुमानित डिलीवरी: {{date}}।

अपना ऑर्डर प्रबंधित करने के लिए नीचे क्लिक करें।',
    ],
    'order_management_4' => [
        'en' => 'Hi {{text}},
Your order has been successfully placed and is being processed. Your order number is {{text}}. You can view order details below.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ ഓർഡർ വിജയകരമായി നൽകി, പ്രോസസ് ചെയ്യുന്നു. നിങ്ങളുടെ ഓർഡർ നമ്പർ {{text}} ആണ്. താഴെ ഓർഡർ വിശദാംശങ്ങൾ കാണാം.',
        'ar' => 'مرحبا {{text}}،
تم تقديم طلبك بنجاح وتتم معالجته. رقم طلبك هو {{text}}. يمكنك عرض تفاصيل الطلب أدناه.',
        'hi' => 'नमस्ते {{text}},
आपका ऑर्डर सफलतापूर्वक दे दिया गया है और संसाधित किया जा रहा है। आपका ऑर्डर नंबर {{text}} है। आप नीचे ऑर्डर विवरण देख सकते हैं।',
    ],
    'order_management_5' => [
        'en' => 'Hello {{text}},

We received your order {{text}}. We’ll send you a status update once your payment is approved.

Thank you for shopping with us!',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങൾക്ക് ലഭിച്ചു. നിങ്ങളുടെ പേയ്മെന്റ് അംഗീകരിച്ചാൽ ഞങ്ങൾ നിങ്ങൾക്ക് ഒരു സ്റ്റാറ്റസ് അപ്ഡേറ്റ് അയയ്ക്കും.

ഞങ്ങളോടൊപ്പം ഷോപ്പിംഗ് ചെയ്തതിന് നന്ദി!',
        'ar' => 'مرحبا {{text}}،

استلمنا طلبك {{text}}. سنرسل لك تحديثا للحالة بمجرد الموافقة على دفعتك.

شكرا للتسوق معنا!',
        'hi' => 'नमस्ते {{text}},

हमें आपका ऑर्डर {{text}} मिल गया। आपका भुगतान स्वीकृत होते ही हम आपको एक स्थिति अपडेट भेजेंगे।

हमारे साथ खरीदारी करने के लिए धन्यवाद!',
    ],
    'order_management_6' => [
        'en' => 'Hi {{text}},

Your order {{text}} has been successfully placed with {{text}} and is being processed.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} {{text}} യുമായി വിജയകരമായി നൽകി, പ്രോസസ് ചെയ്യുന്നു.',
        'ar' => 'مرحبا {{text}}،

تم تقديم طلبك {{text}} بنجاح لدى {{text}} وتتم معالجته.',
        'hi' => 'नमस्ते {{text}},

आपका ऑर्डर {{text}} {{text}} के साथ सफलतापूर्वक दे दिया गया है और संसाधित किया जा रहा है।',
    ],
    'order_management_no_cta_5' => [
        'en' => 'Hello {{text}},

We received your order {{text}}. We’ll send you a status update once your payment is approved.

Thank you for shopping with us!',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങൾക്ക് ലഭിച്ചു. നിങ്ങളുടെ പേയ്മെന്റ് അംഗീകരിച്ചാൽ ഞങ്ങൾ നിങ്ങൾക്ക് ഒരു സ്റ്റാറ്റസ് അപ്ഡേറ്റ് അയയ്ക്കും.

ഞങ്ങളോടൊപ്പം ഷോപ്പിംഗ് ചെയ്തതിന് നന്ദി!',
        'ar' => 'مرحبا {{text}}،

استلمنا طلبك {{text}}. سنرسل لك تحديثا للحالة بمجرد الموافقة على دفعتك.

شكرا للتسوق معنا!',
        'hi' => 'नमस्ते {{text}},

हमें आपका ऑर्डर {{text}} मिल गया। आपका भुगतान स्वीकृत होते ही हम आपको एक स्थिति अपडेट भेजेंगे।

हमारे साथ खरीदारी करने के लिए धन्यवाद!',
    ],
    'order_pick_up_1' => [
        'en' => 'Hi {{text}}, your order {{text}} is ready for pick up at {{address}}.
When you arrive, tap the button below and we will bring your order to you.
See you soon!',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} {{address}} ൽ പിക്ക് അപ്പിന് തയ്യാറാണ്.
നിങ്ങൾ എത്തുമ്പോൾ, താഴെയുള്ള ബട്ടൺ ടാപ്പ് ചെയ്യുക, ഞങ്ങൾ നിങ്ങളുടെ ഓർഡർ നിങ്ങളുടെ അടുത്ത് കൊണ്ടുവരും.
ഉടൻ കാണാം!',
        'ar' => 'مرحبا {{text}}، طلبك {{text}} جاهز للاستلام في {{address}}.
عند وصولك، انقر على الزر أدناه وسنحضر طلبك إليك.
نراك قريبا!',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर {{text}} {{address}} पर पिकअप के लिए तैयार है।
जब आप पहुंचें, तो नीचे दिए गए बटन को टैप करें और हम आपका ऑर्डर आपके पास लाएंगे।
जल्द ही मिलते हैं!',
    ],
    'order_pick_up_3' => [
        'en' => 'Great news! Your order {{text}} is now ready for pick up at {{address}}.

Click "I\'m here" when you arrive and we will meet you with your products.

See you soon!',
        'ml' => 'സന്തോഷവാർത്ത! നിങ്ങളുടെ ഓർഡർ {{text}} ഇപ്പോൾ {{address}} ൽ പിക്ക് അപ്പിന് തയ്യാറാണ്.

നിങ്ങൾ എത്തുമ്പോൾ "I\'m here" ക്ലിക്ക് ചെയ്യുക, ഞങ്ങൾ നിങ്ങളുടെ ഉൽപ്പന്നങ്ങളുമായി നിങ്ങളെ കാണും.

ഉടൻ കാണാം!',
        'ar' => 'أخبار رائعة! طلبك {{text}} جاهز الآن للاستلام في {{address}}.

انقر على "I\'m here" عند وصولك وسنقابلك مع منتجاتك.

نراك قريبا!',
        'hi' => 'बढ़िया खबर! आपका ऑर्डर {{text}} अब {{address}} पर पिकअप के लिए तैयार है।

जब आप पहुंचें तो "I\'m here" पर क्लिक करें और हम आपके उत्पादों के साथ आपसे मिलेंगे।

जल्द ही मिलते हैं!',
    ],
    'order_pick_up_4' => [
        'en' => 'Hello {{text}},

Your order {{text}} is now ready for pickup at {{address}}.

Please remember to bring a photo ID with you.

See you soon!',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഇപ്പോൾ {{address}} ൽ പിക്ക് അപ്പിന് തയ്യാറാണ്.

നിങ്ങളോടൊപ്പം ഒരു ഫോട്ടോ ഐഡി കൊണ്ടുവരാൻ ഓർക്കുക.

ഉടൻ കാണാം!',
        'ar' => 'مرحبا {{text}}،

طلبك {{text}} جاهز الآن للاستلام في {{address}}.

يرجى تذكر إحضار بطاقة هوية بصورة معك.

نراك قريبا!',
        'hi' => 'नमस्ते {{text}},

आपका ऑर्डर {{text}} अब {{address}} पर पिकअप के लिए तैयार है।

कृपया अपने साथ एक फोटो पहचान पत्र लाना याद रखें।

जल्द ही मिलते हैं!',
    ],
    'order_pick_up_no_cta_4' => [
        'en' => 'Hello {{text}},

Your order {{text}} is now ready for pickup at {{address}}.

Please remember to bring a photo ID with you.

See you soon!',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഇപ്പോൾ {{address}} ൽ പിക്ക് അപ്പിന് തയ്യാറാണ്.

നിങ്ങളോടൊപ്പം ഒരു ഫോട്ടോ ഐഡി കൊണ്ടുവരാൻ ഓർക്കുക.

ഉടൻ കാണാം!',
        'ar' => 'مرحبا {{text}}،

طلبك {{text}} جاهز الآن للاستلام في {{address}}.

يرجى تذكر إحضار بطاقة هوية بصورة معك.

نراك قريبا!',
        'hi' => 'नमस्ते {{text}},

आपका ऑर्डर {{text}} अब {{address}} पर पिकअप के लिए तैयार है।

कृपया अपने साथ एक फोटो पहचान पत्र लाना याद रखें।

जल्द ही मिलते हैं!',
    ],
    'order_shipped' => [
        'en' => 'Hi {{text}},
Your order {{text}} has been shipped and is on the way.',
        'ml' => 'ഹായ് {{text}},
നിങ്ങളുടെ ഓർഡർ {{text}} ഷിപ്പ് ചെയ്തു, വഴിയിലാണ്.',
        'ar' => 'مرحبا {{text}}،
تم شحن طلبك {{text}} وهو في الطريق.',
        'hi' => 'नमस्ते {{text}},
आपका ऑर्डर {{text}} शिप कर दिया गया है और रास्ते में है।',
    ],
    'order_update_1' => [
        'en' => 'Hi {{text}},

We’re preparing your order {{text}} and will let you know when it’s ready.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങൾ തയ്യാറാക്കുന്നു, അത് തയ്യാറാകുമ്പോൾ നിങ്ങളെ അറിയിക്കും.',
        'ar' => 'مرحبا {{text}}،

نحن نجهز طلبك {{text}} وسنخبرك عندما يكون جاهزا.',
        'hi' => 'नमस्ते {{text}},

हम आपका ऑर्डर {{text}} तैयार कर रहे हैं और आपको बताएंगे कि यह कब तैयार है।',
    ],
    'order_update_no_cta_1' => [
        'en' => 'Hi {{text}},

We’re preparing your order {{text}} and will let you know when it’s ready.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങൾ തയ്യാറാക്കുന്നു, അത് തയ്യാറാകുമ്പോൾ നിങ്ങളെ അറിയിക്കും.',
        'ar' => 'مرحبا {{text}}،

نحن نجهز طلبك {{text}} وسنخبرك عندما يكون جاهزا.',
        'hi' => 'नमस्ते {{text}},

हम आपका ऑर्डर {{text}} तैयार कर रहे हैं और आपको बताएंगे कि यह कब तैयार है।',
    ],
    'payment_action_required_1' => [
        'en' => 'Hi {{text}},

Payment for your {{text}} card ending in {{number}} is coming due.

Verify your information to avoid {{text}} fees.',
        'ml' => 'ഹായ് {{text}},

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള പേയ്മെന്റ് അടയ്ക്കാറായി.

{{text}} ഫീസ് ഒഴിവാക്കാൻ നിങ്ങളുടെ വിവരങ്ങൾ പരിശോധിച്ചുറപ്പിക്കുക.',
        'ar' => 'مرحبا {{text}}،

اقترب موعد استحقاق دفعة بطاقتك {{text}} المنتهية بـ {{number}}.

تحقق من معلوماتك لتجنب رسوم {{text}}.',
        'hi' => 'नमस्ते {{text}},

{{number}} में समाप्त होने वाले आपके {{text}} कार्ड का भुगतान देय होने वाला है।

{{text}} शुल्क से बचने के लिए अपनी जानकारी सत्यापित करें।',
    ],
    'payment_action_required_2' => [
        'en' => 'Hi {{text}},

We encountered an issue with your recent transaction for {{amount}} at {{text}}.

Please contact us at {{phone}} for assistance.',
        'ml' => 'ഹായ് {{text}},

{{text}} ൽ {{amount}} നുള്ള നിങ്ങളുടെ സമീപകാല ഇടപാടിൽ ഞങ്ങൾ ഒരു പ്രശ്നം നേരിട്ടു.

സഹായത്തിനായി {{phone}} എന്ന നമ്പറിൽ ഞങ്ങളെ ബന്ധപ്പെടുക.',
        'ar' => 'مرحبا {{text}}،

واجهنا مشكلة في معاملتك الأخيرة بمبلغ {{amount}} في {{text}}.

يرجى الاتصال بنا على {{phone}} للحصول على المساعدة.',
        'hi' => 'नमस्ते {{text}},

{{text}} पर {{amount}} के आपके हाल के लेनदेन में हमें एक समस्या आई।

सहायता के लिए कृपया हमें {{phone}} पर संपर्क करें।',
    ],
    'payment_action_required_3' => [
        'en' => 'Your scheduled payment of {{amount}} for {{text}} could not be processed.

Please contact us at {{phone}} for assistance.',
        'ml' => '{{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ ഷെഡ്യൂൾ ചെയ്ത പേയ്മെന്റ് പ്രോസസ് ചെയ്യാൻ കഴിഞ്ഞില്ല.

സഹായത്തിനായി {{phone}} എന്ന നമ്പറിൽ ഞങ്ങളെ ബന്ധപ്പെടുക.',
        'ar' => 'تعذرت معالجة دفعتك المجدولة بمبلغ {{amount}} لـ {{text}}.

يرجى الاتصال بنا على {{phone}} للحصول على المساعدة.',
        'hi' => '{{text}} के लिए {{amount}} का आपका निर्धारित भुगतान संसाधित नहीं किया जा सका।

सहायता के लिए कृपया हमें {{phone}} पर संपर्क करें।',
    ],
    'payment_confirmation_1' => [
        'en' => 'Hi {{text}},

We have received your payment of {{amount}} for {{text}}. Thank you for your payment.',
        'ml' => 'ഹായ് {{text}},

{{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് ഞങ്ങൾക്ക് ലഭിച്ചു. നിങ്ങളുടെ പേയ്മെന്റിന് നന്ദി.',
        'ar' => 'مرحبا {{text}}،

لقد استلمنا دفعتك بمبلغ {{amount}} لـ {{text}}. شكرا لدفعتك.',
        'hi' => 'नमस्ते {{text}},

हमें {{text}} के लिए {{amount}} का आपका भुगतान मिल गया है। आपके भुगतान के लिए धन्यवाद।',
    ],
    'payment_confirmation_2' => [
        'en' => 'Your payment of {{amount}} for {{text}} has been processed successfully. We appreciate your business.',
        'ml' => '{{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് വിജയകരമായി പ്രോസസ് ചെയ്തു. നിങ്ങളുടെ ബിസിനസിനെ ഞങ്ങൾ അഭിനന്ദിക്കുന്നു.',
        'ar' => 'تمت معالجة دفعتك بمبلغ {{amount}} لـ {{text}} بنجاح. نقدر تعاملك معنا.',
        'hi' => '{{text}} के लिए {{amount}} का आपका भुगतान सफलतापूर्वक संसाधित किया गया है। हम आपके व्यापार की सराहना करते हैं।',
    ],
    'payment_confirmation_3' => [
        'en' => 'Payment confirmation:

Account: {{text}}
Amount: {{amount}}
Date: {{date}}

Thank you and have a nice day.',
        'ml' => 'പേയ്മെന്റ് സ്ഥിരീകരണം:

അക്കൗണ്ട്: {{text}}
തുക: {{amount}}
തീയതി: {{date}}

നന്ദി, നല്ലൊരു ദിവസം ആശംസിക്കുന്നു.',
        'ar' => 'تأكيد الدفع:

الحساب: {{text}}
المبلغ: {{amount}}
التاريخ: {{date}}

شكرا لك ويوم سعيد.',
        'hi' => 'भुगतान पुष्टि:

खाता: {{text}}
राशि: {{amount}}
तिथि: {{date}}

धन्यवाद और आपका दिन शुभ हो।',
    ],
    'payment_confirmation_4' => [
        'en' => 'Hello {{text}},

Your payment of {{amount}} for order {{text}} has been approved.',
        'ml' => 'ഹലോ {{text}},

ഓർഡർ {{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് അംഗീകരിച്ചു.',
        'ar' => 'مرحبا {{text}}،

تمت الموافقة على دفعتك بمبلغ {{amount}} للطلب {{text}}.',
        'hi' => 'नमस्ते {{text}},

ऑर्डर {{text}} के लिए {{amount}} का आपका भुगतान स्वीकृत हो गया है।',
    ],
    'payment_due_reminder' => [
        'en' => 'Hi {{text}}, your {{text}} bill of {{amount}} is due on {{date}}. Pay now to avoid service disruption.',
        'ml' => 'ഹായ് {{text}}, {{amount}} എന്ന നിങ്ങളുടെ {{text}} ബിൽ {{date}} ന് അടയ്ക്കേണ്ടതാണ്. സേവന തടസ്സം ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'مرحبا {{text}}، فاتورة {{text}} الخاصة بك بمبلغ {{amount}} مستحقة في {{date}}. ادفع الآن لتجنب انقطاع الخدمة.',
        'hi' => 'नमस्ते {{text}}, {{amount}} का आपका {{text}} बिल {{date}} को देय है। सेवा में व्यवधान से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_failed_1' => [
        'en' => 'Hi {{text}}, your recent payment of {{amount}} for your {{text}} account has failed.

Please check your account and try again.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} അക്കൗണ്ടിനുള്ള {{amount}} എന്ന നിങ്ങളുടെ സമീപകാല പേയ്മെന്റ് പരാജയപ്പെട്ടു.

നിങ്ങളുടെ അക്കൗണ്ട് പരിശോധിച്ച് വീണ്ടും ശ്രമിക്കുക.',
        'ar' => 'مرحبا {{text}}، فشلت دفعتك الأخيرة بمبلغ {{amount}} لحساب {{text}} الخاص بك.

يرجى التحقق من حسابك والمحاولة مرة أخرى.',
        'hi' => 'नमस्ते {{text}}, आपके {{text}} खाते के लिए {{amount}} का आपका हालिया भुगतान विफल हो गया है।

कृपया अपना खाता जांचें और पुनः प्रयास करें।',
    ],
    'payment_failed_2' => [
        'en' => 'Hi {{text}},

We were unable to process your payment of {{amount}} for {{text}}.

Please update your payment method or contact us for assistance.',
        'ml' => 'ഹായ് {{text}},

{{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് പ്രോസസ് ചെയ്യാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല.

നിങ്ങളുടെ പേയ്മെന്റ് രീതി അപ്ഡേറ്റ് ചെയ്യുകയോ സഹായത്തിനായി ഞങ്ങളെ ബന്ധപ്പെടുകയോ ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}،

لم نتمكن من معالجة دفعتك بمبلغ {{amount}} لـ {{text}}.

يرجى تحديث طريقة الدفع الخاصة بك أو الاتصال بنا للحصول على المساعدة.',
        'hi' => 'नमस्ते {{text}},

हम {{text}} के लिए {{amount}} का आपका भुगतान संसाधित करने में असमर्थ रहे।

कृपया अपनी भुगतान विधि अपडेट करें या सहायता के लिए हमसे संपर्क करें।',
    ],
    'payment_failed_3' => [
        'en' => 'Your payment was rejected.

Account: {{text}}
Amount: {{amount}}
Date: {{date}}

Please check your account and try again.',
        'ml' => 'നിങ്ങളുടെ പേയ്മെന്റ് നിരസിച്ചു.

അക്കൗണ്ട്: {{text}}
തുക: {{amount}}
തീയതി: {{date}}

നിങ്ങളുടെ അക്കൗണ്ട് പരിശോധിച്ച് വീണ്ടും ശ്രമിക്കുക.',
        'ar' => 'تم رفض دفعتك.

الحساب: {{text}}
المبلغ: {{amount}}
التاريخ: {{date}}

يرجى التحقق من حسابك والمحاولة مرة أخرى.',
        'hi' => 'आपका भुगतान अस्वीकृत कर दिया गया।

खाता: {{text}}
राशि: {{amount}}
तिथि: {{date}}

कृपया अपना खाता जांचें और पुनः प्रयास करें।',
    ],
    'payment_failed_4' => [
        'en' => 'Hi {{text}}, we were unable to process your {{text}} bill payment. Please try again or contact our support team.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} ബിൽ പേയ്മെന്റ് പ്രോസസ് ചെയ്യാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല. വീണ്ടും ശ്രമിക്കുകയോ ഞങ്ങളുടെ പിന്തുണാ ടീമിനെ ബന്ധപ്പെടുകയോ ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، لم نتمكن من معالجة دفع فاتورة {{text}} الخاصة بك. يرجى المحاولة مرة أخرى أو الاتصال بفريق الدعم لدينا.',
        'hi' => 'नमस्ते {{text}}, हम आपके {{text}} बिल भुगतान को संसाधित करने में असमर्थ रहे। कृपया पुनः प्रयास करें या हमारी सहायता टीम से संपर्क करें।',
    ],
    'payment_notice_1' => [
        'en' => 'Your payment for {{amount}} will be processed on {{date}}. Thank you!',
        'ml' => '{{amount}} നുള്ള നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് പ്രോസസ് ചെയ്യും. നന്ദി!',
        'ar' => 'ستتم معالجة دفعتك بمبلغ {{amount}} في {{date}}. شكرا لك!',
        'hi' => '{{amount}} के लिए आपका भुगतान {{date}} को संसाधित किया जाएगा। धन्यवाद!',
    ],
    'payment_notice_2' => [
        'en' => 'Thank you for scheduling your payment of {{amount}}. We will {{text}} on {{date}}.',
        'ml' => '{{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് ഷെഡ്യൂൾ ചെയ്തതിന് നന്ദി. ഞങ്ങൾ {{date}} ന് {{text}} ചെയ്യും.',
        'ar' => 'شكرا لجدولة دفعتك بمبلغ {{amount}}. سنقوم بـ {{text}} في {{date}}.',
        'hi' => '{{amount}} का अपना भुगतान निर्धारित करने के लिए धन्यवाद। हम {{date}} को {{text}} करेंगे।',
    ],
    'payment_notice_3' => [
        'en' => 'This is to {{text}} your payment of {{amount}} for {{text}} will be processed on {{date}}.',
        'ml' => '{{text}} നുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് പ്രോസസ് ചെയ്യും എന്ന് {{text}} ചെയ്യാനാണിത്.',
        'ar' => 'هذا لـ {{text}} أن دفعتك بمبلغ {{amount}} لـ {{text}} ستتم معالجتها في {{date}}.',
        'hi' => 'यह {{text}} करने के लिए है कि {{text}} के लिए {{amount}} का आपका भुगतान {{date}} को संसाधित किया जाएगा।',
    ],
    'payment_overdue_1' => [
        'en' => 'Your {{text}} payment of {{amount}} is overdue by {{number}} days.

Please pay now to avoid {{text}}. Contact us if you need assistance.',
        'ml' => '{{amount}} എന്ന നിങ്ങളുടെ {{text}} പേയ്മെന്റ് {{number}} ദിവസം കുടിശ്ശികയാണ്.

{{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക. സഹായം വേണമെങ്കിൽ ഞങ്ങളെ ബന്ധപ്പെടുക.',
        'ar' => 'دفعة {{text}} الخاصة بك بمبلغ {{amount}} متأخرة بمقدار {{number}} أيام.

يرجى الدفع الآن لتجنب {{text}}. اتصل بنا إذا كنت بحاجة إلى مساعدة.',
        'hi' => '{{amount}} का आपका {{text}} भुगतान {{number}} दिनों से अतिदेय है।

{{text}} से बचने के लिए कृपया अभी भुगतान करें। यदि आपको सहायता चाहिए तो हमसे संपर्क करें।',
    ],
    'payment_overdue_2' => [
        'en' => 'Hi {{text}}, you have a payment overdue:

Account: {{text}}
Amount due: {{amount}}
Due date: {{date}}

Use the button below to complete payment via our website.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങൾക്ക് ഒരു പേയ്മെന്റ് കുടിശ്ശികയുണ്ട്:

അക്കൗണ്ട്: {{text}}
അടയ്ക്കേണ്ട തുക: {{amount}}
അവസാന തീയതി: {{date}}

ഞങ്ങളുടെ വെബ്സൈറ്റ് വഴി പേയ്മെന്റ് പൂർത്തിയാക്കാൻ താഴെയുള്ള ബട്ടൺ ഉപയോഗിക്കുക.',
        'ar' => 'مرحبا {{text}}، لديك دفعة متأخرة:

الحساب: {{text}}
المبلغ المستحق: {{amount}}
تاريخ الاستحقاق: {{date}}

استخدم الزر أدناه لإكمال الدفع عبر موقعنا.',
        'hi' => 'नमस्ते {{text}}, आपका एक भुगतान अतिदेय है:

खाता: {{text}}
देय राशि: {{amount}}
देय तिथि: {{date}}

हमारी वेबसाइट के माध्यम से भुगतान पूरा करने के लिए नीचे दिए गए बटन का उपयोग करें।',
    ],
    'payment_overdue_3' => [
        'en' => 'Payment is overdue for your {{text}} card ending in {{number}} for {{amount}}.

Please click to pay now and avoid {{text}} fees.',
        'ml' => '{{amount}} ന് {{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള പേയ്മെന്റ് കുടിശ്ശികയാണ്.

ഇപ്പോൾ അടയ്ക്കാനും {{text}} ഫീസ് ഒഴിവാക്കാനും ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'الدفع متأخر لبطاقتك {{text}} المنتهية بـ {{number}} بمبلغ {{amount}}.

يرجى النقر للدفع الآن وتجنب رسوم {{text}}.',
        'hi' => '{{amount}} के लिए {{number}} में समाप्त होने वाले आपके {{text}} कार्ड का भुगतान अतिदेय है।

कृपया अभी भुगतान करने और {{text}} शुल्क से बचने के लिए क्लिक करें।',
    ],
    'payment_overdue_5' => [
        'en' => 'Payment is overdue for your {{text}} card ending in {{number}}.

Please click to pay now and avoid {{text}} fees.',
        'ml' => '{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള പേയ്മെന്റ് കുടിശ്ശികയാണ്.

ഇപ്പോൾ അടയ്ക്കാനും {{text}} ഫീസ് ഒഴിവാക്കാനും ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'الدفع متأخر لبطاقتك {{text}} المنتهية بـ {{number}}.

يرجى النقر للدفع الآن وتجنب رسوم {{text}}.',
        'hi' => '{{number}} में समाप्त होने वाले आपके {{text}} कार्ड का भुगतान अतिदेय है।

कृपया अभी भुगतान करने और {{text}} शुल्क से बचने के लिए क्लिक करें।',
    ],
    'payment_overdue_6' => [
        'en' => 'Hi {{text}}, you have a payment overdue:

Account: {{text}}
Due date: {{date}}

{{text}} to complete payment via our website.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങൾക്ക് ഒരു പേയ്മെന്റ് കുടിശ്ശികയുണ്ട്:

അക്കൗണ്ട്: {{text}}
അവസാന തീയതി: {{date}}

ഞങ്ങളുടെ വെബ്സൈറ്റ് വഴി പേയ്മെന്റ് പൂർത്തിയാക്കാൻ {{text}}.',
        'ar' => 'مرحبا {{text}}، لديك دفعة متأخرة:

الحساب: {{text}}
تاريخ الاستحقاق: {{date}}

{{text}} لإكمال الدفع عبر موقعنا.',
        'hi' => 'नमस्ते {{text}}, आपका एक भुगतान अतिदेय है:

खाता: {{text}}
देय तिथि: {{date}}

हमारी वेबसाइट के माध्यम से भुगतान पूरा करने के लिए {{text}}।',
    ],
    'payment_overdue_7' => [
        'en' => 'Your {{text}} payment is overdue by {{number}} days.

Please pay now to avoid {{text}}. Contact us if you need assistance.',
        'ml' => 'നിങ്ങളുടെ {{text}} പേയ്മെന്റ് {{number}} ദിവസം കുടിശ്ശികയാണ്.

{{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക. സഹായം വേണമെങ്കിൽ ഞങ്ങളെ ബന്ധപ്പെടുക.',
        'ar' => 'دفعة {{text}} الخاصة بك متأخرة بمقدار {{number}} أيام.

يرجى الدفع الآن لتجنب {{text}}. اتصل بنا إذا كنت بحاجة إلى مساعدة.',
        'hi' => 'आपका {{text}} भुगतान {{number}} दिनों से अतिदेय है।

{{text}} से बचने के लिए कृपया अभी भुगतान करें। यदि आपको सहायता चाहिए तो हमसे संपर्क करें।',
    ],
    'payment_recharge_reminder_01' => [
        'en' => 'Hello! Your {{text}} expires in {{text}}.
You can top up your number {{text}} - {{text}} - with {{amount}} to avoid interruptions.

Or click the link below to top up with another amount of your choice.

If you have already topped up, please disregard this message. Thank you!',
        'ml' => 'ഹലോ! നിങ്ങളുടെ {{text}} {{text}} ൽ കാലഹരണപ്പെടും.
തടസ്സങ്ങൾ ഒഴിവാക്കാൻ നിങ്ങളുടെ നമ്പർ {{text}} - {{text}} - {{amount}} ഉപയോഗിച്ച് ടോപ്പ് അപ്പ് ചെയ്യാം.

അല്ലെങ്കിൽ നിങ്ങൾക്ക് ഇഷ്ടമുള്ള മറ്റൊരു തുക ഉപയോഗിച്ച് ടോപ്പ് അപ്പ് ചെയ്യാൻ താഴെയുള്ള ലിങ്ക് ക്ലിക്ക് ചെയ്യുക.

നിങ്ങൾ ഇതിനകം ടോപ്പ് അപ്പ് ചെയ്തിട്ടുണ്ടെങ്കിൽ, ഈ സന്ദേശം അവഗണിക്കുക. നന്ദി!',
        'ar' => 'مرحبا! تنتهي صلاحية {{text}} الخاص بك في {{text}}.
يمكنك إعادة شحن رقمك {{text}} - {{text}} - بمبلغ {{amount}} لتجنب الانقطاعات.

أو انقر على الرابط أدناه لإعادة الشحن بمبلغ آخر من اختيارك.

إذا كنت قد أعدت الشحن بالفعل، يرجى تجاهل هذه الرسالة. شكرا لك!',
        'hi' => 'नमस्ते! आपका {{text}} {{text}} में समाप्त हो रहा है।
रुकावटों से बचने के लिए आप अपना नंबर {{text}} - {{text}} - {{amount}} के साथ रिचार्ज कर सकते हैं।

या अपनी पसंद की किसी अन्य राशि के साथ रिचार्ज करने के लिए नीचे दिए गए लिंक पर क्लिक करें।

यदि आपने पहले ही रिचार्ज कर लिया है, तो कृपया इस संदेश को अनदेखा करें। धन्यवाद!',
    ],
    'payment_reminder_1' => [
        'en' => 'Hello {{text}},

Your payment of {{amount}} is due on {{date}}.',
        'ml' => 'ഹലോ {{text}},

{{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്.',
        'ar' => 'مرحبا {{text}}،

دفعتك بمبلغ {{amount}} مستحقة في {{date}}.',
        'hi' => 'नमस्ते {{text}},

{{amount}} का आपका भुगतान {{date}} को देय है।',
    ],
    'payment_reminder_2' => [
        'en' => 'Your payment of {{amount}} is due on {{date}}. Pay now to avoid {{text}}.',
        'ml' => '{{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്. {{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'دفعتك بمبلغ {{amount}} مستحقة في {{date}}. ادفع الآن لتجنب {{text}}.',
        'hi' => '{{amount}} का आपका भुगतान {{date}} को देय है। {{text}} से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_3' => [
        'en' => 'Payment reminder:

Account: {{text}}
Amount due: {{amount}}
Due date: {{date}}

Pay now to avoid {{text}}.',
        'ml' => 'പേയ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ:

അക്കൗണ്ട്: {{text}}
അടയ്ക്കേണ്ട തുക: {{amount}}
അവസാന തീയതി: {{date}}

{{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'تذكير بالدفع:

الحساب: {{text}}
المبلغ المستحق: {{amount}}
تاريخ الاستحقاق: {{date}}

ادفع الآن لتجنب {{text}}.',
        'hi' => 'भुगतान अनुस्मारक:

खाता: {{text}}
देय राशि: {{amount}}
देय तिथि: {{date}}

{{text}} से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_4' => [
        'en' => 'Hi {{text}},

Payment of {{amount}} for your {{text}} card ending in {{number}} is due on {{date}}.

Pay now to avoid late fees.',
        'ml' => 'ഹായ് {{text}},

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള {{amount}} എന്ന പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്.

വൈകി അടയ്ക്കുന്ന ഫീസ് ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'مرحبا {{text}}،

دفعة بمبلغ {{amount}} لبطاقتك {{text}} المنتهية بـ {{number}} مستحقة في {{date}}.

ادفع الآن لتجنب رسوم التأخير.',
        'hi' => 'नमस्ते {{text}},

{{number}} में समाप्त होने वाले आपके {{text}} कार्ड के लिए {{amount}} का भुगतान {{date}} को देय है।

विलंब शुल्क से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_5' => [
        'en' => 'Hi {{text}},

Payment for your {{text}} card ending in {{number}} is due on {{date}}.

Pay now to avoid {{text}}.',
        'ml' => 'ഹായ് {{text}},

{{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ {{text}} കാർഡിനുള്ള പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്.

{{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'مرحبا {{text}}،

دفعة بطاقتك {{text}} المنتهية بـ {{number}} مستحقة في {{date}}.

ادفع الآن لتجنب {{text}}.',
        'hi' => 'नमस्ते {{text}},

{{number}} में समाप्त होने वाले आपके {{text}} कार्ड का भुगतान {{date}} को देय है।

{{text}} से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_6' => [
        'en' => 'Payment reminder:

Account: {{text}}
Due date: {{date}}

Pay now to avoid {{text}}.',
        'ml' => 'പേയ്മെന്റ് ഓർമ്മപ്പെടുത്തൽ:

അക്കൗണ്ട്: {{text}}
അവസാന തീയതി: {{date}}

{{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'تذكير بالدفع:

الحساب: {{text}}
تاريخ الاستحقاق: {{date}}

ادفع الآن لتجنب {{text}}.',
        'hi' => 'भुगतान अनुस्मारक:

खाता: {{text}}
देय तिथि: {{date}}

{{text}} से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_7' => [
        'en' => 'Your payment is due on {{date}}. Pay now to avoid {{text}}.',
        'ml' => 'നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്. {{text}} ഒഴിവാക്കാൻ ഇപ്പോൾ അടയ്ക്കുക.',
        'ar' => 'دفعتك مستحقة في {{date}}. ادفع الآن لتجنب {{text}}.',
        'hi' => 'आपका भुगतान {{date}} को देय है। {{text}} से बचने के लिए अभी भुगतान करें।',
    ],
    'payment_reminder_8' => [
        'en' => 'Hello {{text}},

Your payment is due on {{date}}.',
        'ml' => 'ഹലോ {{text}},

നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് അടയ്ക്കേണ്ടതാണ്.',
        'ar' => 'مرحبا {{text}}،

دفعتك مستحقة في {{date}}.',
        'hi' => 'नमस्ते {{text}},

आपका भुगतान {{date}} को देय है।',
    ],
    'payment_scheduled_1' => [
        'en' => 'Your payment of {{amount}} for your {{text}} account is scheduled for {{date}}. Please ensure you have sufficient funds to avoid generating any {{text}} fees.',
        'ml' => 'നിങ്ങളുടെ {{text}} അക്കൗണ്ടിനുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് {{date}} ന് ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു. ഏതെങ്കിലും {{text}} ഫീസ് ഒഴിവാക്കാൻ മതിയായ ഫണ്ട് ഉണ്ടെന്ന് ഉറപ്പാക്കുക.',
        'ar' => 'دفعتك بمبلغ {{amount}} لحساب {{text}} الخاص بك مجدولة في {{date}}. يرجى التأكد من وجود أموال كافية لتجنب توليد أي رسوم {{text}}.',
        'hi' => 'आपके {{text}} खाते के लिए {{amount}} का आपका भुगतान {{date}} के लिए निर्धारित है। कृपया सुनिश्चित करें कि किसी भी {{text}} शुल्क से बचने के लिए आपके पास पर्याप्त धनराशि है।',
    ],
    'payment_scheduled_2' => [
        'en' => 'Hi {{text}},

Thank you for scheduling your payment of {{amount}} for your {{text}} account on {{date}}. Please visit your account if you would like to make any changes ahead of this date.',
        'ml' => 'ഹായ് {{text}},

{{date}} ന് നിങ്ങളുടെ {{text}} അക്കൗണ്ടിനുള്ള {{amount}} എന്ന നിങ്ങളുടെ പേയ്മെന്റ് ഷെഡ്യൂൾ ചെയ്തതിന് നന്ദി. ഈ തീയതിക്ക് മുമ്പ് എന്തെങ്കിലും മാറ്റങ്ങൾ വരുത്താൻ ആഗ്രഹിക്കുന്നുവെങ്കിൽ നിങ്ങളുടെ അക്കൗണ്ട് സന്ദർശിക്കുക.',
        'ar' => 'مرحبا {{text}}،

شكرا لجدولة دفعتك بمبلغ {{amount}} لحساب {{text}} الخاص بك في {{date}}. يرجى زيارة حسابك إذا كنت ترغب في إجراء أي تغييرات قبل هذا التاريخ.',
        'hi' => 'नमस्ते {{text}},

{{date}} को अपने {{text}} खाते के लिए {{amount}} का अपना भुगतान निर्धारित करने के लिए धन्यवाद। यदि आप इस तिथि से पहले कोई परिवर्तन करना चाहते हैं तो कृपया अपना खाता देखें।',
    ],
    'payment_scheduled_3' => [
        'en' => 'Hi {{text}}, this is to remind you of your upcoming scheduled payment:

Date: {{date}}
Account: {{text}}
Amount: {{amount}}

Thank you and have a nice day.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ വരാനിരിക്കുന്ന ഷെഡ്യൂൾ ചെയ്ത പേയ്മെന്റ് ഓർമ്മിപ്പിക്കാനാണിത്:

തീയതി: {{date}}
അക്കൗണ്ട്: {{text}}
തുക: {{amount}}

നന്ദി, നല്ലൊരു ദിവസം ആശംസിക്കുന്നു.',
        'ar' => 'مرحبا {{text}}، هذا لتذكيرك بدفعتك المجدولة القادمة:

التاريخ: {{date}}
الحساب: {{text}}
المبلغ: {{amount}}

شكرا لك ويوم سعيد.',
        'hi' => 'नमस्ते {{text}}, यह आपको आपके आगामी निर्धारित भुगतान की याद दिलाने के लिए है:

तिथि: {{date}}
खाता: {{text}}
राशि: {{amount}}

धन्यवाद और आपका दिन शुभ हो।',
    ],
    'payment_successful' => [
        'en' => 'Hi {{text}}, your {{text}} bill payment of {{amount}} has been successfully received. Your payment date was {{date}}. Thank you!',
        'ml' => 'ഹായ് {{text}}, {{amount}} എന്ന നിങ്ങളുടെ {{text}} ബിൽ പേയ്മെന്റ് വിജയകരമായി ലഭിച്ചു. നിങ്ങളുടെ പേയ്മെന്റ് തീയതി {{date}} ആയിരുന്നു. നന്ദി!',
        'ar' => 'مرحبا {{text}}، تم استلام دفع فاتورة {{text}} الخاصة بك بمبلغ {{amount}} بنجاح. كان تاريخ دفعك {{date}}. شكرا لك!',
        'hi' => 'नमस्ते {{text}}, {{amount}} का आपका {{text}} बिल भुगतान सफलतापूर्वक प्राप्त हो गया है। आपकी भुगतान तिथि {{date}} थी। धन्यवाद!',
    ],
    'privacy_disclosure_1' => [
        'en' => 'We updated our privacy policy on {{date}}. Please click the button below to learn more.',
        'ml' => '{{date}} ന് ഞങ്ങൾ ഞങ്ങളുടെ സ്വകാര്യതാ നയം അപ്ഡേറ്റ് ചെയ്തു. കൂടുതലറിയാൻ താഴെയുള്ള ബട്ടൺ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'قمنا بتحديث سياسة الخصوصية الخاصة بنا في {{date}}. يرجى النقر على الزر أدناه لمعرفة المزيد.',
        'hi' => 'हमने {{date}} को अपनी गोपनीयता नीति अपडेट की। अधिक जानने के लिए कृपया नीचे दिए गए बटन पर क्लिक करें।',
    ],
    'product_recall_1' => [
        'en' => 'The {{text}} you ordered on {{date}} has been recalled. Please click below to let us know how you would like to proceed.',
        'ml' => '{{date}} ന് നിങ്ങൾ ഓർഡർ ചെയ്ത {{text}} തിരിച്ചുവിളിച്ചു. നിങ്ങൾ എങ്ങനെ മുന്നോട്ട് പോകാൻ ആഗ്രഹിക്കുന്നു എന്ന് അറിയിക്കാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'تم سحب {{text}} الذي طلبته في {{date}}. يرجى النقر أدناه لإخبارنا كيف ترغب في المتابعة.',
        'hi' => '{{date}} को आपके द्वारा ऑर्डर किए गए {{text}} को वापस बुला लिया गया है। कृपया हमें बताने के लिए नीचे क्लिक करें कि आप कैसे आगे बढ़ना चाहते हैं।',
    ],
    'purchase_receipt_1' => [
        'en' => 'Thank you for your purchase of {{amount}} from {{address}}. Your {{text}} PDF is attached.',
        'ml' => '{{address}} ൽ നിന്ന് {{amount}} വാങ്ങിയതിന് നന്ദി. നിങ്ങളുടെ {{text}} PDF അറ്റാച്ച് ചെയ്തിരിക്കുന്നു.',
        'ar' => 'شكرا لشرائك بمبلغ {{amount}} من {{address}}. تم إرفاق ملف {{text}} PDF الخاص بك.',
        'hi' => '{{address}} से {{amount}} की आपकी खरीद के लिए धन्यवाद। आपका {{text}} PDF संलग्न है।',
    ],
    'purchase_receipt_2' => [
        'en' => 'Thank you for using your {{text}} card at {{text}}. Your {{text}} is attached as a PDF.',
        'ml' => '{{text}} ൽ നിങ്ങളുടെ {{text}} കാർഡ് ഉപയോഗിച്ചതിന് നന്ദി. നിങ്ങളുടെ {{text}} ഒരു PDF ആയി അറ്റാച്ച് ചെയ്തിരിക്കുന്നു.',
        'ar' => 'شكرا لاستخدامك بطاقة {{text}} في {{text}}. تم إرفاق {{text}} الخاص بك كملف PDF.',
        'hi' => '{{text}} पर अपने {{text}} कार्ड का उपयोग करने के लिए धन्यवाद। आपका {{text}} PDF के रूप में संलग्न है।',
    ],
    'purchase_receipt_3' => [
        'en' => 'Hello {{text}},

Your invoice for order {{text}} is attached.

Thank you for shopping with us!',
        'ml' => 'ഹലോ {{text}},

ഓർഡർ {{text}} നുള്ള നിങ്ങളുടെ ഇൻവോയ്സ് അറ്റാച്ച് ചെയ്തിരിക്കുന്നു.

ഞങ്ങളോടൊപ്പം ഷോപ്പിംഗ് ചെയ്തതിന് നന്ദി!',
        'ar' => 'مرحبا {{text}}،

تم إرفاق فاتورتك للطلب {{text}}.

شكرا للتسوق معنا!',
        'hi' => 'नमस्ते {{text}},

ऑर्डर {{text}} के लिए आपका चालान संलग्न है।

हमारे साथ खरीदारी करने के लिए धन्यवाद!',
    ],
    'purchase_transaction_alert' => [
        'en' => 'This message is to confirm your {{text}} for {{amount}} from {{text}} on {{date}}.',
        'ml' => '{{date}} ന് {{text}} ൽ നിന്ന് {{amount}} നുള്ള നിങ്ങളുടെ {{text}} സ്ഥിരീകരിക്കാനുള്ള സന്ദേശമാണിത്.',
        'ar' => 'هذه الرسالة لتأكيد {{text}} الخاص بك بمبلغ {{amount}} من {{text}} في {{date}}.',
        'hi' => 'यह संदेश {{date}} को {{text}} से {{amount}} के लिए आपके {{text}} की पुष्टि करने के लिए है।',
    ],
    'recharge_failure' => [
        'en' => 'Hi {{text}}, we were unable to process your mobile recharge. Please try again or contact our support team.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ മൊബൈൽ റീചാർജ് പ്രോസസ് ചെയ്യാൻ ഞങ്ങൾക്ക് കഴിഞ്ഞില്ല. വീണ്ടും ശ്രമിക്കുകയോ ഞങ്ങളുടെ പിന്തുണാ ടീമിനെ ബന്ധപ്പെടുകയോ ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، لم نتمكن من معالجة إعادة شحن هاتفك المحمول. يرجى المحاولة مرة أخرى أو الاتصال بفريق الدعم لدينا.',
        'hi' => 'नमस्ते {{text}}, हम आपका मोबाइल रिचार्ज संसाधित करने में असमर्थ रहे। कृपया पुनः प्रयास करें या हमारी सहायता टीम से संपर्क करें।',
    ],
    'recharge_reminder' => [
        'en' => 'Hi {{text}}, your {{text}} is ending tonight. To continue using without interruption, please recharge.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ {{text}} ഇന്ന് രാത്രി അവസാനിക്കുന്നു. തടസ്സമില്ലാതെ ഉപയോഗിക്കുന്നത് തുടരാൻ, ദയവായി റീചാർജ് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، ينتهي {{text}} الخاص بك الليلة. للاستمرار في الاستخدام دون انقطاع، يرجى إعادة الشحن.',
        'hi' => 'नमस्ते {{text}}, आपका {{text}} आज रात समाप्त हो रहा है। बिना किसी रुकावट के उपयोग जारी रखने के लिए, कृपया रिचार्ज करें।',
    ],
    'recharge_successful' => [
        'en' => 'Hi {{text}}, your mobile recharge of {{amount}} has been successful! Your new balance is {{amount}}.',
        'ml' => 'ഹായ് {{text}}, {{amount}} എന്ന നിങ്ങളുടെ മൊബൈൽ റീചാർജ് വിജയകരമായി! നിങ്ങളുടെ പുതിയ ബാലൻസ് {{amount}} ആണ്.',
        'ar' => 'مرحبا {{text}}، نجحت إعادة شحن هاتفك المحمول بمبلغ {{amount}}! رصيدك الجديد هو {{amount}}.',
        'hi' => 'नमस्ते {{text}}, {{amount}} का आपका मोबाइल रिचार्ज सफल रहा! आपका नया बैलेंस {{amount}} है।',
    ],
    'refund_confirmation_1' => [
        'en' => 'Hi {{text}},

Your refund for {{amount}} has been processed for order {{text}}. You\'ll be credited back to your original payment method in 3-5 business days.',
        'ml' => 'ഹായ് {{text}},

ഓർഡർ {{text}} നായി {{amount}} നുള്ള നിങ്ങളുടെ റീഫണ്ട് പ്രോസസ് ചെയ്തു. 3-5 പ്രവൃത്തി ദിവസങ്ങൾക്കുള്ളിൽ നിങ്ങളുടെ യഥാർത്ഥ പേയ്മെന്റ് രീതിയിലേക്ക് തിരികെ ക്രെഡിറ്റ് ചെയ്യും.',
        'ar' => 'مرحبا {{text}}،

تمت معالجة استردادك بمبلغ {{amount}} للطلب {{text}}. سيتم رد المبلغ إلى طريقة الدفع الأصلية الخاصة بك خلال 3-5 أيام عمل.',
        'hi' => 'नमस्ते {{text}},

ऑर्डर {{text}} के लिए {{amount}} का आपका रिफंड संसाधित कर दिया गया है। आपको 3-5 व्यावसायिक दिनों में आपकी मूल भुगतान विधि में वापस क्रेडिट कर दिया जाएगा।',
    ],
    'renewal_reminder' => [
        'en' => 'Your {{text}} plan is scheduled to renew on {{date}}. Please maintain sufficient balance to keep the service active.',
        'ml' => 'നിങ്ങളുടെ {{text}} പ്ലാൻ {{date}} ന് പുതുക്കാൻ ഷെഡ്യൂൾ ചെയ്തിരിക്കുന്നു. സേവനം സജീവമായി നിലനിർത്താൻ മതിയായ ബാലൻസ് നിലനിർത്തുക.',
        'ar' => 'من المقرر تجديد خطة {{text}} الخاصة بك في {{date}}. يرجى الاحتفاظ برصيد كافٍ لإبقاء الخدمة نشطة.',
        'hi' => 'आपका {{text}} प्लान {{date}} को नवीनीकृत होने वाला है। सेवा को सक्रिय रखने के लिए कृपया पर्याप्त बैलेंस बनाए रखें।',
    ],
    'renewal_successful' => [
        'en' => 'Hi {{text}},

Your {{text}} plan has been successfully renewed.
Your new plan details are:

Plan name: {{text}}
Data limit: {{text}}
Validity: {{text}}

Thank you for choosing us!',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ {{text}} പ്ലാൻ വിജയകരമായി പുതുക്കി.
നിങ്ങളുടെ പുതിയ പ്ലാൻ വിശദാംശങ്ങൾ:

പ്ലാൻ പേര്: {{text}}
ഡാറ്റ പരിധി: {{text}}
സാധുത: {{text}}

ഞങ്ങളെ തിരഞ്ഞെടുത്തതിന് നന്ദി!',
        'ar' => 'مرحبا {{text}}،

تم تجديد خطة {{text}} الخاصة بك بنجاح.
تفاصيل خطتك الجديدة هي:

اسم الخطة: {{text}}
حد البيانات: {{text}}
الصلاحية: {{text}}

شكرا لاختيارك لنا!',
        'hi' => 'नमस्ते {{text}},

आपका {{text}} प्लान सफलतापूर्वक नवीनीकृत कर दिया गया है।
आपके नए प्लान का विवरण है:

प्लान का नाम: {{text}}
डेटा सीमा: {{text}}
वैधता: {{text}}

हमें चुनने के लिए धन्यवाद!',
    ],
    'request_contact_info_1' => [
        'en' => 'Hi {{text}}, we\'d like to have your phone number on file so we can reach you more easily. Please share your contact info below.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളെ കൂടുതൽ എളുപ്പത്തിൽ ബന്ധപ്പെടാൻ കഴിയുന്നതിന് നിങ്ങളുടെ ഫോൺ നമ്പർ ഞങ്ങളുടെ ഫയലിൽ വേണം. താഴെ നിങ്ങളുടെ കോൺടാക്റ്റ് വിവരങ്ങൾ പങ്കിടുക.',
        'ar' => 'مرحبا {{text}}، نود الاحتفاظ برقم هاتفك في ملفاتنا حتى نتمكن من الوصول إليك بسهولة أكبر. يرجى مشاركة معلومات الاتصال الخاصة بك أدناه.',
        'hi' => 'नमस्ते {{text}}, हम आपका फ़ोन नंबर फ़ाइल में रखना चाहते हैं ताकि हम आप तक आसानी से पहुंच सकें। कृपया नीचे अपनी संपर्क जानकारी साझा करें।',
    ],
    'request_contact_info_2' => [
        'en' => 'Hi {{text}}, please share your phone number with us so we can stay in touch and assist you better.',
        'ml' => 'ഹായ് {{text}}, ഞങ്ങൾക്ക് ബന്ധം നിലനിർത്താനും നിങ്ങളെ മികച്ച രീതിയിൽ സഹായിക്കാനും കഴിയുന്നതിന് ദയവായി നിങ്ങളുടെ ഫോൺ നമ്പർ ഞങ്ങളുമായി പങ്കിടുക.',
        'ar' => 'مرحبا {{text}}، يرجى مشاركة رقم هاتفك معنا حتى نتمكن من البقاء على تواصل ومساعدتك بشكل أفضل.',
        'hi' => 'नमस्ते {{text}}, कृपया अपना फ़ोन नंबर हमारे साथ साझा करें ताकि हम संपर्क में रह सकें और आपकी बेहतर सहायता कर सकें।',
    ],
    'request_contact_info_3' => [
        'en' => 'Hi {{text}}, having your phone number helps us serve you faster. Tap below to share your contact info.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഫോൺ നമ്പർ ഉള്ളത് നിങ്ങളെ വേഗത്തിൽ സേവിക്കാൻ ഞങ്ങളെ സഹായിക്കുന്നു. നിങ്ങളുടെ കോൺടാക്റ്റ് വിവരങ്ങൾ പങ്കിടാൻ താഴെ ടാപ്പ് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، وجود رقم هاتفك يساعدنا على خدمتك بشكل أسرع. انقر أدناه لمشاركة معلومات الاتصال الخاصة بك.',
        'hi' => 'नमस्ते {{text}}, आपका फ़ोन नंबर होने से हमें आपकी तेज़ी से सेवा करने में मदद मिलती है। अपनी संपर्क जानकारी साझा करने के लिए नीचे टैप करें।',
    ],
    'rescheduling_request' => [
        'en' => 'Hi {{text}}, we need to reschedule your {{text}}. Reply *Reschedule* to pick a new time.',
        'ml' => 'ഹായ് {{text}}, ഞങ്ങൾക്ക് നിങ്ങളുടെ {{text}} വീണ്ടും ഷെഡ്യൂൾ ചെയ്യേണ്ടതുണ്ട്. ഒരു പുതിയ സമയം തിരഞ്ഞെടുക്കാൻ *Reschedule* എന്ന് മറുപടി നൽകുക.',
        'ar' => 'مرحبا {{text}}، نحتاج إلى إعادة جدولة {{text}} الخاص بك. أرسل *Reschedule* لاختيار وقت جديد.',
        'hi' => 'नमस्ते {{text}}, हमें आपके {{text}} को पुनर्निर्धारित करने की आवश्यकता है। नया समय चुनने के लिए *Reschedule* का उत्तर दें।',
    ],
    'return_confirmation_1' => [
        'en' => 'Hi {{text}}, thank you for returning product(s) from your order {{text}}. We are currently processing your return and will notify you of your {{text}} status.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} ൽ നിന്ന് ഉൽപ്പന്നം(ങ്ങൾ) തിരികെ നൽകിയതിന് നന്ദി. ഞങ്ങൾ നിലവിൽ നിങ്ങളുടെ റിട്ടേൺ പ്രോസസ് ചെയ്യുന്നു, നിങ്ങളുടെ {{text}} സ്ഥിതി അറിയിക്കും.',
        'ar' => 'مرحبا {{text}}، شكرا لإرجاع المنتج (المنتجات) من طلبك {{text}}. نقوم حاليا بمعالجة إرجاعك وسنخطرك بحالة {{text}} الخاص بك.',
        'hi' => 'नमस्ते {{text}}, आपके ऑर्डर {{text}} से उत्पाद(ओं) को वापस करने के लिए धन्यवाद। हम वर्तमान में आपकी वापसी संसाधित कर रहे हैं और आपको आपकी {{text}} स्थिति के बारे में सूचित करेंगे।',
    ],
    'return_confirmation_2' => [
        'en' => 'We have received item(s) from your order {{text}}.

Your return is complete, and we have processed your {{text}} for {{amount}}.

Thank you for your business.',
        'ml' => 'നിങ്ങളുടെ ഓർഡർ {{text}} ൽ നിന്ന് ഇനം(ങ്ങൾ) ഞങ്ങൾക്ക് ലഭിച്ചു.

നിങ്ങളുടെ റിട്ടേൺ പൂർത്തിയായി, {{amount}} ന് നിങ്ങളുടെ {{text}} ഞങ്ങൾ പ്രോസസ് ചെയ്തു.

നിങ്ങളുടെ ബിസിനസിന് നന്ദി.',
        'ar' => 'استلمنا العنصر (العناصر) من طلبك {{text}}.

اكتمل إرجاعك، وقمنا بمعالجة {{text}} الخاص بك بمبلغ {{amount}}.

شكرا لتعاملك معنا.',
        'hi' => 'हमें आपके ऑर्डर {{text}} से वस्तु(एं) मिल गई हैं।

आपकी वापसी पूरी हो गई है, और हमने {{amount}} के लिए आपका {{text}} संसाधित कर दिया है।

आपके व्यापार के लिए धन्यवाद।',
    ],
    'roaming_reminder' => [
        'en' => 'Hi {{text}}, your number is currently on a network outside {{text}}. To avoid high pay-as-you-go charges, you can activate an international roaming pack.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ നമ്പർ നിലവിൽ {{text}} ന് പുറത്തുള്ള ഒരു നെറ്റ്‌വർക്കിലാണ്. ഉയർന്ന പേ-ആസ്-യു-ഗോ നിരക്കുകൾ ഒഴിവാക്കാൻ, നിങ്ങൾക്ക് ഒരു അന്താരാഷ്ട്ര റോമിംഗ് പായ്ക്ക് സജീവമാക്കാം.',
        'ar' => 'مرحبا {{text}}، رقمك حاليا على شبكة خارج {{text}}. لتجنب رسوم الدفع أولا بأول المرتفعة، يمكنك تفعيل باقة تجوال دولية.',
        'hi' => 'नमस्ते {{text}}, आपका नंबर वर्तमान में {{text}} के बाहर एक नेटवर्क पर है। उच्च पे-एज़-यू-गो शुल्क से बचने के लिए, आप एक अंतर्राष्ट्रीय रोमिंग पैक सक्रिय कर सकते हैं।',
    ],
    'service_disruption' => [
        'en' => 'Dear Customer, we have scheduled network updates on {{date}} between {{text}} and {{text}}. You may experience temporary service disruption. Thank you for your understanding.',
        'ml' => 'പ്രിയ ഉപഭോക്താവേ, {{date}} ന് {{text}} നും {{text}} നും ഇടയിൽ ഞങ്ങൾ നെറ്റ്‌വർക്ക് അപ്ഡേറ്റുകൾ ഷെഡ്യൂൾ ചെയ്തിട്ടുണ്ട്. നിങ്ങൾക്ക് താൽക്കാലിക സേവന തടസ്സം അനുഭവപ്പെട്ടേക്കാം. നിങ്ങളുടെ മനസ്സിലാക്കലിന് നന്ദി.',
        'ar' => 'عزيزي العميل، لقد قمنا بجدولة تحديثات الشبكة في {{date}} بين {{text}} و {{text}}. قد تواجه انقطاعا مؤقتا في الخدمة. شكرا لتفهمك.',
        'hi' => 'प्रिय ग्राहक, हमने {{date}} को {{text}} और {{text}} के बीच नेटवर्क अपडेट निर्धारित किए हैं। आपको अस्थायी सेवा व्यवधान का अनुभव हो सकता है। आपकी समझ के लिए धन्यवाद।',
    ],
    'severe_weather_alert_1' => [
        'en' => 'There is a {{text}} alert in the {{text}} area. We recommend you remain indoors until {{date}}. If you are facing issues related to {{text}} or {{text}}, you can inform us by using the button below and we will follow up for an on-site inspection. We will follow up with key updates and any recommended precautions. For more information on {{text}} preparedness, click the URL below.',
        'ml' => '{{text}} ഏരിയയിൽ ഒരു {{text}} അലേർട്ട് ഉണ്ട്. {{date}} വരെ വീടിനുള്ളിൽ തുടരാൻ ഞങ്ങൾ ശുപാർശ ചെയ്യുന്നു. {{text}} അല്ലെങ്കിൽ {{text}} മായി ബന്ധപ്പെട്ട പ്രശ്നങ്ങൾ നിങ്ങൾ നേരിടുന്നുവെങ്കിൽ, താഴെയുള്ള ബട്ടൺ ഉപയോഗിച്ച് നിങ്ങൾക്ക് ഞങ്ങളെ അറിയിക്കാം, ഞങ്ങൾ ഒരു ഓൺ-സൈറ്റ് പരിശോധനയ്ക്കായി ബന്ധപ്പെടും. പ്രധാന അപ്ഡേറ്റുകളും ശുപാർശ ചെയ്ത മുൻകരുതലുകളും ഞങ്ങൾ അറിയിക്കും. {{text}} തയ്യാറെടുപ്പിനെക്കുറിച്ചുള്ള കൂടുതൽ വിവരങ്ങൾക്ക്, താഴെയുള്ള URL ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'يوجد تنبيه {{text}} في منطقة {{text}}. نوصي بالبقاء في الداخل حتى {{date}}. إذا كنت تواجه مشكلات متعلقة بـ {{text}} أو {{text}}، يمكنك إبلاغنا باستخدام الزر أدناه وسنتابع لإجراء فحص ميداني. سنتابع بالتحديثات الرئيسية وأي احتياطات موصى بها. لمزيد من المعلومات حول الاستعداد لـ {{text}}، انقر على الرابط أدناه.',
        'hi' => '{{text}} क्षेत्र में एक {{text}} अलर्ट है। हम अनुशंसा करते हैं कि आप {{date}} तक घर के अंदर रहें। यदि आप {{text}} या {{text}} से संबंधित समस्याओं का सामना कर रहे हैं, तो आप नीचे दिए गए बटन का उपयोग करके हमें सूचित कर सकते हैं और हम ऑन-साइट निरीक्षण के लिए फॉलो अप करेंगे। हम प्रमुख अपडेट और किसी भी अनुशंसित सावधानियों के साथ फॉलो अप करेंगे। {{text}} तैयारी पर अधिक जानकारी के लिए, नीचे दिए गए URL पर क्लिक करें।',
    ],
    'severe_weather_alert_2' => [
        'en' => 'There is a {{text}} alert in the {{text}} area. For more information on precautions to take during a {{text}} click the URL below. For live updates on the {{text}} alert in the {{text}} area, visit our website {{URL}}.',
        'ml' => '{{text}} ഏരിയയിൽ ഒരു {{text}} അലേർട്ട് ഉണ്ട്. {{text}} സമയത്ത് എടുക്കേണ്ട മുൻകരുതലുകളെക്കുറിച്ചുള്ള കൂടുതൽ വിവരങ്ങൾക്ക് താഴെയുള്ള URL ക്ലിക്ക് ചെയ്യുക. {{text}} ഏരിയയിലെ {{text}} അലേർട്ടിനെക്കുറിച്ചുള്ള തത്സമയ അപ്ഡേറ്റുകൾക്ക്, ഞങ്ങളുടെ വെബ്സൈറ്റ് {{URL}} സന്ദർശിക്കുക.',
        'ar' => 'يوجد تنبيه {{text}} في منطقة {{text}}. لمزيد من المعلومات حول الاحتياطات الواجب اتخاذها أثناء {{text}}، انقر على الرابط أدناه. للحصول على تحديثات مباشرة حول تنبيه {{text}} في منطقة {{text}}، تفضل بزيارة موقعنا {{URL}}.',
        'hi' => '{{text}} क्षेत्र में एक {{text}} अलर्ट है। {{text}} के दौरान बरती जाने वाली सावधानियों पर अधिक जानकारी के लिए नीचे दिए गए URL पर क्लिक करें। {{text}} क्षेत्र में {{text}} अलर्ट पर लाइव अपडेट के लिए, हमारी वेबसाइट {{URL}} पर जाएं।',
    ],
    'shifting_journey' => [
        'en' => 'Hi {{text}}, your broadband connection shifting request is being processed! We\'ll keep you updated on the status.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ബ്രോഡ്ബാൻഡ് കണക്ഷൻ ഷിഫ്റ്റിംഗ് അഭ്യർത്ഥന പ്രോസസ് ചെയ്യുന്നു! സ്ഥിതിയെക്കുറിച്ച് ഞങ്ങൾ നിങ്ങളെ അപ്ഡേറ്റ് ചെയ്യും.',
        'ar' => 'مرحبا {{text}}، تتم معالجة طلب نقل اتصال النطاق العريض الخاص بك! سنبقيك على اطلاع بالحالة.',
        'hi' => 'नमस्ते {{text}}, आपके ब्रॉडबैंड कनेक्शन स्थानांतरण अनुरोध पर कार्रवाई की जा रही है! हम आपको स्थिति से अवगत कराते रहेंगे।',
    ],
    'shipment_confirmation_1' => [
        'en' => 'Hi {{text}}, your order has shipped!

Your tracking number is {{text}}.

Estimated delivery is {{date}}.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ ഷിപ്പ് ചെയ്തു!

നിങ്ങളുടെ ട്രാക്കിംഗ് നമ്പർ {{text}} ആണ്.

ഏകദേശ ഡെലിവറി {{date}} ആണ്.',
        'ar' => 'مرحبا {{text}}، تم شحن طلبك!

رقم التتبع الخاص بك هو {{text}}.

التسليم المتوقع هو {{date}}.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर शिप हो गया है!

आपका ट्रैकिंग नंबर {{text}} है।

अनुमानित डिलीवरी {{date}} है।',
    ],
    'shipment_confirmation_2' => [
        'en' => 'Hi {{text}}, great news! Your order {{text}} has shipped.

Tracking #: {{text}}
Estimated delivery: {{date}}

We will provide updates until delivery.',
        'ml' => 'ഹായ് {{text}}, സന്തോഷവാർത്ത! നിങ്ങളുടെ ഓർഡർ {{text}} ഷിപ്പ് ചെയ്തു.

ട്രാക്കിംഗ് #: {{text}}
ഏകദേശ ഡെലിവറി: {{date}}

ഡെലിവറി വരെ ഞങ്ങൾ അപ്ഡേറ്റുകൾ നൽകും.',
        'ar' => 'مرحبا {{text}}، أخبار رائعة! تم شحن طلبك {{text}}.

رقم التتبع: {{text}}
التسليم المتوقع: {{date}}

سنقدم تحديثات حتى التسليم.',
        'hi' => 'नमस्ते {{text}}, बढ़िया खबर! आपका ऑर्डर {{text}} शिप हो गया है।

ट्रैकिंग #: {{text}}
अनुमानित डिलीवरी: {{date}}

हम डिलीवरी तक अपडेट प्रदान करेंगे।',
    ],
    'shipment_confirmation_3' => [
        'en' => 'Hi {{text}}, your order {{text}} has left our {{text}} and is on its way to you!

Your tracking ID is {{text}}.

Click below to track your package.',
        'ml' => 'ഹായ് {{text}}, നിങ്ങളുടെ ഓർഡർ {{text}} ഞങ്ങളുടെ {{text}} വിട്ട് നിങ്ങളുടെ അടുത്തേക്ക് വരുന്നു!

നിങ്ങളുടെ ട്രാക്കിംഗ് ഐഡി {{text}} ആണ്.

നിങ്ങളുടെ പാക്കേജ് ട്രാക്ക് ചെയ്യാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، غادر طلبك {{text}} {{text}} الخاص بنا وهو في طريقه إليك!

معرف التتبع الخاص بك هو {{text}}.

انقر أدناه لتتبع طردك.',
        'hi' => 'नमस्ते {{text}}, आपका ऑर्डर {{text}} हमारे {{text}} से निकल चुका है और आपके पास आ रहा है!

आपकी ट्रैकिंग आईडी {{text}} है।

अपने पैकेज को ट्रैक करने के लिए नीचे क्लिक करें।',
    ],
    'shipment_confirmation_4' => [
        'en' => 'Hi {{text}},

We\'re happy to inform you that your order {{text}} has shipped! Click view order details to view the status of your shipment.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഷിപ്പ് ചെയ്തു എന്ന് അറിയിക്കുന്നതിൽ ഞങ്ങൾക്ക് സന്തോഷമുണ്ട്! നിങ്ങളുടെ ഷിപ്പ്മെന്റിന്റെ സ്ഥിതി കാണാൻ ഓർഡർ വിശദാംശങ്ങൾ കാണുക ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}،

يسعدنا إبلاغك بأن طلبك {{text}} قد تم شحنه! انقر على عرض تفاصيل الطلب لعرض حالة شحنتك.',
        'hi' => 'नमस्ते {{text}},

हमें आपको यह सूचित करते हुए खुशी हो रही है कि आपका ऑर्डर {{text}} शिप हो गया है! अपने शिपमेंट की स्थिति देखने के लिए ऑर्डर विवरण देखें पर क्लिक करें।',
    ],
    'shipment_confirmation_5' => [
        'en' => 'Hi {{text}},

We’re happy to inform you that your order {{text}} has shipped! Click below to view the status of your shipment.',
        'ml' => 'ഹായ് {{text}},

നിങ്ങളുടെ ഓർഡർ {{text}} ഷിപ്പ് ചെയ്തു എന്ന് അറിയിക്കുന്നതിൽ ഞങ്ങൾക്ക് സന്തോഷമുണ്ട്! നിങ്ങളുടെ ഷിപ്പ്മെന്റിന്റെ സ്ഥിതി കാണാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}،

يسعدنا إبلاغك بأن طلبك {{text}} قد تم شحنه! انقر أدناه لعرض حالة شحنتك.',
        'hi' => 'नमस्ते {{text}},

हमें आपको यह सूचित करते हुए खुशी हो रही है कि आपका ऑर्डर {{text}} शिप हो गया है! अपने शिपमेंट की स्थिति देखने के लिए नीचे क्लिक करें।',
    ],
    'statement_available_1' => [
        'en' => 'Hi {{text}}, Your {{text}} statement for your account ending in {{number}} is now available. Click below to see your statement.',
        'ml' => 'ഹായ് {{text}}, {{number}} ൽ അവസാനിക്കുന്ന നിങ്ങളുടെ അക്കൗണ്ടിനുള്ള നിങ്ങളുടെ {{text}} സ്റ്റേറ്റ്മെന്റ് ഇപ്പോൾ ലഭ്യമാണ്. നിങ്ങളുടെ സ്റ്റേറ്റ്മെന്റ് കാണാൻ താഴെ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، كشف حساب {{text}} الخاص بك لحسابك المنتهي بـ {{number}} متاح الآن. انقر أدناه لرؤية كشف حسابك.',
        'hi' => 'नमस्ते {{text}}, {{number}} में समाप्त होने वाले आपके खाते के लिए आपका {{text}} विवरण अब उपलब्ध है। अपना विवरण देखने के लिए नीचे क्लिक करें।',
    ],
    'statement_available_2' => [
        'en' => 'This is to notify you that your latest statement for your {{text}} account is now available. Please log into your account to view your statement.',
        'ml' => 'നിങ്ങളുടെ {{text}} അക്കൗണ്ടിനുള്ള നിങ്ങളുടെ ഏറ്റവും പുതിയ സ്റ്റേറ്റ്മെന്റ് ഇപ്പോൾ ലഭ്യമാണ് എന്ന് അറിയിക്കാനാണിത്. നിങ്ങളുടെ സ്റ്റേറ്റ്മെന്റ് കാണാൻ നിങ്ങളുടെ അക്കൗണ്ടിലേക്ക് ലോഗിൻ ചെയ്യുക.',
        'ar' => 'هذا لإعلامك بأن أحدث كشف حساب لحساب {{text}} الخاص بك متاح الآن. يرجى تسجيل الدخول إلى حسابك لعرض كشف حسابك.',
        'hi' => 'यह आपको सूचित करने के लिए है कि आपके {{text}} खाते के लिए आपका नवीनतम विवरण अब उपलब्ध है। अपना विवरण देखने के लिए कृपया अपने खाते में लॉग इन करें।',
    ],
    'support_ticket_acknowledgement' => [
        'en' => 'Your request {{number}} is registered. We will contact you within {{number}} hours.',
        'ml' => 'നിങ്ങളുടെ അഭ്യർത്ഥന {{number}} രജിസ്റ്റർ ചെയ്തു. {{number}} മണിക്കൂറിനുള്ളിൽ ഞങ്ങൾ നിങ്ങളെ ബന്ധപ്പെടും.',
        'ar' => 'تم تسجيل طلبك {{number}}. سنتصل بك في غضون {{number}} ساعة.',
        'hi' => 'आपका अनुरोध {{number}} पंजीकृत है। हम {{number}} घंटों के भीतर आपसे संपर्क करेंगे।',
    ],
    'system_outage_1' => [
        'en' => 'We have detected a system outage that impacts zip code {{text}}. We expect to restore service by {{date}}. We apologize for the inconvenience.',
        'ml' => 'സിപ് കോഡ് {{text}} നെ ബാധിക്കുന്ന ഒരു സിസ്റ്റം തടസ്സം ഞങ്ങൾ കണ്ടെത്തി. {{date}} നകം സേവനം പുനഃസ്ഥാപിക്കുമെന്ന് ഞങ്ങൾ പ്രതീക്ഷിക്കുന്നു. അസൗകര്യത്തിന് ഞങ്ങൾ ക്ഷമ ചോദിക്കുന്നു.',
        'ar' => 'اكتشفنا انقطاعا في النظام يؤثر على الرمز البريدي {{text}}. نتوقع استعادة الخدمة بحلول {{date}}. نعتذر عن الإزعاج.',
        'hi' => 'हमने एक सिस्टम आउटेज का पता लगाया है जो ज़िप कोड {{text}} को प्रभावित करता है। हम {{date}} तक सेवा बहाल करने की उम्मीद करते हैं। असुविधा के लिए हम क्षमा चाहते हैं।',
    ],
    'system_outage_2' => [
        'en' => 'The system outage has been restored for zip code {{text}}. If you are still experiencing an outage in zip code {{text}}, click the button below to alert us.',
        'ml' => 'സിപ് കോഡ് {{text}} നായി സിസ്റ്റം തടസ്സം പുനഃസ്ഥാപിച്ചു. സിപ് കോഡ് {{text}} ൽ നിങ്ങൾക്ക് ഇപ്പോഴും തടസ്സം അനുഭവപ്പെടുന്നുവെങ്കിൽ, ഞങ്ങളെ അറിയിക്കാൻ താഴെയുള്ള ബട്ടൺ ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'تمت استعادة انقطاع النظام للرمز البريدي {{text}}. إذا كنت لا تزال تواجه انقطاعا في الرمز البريدي {{text}}، فانقر على الزر أدناه لتنبيهنا.',
        'hi' => 'ज़िप कोड {{text}} के लिए सिस्टम आउटेज बहाल कर दिया गया है। यदि आप अभी भी ज़िप कोड {{text}} में आउटेज का अनुभव कर रहे हैं, तो हमें सचेत करने के लिए नीचे दिए गए बटन पर क्लिक करें।',
    ],
    'technician_arrival' => [
        'en' => 'Hi {{text}}, our technician will arrive at your location within the next {{text}}. Tap to track real-time location.',
        'ml' => 'ഹായ് {{text}}, ഞങ്ങളുടെ ടെക്നീഷ്യൻ അടുത്ത {{text}} നുള്ളിൽ നിങ്ങളുടെ സ്ഥലത്ത് എത്തും. തത്സമയ ലൊക്കേഷൻ ട്രാക്ക് ചെയ്യാൻ ടാപ്പ് ചെയ്യുക.',
        'ar' => 'مرحبا {{text}}، سيصل الفني إلى موقعك خلال {{text}} القادمة. انقر لتتبع الموقع في الوقت الفعلي.',
        'hi' => 'नमस्ते {{text}}, हमारा तकनीशियन अगले {{text}} के भीतर आपके स्थान पर पहुंचेगा। वास्तविक समय स्थान को ट्रैक करने के लिए टैप करें।',
    ],
    'upgrade_confirmation' => [
        'en' => 'We are pleased to inform you that your internet speed has been upgraded to {{number}} Mbps. Thank you for choosing our services.',
        'ml' => 'നിങ്ങളുടെ ഇന്റർനെറ്റ് വേഗത {{number}} Mbps ലേക്ക് അപ്ഗ്രേഡ് ചെയ്തു എന്ന് അറിയിക്കുന്നതിൽ ഞങ്ങൾക്ക് സന്തോഷമുണ്ട്. ഞങ്ങളുടെ സേവനങ്ങൾ തിരഞ്ഞെടുത്തതിന് നന്ദി.',
        'ar' => 'يسعدنا إبلاغك بأنه تمت ترقية سرعة الإنترنت الخاصة بك إلى {{number}} ميجابت في الثانية. شكرا لاختيارك خدماتنا.',
        'hi' => 'हमें आपको यह सूचित करते हुए खुशी हो रही है कि आपकी इंटरनेट स्पीड को {{number}} एमबीपीएस में अपग्रेड कर दिया गया है। हमारी सेवाओं को चुनने के लिए धन्यवाद।',
    ],
    'voting_registration_1' => [
        'en' => 'To vote on {{date}}, please ensure your voter {{text}} is active. Please click the URL below to understand steps required to renew, if needed.',
        'ml' => '{{date}} ന് വോട്ട് ചെയ്യാൻ, നിങ്ങളുടെ വോട്ടർ {{text}} സജീവമാണെന്ന് ഉറപ്പാക്കുക. ആവശ്യമെങ്കിൽ പുതുക്കാൻ വേണ്ട ഘട്ടങ്ങൾ മനസ്സിലാക്കാൻ താഴെയുള്ള URL ക്ലിക്ക് ചെയ്യുക.',
        'ar' => 'للتصويت في {{date}}، يرجى التأكد من أن {{text}} الناخب الخاص بك نشط. يرجى النقر على الرابط أدناه لفهم الخطوات المطلوبة للتجديد، إذا لزم الأمر.',
        'hi' => '{{date}} को मतदान करने के लिए, कृपया सुनिश्चित करें कि आपका मतदाता {{text}} सक्रिय है। यदि आवश्यक हो, तो नवीनीकरण के लिए आवश्यक चरणों को समझने के लिए कृपया नीचे दिए गए URL पर क्लिक करें।',
    ],
    'warranty_alert_1' => [
        'en' => 'Thank you for your purchase of {{text}}. Your warranty is active as of {{date}}. Our {{text}} are below, for your reference.',
        'ml' => '{{text}} വാങ്ങിയതിന് നന്ദി. നിങ്ങളുടെ വാറന്റി {{date}} മുതൽ സജീവമാണ്. നിങ്ങളുടെ റഫറൻസിനായി ഞങ്ങളുടെ {{text}} താഴെയുണ്ട്.',
        'ar' => 'شكرا لشرائك {{text}}. الضمان الخاص بك نشط اعتبارا من {{date}}. {{text}} الخاصة بنا أدناه، للرجوع إليها.',
        'hi' => '{{text}} खरीदने के लिए धन्यवाद। आपकी वारंटी {{date}} से सक्रिय है। आपके संदर्भ के लिए हमारे {{text}} नीचे हैं।',
    ],
];
