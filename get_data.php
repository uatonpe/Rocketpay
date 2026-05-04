<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate'); // कॅशिंग टाळण्यासाठी

// सेव्ह केलेली JSON फाईल वाचा आणि आउटपुट करा
if (file_exists('dashboard_data.json')) {
    echo file_get_contents('dashboard_data.json');
} else {
    // जर फाईल अस्तित्वात नसेल तर त्रुटी संदेश पाठवा
    http_response_code(500);
    echo json_encode(['error' => 'Data file not found. Please run the update script.']);
}
?>
