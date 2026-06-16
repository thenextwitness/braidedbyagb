<?php
// ============================================================
// BraidedbyAGB — Rule-Based Chat Engine (zero API cost)
// FILE: /api/chat.php
// POST /api/chat  →  { reply, suggests_whatsapp, quick_replies[] }
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$messages = $body['messages'] ?? [];
if (empty($messages)) {
    http_response_code(400); echo json_encode(['error' => 'No messages']); exit;
}

$lastMsg = '';
foreach (array_reverse($messages) as $m) {
    if (($m['role'] ?? '') === 'user') {
        $lastMsg = mb_strtolower(trim(strip_tags($m['content'] ?? '')));
        break;
    }
}
if (!$lastMsg) { echo json_encode(['reply' => "Could you rephrase that?", 'suggests_whatsapp' => false, 'quick_replies' => []]); exit; }
$lastMsg = mb_substr($lastMsg, 0, 400);

try {
    $db = getDB();
    $services = $db->query("
        SELECT s.name, s.slug, s.description, s.price_from, s.duration_mins,
               s.category, s.prep_notes,
               GROUP_CONCAT(sv.variant_name, ' - ', sv.price
                   ORDER BY sv.display_order SEPARATOR '|||') as variants,
               GROUP_CONCAT(DISTINCT CONCAT(sa.name, ' +', sa.price)
                   ORDER BY sa.id SEPARATOR ', ') as addons
        FROM services s
        LEFT JOIN service_variants sv ON sv.service_id = s.id
        LEFT JOIN service_addons sa ON sa.service_id = s.id AND sa.is_active = 1
        WHERE s.is_active = 1
        GROUP BY s.id ORDER BY s.display_order
    ")->fetchAll();

    $cfg = [];
    foreach ($db->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $r) {
        $cfg[$r['setting_key']] = $r['setting_value'];
    }
} catch (Throwable $e) {
    error_log('Chat DB error: ' . $e->getMessage());
    $phone = '07769064971';
    echo json_encode([
        'reply' => "I'm having a technical issue right now. Please WhatsApp us on {$phone} and we'll help straight away! 💬",
        'suggests_whatsapp' => true,
        'quick_replies' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone    = $cfg['whatsapp_number']           ?? '07769064971';
$deposit  = $cfg['deposit_percent']            ?? '30';
$cancel   = $cfg['cancellation_hours']         ?? '48';
$late     = $cfg['late_arrival_mins']          ?? '20';
$bankHrs  = $cfg['bank_transfer_hold_hours']   ?? '24';
$address  = $cfg['site_address']               ?? 'Farnborough, Hampshire, UK';
$bankName = $cfg['bank_account_name']          ?? 'BraidedbyAGB';
$bankSort = $cfg['bank_sort_code']             ?? '';
$bankAcc  = $cfg['bank_account_number']        ?? '';
$instagram= $cfg['instagram_url']              ?? '';

$q     = $lastMsg;
$reply = '';
$wa    = false;
$qr    = [];

function dur(int $mins): string {
    return formatDuration($mins);
}
function has(string $pattern, string $q): bool {
    return (bool)preg_match('/' . $pattern . '/i', $q);
}
function svc(array $services, string $slug): ?array {
    foreach ($services as $s) { if ($s['slug'] === $slug) return $s; }
    return null;
}
function svcBlock(array $s): string {
    $out = "{$s['name']}\n";
    if ($s['description']) $out .= $s['description'] . "\n\n";
    if ($s['variants']) {
        $out .= "Options:\n";
        foreach (explode('|||', $s['variants']) as $v) $out .= "- " . trim($v) . "\n";
    } else {
        $out .= "From " . number_format((float)$s['price_from'], 0) . "\n";
    }
    $out .= "Duration: " . dur((int)$s['duration_mins']);
    if ($s['addons'])     $out .= "\nAdd-ons: {$s['addons']}";
    if ($s['prep_notes']) $out .= "\n\nPrep tip: {$s['prep_notes']}";
    $out .= "\n\nBook at: braidedbyagb.co.uk/booking/{$s['slug']}";
    return $out;
}

// GREETINGS
if (has('^(hi|hello|hey|hiya|good (morning|afternoon|evening)|howdy|sup|yo)\b', $q)) {
    $reply = "Hi there! Welcome to BraidedbyAGB!\n\nI can help with services, prices, booking, policies and more. What would you like to know?";
    $qr = ['What services do you offer?', 'How much does it cost?', 'How do I book?', 'Where are you based?'];

// ALL SERVICES
} elseif (has('\b(services?|styles?|what do you (do|offer)|menu|what.s (available|on offer))\b', $q)) {
    $cats = [];
    foreach ($services as $s) { $cats[$s['category'] ?: 'Other'][] = $s; }
    $list = '';
    foreach ($cats as $cat => $svcs) {
        $list .= "\n{$cat}:\n";
        foreach ($svcs as $s) $list .= "  - {$s['name']} from " . number_format((float)$s['price_from'], 0) . "\n";
    }
    $reply = "Here's everything we offer:{$list}\nFull details at braidedbyagb.co.uk/services";
    $qr = ['How much does it cost?', 'How do I book?', 'How long does it take?'];

// PRICING
} elseif (has('\b(price|prices?|cost|costs?|how much|charge|rate|fee|fees)\b', $q)) {
    $list = '';
    foreach ($services as $s) $list .= "- {$s['name']}: from " . number_format((float)$s['price_from'], 0) . "\n";
    $reply = "Our current starting prices:\n\n{$list}\nPrices vary by hair length and thickness. A {$deposit}% non-refundable deposit is required at booking.\n\nFor a tailored quote: braidedbyagb.co.uk/custom-request";
    $qr = ['How do I book?', "What's the deposit?", 'Custom style?'];

// BOX BRAIDS
} elseif (has('\bbox.?braid\b', $q)) {
    $s = svc($services, 'box-braids');
    $reply = $s ? svcBlock($s) : "Box braids are one of our most popular styles! See braidedbyagb.co.uk/services for full pricing.";
    $qr = ['How do I book?', "What's the deposit?", 'What other styles do you offer?'];

// KNOTLESS
} elseif (has('\bknotless\b', $q)) {
    $s = svc($services, 'knotless-braids');
    $reply = $s ? svcBlock($s) : "Knotless braids are a gentle, natural-looking option. See braidedbyagb.co.uk/services for prices.";
    $qr = ['How do I book?', "What's the deposit?"];

// FEED-IN
} elseif (has('\bfeed.?in\b', $q)) {
    $s = svc($services, 'feed-in-braids');
    $reply = $s ? svcBlock($s) : "We offer feed-in braids! Check braidedbyagb.co.uk/services for pricing.";
    $qr = ['How do I book?', 'What other styles do you offer?'];

// CORNROWS
} elseif (has('\bcornrow\b', $q)) {
    $cornrows = array_filter($services, fn($x) => str_contains($x['slug'], 'cornrow'));
    $reply = "Cornrow options:\n\n";
    foreach ($cornrows as $s) {
        $reply .= "- {$s['name']}: from " . number_format((float)$s['price_from'], 0) . " | " . dur((int)$s['duration_mins']) . "\n";
        $reply .= "  braidedbyagb.co.uk/booking/{$s['slug']}\n\n";
    }
    if (!$cornrows) $reply = "Yes, we do cornrows! Check braidedbyagb.co.uk/services for pricing.";
    $qr = ['How do I book?', 'What other styles do you offer?'];

// TWISTS
} elseif (has('\b(twist|passion twist|marley)\b', $q)) {
    $s = svc($services, 'twists');
    $reply = $s ? svcBlock($s) : "We offer passion and Marley twists! See braidedbyagb.co.uk/services.";
    $qr = ['How do I book?', 'What other styles do you offer?'];

// LOCS
} elseif (has('\b(starter.?loc|loc retwist|retwist|dreadlock)\b', $q)) {
    $locs = array_filter($services, fn($x) => str_contains($x['slug'], 'loc'));
    $reply = "Our loc services:\n\n";
    foreach ($locs as $s) {
        $reply .= "- {$s['name']}: from " . number_format((float)$s['price_from'], 0) . " | " . dur((int)$s['duration_mins']) . "\n";
        $reply .= "  braidedbyagb.co.uk/booking/{$s['slug']}\n\n";
    }
    if (!$locs) $reply = "We offer starter locs and loc retwists! Check braidedbyagb.co.uk/services.";
    $qr = ['How do I book?', 'What other styles do you offer?'];

// KIDS
} elseif (has('\b(kid|child|daughter|son|baby|toddler|little one|young)\b', $q)) {
    $s = svc($services, 'kids-styles');
    $reply = "Yes, we do kids styles!\n\n" . ($s ? svcBlock($s) : "Check braidedbyagb.co.uk/booking/kids-styles for details.");
    $qr = ['How do I book?', 'What other styles do you offer?'];

// BOOKING
} elseif (has('\b(book|booking|appointment|appt|schedule|reserve)\b', $q)
       && !has('\b(cancel|reschedul|change|move)\b', $q)) {
    $reply = "Booking is simple!\n\n1. Go to braidedbyagb.co.uk/booking\n2. Choose your service\n3. Pick a date and time\n4. Enter your details\n5. Pay your {$deposit}% deposit to confirm\n\nInstant email confirmation is sent straight away. The remaining balance is paid on the day.";
    $qr = ["What's the deposit?", 'What payment do you take?', 'Can I cancel if needed?'];

// DEPOSIT
} elseif (has('\b(deposit|upfront|secure|down.?payment)\b', $q)) {
    $reply = "A {$deposit}% non-refundable deposit is required to secure your booking.\n\nPaid by card (Stripe) or bank transfer when you book online. The remaining balance is paid on the day in cash or bank transfer.\n\nIf you need to rearrange, please give at least {$cancel} hours notice.";
    $qr = ['Cancellation policy?', 'How do I book?', 'Payment methods?'];

// PAYMENT
} elseif (has('\b(pay|payment|card|cash|bank.?transfer|stripe|paypal)\b', $q)) {
    $bank = ($bankSort && $bankAcc) ? "\n\nBank details:\n- {$bankName}\n- Sort code: {$bankSort}\n- Account: {$bankAcc}" : '';
    $reply = "We accept:\n\nDeposit at booking:\n- Card via Stripe\n- Bank transfer (must arrive within {$bankHrs} hours){$bank}\n\nBalance on the day:\n- Cash\n- Bank transfer\n\nNo PayPal or Clearpay, sorry!";
    $qr = ["What's the deposit?", 'How do I book?'];

// CANCELLATION
} elseif (has('\b(cancel|cancellation|reschedul|rearrange|change.*date|postpone)\b', $q)) {
    $reply = "Cancellation policy:\n\n- Cancel at least {$cancel} hours before your appointment\n- Deposits are non-refundable\n- To cancel or reschedule, WhatsApp us on {$phone}\n\nThe sooner you let us know, the better!";
    $wa = true;
    $qr = ['How do I contact you?', 'How do I rebook?'];

// LATE ARRIVAL
} elseif (has('\b(late|running late|on my way|delayed)\b', $q)) {
    $reply = "Late arrival policy:\n\nPlease arrive within {$late} minutes of your appointment time. After {$late} minutes your appointment may be cancelled and deposit forfeited.\n\nRunning late? WhatsApp us immediately on {$phone}!";
    $wa = true;
    $qr = ['Cancellation policy?', 'How do I contact you?'];

// DURATION
} elseif (has('\b(how long|duration|hours?|how.*time|takes?|sit for)\b', $q)
       && !has('\b(open|business|trading)\b', $q)) {
    $list = '';
    foreach ($services as $s) $list .= "- {$s['name']}: " . dur((int)$s['duration_mins']) . "\n";
    $reply = "Approximate durations:\n\n{$list}\nTimes vary by hair length and thickness.";
    $qr = ['How much does it cost?', 'How do I book?'];

// LOCATION
} elseif (has('\b(where|location|address|based|find you|directions?|farnborough|hampshire)\b', $q)) {
    $reply = "We are based in {$address}.\n\nThe exact address is shared after booking confirmation. WhatsApp us on {$phone} for specific directions!";
    $wa = true;
    $qr = ['How do I book?', 'How do I contact you?'];

// OPENING HOURS
} elseif (has('\b(open|opening|hours?|when|weekend|saturday|sunday|monday)\b', $q)) {
    $reply = "Our availability changes week to week based on bookings. The live calendar at braidedbyagb.co.uk/booking shows all available dates and times in real time.\n\nFor urgent availability queries, WhatsApp us on {$phone}";
    $wa = true;
    $qr = ['How do I book?', 'Where are you based?'];

// CONTACT
} elseif (has('\b(contact|reach|speak|phone|number|email|whatsapp|instagram|social)\b', $q)) {
    $ig = $instagram ? "\n- Instagram: {$instagram}" : '';
    $reply = "How to reach us:\n\n- WhatsApp / Phone: {$phone}\n- Email: hello@braidedbyagb.co.uk{$ig}\n- Website: braidedbyagb.co.uk\n\nWhatsApp is quickest!";
    $wa = true;
    $qr = ['How do I book?', 'Where are you based?'];

// PREP
} elseif (has('\b(prep|prepare|before|wash|detangle|clean hair|blow.?dry|come with)\b', $q)) {
    $specific = '';
    foreach ($services as $s) {
        if ($s['prep_notes']) $specific .= "- {$s['name']}: {$s['prep_notes']}\n";
    }
    $reply = "Before your appointment:\n\n- Come with clean, detangled hair\n- Blow-dry or stretch natural hair if possible\n- No heavy oils or products\n- Eat beforehand - sessions can be long!\n\n" . ($specific ? "Service-specific tips:\n{$specific}" : '');
    $qr = ['How long does it take?', 'How do I book?'];

// AFTERCARE
} elseif (has('\b(aftercare|after|maintain|itchy|frizz|moisture|how long.*last)\b', $q)) {
    $reply = "Aftercare tips:\n\n- Sleep with a satin bonnet or pillowcase\n- Moisturise scalp with a light oil or spray\n- Do not leave braids in longer than 6-8 weeks\n- Avoid excessive pulling or back-to-back tight styles\n\nFor specific advice, WhatsApp us!";
    $qr = ['How do I book my next appointment?', 'How do I contact you?'];

// CUSTOM STYLE
} elseif (has('\b(custom|bespoke|specific|pinterest|inspiration|not on (the )?menu|something different)\b', $q)) {
    $reply = "We love a bespoke request!\n\nUse our custom style form at braidedbyagb.co.uk/custom-request\n\nDescribe your dream look, upload an inspiration image if you have one, and we will reply within 24 hours with a personalised quote. No obligation!";
    $qr = ['How do I book?', 'How do I contact you?'];

// EXTENSIONS
} elseif (has('\b(extension|kanekalon|hair included|bring.*hair|own hair)\b', $q)) {
    $reply = "For most of our braiding services, extensions are included in the price - no need to bring anything!\n\nFor questions about specific colours or textures, WhatsApp us on {$phone} and we can advise.";
    $wa = true;
    $qr = ['What services do you offer?', 'How do I book?'];

// COMPLAINTS
} elseif (has('\b(complaint|unhappy|wrong|bad|issue|problem|disappoint|refund|money back)\b', $q)) {
    $reply = "We are really sorry to hear that.\n\nPlease WhatsApp us directly on {$phone} so we can look into this personally and make it right. We take all feedback very seriously.";
    $wa = true;
    $qr = ['How do I contact you?'];

// FALLBACK
} else {
    $reply = "I am not sure about that one!\n\nFor anything specific, WhatsApp the team on {$phone} - we reply quickly. Or browse braidedbyagb.co.uk for more info.";
    $wa = true;
    $qr = ['What services do you offer?', 'How much does it cost?', 'How do I book?', 'Where are you based?'];
}

echo json_encode([
    'reply'             => $reply,
    'suggests_whatsapp' => $wa,
    'quick_replies'     => $qr,
], JSON_UNESCAPED_UNICODE);
