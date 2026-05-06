<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Database connection
$mysqli = new mysqli('202.5.50.144', 'hamkoict', 'HelloWorld@1#', 'kmi_pasting_weight');

if ($mysqli->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database connection failed: " . $mysqli->connect_error]));
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$action) {

    file_put_contents(
        "debug_log.txt",
        date("Y-m-d H:i:s") . " | " . json_encode($_POST) . "\n",
        FILE_APPEND
    );

    if (!isset($_POST['machine_no'])) {
        echo json_encode(["status" => "error", "msg" => "machine_no missing"]);
        exit;
    }

    $machine_no = (int)$_POST['machine_no'];
    $item_id    = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $weight     = isset($_POST['weight']) ? (float)$_POST['weight'] : 0;

    if ($machine_no <= 0) {
        echo json_encode(["status" => "error", "msg" => "Invalid machine_no"]);
        exit;
    }

    if ($weight <= 5 || $weight > 1000) {
        echo json_encode(["status" => "error", "msg" => "Invalid weight"]);
        exit;
    }

    $resolved_item_id = $item_id;
    $deviceStmt = $mysqli->prepare("SELECT active_item_id FROM scale_devices WHERE machine_no = ? LIMIT 1");
    $deviceStmt->bind_param("i", $machine_no);
    $deviceStmt->execute();
    $deviceRes = $deviceStmt->get_result();
    $device = $deviceRes ? $deviceRes->fetch_assoc() : null;
    $deviceStmt->close();

    $active_item_id = (int)($device['active_item_id'] ?? 0);
    if ($active_item_id > 0) {
        $resolved_item_id = $active_item_id;
    } elseif ($resolved_item_id <= 0) {
        $fallbackItemStmt = $mysqli->query("SELECT id FROM items ORDER BY id ASC LIMIT 1");
        $fallbackItem = $fallbackItemStmt ? $fallbackItemStmt->fetch_assoc() : null;
        $resolved_item_id = (int)($fallbackItem['id'] ?? 0);
    }

    if ($resolved_item_id <= 0) {
        echo json_encode(["status" => "error", "msg" => "No active item configured", "machine_no" => $machine_no]);
        exit;
    }

    // Keep backend as source of truth: always save using resolved machine item mapping.

    $itemCheckStmt = $mysqli->prepare("SELECT id FROM items WHERE id = ? LIMIT 1");
    $itemCheckStmt->bind_param("i", $resolved_item_id);
    $itemCheckStmt->execute();
    $itemRes = $itemCheckStmt->get_result();
    $itemExists = $itemRes && $itemRes->num_rows > 0;
    $itemCheckStmt->close();

    if (!$itemExists) {
        echo json_encode(["status" => "error", "msg" => "Active item not found", "active_item_id" => $resolved_item_id]);
        exit;
    }

    $deviceUpsert = $mysqli->prepare("INSERT INTO scale_devices (machine_no, active_item_id, location_name) VALUES (?, ?, '') ON DUPLICATE KEY UPDATE active_item_id = VALUES(active_item_id)");
    $deviceUpsert->bind_param("ii", $machine_no, $resolved_item_id);
    $deviceUpsert->execute();
    $deviceUpsert->close();

    $stmt = $mysqli->prepare("INSERT INTO plate_weights (item_id, weight, machine_no) VALUES (?, ?, ?)");
    $stmt->bind_param("idi", $resolved_item_id, $weight, $machine_no);

    if ($stmt->execute()) {
        echo json_encode(["status" => "ok", "machine_no" => $machine_no, "item_id" => $resolved_item_id, "weight" => $weight]);
    } else {
        echo json_encode(["status" => "db_error", "message" => $stmt->error]);
    }

    exit;
}

// 2. GET ACTIVE ITEM (For ESP32 Individual Sync)
if ($action === 'get_active_item') {
    $m_no = isset($_GET['machine_no']) ? (int)$_GET['machine_no'] : 0;
    $stmt = $mysqli->prepare("SELECT active_item_id FROM scale_devices WHERE machine_no = ? LIMIT 1");
    $stmt->bind_param("i", $m_no);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    echo $row['active_item_id'] ?? "0";
    exit;
}

// 3. SET ACTIVE ITEM (Called from Dashboard SYNC button)
if ($action === 'set_active_item') {
    $item_id = (int)$_GET['item_id'];
    $m_no = (int)$_GET['machine_no'];
    $stmt = $mysqli->prepare("INSERT INTO scale_devices (machine_no, active_item_id, location_name) VALUES (?, ?, '') ON DUPLICATE KEY UPDATE active_item_id = VALUES(active_item_id)");
    $stmt->bind_param("ii", $m_no, $item_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(["status" => "success"]);
    exit;
}

// 4. FETCH SUMMARY (Dashboard Grid Data)
if ($action === 'fetch_summary') {
    $m_no = (int)$_GET['machine_no'];
    $item_id = !empty($_GET['item_id']) ? (int)$_GET['item_id'] : null;
    $date = !empty($_GET['date']) ? $_GET['date'] : null;

    $where = "WHERE pw.machine_no = $m_no";
    if ($item_id) $where .= " AND pw.item_id = $item_id";
    if ($date) $where .= " AND DATE(pw.created_at) = '$date'";

    $histRes = $mysqli->query("SELECT weight, created_at FROM plate_weights pw $where ORDER BY pw.id DESC");
    $history = $histRes->fetch_all(MYSQLI_ASSOC);

    $latestRes = $mysqli->query("SELECT pw.id as weight_id, pw.item_id, pw.weight, pw.created_at, i.standard_weight, i.tolerance
                                 FROM plate_weights pw
                                 JOIN items i ON pw.item_id = i.id
                                 $where ORDER BY pw.id DESC LIMIT 1");
    $latest = $latestRes->fetch_assoc();

    echo json_encode(["latest" => $latest ?: null, "history" => $history]);
    exit;
}

// 5. UTILITIES
if ($action === 'list_items') {
    echo json_encode($mysqli->query("SELECT id, name, standard_weight, tolerance FROM items")->fetch_all(MYSQLI_ASSOC));
    exit;
}

if ($action === 'list_devices') {
    echo json_encode($mysqli->query("SELECT * FROM scale_devices ORDER BY machine_no ASC")->fetch_all(MYSQLI_ASSOC));
    exit;
}

if ($action === 'add_device') {
    $m = (int)$_POST['machine_no'];
    $loc = $mysqli->real_escape_string($_POST['location_name']);
    if($mysqli->query("INSERT INTO scale_devices (machine_no, location_name) VALUES ($m, '$loc') ON DUPLICATE KEY UPDATE location_name='$loc'")) {
        echo json_encode(["status" => "success", "message" => "Machine added successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => $mysqli->error]);
    }
    exit;
}

if ($action === 'add_item') {
    $name = $mysqli->real_escape_string($_POST['item_name']);
    $std = (float)$_POST['std_weight'];
    $tol = (float)$_POST['tolerance'];
    if($mysqli->query("INSERT INTO items (name, standard_weight, tolerance) VALUES ('$name', $std, $tol)")) {
        echo json_encode(["status" => "success", "message" => "Product added successfully", "id" => $mysqli->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $mysqli->error]);
    }
    exit;
}

if ($action === 'edit_item') {
    $id = (int)$_POST['id'];
    $name = $mysqli->real_escape_string($_POST['item_name']);
    $std = (float)$_POST['std_weight'];
    $tol = (float)$_POST['tolerance'];
    if($mysqli->query("UPDATE items SET name='$name', standard_weight=$std, tolerance=$tol WHERE id=$id")) {
        echo json_encode(["status" => "success", "message" => "Product updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => $mysqli->error]);
    }
    exit;
}

if ($action === 'delete_item') {
    $id = (int)$_GET['id'];
    if($mysqli->query("DELETE FROM items WHERE id=$id")) {
        echo json_encode(["status" => "success", "message" => "Product deleted successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => $mysqli->error]);
    }
    exit;
}
?>
