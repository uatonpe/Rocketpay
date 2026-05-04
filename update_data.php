<?php
// त्रुटी दाखवण्यासाठी
ini_set('display_errors', 1);
error_reporting(E_ALL);

// वेळ आणि मेमरी लिमिट
set_time_limit(1800); 
ini_set('memory_limit', '512M'); 

echo "Script started in INCREMENTAL UPDATE mode...\n<br>";

// *** तुमचा JWT टोकन इथे टाका ***
$apiToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6IjAwMDAwMTk4LTg4ZjktNDAwMC1hNWFlLTYxMzAyYzRiMDY3NyIsIm1vYmlsZV9udW1iZXIiOiIrOTE4MDA3NzczODg4IiwibWVyY2hhbnRfaWQiOiIwMDAwMDE5OC04OGY5LTQxMTQtOTZlYy0wMDRiNGQ4YzM4MDAiLCJyb2xlIjoiQURNSU4iLCJhY2NvdW50X3R5cGUiOiJNRVJDSEFOVCIsInNvdXJjZSI6IkNPTU1PTl9MT0dJTiIsImVudGVycHJpc2VfaWQiOiJlNTFhMzBkNS0yNDA1LTQzODEtOWM3Mi0wMTFjNzA5NDgzNzciLCJpYXQiOjE3NTkwNDQ0MjB9.sXCTjJ84K32ucngOkKAGvJ2uaNFTBv9_XW2LH3bGt_M"; 
$staffSheetUrl = "https://docs.google.com/spreadsheets/d/e/2PACX-1vT0_v9wbfm4s-8lWD3rEsWDhjHHt_Z5V4eqbRWKYDEUpWTZ8D2MG5zwYU17N4qKve30xYduukIbtnw6/pub?output=csv";
$savePath = __DIR__ . '/dashboard_data.json';

// cURL फंक्शन (यात बदल नाही)
function fetchData($url, $token) {
    // ... (हे फंक्शन आधीच्या कोडप्रमाणेच राहील) ...
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('x-token: ' . $token, 'Content-Type: application/json'));
    $result = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { return null; } curl_close($ch);
    if ($http_code == 200) { return json_decode($result, true); }
    return null;
}

// --- १. जुना डेटा आणि शेवटची अपडेट वेळ मिळवणे ---
$existingData = [];
$lastSyncTimestamp = 0;
if (file_exists($savePath)) {
    echo "Existing data file found. Reading last sync time...\n<br>";
    $jsonContent = file_get_contents($savePath);
    $existingData = json_decode($jsonContent, true);
    // जर JSON वाचण्यात अडचण आली किंवा तो रिकामा असेल, तर सुरुवातीपासून सुरू करा
    if (json_last_error() !== JSON_ERROR_NONE || !isset($existingData['last_updated'])) {
        echo "Could not read existing JSON properly. Starting from scratch.\n<br>";
        $lastSyncTimestamp = 0;
        $existingData = ['mandates' => [], 'installments' => [], 'staffMap' => [], 'unitMap' => []];
    } else {
        $lastSyncTimestamp = $existingData['last_updated'];
        echo "Last sync was at: " . date('Y-m-d H:i:s', $lastSyncTimestamp) . " (Timestamp: {$lastSyncTimestamp})\n<br>";
    }
} else {
    echo "No existing data file. This is the first run. Fetching all data...\n<br>";
    $existingData = ['mandates' => [], 'installments' => [], 'staffMap' => [], 'unitMap' => []];
}


// --- २. फक्त नवीन किंवा बदललेला डेटा API कडून मिळवणे ---
echo "<h3>Fetching new or updated mandates since last sync...</h3>";
$newOrUpdatedMandates = [];
$lastCreatedAt = 0; 
// *** सर्वात महत्त्वाचा बदल: updated_at मध्ये शेवटची सिंक वेळ वापरणे ***
$lastUpdatedAt = $lastSyncTimestamp; 
$hasMoreData = true;
$page = 1;

while ($hasMoreData) {
    echo "Fetching page {$page} of new data...\n<br>";
    $syncUrl = "https://api.rocketpay.co.in/v1/merchant/mandates/sync?updated_at={$lastUpdatedAt}&limit=100&created_at={$lastCreatedAt}";
    $data = fetchData($syncUrl, $apiToken); 
    
    if (isset($data['items']) && count($data['items']) > 0) {
        // सर्व नवीन मिळालेले मँडेट्स एका तात्पुरत्या ॲरेमध्ये जमा करणे
        $newOrUpdatedMandates = array_merge($newOrUpdatedMandates, $data['items']);
        
        $lastItem = end($data['items']);
        $lastCreatedAt = $lastItem['created_at'];
        $lastUpdatedAt = $lastItem['updated_at'];
        $page++;
    } else {
        $hasMoreData = false;
    }
    sleep(1);
    flush(); ob_flush();
}

echo "Found " . count($newOrUpdatedMandates) . " new or updated mandates to process.\n<br>";

if (count($newOrUpdatedMandates) > 0) {
    // --- ३. नवीन डेटा जुन्या डेटामध्ये मर्ज करणे ---
    echo "Merging new data with existing data...\n<br>";
    
    // जलद शोधण्यासाठी, जुन्या मँडेट्सची एक 'मॅप' बनवणे
    $mandatesMap = [];
    foreach ($existingData['mandates'] as $mandate) {
        $mandatesMap[$mandate['id']] = $mandate;
    }

    $newInstallmentIds = [];
    foreach ($newOrUpdatedMandates as $mandate) {
        if (!empty($mandate['is_deleted']) || $mandate['status'] === 'deleted') {
            // जर मँडेट डिलीट झाले असेल, तर त्याला मॅपमधून काढून टाकणे
            unset($mandatesMap[$mandate['id']]);
            unset($existingData['installments'][$mandate['id']]); // त्याचे हप्ते पण काढून टाकणे
        } else {
            // जर मँडेट नवीन असेल किंवा अपडेट झाले असेल, तर त्याला मॅपमध्ये टाकणे/बदलणे
            $mandatesMap[$mandate['id']] = $mandate;
            $newInstallmentIds[] = $mandate['id']; // याच्या हप्त्यांची माहिती मिळवावी लागेल
        }
    }
    
    // मॅपला पुन्हा सामान्य ॲरेमध्ये बदलणे
    $existingData['mandates'] = array_values($mandatesMap);

    // --- ४. फक्त नवीन किंवा बदललेल्या मँडेट्सचे हप्ते मिळवणे ---
    if (!empty($newInstallmentIds)) {
        echo "Fetching installments for " . count($newInstallmentIds) . " updated mandates...\n<br>";
        foreach ($newInstallmentIds as $mandateId) {
            $installmentsUrl = "https://api.rocketpay.co.in/v2/merchant/installments?mandate_id=" . $mandateId;
            $installmentData = fetchData($installmentsUrl, $apiToken);
            // नवीन हप्त्यांची माहिती जुन्या माहितीवर ओव्हरराईट करणे
            $existingData['installments'][$mandateId] = isset($installmentData['items']) ? $installmentData['items'] : [];
        }
    }
} else {
    echo "No new updates found.\n<br>";
}

// --- ५. स्टाफची माहिती मिळवणे आणि अपडेट करणे (हा भाग प्रत्येक वेळी चालेल) ---
echo "Fetching and updating staff data...\n<br>";
// ... (हा संपूर्ण भाग जसा आहे तसाच राहील) ...
$staffDataCsv = @file_get_contents($staffSheetUrl);
// ... (बाकी सर्व कोड तसाच) ...
$staffDataMap = [];
$unitVillageStaffMap = [];

$rows = explode("\n", trim($staffDataCsv));
array_shift($rows);

foreach ($rows as $row) {
    $columns = str_getcsv($row);
    if (count($columns) >= 5) {
        list($loanNumber, $customerName, $staffName, $villageName, $unitName) = array_map('trim', $columns);
        $villageName = empty($villageName) ? 'N/A' : $villageName;
        if ($loanNumber && $staffName && $unitName) {
            $staffDataMap[$loanNumber] = ['staffName' => $staffName, 'villageName' => $villageName, 'unitName' => $unitName];
            if (!isset($unitVillageStaffMap[$unitName])) $unitVillageStaffMap[$unitName] = [];
            if (!isset($unitVillageStaffMap[$unitName][$villageName])) $unitVillageStaffMap[$unitName][$villageName] = [];
            $unitVillageStaffMap[$unitName][$villageName][$staffName] = true;
        }
    }
}
foreach($unitVillageStaffMap as $unit => &$villages) {
    foreach($villages as $village => &$staffs) {
        $staffs = array_keys($staffs);
    }
}

// जुन्या डेटा ऑब्जेक्टमध्ये स्टाफची माहिती अपडेट करणे
$existingData['staffMap'] = $staffDataMap;
$existingData['unitMap'] = $unitVillageStaffMap;


// --- ६. अंतिम डेटा फाईलमध्ये सेव्ह करणे ---
echo "Saving updated data to JSON file...\n<br>";
$existingData['last_updated'] = time(); // नवीन अपडेटची वेळ सेव्ह करणे

$jsonOutput = json_encode($existingData);

if (file_put_contents($savePath, $jsonOutput)) {
    echo "<b style='color:green;'>Data updated successfully! Total mandates now: " . count($existingData['mandates']) . "</b>\n<br>";
    echo "File saved at: " . $savePath;
} else {
    echo "<b style='color:red;'>Error: Could not save the data file.</b>\n<br>";
}

?>
