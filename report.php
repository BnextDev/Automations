<?php
$env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);

$BASE_URL     = rtrim($env['DEBT_REPORT_BASE_URL'], '/');
$AUTH_USER    = $env['AUTH_USER'];
$AUTH_PASS    = $env['AUTH_PASS'];
$PM_TOKEN     = $env['POSTMARK_SERVER_TOKEN'];
$PM_FROM      = $env['POSTMARK_SERVER_EMAIL'];
$GLOBAL_RCPTS = array_map('trim', explode(',', $env['GLOBAL_REPORT_RECIPIENTS']));

// מפענחים את מפת הסוכנים
$AGENTS_MAP = [];
if (!empty($env['AGENTS_EMAILS'])) {
    $items = explode(',', trim($env['AGENTS_EMAILS'], "\" \t\n\r\0\x0B"));
    foreach ($items as $item) {
        $parts = array_map('trim', explode('=', $item));
        if (count($parts) >= 2) {
            $num  = (int)$parts[0];
            $mail = $parts[1] ?? '';
            $name = $parts[2] ?? null; // אופציונלי
            if ($num && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $AGENTS_MAP[$num] = ['email' => $mail, 'name' => $name];
            }
        }
    }
}

function fetchReportHtml(string $url, string $user, string $pass): string
{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_USERPWD        => $user . ':' . $pass, // Basic Auth
		CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_HTTPHEADER     => [
			'Accept: text/html,application/xhtml+xml',
		],
	]);
	$html = curl_exec($ch);
	if ($html === false) {
		throw new RuntimeException('curl error: ' . curl_error($ch));
	}
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($code >= 400) {
		throw new RuntimeException("HTTP $code when fetching $url");
	}
	return $html;
}

function sendPostmark(string $token, string $from, string $toCsv, string $subject, string $html, ?string $replyTo = null, string $stream = 'outbound'): void
{
	$payload = [
		'From'          => $from,
		'To'            => $toCsv,
		'Subject'       => $subject,
		'HtmlBody'      => $html,
		'MessageStream' => $stream,
	];
	if ($replyTo) $payload['ReplyTo'] = $replyTo;

	$ch = curl_init('https://api.postmarkapp.com/email');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST           => true,
		CURLOPT_HTTPHEADER     => [
			'Accept: application/json',
			'Content-Type: application/json',
			'X-Postmark-Server-Token: ' . $token,
		],
		CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
		CURLOPT_TIMEOUT        => 30,
	]);
	$resp = curl_exec($ch);
	if ($resp === false) {
		throw new RuntimeException('Postmark curl error: ' . curl_error($ch));
	}
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($code >= 400) {
		throw new RuntimeException("Postmark HTTP $code: $resp");
	}
}

/** 2.1 – שולחים דוח כללי לשני הנמענים */
try {
	$url = $BASE_URL . '?email=1&pagePerAgent=0';
	$html = fetchReportHtml($url, $AUTH_USER, $AUTH_PASS);
	$subject = 'דו"ח גבייה - סיכום כללי';
	sendPostmark($PM_TOKEN, $PM_FROM, implode(',', $GLOBAL_RCPTS), $subject, $html);
} catch (Throwable $e) {
	error_log('GLOBAL REPORT SEND FAILED: ' . $e->getMessage());
	// אפשר גם לשמור בקובץ/DB
}

/** 2.2 – שולחים דוח פר-סוכן לכל סוכן */
foreach ($AGENTS_MAP as $agentNum => $info) {
    $email = $info['email'];
    $name  = $info['name'] ?? null;

    try {
        $url  = $BASE_URL . '?email=1&pagePerAgent=0&agent=' . urlencode((string)$agentNum);
        $html = fetchReportHtml($url, $AUTH_USER, $AUTH_PASS);

        // דלג אם אין טבלה (אין נתונים)
        if (stripos($html, '<table') === false) {
            continue;
        }

        $subject = $name
            ? "דו\"ח גבייה — {$name} (#{$agentNum})"
            : "דו\"ח גבייה — סוכן #{$agentNum}";

        sendPostmark($PM_TOKEN, $PM_FROM, $email, $subject, $html);

    } catch (Throwable $e) {
        error_log("AGENT $agentNum SEND FAILED: " . $e->getMessage());
    }
}

