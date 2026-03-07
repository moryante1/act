<?php
/**
 * SHASHETY PRO - Server & License Control System
 * النظام متكامل وشامل: الواجهة المتجاوبة + استثناء النسخ للسيريال + أنيميشن SHASHETY PRO
 */

session_start();
require_once 'config.php';

// === إعدادات البوت وتيليجرام الأصلي الخاص بك ===
define('TG_BOT_TOKEN', '1625295356:AAFh9i_nlqhkMD96WKPpeSKlcM32tC0Y1oM');
define('TG_CHAT_ID', '-519828616');

// دالة الإرسال لتيليجرام
function sendToTelegram($data, $license_key) {
    $text = "🆕 <b>طلب تفعيل (SHASHETY PRO)</b>\n\n";
    $text .= "👤 <b>العميل:</b> " . $data['customer_name'] . "\n";
    $text .= "📱 <b>الهاتف:</b> " . $data['phone'] . "\n";
    if (!empty($data['email'])) $text .= "📧 <b>الإيميل:</b> " . $data['email'] . "\n";
    if (!empty($data['domain'])) $text .= "🌐 <b>الدومين:</b> " . $data['domain'] . "\n";
    $text .= "💻 <b>Machine ID:</b> <code>" . $data['machine_id'] . "</code>\n";
    $text .= "📦 <b>الخطة:</b> " . $data['license_type'] . "\n";
    $text .= "🔑 <b>المفتاح:</b> <code>" . $license_key . "</code>\n\n";
    if (!empty($data['notes'])) $text .= "📝 <b>ملاحظات:</b> " . $data['notes'] . "\n";
    $text .= "✅ <b>حالة السيرفر:</b> مسجل بنجاح";

    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
    $post_fields = [
        'chat_id' => TG_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// حماية CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ADMIN_PASSWORD = 'admin@2024'; 
$logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

$success_message = $_SESSION['flash_success'] ?? null;
$error_message = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// العمليات الرئيسية للمسؤول
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        session_regenerate_id(true);
        header('Location: index.php'); exit;
    } else {
        $_SESSION['flash_error'] = 'بيانات الدخول غير مطابقة للسيرفرات';
        header('Location: index.php'); exit;
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php'); exit;
}

// اصدار تفعيل
if ($logged_in && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_license'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('مرفوض.');

    $machine_id = trim($_POST['machine_id']);
    $customer_name = trim($_POST['customer_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $domain = trim($_POST['domain']);
    $license_type = $_POST['license_type'];
    $notes = trim($_POST['notes']);
    
    $license_key = function_exists('generateLicenseKey') ? generateLicenseKey() : strtoupper(md5(uniqid('', true)));
    $expiry_date = function_exists('calculateExpiry') ? calculateExpiry($license_type) : null;
    $ip = null; 
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO licenses (machine_id, customer_name, phone, email, domain, license_key, license_type, activation_date, expiry_date, ip_address, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        
        if($stmt->execute([$machine_id, $customer_name, $phone, $email, $domain, $license_key, $license_type, $expiry_date, $ip, $notes])){
            sendToTelegram($_POST, $license_key);
            $_SESSION['flash_success'] = "تم توليد وتوثيق تفعيل (SHASHETY PRO) للعميل بنجاح!";
            header('Location: index.php'); exit;
        }
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) $_SESSION['flash_error'] = 'المُعرف متواجد بالنظام ومسجل لعميل آخر.';
        else $_SESSION['flash_error'] = 'خطأ خوادم (SHASHETY PRO) المركزية.';
        header('Location: index.php'); exit;
    }
}

if ($logged_in && isset($_GET['token']) && hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
    if (isset($_GET['toggle']) && isset($_GET['id'])) {
        $stmt = $pdo->prepare("UPDATE licenses SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]); header('Location: index.php'); exit;
    }
    if (isset($_GET['delete']) && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]); header('Location: index.php'); exit;
    }
}

$licenses = []; $stats = ['total'=>0, 'active'=>0, 'expired'=>0, 'lifetime'=>0];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"> 
    <title>SHASHETY PRO | التحكم والادارة</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --m-bg: #0f0f13; /* خلفية داكنة جدا واحترافية */
            --m-card: #18181e;
            --main-red: #D20000;
            --main-hover: #b30000;
            --m-border: #282833;
            --text-l: #ffffff;
            --text-d: #8c8c8c;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Tajawal', sans-serif; background-color: var(--m-bg); 
            color: var(--text-l); min-height: 100vh;
            /* منع السحب والنقر بشكل افتراضي لحماية الواجهة ككل */
            user-select: none; -webkit-user-select: none;
            scrollbar-width: thin; scrollbar-color: var(--main-red) var(--m-bg);
            overflow-x: hidden;
        }
        
        body::-webkit-scrollbar { width: 8px; }
        body::-webkit-scrollbar-track { background: var(--m-bg); }
        body::-webkit-scrollbar-thumb { background-color: var(--main-red); border-radius: 4px; }

        /* الكلاس المستثنى ليتمكن الموظف من تحديد السيريال والنسخ براحة */
        .allow-copy { user-select: text; -webkit-user-select: text; cursor: text; }

        /* ========= شاشة العرض الترحيبية (INTRO) ========== */
        .intro-screen {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: #000; z-index: 9999999; display: flex;
            align-items: center; justify-content: center;
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .intro-text {
            color: var(--main-red); font-size: 50px; font-weight: 900;
            letter-spacing: 5px; opacity: 0; font-family: Impact, Arial, sans-serif;
            animation: introPop 2.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
            text-shadow: 0px 0px 30px rgba(210,0,0, 0.5);
        }
        @keyframes introPop {
            0% { transform: scale(0.3); opacity: 0; }
            40% { transform: scale(1.1); opacity: 1; }
            70% { transform: scale(1); opacity: 1; text-shadow: 0px 0px 60px rgba(210,0,0, 1);}
            100% { transform: scale(4); opacity: 0; }
        }
        /* ============================================== */

        .login-container { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-box { background: rgba(24, 24, 30, 0.85); padding: 50px 40px; border-radius: 15px; border: 1px solid var(--m-border); width: 100%; max-width: 450px; text-align:center;}
        .login-box h1 { font-family: Impact, sans-serif; color: var(--main-red); margin-bottom: 5px; font-size: 35px; letter-spacing: 2px;}
        
        .form-group { margin-bottom: 22px; position: relative;}
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; padding: 16px; background-color: #202028; color: #fff; 
            border: 1px solid #333; border-radius: 8px; font-size: 15px; 
            transition: 0.3s; outline: none; font-family: 'Tajawal', sans-serif;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--main-red); background-color: #252530; transform: translateY(-2px);}
        
        .btn { width: 100%; padding: 16px; background: linear-gradient(135deg, var(--main-red), #ff2a2a); color: white; border: none; border-radius: 8px; font-size: 17px; font-weight: bold; cursor: pointer; transition: 0.3s;}
        .btn:hover { background: linear-gradient(135deg, #a30000, var(--main-red)); transform: scale(1.02); }

        .dashboard { padding: 30px; max-width: 1500px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--m-border); }
        .header h1 { font-size: 26px; font-family: Impact, sans-serif; color: var(--main-red); letter-spacing:1px; display:flex; align-items:center; gap: 8px;}
        .header h1 span { color:#fff; font-family:'Tajawal', sans-serif;}
        .logout { background: #222; border: 1px solid #444; color: #ccc; padding: 10px 20px; border-radius: 6px; text-decoration: none; transition: 0.3s; font-size:14px; }
        .logout:hover { background: var(--main-red); color: #fff; border-color:var(--main-red);}

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--m-card); padding: 25px; border-radius: 12px; border: 1px solid var(--m-border); transition: 0.3s; position: relative; overflow: hidden; }
        .stat-card h3 { font-size: 38px; margin: 10px 0; font-weight: 800; }
        .stat-card p { color: var(--text-d); font-size: 14px; font-weight: 600;}
        .stat-card i { position: absolute; left: 15px; top: 30%; font-size: 60px; opacity: 0.05;}
        
        .card { background: var(--m-card); padding: 35px; border-radius: 12px; margin-bottom: 40px; border: 1px solid var(--m-border);}
        .card h2 { font-size: 22px; margin-bottom: 30px; border-right: 4px solid var(--main-red); padding-right: 15px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        
        .table-responsive { overflow-x: auto; border-radius: 10px; border: 1px solid var(--m-border); width: 100%;}
        table { width: 100%; border-collapse: collapse; text-align: right; min-width: 900px;} /* حد ادنى لكي يسحب يمين ويسار بالجوال */
        th { background: #1f1f26; padding: 16px 15px; color:#fff; font-size: 14px; border-bottom: 1px solid #333;}
        td { padding: 16px 15px; border-bottom: 1px solid #202028; font-size: 14px; white-space: nowrap; }
        tr:hover td { background-color: #24242e; }
        
        .badge { padding: 5px 12px; border-radius: 6px; font-size: 11px; background: #333; font-weight:bold;}
        .badge-active { background: rgba(46, 213, 115, 0.2); color: #2ed573; border:1px solid #2ed573;}
        .badge-inactive { background: rgba(210, 0, 0, 0.2); color: #ff4c4c; border:1px solid var(--main-red);}

        /* زر الدعم الواتساب العائم الذكي */
        .pro-whatsapp-float {
            position: fixed; bottom: 30px; left: 30px; 
            background: rgba(15, 15, 19, 0.9); backdrop-filter: blur(10px);
            border: 1px solid #25D366; padding: 6px 18px 6px 6px;
            border-radius: 50px; display: flex; align-items: center; gap: 10px; text-decoration: none; z-index: 999;
        }
        .wa-icon { width: 45px; height: 45px; background: linear-gradient(135deg, #25D366, #128C7E); color: #fff; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 24px;}
        .wa-details span { color:#ccc; font-size:11px;}
        .wa-details strong { display:block; color:#fff; font-size:14px; font-family:monospace; }

        /* جدار الحماية الوهمي Modal */
        .sec-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0, 0.9); backdrop-filter: blur(8px); 
            z-index: 999999; display: flex; align-items: center; justify-content: center;
            visibility: hidden; opacity: 0; transition: 0.3s;
        }
        .sec-overlay.active { visibility: visible; opacity: 1; }
        .sec-box { background: var(--m-card); border: 2px solid var(--main-red); padding: 40px; text-align: center; max-width: 400px; width:90%; border-radius:12px; transform: scale(0.8); transition:0.3s;}
        .sec-overlay.active .sec-box { transform: scale(1); }

        /* ======= الموبايل التجاوبي (الهواتف الأندرويد والآيفون) ======= */
        @media (max-width: 768px) {
            .dashboard { padding: 15px; }
            .login-box { padding: 40px 25px; margin: 15px; }
            .header { flex-direction: column; gap: 15px; }
            .card { padding: 20px 15px; }
            .form-grid { grid-template-columns: 1fr; } /* يجعل المربعات عمودية للموبايل */
            
            /* تحويل الجدول لنظام اللمس */
            .table-responsive { -webkit-overflow-scrolling: touch; border-radius:6px; margin-bottom:80px; }
            
            /* تحويل ايقونة الواتساب لتكون دائرة صغيرة لا تعيق الجوال */
            .pro-whatsapp-float { padding: 6px; border-radius: 50%; bottom: 15px; left: 15px; border:none;}
            .wa-details { display: none; /* اخفاء الرقم نصياً وترك الايقونة فقط */ }
        }
    </style>
</head>
<body>

    <!-- Intro للترحيب بالنظام -->
    <div id="intro-screen" class="intro-screen">
        <div class="intro-text">SHASHETY PRO</div>
    </div>

    <?php if (!$logged_in): ?>
        <div class="login-container">
            <div class="login-box">
                <h1>SHASHETY <span>PRO</span></h1>
                <p style="color:#aaa; font-size:14px; margin-bottom: 25px;">الإدارة المتقدمة | Server Security V4</p>
                <?php if ($error_message): ?>
                    <div style="background:rgba(210,0,0,0.1); border-right:4px solid var(--main-red); padding:10px; color:#ff4c4c; margin-bottom:20px; font-weight:bold;"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <input type="password" name="password" placeholder="كود المصادقة الخاص..." required>
                    </div>
                    <button type="submit" name="login" class="btn">تأكيد المصادقة الدخول</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="dashboard">
            <div class="header">
                <h1>SHASHETY <span>PRO</span> <span style="font-size:16px; margin-top:6px; color:var(--main-red);"><i class="fas fa-server"></i> PANEL</span></h1>
                <a href="?logout" class="logout"><i class="fas fa-sign-out-alt"></i> إنهاء الجلسة </a>
            </div>
            
            <div class="stats">
                <div class="stat-card total"><i class="fas fa-users"></i><p>المستفيدين كليا</p><h3 style="color:#fff"><?php echo $stats['total']; ?></h3></div>
                <div class="stat-card active"><i class="fas fa-check-circle"></i><p>أجهزة متصلة ونشطة</p><h3 style="color:#2ed573"><?php echo $stats['active']; ?></h3></div>
                <div class="stat-card expired"><i class="fas fa-ban"></i><p>ملفات متعطلة (مرفوض)</p><h3 style="color:var(--main-red)"><?php echo $stats['expired']; ?></h3></div>
                <div class="stat-card lifetime"><i class="fas fa-star"></i><p>الباقات المستمرة الدائمة</p><h3 style="color:#eccc68"><?php echo $stats['lifetime']; ?></h3></div>
            </div>
            
            <div class="card">
                <h2>إنشاء رخصة SHASHETY لعميل</h2>
                <?php if ($success_message): ?><div style="color:#2ed573; margin-bottom:15px; font-weight:bold;">🟢 <?php echo $success_message; ?></div><?php endif; ?>
                <?php if ($error_message): ?><div style="color:var(--main-red); margin-bottom:15px; font-weight:bold;">🔴 <?php echo $error_message; ?></div><?php endif; ?>
                
                <form method="POST" id="mainForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-grid">
                        <div class="form-group"><input type="text" name="customer_name" placeholder="اسم العميل *" required></div>
                        <div class="form-group"><input type="tel" name="phone" placeholder="رقم الاتصال للمستفيد *" required></div>
                        <!-- كلاس allow-copy مسموح النسخ منه ولصق إليه (للـ Hardware ID) -->
                        <div class="form-group"><input class="allow-copy" type="text" name="machine_id" placeholder="الصق الماك ادرس / السيريال (يُدعم اللصق) *" required></div>
                        <div class="form-group">
                            <select name="license_type" required>
                                <option value="" disabled selected>تحديد نظام الصلاحية...</option>
                                <option value="trial_1day">تجريبي Test - مدة (24 ساعة)</option>
                                <option value="trial_1week">دوري Weekly - مدة (اسبوع)</option>
                                <option value="monthly">رئيسي Monthly - مدة (30 يوم)</option>
                                <option value="yearly">ذهبي Yearly - مدة (12 شهر)</option>
                                <option value="lifetime">V.I.P Forever - مفتوح بالكامل</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_license" class="btn" onclick="this.innerHTML='يتم إتصال وتثبيت التفعيل...';">تكوين الاشتراك للسيرفر المضيف</button>
                </form>
            </div>
            
            <div class="card">
                <h2>إدارة سيرفرات SHASHETY الخاصة بك</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>تفاصيل الملف</th>
                                <th>البصمة والسيريال (متاح النسخ)</th>
                                <th>نظام الخطة</th>
                                <th>مدة التقادم للبانل</th>
                                <th>IP Log</th>
                                <th>الإجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($licenses as $lic): ?>
                                <tr style="<?php echo (!$lic['is_active']) ? 'opacity:0.4' : ''; ?>">
                                    <td># <?php echo $lic['id']; ?></td>
                                    <td>
                                        <b style="color:#fff"><?php echo htmlspecialchars($lic['customer_name']); ?></b><br>
                                        <small style="color:#777;"><?php echo htmlspecialchars($lic['phone']); ?></small>
                                    </td>
                                    <!-- يسمح بالنسخ فقط بفضل كلاس allow-copy -->
                                    <td class="allow-copy" style="font-family:monospace; color:#ccc;">
                                        <?php echo htmlspecialchars($lic['machine_id']); ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="<?php echo $lic['license_type']=='lifetime' ? 'color:#eccc68; background:rgba(236,204,104,0.1)' : ''; ?>">
                                            <?php echo htmlspecialchars($lic['license_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $lic['expiry_date'] ? date('Y-m-d', strtotime($lic['expiry_date'])) : 'دائم (Forever)'; ?>
                                        <?php if ($lic['expiry_date'] && strtotime($lic['expiry_date']) < time()) echo '<span style="color:var(--main-red); font-size:11px;"><br>Expired</span>'; ?>
                                    </td>
                                    <td style="color:#666; font-size:12px;"><?php echo $lic['ip_address']?:'سيرفر غير موصول';?></td>
                                    <td>
                                        <a href="?toggle&id=<?php echo $lic['id']; ?>&token=<?php echo $_SESSION['csrf_token']; ?>" style="color:#2ed573; text-decoration:none; padding:8px;" title="التعليق/السماح"><i class="fas fa-power-off"></i></a>
                                        <a href="?delete&id=<?php echo $lic['id']; ?>&token=<?php echo $_SESSION['csrf_token']; ?>" onclick="return confirm('إبادة هذا المفتاح؟')" style="color:#ff4c4c; text-decoration:none; padding:8px;" title="ازالة البانل"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <a href="https://wa.me/009647512328848" target="_blank" class="pro-whatsapp-float">
                <div class="wa-icon"><i class="fab fa-whatsapp"></i></div>
                <div class="wa-details"><span>للدعم الهندسي للبانل</span><strong>009647512328848</strong></div>
            </a>
        </div>
    <?php endif; ?>

    <!-- النافذة الدفاعية (Security Firewall) -->
    <div id="secShield" class="sec-overlay">
        <div class="sec-box">
            <i class="fas fa-fingerprint" style="font-size: 60px; color:var(--main-red); margin-bottom:15px;"></i>
            <h2 style="color:#fff;">(SHASHETY PRO Firewall)</h2>
            <p style="color:#ccc; font-size:14px; margin-bottom:20px;">الواجهة الأساسية مُحمّاة ضد الاختراق والتفقد الخارجي.</p>
            <button class="btn" style="padding:10px;" onclick="closeSec()">إخفاء</button>
        </div>
    </div>

    <script>
        // إزالة رسالة اعادة الإرسال عند ريفريش F5
        if (window.history.replaceState) window.history.replaceState(null, null, window.location.href);

        // إغلاق الواجهة الافتتاحية SHASHETY PRO
        window.onload = function() {
            var intro = document.getElementById("intro-screen");
            setTimeout(function() {
                if(intro) { intro.style.opacity = "0"; intro.style.visibility = "hidden"; }
            }, 2300); // 2.3 seconds
        };

        // ====== أنظمة الحماية واستثناء (النسخ واللصق) ======= //
        
        function closeSec() { document.getElementById("secShield").classList.remove('active'); }
        function openSec(e) { if(e) e.preventDefault(); document.getElementById("secShield").classList.add('active'); return false; }
        
        // فحص: هل العنصر الذي ضغط عليه أو أبوه مسموح بنسخه؟
        function canInteract(target) {
            // السماح إذا كان العنصر يحتوي على الكلاس 'allow-copy' أو كُتب بداخله
            if (target.classList && target.classList.contains('allow-copy')) return true;
            if (target.closest && target.closest('.allow-copy')) return true;
            // حماية المدخلات العادية كالأسم ليمكن كتابتها ومسحها 
            if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') return true; 
            return false;
        }

        // إعدادت منع كليك يمين
        document.addEventListener('contextmenu', function(e) {
            if (canInteract(e.target)) return; // سيتجاهل إذا ضغط داخل المربع המخصص او جدول הסيريالات
            openSec(e);
        });

        // حماية اختصارات الفحص F12 & ctrl + shft + i .. الخ
        document.addEventListener('keydown', function(e) {
            if (e.keyCode === 123 || 
               (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) || 
               (e.ctrlKey && (e.keyCode === 85 || e.keyCode === 83))) {
                openSec(e);
            }
        });
        
        // تعطيل نسخ النص في الصفحة כكل (السحب الازرق) عدا الاشياء التي وسمناها
        document.addEventListener('selectstart', function(e) {
            if (canInteract(e.target)) return; // אשמח فقط بالنص المطلوب
            e.preventDefault();
        });

    </script>
</body>
</html>