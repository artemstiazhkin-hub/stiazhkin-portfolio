<?php

declare(strict_types=1);

$configPath = __DIR__ . '/telegram-config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Config missing';
    exit;
}

$config = require $configPath;
$botToken = (string)($config['bot_token'] ?? '');
$adminChatId = (string)($config['admin_chat_id'] ?? '');
$webhookSecret = (string)($config['webhook_secret'] ?? '');

if ($botToken === '' || $adminChatId === '') {
    http_response_code(500);
    echo 'Config invalid';
    exit;
}

if ($webhookSecret !== '') {
    $incomingSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

    if (!hash_equals($webhookSecret, $incomingSecret)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo 'OK';
    exit;
}

$rawInput = file_get_contents('php://input') ?: '';
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$message = $update['message'] ?? null;

if (!is_array($message)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$chat = $message['chat'] ?? [];
$from = $message['from'] ?? [];
$chatId = (string)($chat['id'] ?? '');
$chatType = (string)($chat['type'] ?? '');
$text = trim((string)($message['text'] ?? ''));

if ($chatId === '' || $chatType !== 'private') {
    http_response_code(200);
    echo 'OK';
    exit;
}

$dataDir = __DIR__ . '/data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function tg_call(string $method, array $params): array
{
    global $botToken;

    $url = 'https://api.telegram.org/bot' . $botToken . '/' . $method;
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'description' => $error];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Bad response'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\n",
            'content' => $payload,
            'timeout' => 15,
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    $decoded = json_decode((string)$response, true);

    return is_array($decoded) ? $decoded : ['ok' => false, 'description' => 'Bad response'];
}

function send_message(string $chatId, string $text, array $replyMarkup = null): void
{
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    tg_call('sendMessage', $params);
}

function keyboard(array $rows): array
{
    return [
        'keyboard' => array_map(
            static fn(array $row): array => array_map(static fn(string $label): array => ['text' => $label], $row),
            $rows
        ),
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
        'input_field_placeholder' => 'Выберите вариант или напишите свой',
    ];
}

function remove_keyboard(): array
{
    return ['remove_keyboard' => true];
}

function clean_text(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    return mb_substr($value, 0, 700, 'UTF-8');
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function state_file(string $chatId): string
{
    global $dataDir;
    $safeId = preg_replace('/[^0-9_-]/', '_', $chatId) ?: 'unknown';
    return $dataDir . '/state_' . $safeId . '.json';
}

function load_state(string $chatId): array
{
    $file = state_file($chatId);

    if (!is_file($file)) {
        return ['step' => 'start', 'answers' => []];
    }

    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : ['step' => 'start', 'answers' => []];
}

function save_state(string $chatId, array $state): void
{
    file_put_contents(
        state_file($chatId),
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function reset_state(string $chatId): void
{
    $file = state_file($chatId);

    if (is_file($file)) {
        unlink($file);
    }
}

function user_label(array $from): string
{
    $name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));
    $username = (string)($from['username'] ?? '');
    $parts = [];

    if ($name !== '') {
        $parts[] = esc($name);
    }

    if ($username !== '') {
        $parts[] = '@' . esc($username);
    }

    return $parts ? implode(' ', $parts) : 'без имени';
}

function send_lead_to_admin(string $chatId, array $from, array $answers): void
{
    global $adminChatId;

    $summary = "Новая заявка с сайта stiazhkin.ru\n\n"
        . "Пользователь: " . user_label($from) . "\n"
        . "Telegram ID: " . esc($chatId) . "\n\n"
        . "Имя: " . esc((string)($answers['name'] ?? '')) . "\n"
        . "Что нужно: " . esc((string)($answers['task'] ?? '')) . "\n"
        . "Тема/ниша: " . esc((string)($answers['niche'] ?? '')) . "\n"
        . "Стиль: " . esc((string)($answers['style'] ?? '')) . "\n"
        . "Контакт: " . esc((string)($answers['contact'] ?? ''));

    send_message($adminChatId, $summary);
}

if ($text === '' || preg_match('/^\/start/i', $text)) {
    reset_state($chatId);
    save_state($chatId, ['step' => 'name', 'answers' => []]);
    send_message(
        $chatId,
        "Привет! Я бот портфолио Миши.\n\nОтветьте на пару коротких вопросов, а взрослый увидит заявку в чате.\n\nКак к вам обращаться?",
        remove_keyboard()
    );
    echo 'OK';
    exit;
}

if (preg_match('/^\/cancel/i', $text)) {
    reset_state($chatId);
    send_message($chatId, 'Ок, заявку сбросил. Чтобы начать заново, напишите /start.', remove_keyboard());
    echo 'OK';
    exit;
}

$state = load_state($chatId);
$step = (string)($state['step'] ?? 'start');
$answers = is_array($state['answers'] ?? null) ? $state['answers'] : [];
$answer = clean_text($text);

switch ($step) {
    case 'name':
        $answers['name'] = $answer;
        save_state($chatId, ['step' => 'task', 'answers' => $answers]);
        send_message(
            $chatId,
            'Что нужно сделать?',
            keyboard([
                ['Личная страница', 'Страница проекта'],
                ['Лендинг для события', 'Сайт для игры'],
                ['Другое'],
            ])
        );
        break;

    case 'task':
        $answers['task'] = $answer;
        save_state($chatId, ['step' => 'niche', 'answers' => $answers]);
        send_message(
            $chatId,
            'Для какой темы или ниши нужен сайт?',
            keyboard([
                ['Учёба / кружок', 'Игры / сервер'],
                ['Мероприятие', 'Маленький бизнес'],
                ['Портфолио', 'Другая тема'],
            ])
        );
        break;

    case 'niche':
        $answers['niche'] = $answer;
        save_state($chatId, ['step' => 'style', 'answers' => $answers]);
        send_message(
            $chatId,
            'Какой стиль ближе?',
            keyboard([
                ['Ярко и смело', 'Спокойно и чисто'],
                ['Похоже на комикс', 'Статусно и аккуратно'],
                ['Пока не знаю'],
            ])
        );
        break;

    case 'style':
        $answers['style'] = $answer;
        save_state($chatId, ['step' => 'contact', 'answers' => $answers]);
        send_message(
            $chatId,
            "Куда лучше ответить?\n\nМожно написать Telegram, телефон или просто «ответьте здесь».",
            remove_keyboard()
        );
        break;

    case 'contact':
        $answers['contact'] = $answer;
        send_lead_to_admin($chatId, $from, $answers);
        reset_state($chatId);
        send_message(
            $chatId,
            "Спасибо! Заявка ушла взрослому в чат.\n\nЕсли захотите отправить ещё одну идею, напишите /start.",
            remove_keyboard()
        );
        break;

    default:
        save_state($chatId, ['step' => 'name', 'answers' => []]);
        send_message($chatId, 'Давайте начнём заново. Как к вам обращаться?', remove_keyboard());
        break;
}

echo 'OK';
