<?php

$env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);

$authUser = $env['AUTH_USER'] ?? '';
$authPass = $env['AUTH_PASS'] ?? '';
$customersUrl = $env['CUSTOMERS_URL'] ?? '';
$debtSyncUrl = $env['DEBT_SYNC_URL'] ?? '';
$updateUrl = $env['UPDATE_URL'] ?? '';

// 🛠 פונקציית קריאה עם Basic Auth
function fetchJson($url, $authUser, $authPass)
{
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Basic " . base64_encode("$authUser:$authPass")
        ]
    ];
    $context = stream_context_create($opts);
    $json = file_get_contents($url, false, $context);
    return json_decode($json, true);
}

// 🛠 פונקציית שליחה עם POST
function postJson($url, $data, $authUser, $authPass)
{
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" .
                "Authorization: Basic " . base64_encode("$authUser:$authPass"),
            'content' => json_encode($data, JSON_UNESCAPED_UNICODE)
        ]
    ];
    $context = stream_context_create($opts);
    return file_get_contents($url, false, $context);
}

// 🧩 שלב 1: שאיבת לקוחות
$customers = fetchJson($customersUrl, $authUser, $authPass);
$customersByNumber = [];
foreach ($customers as $cust) {
    $cardNumber = $cust['CardNumber'];
    $customersByNumber[$cardNumber] = $cust;
}

// 🧩 שלב 2: שאיבת לקוחות עם חוב
$debtList = fetchJson($debtSyncUrl, $authUser, $authPass);

// 🧩 שלב 3: עדכון לקוחות לפי CardStatusToUpdate
$updatedCount = 0;


foreach ($debtList as $debtCustomer) {

    $cardNumber = $debtCustomer['CardNumber'];
    $statusToUpdate = $debtCustomer['CardStatusToUpdate'];

    if (isset($customersByNumber[$cardNumber])) {
        $customer = $customersByNumber[$cardNumber];

        // עדכון השדה CardGroupNo4
        $customer['CardGroupNo4'] = $statusToUpdate;
        if (empty($customer['Phone1'])) {
            $customer['Phone1'] = !empty($customer['Phone2']) ? $customer['Phone2'] : (!empty($customer['Phone3']) ? $customer['Phone3'] : '000-0000000');
        }

        // שליחה לשרת
        $response = postJson($updateUrl, $customer, $authUser, $authPass);

        // בדיקה אם הצליח (אפשר להוסיף תנאי לפי תגובה)
        $updatedCount++;
    }
}

echo "עודכנו $updatedCount לקוחות בהצלחה.\n";
