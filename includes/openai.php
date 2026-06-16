<?php
/**
 * OpenAI integration — Whisper (transcription) + GPT-4o-mini (extraction)
 */

function getOpenAiKey(): string {
    return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
}

/**
 * Download WhatsApp audio file using media ID + business access token.
 * Returns local temp file path, or null on failure.
 */
function downloadWhatsappAudio(string $mediaId, string $accessToken): ?string {
    // Step 1: Get media download URL
    $ch = curl_init("https://graph.facebook.com/v19.0/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $mediaUrl = json_decode($resp, true)['url'] ?? null;
    if (!$mediaUrl) return null;

    // Step 2: Download actual audio bytes
    $ch = curl_init($mediaUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $audioBytes = curl_exec($ch);
    curl_close($ch);

    if (!$audioBytes) return null;

    $tmpPath = sys_get_temp_dir() . '/wa_voice_' . $mediaId . '.ogg';
    file_put_contents($tmpPath, $audioBytes);
    return $tmpPath;
}

/**
 * Transcribe audio file using OpenAI Whisper.
 * Returns transcribed text, or null on failure.
 */
function transcribeAudio(string $filePath, string $lang = 'en'): ?string {
    $key = getOpenAiKey();
    if (!$key || !file_exists($filePath)) return null;

    // Whisper language codes
    $langMap = ['en' => 'en', 'hi' => 'hi', 'pa' => 'pa'];
    $whisperLang = $langMap[$lang] ?? 'hi'; // default hindi for better Indian accent support

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'            => new CURLFile($filePath, 'audio/ogg', 'voice.ogg'),
            'model'           => 'whisper-1',
            'language'        => $whisperLang,
            'response_format' => 'text',
        ],
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$key}"],
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    @unlink($filePath); // cleanup temp file

    return ($code === 200 && $resp) ? trim($resp) : null;
}

/**
 * Extract booking details from transcribed text using GPT-4o-mini.
 * Returns array with keys: doctor_name, date, session, patient_name, place
 */
function extractBookingFromTranscript(string $transcript, array $doctors): ?array {
    $key = getOpenAiKey();
    if (!$key) return null;

    $today    = date('Y-m-d') . ' (' . date('d M Y') . ')';
    $tomorrow = date('Y-m-d', strtotime('+1 day')) . ' (' . date('d M Y', strtotime('+1 day')) . ')';

    $doctorList = '';
    foreach ($doctors as $d) {
        $doctorList .= "- ID:{$d['id']} Name:{$d['name']}" . (!empty($d['specialization']) ? " ({$d['specialization']})" : '') . "\n";
    }

    $prompt = <<<PROMPT
Extract appointment booking details from this voice message transcript.

Today: {$today}
Tomorrow: {$tomorrow}

Available doctors:
{$doctorList}

Voice transcript: "{$transcript}"

Return JSON with these fields (use null if not mentioned):
- doctor_id: integer ID from doctor list (match by name/specialty), or null
- doctor_name: matched doctor name string, or null
- date: YYYY-MM-DD (only today or tomorrow), or null
- session: "morning" or "evening", or null
- patient_name: patient name if mentioned, or null
- place: city or area name, or null

Only return valid JSON, nothing else.
PROMPT;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'           => 'gpt-4o-mini',
            'messages'        => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'max_tokens'      => 250,
            'temperature'     => 0,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$key}",
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) return null;

    $data    = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;

    $extracted = json_decode($content, true);
    return is_array($extracted) ? $extracted : null;
}
