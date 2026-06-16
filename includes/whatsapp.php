<?php
/**
 * WhatsApp Cloud API helper + conversational booking bot.
 *
 * Requires config.php + functions.php + wallet.php to already be loaded.
 */

// ─── Default fallback message templates ────────────────────────────────────────

const DEFAULT_WA_TEMPLATES = [
    'welcome'           => "👋 Welcome to *{{business_name}}*!\n\nHow can we help you today?",
    'booking_confirm'   => "✅ *Appointment Confirmed!*\n\n📋 Service: {{service_name}}\n📅 Date: {{date}}\n{{time_line}}👤 Staff: {{staff_name}}\n💰 Price: {{price}}\n🔖 Booking #: {{appointment_number}}\n\nWe look forward to seeing you, {{customer_name}}! 🙏",
    'booking_cancel'    => "❌ Your appointment ({{appointment_number}}) for *{{service_name}}* on {{date}}{{time_at}} has been cancelled.\n\nIf you'd like to rebook, just send us a message anytime — we're happy to help, {{customer_name}}.",
    'reminder_24h'      => "⏰ Reminder: You have an appointment for *{{service_name}}* tomorrow{{time_at}}.\n\nSee you soon, {{customer_name}}!",
    'reminder_1h'       => "⏰ Reminder: Your appointment for *{{service_name}}* is in 1 hour{{time_at}}.\n\nSee you soon, {{customer_name}}!",
    'follow_up'         => "Hi {{customer_name}}, thank you for visiting *{{business_name}}*! We hope you enjoyed {{service_name}}. 😊",
    'review_request'    => "Hi {{customer_name}}, we'd love to hear your feedback on your recent {{service_name}} visit at *{{business_name}}*. Reply with a rating from 1-5 ⭐",
    'doctor_available'  => "🟢 *{{business_name}}*\n\nDear {{customer_name}}, your doctor/staff *{{staff_name}}* is now available.\n\n📋 Service: {{service_name}}\n📅 Date: {{date}}\n\nPlease come in at your earliest convenience. 🙏",
];

// Localized fallback versions of the appointment-lifecycle templates (used when
// the business hasn't customized the template and the customer's saved language
// is not English).
const DEFAULT_WA_TEMPLATES_I18N = [
    'hi' => [
        'booking_confirm'  => "✅ *अपॉइंटमेंट कन्फर्म हो गई!*\n\n📋 सेवा: {{service_name}}\n📅 तारीख: {{date}}\n{{time_line}}👤 स्टाफ: {{staff_name}}\n💰 कीमत: {{price}}\n🔖 बुकिंग नंबर: {{appointment_number}}\n\n{{customer_name}}, हम आपका इंतज़ार करेंगे! 🙏",
        'booking_cancel'   => "❌ आपकी अपॉइंटमेंट ({{appointment_number}}) — *{{service_name}}*, {{date}}{{time_at}} — रद्द कर दी गई है।\n\nदोबारा बुक करने के लिए कभी भी हमें संदेश भेजें, {{customer_name}}।",
        'doctor_available' => "🟢 *{{business_name}}*\n\n{{customer_name}}, आपके डॉक्टर/स्टाफ *{{staff_name}}* अब उपलब्ध हैं।\n\n📋 सेवा: {{service_name}}\n📅 तारीख: {{date}}\n\nकृपया जल्द से जल्द आएं। 🙏",
    ],
    'pa' => [
        'booking_confirm'  => "✅ *ਅਪੌਇੰਟਮੈਂਟ ਕਨਫਰਮ ਹੋ ਗਈ!*\n\n📋 ਸੇਵਾ: {{service_name}}\n📅 ਮਿਤੀ: {{date}}\n{{time_line}}👤 ਸਟਾਫ: {{staff_name}}\n💰 ਕੀਮਤ: {{price}}\n🔖 ਬੁਕਿੰਗ ਨੰਬਰ: {{appointment_number}}\n\n{{customer_name}}, ਅਸੀਂ ਤੁਹਾਡੀ ਉਡੀਕ ਕਰਾਂਗੇ! 🙏",
        'booking_cancel'   => "❌ ਤੁਹਾਡੀ ਅਪੌਇੰਟਮੈਂਟ ({{appointment_number}}) — *{{service_name}}*, {{date}}{{time_at}} — ਰੱਦ ਕਰ ਦਿੱਤੀ ਗਈ ਹੈ।\n\nਦੁਬਾਰਾ ਬੁੱਕ ਕਰਨ ਲਈ ਕਿਸੇ ਵੀ ਸਮੇਂ ਸਾਨੂੰ ਸੁਨੇਹਾ ਭੇਜੋ, {{customer_name}}।",
        'doctor_available' => "🟢 *{{business_name}}*\n\n{{customer_name}}, ਤੁਹਾਡੇ ਡਾਕਟਰ/ਸਟਾਫ *{{staff_name}}* ਹੁਣ ਉਪਲਬਧ ਹਨ।\n\n📋 ਸੇਵਾ: {{service_name}}\n📅 ਮਿਤੀ: {{date}}\n\nਕਿਰਪਾ ਕਰਕੇ ਜਲਦੀ ਆਓ। 🙏",
    ],
];

/** Languages supported by the WhatsApp booking bot. */
const WA_LANGS = [
    'en' => 'English',
    'hi' => 'हिंदी',
    'pa' => 'ਪੰਜਾਬੀ',
];

/** Format an appointment ID as a customer-facing booking number. */
function formatAppointmentNumber(int $appointmentId): string {
    return '#' . str_pad((string)$appointmentId, 6, '0', STR_PAD_LEFT);
}

// ─── Config helpers ─────────────────────────────────────────────────────────────

function getWhatsappConfig(int $businessId): ?array {
    $stmt = db()->prepare("SELECT * FROM whatsapp_configs WHERE business_id = ?");
    $stmt->execute([$businessId]);
    return $stmt->fetch() ?: null;
}

function isWhatsappConnected(int $businessId): bool {
    $config = getWhatsappConfig($businessId);
    return $config && (int)$config['is_connected'] === 1
        && !empty($config['access_token']) && !empty($config['phone_number_id']);
}

// ─── Message logging ─────────────────────────────────────────────────────────────

function logWhatsappMessage(int $businessId, string $phone, string $direction, string $type, string $content, ?int $customerId = null, ?string $waMessageId = null): void {
    $isRead = $direction === 'outbound' ? 1 : 0;
    $stmt = db()->prepare("
        INSERT INTO whatsapp_messages (business_id, customer_phone, customer_id, direction, message_type, content, wa_message_id, is_read)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$businessId, $phone, $customerId, $direction, $type, $content, $waMessageId, $isRead]);
}

// ─── Sending messages via WhatsApp Cloud API ─────────────────────────────────────

/**
 * Low-level POST to the Cloud API /messages endpoint.
 * Returns ['success' => bool, 'wa_message_id' => ?string].
 */
function waApiPost(array $config, array $payload): array {
    $url = "https://graph.facebook.com/v19.0/{$config['phone_number_id']}/messages";

    $waMessageId = null;
    $success = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $config['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response) {
            $decoded = json_decode($response, true);
            $waMessageId = $decoded['messages'][0]['id'] ?? null;
        }
        $success = $httpCode >= 200 && $httpCode < 300;
    }

    return ['success' => $success, 'wa_message_id' => $waMessageId];
}

function sendWhatsappMessage(int $businessId, string $to, string $text): bool {
    $config = getWhatsappConfig($businessId);

    if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
        return false;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $text],
    ];

    $result = waApiPost($config, $payload);
    logWhatsappMessage($businessId, $to, 'outbound', 'text', $text, null, $result['wa_message_id']);

    return $result['success'];
}

/**
 * Send up to 3 quick-reply buttons.
 * $buttons: array of ['id' => string, 'title' => string]
 */
function sendWhatsappInteractiveButtons(int $businessId, string $to, string $bodyText, array $buttons, ?string $headerText = null): bool {
    $config = getWhatsappConfig($businessId);

    if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
        return false;
    }

    $buttons = array_slice($buttons, 0, 3);

    $interactive = [
        'type'   => 'button',
        'body'   => ['text' => $bodyText],
        'action' => [
            'buttons' => array_map(fn($b) => [
                'type'  => 'reply',
                'reply' => ['id' => (string)$b['id'], 'title' => mb_substr($b['title'], 0, 20)],
            ], $buttons),
        ],
    ];
    if ($headerText) $interactive['header'] = ['type' => 'text', 'text' => $headerText];

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'interactive',
        'interactive'       => $interactive,
    ];

    $result = waApiPost($config, $payload);

    $logText = $bodyText . "\n" . implode(' | ', array_map(fn($b) => '[' . $b['title'] . ']', $buttons));
    logWhatsappMessage($businessId, $to, 'outbound', 'interactive', $logText, null, $result['wa_message_id']);

    return $result['success'];
}

/**
 * Send an interactive list message (popup with selectable rows).
 * $sections: array of ['title' => ?string, 'rows' => array of ['id'=>string,'title'=>string,'description'=>?string]]
 * Total rows across all sections should be <= 10.
 */
function sendWhatsappInteractiveList(int $businessId, string $to, string $bodyText, string $buttonText, array $sections, ?string $headerText = null): bool {
    $config = getWhatsappConfig($businessId);

    if (!$config || empty($config['access_token']) || empty($config['phone_number_id'])) {
        return false;
    }

    $waSections = [];
    $logLines   = [];

    foreach ($sections as $section) {
        $rows = [];
        foreach ($section['rows'] ?? [] as $row) {
            $r = ['id' => (string)$row['id'], 'title' => mb_substr($row['title'], 0, 24)];
            if (!empty($row['description'])) $r['description'] = mb_substr($row['description'], 0, 72);
            $rows[] = $r;
            $logLines[] = '[' . $row['title'] . ']';
        }
        if (empty($rows)) continue;

        $waSection = ['rows' => $rows];
        if (!empty($section['title'])) $waSection['title'] = mb_substr($section['title'], 0, 24);
        $waSections[] = $waSection;
    }

    if (empty($waSections)) return false;

    $interactive = [
        'type'   => 'list',
        'body'   => ['text' => $bodyText],
        'action' => [
            'button'   => mb_substr($buttonText, 0, 20),
            'sections' => $waSections,
        ],
    ];
    if ($headerText) $interactive['header'] = ['type' => 'text', 'text' => $headerText];

    $payload = [
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
        'type'              => 'interactive',
        'interactive'       => $interactive,
    ];

    $result = waApiPost($config, $payload);

    logWhatsappMessage($businessId, $to, 'outbound', 'interactive', $bodyText . "\n" . implode(' | ', $logLines), null, $result['wa_message_id']);

    return $result['success'];
}

// ─── Templates ────────────────────────────────────────────────────────────────

function getTemplateContent(int $businessId, string $type, string $lang = 'en'): string {
    $stmt = db()->prepare("SELECT content FROM message_templates WHERE business_id = ? AND template_type = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$businessId, $type]);
    $row = $stmt->fetch();
    if ($row && !empty($row['content'])) return $row['content'];
    return DEFAULT_WA_TEMPLATES_I18N[$lang][$type] ?? DEFAULT_WA_TEMPLATES[$type] ?? '';
}

function renderTemplate(string $content, array $vars): string {
    foreach ($vars as $key => $val) {
        $content = str_replace('{{' . $key . '}}', (string)$val, $content);
    }
    return $content;
}

/**
 * Send a status-change notification (confirm / cancel) to the customer for an appointment.
 */
function sendAppointmentStatusMessage(int $businessId, int $appointmentId, string $status): void {
    $type = match ($status) {
        'confirmed' => 'booking_confirm',
        'cancelled' => 'booking_cancel',
        default     => null,
    };
    if (!$type || !isWhatsappConnected($businessId)) return;

    $stmt = db()->prepare("
        SELECT a.*, c.phone AS customer_phone, c.name AS customer_name, c.language AS customer_language,
               s.name AS service_name, st.name AS staff_name,
               b.name AS business_name, b.currency AS currency, b.token_mode AS token_mode
        FROM appointments a
        LEFT JOIN customers c  ON c.id = a.customer_id
        LEFT JOIN services  s  ON s.id = a.service_id
        LEFT JOIN staff     st ON st.id = a.staff_id
        LEFT JOIN businesses b ON b.id = a.business_id
        WHERE a.id = ? AND a.business_id = ?
    ");
    $stmt->execute([$appointmentId, $businessId]);
    $appt = $stmt->fetch();
    if (!$appt || empty($appt['customer_phone'])) return;

    $lang = $appt['customer_language'] ?? '';
    if (!isset(WA_LANGS[$lang])) $lang = 'en';

    $isDateOnly = (substr($appt['appointment_time'] ?? '', 0, 5) === '00:00');
    $timeFmt    = $isDateOnly ? '' : formatTime($appt['appointment_time']);
    $timeLine   = $isDateOnly ? '' : "🕐 Time: {$timeFmt}\n";
    $timeAt     = $isDateOnly ? '' : " at {$timeFmt}";
    $apptNum    = (($appt['token_mode'] ?? 'db_id') === 'daily' && !empty($appt['daily_token']))
                    ? "Token #{$appt['daily_token']}"
                    : formatAppointmentNumber((int)$appt['id']);

    $content = getTemplateContent($businessId, $type, $lang);
    $vars = [
        'customer_name'      => $appt['customer_name'] ?: 'there',
        'business_name'      => $appt['business_name'],
        'service_name'       => $appt['service_name'],
        'staff_name'         => $appt['staff_name'] ?: 'our team',
        'date'               => formatDate($appt['appointment_date']),
        'time'               => $timeFmt,
        'time_line'          => $timeLine,
        'time_at'            => $timeAt,
        'price'              => formatPrice((float)$appt['total_price'], $appt['currency'] ?? 'USD'),
        'appointment_number' => $apptNum,
    ];

    sendWhatsappMessage($businessId, $appt['customer_phone'], renderTemplate($content, $vars));
}

function sendDoctorAvailableMessage(int $businessId, int $appointmentId): bool {
    if (!isWhatsappConnected($businessId)) return false;

    $stmt = db()->prepare("
        SELECT a.*, c.phone AS customer_phone, c.name AS customer_name, c.language AS customer_language,
               s.name AS service_name, st.name AS staff_name,
               b.name AS business_name
        FROM appointments a
        LEFT JOIN customers c  ON c.id  = a.customer_id
        LEFT JOIN services  s  ON s.id  = a.service_id
        LEFT JOIN staff     st ON st.id = a.staff_id
        LEFT JOIN businesses b ON b.id  = a.business_id
        WHERE a.id = ? AND a.business_id = ?
    ");
    $stmt->execute([$appointmentId, $businessId]);
    $appt = $stmt->fetch();
    if (!$appt || empty($appt['customer_phone'])) return false;

    $lang = $appt['customer_language'] ?? 'en';
    if (!isset(WA_LANGS[$lang])) $lang = 'en';

    $content = getTemplateContent($businessId, 'doctor_available', $lang);
    $vars = [
        'customer_name' => $appt['customer_name'] ?: 'there',
        'business_name' => $appt['business_name'],
        'service_name'  => $appt['service_name'],
        'staff_name'    => $appt['staff_name'] ?: 'our team',
        'date'          => formatDate($appt['appointment_date']),
    ];

    return sendWhatsappMessage($businessId, $appt['customer_phone'], renderTemplate($content, $vars));
}

// ─── Session management ──────────────────────────────────────────────────────────

function getWhatsappSession(int $businessId, string $phone): array {
    $stmt = db()->prepare("SELECT * FROM whatsapp_sessions WHERE business_id = ? AND customer_phone = ?");
    $stmt->execute([$businessId, $phone]);
    $row = $stmt->fetch();

    if ($row) {
        $row['session_data'] = json_decode($row['session_data'] ?? '', true) ?: [];
        return $row;
    }

    return [
        'business_id'    => $businessId,
        'customer_phone' => $phone,
        'session_state'  => 'idle',
        'session_data'   => [],
        'appointment_id' => null,
    ];
}

function saveWhatsappSession(int $businessId, string $phone, string $state, array $data, ?int $appointmentId = null): void {
    $stmt = db()->prepare("
        INSERT INTO whatsapp_sessions (business_id, customer_phone, session_state, session_data, appointment_id)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE session_state = VALUES(session_state), session_data = VALUES(session_data), appointment_id = VALUES(appointment_id)
    ");
    $stmt->execute([$businessId, $phone, $state, json_encode($data), $appointmentId]);
}

function resetWhatsappSession(int $businessId, string $phone): void {
    saveWhatsappSession($businessId, $phone, 'idle', []);
}

// ─── Translation strings (en / hi / pa) ────────────────────────────────────────

function waStrings(): array {
    return [
        'en' => [
            'choose_language'     => "🌐 Please select your language:",
            'welcome'             => "👋 Welcome to *{{business_name}}*!",
            'setting_up'          => "We're currently setting up our services. Please check back soon.",
            'choose_category'     => "Browse our services and select a category:",
            'tap_to_explore'      => "Tap to explore",
            'view_menu'           => "View Menu",
            'choose_service'      => "Great choice! Please select a service:",
            'view_services'       => "View Services",
            'choose_staff'        => "Who would you like to book with?",
            'select_staff'        => "Select Staff",
            'any_staff'           => "Any available staff",
            'choose_date'         => "Please select a date:",
            'select_date'         => "Select Date",
            'today'               => "Today",
            'tomorrow'            => "Tomorrow",
            'choose_time'         => "Available time slots for {{date}}:",
            'select_time'         => "Select Time",
            'no_slots'            => "Sorry, there are no available slots on that day 😔\n\nPlease choose another date:",
            'no_services_in_cat'  => "Sorry, no services are available in that category right now. Send *menu* to start over.",
            'invalid_option'      => "Sorry, that's not a valid option 🙏\n\nPlease try again, or send *menu* to start over.",
            'ask_name'            => "Almost done! What name should we book this appointment under?",
            'name_required'       => "Please reply with your name to complete the booking.",
            'slot_taken'          => "Sorry, that time slot was just booked by someone else 😔\n\nSend *menu* to start a new booking.",
            'something_wrong'     => "Sorry, something went wrong. Send *menu* to start over.",
            'staff_line'          => "👤 Staff: {{staff_name}}\n",
            'price_line'          => "💰 Price: {{price}}\n",
            'booking_received'    => "✅ *Booking Received!*\n\n📋 Service: {{service_name}}\n📅 Date: {{date}}\n{{time_line}}{{staff_line}}{{price_line}}🔖 Booking #: {{appointment_number}}\n\nStatus: ⏳ *Waiting for confirmation*\n\nWe'll send you a message as soon as it's confirmed. Thank you, {{customer_name}}! 🙏",
            'appointments_empty'  => "You don't have any upcoming appointments.\n\nSend *menu* to book one!",
            'appointments_header' => "📅 *Your Upcoming Appointments:*",
            'send_menu_to_book'   => "Send *menu* to book a new appointment.",
        ],
        'hi' => [
            'choose_language'     => "🌐 कृपया अपनी भाषा चुनें:",
            'welcome'             => "👋 *{{business_name}}* में आपका स्वागत है!",
            'setting_up'          => "हम अभी अपनी सेवाएं सेट कर रहे हैं। कृपया जल्द ही वापस देखें।",
            'choose_category'     => "हमारी सेवाएं देखें और एक श्रेणी चुनें:",
            'tap_to_explore'      => "देखने के लिए टैप करें",
            'view_menu'           => "मेन्यू देखें",
            'choose_service'      => "बढ़िया! कृपया एक सेवा चुनें:",
            'view_services'       => "सेवाएं देखें",
            'choose_staff'        => "आप किसके साथ बुक करना चाहेंगे?",
            'select_staff'        => "स्टाफ चुनें",
            'any_staff'           => "कोई भी उपलब्ध स्टाफ",
            'choose_date'         => "कृपया एक तारीख चुनें:",
            'select_date'         => "तारीख चुनें",
            'today'               => "आज",
            'tomorrow'            => "कल",
            'choose_time'         => "{{date}} के लिए उपलब्ध समय:",
            'select_time'         => "समय चुनें",
            'no_slots'            => "क्षमा करें, उस दिन कोई समय उपलब्ध नहीं है 😔\n\nकृपया दूसरी तारीख चुनें:",
            'no_services_in_cat'  => "क्षमा करें, इस श्रेणी में फिलहाल कोई सेवा उपलब्ध नहीं है। दोबारा शुरू करने के लिए *menu* भेजें।",
            'invalid_option'      => "क्षमा करें, यह एक मान्य विकल्प नहीं है 🙏\n\nकृपया पुनः प्रयास करें, या दोबारा शुरू करने के लिए *menu* भेजें।",
            'ask_name'            => "लगभग पूरा हुआ! इस अपॉइंटमेंट को किस नाम पर बुक करें?",
            'name_required'       => "बुकिंग पूरी करने के लिए कृपया अपना नाम भेजें।",
            'slot_taken'          => "क्षमा करें, वह समय किसी और ने अभी बुक कर लिया है 😔\n\nनई बुकिंग शुरू करने के लिए *menu* भेजें।",
            'something_wrong'     => "क्षमा करें, कुछ गलत हो गया। दोबारा शुरू करने के लिए *menu* भेजें।",
            'staff_line'          => "👤 स्टाफ: {{staff_name}}\n",
            'price_line'          => "💰 कीमत: {{price}}\n",
            'booking_received'    => "✅ *बुकिंग प्राप्त हुई!*\n\n📋 सेवा: {{service_name}}\n📅 तारीख: {{date}}\n{{time_line}}{{staff_line}}{{price_line}}🔖 बुकिंग नंबर: {{appointment_number}}\n\nस्थिति: ⏳ *पुष्टि की प्रतीक्षा है*\n\nपुष्टि होते ही हम आपको संदेश भेजेंगे। धन्यवाद, {{customer_name}}! 🙏",
            'appointments_empty'  => "आपकी कोई आगामी अपॉइंटमेंट नहीं है।\n\nबुक करने के लिए *menu* भेजें!",
            'appointments_header' => "📅 *आपकी आगामी अपॉइंटमेंट्स:*",
            'send_menu_to_book'   => "नई अपॉइंटमेंट बुक करने के लिए *menu* भेजें।",
        ],
        'pa' => [
            'choose_language'     => "🌐 ਕਿਰਪਾ ਕਰਕੇ ਆਪਣੀ ਭਾਸ਼ਾ ਚੁਣੋ:",
            'welcome'             => "👋 *{{business_name}}* ਵਿੱਚ ਜੀ ਆਇਆਂ ਨੂੰ!",
            'setting_up'          => "ਅਸੀਂ ਇਸ ਵੇਲੇ ਆਪਣੀਆਂ ਸੇਵਾਵਾਂ ਸੈੱਟਅਪ ਕਰ ਰਹੇ ਹਾਂ। ਕਿਰਪਾ ਕਰਕੇ ਜਲਦੀ ਹੀ ਦੁਬਾਰਾ ਚੈੱਕ ਕਰੋ।",
            'choose_category'     => "ਸਾਡੀਆਂ ਸੇਵਾਵਾਂ ਵੇਖੋ ਅਤੇ ਇੱਕ ਸ਼੍ਰੇਣੀ ਚੁਣੋ:",
            'tap_to_explore'      => "ਦੇਖਣ ਲਈ ਟੈਪ ਕਰੋ",
            'view_menu'           => "ਮੀਨੂ ਵੇਖੋ",
            'choose_service'      => "ਵਧੀਆ! ਕਿਰਪਾ ਕਰਕੇ ਇੱਕ ਸੇਵਾ ਚੁਣੋ:",
            'view_services'       => "ਸੇਵਾਵਾਂ ਵੇਖੋ",
            'choose_staff'        => "ਤੁਸੀਂ ਕਿਸ ਨਾਲ ਬੁੱਕ ਕਰਨਾ ਚਾਹੋਗੇ?",
            'select_staff'        => "ਸਟਾਫ ਚੁਣੋ",
            'any_staff'           => "ਕੋਈ ਵੀ ਉਪਲਬਧ ਸਟਾਫ",
            'choose_date'         => "ਕਿਰਪਾ ਕਰਕੇ ਇੱਕ ਮਿਤੀ ਚੁਣੋ:",
            'select_date'         => "ਮਿਤੀ ਚੁਣੋ",
            'today'               => "ਅੱਜ",
            'tomorrow'            => "ਕੱਲ੍ਹ",
            'choose_time'         => "{{date}} ਲਈ ਉਪਲਬਧ ਸਮਾਂ:",
            'select_time'         => "ਸਮਾਂ ਚੁਣੋ",
            'no_slots'            => "ਮਾਫ਼ ਕਰਨਾ, ਉਸ ਦਿਨ ਕੋਈ ਸਮਾਂ ਉਪਲਬਧ ਨਹੀਂ ਹੈ 😔\n\nਕਿਰਪਾ ਕਰਕੇ ਕੋਈ ਹੋਰ ਮਿਤੀ ਚੁਣੋ:",
            'no_services_in_cat'  => "ਮਾਫ਼ ਕਰਨਾ, ਇਸ ਸ਼੍ਰੇਣੀ ਵਿੱਚ ਫਿਲਹਾਲ ਕੋਈ ਸੇਵਾ ਉਪਲਬਧ ਨਹੀਂ ਹੈ। ਮੁੜ ਸ਼ੁਰੂ ਕਰਨ ਲਈ *menu* ਭੇਜੋ।",
            'invalid_option'      => "ਮਾਫ਼ ਕਰਨਾ, ਇਹ ਇੱਕ ਵੈਧ ਵਿਕਲਪ ਨਹੀਂ ਹੈ 🙏\n\nਕਿਰਪਾ ਕਰਕੇ ਦੁਬਾਰਾ ਕੋਸ਼ਿਸ਼ ਕਰੋ, ਜਾਂ ਮੁੜ ਸ਼ੁਰੂ ਕਰਨ ਲਈ *menu* ਭੇਜੋ।",
            'ask_name'            => "ਲਗਭਗ ਹੋ ਗਿਆ! ਇਹ ਅਪੌਇੰਟਮੈਂਟ ਕਿਸ ਨਾਮ 'ਤੇ ਬੁੱਕ ਕਰੀਏ?",
            'name_required'       => "ਬੁਕਿੰਗ ਪੂਰੀ ਕਰਨ ਲਈ ਕਿਰਪਾ ਕਰਕੇ ਆਪਣਾ ਨਾਮ ਭੇਜੋ।",
            'slot_taken'          => "ਮਾਫ਼ ਕਰਨਾ, ਉਹ ਸਮਾਂ ਕਿਸੇ ਹੋਰ ਨੇ ਹੁਣੇ ਬੁੱਕ ਕਰ ਲਿਆ ਹੈ 😔\n\nਨਵੀਂ ਬੁਕਿੰਗ ਸ਼ੁਰੂ ਕਰਨ ਲਈ *menu* ਭੇਜੋ।",
            'something_wrong'     => "ਮਾਫ਼ ਕਰਨਾ, ਕੁਝ ਗਲਤ ਹੋ ਗਿਆ। ਮੁੜ ਸ਼ੁਰੂ ਕਰਨ ਲਈ *menu* ਭੇਜੋ।",
            'staff_line'          => "👤 ਸਟਾਫ: {{staff_name}}\n",
            'price_line'          => "💰 ਕੀਮਤ: {{price}}\n",
            'booking_received'    => "✅ *ਬੁਕਿੰਗ ਪ੍ਰਾਪਤ ਹੋਈ!*\n\n📋 ਸੇਵਾ: {{service_name}}\n📅 ਮਿਤੀ: {{date}}\n{{time_line}}{{staff_line}}{{price_line}}🔖 ਬੁਕਿੰਗ ਨੰਬਰ: {{appointment_number}}\n\nਸਥਿਤੀ: ⏳ *ਪੁਸ਼ਟੀ ਦੀ ਉਡੀਕ ਹੈ*\n\nਪੁਸ਼ਟੀ ਹੁੰਦੇ ਹੀ ਅਸੀਂ ਤੁਹਾਨੂੰ ਸੁਨੇਹਾ ਭੇਜਾਂਗੇ। ਧੰਨਵਾਦ, {{customer_name}}! 🙏",
            'appointments_empty'  => "ਤੁਹਾਡੀ ਕੋਈ ਆਉਣ ਵਾਲੀ ਅਪੌਇੰਟਮੈਂਟ ਨਹੀਂ ਹੈ।\n\nਬੁੱਕ ਕਰਨ ਲਈ *menu* ਭੇਜੋ!",
            'appointments_header' => "📅 *ਤੁਹਾਡੀਆਂ ਆਉਣ ਵਾਲੀਆਂ ਅਪੌਇੰਟਮੈਂਟਾਂ:*",
            'send_menu_to_book'   => "ਨਵੀਂ ਅਪੌਇੰਟਮੈਂਟ ਬੁੱਕ ਕਰਨ ਲਈ *menu* ਭੇਜੋ।",
        ],
    ];
}

function wt(string $lang, string $key, array $vars = []): string {
    $strings = waStrings();
    $text = $strings[$lang][$key] ?? $strings['en'][$key] ?? $key;
    return renderTemplate($text, $vars);
}

function waWeekdayName(string $date, string $lang): string {
    $en  = date('D', strtotime($date));
    $map = [
        'hi' => ['Sun' => 'रवि', 'Mon' => 'सोम', 'Tue' => 'मंगल', 'Wed' => 'बुध', 'Thu' => 'गुरु', 'Fri' => 'शुक्र', 'Sat' => 'शनि'],
        'pa' => ['Sun' => 'ਐਤ',  'Mon' => 'ਸੋਮ', 'Tue' => 'ਮੰਗਲ', 'Wed' => 'ਬੁੱਧ', 'Thu' => 'ਵੀਰ',  'Fri' => 'ਸ਼ੁੱਕਰ', 'Sat' => 'ਸ਼ਨੀ'],
    ];
    return $map[$lang][$en] ?? $en;
}

// ─── Menu builders (interactive list rows) ─────────────────────────────────────

function buildCategoryMenu(int $businessId): array {
    $categories = getCategories($businessId, true);
    $ids = []; $rows = [];

    foreach ($categories as $cat) {
        $services = getServicesList($businessId, (int)$cat['id'], true);
        if (empty($services)) continue;

        $ids[] = (int)$cat['id'];
        $title = trim(($cat['icon'] ?: '') . ' ' . $cat['name']);
        $row = ['id' => 'cat_' . $cat['id'], 'title' => mb_substr($title, 0, 24)];
        if (!empty($cat['description'])) $row['description'] = mb_substr($cat['description'], 0, 72);
        $rows[] = $row;

        if (count($rows) >= 10) break;
    }

    return ['ids' => $ids, 'rows' => $rows];
}

function buildServiceMenu(int $businessId, int $categoryId, string $currency, bool $hidePrice): array {
    $services = getServicesList($businessId, $categoryId, true);
    $ids = []; $rows = [];

    foreach ($services as $svc) {
        $ids[] = (int)$svc['id'];
        $desc = $hidePrice
            ? (int)$svc['duration'] . ' min'
            : formatPrice((float)$svc['price'], $currency) . ' • ' . (int)$svc['duration'] . ' min';
        $rows[] = [
            'id'          => 'svc_' . $svc['id'],
            'title'       => mb_substr($svc['name'], 0, 24),
            'description' => mb_substr($desc, 0, 72),
        ];

        if (count($rows) >= 10) break;
    }

    return ['ids' => $ids, 'rows' => $rows];
}

function buildStaffMenu(int $businessId, int $serviceId, string $lang): array {
    $ids  = [0];
    $rows = [['id' => 'staff_0', 'title' => mb_substr(wt($lang, 'any_staff'), 0, 24)]];

    $staffIds = getServiceStaffIds($serviceId);
    if (!empty($staffIds)) {
        $in = implode(',', array_fill(0, count($staffIds), '?'));
        $stmt = db()->prepare("SELECT id, name FROM staff WHERE id IN ($in) AND is_active = 1 ORDER BY name");
        $stmt->execute($staffIds);
        foreach ($stmt->fetchAll() as $st) {
            $ids[]  = (int)$st['id'];
            $rows[] = ['id' => 'staff_' . $st['id'], 'title' => mb_substr($st['name'], 0, 24)];
            if (count($rows) >= 10) break;
        }
    }

    return ['ids' => $ids, 'rows' => $rows];
}

function buildDateMenu(string $lang): array {
    $dates = []; $rows = [];

    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} day"));

        if ($i === 0) {
            $label = wt($lang, 'today');
        } elseif ($i === 1) {
            $label = wt($lang, 'tomorrow');
        } elseif ($lang === 'en') {
            $label = date('D, M j', strtotime($date));
        } else {
            $label = waWeekdayName($date, $lang) . ', ' . date('j/n', strtotime($date));
        }

        $dates[] = $date;
        $rows[]  = ['id' => 'date_' . $date, 'title' => mb_substr($label, 0, 24)];
    }

    return ['dates' => $dates, 'rows' => $rows];
}

function buildSlotMenu(array $slots): array {
    $slots = array_slice($slots, 0, 10);
    $rows  = [];

    foreach ($slots as $slot) {
        $rows[] = ['id' => 'slot_' . $slot['time'], 'title' => mb_substr($slot['label'], 0, 24)];
    }

    return ['slots' => $slots, 'rows' => $rows];
}

// ─── Selection helper ───────────────────────────────────────────────────────────

/**
 * Resolve a reply against an interactive list/button id (preferred prefix match)
 * or a plain numeric reply (1-based index) against $ids. Returns the matched id
 * value as a string, or null if invalid.
 */
function waResolveSelection(string $sel, string $prefix, array $ids): ?string {
    if (str_starts_with($sel, $prefix)) {
        $val = substr($sel, strlen($prefix));
        return in_array($val, array_map('strval', $ids), true) ? $val : null;
    }

    if (ctype_digit($sel)) {
        $idx = (int)$sel;
        if ($idx >= 1 && $idx <= count($ids)) return (string)$ids[$idx - 1];
    }

    return null;
}

// ─── Helper: send the welcome message + category list, persist session ─────────

function sendCategoryMenu(int $businessId, string $fromPhone, string $lang, string $businessName, array $data): void {
    $menu = buildCategoryMenu($businessId);

    if (empty($menu['rows'])) {
        sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'welcome', ['business_name' => $businessName]) . "\n\n" . wt($lang, 'setting_up'));
        saveWhatsappSession($businessId, $fromPhone, 'idle', $data);
        return;
    }

    $body = wt($lang, 'welcome', ['business_name' => $businessName]) . "\n\n" . wt($lang, 'choose_category') . "\n" . wt($lang, 'tap_to_explore');
    sendWhatsappInteractiveList($businessId, $fromPhone, $body, wt($lang, 'view_menu'), [['rows' => $menu['rows']]]);
    saveWhatsappSession($businessId, $fromPhone, 'awaiting_category', array_merge($data, ['category_ids' => $menu['ids']]));
}

function waLanguageButtons(int $businessId, string $fromPhone): void {
    sendWhatsappInteractiveButtons(
        $businessId, $fromPhone,
        wt('en', 'choose_language') . "\n" . wt('hi', 'choose_language') . "\n" . wt('pa', 'choose_language'),
        [
            ['id' => 'lang_en', 'title' => 'English'],
            ['id' => 'lang_hi', 'title' => WA_LANGS['hi']],
            ['id' => 'lang_pa', 'title' => WA_LANGS['pa']],
        ]
    );
}

// ─── Main conversation state machine ─────────────────────────────────────────────

function processIncomingMessage(int $businessId, string $fromPhone, string $text, string $interactiveId = ''): void {
    $stmt = db()->prepare("SELECT name, currency, pricing_mode, fixed_price, time_required, token_mode FROM businesses WHERE id = ?");
    $stmt->execute([$businessId]);
    $biz = $stmt->fetch();
    if (!$biz) return;

    $businessName = $biz['name'];
    $currency     = $biz['currency'] ?? 'USD';
    $hidePrice    = ($biz['pricing_mode'] ?? 'per_service') === 'fixed';
    $fixedPrice   = $biz['fixed_price'];
    $timeRequired = isset($biz['time_required']) ? (int)$biz['time_required'] : 1;
    $tokenMode    = $biz['token_mode'] ?? 'db_id';

    $session = getWhatsappSession($businessId, $fromPhone);
    $state   = $session['session_state'] ?: 'idle';
    $data    = $session['session_data'] ?: [];

    $raw   = trim($text);
    $lower = strtolower($raw);
    $sel   = $interactiveId !== '' ? $interactiveId : $lower;

    $lang = $data['lang'] ?? null;
    if (!$lang) {
        $stmt = db()->prepare("SELECT language FROM customers WHERE business_id = ? AND phone = ? LIMIT 1");
        $stmt->execute([$businessId, $fromPhone]);
        $savedLang = $stmt->fetchColumn();
        if ($savedLang && isset(WA_LANGS[$savedLang])) {
            $lang = $savedLang;
            $data['lang'] = $lang;
        }
    }

    // Global reset triggers (preserve language preference)
    $resetTriggers = ['menu', 'restart', 'cancel', 'hi', 'hello', 'hey', 'start', 'मेनू', 'शुरू', 'रद्द', 'नमस्ते', 'ਮੀਨੂ', 'ਸ਼ੁਰੂ', 'ਰੱਦ'];
    if ($state !== 'idle' && in_array($lower, $resetTriggers, true)) {
        resetWhatsappSession($businessId, $fromPhone);
        $state = 'idle';
        $data  = $lang ? ['lang' => $lang] : [];
    }

    switch ($state) {

        case 'idle':
        default:
            if (!$lang) {
                waLanguageButtons($businessId, $fromPhone);
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_language', []);
                return;
            }

            // "My appointments" lookup
            if (preg_match('/my (appointment|booking)|appointment status|मेरी (अपॉइंटमेंट|बुकिंग)|ਮੇਰੀ (ਅਪੌਇੰਟਮੈਂਟ|ਬੁਕਿੰਗ)/iu', $raw)) {
                $stmt = db()->prepare("
                    SELECT a.*, s.name AS service_name
                    FROM appointments a
                    LEFT JOIN customers c ON c.id = a.customer_id
                    LEFT JOIN services  s ON s.id = a.service_id
                    WHERE a.business_id = ? AND c.phone = ? AND a.appointment_date >= CURDATE() AND a.status != 'cancelled'
                    ORDER BY a.appointment_date ASC, a.appointment_time ASC
                    LIMIT 5
                ");
                $stmt->execute([$businessId, $fromPhone]);
                $appts = $stmt->fetchAll();

                if (empty($appts)) {
                    sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'appointments_empty'));
                } else {
                    $statusEmoji = ['pending' => '⏳', 'confirmed' => '✅', 'in_progress' => '🔵', 'completed' => '✔️', 'no_show' => '❌'];
                    $lines = [];
                    foreach ($appts as $a) {
                        $emoji = $statusEmoji[$a['status']] ?? '•';
                        $lines[] = "{$emoji} {$a['service_name']} (" . formatAppointmentNumber((int)$a['id']) . ") — " . formatDate($a['appointment_date']) . ' at ' . formatTime($a['appointment_time']) . " ({$a['status']})";
                    }
                    sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'appointments_header') . "\n\n" . implode("\n", $lines) . "\n\n" . wt($lang, 'send_menu_to_book'));
                }
                saveWhatsappSession($businessId, $fromPhone, 'idle', $data);
                return;
            }

            sendCategoryMenu($businessId, $fromPhone, $lang, $businessName, $data);
            break;

        case 'awaiting_language':
            $newLang = match (true) {
                $sel === 'lang_en' || in_array($lower, ['english', 'en', '1'], true) => 'en',
                $sel === 'lang_hi' || in_array($lower, ['hindi', 'hi', '2', 'हिंदी', 'हिन्दी'], true) => 'hi',
                $sel === 'lang_pa' || in_array($lower, ['punjabi', 'pa', '3', 'ਪੰਜਾਬੀ'], true) => 'pa',
                default => null,
            };

            if (!$newLang) {
                waLanguageButtons($businessId, $fromPhone);
                break;
            }

            $lang = $newLang;
            $data = ['lang' => $lang];

            try {
                db()->prepare("UPDATE customers SET language = ? WHERE business_id = ? AND phone = ?")->execute([$lang, $businessId, $fromPhone]);
            } catch (Exception $e) {}

            sendCategoryMenu($businessId, $fromPhone, $lang, $businessName, $data);
            break;

        case 'awaiting_category':
            $menu = buildCategoryMenu($businessId);
            $categoryId = waResolveSelection($sel, 'cat_', $data['category_ids'] ?? $menu['ids']);

            if ($categoryId === null) {
                sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'invalid_option') . "\n\n" . wt($lang, 'choose_category'), wt($lang, 'view_menu'), [['rows' => $menu['rows']]]);
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_category', array_merge($data, ['category_ids' => $menu['ids']]));
                break;
            }

            $svcMenu = buildServiceMenu($businessId, (int)$categoryId, $currency, $hidePrice);

            if (empty($svcMenu['ids'])) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'no_services_in_cat'));
                resetWhatsappSession($businessId, $fromPhone);
                break;
            }

            sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'choose_service'), wt($lang, 'view_services'), [['rows' => $svcMenu['rows']]]);
            saveWhatsappSession($businessId, $fromPhone, 'awaiting_service', array_merge($data, ['service_ids' => $svcMenu['ids']]));
            break;

        case 'awaiting_service':
            $serviceId = waResolveSelection($sel, 'svc_', $data['service_ids'] ?? []);

            if ($serviceId === null) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'invalid_option'));
                break;
            }

            $serviceId = (int)$serviceId;
            $staffIds  = getServiceStaffIds($serviceId);

            if (count($staffIds) > 1) {
                $staffMenu = buildStaffMenu($businessId, $serviceId, $lang);
                sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'choose_staff'), wt($lang, 'select_staff'), [['rows' => $staffMenu['rows']]]);
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_staff', array_merge($data, ['service_id' => $serviceId, 'staff_ids' => $staffMenu['ids']]));
            } else {
                $staffId = count($staffIds) === 1 ? $staffIds[0] : 0;
                $dateMenu = buildDateMenu($lang);
                sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'choose_date'), wt($lang, 'select_date'), [['rows' => $dateMenu['rows']]]);
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_date', array_merge($data, ['service_id' => $serviceId, 'staff_id' => $staffId, 'dates' => $dateMenu['dates']]));
            }
            break;

        case 'awaiting_staff':
            $staffId = waResolveSelection($sel, 'staff_', $data['staff_ids'] ?? []);

            if ($staffId === null) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'invalid_option'));
                break;
            }

            $dateMenu = buildDateMenu($lang);
            sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'choose_date'), wt($lang, 'select_date'), [['rows' => $dateMenu['rows']]]);
            saveWhatsappSession($businessId, $fromPhone, 'awaiting_date', array_merge($data, ['service_id' => $data['service_id'], 'staff_id' => (int)$staffId, 'dates' => $dateMenu['dates']]));
            break;

        case 'awaiting_date':
            $dateMenu = buildDateMenu($lang);
            $date = waResolveSelection($sel, 'date_', $data['dates'] ?? $dateMenu['dates']);

            if ($date === null) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'invalid_option'));
                break;
            }

            // If business disabled time slots, skip straight to name collection
            if (!$timeRequired) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'ask_name'));
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_name', array_merge($data, ['date' => $date, 'time' => '00:00:00']));
                break;
            }

            $slots = getAvailableSlots($businessId, (int)$data['service_id'], $date, (int)($data['staff_id'] ?? 0));

            if (empty($slots)) {
                sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'no_slots'), wt($lang, 'select_date'), [['rows' => $dateMenu['rows']]]);
                saveWhatsappSession($businessId, $fromPhone, 'awaiting_date', array_merge($data, ['dates' => $dateMenu['dates']]));
                break;
            }

            $slotMenu = buildSlotMenu($slots);
            sendWhatsappInteractiveList($businessId, $fromPhone, wt($lang, 'choose_time', ['date' => formatDate($date)]), wt($lang, 'select_time'), [['rows' => $slotMenu['rows']]]);
            saveWhatsappSession($businessId, $fromPhone, 'awaiting_slot', array_merge($data, ['date' => $date, 'slots' => $slotMenu['slots']]));
            break;

        case 'awaiting_slot':
            $slots   = $data['slots'] ?? [];
            $slotIds = array_map(fn($s) => $s['time'], $slots);
            $time    = waResolveSelection($sel, 'slot_', $slotIds);

            if ($time === null) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'invalid_option'));
                break;
            }

            sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'ask_name'));
            saveWhatsappSession($businessId, $fromPhone, 'awaiting_name', array_merge($data, ['time' => $time]));
            break;

        case 'awaiting_name':
            $name = $raw;
            if ($name === '') {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'name_required'));
                break;
            }

            $serviceId = (int)$data['service_id'];
            $date      = $data['date'];
            $time      = $data['time'];
            $staffId   = (int)($data['staff_id'] ?? 0);
            $isDateOnly = ($time === '00:00:00');

            // Re-validate slot is still free (skip for date-only bookings)
            $matched = null;
            if (!$isDateOnly) {
                $slots = getAvailableSlots($businessId, $serviceId, $date, $staffId);
                foreach ($slots as $s) {
                    if ($s['time'] === $time) { $matched = $s; break; }
                }
                if (!$matched) {
                    sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'slot_taken'));
                    resetWhatsappSession($businessId, $fromPhone);
                    break;
                }
            }

            $stmt = db()->prepare("SELECT * FROM services WHERE id = ? AND business_id = ?");
            $stmt->execute([$serviceId, $businessId]);
            $service = $stmt->fetch();
            if (!$service) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'something_wrong'));
                resetWhatsappSession($businessId, $fromPhone);
                break;
            }

            // Check wallet balance before creating the booking
            if (!hasEnoughBalance($businessId)) {
                sendWhatsappMessage($businessId, $fromPhone, wt($lang, 'something_wrong'));
                resetWhatsappSession($businessId, $fromPhone);
                break;
            }

            $assignedStaff = $staffId > 0 ? $staffId : ($matched['staff_id'] ?? null);
            $duration  = (int)$service['duration'];
            $endTime   = $isDateOnly ? '00:00:00' : minutesToTime(timeToMinutes($time) + $duration);

            $totalPrice = ($hidePrice && $fixedPrice !== null) ? (float)$fixedPrice : (float)$service['price'];

            $customerId = findOrCreateCustomer($businessId, $fromPhone, $name);
            try {
                db()->prepare("UPDATE customers SET language = ? WHERE id = ? AND language IS NULL")->execute([$lang, $customerId]);
            } catch (Exception $e) {}

            $dailyToken = ($tokenMode === 'daily') ? assignDailyToken($businessId, $date) : null;

            $stmt = db()->prepare("
                INSERT INTO appointments (business_id, customer_id, service_id, staff_id, appointment_date, appointment_time, end_time, duration, status, total_price, payment_status, booking_source, daily_token)
                VALUES (?,?,?,?,?,?,?,?, 'pending', ?, 'unpaid', 'whatsapp', ?)
            ");
            $stmt->execute([$businessId, $customerId, $serviceId, $assignedStaff, $date, $time, $endTime, $duration, $totalPrice, $dailyToken]);
            $appointmentId = (int)db()->lastInsertId();

            // Deduct booking fee from wallet (non-blocking — booking already created)
            deductBookingFee($businessId, $appointmentId);

            $staffName = '';
            if ($assignedStaff) {
                $stmt = db()->prepare("SELECT name FROM staff WHERE id = ?");
                $stmt->execute([$assignedStaff]);
                $staffName = (string)$stmt->fetchColumn();
            }

            $timeLine      = $isDateOnly ? '' : ("🕐 " . ($lang === 'hi' ? 'समय' : ($lang === 'pa' ? 'ਸਮਾਂ' : 'Time')) . ": " . date('g:i A', strtotime($time)) . "\n");
            $apptNumLabel  = ($tokenMode === 'daily' && $dailyToken) ? "Token #{$dailyToken}" : formatAppointmentNumber($appointmentId);
            $summary = wt($lang, 'booking_received', [
                'service_name'       => $service['name'],
                'date'               => formatDate($date),
                'time'               => $isDateOnly ? '' : date('g:i A', strtotime($time)),
                'time_line'          => $timeLine,
                'staff_line'         => $staffName ? wt($lang, 'staff_line', ['staff_name' => $staffName]) : '',
                'price_line'         => wt($lang, 'price_line', ['price' => formatPrice($totalPrice, $currency)]),
                'appointment_number' => $apptNumLabel,
                'customer_name'      => $name,
            ]);

            sendWhatsappMessage($businessId, $fromPhone, $summary);

            try {
                db()->prepare("
                    INSERT INTO notifications (business_id, type, title, message, action_url, related_id, related_type)
                    VALUES (?, 'new_booking', 'New Appointment Request', ?, 'appointments.php', ?, 'appointment')
                ")->execute([
                    $businessId,
                    "{$name} requested {$service['name']} on " . formatDate($date) . ' at ' . date('g:i A', strtotime($time)),
                    $appointmentId,
                ]);
            } catch (Exception $e) {}

            saveWhatsappSession($businessId, $fromPhone, 'idle', ['lang' => $lang], $appointmentId);
            break;
    }
}