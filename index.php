<?php
/**
 * License Server Control Panel
 * لوحة تحكم سيرفر الرخص
 */

session_start();
require_once 'config.php';

// كلمة مرور لوحة التحكم (غيّرها!)
$ADMIN_PASSWORD = 'admin@2024';

$logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $logged_in = true;
    } else {
        $login_error = 'كلمة مرور خاطئة';
    }
}

// تسجيل الخروج
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// إضافة رخصة جديدة
if ($logged_in && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_license'])) {
    $machine_id = trim($_POST['machine_id']);
    $customer_name = trim($_POST['customer_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $domain = trim($_POST['domain']);
    $license_type = $_POST['license_type'];
    $notes = trim($_POST['notes']);
    
    $license_key = generateLicenseKey();
    $expiry_date = calculateExpiry($license_type);
    $ip = $_SERVER['REMOTE_ADDR'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO licenses 
            (machine_id, customer_name, phone, email, domain, license_key, license_type, activation_date, expiry_date, ip_address, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([$machine_id, $customer_name, $phone, $email, $domain, $license_key, $license_type, $expiry_date, $ip, $notes]);
        
        $success_message = "✅ تم إضافة الرخصة بنجاح! المفتاح: $license_key";
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            $error_message = '❌ Machine ID مُستخدم مسبقاً';
        } else {
            $error_message = '❌ خطأ: ' . $e->getMessage();
        }
    }
}

// تعديل حالة الرخصة
if ($logged_in && isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE licenses SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php');
    exit;
}

// حذف رخصة
if ($logged_in && isset($_GET['delete']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: index.php');
    exit;
}

// جلب جميع الرخص
$licenses = [];
$stats = ['total' => 0, 'active' => 0, 'expired' => 0, 'lifetime' => 0];

if ($logged_in) {
    $licenses = $pdo->query("SELECT * FROM licenses ORDER BY created_at DESC")->fetchAll();
    
    $stats['total'] = count($licenses);
    foreach($licenses as $lic) {
        if ($lic['is_active']) $stats['active']++;
        if ($lic['license_type'] == 'lifetime') $stats['lifetime']++;
        if ($lic['expiry_date'] && strtotime($lic['expiry_date']) < time()) $stats['expired']++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Server - لوحة التحكم</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
        }
        
        /* Login Page */
        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-box {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 100%;
        }
        .login-box h1 {
            text-align: center;
            color: #1e3c72;
            margin-bottom: 30px;
            font-size: 28px;
        }
        .login-box .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-box .logo i {
            font-size: 60px;
            color: #1e3c72;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1e3c72;
        }
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .error {
            background: #f44336;
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success {
            background: #4caf50;
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        /* Dashboard */
        .dashboard {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: #1e3c72;
            font-size: 28px;
        }
        .header .logout {
            background: #f44336;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .header .logout:hover {
            background: #d32f2f;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card i {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .stat-card.total { color: #2196f3; }
        .stat-card.active { color: #4caf50; }
        .stat-card.expired { color: #f44336; }
        .stat-card.lifetime { color: #ff9800; }
        .stat-card h3 {
            font-size: 36px;
            margin: 10px 0;
        }
        .stat-card p {
            color: #666;
            font-size: 14px;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .card h2 {
            color: #1e3c72;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f5f5f5;
            font-weight: bold;
            color: #333;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .expired-row {
            background: #ffebee !important;
        }
        .inactive-row {
            opacity: 0.6;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-active { background: #4caf50; color: white; }
        .badge-inactive { background: #f44336; color: white; }
        .badge-lifetime { background: #ff9800; color: white; }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 5px;
            text-decoration: none;
            display: inline-block;
            margin: 0 2px;
            color: white;
            cursor: pointer;
        }
        .btn-toggle { background: #2196f3; }
        .btn-delete { background: #f44336; }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        code {
            background: #f5f5f5;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
        }
        
        .api-info {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #2196f3;
            margin-top: 20px;
        }
        .api-info h3 {
            color: #1976d2;
            margin-bottom: 10px;
        }
        .api-info code {
            display: block;
            margin: 10px 0;
            padding: 10px;
            background: white;
        }
    </style>
</head>
<body>
    <?php if (!$logged_in): ?>
        <div class="login-container">
            <div class="login-box">
                <div class="logo">
                    <i class="fas fa-server"></i>
                </div>
                <h1>License Server</h1>
                <?php if (isset($login_error)): ?>
                    <div class="error"><?php echo $login_error; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <input type="password" name="password" placeholder="كلمة المرور" required autofocus>
                    </div>
                    <button type="submit" name="login" class="btn">
                        <i class="fas fa-sign-in-alt"></i> دخول
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="dashboard">
            <div class="header">
                <h1><i class="fas fa-server"></i> License Server Control Panel</h1>
                <a href="?logout" class="logout">
                    <i class="fas fa-sign-out-alt"></i> خروج
                </a>
            </div>
            
            <div class="stats">
                <div class="stat-card total">
                    <i class="fas fa-key"></i>
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>إجمالي الرخص</p>
                </div>
                <div class="stat-card active">
                    <i class="fas fa-check-circle"></i>
                    <h3><?php echo $stats['active']; ?></h3>
                    <p>رخص نشطة</p>
                </div>
                <div class="stat-card expired">
                    <i class="fas fa-times-circle"></i>
                    <h3><?php echo $stats['expired']; ?></h3>
                    <p>رخص منتهية</p>
                </div>
                <div class="stat-card lifetime">
                    <i class="fas fa-infinity"></i>
                    <h3><?php echo $stats['lifetime']; ?></h3>
                    <p>رخص مفتوحة</p>
                </div>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-plus-circle"></i> إضافة رخصة جديدة</h2>
                <?php if (isset($success_message)): ?>
                    <div class="success"><?php echo $success_message; ?></div>
                <?php endif; ?>
                <?php if (isset($error_message)): ?>
                    <div class="error"><?php echo $error_message; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <input type="text" name="customer_name" placeholder="اسم العميل *" required>
                        </div>
                        <div class="form-group">
                            <input type="tel" name="phone" placeholder="رقم الهاتف *" required>
                        </div>
                        <div class="form-group">
                            <input type="email" name="email" placeholder="البريد الإلكتروني">
                        </div>
                        <div class="form-group">
                            <input type="text" name="domain" placeholder="النطاق/الموقع">
                        </div>
                        <div class="form-group">
                            <input type="text" name="machine_id" placeholder="Machine ID *" required>
                        </div>
                        <div class="form-group">
                            <select name="license_type" required>
                                <option value="">اختر نوع الرخصة</option>
                                <option value="trial_1day">تجريبي - يوم واحد</option>
                                <option value="trial_1week">تجريبي - أسبوع</option>
                                <option value="trial_1month">تجريبي - شهر</option>
                                <option value="monthly">شهري</option>
                                <option value="yearly">سنوي</option>
                                <option value="lifetime">مفتوح - مدى الحياة</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <textarea name="notes" rows="3" placeholder="ملاحظات (اختياري)"></textarea>
                    </div>
                    <button type="submit" name="add_license" class="btn">
                        <i class="fas fa-plus"></i> إضافة الرخصة
                    </button>
                </form>
                
                <div class="api-info">
                    <h3><i class="fas fa-info-circle"></i> معلومات API</h3>
                    <p><strong>API URL:</strong></p>
                    <code>http://yourserver.com/api.php</code>
                    <p><strong>API Key:</strong></p>
                    <code><?php echo API_SECRET_KEY; ?></code>
                    <p style="margin-top: 10px; color: #666; font-size: 13px;">
                        قم بإعداد هذا الـ API URL و API Key في ملف config الخاص بنظام IPTV عند العميل
                    </p>
                </div>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-list"></i> جميع الرخص</h2>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>العميل</th>
                            <th>الهاتف</th>
                            <th>Machine ID</th>
                            <th>License Key</th>
                            <th>النوع</th>
                            <th>التفعيل</th>
                            <th>الانتهاء</th>
                            <th>IP</th>
                            <th>آخر فحص</th>
                            <th>الحالة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($licenses as $lic): 
                            $is_expired = $lic['expiry_date'] && strtotime($lic['expiry_date']) < time();
                            $row_class = '';
                            if ($is_expired) $row_class = 'expired-row';
                            if (!$lic['is_active']) $row_class .= ' inactive-row';
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td><?php echo $lic['id']; ?></td>
                                <td><?php echo htmlspecialchars($lic['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($lic['phone']); ?></td>
                                <td><code><?php echo substr($lic['machine_id'], 0, 20); ?>...</code></td>
                                <td><code><?php echo $lic['license_key']; ?></code></td>
                                <td>
                                    <?php if ($lic['license_type'] == 'lifetime'): ?>
                                        <span class="badge badge-lifetime">مفتوح</span>
                                    <?php else: ?>
                                        <?php echo getLicenseTypeName($lic['license_type']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($lic['activation_date'])); ?></td>
                                <td>
                                    <?php if ($lic['expiry_date']): ?>
                                        <?php echo date('Y-m-d', strtotime($lic['expiry_date'])); ?>
                                        <?php if ($is_expired): ?>
                                            <br><small style="color: #f44336;">منتهي</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($lic['ip_address']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($lic['last_check'])); ?></td>
                                <td>
                                    <?php if ($lic['is_active']): ?>
                                        <span class="badge badge-active">نشط</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?toggle&id=<?php echo $lic['id']; ?>" class="btn-small btn-toggle">
                                        <i class="fas fa-power-off"></i>
                                    </a>
                                    <a href="?delete&id=<?php echo $lic['id']; ?>" class="btn-small btn-delete" 
                                       onclick="return confirm('متأكد من الحذف؟')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>
